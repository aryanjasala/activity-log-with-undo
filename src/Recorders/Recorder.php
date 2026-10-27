<?php
/**
 * Contract for recorders.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Watches one kind of change, and knows how to reverse it.
 *
 * Add your own with the `activity_log_with_undo_recorders` filter.
 */
interface Recorder {

	/**
	 * Short ID stored with each entry, e.g. options.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Events it logs, event => label.
	 *
	 * @return array<string, string>
	 */
	public function events(): array;

	/**
	 * Hooks into WordPress. Only called when the recorder is on.
	 */
	public function register(): void;

	/**
	 * Whether an entry can be reversed right now.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error The reason when it cannot.
	 */
	public function can_undo( Entry $entry );

	/**
	 * The current state of what the entry changed, in the same shape as its after state.
	 *
	 * Undo only runs without asking when this still equals the after state.
	 *
	 * @param Entry $entry Entry.
	 * @return mixed
	 */
	public function current_state( Entry $entry );

	/**
	 * Puts the before state back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry );

	/**
	 * One line about the change, for people.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string;

	/**
	 * Writes changes it collected during the request. Called at shutdown and after each undo.
	 */
	public function flush(): void;
}
