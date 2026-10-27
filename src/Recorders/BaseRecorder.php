<?php
/**
 * Shared recorder code.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Logging helper and defaults for recorders.
 */
abstract class BaseRecorder implements Recorder {

	/**
	 * Writes entries.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Sets up the recorder.
	 *
	 * @param Logger $logger Writes entries.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Undoable entries can be undone unless the recorder says otherwise.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		return $entry->undoable ? true : self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * Nothing to write by default.
	 */
	public function flush(): void {
	}

	/**
	 * Logs a change from this recorder.
	 *
	 * @param string     $event       Event.
	 * @param string     $object_type Kind of object.
	 * @param int|string $object_id   Its ID or name.
	 * @param string     $label       Its name, for people.
	 * @param mixed      $before      State before.
	 * @param mixed      $after       State after.
	 * @param bool       $undoable    Whether it can be reversed.
	 * @param bool       $network     Log on the main site.
	 * @param int        $limit       A lower size limit in bytes for the states, or 0 for the usual one.
	 * @return int Entry ID or 0.
	 */
	protected function record( string $event, string $object_type, $object_id, string $label, $before, $after, bool $undoable, bool $network = false, int $limit = 0 ): int {
		return $this->logger->log(
			array(
				'event'        => $event,
				'recorder'     => $this->id(),
				'object_type'  => $object_type,
				'object_id'    => (string) $object_id,
				'object_label' => $label,
				'before'       => $before,
				'after'        => $after,
				'undoable'     => $undoable,
				'network'      => $network,
				'limit'        => $limit,
			)
		);
	}

	/**
	 * An error that says why an entry cannot be undone.
	 *
	 * @param string $message Reason.
	 * @return \WP_Error
	 */
	protected static function refuse( string $message ): \WP_Error {
		return new \WP_Error( 'activity_log_with_undo_cannot_undo', $message );
	}

	/**
	 * Turns a WordPress result into true or the error it returned.
	 *
	 * @param mixed  $result  What WordPress returned.
	 * @param string $message Message when it returned false.
	 * @return true|\WP_Error
	 */
	protected static function result( $result, string $message ) {
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return false === $result ? self::refuse( $message ) : true;
	}
}
