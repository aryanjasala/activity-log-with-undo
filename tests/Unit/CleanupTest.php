<?php
/**
 * Tests for the clean-up of what version 1 logged, and the Site Health results about it.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Cleanup;
use AryanX\Axle\ActivityLogWithUndo\Health\SiteHealth;
use AryanX\Axle\ActivityLogWithUndo\Recorders\OptionsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CleanupTest extends LogTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_registered_settings' )->justReturn( array() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number ) {
				return number_format( (float) $number );
			}
		);
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( $bytes / MB_IN_BYTES, 1 ) . ' MB';
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
	}

	private function cleanup(): Cleanup {
		return new Cleanup( $this->store, new OptionsRecorder( $this->logger() ) );
	}

	public function test_the_scan_finds_options_that_are_not_settings_biggest_first(): void {
		$this->store->shouldReceive( 'option_entry_counts' )->andReturn(
			array(
				'blogname'                => 4,
				'aryanx_content_version'  => 1036,
				'block_doctor_index'      => 5079,
				'widget_search'           => 2,
				'network:site_name'       => 1,
				'network:some_plugin_job' => 3,
			)
		);

		$this->assertSame(
			array(
				'block_doctor_index'      => 5079,
				'aryanx_content_version'  => 1036,
				'network:some_plugin_job' => 3,
			),
			$this->cleanup()->scan()
		);
	}

	public function test_the_options_filter_can_keep_entries(): void {
		$this->store->shouldReceive( 'option_entry_counts' )->andReturn( array( 'my_counter' => 9, 'my_cache_x' => 4 ) );
		Filters\expectApplied( 'activity_log_with_undo_cleanup_options' )->once()->with( array( 'my_counter' => 9, 'my_cache_x' => 4 ) )->andReturn( array( 'my_counter' => 9, '' => 1, 'zero' => 0 ) );

		$this->assertSame( array( 'my_counter' => 9 ), $this->cleanup()->scan() );
	}

	public function test_an_upgrade_from_version_1_flags_the_log_and_the_first_admin_screen_offers_the_clean_up(): void {
		Cleanup::flag();
		$this->assertSame( 'pending', Cleanup::state()['status'] );
		$this->store->shouldReceive( 'option_entry_counts' )->once()->andReturn( array( 'block_doctor_index' => 5079, 'blogname' => 3 ) );
		$this->store->shouldReceive( 'size' )->andReturn( 1181 * MB_IN_BYTES );

		ob_start();
		$this->cleanup()->notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '5,079 entries', $html );
		$this->assertStringContainsString( 'block_doctor_index', $html );
		$this->assertStringContainsString( '1181 MB', $html );
		$this->assertStringContainsString( 'value="delete"', $html );
		$this->assertSame(
			array(
				'status'  => 'offered',
				'options' => array( 'block_doctor_index' ),
				'entries' => 5079,
				'deleted' => 0,
			),
			Cleanup::state()
		);
	}

	public function test_nothing_found_means_no_notice(): void {
		Cleanup::flag();
		$this->store->shouldReceive( 'option_entry_counts' )->andReturn( array( 'blogname' => 3 ) );

		ob_start();
		$this->cleanup()->notice();

		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( 'done', Cleanup::state()['status'] );
	}

	public function test_the_notice_message_is_filterable_and_hidden_from_people_without_the_capability(): void {
		$this->options[ Cleanup::OPTION ] = array(
			'status'  => 'offered',
			'options' => array( 'x' ),
			'entries' => 2,
		);
		$this->store->shouldReceive( 'size' )->andReturn( 0 );
		Filters\expectApplied( 'activity_log_with_undo_cleanup_message' )->once()->andReturn( 'Custom message' );

		ob_start();
		$this->cleanup()->notice();
		$this->assertStringContainsString( 'Custom message', (string) ob_get_clean() );

		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		$this->cleanup()->notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_deleting_runs_in_batches_and_the_rest_goes_to_wp_cron(): void {
		$this->options[ Cleanup::OPTION ] = array(
			'status'  => 'offered',
			'options' => array( 'block_doctor_index' ),
			'entries' => 2500,
		);
		Filters\expectApplied( 'activity_log_with_undo_cleanup_batch_size' )->andReturn( 1000 );
		$this->store->shouldReceive( 'delete_option_entries' )->with( array( 'block_doctor_index' ), 1000 )->andReturn( 1000, 1000, 500 );
		$this->store->shouldReceive( 'forget_size' )->once();
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), Cleanup::HOOK );
		Actions\expectDone( 'activity_log_with_undo_cleaned_up' )->once()->with( 500, array( 'block_doctor_index' ) );

		$first = $this->cleanup()->step( 2 );
		$this->assertSame( array( 'deleted' => 2000, 'done' => false ), $first );
		$this->assertSame( 'running', Cleanup::state()['status'] );

		$this->cleanup()->run();
		$this->assertSame( 'done', Cleanup::state()['status'] );
		$this->assertSame( 2500, Cleanup::state()['deleted'] );
	}

	public function test_finish_marks_it_done_and_clears_the_job(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cleanup::HOOK );

		Cleanup::finish( 12 );

		$this->assertSame( 'done', Cleanup::state()['status'] );
		$this->assertSame( 12, Cleanup::state()['deleted'] );
	}

	public function test_site_health_reports_the_old_entries_and_a_full_log(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		$this->store->shouldReceive( 'size' )->andReturn( 10 * MB_IN_BYTES, 600 * MB_IN_BYTES );
		$this->options[ Cleanup::OPTION ] = array( 'status' => 'offered' );

		global $wpdb;
		$previous = $wpdb;
		// Schema::exists() asks the database whether the table is there.
		$wpdb = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			public function prepare( $query ) {
				return $query;
			}

			public function esc_like( $text ) {
				return $text;
			}

			public function get_var() {
				return 'wp_activity_log_with_undo';
			}
		};

		$health = new SiteHealth( new Settings(), $this->store );
		$old    = $health->run();
		$full   = $health->run();
		$wpdb   = $previous;

		$this->assertSame( array( 'recommended', 'The activity log holds entries nobody can act on' ), array( $old['status'], $old['label'] ) );
		$this->assertSame( array( 'critical', 'The activity log is full' ), array( $full['status'], $full['label'] ) );
	}
}
