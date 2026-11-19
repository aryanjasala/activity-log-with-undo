<?php
/**
 * Links from an entry to the plugin that knows more about it.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Integrations;

use AryanX\Axle\ActivityLogWithUndo\Access;
use AryanX\Axle\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * "See what it left" for a deleted plugin or theme, the trash item of a
 * deleted or merged term. A link only appears when the other plugin is
 * active and the person may see the entry.
 */
final class Handoff {

	/**
	 * Links for an entry.
	 *
	 * @param Entry $entry Entry.
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function links( Entry $entry ): array {
		$links = array();
		if ( ! Access::can_view( $entry ) ) {
			return $links;
		}

		$before = (array) $entry->before();
		if ( in_array( $entry->event, array( 'plugin_deleted', 'theme_deleted' ), true ) && Siblings::leftovers_active() && current_user_can( 'manage_options' ) ) {
			$links['leftovers'] = array(
				'label' => __( 'See what it left', 'activity-log-with-undo' ),
				'url'   => Siblings::leftovers_url(),
			);
		}

		$term_event = in_array( $entry->event, array( 'term_deleted', 'ctt_merged' ), true ) && ! empty( $before['ctt_item'] );
		$trash      = new CategoryTagTrash();
		if ( $term_event && $trash->active() ) {
			$name              = 'ctt_merged' === $entry->event ? (string) ( $before['source'] ?? '' ) : $entry->object_label;
			$links['ctt_item'] = array(
				'label' => __( 'Open it in the Category & Tag Trash', 'activity-log-with-undo' ),
				'url'   => CategoryTagTrash::url( $name ),
			);
		}

		if ( 'config_sync_applied' === $entry->event && Siblings::config_engine() ) {
			$links['config_sync'] = array(
				'label' => __( 'Open Config Sync', 'activity-log-with-undo' ),
				'url'   => Siblings::config_sync_url(),
			);
		}

		if ( in_array( $entry->event, array( 'block_restore_applied' ), true ) && current_user_can( 'edit_post', (int) $entry->object_id ) ) {
			$links['post'] = array(
				'label' => __( 'Open the post', 'activity-log-with-undo' ),
				'url'   => (string) get_edit_post_link( (int) $entry->object_id, 'raw' ),
			);
		}

		/**
		 * Filters the links from an entry to other plugins.
		 *
		 * @param array $links Key => array( 'label' => string, 'url' => string ).
		 * @param Entry $entry The entry.
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_entry_links', $links, $entry );

		$out = array();
		foreach ( $filtered as $key => $link ) {
			if ( is_array( $link ) && ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
				$out[ sanitize_key( (string) $key ) ] = array(
					'label' => (string) $link['label'],
					'url'   => (string) $link['url'],
				);
			}
		}

		return $out;
	}
}
