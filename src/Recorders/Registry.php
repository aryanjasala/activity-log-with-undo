<?php
/**
 * The recorders in use.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every recorder, hooks in the ones that are on, and writes what they
 * collected at the end of the request.
 *
 * Recorders that are off still undo their old entries; they only stop logging.
 */
final class Registry implements Service {

	/**
	 * Recorders keyed by ID.
	 *
	 * @var array<string, Recorder>
	 */
	private array $recorders = array();

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the registry.
	 *
	 * @param Recorder[] $recorders Built-in recorders.
	 * @param Settings   $settings  Saved settings.
	 */
	public function __construct( array $recorders, Settings $settings ) {
		$this->settings = $settings;

		/**
		 * Filters the recorders. Add your own (anything implementing
		 * AryanX\Axle\ActivityLogWithUndo\Recorders\Recorder) or remove one.
		 *
		 * @param Recorder[] $recorders Built-in recorders, keyed by ID.
		 */
		$filtered = apply_filters( 'activity_log_with_undo_recorders', self::by_id( $recorders ) );

		$this->recorders = self::by_id( (array) $filtered );
	}

	/**
	 * Keys recorders by ID, dropping anything that is not one.
	 *
	 * @param array $recorders Recorders.
	 * @return array<string, Recorder>
	 */
	private static function by_id( array $recorders ): array {
		$out = array();
		foreach ( $recorders as $recorder ) {
			if ( $recorder instanceof Recorder && '' !== $recorder->id() ) {
				$out[ $recorder->id() ] = $recorder;
			}
		}

		return $out;
	}

	/**
	 * Hooks in the recorders that are on.
	 */
	public function register(): void {
		foreach ( $this->recorders as $id => $recorder ) {
			if ( $this->settings->recorder_enabled( $id ) ) {
				$recorder->register();
			}
		}
	}

	/**
	 * Every recorder, keyed by ID.
	 *
	 * @return array<string, Recorder>
	 */
	public function all(): array {
		return $this->recorders;
	}

	/**
	 * One recorder.
	 *
	 * @param string $id Recorder ID.
	 * @return Recorder|null
	 */
	public function get( string $id ): ?Recorder {
		return $this->recorders[ $id ] ?? null;
	}

	/**
	 * Every event, event => label.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		$events = array( 'undo' => __( 'Undo', 'activity-log-with-undo' ) );
		foreach ( $this->recorders as $recorder ) {
			$events += $recorder->events();
		}

		return $events;
	}

	/**
	 * One line about an entry, for people.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$recorder = $this->get( $entry->recorder );
		if ( 'undo' === $entry->event ) {
			/* translators: %d: entry number. */
			$text = sprintf( __( 'Undid change #%d', 'activity-log-with-undo' ), (int) $entry->undo_of );
		} else {
			$text = $recorder ? $recorder->describe( $entry ) : $entry->event . ' ' . $entry->object_label;
		}

		/**
		 * Filters the one-line description of an entry.
		 *
		 * @param string $text  Description.
		 * @param Entry  $entry The entry.
		 */
		$filtered = (string) apply_filters( 'activity_log_with_undo_entry_description', $text, $entry );

		return '' !== $filtered ? $filtered : $text;
	}

	/**
	 * Writes what the recorders collected. Runs at shutdown, and after each undo.
	 */
	public function flush(): void {
		foreach ( $this->recorders as $recorder ) {
			$recorder->flush();
		}
	}
}
