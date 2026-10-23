<?php
/**
 * The log table.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the per-site table with dbDelta.
 *
 * `before` is a reserved word in MySQL, so the states live in `before_state`
 * and `after_state`.
 */
final class Schema {

	public const VERSION_OPTION = 'activity_log_with_undo_db_version';

	/**
	 * Bump when the table changes.
	 */
	public const DB_VERSION = '2';

	/**
	 * Table name for the current site.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'activity_log_with_undo';
	}

	/**
	 * Activation: creates the table on this site and schedules the clean-up.
	 */
	public static function activate(): void {
		self::install();
		Retention::schedule();
	}

	/**
	 * Creates or upgrades the table when the stored version is older.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the plugin's own table, for Site Health.
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
	}

	/**
	 * Runs dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		$installed = get_option( self::VERSION_OPTION );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				change_set char(32) NOT NULL DEFAULT '',
				event varchar(64) NOT NULL DEFAULT '',
				recorder varchar(32) NOT NULL DEFAULT '',
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id varchar(191) NOT NULL DEFAULT '',
				object_label varchar(255) NOT NULL DEFAULT '',
				actor_user_id bigint(20) NOT NULL DEFAULT 0,
				actor_via varchar(20) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				before_state longtext NULL,
				after_state longtext NULL,
				undoable tinyint(1) NOT NULL DEFAULT 0,
				undone_by bigint(20) unsigned NULL,
				undo_of bigint(20) unsigned NULL,
				agent varchar(191) NOT NULL DEFAULT '',
				agent_request bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_gmt (created_gmt),
				KEY change_set (change_set),
				KEY object (object_type,object_id(64)),
				KEY actor (actor_user_id,undoable),
				KEY event (event)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION );

		// Version 1 logged every option, including other plugins' internal state. Offer to delete those entries.
		if ( false !== $installed && version_compare( (string) $installed, '2', '<' ) ) {
			Cleanup::flag();
		} elseif ( false === $installed ) {
			Cleanup::nothing_to_do();
		}
	}

	/**
	 * Drops the table on the current site. Used by uninstall.php.
	 */
	public static function drop(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}
}
