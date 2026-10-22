<?php
/**
 * Tests for settings, their filters and the IP and source detection.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Context;
use AryanX\ActivityLogWithUndo\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class SettingsTest extends LogTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_defaults_log_everything_for_90_days_with_masked_ips(): void {
		$settings = new Settings();

		$this->assertSame( 90, $settings->retention_days() );
		$this->assertSame( 'masked', $settings->get( 'ip' ) );
		$this->assertSame( 262144, $settings->payload_limit() );
		$this->assertFalse( $settings->get( 'admin_bar' ) );
		$this->assertTrue( $settings->recorder_enabled( 'options' ) );
	}

	public function test_the_defaults_filter_is_sanitised(): void {
		Filters\expectApplied( 'activity_log_with_undo_default_settings' )->once()->andReturn(
			array(
				'retention_days' => 99999,
				'ip'             => 'everything',
				'disabled'       => array( 'Menus' ),
			)
		);

		$all = ( new Settings() )->all();

		$this->assertSame( 3650, $all['retention_days'] );
		$this->assertSame( 'masked', $all['ip'] );
		$this->assertSame( array( 'menus' ), $all['disabled'] );
	}

	public function test_retention_and_payload_filters_are_clamped(): void {
		Filters\expectApplied( 'activity_log_with_undo_retention_days' )->once()->with( 90 )->andReturn( -5 );
		Filters\expectApplied( 'activity_log_with_undo_payload_limit' )->once()->with( 262144 )->andReturn( 10 );

		$settings = new Settings();

		$this->assertSame( 1, $settings->retention_days() );
		$this->assertSame( 1024, $settings->payload_limit() );
	}

	public function test_saving_merges_cleans_and_fires_an_action(): void {
		Actions\expectDone( 'activity_log_with_undo_settings_saved' )->once();

		$saved = ( new Settings() )->save(
			array(
				'retention_days' => '30',
				'disabled'       => array( 'menus' ),
			)
		);

		$this->assertSame( 30, $saved['retention_days'] );
		$this->assertSame( 'masked', $saved['ip'] );
		$this->assertSame( $saved, $this->options[ Settings::OPTION ] );
		$this->assertFalse( ( new Settings() )->recorder_enabled( 'menus' ) );
	}

	public function test_ips_are_kept_full_masked_or_not_at_all(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		$this->assertSame( '203.0.113.0', ( new Context( new Settings() ) )->ip() );

		$this->options[ Settings::OPTION ] = array( 'ip' => 'full' );
		$this->assertSame( '203.0.113.42', ( new Context( new Settings() ) )->ip() );

		$this->options[ Settings::OPTION ] = array( 'ip' => 'off' );
		$this->assertSame( '', ( new Context( new Settings() ) )->ip() );
	}

	public function test_the_mask_ip_filter_can_change_the_address_but_not_store_junk(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
		Filters\expectApplied( 'activity_log_with_undo_mask_ip' )->twice()->with( '203.0.113.0', '203.0.113.42', 'masked' )->andReturn( '198.51.100.1', 'not an ip' );

		$context = new Context( new Settings() );

		$this->assertSame( '198.51.100.1', $context->ip() );
		$this->assertSame( '', $context->ip() );
	}

	public function test_changes_made_inside_an_ability_or_with_an_application_password_say_so(): void {
		$context = new Context( new Settings() );
		$this->assertSame( 'admin', $context->via() );

		$context->app_password_used();
		$this->assertSame( 'app-password', $context->via() );

		$context->ability_started();
		$this->assertSame( 'ability', $context->via() );
		$context->ability_ended();
		$this->assertSame( 'app-password', $context->via() );
	}
}
