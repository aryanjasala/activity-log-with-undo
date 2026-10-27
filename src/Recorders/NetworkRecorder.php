<?php
/**
 * Sites added to and removed from a multisite network.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Logs new and deleted sites on the main site's log. Logged only: creating or
 * deleting a whole site is too big to reverse from a log.
 */
final class NetworkRecorder extends BaseRecorder {

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'network';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Network sites (multisite)', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'site_created' => __( 'Site added to the network', 'activity-log-with-undo' ),
			'site_deleted' => __( 'Site deleted from the network', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into site creation and deletion.
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'wp_initialize_site', array( $this, 'created' ), 100 );
		add_action( 'wp_delete_site', array( $this, 'deleted' ) );
	}

	/**
	 * A site was added.
	 *
	 * @param mixed $site New site (WP_Site).
	 */
	public function created( $site ): void {
		if ( $site instanceof \WP_Site ) {
			$this->record( 'site_created', 'site', $site->blog_id, $site->domain . $site->path, null, array( 'url' => $site->domain . $site->path ), false, true );
		}
	}

	/**
	 * A site was deleted.
	 *
	 * @param mixed $site Deleted site (WP_Site).
	 */
	public function deleted( $site ): void {
		if ( $site instanceof \WP_Site ) {
			$this->record( 'site_deleted', 'site', $site->blog_id, $site->domain . $site->path, array( 'url' => $site->domain . $site->path ), null, false, true );
		}
	}

	/**
	 * Sites are never undone.
	 *
	 * @param Entry $entry Entry.
	 * @return \WP_Error
	 */
	public function can_undo( Entry $entry ) {
		return self::refuse( __( 'Adding or deleting a site is logged only. Manage sites under Network Admin > Sites.', 'activity-log-with-undo' ) );
	}

	/**
	 * Nothing to compare.
	 *
	 * @param Entry $entry Entry.
	 * @return null
	 */
	public function current_state( Entry $entry ) {
		return null;
	}

	/**
	 * Refused.
	 *
	 * @param Entry $entry Entry.
	 * @return \WP_Error
	 */
	public function undo( Entry $entry ) {
		return $this->can_undo( $entry );
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		/* translators: %s: site address. */
		return sprintf( 'site_created' === $entry->event ? __( 'Added the site %s', 'activity-log-with-undo' ) : __( 'Deleted the site %s', 'activity-log-with-undo' ), $entry->object_label );
	}
}
