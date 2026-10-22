<?php
/**
 * Tests for writing entries and change sets.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Payload;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class LoggerTest extends LogTestCase {

	private function change( array $extra = array() ): array {
		return $extra + array(
			'event'        => 'option_updated',
			'recorder'     => 'options',
			'object_type'  => 'option',
			'object_id'    => 'blogname',
			'object_label' => 'Site title',
			'before'       => 'Old',
			'after'        => 'New',
			'undoable'     => true,
		);
	}

	public function test_a_change_is_stored_with_who_where_and_both_states_and_announced(): void {
		Actions\expectDone( 'activity_log_with_undo_logged' )->once()->with( \Mockery::type( Entry::class ) );

		$id  = $this->logger()->log( $this->change() );
		$row = $this->rows[ $id ];

		$this->assertSame( 'option_updated', $row['event'] );
		$this->assertSame( 'blogname', $row['object_id'] );
		$this->assertSame( 1, $row['actor_user_id'] );
		$this->assertSame( 'admin', $row['actor_via'] );
		$this->assertSame( 1, $row['undoable'] );
		$this->assertSame( 'Old', $this->state( $id, 'before' ) );
		$this->assertSame( 'New', $this->state( $id, 'after' ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $row['change_set'] );
	}

	public function test_should_log_can_skip_a_change(): void {
		Filters\expectApplied( 'activity_log_with_undo_should_log' )->once()->with( true, 'option_updated', \Mockery::type( 'array' ) )->andReturn( false );
		Actions\expectDone( 'activity_log_with_undo_logged' )->never();

		$this->assertSame( 0, $this->logger()->log( $this->change() ) );
		$this->assertSame( array(), $this->rows );
	}

	public function test_states_over_the_payload_limit_are_left_out_and_the_change_cannot_be_undone(): void {
		Filters\expectApplied( 'activity_log_with_undo_payload_limit' )->andReturn( 1024 );

		$id = $this->logger()->log( $this->change( array( 'after' => str_repeat( 'x', 5000 ) ) ) );

		$this->assertSame( 0, $this->rows[ $id ]['undoable'] );
		$this->assertGreaterThan( 5000, Payload::left_out( $this->rows[ $id ]['after_state'] ) );
		$this->assertSame( 'Old', $this->state( $id, 'before' ) );
	}

	public function test_one_request_is_one_change_set_until_the_size_filter_cuts_it(): void {
		Filters\expectApplied( 'activity_log_with_undo_change_set_size' )->andReturn( 2 );
		$logger = $this->logger();

		$first  = $logger->log( $this->change() );
		$second = $logger->log( $this->change() );
		$third  = $logger->log( $this->change() );

		$this->assertSame( $this->rows[ $first ]['change_set'], $this->rows[ $second ]['change_set'] );
		$this->assertNotSame( $this->rows[ $first ]['change_set'], $this->rows[ $third ]['change_set'] );
	}

	public function test_entries_written_during_an_undo_point_back_and_get_their_own_change_set(): void {
		$logger = $this->logger();
		$before = $logger->log( $this->change() );

		$logger->start_undo_run();
		$logger->begin_undo( $before );
		$undo = $logger->log( $this->change( array( 'before' => 'New', 'after' => 'Old' ) ) );
		$this->assertSame( array( $undo ), $logger->end_undo() );
		$logger->finish_undo_run();
		$after = $logger->log( $this->change() );

		$this->assertSame( $before, $this->rows[ $undo ]['undo_of'] );
		$this->assertNull( $this->rows[ $after ]['undo_of'] );
		$this->assertNotSame( $this->rows[ $before ]['change_set'], $this->rows[ $undo ]['change_set'] );
		$this->assertNotSame( $this->rows[ $undo ]['change_set'], $this->rows[ $after ]['change_set'] );
	}

	public function test_update_after_replaces_the_after_state(): void {
		$logger = $this->logger();
		$id     = $logger->log( $this->change() );

		$logger->update_after( $id, array( 'editor' ) );

		$this->assertSame( array( 'editor' ), $this->state( $id, 'after' ) );
	}
}
