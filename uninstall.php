<?php
/**
 * Removes the Activity Log with Undo table, options and scheduled job, on every site.
 *
 * @package ActivityLogWithUndo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Activity Log with Undo data from the current site.
 */
function activity_log_with_undo_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'activity_log_with_undo' ) );

	delete_option( 'activity_log_with_undo_settings' );
	delete_option( 'activity_log_with_undo_db_version' );
	wp_unschedule_hook( 'activity_log_with_undo_purge' );

	// One-time notices waiting for users.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_activity_log_with_undo_' ) . '%', $wpdb->esc_like( '_transient_timeout_activity_log_with_undo_' ) . '%' ) );
}

if ( is_multisite() ) {
	$activity_log_with_undo_offset = 0;
	do {
		$activity_log_with_undo_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $activity_log_with_undo_offset,
			)
		);

		foreach ( $activity_log_with_undo_sites as $activity_log_with_undo_site ) {
			switch_to_blog( $activity_log_with_undo_site );
			activity_log_with_undo_uninstall_site();
			restore_current_blog();
		}

		$activity_log_with_undo_offset += 100;
	} while ( $activity_log_with_undo_sites );
} else {
	activity_log_with_undo_uninstall_site();
}
