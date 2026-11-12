<?php
/**
 * Tests for rolling back to a moment: the plan, the run and its hooks.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Logger;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\RollbackRecorder;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RollbackTest extends LogTestCase {

	/**
	 * Values of "things", as the fake recorder sees them.
	 *
	 * @var array<string, string>
	 */
	private $things = array();

	/**
	 * Fake recorder.
	 *
	 * @var FakeRecorder
	 */
	private $recorder;

	/**
	 * Logger shared by the undoer and the recorder.
	 *
	 * @var Logger
	 */
	private $logger;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);

		$this->things            = array();
		$this->logger            = $this->logger();
		$this->recorder          = new FakeRecorder();
		$this->recorder->current = function ( Entry $entry ) {
			return $this->things[ $entry->object_id ] ?? null;
		};
		$this->recorder->on_undo = function ( Entry $entry ) {
			$this->things[ $entry->object_id ] = $entry->before();
			$this->logger->log(
				array(
					'event'       => 'fake_changed',
					'recorder'    => 'fake',
					'object_type' => 'thing',
					'object_id'   => $entry->object_id,
					'before'      => $entry->after(),
					'after'       => $entry->before(),
					'undoable'    => true,
				)
			);
		};
	}

	private function rollback(): Rollback {
		$registry = new Registry( array( $this->recorder, new RollbackRecorder( $this->logger ) ), new Settings() );
		$undoer   = new Undoer( $this->store, $this->logger, $registry );

		return new Rollback( $this->store, $this->logger, $undoer, $registry );
	}

	/**
	 * Makes the store return these entries, oldest first as given, newest first as the plan reads them.
	 *
	 * @param Entry[] $entries Entries.
	 */
	private function found( array $entries ): void {
		$this->store->shouldReceive( 'find' )->andReturn(
			array(
				'entries' => array_reverse( $entries ),
				'total'   => count( $entries ),
			)
		);
	}

	private function change( array $row ): Entry {
		return $this->entry( $row + array( 'created_gmt' => '2026-11-13 13:00:00' ) );
	}

	/**
	 * A thing that was changed from one value to another.
	 *
	 * @param string $name   Thing.
	 * @param string $before Value before.
	 * @param string $after  Value after.
	 * @param array  $extra  More columns.
	 * @return Entry
	 */
	private function changed( string $name, string $before, string $after, array $extra = array() ): Entry {
		return $this->change(
			$extra + array(
				'object_id' => $name,
				'before'    => $before,
				'after'     => $after,
			)
		);
	}

	public function test_a_moment_can_be_a_preset_an_entry_or_a_time(): void {
		$rollback = $this->rollback();

		$hour = $rollback->resolve( 'hour' );
		$this->assertIsArray( $hour );
		$this->assertSame( '1 hour ago', $hour['label'] );
		$this->assertEqualsWithDelta( time() - HOUR_IN_SECONDS, $hour['timestamp'], 5 );

		$entry = $this->change( array( 'created_gmt' => '2026-11-13 09:30:00' ) );
		$this->store->shouldReceive( 'first_in_set' )->with( $entry->change_set )->andReturn( $entry->id );
		$before = $rollback->resolve( (string) $entry->id );
		$this->assertSame( $entry->id, $before['min_id'] );
		$this->assertSame( '2026-11-13 09:30:00', $before['since'] );

		$time = $rollback->resolve( '2026-01-01T14:00' );
		$this->assertSame( '2026-01-01 14:00:00', $time['since'] );
	}

	public function test_a_missing_moment_a_future_time_and_an_unknown_entry_are_refused(): void {
		$rollback = $this->rollback();
		$this->store->shouldReceive( 'get' )->with( 999 )->andReturn( null );

		$this->assertSame( 'activity_log_with_undo_rollback_time', $rollback->resolve( '' )->get_error_code() );
		$this->assertSame( 'activity_log_with_undo_rollback_time', $rollback->resolve( '+2 days' )->get_error_code() );
		$this->assertSame( 'activity_log_with_undo_not_found', $rollback->resolve( '999' )->get_error_code() );
	}

	public function test_the_presets_are_filterable_and_bad_ones_are_dropped(): void {
		Filters\expectApplied( 'activity_log_with_undo_rollback_presets' )->once()->andReturn(
			array(
				'lunch' => array(
					'label' => 'Before lunch',
					'time'  => time() - 600,
				),
				'later' => array(
					'label' => 'In the future',
					'time'  => time() + 600,
				),
				'bare'  => 'not an array',
			)
		);

		$this->assertSame( array( 'lunch' ), array_keys( $this->rollback()->presets() ) );
	}

	public function test_the_plan_lists_changes_newest_first_and_says_why_some_cannot_be_undone(): void {
		$this->things = array(
			'a' => 'z',
			'b' => 2,
		);
		$first        = $this->changed( 'a', 'x', 'y' );
		$second       = $this->changed( 'a', 'y', 'z' );
		$logged_only  = $this->change(
			array(
				'object_id' => 'b',
				'undoable'  => 0,
			)
		);
		$this->found( array( $first, $second, $logged_only ) );

		$plan = $this->rollback()->plan( array( 'to' => '2026-01-01 12:00' ) );

		$ids = array();
		foreach ( $plan['items'] as $item ) {
			$ids[] = $item['entry']->id;
		}
		$this->assertSame( array( $logged_only->id, $second->id, $first->id ), $ids );
		$this->assertSame( 'blocked', $plan['items'][0]['status'] );
		$this->assertNotSame( '', $plan['items'][0]['reason'] );
		// The older change on the same thing is covered once the newer one is undone, so it is no clash.
		$this->assertSame( 'will', $plan['items'][1]['status'] );
		$this->assertSame( 'will', $plan['items'][2]['status'] );
		$this->assertSame(
			array(
				'will'     => 2,
				'blocked'  => 1,
				'conflict' => 0,
				'sets'     => 2,
			),
			$plan['counts']
		);
		$this->assertSame( $second->id, $plan['runs'][0]['entry'] );
		$this->assertSame( $first->id, $plan['runs'][1]['entry'] );
	}

	public function test_a_change_made_again_by_something_not_in_the_list_is_a_clash(): void {
		$this->things = array( 'a' => 'someone else' );
		$first        = $this->changed( 'a', 'x', 'y' );
		$second       = $this->changed( 'a', 'y', 'z' );
		$this->found( array( $first, $second ) );

		$plan = $this->rollback()->plan( array( 'to' => '2026-01-01 12:00' ) );

		$this->assertSame( 'conflict', $plan['items'][0]['status'] );
		$this->assertSame( 'will', $plan['items'][1]['status'] );
		$this->assertSame( 1, $plan['counts']['conflict'] );
		$this->assertTrue( $plan['runs'][0]['conflict'] );
	}

	public function test_undone_changes_and_the_undos_that_cancel_with_them_are_left_out(): void {
		$undone  = $this->change( array( 'undone_by' => 3 ) );
		$redo    = $this->change( array( 'undo_of' => $undone->id ) );
		$summary = $this->change(
			array(
				'recorder' => 'rollback',
				'event'    => 'rollback',
			)
		);
		$this->found( array( $undone, $redo, $summary ) );

		$plan = $this->rollback()->plan( array( 'to' => '2026-01-01 12:00' ) );

		$this->assertSame( array(), $plan['items'] );
	}

	public function test_an_area_the_plugin_does_not_log_is_refused(): void {
		$plan = $this->rollback()->plan(
			array(
				'to'   => '2026-01-01 12:00',
				'area' => 'nonsense',
			)
		);

		$this->assertSame( 'activity_log_with_undo_rollback_area', $plan->get_error_code() );
	}

	public function test_one_run_stops_at_the_limit_and_leaves_the_rest_for_the_next_one(): void {
		Filters\expectApplied( 'activity_log_with_undo_rollback_max_entries' )->andReturn( 2 );
		$entries = array();
		foreach ( array( 'a', 'b', 'c' ) as $name ) {
			$this->things[ $name ] = 'after';
			$entries[]             = $this->changed( $name, 'before', 'after' );
		}
		$this->found( $entries );

		$plan = $this->rollback()->plan( array( 'to' => '2026-01-01 12:00' ) );

		$this->assertCount( 2, $plan['runs'] );
		$this->assertSame( 1, $plan['deferred'] );
		$this->assertTrue( $plan['items'][2]['deferred'] );
	}

	public function test_a_roll_back_undoes_newest_first_in_one_change_set_with_one_summary_entry(): void {
		$this->things = array(
			'a' => 'z',
			'b' => '2',
		);
		$first        = $this->changed( 'a', 'x', 'y' );
		$second       = $this->changed( 'a', 'y', 'z' );
		$third        = $this->changed( 'b', '1', '2' );
		$this->found( array( $first, $second, $third ) );
		Actions\expectDone( 'activity_log_with_undo_before_rollback' )->once();
		Actions\expectDone( 'activity_log_with_undo_after_rollback' )->once();

		$rollback = $this->rollback();
		$plan     = $rollback->plan( array( 'to' => '2026-01-01 12:00' ) );
		$state    = $rollback->run( $plan );

		$this->assertSame( 'done', $state['state'] );
		$this->assertSame( 3, $state['undone'] );
		$this->assertSame( array(), $state['skipped'] );
		// Newest first: b, then a's second change, then a's first.
		$this->assertSame( array( $third->id, $second->id, $first->id ), $this->recorder->undone );
		$this->assertSame( 'x', $this->things['a'] );
		$this->assertSame( '1', $this->things['b'] );

		$summary = $this->rows[ $state['summary'] ];
		$this->assertSame( 'rollback', $summary['event'] );
		$this->assertSame( 1, $summary['undoable'] );
		$set = $summary['change_set'];
		foreach ( $this->rows as $id => $row ) {
			if ( ! empty( $row['undo_of'] ) ) {
				$this->assertSame( $set, $row['change_set'], "Undo entry #{$id} shares the roll back's change set." );
			}
		}
		$this->assertArrayNotHasKey( Rollback::JOB_OPTION, $this->options );
	}

	public function test_a_clash_is_skipped_and_reported_unless_the_run_stops_at_it(): void {
		$this->things = array(
			'a' => 'someone else',
			'b' => '2',
		);
		$clash        = $this->changed( 'a', 'x', 'y' );
		$fine         = $this->changed( 'b', '1', '2' );
		$this->found( array( $clash, $fine ) );

		$rollback = $this->rollback();
		$state    = $rollback->run( $rollback->plan( array( 'to' => '2026-01-01 12:00' ) ) );

		$this->assertSame( 'done', $state['state'] );
		$this->assertSame( 1, $state['undone'] );
		$this->assertCount( 1, $state['skipped'] );
		$this->assertSame( $clash->id, $state['skipped'][0]['id'] );
		$this->assertTrue( $state['skipped'][0]['conflict'] );
		$this->assertSame( 'someone else', $this->things['a'] );
	}

	public function test_stop_on_conflict_ends_the_run_at_the_first_clash(): void {
		$this->things = array(
			'a' => 'someone else',
			'b' => '2',
		);
		$fine         = $this->changed( 'b', '1', '2' );
		$clash        = $this->changed( 'a', 'x', 'y' );
		// The clash is the newest, so it comes first and the run stops before the other change.
		$this->found( array( $fine, $clash ) );

		$rollback = $this->rollback();
		$state    = $rollback->run( $rollback->plan( array( 'to' => '2026-01-01 12:00' ) ), true );

		$this->assertSame( 'stopped', $state['state'] );
		$this->assertSame( 0, $state['undone'] );
		$this->assertSame( '2', $this->things['b'] );
	}

	public function test_the_run_works_in_batches_and_reports_progress(): void {
		Filters\expectApplied( 'activity_log_with_undo_rollback_batch_size' )->andReturn( 1 );
		$entries = array();
		foreach ( array( 'a', 'b' ) as $name ) {
			$this->things[ $name ] = 'after';
			$entries[]             = $this->changed( $name, 'before', 'after' );
		}
		$this->found( $entries );

		$rollback = $this->rollback();
		$job      = $rollback->start( $rollback->plan( array( 'to' => '2026-01-01 12:00' ) ) );
		$first    = $rollback->step( $job['id'] );
		$second   = $rollback->step( $job['id'] );

		$this->assertSame( 'running', $first['state'] );
		$this->assertSame( 1, $first['done'] );
		$this->assertSame( 2, $first['total'] );
		$this->assertSame( 'done', $second['state'] );
		$this->assertSame( 2, $second['undone'] );
	}

	public function test_only_one_roll_back_runs_at_a_time_and_a_cancel_keeps_what_was_done(): void {
		$this->things['a'] = 'after';
		$this->found( array( $this->changed( 'a', 'before', 'after' ) ) );

		$rollback = $this->rollback();
		$plan     = $rollback->plan( array( 'to' => '2026-01-01 12:00' ) );
		$job      = $rollback->start( $plan );

		$busy = $rollback->start( $plan );
		$this->assertSame( 'activity_log_with_undo_rollback_busy', $busy->get_error_code() );

		$state = $rollback->cancel( $job['id'] );
		$this->assertSame( 'cancelled', $state['state'] );
		$this->assertNull( $rollback->job() );
		$this->assertSame( 'activity_log_with_undo_rollback_gone', $rollback->step( $job['id'] )->get_error_code() );
	}

	public function test_another_user_cannot_continue_a_roll_back(): void {
		$this->things['a'] = 'after';
		$this->found( array( $this->changed( 'a', 'before', 'after' ) ) );

		$rollback = $this->rollback();
		$job      = $rollback->start( $rollback->plan( array( 'to' => '2026-01-01 12:00' ) ) );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		$this->assertSame( 'activity_log_with_undo_forbidden', $rollback->step( $job['id'] )->get_error_code() );
	}

	public function test_a_roll_back_with_nothing_to_undo_is_refused(): void {
		$this->found( array() );

		$rollback = $this->rollback();
		$result   = $rollback->run( $rollback->plan( array( 'to' => '2026-01-01 12:00' ) ) );

		$this->assertSame( 'activity_log_with_undo_rollback_empty', $result->get_error_code() );
	}

	public function test_the_capability_for_a_roll_back_is_filterable(): void {
		Filters\expectApplied( 'activity_log_with_undo_rollback_capability' )->once()->andReturn( 'update_core' );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return 'update_core' !== $capability;
			}
		);

		$this->assertFalse( Rollback::allowed() );
	}

	public function test_the_end_message_is_filterable(): void {
		Filters\expectApplied( 'activity_log_with_undo_rollback_message' )->once()->andReturn( 'Done and dusted.' );

		$this->assertSame(
			'Done and dusted.',
			Rollback::message(
				array(
					'state'  => 'done',
					'undone' => 3,
				)
			)
		);
	}
}
