<?php
/**
 * Writes entries.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a recorder saw into a log entry.
 *
 * Every entry written in one request shares a change set ID, so one save that
 * changed five options is undone as one change. While an undo runs, entries
 * get a fresh change set and point back at the entry they reverse.
 */
final class Logger {

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Where changes come from.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Change set of this request.
	 *
	 * @var string
	 */
	private string $change_set = '';

	/**
	 * Entries written in the current change set.
	 *
	 * @var int
	 */
	private int $in_set = 0;

	/**
	 * The entry being undone, while an undo runs.
	 *
	 * @var int|null
	 */
	private ?int $undo_of = null;

	/**
	 * Entries written by the current undo, keyed by the entry they reverse.
	 *
	 * @var array<int, int[]>
	 */
	private array $undo_entries = array();

	/**
	 * Sets up the logger.
	 *
	 * @param Store    $store    Entries.
	 * @param Settings $settings Saved settings.
	 * @param Context  $context  Where changes come from.
	 */
	public function __construct( Store $store, Settings $settings, Context $context ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->context  = $context;
	}

	/**
	 * Where changes come from, for recorders that decide what to log by it.
	 *
	 * @return Context
	 */
	public function context(): Context {
		return $this->context;
	}

	/**
	 * Logs a change.
	 *
	 * @param array $data event, recorder, object_type, object_id, object_label, before, after,
	 *                    undoable, network (log on the main site), and limit (a lower size
	 *                    limit in bytes for this entry's states, 0 for the usual one).
	 * @return int Entry ID, or 0 when nothing was logged.
	 */
	public function log( array $data ): int {
		$data = wp_parse_args(
			$data,
			array(
				'event'        => '',
				'recorder'     => '',
				'object_type'  => '',
				'object_id'    => '',
				'object_label' => '',
				'before'       => null,
				'after'        => null,
				'undoable'     => false,
				'network'      => false,
				'limit'        => 0,
			)
		);

		/**
		 * Filters whether a change is logged.
		 *
		 * @param bool   $log   Default true.
		 * @param string $event Event, e.g. option_updated or post_trashed.
		 * @param array  $data  event, recorder, object_type, object_id, object_label, before, after, undoable.
		 */
		if ( ! apply_filters( 'activity_log_with_undo_should_log', true, (string) $data['event'], $data ) ) {
			return 0;
		}

		$limit    = $this->limit( (int) $data['limit'] );
		$full     = $this->log_full();
		$undoable = (bool) $data['undoable'];
		$states   = array();
		foreach ( array( 'before', 'after' ) as $which ) {
			$encoded = Payload::encode( $data[ $which ] );
			if ( null !== $encoded && ( $full || strlen( $encoded ) > $limit ) ) {
				$encoded  = Payload::over_limit( strlen( $encoded ), $full ? Payload::LOG_FULL : Payload::TOO_BIG );
				$undoable = false;
			}
			$states[ $which ] = $encoded;
		}
		$agent = $this->context->agent();

		$row = array(
			'created_gmt'   => current_time( 'mysql', true ),
			'change_set'    => $this->change_set(),
			'event'         => substr( sanitize_key( (string) $data['event'] ), 0, 64 ),
			'recorder'      => substr( sanitize_key( (string) $data['recorder'] ), 0, 32 ),
			'object_type'   => substr( sanitize_key( (string) $data['object_type'] ), 0, 32 ),
			'object_id'     => substr( (string) $data['object_id'], 0, 191 ),
			'object_label'  => wp_html_excerpt( wp_strip_all_tags( (string) $data['object_label'] ), 250, '…' ),
			'actor_user_id' => get_current_user_id(),
			'actor_via'     => $this->context->via(),
			'ip'            => $this->context->ip(),
			'before_state'  => $states['before'],
			'after_state'   => $states['after'],
			'undoable'      => $undoable ? 1 : 0,
			'undo_of'       => $this->undo_of,
			'agent'         => $agent ? $agent['agent'] : '',
			'agent_request' => $agent ? $agent['request'] : 0,
			'approved_by'   => $agent ? $agent['approved_by'] : 0,
		);

		$switched = $data['network'] && is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		$id = $this->store->insert( $row );
		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! $id ) {
			return 0;
		}

		++$this->in_set;
		if ( null !== $this->undo_of ) {
			$this->undo_entries[ $this->undo_of ][] = $id;
		}

		/**
		 * Fires after a change is logged.
		 *
		 * @param Entry $entry The new entry.
		 */
		do_action( 'activity_log_with_undo_logged', Entry::from_row( array( 'id' => $id ) + $row ) );

		return $id;
	}

	/**
	 * Replaces the after state of an entry written earlier in this request.
	 *
	 * Used when one change fires several hooks, such as a role change, or
	 * writes the same option again.
	 *
	 * @param int   $id    Entry ID.
	 * @param mixed $after New after state.
	 * @param int   $limit A lower size limit in bytes for this entry, or 0 for the usual one.
	 */
	public function update_after( int $id, $after, int $limit = 0 ): void {
		$encoded = Payload::encode( $after );
		$fields  = array( 'after_state' => $encoded );
		if ( null !== $encoded && strlen( $encoded ) > $this->limit( $limit ) ) {
			$fields = array(
				'after_state' => Payload::over_limit( strlen( $encoded ) ),
				'undoable'    => 0,
			);
		}
		$this->store->update( $id, $fields );
	}

	/**
	 * Largest state stored with an entry, in bytes.
	 *
	 * @param int $lower A lower limit for this entry, or 0.
	 * @return int
	 */
	private function limit( int $lower ): int {
		$limit = $this->settings->payload_limit();

		return $lower > 0 ? min( $limit, $lower ) : $limit;
	}

	/**
	 * Largest the log table may grow, in bytes, before changes are logged without their states.
	 *
	 * @return int 0 for no limit.
	 */
	public static function max_log_size(): int {
		/**
		 * Filters how big the log table may grow, in bytes.
		 *
		 * Past it, changes are still logged, but without their before and
		 * after states, so they cannot be undone. Site Health says so. 0 turns
		 * the limit off.
		 *
		 * @param int $bytes Default 536870912 (512 MB).
		 */
		return max( 0, (int) apply_filters( 'activity_log_with_undo_max_log_size', 512 * MB_IN_BYTES ) );
	}

	/**
	 * Whether the log table is over its size limit.
	 *
	 * @return bool
	 */
	public function log_full(): bool {
		$max = self::max_log_size();

		return $max > 0 && $this->store->size() > $max;
	}

	/**
	 * Whether an undo is running, so its changes are logged as reversals.
	 *
	 * @return bool
	 */
	public function undoing(): bool {
		return null !== $this->undo_of;
	}

	/**
	 * Change set ID of the current request or undo.
	 *
	 * @return string
	 */
	public function change_set(): string {
		/**
		 * Filters how many entries one change set holds before a new one starts.
		 *
		 * Keeps a long WP-CLI import from becoming one giant change to undo.
		 *
		 * @param int $size Default 100.
		 */
		$size = max( 1, (int) apply_filters( 'activity_log_with_undo_change_set_size', 100 ) );

		if ( '' === $this->change_set || ( null === $this->undo_of && $this->in_set >= $size ) ) {
			$this->new_change_set();
		}

		return $this->change_set;
	}

	/**
	 * Change set ID without starting a new one, for recorders that merge hooks within a change set.
	 *
	 * @return string
	 */
	public function current_change_set(): string {
		if ( '' === $this->change_set ) {
			$this->new_change_set();
		}

		return $this->change_set;
	}

	/**
	 * Starts an undo run: what it changes goes in a change set of its own.
	 */
	public function start_undo_run(): void {
		$this->undo_of      = null;
		$this->undo_entries = array();
		$this->new_change_set();
	}

	/**
	 * Starts logging the reversal of an entry.
	 *
	 * @param int $entry_id The entry being undone.
	 */
	public function begin_undo( int $entry_id ): void {
		$this->undo_of                   = $entry_id;
		$this->undo_entries[ $entry_id ] = $this->undo_entries[ $entry_id ] ?? array();
	}

	/**
	 * Stops logging the reversal of an entry.
	 *
	 * @return int[] Entries written while it ran.
	 */
	public function end_undo(): array {
		$ids           = null === $this->undo_of ? array() : ( $this->undo_entries[ $this->undo_of ] ?? array() );
		$this->undo_of = null;

		return $ids;
	}

	/**
	 * Closes an undo run, so the next change starts its own change set.
	 */
	public function finish_undo_run(): void {
		$this->undo_of      = null;
		$this->undo_entries = array();
		$this->new_change_set();
	}

	/**
	 * Starts a new change set.
	 */
	private function new_change_set(): void {
		$this->change_set = str_replace( '-', '', wp_generate_uuid4() );
		$this->in_set     = 0;
	}
}
