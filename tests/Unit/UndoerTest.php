<?php
/**
 * Tests for undo: change sets, conflicts, force, redo and the undo hooks.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Logger;
use AryanX\Axle\ActivityLogWithUndo\Recorders\OptionsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

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

	/**
	 * An undoer that knows the options recorder too.
	 *
	 * @return Undoer
	 */
	private function undoer_with_options(): Undoer {
		Functions\when( 'get_registered_settings' )->justReturn( array() );

		return new Undoer( $this->store, $this->logger, new Registry( array( $this->recorder, new OptionsRecorder( $this->logger ) ), new Settings() ) );
	}

	/**
	 * A term change and, in the same request, a theme's internal counter, as version 1 logged them.
	 *
	 * @param string $option Option logged next to the change.
	 * @return array{0: Entry, 1: Entry}
	 */
	private function change_with_option( string $option ): array {
		$this->options[ $option ] = '2';
		$change                   = $this->entry(
			array(
				'change_set' => 'request',
				'object_id'  => 'a',
				'before'     => 'old',
				'after'      => 'new',
			)
		);
		$internal                 = $this->entry(
			array(
				'change_set'  => 'request',
				'recorder'    => 'options',
				'event'       => 'option_updated',
				'object_type' => 'option',
				'object_id'   => $option,
				'before'      => '1',
				'after'       => '2',
			)
		);

		return array( $change, $internal );
	}

	public function test_another_plugins_state_logged_in_the_same_request_is_left_out_of_the_undo(): void {
		list( $change, $internal ) = $this->change_with_option( 'aryanx_content_version' );
		$undoer                    = $this->undoer_with_options();

		$this->assertSame( array( $change->id ), array_column( array_map( 'get_object_vars', $undoer->members( $change ) ), 'id' ) );
		$result = $undoer->undo( $change->id );

		$this->assertSame( array( $change->id ), $result['undone'] );
		$this->assertSame( 'old', $this->things['a'] );
		$this->assertSame( '2', $this->options['aryanx_content_version'] );
		$this->assertArrayNotHasKey( 'undone_by', $this->rows[ $internal->id ] );
	}

	public function test_a_setting_saved_in_the_same_request_is_still_undone_with_it(): void {
		list( $change, $setting ) = $this->change_with_option( 'blogname' );

		$result = $this->undoer_with_options()->undo( $change->id );

		$this->assertSame( array( $setting->id, $change->id ), $result['undone'] );
		$this->assertSame( '1', $this->options['blogname'] );
	}

	public function test_an_old_entry_of_an_internal_option_can_still_be_undone_on_its_own(): void {
		list( , $internal ) = $this->change_with_option( 'aryanx_content_version' );

		$result = $this->undoer_with_options()->undo( $internal->id );

		$this->assertSame( array( $internal->id ), $result['undone'] );
		$this->assertSame( '1', $this->options['aryanx_content_version'] );
		$this->assertSame( 'new', $this->things['a'] );
	}

	public function test_the_change_set_filter_can_leave_entries_out_but_never_the_entry_itself(): void {
		$this->things = array(
			'a' => 'a2',
			'b' => 'b2',
		);
		$first        = $this->entry( array( 'change_set' => 'one', 'object_id' => 'a', 'before' => 'a1', 'after' => 'a2' ) );
		$second       = $this->entry( array( 'change_set' => 'one', 'object_id' => 'b', 'before' => 'b1', 'after' => 'b2' ) );
		$foreign      = $this->entry( array( 'change_set' => 'two', 'object_id' => 'c', 'before' => 'c1', 'after' => 'c2' ) );
		Filters\expectApplied( 'activity_log_with_undo_change_set_entries' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( Entry::class ) )->andReturn( array( $foreign ) );

		$members = $this->undoer()->members( $first );

		$this->assertCount( 1, $members );
		$this->assertSame( $first->id, $members[0]->id );
		$this->assertNotSame( $second->id, $members[0]->id );
	}

	public function test_missing_entries_are_a_404(): void {
		$this->assertSame( 404, $this->undoer()->undo( 99 )->get_error_data()['status'] );
	}
}
