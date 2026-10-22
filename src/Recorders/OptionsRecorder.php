<?php
/**
 * Settings, widgets and other options.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Logs added, changed and deleted options, and network options on multisite.
 *
 * Caches, transients, cron, locks and other values WordPress rewrites on its
 * own are left out, as are options another recorder covers (active plugins,
 * the theme). Widgets are options too, so they are covered here.
 */
final class OptionsRecorder extends BaseRecorder {

	/**
	 * Values of options about to be deleted, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $deleting = array();

	/**
	 * Patterns of logged options, cached for the request.
	 *
	 * @var array{0: string[], 1: string[]}|null
	 */
	private ?array $patterns = null;

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'options';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Settings and widgets', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'option_added'           => __( 'Option added', 'activity-log-with-undo' ),
			'option_updated'         => __( 'Setting changed', 'activity-log-with-undo' ),
			'option_deleted'         => __( 'Option deleted', 'activity-log-with-undo' ),
			'network_option_added'   => __( 'Network option added', 'activity-log-with-undo' ),
			'network_option_updated' => __( 'Network setting changed', 'activity-log-with-undo' ),
			'network_option_deleted' => __( 'Network option deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into option changes.
	 */
	public function register(): void {
		add_action( 'added_option', array( $this, 'added' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'updated' ), 10, 3 );
		add_action( 'delete_option', array( $this, 'deleting' ) );
		add_action( 'deleted_option', array( $this, 'deleted' ) );

		if ( is_multisite() ) {
			add_action( 'add_site_option', array( $this, 'network_added' ), 10, 2 );
			add_action( 'update_site_option', array( $this, 'network_updated' ), 10, 3 );
			add_action( 'delete_site_option', array( $this, 'network_deleted' ) );
		}
	}

	/**
	 * Options left out by default. `*` matches anything.
	 *
	 * @return string[]
	 */
	public static function default_ignored(): array {
		return array(
			'_transient_*',
			'_site_transient_*',
			'cron',
			'doing_cron',
			'rewrite_rules',
			'*_lock',
			'*.lock',
			'*_children',
			'*_cache',
			'*_cache_*',
			'*_session',
			'*_sessions',
			'session_*',
			'*_db_version',
			'db_version',
			'db_upgraded',
			'initial_db_version',
			'active_plugins',
			'active_sitewide_plugins',
			'recently_activated',
			'uninstall_plugins',
			'recently_edited',
			'template',
			'stylesheet',
			'current_theme',
			'theme_switched',
			'theme_switched_via_customizer',
			'customize_stashed_theme_mods',
			'*_user_roles',
			'can_compress_scripts',
			'wp_force_deactivated_plugins',
			'recovery_keys',
			'recovery_mode_email_last_sent',
			'admin_email_lifespan',
			'adminhash',
			'new_admin_email',
			'https_detection_errors',
			'auto_core_update_*',
			'finished_splitting_shared_terms',
			'finished_updating_comment_type',
			'fresh_site',
			'user_count',
			'wp_calendar_block_has_published_posts',
			'_wp_suggested_policy_text_has_changed',
			'auth_key',
			'auth_salt',
			'logged_in_key',
			'logged_in_salt',
			'nonce_key',
			'nonce_salt',
			'secure_auth_key',
			'secure_auth_salt',
			'activity_log_with_undo_*',
		);
	}

	/**
	 * Whether changes to an option are logged.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public function is_logged( string $option ): bool {
		if ( null === $this->patterns ) {
			/**
			 * Filters which options are logged. Each item is a name, `*` matches anything.
			 *
			 * An option is logged when it matches this list and not the ignore list.
			 *
			 * @param string[] $patterns Default `*`, every option.
			 */
			$logged = array_filter( array_map( 'strval', (array) apply_filters( 'activity_log_with_undo_logged_options', array( '*' ) ) ) );

			/**
			 * Filters which options are never logged. Each item is a name, `*` matches anything.
			 *
			 * The default covers transients, caches, cron, locks, salts, and options
			 * other recorders handle (active plugins, the theme).
			 *
			 * @param string[] $patterns Option names and patterns.
			 */
			$ignored = array_filter( array_map( 'strval', (array) apply_filters( 'activity_log_with_undo_ignored_options', self::default_ignored() ) ) );

			// Our own options are never logged, whatever the filters say, or saving the settings would log itself.
			$ignored[] = 'activity_log_with_undo_*';

			$this->patterns = array( $logged, $ignored );
		}

		list( $logged, $ignored ) = $this->patterns;

		return self::matches_any( $option, $logged ) && ! self::matches_any( $option, $ignored );
	}

	/**
	 * Whether a name matches one of the patterns.
	 *
	 * @param string   $name     Option name.
	 * @param string[] $patterns Names, `*` matching anything.
	 * @return bool
	 */
	public static function matches_any( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( $pattern === $name || ( false !== strpos( $pattern, '*' ) && preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/', $name ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An option was added.
	 *
	 * @param string $option Name.
	 * @param mixed  $value  Value.
	 */
	public function added( $option, $value ): void {
		// WordPress adds some options empty the first time a screen is saved (WPLANG on Settings > General). Nothing changes.
		if ( $this->is_logged( (string) $option ) && ! in_array( $value, array( '', null, false, array() ), true ) ) {
			$this->record( 'option_added', 'option', $option, self::label_for( (string) $option ), null, $value, true );
		}
	}

	/**
	 * An option changed.
	 *
	 * @param string $option    Name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 */
	public function updated( $option, $old_value, $value ): void {
		if ( $this->is_logged( (string) $option ) && ! Payload::same( $old_value, $value ) ) {
			$this->record( 'option_updated', 'option', $option, self::label_for( (string) $option ), $old_value, $value, true );
		}
	}

	/**
	 * An option is about to be deleted: keep its value.
	 *
	 * @param string $option Name.
	 */
	public function deleting( $option ): void {
		if ( $this->is_logged( (string) $option ) ) {
			$this->deleting[ (string) $option ] = get_option( (string) $option );
		}
	}

	/**
	 * An option was deleted.
	 *
	 * @param string $option Name.
	 */
	public function deleted( $option ): void {
		$option = (string) $option;
		if ( ! array_key_exists( $option, $this->deleting ) ) {
			return;
		}
		$before = $this->deleting[ $option ];
		unset( $this->deleting[ $option ] );

		$this->record( 'option_deleted', 'option', $option, self::label_for( $option ), $before, null, false !== $before );
	}

	/**
	 * A network option was added.
	 *
	 * @param string $option Name.
	 * @param mixed  $value  Value.
	 */
	public function network_added( $option, $value ): void {
		if ( $this->is_logged( (string) $option ) ) {
			$this->record( 'network_option_added', 'network_option', $option, (string) $option, null, $value, true, true );
		}
	}

	/**
	 * A network option changed.
	 *
	 * @param string $option    Name.
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Old value.
	 */
	public function network_updated( $option, $value, $old_value ): void {
		if ( $this->is_logged( (string) $option ) && ! Payload::same( $old_value, $value ) ) {
			$this->record( 'network_option_updated', 'network_option', $option, (string) $option, $old_value, $value, true, true );
		}
	}

	/**
	 * A network option was deleted. WordPress has no generic hook before that, so the old value is unknown.
	 *
	 * @param string $option Name.
	 */
	public function network_deleted( $option ): void {
		if ( $this->is_logged( (string) $option ) ) {
			$this->record( 'network_option_deleted', 'network_option', $option, (string) $option, null, null, false, true );
		}
	}

	/**
	 * Why an entry cannot be undone, if it cannot.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( 'network_option_deleted' === $entry->event ) {
			return self::refuse( __( 'WordPress gives no chance to read a network option before it is deleted, so its old value is unknown.', 'activity-log-with-undo' ) );
		}
		if ( 0 === strpos( $entry->event, 'network_' ) && ! is_multisite() ) {
			return self::refuse( __( 'Network options can only be restored on a multisite network.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The option's current value, or null when it does not exist.
	 *
	 * @param Entry $entry Entry.
	 * @return mixed
	 */
	public function current_state( Entry $entry ) {
		$missing = new \stdClass();
		$value   = 'network_option' === $entry->object_type
			? get_network_option( null, $entry->object_id, $missing )
			: get_option( $entry->object_id, $missing );

		return $value === $missing ? null : $value;
	}

	/**
	 * Writes the old value back, or removes an option that was added.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$name    = $entry->object_id;
		$network = 'network_option' === $entry->object_type;
		$exists  = null !== $this->current_state( $entry );

		switch ( $entry->event ) {
			case 'option_added':
			case 'network_option_added':
				if ( ! $exists ) {
					return true;
				}
				$done = $network ? delete_network_option( null, $name ) : delete_option( $name );
				break;

			case 'option_deleted':
				if ( $exists ) {
					return self::refuse( __( 'The option exists again, so it was not added back.', 'activity-log-with-undo' ) );
				}
				$done = add_option( $name, $entry->before() );
				break;

			default:
				if ( Payload::same( $this->current_state( $entry ), $entry->before() ) ) {
					return true;
				}
				$done = $network ? update_network_option( null, $name, $entry->before() ) : update_option( $name, $entry->before() );
		}

		/* translators: %s: option name. */
		return self::result( $done, sprintf( __( 'WordPress did not save the %s option.', 'activity-log-with-undo' ), $name ) );
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$label = '' !== $entry->object_label ? $entry->object_label : $entry->object_id;

		switch ( $entry->event ) {
			case 'option_added':
			case 'network_option_added':
				/* translators: %s: option name. */
				return sprintf( __( 'Added %s', 'activity-log-with-undo' ), $label );
			case 'option_deleted':
			case 'network_option_deleted':
				/* translators: %s: option name. */
				return sprintf( __( 'Deleted %s', 'activity-log-with-undo' ), $label );
			default:
				/* translators: %s: setting name, e.g. "Site title". */
				return sprintf( __( 'Changed %s', 'activity-log-with-undo' ), $label );
		}
	}

	/**
	 * Name of an option for people.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public static function label_for( string $option ): string {
		$known = array(
			'blogname'                   => __( 'Site title', 'activity-log-with-undo' ),
			'blogdescription'            => __( 'Tagline', 'activity-log-with-undo' ),
			'siteurl'                    => __( 'WordPress address', 'activity-log-with-undo' ),
			'home'                       => __( 'Site address', 'activity-log-with-undo' ),
			'admin_email'                => __( 'Administration email address', 'activity-log-with-undo' ),
			'users_can_register'         => __( 'Membership', 'activity-log-with-undo' ),
			'default_role'               => __( 'New user default role', 'activity-log-with-undo' ),
			'WPLANG'                     => __( 'Site language', 'activity-log-with-undo' ),
			'timezone_string'            => __( 'Timezone', 'activity-log-with-undo' ),
			'gmt_offset'                 => __( 'Timezone offset', 'activity-log-with-undo' ),
			'date_format'                => __( 'Date format', 'activity-log-with-undo' ),
			'time_format'                => __( 'Time format', 'activity-log-with-undo' ),
			'start_of_week'              => __( 'Week starts on', 'activity-log-with-undo' ),
			'posts_per_page'             => __( 'Blog pages show at most', 'activity-log-with-undo' ),
			'show_on_front'              => __( 'Your homepage displays', 'activity-log-with-undo' ),
			'page_on_front'              => __( 'Homepage', 'activity-log-with-undo' ),
			'page_for_posts'             => __( 'Posts page', 'activity-log-with-undo' ),
			'blog_public'                => __( 'Search engine visibility', 'activity-log-with-undo' ),
			'default_comment_status'     => __( 'Allow comments on new posts', 'activity-log-with-undo' ),
			'permalink_structure'        => __( 'Permalink structure', 'activity-log-with-undo' ),
			'category_base'              => __( 'Category base', 'activity-log-with-undo' ),
			'tag_base'                   => __( 'Tag base', 'activity-log-with-undo' ),
			'site_icon'                  => __( 'Site icon', 'activity-log-with-undo' ),
			'sticky_posts'               => __( 'Sticky posts', 'activity-log-with-undo' ),
			'wp_page_for_privacy_policy' => __( 'Privacy policy page', 'activity-log-with-undo' ),
			'sidebars_widgets'           => __( 'Widgets in each widget area', 'activity-log-with-undo' ),
			'nav_menu_options'           => __( 'Menu settings', 'activity-log-with-undo' ),
		);

		if ( isset( $known[ $option ] ) ) {
			return $known[ $option ];
		}
		if ( 0 === strpos( $option, 'widget_' ) ) {
			/* translators: %s: widget type, e.g. "search". */
			return sprintf( __( 'Widget settings: %s', 'activity-log-with-undo' ), substr( $option, 7 ) );
		}
		if ( 0 === strpos( $option, 'theme_mods_' ) ) {
			/* translators: %s: theme folder name. */
			return sprintf( __( 'Customizer settings of %s', 'activity-log-with-undo' ), substr( $option, 11 ) );
		}

		return $option;
	}
}
