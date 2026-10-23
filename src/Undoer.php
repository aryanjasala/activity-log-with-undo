<?php
/**
 * Reverses logged changes.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

use AryanX\ActivityLogWithUndo\Recorders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Undoes the change set an entry belongs to.
 *
 * Before anything runs, every entry in the set is compared with how things
 * are now. When something changed since, the undo stops and reports what is
 * different, unless it is forced. The undo is logged like any other change,
 * pointing back at what it reversed, so it can be undone too (redo).
 */
final class Undoer {

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Writes entries.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Recorders.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the undoer.
	 *
	 * @param Store    $store    Entries.
	 * @param Logger   $logger   Writes entries.
	 * @param Registry $registry Recorders.
	 */
	public function __construct( Store $store, Logger $logger, Registry $registry ) {
		$this->store    = $store;
		$this->logger   = $logger;
		$this->registry = $registry;
	}

	/**
	 * Whether one entry can be undone right now, before any conflict check.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( $entry->is_undone() ) {
			/* translators: %d: entry number. */
			return new \WP_Error( 'activity_log_with_undo_already_undone', sprintf( __( 'This change was already undone (see #%d).', 'activity-log-with-undo' ), (int) $entry->undone_by ) );
		}

		$recorder = $this->registry->get( $entry->recorder );
		$can      = $recorder ? $recorder->can_undo( $entry ) : new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'The part of the plugin that logged this change is not loaded, so it cannot be undone.', 'activity-log-with-undo' ) );
		if ( true === $can && ! $entry->undoable ) {
			$can = new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
		}

		/**
		 * Filters whether an entry can be undone.
		 *
		 * Return a WP_Error to refuse with your own reason. Entries logged
		 * without their before state can never be undone, whatever this returns.
		 *
		 * @param true|\WP_Error $can   True, or the reason it cannot.
		 * @param Entry          $entry The entry.
		 */
		$filtered = apply_filters( 'activity_log_with_undo_can_undo', $can, $entry );

		if ( true === $filtered && $entry->undoable && $recorder ) {
			return true;
		}
		if ( $filtered instanceof \WP_Error ) {
			return $filtered;
		}

		return $can instanceof \WP_Error ? $can : new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * The entries an undo of this entry reverses: the entry, and the other
	 * changes saved in the same request that can still be undone.
	 *
	 * @param Entry $entry Entry.
	 * @return Entry[] Oldest first.
	 */
	public function members( Entry $entry ): array {
		return $this->members_of( $entry, $this->store->change_set( $entry->change_set ) );
	}

	/**
	 * The entries of a change set that an undo of one of them reverses.
	 *
	 * Entries that cannot be undone or were undone already are left out, and
	 * so are entries a recorder says do not belong with the rest: options that
	 * are not logged any more, such as another plugin's cache that version 1
	 * logged next to a person's change. Such an entry, undone directly, is
	 * undone on its own.
	 *
	 * @param Entry   $entry The entry being undone.
	 * @param Entry[] $set   Every entry of its change set.
	 * @return Entry[] Oldest first.
	 */
	public function members_of( Entry $entry, array $set ): array {
		// An entry that does not belong with the rest (another plugin's option) is undone on its own.
		$set     = $this->belongs( $entry ) ? $set : array();
		$members = array();
		foreach ( $set as $item ) {
			if ( $item->change_set !== $entry->change_set || ! $item->undoable || $item->is_undone() ) {
				continue;
			}
			if ( $item->id === $entry->id || $this->belongs( $item ) ) {
				$members[ $item->id ] = $item;
			}
		}
		if ( $entry->undoable && ! $entry->is_undone() ) {
			$members[ $entry->id ] = $entry;
		}

		/**
		 * Filters the entries undone together with an entry.
		 *
		 * Remove entries to leave them alone. Only entries of the same change
		 * set count, and the entry itself is always undone.
		 *
		 * @param Entry[] $members The entries, keyed by ID.
		 * @param Entry   $entry   The entry being undone.
		 */
		/**
		 * Whatever the filter returned.
		 *
		 * @var array<mixed> $filtered
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_change_set_entries', $members, $entry );

		$out = array();
		foreach ( $filtered as $item ) {
			if ( $item instanceof Entry && isset( $members[ $item->id ] ) ) {
				$out[ $item->id ] = $item;
			}
		}
		if ( isset( $members[ $entry->id ] ) ) {
			$out[ $entry->id ] = $members[ $entry->id ];
		}
		ksort( $out );

		return array_values( $out );
	}

	/**
	 * Whether another entry of the change set is undone together with the one asked for.
	 *
	 * @param Entry $item Entry.
	 * @return bool
	 */
	private function belongs( Entry $item ): bool {
		$recorder = $this->registry->get( $item->recorder );
		if ( $recorder && method_exists( $recorder, 'in_change_set' ) ) {
			return (bool) $recorder->in_change_set( $item );
		}

		return true;
	}

	/**
	 * What undoing an entry would do: the entries in its change set, the ones
	 * that cannot be undone, and the ones that changed since.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array{entry: Entry, entries: Entry[], blocked: array<int, \WP_Error>, conflicts: array[]}|\WP_Error
	 */
	public function check( int $entry_id ) {
		$entry = $this->store->get( $entry_id );
		if ( ! $entry ) {
			return new \WP_Error( 'activity_log_with_undo_not_found', __( 'That log entry does not exist.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
		}
		if ( $entry->is_undone() || ! $entry->undoable ) {
			$can = $this->can_undo( $entry );
			return $can instanceof \WP_Error ? $can : new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
		}

		$entries = $this->members( $entry );

		$blocked = array();
		foreach ( $entries as $item ) {
			$can = $this->can_undo( $item );
			if ( $can instanceof \WP_Error ) {
				$blocked[ $item->id ] = $can;
			}
		}

		// When one change set touched the same thing twice, only its last state can be compared with now.
		$latest = array();
		foreach ( $entries as $item ) {
			if ( ! isset( $blocked[ $item->id ] ) ) {
				$latest[ $item->recorder . '|' . $item->event . '|' . $item->object_type . '|' . $item->object_id ] = $item;
			}
		}

		$conflicts = array();
		foreach ( $latest as $item ) {
			$recorder = $this->registry->get( $item->recorder );
			$current  = $recorder ? $recorder->current_state( $item ) : null;
			if ( ! Payload::same( $current, $item->after() ) ) {
				$conflicts[] = array(
					'id'       => $item->id,
					'expected' => $item->after(),
					'current'  => $current,
				);
			}
		}

		return array(
			'entry'     => $entry,
			'entries'   => $entries,
			'blocked'   => $blocked,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Undoes an entry and the rest of its change set.
	 *
	 * @param int  $entry_id Entry ID.
	 * @param bool $force    Undo even when things changed since.
	 * @return array{undone: int[], failed: array<int, string>, entries: int[], forced: bool}|\WP_Error
	 */
	public function undo( int $entry_id, bool $force = false ) {
		$check = $this->check( $entry_id );
		if ( $check instanceof \WP_Error ) {
			return $check;
		}

		if ( $check['conflicts'] && ! $force ) {
			/**
			 * Filters the message shown when something changed since the entry was logged.
			 *
			 * @param string $message Message.
			 * @param Entry  $entry   The entry.
			 */
			$message = (string) apply_filters( 'activity_log_with_undo_conflict_message', __( 'This changed again since. Look at the differences, then use Undo anyway to put the old version back.', 'activity-log-with-undo' ), $check['entry'] );

			return new \WP_Error(
				'activity_log_with_undo_conflict',
				$message,
				array(
					'status'    => 409,
					'conflicts' => $check['conflicts'],
				)
			);
		}

		if ( count( $check['blocked'] ) === count( $check['entries'] ) ) {
			$first = reset( $check['blocked'] );
			return $first instanceof \WP_Error ? $first : new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
		}

		$undone  = array();
		$failed  = array();
		$written = array();

		$this->logger->start_undo_run();
		foreach ( array_reverse( $check['entries'] ) as $entry ) {
			if ( isset( $check['blocked'][ $entry->id ] ) ) {
				$failed[ $entry->id ] = $check['blocked'][ $entry->id ]->get_error_message();
				continue;
			}

			/**
			 * Fires before an entry is undone.
			 *
			 * @param Entry $entry The entry.
			 * @param bool  $force Whether the undo was forced past a conflict.
			 */
			do_action( 'activity_log_with_undo_before_undo', $entry, $force );

			$result = $this->run( $entry );
			$ids    = $this->logger->end_undo();

			if ( $result instanceof \WP_Error ) {
				$failed[ $entry->id ] = $result->get_error_message();

				/**
				 * Fires when undoing an entry failed.
				 *
				 * @param Entry     $entry The entry.
				 * @param \WP_Error $error What went wrong.
				 */
				do_action( 'activity_log_with_undo_undo_failed', $entry, $result );
				continue;
			}

			if ( ! $ids ) {
				// The recorder that would log the reversal is off: write the undo down here.
				$this->logger->begin_undo( $entry->id );
				$this->logger->log(
					array(
						'event'        => 'undo',
						'recorder'     => $entry->recorder,
						'object_type'  => $entry->object_type,
						'object_id'    => $entry->object_id,
						'object_label' => $entry->object_label,
						'before'       => $entry->after(),
						'after'        => $entry->before(),
						'undoable'     => false,
					)
				);
				$ids = $this->logger->end_undo();
			}

			if ( $ids ) {
				$this->store->mark_undone( $entry->id, (int) $ids[0] );
			}
			$undone[] = $entry->id;
			$written  = array_merge( $written, $ids );

			/**
			 * Fires after an entry is undone.
			 *
			 * @param Entry $entry   The entry that was undone.
			 * @param int[] $entries Entries that logged the reversal.
			 */
			do_action( 'activity_log_with_undo_undone', $entry, $ids );
		}
		$this->logger->finish_undo_run();

		if ( ! $undone ) {
			return new \WP_Error( 'activity_log_with_undo_failed', implode( ' ', array_unique( $failed ) ), array( 'failed' => $failed ) );
		}

		return array(
			'undone'  => $undone,
			'failed'  => $failed,
			'entries' => $written,
			'forced'  => $force && (bool) $check['conflicts'],
		);
	}

	/**
	 * Runs one recorder's undo, and writes what the recorders collected.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	private function run( Entry $entry ) {
		$recorder = $this->registry->get( $entry->recorder );
		if ( ! $recorder ) {
			return new \WP_Error( 'activity_log_with_undo_cannot_undo', __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
		}

		$this->logger->begin_undo( $entry->id );
		try {
			$result = $recorder->undo( $entry );
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'activity_log_with_undo_error', $error->getMessage() );
		}
		$this->registry->flush();

		return $result instanceof \WP_Error ? $result : true;
	}
}
