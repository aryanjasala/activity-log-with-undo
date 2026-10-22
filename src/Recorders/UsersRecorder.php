<?php
/**
 * User roles, profile fields, new and deleted users.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Role changes and profile fields (display name, email, website) can be
 * reversed. Undoing a new user deletes the account, with their content given
 * to whoever created it. Deleted users are logged only.
 *
 * One role change fires up to three hooks (remove, add, set), so they are
 * merged into one entry per user per request, with the roles read before the
 * first change.
 */
final class UsersRecorder extends BaseRecorder {

	/**
	 * Profile fields that are logged and restored.
	 */
	private const FIELDS = array( 'display_name', 'user_email', 'user_url' );

	/**
	 * Roles before the first change in a change set, keyed by change set and user ID.
	 *
	 * @var array<string, string[]>
	 */
	private array $roles_before = array();

	/**
	 * Role entries written in a change set, keyed by change set and user ID.
	 *
	 * @var array<string, int>
	 */
	private array $role_entries = array();

	/**
	 * Users whose roles were set for the first time in this request (new users).
	 *
	 * @var array<int, bool>
	 */
	private array $new_users = array();

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'users';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Users and roles', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'user_role_changed' => __( 'User role changed', 'activity-log-with-undo' ),
			'user_updated'      => __( 'User profile changed', 'activity-log-with-undo' ),
			'user_created'      => __( 'User added', 'activity-log-with-undo' ),
			'user_deleted'      => __( 'User deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into user changes.
	 */
	public function register(): void {
		add_action( 'update_user_meta', array( $this, 'before_meta_update' ), 10, 3 );
		add_action( 'add_user_meta', array( $this, 'before_meta_add' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'roles_changed' ) );
		add_action( 'add_user_role', array( $this, 'roles_changed' ) );
		add_action( 'remove_user_role', array( $this, 'roles_changed' ) );
		add_action( 'profile_update', array( $this, 'profile_updated' ), 10, 2 );
		add_action( 'user_register', array( $this, 'created' ) );
		add_action( 'delete_user', array( $this, 'deleting' ), 10, 3 );
	}

	/**
	 * Meta key holding a user's roles on this site.
	 *
	 * @return string
	 */
	private static function cap_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	/**
	 * A user's roles on this site, sorted.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function roles( int $user_id ): array {
		$caps  = get_user_meta( $user_id, self::cap_key(), true );
		$roles = array();
		foreach ( is_array( $caps ) ? $caps : array() as $cap => $granted ) {
			if ( $granted && wp_roles()->is_role( (string) $cap ) ) {
				$roles[] = (string) $cap;
			}
		}
		sort( $roles );

		return $roles;
	}

	/**
	 * Keeps a user's roles before their capabilities meta is rewritten.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 */
	public function before_meta_update( $meta_id, $user_id, $meta_key ): void {
		$key = $this->key( (int) $user_id );
		if ( self::cap_key() === $meta_key && ! isset( $this->roles_before[ $key ] ) ) {
			$this->roles_before[ $key ] = self::roles( (int) $user_id );
		}
	}

	/**
	 * Notes users getting roles on this site for the first time (new users).
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 */
	public function before_meta_add( $user_id, $meta_key ): void {
		if ( self::cap_key() === $meta_key ) {
			$this->new_users[ (int) $user_id ] = true;
		}
	}

	/**
	 * A user's roles changed: logs one entry per user per request.
	 *
	 * @param int $user_id User ID.
	 */
	public function roles_changed( $user_id ): void {
		$user_id = (int) $user_id;
		$key     = $this->key( $user_id );
		if ( isset( $this->new_users[ $user_id ] ) || ! isset( $this->roles_before[ $key ] ) ) {
			return;
		}

		$before = $this->roles_before[ $key ];
		$after  = self::roles( $user_id );

		if ( isset( $this->role_entries[ $key ] ) ) {
			$this->logger->update_after( $this->role_entries[ $key ], $after );
			return;
		}
		if ( $before === $after ) {
			return;
		}

		$user = get_userdata( $user_id );
		$id   = $this->record( 'user_role_changed', 'user', $user_id, $user ? $user->user_login : '#' . $user_id, $before, $after, true );
		if ( $id ) {
			$this->role_entries[ $key ] = $id;
		}
	}

	/**
	 * Key for merging role hooks: one entry per user per change set.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function key( int $user_id ): string {
		return $this->logger->current_change_set() . ':' . $user_id;
	}

	/**
	 * A profile was saved: logs changed display name, email and website.
	 *
	 * @param int   $user_id  User ID.
	 * @param mixed $old_data The user before the change (WP_User).
	 */
	public function profile_updated( $user_id, $old_data ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_object( $old_data ) ) {
			return;
		}

		$before = array();
		$after  = array();
		foreach ( self::FIELDS as $field ) {
			$old = (string) ( $old_data->$field ?? '' );
			$new = (string) $user->$field;
			if ( $old !== $new ) {
				$before[ $field ] = $old;
				$after[ $field ]  = $new;
			}
		}

		if ( $after ) {
			$this->record( 'user_updated', 'user', $user_id, $user->user_login, $before, $after, true );
		}
	}

	/**
	 * A user was added.
	 *
	 * @param int $user_id User ID.
	 */
	public function created( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			$this->record( 'user_created', 'user', $user_id, $user->user_login, null, self::summary( $user ), true );
		}
	}

	/**
	 * A user is about to be deleted.
	 *
	 * @param int           $user_id  User ID.
	 * @param int|null      $reassign User who gets their content, or null.
	 * @param \WP_User|null $user     The user.
	 */
	public function deleting( $user_id, $reassign = null, $user = null ): void {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user_id );
		if ( $user ) {
			$this->record( 'user_deleted', 'user', $user_id, $user->user_login, array( 'reassign' => $reassign ? (int) $reassign : 0 ), null, false );
		}
	}

	/**
	 * Login, email and roles of a user.
	 *
	 * @param \WP_User $user User.
	 * @return array
	 */
	private static function summary( \WP_User $user ): array {
		return array(
			'user_login' => $user->user_login,
			'user_email' => $user->user_email,
			'roles'      => self::roles( $user->ID ),
		);
	}

	/**
	 * Whether the user change can be reversed.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( 'user_deleted' === $entry->event ) {
			return self::refuse( __( 'WordPress deletes the account and its settings, and may have deleted their content, so it cannot be put back.', 'activity-log-with-undo' ) );
		}

		$user_id = (int) $entry->object_id;
		if ( 'user_created' === $entry->event ) {
			if ( ! get_userdata( $user_id ) ) {
				return self::refuse( __( 'The user no longer exists.', 'activity-log-with-undo' ) );
			}
			if ( get_current_user_id() === $user_id || is_super_admin( $user_id ) ) {
				return self::refuse( __( 'You cannot delete your own account or a network administrator this way.', 'activity-log-with-undo' ) );
			}
		} elseif ( ! get_userdata( $user_id ) ) {
			return self::refuse( __( 'The user no longer exists.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The user's current roles, fields or summary.
	 *
	 * @param Entry $entry Entry.
	 * @return mixed
	 */
	public function current_state( Entry $entry ) {
		$user = get_userdata( (int) $entry->object_id );
		if ( ! $user ) {
			return null;
		}

		switch ( $entry->event ) {
			case 'user_role_changed':
				return self::roles( $user->ID );
			case 'user_updated':
				$state = array();
				foreach ( array_keys( (array) $entry->after() ) as $field ) {
					$state[ $field ] = in_array( $field, self::FIELDS, true ) ? (string) $user->$field : '';
				}
				return $state;
			case 'user_created':
				return self::summary( $user );
		}

		return null;
	}

	/**
	 * Restores the roles or fields, or deletes a new user.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$user_id = (int) $entry->object_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return self::refuse( __( 'The user no longer exists.', 'activity-log-with-undo' ) );
		}

		switch ( $entry->event ) {
			case 'user_role_changed':
				$roles = array_values( array_filter( array_map( 'strval', (array) $entry->before() ), array( wp_roles(), 'is_role' ) ) );
				$user->set_role( (string) array_shift( $roles ) );
				foreach ( $roles as $role ) {
					$user->add_role( $role );
				}
				return Payload::same( self::roles( $user_id ), array_values( array_filter( (array) $entry->before() ) ) ) || ! $entry->before()
					? true
					: self::refuse( __( 'Some of the old roles no longer exist.', 'activity-log-with-undo' ) );

			case 'user_updated':
				$fields = array_intersect_key( (array) $entry->before(), array_flip( self::FIELDS ) );
				// The person is getting their own address back, so the "email changed" notice would only confuse them.
				add_filter( 'send_email_change_email', '__return_false' );
				$result = wp_update_user( array( 'ID' => $user_id ) + $fields );
				remove_filter( 'send_email_change_email', '__return_false' );
				return is_wp_error( $result ) ? $result : true;

			case 'user_created':
				$reassign = $entry->actor_user_id > 0 && $entry->actor_user_id !== $user_id && get_userdata( $entry->actor_user_id ) ? $entry->actor_user_id : get_current_user_id();
				if ( ! $reassign || $reassign === $user_id ) {
					// Without someone to give their content to, WordPress would delete it.
					return self::refuse( __( 'Nobody to give their content to. Undo this as a logged-in user (with WP-CLI, add --user=<login>).', 'activity-log-with-undo' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/user.php';
				return self::result( wp_delete_user( $user_id, $reassign ), __( 'WordPress did not delete the user.', 'activity-log-with-undo' ) );
		}

		return self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$login = '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;

		switch ( $entry->event ) {
			case 'user_role_changed':
				/* translators: 1: user login, 2: old roles, 3: new roles. */
				return sprintf( __( 'Changed the role of %1$s from %2$s to %3$s', 'activity-log-with-undo' ), $login, self::role_names( (array) $entry->before() ), self::role_names( (array) $entry->after() ) );
			case 'user_updated':
				$labels = array(
					'display_name' => __( 'display name', 'activity-log-with-undo' ),
					'user_email'   => __( 'email', 'activity-log-with-undo' ),
					'user_url'     => __( 'website', 'activity-log-with-undo' ),
				);
				$fields = array_map(
					static function ( $field ) use ( $labels ) {
						return $labels[ $field ] ?? $field;
					},
					array_keys( (array) $entry->after() )
				);
				/* translators: 1: user login, 2: changed fields, e.g. "display name, email". */
				return sprintf( __( 'Changed the %2$s of %1$s', 'activity-log-with-undo' ), $login, implode( ', ', $fields ) );
			case 'user_created':
				/* translators: %s: user login. */
				return sprintf( __( 'Added the user %s', 'activity-log-with-undo' ), $login );
			default:
				/* translators: %s: user login. */
				return sprintf( __( 'Deleted the user %s', 'activity-log-with-undo' ), $login );
		}
	}

	/**
	 * Role names for people.
	 *
	 * @param array $roles Role slugs.
	 * @return string
	 */
	private static function role_names( array $roles ): string {
		if ( ! $roles ) {
			return __( 'no role', 'activity-log-with-undo' );
		}
		$names = wp_roles()->get_names();

		return implode(
			', ',
			array_map(
				static function ( $role ) use ( $names ) {
					return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role;
				},
				$roles
			)
		);
	}
}
