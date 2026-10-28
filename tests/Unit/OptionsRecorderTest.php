<?php
/**
 * Tests for the options recorder: which options are logged, in which
 * requests, how often, how big, and how they are undone.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Payload;
use AryanX\Axle\ActivityLogWithUndo\Recorders\OptionsRecorder;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class OptionsRecorderTest extends LogTestCase {

	/**
	 * Transients, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = array();

	/**
	 * Settings registered with register_setting().
	 *
	 * @var array<string, array>
	 */
	private $registered = array();

	protected function setUp(): void {
		parent::setUp();

		$this->transients = array();
		$this->registered = array();

		// An administrator in wp-admin, after init.
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'get_registered_settings' )->alias(
			function () {
				return $this->registered;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	private function recorder(): OptionsRecorder {
		return new OptionsRecorder( $this->logger() );
	}

	public function test_only_settings_people_change_are_logged_by_default(): void {
		$recorder = $this->recorder();

		foreach ( array( 'blogname', 'blogdescription', 'permalink_structure', 'widget_search', 'sidebars_widgets', 'theme_mods_twentytwentyfive', 'site_logo', 'wp_page_for_privacy_policy' ) as $option ) {
			$this->assertTrue( $recorder->is_logged( $option ), $option );
		}

		// Other plugins' state and caches, and what WordPress rewrites on its own.
		foreach ( array( 'block_doctor_index', 'block_doctor_scan', 'aryanx_content_version', 'axle_read_only_mode_state', 'role_right_sizer_report', 'woocommerce_queue_flush_rewrite_rules', 'woocommerce_currency', '_transient_feed_x', '_site_transient_update_core', 'cron', 'rewrite_rules', 'core_updater.lock', 'auth_key', 'category_children', 'active_plugins', 'activity_log_with_undo_settings' ) as $option ) {
			$this->assertFalse( $recorder->is_logged( $option ), $option );
		}
	}

	public function test_a_flood_of_another_plugins_internal_option_writes_no_entries(): void {
		$recorder = $this->recorder();
		$index    = array( 'posts' => array_fill( 0, 500, array( 'blocks' => 'core/paragraph' ) ) );

		for ( $i = 0; $i < 50; $i++ ) {
			$recorder->updated( 'block_doctor_index', $index, $index + array( 'n' => $i ) );
			$recorder->updated( 'aryanx_content_version', $i, $i + 1 );
		}

		$this->assertSame( array(), $this->rows );
	}

	public function test_options_registered_with_the_settings_api_are_settings(): void {
		$this->registered = array(
			'my_plugin_settings' => array(
				'type'  => 'array',
				'label' => 'My plugin settings',
			),
		);
		$recorder         = $this->recorder();

		$this->assertTrue( $recorder->is_logged( 'my_plugin_settings' ) );
		$this->assertSame( 'My plugin settings', OptionsRecorder::label_for( 'my_plugin_settings' ) );
		$this->assertFalse( $recorder->is_logged( 'my_plugin_cache' ) );
	}

	public function test_settings_changed_by_cron_or_a_visitor_are_not_logged_but_always_logged_options_are(): void {
		Filters\expectApplied( 'activity_log_with_undo_logged_options' )->once()->with( array() )->andReturn( array( 'my_flag' ) );
		Actions\expectDone( 'activity_log_with_undo_option_skipped' )->once()->with( 'blogname', 'background' );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$recorder = $this->recorder();

		$recorder->updated( 'blogname', 'Old', 'Changed by cron' );
		$recorder->updated( 'my_flag', 'off', 'on' );

		$this->assertCount( 1, $this->rows );
		$this->assertSame( 'my_flag', $this->rows[1]['object_id'] );
	}

	public function test_a_visitor_on_the_front_end_is_not_a_person_changing_settings(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->assertFalse( $this->recorder()->is_logged( 'blogname' ) );
	}

	public function test_the_user_request_filter_decides_in_the_end(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Filters\expectApplied( 'activity_log_with_undo_is_user_request' )->once()->with( false, 'cron' )->andReturn( true );

		$this->assertTrue( $this->recorder()->is_logged( 'blogname' ) );
	}

	public function test_the_settings_filter_gets_the_defaults_and_whether_they_are_network_settings(): void {
		Filters\expectApplied( 'activity_log_with_undo_setting_options' )->once()->with( OptionsRecorder::default_settings(), false )->andReturn( array( 'only_this' ) );
		$recorder = $this->recorder();

		$this->assertTrue( $recorder->is_logged( 'only_this' ) );
		$this->assertFalse( $recorder->is_logged( 'blogname' ) );
		$this->assertContains( 'site_name', OptionsRecorder::default_settings( true ) );
	}

	public function test_the_ignore_list_wins_over_the_other_lists(): void {
		Filters\expectApplied( 'activity_log_with_undo_logged_options' )->once()->andReturn( array( 'blog*', '*' ) );
		Filters\expectApplied( 'activity_log_with_undo_ignored_options' )->once()->andReturnUsing(
			static function ( array $ignored ) {
				return array_merge( $ignored, array( 'blogdescription' ) );
			}
		);
		$recorder = $this->recorder();

		$this->assertTrue( $recorder->is_logged( 'blogname' ) );
		$this->assertFalse( $recorder->is_logged( 'blogdescription' ) );
		$this->assertFalse( $recorder->is_logged( 'cron' ) );
	}

	public function test_its_own_options_stay_ignored_even_if_a_filter_empties_the_ignore_list(): void {
		Filters\expectApplied( 'activity_log_with_undo_ignored_options' )->once()->andReturn( array() );
		Filters\expectApplied( 'activity_log_with_undo_logged_options' )->andReturn( array( '*' ) );

		$this->assertFalse( $this->recorder()->is_logged( 'activity_log_with_undo_settings' ) );
	}

	public function test_updates_adds_and_deletes_are_logged_with_their_values(): void {
		$recorder = $this->recorder();

		$recorder->updated( 'blogname', 'Old', 'New' );
		$recorder->updated( 'cron', array(), array( 1 ) );
		$recorder->updated( 'posts_per_page', 10, '10' );
		$recorder->added( 'widget_search', array( 'on' => true ) );
		$this->options['sticky_posts'] = array( 4 );
		$recorder->deleting( 'sticky_posts' );
		$recorder->deleted( 'sticky_posts' );

		$this->assertCount( 3, $this->rows );
		$this->assertSame( array( 'option_updated', 'option_added', 'option_deleted' ), array_column( $this->rows, 'event' ) );
		$this->assertSame( 'Site title', $this->rows[1]['object_label'] );
		$this->assertSame( 'Old', $this->state( 1, 'before' ) );
		$this->assertSame( array( 'on' => true ), $this->state( 2, 'after' ) );
		$this->assertSame( array( 4 ), $this->state( 3, 'before' ) );
		$this->assertNull( $this->state( 3, 'after' ) );
	}

	public function test_writing_one_option_again_in_the_same_request_updates_its_entry(): void {
		$recorder = $this->recorder();

		$recorder->updated( 'blogname', 'First', 'Second' );
		$recorder->updated( 'blogname', 'Second', 'Third' );
		$recorder->updated( 'blogdescription', 'A', 'B' );

		$this->assertCount( 2, $this->rows );
		$this->assertSame( 'First', $this->state( 1, 'before' ) );
		$this->assertSame( 'Third', $this->state( 1, 'after' ) );
	}

	public function test_the_rate_limit_stops_logging_an_option_written_too_often(): void {
		Filters\expectApplied( 'activity_log_with_undo_option_rate_limit' )->andReturn( 2 );
		Actions\expectDone( 'activity_log_with_undo_option_skipped' )->once()->with( 'blogname', 'rate_limited' );

		// Three requests, each with its own logger and change set.
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->recorder()->updated( 'blogname', 'v' . $i, 'v' . ( $i + 1 ) );
		}
		$this->recorder()->updated( 'blogdescription', 'a', 'b' );

		$this->assertSame( array( 'blogname', 'blogname', 'blogdescription' ), array_column( $this->rows, 'object_id' ) );
	}

	public function test_the_rate_limit_starts_over_after_its_window(): void {
		Filters\expectApplied( 'activity_log_with_undo_option_rate_limit' )->andReturn( 1 );
		Filters\expectApplied( 'activity_log_with_undo_option_rate_window' )->andReturn( 30 );
		$key                      = 'activity_log_with_undo_rate_' . md5( 'blogname' );
		$this->transients[ $key ] = array( time() - 31, 1 );

		$this->assertFalse( OptionsRecorder::rate_limited( 'blogname' ) );
		$this->assertTrue( OptionsRecorder::rate_limited( 'blogname' ) );
		$this->assertSame( 1, $this->transients[ $key ][1] );
	}

	public function test_a_rate_limit_of_zero_turns_it_off(): void {
		Filters\expectApplied( 'activity_log_with_undo_option_rate_limit' )->andReturn( 0 );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertFalse( OptionsRecorder::rate_limited( 'blogname' ) );
		}
	}

	public function test_values_over_the_option_size_limit_are_logged_without_them(): void {
		Filters\expectApplied( 'activity_log_with_undo_option_size_limit' )->andReturn( 2048 );
		$recorder = $this->recorder();

		$recorder->updated( 'widget_block', array( 'content' => 'small' ), array( 'content' => str_repeat( 'x', 5000 ) ) );

		$this->assertSame( 0, $this->rows[1]['undoable'] );
		$this->assertGreaterThan( 5000, Payload::left_out( $this->rows[1]['after_state'] ) );
		$this->assertSame( array( 'content' => 'small' ), $this->state( 1, 'before' ) );
	}

	public function test_the_option_size_limit_defaults_to_64_kb_and_is_clamped(): void {
		$this->assertSame( 65536, OptionsRecorder::size_limit( 'blogname' ) );

		Filters\expectApplied( 'activity_log_with_undo_option_size_limit' )->once()->with( 65536, 'blogname' )->andReturn( -5 );
		$this->assertSame( 0, OptionsRecorder::size_limit( 'blogname' ) );
	}

	public function test_a_setting_changed_before_init_is_logged_without_translating(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( '__' )->alias(
			static function () {
				throw new \LogicException( 'Translated before init.' );
			}
		);
		$recorder = $this->recorder();

		$recorder->updated( 'blogdescription', 'Old', 'New' );

		$this->assertSame( 'blogdescription', $this->rows[1]['object_label'] );
	}

	public function test_an_entry_logged_with_the_option_name_gets_its_label_when_shown(): void {
		$entry = $this->entry(
			array(
				'event'        => 'option_updated',
				'object_type'  => 'option',
				'object_id'    => 'blogdescription',
				'object_label' => 'blogdescription',
			)
		);

		$this->assertSame( 'Changed Tagline', $this->recorder()->describe( $entry ) );
	}

	public function test_only_entries_of_options_still_logged_join_a_change_set(): void {
		$recorder = $this->recorder();

		$this->assertTrue( $recorder->in_change_set( $this->entry( array( 'object_type' => 'option', 'object_id' => 'blogname' ) ) ) );
		$this->assertFalse( $recorder->in_change_set( $this->entry( array( 'object_type' => 'option', 'object_id' => 'aryanx_content_version' ) ) ) );
		$this->assertTrue( $recorder->in_change_set( $this->entry( array( 'object_type' => 'category', 'object_id' => '5' ) ) ) );
	}

	public function test_undo_writes_back_removes_or_adds_the_option(): void {
		$recorder                  = $this->recorder();
		$this->options['blogname'] = 'New';
		$this->options['added']    = 'x';

		$this->assertTrue( $recorder->undo( $this->entry( array( 'event' => 'option_updated', 'object_type' => 'option', 'object_id' => 'blogname', 'before' => 'Old', 'after' => 'New' ) ) ) );
		$this->assertTrue( $recorder->undo( $this->entry( array( 'event' => 'option_added', 'object_type' => 'option', 'object_id' => 'added', 'after' => 'x' ) ) ) );
		$this->assertTrue( $recorder->undo( $this->entry( array( 'event' => 'option_deleted', 'object_type' => 'option', 'object_id' => 'gone', 'before' => array( 1, 2 ) ) ) ) );

		$this->assertSame( 'Old', $this->options['blogname'] );
		$this->assertArrayNotHasKey( 'added', $this->options );
		$this->assertSame( array( 1, 2 ), $this->options['gone'] );
	}

	public function test_a_deleted_option_that_exists_again_is_not_overwritten(): void {
		$this->options['gone'] = 'new value';

		$result = $this->recorder()->undo( $this->entry( array( 'event' => 'option_deleted', 'object_type' => 'option', 'object_id' => 'gone', 'before' => 'old' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'new value', $this->options['gone'] );
	}

	public function test_current_state_is_null_for_a_missing_option(): void {
		$entry = $this->entry( array( 'event' => 'option_deleted', 'object_type' => 'option', 'object_id' => 'gone' ) );

		$this->assertNull( $this->recorder()->current_state( $entry ) );
	}

	public function test_widgets_and_customizer_settings_get_friendly_names(): void {
		$this->assertSame( 'Widget settings: search', OptionsRecorder::label_for( 'widget_search' ) );
		$this->assertSame( 'Customizer settings of twentytwentyfive', OptionsRecorder::label_for( 'theme_mods_twentytwentyfive' ) );
		$this->assertSame( 'my_option', OptionsRecorder::label_for( 'my_option' ) );
	}
}
