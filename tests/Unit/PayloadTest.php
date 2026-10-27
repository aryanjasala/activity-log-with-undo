<?php
/**
 * Tests for storing before and after states.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Payload;

final class PayloadTest extends LogTestCase {

	public function test_json_safe_values_round_trip_as_readable_json(): void {
		foreach ( array( 'Site title', 5, 1.5, true, array( 'a' => 1, 'b' => array( 2, 3 ) ), array() ) as $value ) {
			$stored = Payload::encode( $value );
			$this->assertStringStartsWith( '{"v":', $stored );
			$this->assertSame( $value, Payload::decode( $stored ) );
		}
	}

	public function test_objects_are_serialized_so_they_come_back_as_objects(): void {
		$value       = new \stdClass();
		$value->name = 'Widget';

		$stored = Payload::encode( array( 'item' => $value ) );

		$this->assertStringStartsWith( '{"s":', $stored );
		$this->assertEquals( array( 'item' => $value ), Payload::decode( $stored ) );
		$this->assertInstanceOf( \stdClass::class, Payload::decode( $stored )['item'] );
	}

	public function test_null_means_no_state(): void {
		$this->assertNull( Payload::encode( null ) );
		$this->assertNull( Payload::decode( null ) );
		$this->assertNull( Payload::decode( 'not json' ) );
	}

	public function test_states_over_the_limit_keep_only_their_size(): void {
		$marker = Payload::over_limit( 300000 );

		$this->assertSame( 300000, Payload::left_out( $marker ) );
		$this->assertSame( 0, Payload::left_out( Payload::encode( 'small' ) ) );
		$this->assertNull( Payload::decode( $marker ) );
	}

	public function test_same_compares_the_way_the_database_returns_values(): void {
		$this->assertTrue( Payload::same( 5, '5' ) );
		$this->assertTrue( Payload::same( true, '1' ) );
		$this->assertTrue( Payload::same( null, '' ) );
		$this->assertTrue( Payload::same( array( 'b' => 2, 'a' => 1 ), array( 'a' => '1', 'b' => '2' ) ) );
		$this->assertFalse( Payload::same( 'Old title', 'New title' ) );
		$this->assertFalse( Payload::same( array( 'a' ), array( 'a', 'b' ) ) );
	}

	public function test_entries_decode_their_states_and_know_when_they_were_undone(): void {
		$entry = Entry::from_row(
			array(
				'id'           => 3,
				'created_gmt'  => '2026-10-01 12:00:00',
				'before_state' => Payload::encode( 'Old' ),
				'after_state'  => Payload::encode( 'New' ),
				'undoable'     => '1',
				'undone_by'    => '9',
			)
		);

		$this->assertSame( 'Old', $entry->before() );
		$this->assertSame( 'New', $entry->after() );
		$this->assertTrue( $entry->is_undone() );
		$this->assertSame( 9, $entry->undone_by );
		$this->assertNull( $entry->undo_of );
		$this->assertSame( gmmktime( 12, 0, 0, 10, 1, 2026 ), $entry->timestamp() );
	}
}
