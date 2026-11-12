<?php
/**
 * Who may see and change what a log entry is about.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;

defined( 'ABSPATH' ) || exit;

/**
 * Seeing the log is not enough to see every entry in it. A person who may open
 * the log but may not edit a post, a user, a term or a setting does not see what
 * changed there, and cannot undo it. The check runs per object, with the
 * capability that object needs, in the log screen, the REST route, the
 * abilities and WP-CLI.
 */
final class Access {

	/**
	 * Whether the current user may see an entry's details and undo it.
	 *
	 * WP-CLI without `--user` is the server's own shell and sees everything.
	 *
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	public static function can_view( Entry $entry ): bool {
		if ( ! is_user_logged_in() ) {
			$allowed = defined( 'WP_CLI' ) && WP_CLI;
		} else {
			$allowed = self::allowed( $entry );
		}

		/**
		 * Filters whether the current user may see an entry and undo it.
		 *
		 * Entries that are not allowed show as "a change you cannot see" in the
		 * log, abilities and WP-CLI, without the object's name or its values.
		 *
		 * @param bool  $allowed Default: the user may edit the object the entry is about.
		 * @param Entry $entry   The entry.
		 * @param int   $user_id Current user ID.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_can_view_entry', $allowed, $entry, get_current_user_id() );
	}

	/**
	 * What an entry shows instead of its description when it is hidden.
	 *
	 * @return string
	 */
	public static function hidden_text(): string {
		return __( 'A change to something you are not allowed to see', 'activity-log-with-undo' );
	}

	/**
	 * Entry data without the parts that name or show the object.
	 *
	 * @param array $data Entry data as the abilities and WP-CLI return it.
	 * @return array
	 */
	public static function mask( array $data ): array {
		foreach ( array( 'object_id', 'object_label', 'ip', 'before', 'after', 'conflicts' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$data[ $key ] = in_array( $key, array( 'before', 'after' ), true ) ? null : ( 'conflicts' === $key ? array() : '' );
			}
		}
		$data['description'] = self::hidden_text();
		$data['masked']      = true;

		return $data;
	}

	/**
	 * Why an entry cannot be undone by the current user, or null when it can.
	 *
	 * @param Entry $entry Entry.
	 * @return \WP_Error|null
	 */
	public static function refusal( Entry $entry ): ?\WP_Error {
		if ( self::can_view( $entry ) ) {
			return null;
		}

		return new \WP_Error( 'activity_log_with_undo_forbidden', __( 'You are not allowed to change this.', 'activity-log-with-undo' ), array( 'status' => 403 ) );
	}

	/**
	 * The capability check for the object an entry is about.
	 *
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	private static function allowed( Entry $entry ): bool {
		$type = $entry->object_type;
		$id   = $entry->object_id;
		$num  = ctype_digit( $id ) ? (int) $id : 0;

		switch ( $type ) {
			case 'option':
				return current_user_can( 'manage_options' );
			case 'network_option':
				return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
			case 'plugin':
				return current_user_can( 'activate_plugins' );
			case 'theme':
				return current_user_can( 'switch_themes' );
			case 'menu':
				return current_user_can( 'edit_theme_options' );
			case 'site':
				return current_user_can( is_multisite() ? 'manage_sites' : 'manage_options' );
			case 'user':
				return $num && get_userdata( $num ) ? current_user_can( 'edit_user', $num ) : current_user_can( 'list_users' );
			case 'rollback':
				return current_user_can( 'manage_options' );
		}

		if ( post_type_exists( $type ) ) {
			$post_type = get_post_type_object( $type );
			if ( $num && get_post( $num ) ) {
				return current_user_can( 'edit_post', $num );
			}

			return $post_type ? current_user_can( $post_type->cap->edit_posts ) : false;
		}

		if ( taxonomy_exists( $type ) ) {
			$taxonomy = get_taxonomy( $type );
			if ( $num && get_term( $num, $type ) instanceof \WP_Term ) {
				return current_user_can( 'edit_term', $num );
			}

			return $taxonomy ? current_user_can( $taxonomy->cap->edit_terms ) : false;
		}

		// A kind of object this plugin does not know (a custom recorder): the log capability decides.
		return current_user_can( LogPage::capability() );
	}
}
