<?php
/**
 * Tests for the per-object check on log entries.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Abilities;
use AryanX\Axle\ActivityLogWithUndo\Access;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AccessTest extends LogTestCase {

	/**
	 * Makes the current user lack these capabilities, whatever they are checked on.
	 *
	 * @param string ...$denied Capabilities.
	 */
	private function deny( string ...$denied ): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) use ( $denied ) {
				return ! in_array( $capability, $denied, true );
			}
		);
	}

	public function test_settings_need_manage_options_whatever_the_log_capability_is(): void {
		$this->deny( 'manage_options' );

		$this->assertFalse( Access::can_view( $this->entry( array( 'object_type' => 'option', 'object_id' => 'blogname' ) ) ) );
	}

	public function test_a_post_needs_edit_post_for_that_post(): void {
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts' ) ) );
		$this->deny( 'edit_post' );

		$this->assertFalse( Access::can_view( $this->entry( array( 'object_type' => 'post', 'object_id' => '7' ) ) ) );
	}

	public function test_a_deleted_post_falls_back_to_the_post_types_own_capability(): void {
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_pages' ) ) );
		$this->deny( 'edit_pages' );

		$this->assertFalse( Access::can_view( $this->entry( array( 'object_type' => 'page', 'object_id' => '7' ) ) ) );
	}

	public function test_a_user_needs_edit_user_for_that_user(): void {
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 4 ) );
		$this->deny( 'edit_user' );

		$this->assertFalse( Access::can_view( $this->entry( array( 'object_type' => 'user', 'object_id' => '4' ) ) ) );
	}

	public function test_a_term_needs_edit_term_for_that_term(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_taxonomy' )->justReturn( (object) array( 'cap' => (object) array( 'edit_terms' => 'manage_categories' ) ) );
		Functions\when( 'get_term' )->justReturn( new \WP_Term() );
		$this->deny( 'edit_term' );

		$this->assertFalse( Access::can_view( $this->entry( array( 'object_type' => 'category', 'object_id' => '9' ) ) ) );
	}

	public function test_the_filter_has_the_last_word(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Filters\expectApplied( 'activity_log_with_undo_can_view_entry' )->once()->with( false, \Mockery::type( \AryanX\Axle\ActivityLogWithUndo\Entry::class ), 1 )->andReturn( true );

		$this->assertTrue( Access::can_view( $this->entry( array( 'object_type' => 'option', 'object_id' => 'blogname' ) ) ) );
	}

	public function test_a_hidden_entry_loses_its_name_values_and_ip_in_abilities(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$entry    = $this->entry(
			array(
				'object_type'  => 'option',
				'object_id'    => 'secret_option',
				'object_label' => 'Secret',
				'ip'           => '10.0.0.1',
				'before'       => 'a',
				'after'        => 'b',
			)
		);
		$registry = new Registry( array( new FakeRecorder() ), new Settings() );
		$logger   = $this->logger();
		$undoer   = new Undoer( $this->store, $logger, $registry );
		$details  = ( new Abilities( $this->store, $registry, $undoer, new Rollback( $this->store, $logger, $undoer, $registry ) ) )->details( $entry );

		$this->assertTrue( $details['masked'] );
		$this->assertSame( '', $details['object_id'] );
		$this->assertSame( '', $details['object_label'] );
		$this->assertSame( '', $details['ip'] );
		$this->assertNull( $details['before'] );
		$this->assertNull( $details['after'] );
		$this->assertFalse( $details['can_undo'] );
		$this->assertSame( Access::hidden_text(), $details['description'] );
	}

	public function test_undo_refuses_an_entry_the_user_may_not_edit(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$recorder = new FakeRecorder();
		$registry = new Registry( array( $recorder ), new Settings() );
		$undoer   = new Undoer( $this->store, $this->logger(), $registry );
		$entry    = $this->entry(
			array(
				'before' => 'old',
				'after'  => 'new',
			)
		);

		$result = $undoer->undo( $entry->id );

		$this->assertSame( 'activity_log_with_undo_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $recorder->undone );
	}
}
