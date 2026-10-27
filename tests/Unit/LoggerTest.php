<?php
/**
 * Tests for writing entries and change sets.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Context;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Logger;
use AryanX\Axle\ActivityLogWithUndo\Payload;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

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

	public function test_a_lower_limit_for_one_entry_leaves_its_big_states_out(): void {
		$id = $this->logger()->log( $this->change( array( 'after' => str_repeat( 'x', 3000 ), 'limit' => 2048 ) ) );

		$this->assertSame( 0, $this->rows[ $id ]['undoable'] );
		$this->assertSame( Payload::TOO_BIG, Payload::left_out_reason( $this->rows[ $id ]['after_state'] ) );
		$this->assertSame( 'Old', $this->state( $id, 'before' ) );
	}

	public function test_past_the_log_size_limit_changes_are_logged_without_their_states(): void {
		$this->store->shouldReceive( 'size' )->andReturn( 600 * MB_IN_BYTES );

		$id = $this->logger()->log( $this->change() );

		$this->assertSame( 0, $this->rows[ $id ]['undoable'] );
		$this->assertSame( Payload::LOG_FULL, Payload::left_out_reason( $this->rows[ $id ]['before_state'] ) );
		$this->assertSame( Payload::LOG_FULL, Payload::left_out_reason( $this->rows[ $id ]['after_state'] ) );
		$this->assertSame( '', Payload::left_out_reason( Payload::encode( 'kept' ) ) );
	}

	public function test_the_log_size_limit_is_filterable_and_zero_turns_it_off(): void {
		$this->assertSame( 512 * MB_IN_BYTES, Logger::max_log_size() );

		$this->store->shouldReceive( 'size' )->andReturn( 600 * MB_IN_BYTES );
		Filters\expectApplied( 'activity_log_with_undo_max_log_size' )->andReturn( 0 );

		$this->assertFalse( $this->logger()->log_full() );
		$id = $this->logger()->log( $this->change() );
		$this->assertSame( 1, $this->rows[ $id ]['undoable'] );
	}

	public function test_a_change_made_by_an_approved_agent_request_records_the_agent_and_the_approver(): void {
		Functions\when( 'wp_html_excerpt' )->returnArg();
		$settings = new Settings();
		$context  = new Context( $settings );
		$logger   = new Logger( $this->store, $settings, $context );

		$context->agent_request_approved(
			array(
				'id'       => 12,
				'agent'    => 'Claude',
				'identity' => 'app:abc',
				'via'      => 'app-password',
				'user_id'  => 3,
			),
			1
		);
		$during = $logger->log( $this->change() );
		$context->agent_request_done();
		$after = $logger->log( $this->change() );

		$this->assertSame( array( 'Claude', 12, 1 ), array( $this->rows[ $during ]['agent'], $this->rows[ $during ]['agent_request'], $this->rows[ $during ]['approved_by'] ) );
		$this->assertSame( array( '', 0, 0 ), array( $this->rows[ $after ]['agent'], $this->rows[ $after ]['agent_request'], $this->rows[ $after ]['approved_by'] ) );

		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_login' => 'aryan' ) );
		$entry = Entry::from_row( $this->rows[ $during ] );
		$this->assertTrue( $entry->by_agent() );
		$this->assertSame(
			array(
				'name'        => 'Claude',
				'request'     => 12,
				'approved_by' => 1,
				'approver'    => 'aryan',
			),
			$entry->to_array()['agent']
		);
		$this->assertNull( Entry::from_row( $this->rows[ $after ] )->to_array()['agent'] );
	}

	public function test_the_agent_context_filter_can_change_or_drop_what_is_recorded(): void {
		$context = new Context( new Settings() );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
		Filters\expectApplied( 'activity_log_with_undo_agent_context' )->twice()->andReturn( array( 'agent' => '<b>Bot</b>', 'request' => -4, 'approved_by' => '7' ), array() );

		$context->agent_request_approved( array( 'id' => 5, 'agent' => '' ), 7 );
		$this->assertSame( array( 'agent' => 'Bot', 'request' => 0, 'approved_by' => 7 ), $context->agent() );

		$context->agent_request_approved( array( 'id' => 5 ), 7 );
		$this->assertNull( $context->agent() );
	}

	public function test_an_agent_without_a_name_is_known_by_its_identity(): void {
		$context = new Context( new Settings() );

		$context->agent_request_approved( array( 'id' => 9, 'identity' => 'user:1', 'via' => 'mcp' ), 2 );

		$this->assertSame( 'user:1', $context->agent()['agent'] );
	}

	public function test_a_person_started_the_request_unless_it_is_cron_or_a_visitor(): void {
		$context = new Context( new Settings() );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertTrue( $context->user_initiated() );

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertFalse( $context->user_initiated() );

		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		$this->assertFalse( $context->user_initiated() );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertFalse( $context->user_initiated() );
	}

	public function test_update_after_replaces_the_after_state(): void {
		$logger = $this->logger();
		$id     = $logger->log( $this->change() );

		$logger->update_after( $id, array( 'editor' ) );

		$this->assertSame( array( 'editor' ), $this->state( $id, 'after' ) );
	}
}
