<?php
/**
 * The entry a roll back leaves behind.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * A roll back writes one entry that says what it did. The changes it reversed
 * are logged as undos in the same change set, so Redo on this entry (or on any
 * of them) puts everything back. This recorder hooks into nothing: it is only
 * here so the entry has a name, a description and an undo.
 */
final class RollbackRecorder extends BaseRecorder {

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rollback';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Roll backs', 'activity-log-with-undo' );
	}

	/**
	 * Whether the settings screen offers a switch for this recorder. It does not: roll backs are always logged.
	 *
	 * @return bool
	 */
	public function hidden(): bool {
		return true;
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array( 'rollback' => __( 'Rolled back', 'activity-log-with-undo' ) );
	}

	/**
	 * Nothing to hook into.
	 */
	public function register(): void {
	}

	/**
	 * The entry itself changes nothing, so it always matches.
	 *
	 * @param Entry $entry Entry.
	 * @return mixed
	 */
	public function current_state( Entry $entry ) {
		return $entry->after();
	}

	/**
	 * Nothing to write: the changes it reversed are undone, newest first, as members of the same change set.
	 *
	 * @param Entry $entry Entry.
	 * @return true
	 */
	public function undo( Entry $entry ) {
		return true;
	}

	/**
	 * One line about the roll back.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$after = (array) $entry->after();
		$count = (int) ( $after['undone'] ?? 0 );
		$since = (string) ( $after['since_label'] ?? '' );

		if ( '' === $since ) {
			/* translators: %d: number of changes. */
			return sprintf( _n( 'Rolled back %d change', 'Rolled back %d changes', $count, 'activity-log-with-undo' ), $count );
		}

		/* translators: 1: number of changes, 2: the time the site was rolled back to. */
		return sprintf( _n( 'Rolled back %1$d change to %2$s', 'Rolled back %1$d changes to %2$s', $count, 'activity-log-with-undo' ), $count, $since );
	}
}
