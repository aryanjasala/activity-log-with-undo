<?php
/**
 * Tests for undo: change sets, conflicts, force, redo and the undo hooks.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Logger;
use AryanX\ActivityLogWithUndo\Recorders\Registry;
use AryanX\ActivityLogWithUndo\Settings;
use AryanX\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class UndoerTest extends LogTestCase {

	/**
	 * The fake recorder.
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

	/**
	 * Values of "things", as the fake recorder sees them.
	 *
	 * @var array<string, string>
	 */
	private $things = array();

	protected function setUp(): void {
		parent::setUp();

		$this->things   = array( 'a' => 'new' );
		$this->logger   = $this->logger();
		$this->recorder = new FakeRecorder();
		// The current value of a thing, and an undo that writes the old value back and logs it.
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

	private function undoer(): Undoer {
		return new Undoer( $this->store, $this->logger, new Registry( array( $this->recorder ), new Settings() ) );
	}

	public function test_undo_puts_the_old_value_back_logs_it_and_marks_the_entry(): void {
		$entry = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		Actions\expectDone( 'activity_log_with_undo_before_undo' )->once()->with( \Mockery::type( Entry::class ), false );
		Actions\expectDone( 'activity_log_with_undo_undone' )->once()->with( \Mockery::type( Entry::class ), array( 2 ) );

		$result = $this->undoer()->undo( $entry->id );

		$this->assertSame( array( $entry->id ), $result['undone'] );
		$this->assertSame( array( 2 ), $result['entries'] );
		$this->assertSame( 'old', $this->things['a'] );
		$this->assertSame( 2, $this->rows[ $entry->id ]['undone_by'] );
		$this->assertSame( $entry->id, $this->rows[2]['undo_of'] );
		$this->assertSame( 1, $this->recorder->flushed );
	}

	public function test_a_change_made_since_stops_the_undo_until_it_is_forced(): void {
		$entry             = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		$this->things['a'] = 'newer';
		Filters\expectApplied( 'activity_log_with_undo_conflict_message' )->once()->andReturn( 'Changed since!' );

		$refused = $this->undoer()->undo( $entry->id );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'activity_log_with_undo_conflict', $refused->get_error_code() );
		$this->assertSame( 'Changed since!', $refused->get_error_message() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );
		$this->assertSame( 'newer', $refused->get_error_data()['conflicts'][0]['current'] );
		$this->assertSame( 'newer', $this->things['a'] );

		$forced = $this->undoer()->undo( $entry->id, true );
		$this->assertTrue( $forced['forced'] );
		$this->assertSame( 'old', $this->things['a'] );
	}

	public function test_the_whole_change_set_is_undone_newest_first(): void {
		$this->things = array(
			'a' => 'a2',
			'b' => 'b2',
		);
		$first        = $this->entry(
			array(
				'change_set' => 'one',
				'object_id'  => 'a',
				'before'     => 'a1',
				'after'      => 'a2',
			)
		);
		$second       = $this->entry(
			array(
				'change_set' => 'one',
				'object_id'  => 'b',
				'before'     => 'b1',
				'after'      => 'b2',
			)
		);

		$result = $this->undoer()->undo( $first->id );

		$this->assertSame( array( $second->id, $first->id ), $this->recorder->undone );
		$this->assertSame( array( $second->id, $first->id ), $result['undone'] );
		$this->assertSame( array( 'a' => 'a1', 'b' => 'b1' ), $this->things );
		// Both reversals share one new change set.
		$this->assertSame( $this->rows[3]['change_set'], $this->rows[4]['change_set'] );
	}

	public function test_redo_is_an_undo_of_the_undo(): void {
		$entry = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		$undo  = $this->undoer()->undo( $entry->id );

		$redo = $this->undoer()->undo( $undo['entries'][0] );

		$this->assertSame( 'new', $this->things['a'] );
		$this->assertSame( $undo['entries'][0], $this->rows[ $redo['entries'][0] ]['undo_of'] );
	}

	public function test_an_entry_cannot_be_undone_twice(): void {
		$entry = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		$this->undoer()->undo( $entry->id );

		$again = $this->undoer()->undo( $entry->id );

		$this->assertSame( 'activity_log_with_undo_already_undone', $again->get_error_code() );
	}

	public function test_the_can_undo_filter_can_refuse_but_not_bring_back_a_change_without_state(): void {
		$entry = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		Filters\expectApplied( 'activity_log_with_undo_can_undo' )->once()->with( true, \Mockery::type( Entry::class ) )->andReturn( new \WP_Error( 'mine', 'Not on Fridays.' ) );

		$this->assertSame( 'Not on Fridays.', $this->undoer()->undo( $entry->id )->get_error_message() );
		$this->assertSame( 'new', $this->things['a'] );

		$logged_only = $this->entry( array( 'undoable' => 0 ) );
		Filters\expectApplied( 'activity_log_with_undo_can_undo' )->andReturn( true );
		$this->assertInstanceOf( \WP_Error::class, $this->undoer()->undo( $logged_only->id ) );
	}

	public function test_a_failed_undo_fires_undo_failed_and_reports_why(): void {
		$entry                  = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		$this->recorder->on_undo = null;
		$this->recorder->result  = new \WP_Error( 'nope', 'The thing is locked.' );
		Actions\expectDone( 'activity_log_with_undo_undo_failed' )->once()->with( \Mockery::type( Entry::class ), \Mockery::type( \WP_Error::class ) );
		Actions\expectDone( 'activity_log_with_undo_undone' )->never();

		$result = $this->undoer()->undo( $entry->id );

		$this->assertSame( 'The thing is locked.', $result->get_error_message() );
		$this->assertArrayNotHasKey( 'undone_by', $this->rows[ $entry->id ] );
	}

	public function test_when_no_recorder_logs_the_reversal_an_undo_entry_is_written(): void {
		$entry                  = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);
		$this->recorder->on_undo = function () {
			$this->things['a'] = 'old';
		};

		$result = $this->undoer()->undo( $entry->id );

		$written = $this->rows[ $result['entries'][0] ];
		$this->assertSame( 'undo', $written['event'] );
		$this->assertSame( 0, $written['undoable'] );
		$this->assertSame( $entry->id, $written['undo_of'] );
		$this->assertSame( $result['entries'][0], $this->rows[ $entry->id ]['undone_by'] );
	}

	public function test_missing_entries_are_a_404(): void {
		$this->assertSame( 404, $this->undoer()->undo( 99 )->get_error_data()['status'] );
	}
}
