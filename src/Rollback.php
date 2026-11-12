<?php
/**
 * Puts the site back the way it was at a moment in time.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and runs a roll back.
 *
 * The plan lists every change made since the chosen moment, newest first, each
 * marked as one that will be undone, one that cannot be undone (with the
 * reason) or one that a later change not in the list got in the way of. The
 * run undoes change sets newest first, a few at a time, so no single request
 * runs long. Everything it reverses is logged in one change set together with
 * one entry that says what the roll back did, so a single Redo puts it back.
 */
final class Rollback {

	public const JOB_OPTION  = 'activity_log_with_undo_rollback_job';
	public const LOCK_OPTION = 'activity_log_with_undo_rollback_lock';

	/**
	 * A job nobody has touched for this long counts as abandoned.
	 */
	public const STALE_AFTER = HOUR_IN_SECONDS;

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
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Recorders.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the roll back.
	 *
	 * @param Store    $store    Entries.
	 * @param Logger   $logger   Writes entries.
	 * @param Undoer   $undoer   Reverses changes.
	 * @param Registry $registry Recorders.
	 */
	public function __construct( Store $store, Logger $logger, Undoer $undoer, Registry $registry ) {
		$this->store    = $store;
		$this->logger   = $logger;
		$this->undoer   = $undoer;
		$this->registry = $registry;
	}

	/**
	 * Whether the current user may roll back.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		if ( ! is_user_logged_in() ) {
			return defined( 'WP_CLI' ) && WP_CLI;
		}

		/**
		 * Filters the capability needed to roll the site back to a moment.
		 *
		 * Rolling back changes many things at once, so a site can ask for more than
		 * it asks for to read the log. Each change still needs its own object
		 * capability. The default is the capability that opens the log.
		 *
		 * @param string $capability Default: the log capability, manage_options.
		 */
		$capability = (string) apply_filters( 'activity_log_with_undo_rollback_capability', LogPage::capability() );

		return current_user_can( '' !== $capability ? $capability : LogPage::capability() );
	}

	/**
	 * The moments the screen offers.
	 *
	 * @return array<string, array{label: string, time: int}>
	 */
	public function presets(): array {
		$now     = time();
		$zone    = wp_timezone();
		$morning = ( new \DateTimeImmutable( 'today 06:00', $zone ) )->getTimestamp();
		$presets = array(
			'hour'      => array(
				'label' => __( '1 hour ago', 'activity-log-with-undo' ),
				'time'  => $now - HOUR_IN_SECONDS,
			),
			'morning'   => array(
				'label' => __( 'This morning (6 am)', 'activity-log-with-undo' ),
				'time'  => $morning > $now ? $morning - DAY_IN_SECONDS : $morning,
			),
			'yesterday' => array(
				'label' => __( 'Start of yesterday', 'activity-log-with-undo' ),
				'time'  => ( new \DateTimeImmutable( 'yesterday 00:00', $zone ) )->getTimestamp(),
			),
		);

		/**
		 * Filters the moments the Roll back screen offers.
		 *
		 * @param array $presets Key => array( 'label' => string, 'time' => Unix time in the past ).
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_rollback_presets', $presets );

		$out = array();
		foreach ( $filtered as $key => $preset ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && is_array( $preset ) && ! empty( $preset['label'] ) && isset( $preset['time'] ) && (int) $preset['time'] > 0 && (int) $preset['time'] <= $now ) {
				$out[ $key ] = array(
					'label' => (string) $preset['label'],
					'time'  => (int) $preset['time'],
				);
			}
		}

		return $out;
	}

	/**
	 * Works out the moment to roll back to.
	 *
	 * @param string $to A preset key, an entry number ("just before this change"), or a time in the site's time zone.
	 * @return array{since: string, min_id: int, label: string, timestamp: int}|\WP_Error
	 */
	public function resolve( string $to ) {
		$to = trim( $to );
		if ( '' === $to ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_time', __( 'Pick the time to roll back to.', 'activity-log-with-undo' ), array( 'status' => 400 ) );
		}

		$presets = $this->presets();
		if ( isset( $presets[ $to ] ) ) {
			return array(
				'since'     => gmdate( 'Y-m-d H:i:s', $presets[ $to ]['time'] ),
				'min_id'    => 0,
				'label'     => $presets[ $to ]['label'],
				'timestamp' => $presets[ $to ]['time'],
			);
		}

		if ( ctype_digit( $to ) ) {
			$entry = $this->store->get( (int) $to );
			if ( ! $entry ) {
				return new \WP_Error( 'activity_log_with_undo_not_found', __( 'That log entry does not exist.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
			}
			$first = $this->store->first_in_set( $entry->change_set );

			return array(
				'since'     => $entry->created_gmt,
				'min_id'    => $first > 0 ? $first : $entry->id,
				/* translators: %d: entry number. */
				'label'     => sprintf( __( 'just before change #%d', 'activity-log-with-undo' ), $entry->id ),
				'timestamp' => $entry->timestamp(),
			);
		}

		try {
			$time = ( new \DateTimeImmutable( str_replace( 'T', ' ', $to ), wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_time', __( 'That is not a time WordPress can read. Try a date and time such as 2026-11-13 14:00.', 'activity-log-with-undo' ), array( 'status' => 400 ) );
		}
		if ( $time > time() ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_time', __( 'That time is in the future. Pick a time that has passed.', 'activity-log-with-undo' ), array( 'status' => 400 ) );
		}

		return array(
			'since'     => gmdate( 'Y-m-d H:i:s', $time ),
			'min_id'    => 0,
			'label'     => $to,
			'timestamp' => $time,
		);
	}

	/**
	 * Most entries one run reverses.
	 *
	 * @return int
	 */
	public static function run_limit(): int {
		/**
		 * Filters how many changes one roll back reverses. The rest wait for the next run.
		 *
		 * The reversals share one change set, and a change set holds 500 entries at most.
		 *
		 * @param int $limit Default 400, between 1 and 500.
		 */
		return max( 1, min( Store::MAX_PER_PAGE, (int) apply_filters( 'activity_log_with_undo_rollback_max_entries', 400 ) ) );
	}

	/**
	 * Change sets reversed per request.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many change sets one request of a roll back reverses.
		 *
		 * @param int $size Default 10, between 1 and 100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'activity_log_with_undo_rollback_batch_size', 10 ) ) );
	}

	/**
	 * What a roll back would do.
	 *
	 * @param array $args to (preset, entry number or time), user (ID, or '' for everyone), area (recorder ID, or '').
	 * @return array|\WP_Error See the keys below.
	 */
	public function plan( array $args ) {
		$when = $this->resolve( (string) ( $args['to'] ?? '' ) );
		if ( $when instanceof \WP_Error ) {
			return $when;
		}

		$user = isset( $args['user'] ) && '' !== $args['user'] ? (int) $args['user'] : '';
		$area = sanitize_key( (string) ( $args['area'] ?? '' ) );
		if ( '' !== $area && ! $this->registry->get( $area ) ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_area', __( 'That area is not one this plugin logs.', 'activity-log-with-undo' ), array( 'status' => 400 ) );
		}

		/**
		 * Filters how many log entries a roll back looks at.
		 *
		 * @param int $limit Default 2000.
		 */
		$scan_limit = max( 10, (int) apply_filters( 'activity_log_with_undo_rollback_scan_limit', 2000 ) );

		$found     = array();
		$scanned   = 0;
		$truncated = false;
		$page      = 1;
		do {
			$batch = $this->store->find(
				array(
					'since'    => $when['since'],
					'min_id'   => $when['min_id'],
					'user'     => $user,
					'recorder' => $area,
					'order'    => 'DESC',
					'per_page' => Store::MAX_PER_PAGE,
					'page'     => $page,
				)
			);
			foreach ( $batch['entries'] as $entry ) {
				$found[ $entry->id ] = $entry;
			}
			$scanned += count( $batch['entries'] );
			++$page;
			if ( $scanned >= $scan_limit && $scanned < $batch['total'] ) {
				$truncated = true;
				break;
			}
		} while ( $scanned < $batch['total'] && $batch['entries'] );

		$plan = $this->classify( $found, $when['since'] );

		$plan['since']     = $when['since'];
		$plan['timestamp'] = $when['timestamp'];
		$plan['label']     = $when['label'];
		$plan['to']        = (string) $args['to'];
		$plan['user']      = $user;
		$plan['area']      = $area;
		$plan['scanned']   = $scanned;
		$plan['truncated'] = $truncated;

		return $plan;
	}

	/**
	 * Sorts the entries since a moment into what will be undone, what cannot be, and what clashes.
	 *
	 * @param Entry[] $found Entries, keyed by ID.
	 * @param string  $since The moment, UTC `Y-m-d H:i:s`.
	 * @return array{items: array[], runs: array[], counts: array<string, int>, deferred: int}
	 */
	private function classify( array $found, string $since ): array {
		$items    = array();
		$eligible = array();
		krsort( $found );

		foreach ( $found as $entry ) {
			if ( 'rollback' === $entry->recorder || $entry->is_undone() ) {
				continue;
			}
			if ( 'undo' === $entry->event && ! $entry->undoable ) {
				continue;
			}
			// An undo of something that happened after the moment cancels out with it.
			if ( $entry->undo_of ) {
				$target = isset( $found[ $entry->undo_of ] ) ? $found[ $entry->undo_of ] : $this->store->get( $entry->undo_of );
				if ( $target && $target->created_gmt >= $since ) {
					continue;
				}
			}
			if ( ! $entry->undoable ) {
				$can                 = $this->undoer->can_undo( $entry );
				$items[ $entry->id ] = array(
					'entry'  => $entry,
					'status' => 'blocked',
					'reason' => $can instanceof \WP_Error ? $can->get_error_message() : __( 'This change cannot be undone.', 'activity-log-with-undo' ),
					'set'    => $entry->change_set,
				);
				continue;
			}
			$eligible[ $entry->id ] = $entry;
		}

		// The newest change per thing: anything older is covered once the newer one is undone.
		$newest = array();
		foreach ( $eligible as $id => $entry ) {
			$key = $entry->recorder . '|' . $entry->object_type . '|' . $entry->object_id;
			if ( ! isset( $newest[ $key ] ) ) {
				$newest[ $key ] = $id;
			}
		}

		$by_set = array();
		foreach ( $eligible as $entry ) {
			$by_set[ $entry->change_set ][ $entry->id ] = $entry;
		}

		$runs = array();
		foreach ( $by_set as $set => $entries ) {
			$remaining = array_keys( $entries );
			while ( $remaining ) {
				$rep   = (int) reset( $remaining );
				$check = $this->undoer->check( $rep, $remaining );

				if ( $check instanceof \WP_Error ) {
					$covered = array( $rep );
					$blocked = array( $rep => $check );
					$members = array();
					$clashes = array();
				} else {
					$members = array_map(
						static function ( Entry $item ) {
							return $item->id;
						},
						$check['entries']
					);
					$covered = $members ? $members : array( $rep );
					$blocked = $check['blocked'];
					$clashes = array();
					foreach ( $check['conflicts'] as $conflict ) {
						$clashes[ (int) $conflict['id'] ] = true;
					}
				}

				$will = 0;
				$bad  = false;
				foreach ( $covered as $id ) {
					$entry = $entries[ $id ];
					$key   = $entry->recorder . '|' . $entry->object_type . '|' . $entry->object_id;
					if ( isset( $blocked[ $id ] ) ) {
						$status = 'blocked';
						$reason = $blocked[ $id ]->get_error_message();
					} elseif ( isset( $clashes[ $id ] ) && ( $newest[ $key ] ?? 0 ) === $id ) {
						$status = 'conflict';
						$reason = __( 'Changed again by something that is not in this list.', 'activity-log-with-undo' );
						$bad    = true;
						++$will;
					} else {
						$status = 'will';
						$reason = '';
						++$will;
					}
					$items[ $id ] = array(
						'entry'  => $entry,
						'status' => $status,
						'reason' => $reason,
						'set'    => $set,
					);
				}

				if ( $will ) {
					$runs[] = array(
						'entry'    => $rep,
						'only'     => $covered,
						'set'      => $set,
						'changes'  => $will,
						'conflict' => $bad,
						'newest'   => max( $covered ),
					);
				}

				$remaining = array_values( array_diff( $remaining, $covered, array( $rep ) ) );
			}
		}

		usort(
			$runs,
			static function ( $a, $b ) {
				return $b['newest'] <=> $a['newest'];
			}
		);

		// Cap one run, so the reversals fit in one change set; the rest waits for the next run.
		$limit    = self::run_limit();
		$taken    = 0;
		$kept     = array();
		$deferred = 0;
		foreach ( $runs as $run ) {
			if ( $taken + $run['changes'] > $limit && $kept ) {
				$deferred += $run['changes'];
				foreach ( $run['only'] as $id ) {
					if ( isset( $items[ $id ] ) && 'blocked' !== $items[ $id ]['status'] ) {
						$items[ $id ]['deferred'] = true;
					}
				}
				continue;
			}
			$taken += $run['changes'];
			$kept[] = $run;
		}

		krsort( $items );
		$counts = array(
			'will'     => 0,
			'blocked'  => 0,
			'conflict' => 0,
			'sets'     => count( $kept ),
		);
		foreach ( $items as $item ) {
			++$counts[ $item['status'] ];
		}

		return array(
			'items'    => array_values( $items ),
			'runs'     => $kept,
			'counts'   => $counts,
			'deferred' => $deferred,
		);
	}

	/**
	 * The unfinished job, if there is one.
	 *
	 * @return array|null
	 */
	public function job(): ?array {
		$job = get_option( self::JOB_OPTION, null );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Whether a job is old enough to be replaced.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	public static function is_stale( array $job ): bool {
		return (int) ( $job['updated'] ?? 0 ) < time() - self::STALE_AFTER;
	}

	/**
	 * Starts a roll back from a plan.
	 *
	 * @param array $plan         Plan from plan().
	 * @param bool  $stop         Stop at the first change that clashes, instead of skipping it.
	 * @return array|\WP_Error The job.
	 */
	public function start( array $plan, bool $stop = false ) {
		if ( ! self::allowed() ) {
			return new \WP_Error( 'activity_log_with_undo_forbidden', __( 'You are not allowed to roll the site back.', 'activity-log-with-undo' ), array( 'status' => 403 ) );
		}
		if ( empty( $plan['runs'] ) ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_empty', __( 'There is nothing to undo since then.', 'activity-log-with-undo' ), array( 'status' => 400 ) );
		}

		$old = $this->job();
		if ( $old ) {
			if ( ! self::is_stale( $old ) ) {
				return new \WP_Error( 'activity_log_with_undo_rollback_busy', __( 'Another roll back is still running. Let it finish, or cancel it, before you start a new one.', 'activity-log-with-undo' ), array( 'status' => 409 ) );
			}
			$this->finish( $old, 'abandoned' );
		}

		$job = array(
			'id'         => str_replace( '-', '', wp_generate_uuid4() ),
			'user'       => get_current_user_id(),
			'created'    => time(),
			'updated'    => time(),
			'state'      => 'running',
			'stop'       => $stop,
			'since'      => (string) $plan['since'],
			'label'      => (string) $plan['label'],
			'change_set' => str_replace( '-', '', wp_generate_uuid4() ),
			'runs'       => $plan['runs'],
			'cursor'     => 0,
			'summary'    => 0,
			'undone'     => array(),
			'skipped'    => array(),
			'deferred'   => (int) ( $plan['deferred'] ?? 0 ),
			'blocked'    => (int) ( $plan['counts']['blocked'] ?? 0 ),
		);
		update_option( self::JOB_OPTION, $job, false );

		/**
		 * Fires before a roll back starts undoing changes.
		 *
		 * @param array $job  The job: id, user, since, label, runs (entry, only, set, changes, conflict), stop.
		 * @param array $plan What the preview showed.
		 */
		do_action( 'activity_log_with_undo_before_rollback', $job, $plan );

		return $job;
	}

	/**
	 * Reverses the next few change sets of the job.
	 *
	 * @param string $job_id    Job ID.
	 * @param int    $time_cap  Stop starting new change sets after this many seconds. 0 for no cap.
	 * @return array|\WP_Error state (running, done, stopped, cancelled), job, done, total, undone, skipped, summary, deferred.
	 */
	public function step( string $job_id, int $time_cap = 0 ) {
		$job = $this->job();
		if ( ! $job || $job['id'] !== $job_id ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_gone', __( 'This roll back is not running any more. Open the log to see what it did.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
		}
		if ( ! self::allowed() || ( is_user_logged_in() && get_current_user_id() !== (int) $job['user'] ) ) {
			return new \WP_Error( 'activity_log_with_undo_forbidden', __( 'You are not allowed to continue this roll back.', 'activity-log-with-undo' ), array( 'status' => 403 ) );
		}
		if ( 'running' !== $job['state'] ) {
			return $this->state( $job );
		}
		if ( ! $this->lock() ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_busy', __( 'This roll back is working on the previous step. Wait a moment.', 'activity-log-with-undo' ), array( 'status' => 409 ) );
		}

		$started = time();
		$batch   = self::batch_size();
		$total   = count( $job['runs'] );
		$this->logger->pin_change_set( (string) $job['change_set'] );

		try {
			$done = 0;
			while ( (int) $job['cursor'] < $total && $done < $batch ) {
				if ( $time_cap > 0 && $done > 0 && time() - $started >= $time_cap ) {
					break;
				}
				$run = $job['runs'][ (int) $job['cursor'] ];
				if ( ! $job['summary'] ) {
					$job['summary'] = $this->logger->log(
						array(
							'event'        => 'rollback',
							'recorder'     => 'rollback',
							'object_type'  => 'rollback',
							'object_id'    => $job['id'],
							'object_label' => (string) $job['label'],
							'before'       => null,
							'after'        => $this->summary_state( $job ),
							'undoable'     => true,
						)
					);
				}

				$result = $this->undoer->undo( (int) $run['entry'], false, array_map( 'intval', (array) $run['only'] ) );
				if ( $result instanceof \WP_Error ) {
					$conflict = 'activity_log_with_undo_conflict' === $result->get_error_code();
					foreach ( (array) $run['only'] as $id ) {
						$job['skipped'][] = array(
							'id'       => (int) $id,
							'reason'   => $result->get_error_message(),
							'conflict' => $conflict,
						);
					}
					if ( $conflict && $job['stop'] ) {
						$job['state'] = 'stopped';
						++$job['cursor'];
						break;
					}
				} else {
					$job['undone'] = array_merge( $job['undone'], $result['undone'] );
					foreach ( $result['failed'] as $id => $reason ) {
						$job['skipped'][] = array(
							'id'       => (int) $id,
							'reason'   => (string) $reason,
							'conflict' => false,
						);
					}
				}

				++$job['cursor'];
				++$done;
				$job['updated'] = time();
				update_option( self::JOB_OPTION, $job, false );
			}
		} finally {
			$this->logger->unpin_change_set();
			$this->unlock();
		}

		if ( (int) $job['cursor'] >= $total && 'running' === $job['state'] ) {
			$job['state'] = 'done';
		}
		$job['updated'] = time();

		if ( 'running' !== $job['state'] ) {
			return $this->finish( $job, $job['state'] );
		}

		update_option( self::JOB_OPTION, $job, false );

		return $this->state( $job );
	}

	/**
	 * Stops a job. What it already reversed stays reversed, and can be redone.
	 *
	 * @param string $job_id Job ID.
	 * @return array|\WP_Error
	 */
	public function cancel( string $job_id ) {
		$job = $this->job();
		if ( ! $job || $job['id'] !== $job_id ) {
			return new \WP_Error( 'activity_log_with_undo_rollback_gone', __( 'This roll back is not running any more. Open the log to see what it did.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
		}
		if ( ! self::allowed() ) {
			return new \WP_Error( 'activity_log_with_undo_forbidden', __( 'You are not allowed to cancel this roll back.', 'activity-log-with-undo' ), array( 'status' => 403 ) );
		}

		return $this->finish( $job, 'cancelled' );
	}

	/**
	 * Runs a whole roll back, for WP-CLI and abilities.
	 *
	 * @param array $plan     Plan from plan().
	 * @param bool  $stop     Stop at the first clash.
	 * @param int   $time_cap Seconds to work before handing back a running job. 0 for no cap.
	 * @return array|\WP_Error
	 */
	public function run( array $plan, bool $stop = false, int $time_cap = 0 ) {
		$job = $this->start( $plan, $stop );
		if ( $job instanceof \WP_Error ) {
			return $job;
		}

		return $this->continue_job( (string) $job['id'], $time_cap );
	}

	/**
	 * Keeps stepping a job until it ends or the time cap passes.
	 *
	 * @param string $job_id   Job ID.
	 * @param int    $time_cap Seconds. 0 for no cap.
	 * @return array|\WP_Error
	 */
	public function continue_job( string $job_id, int $time_cap = 0 ) {
		$started = time();
		do {
			$state = $this->step( $job_id, $time_cap );
			if ( $state instanceof \WP_Error || 'running' !== $state['state'] ) {
				return $state;
			}
		} while ( 0 === $time_cap || time() - $started < $time_cap );

		return $state;
	}

	/**
	 * Where a job stands.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private function state( array $job ): array {
		$skipped = array();
		foreach ( (array) $job['skipped'] as $item ) {
			$skipped[] = array(
				'id'       => (int) $item['id'],
				'reason'   => (string) $item['reason'],
				'conflict' => ! empty( $item['conflict'] ),
			);
		}

		return array(
			'state'    => (string) $job['state'],
			'job'      => (string) $job['id'],
			'done'     => (int) $job['cursor'],
			'total'    => count( (array) $job['runs'] ),
			'undone'   => count( array_unique( (array) $job['undone'] ) ),
			'skipped'  => $skipped,
			'summary'  => (int) $job['summary'],
			'deferred' => (int) $job['deferred'],
		);
	}

	/**
	 * What the summary entry records.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private function summary_state( array $job ): array {
		$skipped = array();
		foreach ( array_slice( (array) $job['skipped'], 0, 100 ) as $item ) {
			$skipped[] = array(
				'id'     => (int) $item['id'],
				'reason' => (string) $item['reason'],
			);
		}

		return array(
			'since'    => (string) $job['since'],
			'label'    => (string) $job['label'],
			'state'    => (string) $job['state'],
			'undone'   => count( array_unique( (array) $job['undone'] ) ),
			'skipped'  => $skipped,
			'deferred' => (int) $job['deferred'],
		);
	}

	/**
	 * Ends a job: writes the summary, drops the job and tells the hooks.
	 *
	 * @param array  $job   Job.
	 * @param string $state done, stopped, cancelled or abandoned.
	 * @return array
	 */
	private function finish( array $job, string $state ): array {
		$job['state'] = $state;
		if ( $job['summary'] ) {
			$fields = array( 'after_state' => Payload::encode( $this->summary_state( $job ) ) );
			if ( ! $job['undone'] ) {
				$fields['undoable'] = 0;
			}
			$this->store->update( (int) $job['summary'], $fields );
		}
		delete_option( self::JOB_OPTION );

		$result = $this->state( $job );

		/**
		 * Fires when a roll back ends, by finishing, stopping at a clash, being cancelled or being abandoned.
		 *
		 * @param array $result state, job, done, total, undone, skipped (id, reason, conflict), summary (entry ID), deferred.
		 * @param array $job    The job.
		 */
		do_action( 'activity_log_with_undo_after_rollback', $result, $job );

		return $result;
	}

	/**
	 * Closes a job nobody finished.
	 *
	 * @param array $job Job.
	 */
	public function abandon( array $job ): void {
		$this->finish( $job, 'abandoned' );
	}

	/**
	 * Takes the step lock.
	 *
	 * @return bool False when another request holds it.
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		// A request that died leaves the lock behind; it expires after a minute.
		if ( (int) get_option( self::LOCK_OPTION, 0 ) < time() - MINUTE_IN_SECONDS ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Releases the step lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * A sentence that says how a roll back ended.
	 *
	 * @param array $result State from step().
	 * @return string
	 */
	public static function message( array $result ): string {
		$undone = (int) $result['undone'];
		if ( 'cancelled' === $result['state'] || 'abandoned' === $result['state'] ) {
			/* translators: %d: number of changes. */
			$text = sprintf( _n( 'Roll back cancelled after %d change was undone.', 'Roll back cancelled after %d changes were undone.', $undone, 'activity-log-with-undo' ), $undone );
		} elseif ( 'stopped' === $result['state'] ) {
			/* translators: %d: number of changes. */
			$text = sprintf( _n( 'Roll back stopped at a clash after %d change was undone.', 'Roll back stopped at a clash after %d changes were undone.', $undone, 'activity-log-with-undo' ), $undone );
		} else {
			/* translators: %d: number of changes. */
			$text = sprintf( _n( 'Rolled back %d change.', 'Rolled back %d changes.', $undone, 'activity-log-with-undo' ), $undone );
		}

		/**
		 * Filters the message shown when a roll back ends.
		 *
		 * @param string $text   Message.
		 * @param array  $result state, job, done, total, undone, skipped, summary, deferred.
		 */
		return (string) apply_filters( 'activity_log_with_undo_rollback_message', $text, $result );
	}
}
