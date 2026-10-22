<?php
/**
 * Tests for the clean-up job and the privacy tools.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Privacy;
use AryanX\ActivityLogWithUndo\Recorders\Registry;
use AryanX\ActivityLogWithUndo\Retention;
use AryanX\ActivityLogWithUndo\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RetentionPrivacyTest extends LogTestCase {

	public function test_purge_deletes_in_batches_until_a_batch_comes_back_short(): void {
		Filters\expectApplied( 'activity_log_with_undo_purge_batch_size' )->once()->with( 1000 )->andReturn( 2 );
		Actions\expectDone( 'activity_log_with_undo_purged' )->once()->with( 5, \Mockery::type( 'string' ) );
		$this->store->shouldReceive( 'purge_before' )->times( 3 )->with( \Mockery::type( 'string' ), 2 )->andReturn( 2, 2, 1 );

		$result = ( new Retention( $this->store, new Settings() ) )->purge( 30 );

		$this->assertSame( 5, $result['deleted'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), substr( $result['cutoff'], 0, 10 ) );
	}

	public function test_the_daily_job_uses_the_retention_setting_and_comes_back_when_unfinished(): void {
		Filters\expectApplied( 'activity_log_with_undo_retention_days' )->once()->with( 90 )->andReturn( 7 );
		$this->store->shouldReceive( 'purge_before' )->andReturn( 1000 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), Retention::HOOK, array( 'more' ) );

		( new Retention( $this->store, new Settings() ) )->run();
		$this->addToAssertionCount( 1 );
	}

	public function test_export_lists_a_users_changes_and_erase_removes_them_from_the_log(): void {
		$user = new \WP_User(
			array(
				'ID'         => 5,
				'user_email' => 'sam@example.com',
			)
		);
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_userdata' )->justReturn( $user );
		$this->store->shouldReceive( 'by_user' )->once()->with( 5, 100, 0 )->andReturn(
			array(
				Entry::from_row(
					array(
						'id'          => 3,
						'recorder'    => 'fake',
						'object_id'   => 'widget',
						'created_gmt' => '2026-10-01 12:00:00',
						'actor_via'   => 'admin',
						'ip'          => '203.0.113.0',
					)
				),
			)
		);
		$this->store->shouldReceive( 'anonymise_user' )->once()->with( 5, 500 )->andReturn( 4 );
		$privacy = new Privacy( $this->store, new Registry( array( new FakeRecorder() ), new Settings() ) );

		$export = $privacy->export( 'sam@example.com', 1 );
		$erase  = $privacy->erase( 'sam@example.com' );

		$this->assertTrue( $export['done'] );
		$this->assertSame( 'activity-log-with-undo-3', $export['data'][0]['item_id'] );
		$this->assertSame( 'Fake widget', $export['data'][0]['data'][1]['value'] );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertTrue( $erase['items_retained'] );
		$this->assertTrue( $erase['done'] );
	}

	public function test_the_exporter_and_eraser_are_registered(): void {
		$privacy = new Privacy( $this->store, new Registry( array(), new Settings() ) );

		$this->assertArrayHasKey( 'activity-log-with-undo', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'activity-log-with-undo', $privacy->add_eraser( array() ) );
	}
}
