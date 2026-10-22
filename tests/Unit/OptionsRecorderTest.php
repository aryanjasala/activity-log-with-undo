<?php
/**
 * Tests for the options recorder.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Recorders\OptionsRecorder;
use Brain\Monkey\Filters;

final class OptionsRecorderTest extends LogTestCase {

	private function recorder(): OptionsRecorder {
		return new OptionsRecorder( $this->logger() );
	}

	public function test_transients_cron_locks_salts_and_its_own_options_are_ignored(): void {
		$recorder = $this->recorder();

		foreach ( array( '_transient_feed_x', '_site_transient_update_core', 'cron', 'rewrite_rules', 'core_updater.lock', 'auth_key', 'category_children', 'active_plugins', 'activity_log_with_undo_settings' ) as $option ) {
			$this->assertFalse( $recorder->is_logged( $option ), $option );
		}
		foreach ( array( 'blogname', 'widget_search', 'sidebars_widgets', 'theme_mods_twentytwentyfive', 'woocommerce_currency' ) as $option ) {
			$this->assertTrue( $recorder->is_logged( $option ), $option );
		}
	}

	public function test_the_allowlist_and_ignore_list_filters_decide_together(): void {
		Filters\expectApplied( 'activity_log_with_undo_logged_options' )->once()->with( array( '*' ) )->andReturn( array( 'blog*', 'my_setting' ) );
		Filters\expectApplied( 'activity_log_with_undo_ignored_options' )->once()->andReturnUsing(
			static function ( array $ignored ) {
				return array_merge( $ignored, array( 'blogdescription' ) );
			}
		);
		$recorder = $this->recorder();

		$this->assertTrue( $recorder->is_logged( 'blogname' ) );
		$this->assertTrue( $recorder->is_logged( 'my_setting' ) );
		$this->assertFalse( $recorder->is_logged( 'blogdescription' ) );
		$this->assertFalse( $recorder->is_logged( 'admin_email' ) );
	}

	public function test_its_own_options_stay_ignored_even_if_a_filter_empties_the_ignore_list(): void {
		Filters\expectApplied( 'activity_log_with_undo_ignored_options' )->once()->andReturn( array() );

		$this->assertFalse( $this->recorder()->is_logged( 'activity_log_with_undo_settings' ) );
	}

	public function test_updates_adds_and_deletes_are_logged_with_their_values(): void {
		$recorder = $this->recorder();

		$recorder->updated( 'blogname', 'Old', 'New' );
		$recorder->updated( 'cron', array(), array( 1 ) );
		$recorder->updated( 'posts_per_page', 10, '10' );
		$recorder->added( 'my_setting', array( 'on' => true ) );
		$this->options['old_setting'] = 'keep me';
		$recorder->deleting( 'old_setting' );
		$recorder->deleted( 'old_setting' );

		$this->assertCount( 3, $this->rows );
		$this->assertSame( array( 'option_updated', 'option_added', 'option_deleted' ), array_column( $this->rows, 'event' ) );
		$this->assertSame( 'Site title', $this->rows[1]['object_label'] );
		$this->assertSame( 'Old', $this->state( 1, 'before' ) );
		$this->assertSame( array( 'on' => true ), $this->state( 2, 'after' ) );
		$this->assertSame( 'keep me', $this->state( 3, 'before' ) );
		$this->assertNull( $this->state( 3, 'after' ) );
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
