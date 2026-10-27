<?php
/**
 * Tests for the recorder registry and descriptions.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use Brain\Monkey\Filters;

final class RegistryTest extends LogTestCase {

	public function test_the_recorders_filter_can_add_and_remove_recorders_and_junk_is_dropped(): void {
		$extra = new FakeRecorder( 'extra' );
		Filters\expectApplied( 'activity_log_with_undo_recorders' )->once()->andReturnUsing(
			static function ( array $recorders ) use ( $extra ) {
				unset( $recorders['fake'] );
				$recorders[] = $extra;
				$recorders[] = 'not a recorder';
				return $recorders;
			}
		);

		$registry = new Registry( array( new FakeRecorder( 'fake' ) ), new Settings() );

		$this->assertSame( array( 'extra' ), array_keys( $registry->all() ) );
		$this->assertSame( $extra, $registry->get( 'extra' ) );
		$this->assertNull( $registry->get( 'fake' ) );
	}

	public function test_only_recorders_that_are_on_hook_in(): void {
		$this->options[ Settings::OPTION ] = array( 'disabled' => array( 'off' ) );
		$on                                = new FakeRecorder( 'on' );
		$off                               = new FakeRecorder( 'off' );

		( new Registry( array( $on, $off ), new Settings() ) )->register();

		$this->assertSame( 1, $on->registered );
		$this->assertSame( 0, $off->registered );
	}

	public function test_descriptions_come_from_the_recorder_and_can_be_filtered(): void {
		$registry = new Registry( array( new FakeRecorder() ), new Settings() );
		$entry    = Entry::from_row(
			array(
				'recorder'  => 'fake',
				'object_id' => 'widget',
			)
		);
		$this->assertSame( 'Fake widget', $registry->describe( $entry ) );

		Filters\expectApplied( 'activity_log_with_undo_entry_description' )->once()->with( 'Fake widget', $entry )->andReturn( 'Custom' );
		$this->assertSame( 'Custom', $registry->describe( $entry ) );

		Filters\expectApplied( 'activity_log_with_undo_entry_description' )->once()->andReturn( '' );
		$this->assertSame( 'Fake widget', $registry->describe( $entry ), 'An empty description falls back.' );
	}

	public function test_undo_entries_describe_what_they_undid_and_events_are_listed(): void {
		$registry = new Registry( array( new FakeRecorder() ), new Settings() );

		$this->assertSame( 'Undid change #7', $registry->describe( Entry::from_row( array( 'event' => 'undo', 'undo_of' => 7 ) ) ) );
		$this->assertSame( array( 'undo', 'fake_changed' ), array_keys( $registry->events() ) );
	}
}
