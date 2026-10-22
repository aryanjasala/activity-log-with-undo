<?php
/**
 * Tests for the screen's capability and page size filters, and the abilities.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Abilities;
use AryanX\ActivityLogWithUndo\Admin\EntriesTable;
use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Recorders\PluginsRecorder;
use AryanX\ActivityLogWithUndo\Recorders\Registry;
use AryanX\ActivityLogWithUndo\Recorders\ThemesRecorder;
use AryanX\ActivityLogWithUndo\Settings;
use AryanX\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AdminAbilitiesTest extends LogTestCase {

	public function test_the_capability_filter_decides_who_sees_the_log_and_falls_back_when_empty(): void {
		$this->assertSame( 'manage_options', LogPage::capability() );

		Filters\expectApplied( 'activity_log_with_undo_capability' )->once()->with( 'manage_options' )->andReturn( 'edit_others_posts' );
		$this->assertSame( 'edit_others_posts', LogPage::capability() );

		Filters\expectApplied( 'activity_log_with_undo_capability' )->once()->andReturn( '' );
		$this->assertSame( 'manage_options', LogPage::capability() );
	}

	public function test_the_list_per_page_filter_is_clamped(): void {
		$this->assertSame( 20, EntriesTable::per_page() );

		Filters\expectApplied( 'activity_log_with_undo_list_per_page' )->once()->with( 20 )->andReturn( 0 );
		$this->assertSame( 1, EntriesTable::per_page() );
	}

	public function test_list_activity_passes_its_filters_to_the_store(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->store->shouldReceive( 'find' )->once()->with(
			\Mockery::on(
				static function ( array $args ) {
					return 'option_updated' === $args['event'] && 5 === $args['user'] && '2026-10-01 00:00:00' === $args['since'] && true === $args['undoable'] && 100 === $args['per_page'];
				}
			)
		)->andReturn(
			array(
				'entries' => array( $this->entry( array( 'object_id' => 'widget' ) ) ),
				'total'   => 1,
			)
		);
		$registry  = new Registry( array( new FakeRecorder() ), new Settings() );
		$abilities = new Abilities( $this->store, $registry, new Undoer( $this->store, $this->logger(), $registry ) );

		$list = $abilities->list_activity(
			array(
				'event'         => 'option_updated',
				'user'          => 5,
				'since'         => '2026-10-01',
				'undoable_only' => true,
				'limit'         => 500,
			)
		);

		$this->assertSame( 'Fake widget', $list[0]['description'] );
	}

	public function test_abilities_only_register_when_wordpress_has_the_api(): void {
		$registry = new Registry( array(), new Settings() );
		( new Abilities( $this->store, $registry, new Undoer( $this->store, $this->logger(), $registry ) ) )->register();

		$this->assertFalse( has_action( 'wp_abilities_api_init' ) );
	}

	public function test_dates_accept_plain_days_iso_and_relative_times(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-10-01 00:00:00', Abilities::date( '2026-10-01' ) );
		$this->assertSame( '2026-10-01 23:59:59', Abilities::date( '2026-10-01', true ) );
		$this->assertSame( '2026-10-01 10:00:00', Abilities::date( '2026-10-01T12:00:00+02:00' ) );
		$this->assertSame( '', Abilities::date( 'not a date at all' ) );
	}

	public function test_installs_updates_and_deletions_are_logged_only_and_say_why(): void {
		$plugins = new PluginsRecorder( $this->logger() );
		$themes  = new ThemesRecorder( $this->logger() );

		foreach ( array( 'plugin_installed', 'plugin_updated', 'plugin_deleted' ) as $event ) {
			$this->assertInstanceOf( \WP_Error::class, $plugins->can_undo( $this->entry( array( 'event' => $event, 'undoable' => 0 ) ) ), $event );
		}
		foreach ( array( 'theme_installed', 'theme_updated', 'theme_deleted' ) as $event ) {
			$this->assertInstanceOf( \WP_Error::class, $themes->can_undo( $this->entry( array( 'event' => $event, 'undoable' => 0 ) ) ), $event );
		}
	}
}
