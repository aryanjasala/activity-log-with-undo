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
 * Logs settings people change on purpose: the core settings screens, options
 * registered with the Settings API, widgets and theme mods, plus anything on
 * the always-logged list.
 *
 * Other plugins keep working state in options and rewrite it all the time
 * (caches, indexes, counters). Those are not settings, so they are left out.
 * Settings are only logged when a person started the request (wp-admin, the
 * REST API, WP-CLI); a cron job changing one is not logged unless the option
 * is on the always-logged list. Repeated writes of one option in a request
 * become one entry, and each option is rate-limited across requests.
 */
final class OptionsRecorder extends BaseRecorder {

	/**
	 * Values of options about to be deleted, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $deleting = array();

	/**
	 * Compiled patterns, cached for the request, keyed by list name.
	 *
	 * @var array<string, array{0: array<string, bool>, 1: string[]}>
	 */
	private array $compiled = array();

	/**
	 * Entries written in this request, keyed by change set, then by option, so
	 * writing the same option again in the request updates that entry.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $open = array();

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
			'option_added'           => __( 'Setting added', 'activity-log-with-undo' ),
			'option_updated'         => __( 'Setting changed', 'activity-log-with-undo' ),
			'option_deleted'         => __( 'Setting deleted', 'activity-log-with-undo' ),
			'network_option_added'   => __( 'Network setting added', 'activity-log-with-undo' ),
			'network_option_updated' => __( 'Network setting changed', 'activity-log-with-undo' ),
			'network_option_deleted' => __( 'Network setting deleted', 'activity-log-with-undo' ),
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
	 * Options left out by default, even when they look like settings. `*` matches anything.
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
	 * Settings people change on purpose: what the core settings screens save
	 * (the options.php allowlist, permalinks, privacy), widgets, theme mods
	 * and menu settings. `*` matches anything.
	 *
	 * Options registered with register_setting() count too, without being listed here.
	 *
	 * @param bool $network Network settings (Network Admin > Settings) instead.
	 * @return string[]
	 */
	public static function default_settings( bool $network = false ): array {
		if ( $network ) {
			return array(
				'site_name',
				'admin_email',
				'registration',
				'registrationnotification',
				'add_new_users',
				'menu_items',
				'upload_space_check_disabled',
				'blog_upload_space',
				'upload_filetypes',
				'fileupload_maxk',
				'first_post',
				'first_page',
				'first_comment',
				'first_comment_url',
				'first_comment_author',
				'first_comment_email',
				'welcome_email',
				'welcome_user_email',
				'illegal_names',
				'limited_email_domains',
				'banned_email_domains',
				'WPLANG',
			);
		}

		return array(
			// Settings > General.
			'blogname',
			'blogdescription',
			'site_icon',
			'siteurl',
			'home',
			'admin_email',
			'users_can_register',
			'default_role',
			'WPLANG',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
			// Settings > Writing.
			'default_category',
			'default_email_category',
			'default_link_category',
			'default_post_format',
			'mailserver_url',
			'mailserver_port',
			'mailserver_login',
			'mailserver_pass',
			'ping_sites',
			'use_smilies',
			'use_balanceTags',
			// Settings > Reading.
			'posts_per_page',
			'posts_per_rss',
			'rss_use_excerpt',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'blog_public',
			'blog_charset',
			// Settings > Discussion.
			'default_pingback_flag',
			'default_ping_status',
			'default_comment_status',
			'comments_notify',
			'moderation_notify',
			'comment_moderation',
			'require_name_email',
			'comment_previously_approved',
			'comment_max_links',
			'moderation_keys',
			'disallowed_keys',
			'show_avatars',
			'avatar_rating',
			'avatar_default',
			'close_comments_for_old_posts',
			'close_comments_days_old',
			'thread_comments',
			'thread_comments_depth',
			'page_comments',
			'comments_per_page',
			'default_comments_page',
			'comment_order',
			'comment_registration',
			'show_comments_cookies_opt_in',
			'wp_notes_notify',
			// Settings > Media.
			'thumbnail_size_w',
			'thumbnail_size_h',
			'thumbnail_crop',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
			'image_default_size',
			'image_default_align',
			'image_default_link_type',
			'uploads_use_yearmonth_folders',
			'upload_path',
			'upload_url_path',
			// Settings > Permalinks and Settings > Privacy.
			'permalink_structure',
			'category_base',
			'tag_base',
			'wp_page_for_privacy_policy',
			// Appearance: site logo, widgets, Customizer settings, menu settings. Sticky posts.
			'site_logo',
			'sidebars_widgets',
			'widget_*',
			'theme_mods_*',
			'nav_menu_options',
			'sticky_posts',
		);
	}

	/**
	 * Patterns of one list, compiled once per request.
	 *
	 * @param string $which ignored, always or settings / network_settings.
	 * @return array{0: array<string, bool>, 1: string[]} Exact names, and regular expressions for the rest.
	 */
	private function patterns( string $which ): array {
		if ( isset( $this->compiled[ $which ] ) ) {
			return $this->compiled[ $which ];
		}

		if ( 'ignored' === $which ) {
			/**
			 * Filters which options are never logged. Each item is a name, `*` matches anything.
			 *
			 * The default covers transients, caches, cron, locks, salts, and options
			 * other recorders handle (active plugins, the theme). The ignore list
			 * wins over every other list.
			 *
			 * @param string[] $patterns Option names and patterns.
			 */
			$patterns = (array) apply_filters( 'activity_log_with_undo_ignored_options', self::default_ignored() );

			// Our own options are never logged, whatever the filters say, or saving the settings would log itself.
			$patterns[] = 'activity_log_with_undo_*';
		} elseif ( 'always' === $which ) {
			/**
			 * Filters the options that are always logged, on top of the settings.
			 *
			 * These are logged in every request, even a cron job or a visitor's
			 * page view, so only add options that change rarely and that a
			 * person cares about. Each item is a name, `*` matches anything.
			 *
			 * @param string[] $patterns Default none.
			 */
			$patterns = (array) apply_filters( 'activity_log_with_undo_logged_options', array() );
		} else {
			$network = 'network_settings' === $which;

			/**
			 * Filters which options count as settings people change on purpose.
			 *
			 * Settings are logged when a person changes them in wp-admin, through
			 * the REST API or with WP-CLI. Options registered with
			 * register_setting() count as settings without being listed. Each item
			 * is a name, `*` matches anything.
			 *
			 * @param string[] $patterns Default: the core settings screens, widgets, theme mods and menu settings.
			 * @param bool     $network  Whether these are network settings (multisite).
			 */
			$patterns = (array) apply_filters( 'activity_log_with_undo_setting_options', self::default_settings( $network ), $network );
		}

		$exact   = array();
		$regexes = array();
		foreach ( array_filter( $patterns, 'is_scalar' ) as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false === strpos( $pattern, '*' ) ) {
				$exact[ $pattern ] = true;
			} else {
				$regexes[] = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			}
		}

		$this->compiled[ $which ] = array( $exact, $regexes );

		return $this->compiled[ $which ];
	}

	/**
	 * Whether a name is on one of the lists.
	 *
	 * @param string $name Option name.
	 * @param string $which ignored, always, settings or network_settings.
	 * @return bool
	 */
	private function on( string $name, string $which ): bool {
		list( $exact, $regexes ) = $this->patterns( $which );
		if ( isset( $exact[ $name ] ) ) {
			return true;
		}
		foreach ( $regexes as $regex ) {
			if ( preg_match( $regex, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an option is a setting people change on purpose.
	 *
	 * @param string $option  Option name.
	 * @param bool   $network A network option.
	 * @return bool
	 */
	public function is_setting( string $option, bool $network = false ): bool {
		if ( $this->on( $option, $network ? 'network_settings' : 'settings' ) ) {
			return true;
		}
		if ( $network || ! function_exists( 'get_registered_settings' ) ) {
			return false;
		}

		return array_key_exists( $option, (array) get_registered_settings() );
	}

	/**
	 * Whether changes to an option are logged at all: a setting, or on the
	 * always-logged list, and not ignored. Whatever the request.
	 *
	 * @param string $option  Option name.
	 * @param bool   $network A network option.
	 * @return bool
	 */
	public function is_tracked( string $option, bool $network = false ): bool {
		if ( $this->on( $option, 'ignored' ) ) {
			return false;
		}

		return $this->on( $option, 'always' ) || $this->is_setting( $option, $network );
	}

	/**
	 * Whether a change to an option is logged in this request.
	 *
	 * Always-logged options are; settings only when a person started the request.
	 *
	 * @param string $option  Option name.
	 * @param bool   $network A network option.
	 * @return bool
	 */
	public function is_logged( string $option, bool $network = false ): bool {
		if ( $this->on( $option, 'ignored' ) ) {
			return false;
		}
		if ( $this->on( $option, 'always' ) ) {
			return true;
		}
		if ( ! $this->is_setting( $option, $network ) ) {
			return false;
		}
		if ( $this->logger->context()->user_initiated() ) {
			return true;
		}

		self::skipped( $option, 'background' );

		return false;
	}

	/**
	 * Whether an entry of this recorder is undone with the rest of its change set.
	 *
	 * Entries of options that are not logged any more (other plugins' state,
	 * logged before version 2) are left out, so undoing a person's change never
	 * writes back another plugin's cache.
	 *
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	public function in_change_set( Entry $entry ): bool {
		if ( ! in_array( $entry->object_type, array( 'option', 'network_option' ), true ) ) {
			return true;
		}

		return $this->is_tracked( $entry->object_id, 'network_option' === $entry->object_type );
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
	 * Largest before or after value kept for an option, in bytes.
	 *
	 * @param string $option Option name.
	 * @return int 0 for the usual payload limit only.
	 */
	public static function size_limit( string $option ): int {
		/**
		 * Filters the largest value of an option kept with its entry, in bytes.
		 *
		 * Settings are small. A bigger value is another plugin's data, so the
		 * change is logged without its values and cannot be undone. The payload
		 * limit from the settings still applies when it is lower. 0 leaves only
		 * the payload limit.
		 *
		 * @param int    $bytes  Default 65536 (64 KB).
		 * @param string $option Option name.
		 */
		return max( 0, (int) apply_filters( 'activity_log_with_undo_option_size_limit', 64 * KB_IN_BYTES, $option ) );
	}

	/**
	 * Whether an option was logged too often lately, counting this change if not.
	 *
	 * @param string $option  Option name.
	 * @param bool   $network A network option.
	 * @return bool
	 */
	public static function rate_limited( string $option, bool $network = false ): bool {
		/**
		 * Filters how many entries one option may get within the rate window.
		 *
		 * A person does not change one setting dozens of times a minute; code
		 * does. Past the limit, changes are not logged until the window ends.
		 * 0 turns the limit off.
		 *
		 * @param int    $limit  Default 20.
		 * @param string $option Option name.
		 */
		$limit = max( 0, (int) apply_filters( 'activity_log_with_undo_option_rate_limit', 20, $option ) );
		if ( 0 === $limit ) {
			return false;
		}

		/**
		 * Filters the window of the per-option rate limit, in seconds.
		 *
		 * @param int    $seconds Default 60.
		 * @param string $option  Option name.
		 */
		$window = max( 1, (int) apply_filters( 'activity_log_with_undo_option_rate_window', MINUTE_IN_SECONDS, $option ) );

		$key = 'activity_log_with_undo_rate_' . md5( ( $network ? 'network:' : '' ) . $option );
		$now = time();
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) || ! isset( $hit[0], $hit[1] ) || (int) $hit[0] + $window <= $now || (int) $hit[0] > $now ) {
			$hit = array( $now, 0 );
		}
		if ( (int) $hit[1] >= $limit ) {
			return true;
		}
		set_transient( $key, array( (int) $hit[0], (int) $hit[1] + 1 ), max( 1, (int) $hit[0] + $window - $now ) );

		return false;
	}

	/**
	 * Announces a settings change that was left out of the log.
	 *
	 * @param string $option Option name.
	 * @param string $reason background (no person started the request) or rate_limited.
	 */
	private static function skipped( string $option, string $reason ): void {
		/**
		 * Fires when a change to a setting is not logged.
		 *
		 * @param string $option Option name.
		 * @param string $reason background: changed in a request no person started (cron, a
		 *                       visitor); rate_limited: changed too often lately.
		 */
		do_action( 'activity_log_with_undo_option_skipped', $option, $reason );
	}

	/**
	 * Logs a change to an option, or folds it into the entry this request already wrote for it.
	 *
	 * @param string $event   Event.
	 * @param string $option  Option name.
	 * @param mixed  $before  Old value.
	 * @param mixed  $after   New value.
	 * @param bool   $network A network option.
	 */
	private function write( string $event, string $option, $before, $after, bool $network = false ): void {
		$type   = $network ? 'network_option' : 'option';
		$key    = $type . '|' . $option;
		$set    = $this->logger->current_change_set();
		$merge  = ! $this->logger->undoing() && false !== strpos( $event, '_updated' );
		$limit  = self::size_limit( $option );
		$latest = $this->open[ $set ][ $key ] ?? 0;

		if ( $merge && $latest ) {
			$this->logger->update_after( $latest, $after, $limit );
			return;
		}

		if ( self::rate_limited( $option, $network ) ) {
			self::skipped( $option, 'rate_limited' );
			return;
		}

		// Translations load at init; a setting changed earlier keeps its name and gets its label when shown.
		$label    = did_action( 'init' ) ? self::label_for( $option ) : $option;
		$undoable = ! ( false !== strpos( $event, '_deleted' ) && ( null === $before || false === $before ) );
		if ( 'network_option_deleted' === $event ) {
			$undoable = false;
		}

		$id = $this->record( $event, $type, $option, $label, $before, $after, $undoable, $network, $limit );
		if ( $id && ! $this->logger->undoing() && false === strpos( $event, '_deleted' ) ) {
			$this->open[ $this->logger->current_change_set() ][ $key ] = $id;
		} else {
			unset( $this->open[ $set ][ $key ] );
		}
	}

	/**
	 * An option was added.
	 *
	 * @param string $option Name.
	 * @param mixed  $value  Value.
	 */
	public function added( $option, $value ): void {
		// WordPress adds some options empty the first time a screen is saved (WPLANG on Settings > General). Nothing changes.
		if ( ! in_array( $value, array( '', null, false, array() ), true ) && $this->is_logged( (string) $option ) ) {
			$this->write( 'option_added', (string) $option, null, $value );
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
			$this->write( 'option_updated', (string) $option, $old_value, $value );
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

		$this->write( 'option_deleted', $option, $before, null );
	}

	/**
	 * A network option was added.
	 *
	 * @param string $option Name.
	 * @param mixed  $value  Value.
	 */
	public function network_added( $option, $value ): void {
		if ( $this->is_logged( (string) $option, true ) ) {
			$this->write( 'network_option_added', (string) $option, null, $value, true );
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
		if ( $this->is_logged( (string) $option, true ) && ! Payload::same( $old_value, $value ) ) {
			$this->write( 'network_option_updated', (string) $option, $old_value, $value, true );
		}
	}

	/**
	 * A network option was deleted. WordPress has no generic hook before that, so the old value is unknown.
	 *
	 * @param string $option Name.
	 */
	public function network_deleted( $option ): void {
		if ( $this->is_logged( (string) $option, true ) ) {
			$this->write( 'network_option_deleted', (string) $option, null, null, true );
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
		// Changes logged before translations were loaded keep the option name; name them now.
		$label = '' !== $entry->object_label && $entry->object_label !== $entry->object_id ? $entry->object_label : self::label_for( $entry->object_id );

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
		$registered = function_exists( 'get_registered_settings' ) ? (array) get_registered_settings() : array();
		if ( ! empty( $registered[ $option ]['label'] ) ) {
			return (string) $registered[ $option ]['label'];
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
