<?php
/**
 * Tests for the users recorder.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Recorders\UsersRecorder;
use Brain\Monkey\Functions;

final class UsersRecorderTest extends LogTestCase {

	/**
	 * Capabilities meta per user.
	 *
	 * @var array<int, array>
	 */
	private $caps = array();

	/**
	 * The user being changed.
	 *
	 * @var \WP_User
	 */
	private $user;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb'] = new class() {
			public function get_blog_prefix() {
				return 'wp_';
			}
		};
		$roles = new class() {
			public function is_role( $role ) {
				return in_array( $role, array( 'author', 'editor', 'subscriber' ), true );
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id ) {
				return $this->caps[ $user_id ] ?? array();
			}
		);
		$this->user = new \WP_User(
			array(
				'ID'           => 5,
				'user_login'   => 'sam',
				'user_email'   => 'sam@example.com',
				'display_name' => 'Sam',
			)
		);
		Functions\when( 'get_userdata' )->justReturn( $this->user );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_one_role_change_that_fires_three_hooks_is_one_entry(): void {
		$recorder       = new UsersRecorder( $this->logger() );
		$this->caps[5]  = array( 'author' => true );

		$recorder->before_meta_update( 1, 5, 'wp_capabilities' );
		$this->caps[5] = array( 'editor' => true );
		$recorder->roles_changed( 5 );
		$recorder->roles_changed( 5 );
		$recorder->roles_changed( 5 );

		$this->assertCount( 1, $this->rows );
		$this->assertSame( 'user_role_changed', $this->rows[1]['event'] );
		$this->assertSame( array( 'author' ), $this->state( 1, 'before' ) );
		$this->assertSame( array( 'editor' ), $this->state( 1, 'after' ) );
	}

	public function test_roles_set_on_a_new_user_are_not_a_role_change(): void {
		$recorder = new UsersRecorder( $this->logger() );

		$recorder->before_meta_add( 5, 'wp_capabilities' );
		$this->caps[5] = array( 'subscriber' => true );
		$recorder->roles_changed( 5 );

		$this->assertSame( array(), $this->rows );
	}

	public function test_undoing_a_role_change_restores_the_whole_old_set(): void {
		$recorder      = new UsersRecorder( $this->logger() );
		$this->caps[5] = array(
			'author'     => true,
			'subscriber' => true,
		);

		$result = $recorder->undo(
			$this->entry(
				array(
					'event'       => 'user_role_changed',
					'object_type' => 'user',
					'object_id'   => '5',
					'before'      => array( 'author', 'subscriber' ),
					'after'       => array( 'editor' ),
				)
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( array( array( 'set_role', 'author' ), array( 'add_role', 'subscriber' ) ), $this->user->calls );
	}

	public function test_profile_changes_log_only_the_fields_that_changed(): void {
		$recorder                 = new UsersRecorder( $this->logger() );
		$this->user->display_name = 'Samantha';

		$recorder->profile_updated(
			5,
			new \WP_User(
				array(
					'display_name' => 'Sam',
					'user_email'   => 'sam@example.com',
				)
			)
		);

		$this->assertSame( array( 'display_name' => 'Sam' ), $this->state( 1, 'before' ) );
		$this->assertSame( array( 'display_name' => 'Samantha' ), $this->state( 1, 'after' ) );
	}

	public function test_undoing_a_new_user_needs_someone_to_give_their_content_to(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_super_admin' )->justReturn( false );
		$recorder = new UsersRecorder( $this->logger() );

		$result = $recorder->undo(
			$this->entry(
				array(
					'event'         => 'user_created',
					'object_type'   => 'user',
					'object_id'     => '5',
					'actor_user_id' => 0,
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_deleted_users_cannot_be_undone(): void {
		$recorder = new UsersRecorder( $this->logger() );

		$this->assertInstanceOf( \WP_Error::class, $recorder->can_undo( $this->entry( array( 'event' => 'user_deleted', 'undoable' => 0 ) ) ) );
	}
}
