<?php
/**
 * Tests for the posts recorder.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Recorders\PostsRecorder;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PostsRecorderTest extends LogTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'          => 'post',
				'page'          => 'page',
				'wp_template'   => 'wp_template',
				'nav_menu_item' => 'nav_menu_item',
			)
		);
	}

	private function post( array $fields ): \WP_Post {
		return new Post(
			$fields + array(
				'ID'           => 7,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Hello',
				'post_content' => 'Body',
				'post_excerpt' => '',
				'post_name'    => 'hello',
				'post_parent'  => 0,
				'post_author'  => 1,
			)
		);
	}

	public function test_status_changes_become_created_trashed_restored_or_changed(): void {
		$recorder = new PostsRecorder( $this->logger() );
		$post     = $this->post( array() );

		$recorder->status_changed( 'publish', 'auto-draft', $post );
		$recorder->status_changed( 'trash', 'publish', $post );
		$recorder->status_changed( 'draft', 'trash', $post );
		$recorder->status_changed( 'publish', 'draft', $post );
		$recorder->status_changed( 'auto-draft', 'new', $post );
		$recorder->status_changed( 'publish', 'publish', $post );

		$this->assertSame( array( 'post_created', 'post_trashed', 'post_untrashed', 'post_status_changed' ), array_column( $this->rows, 'event' ) );
		$this->assertSame( array( 'post_status' => 'publish' ), $this->state( 2, 'before' ) );
		$this->assertSame( 'post', $this->rows[1]['object_type'] );
	}

	public function test_menu_items_templates_and_filtered_out_types_are_ignored(): void {
		Filters\expectApplied( 'activity_log_with_undo_post_types' )->once()->with( array( 'post', 'page' ) )->andReturn( array( 'page', 'wp_template' ) );
		$recorder = new PostsRecorder( $this->logger() );

		$recorder->status_changed( 'trash', 'publish', $this->post( array() ) );
		$recorder->status_changed( 'trash', 'publish', $this->post( array( 'post_type' => 'wp_template' ) ) );
		$recorder->status_changed( 'trash', 'publish', $this->post( array( 'post_type' => 'page' ) ) );

		$this->assertSame( array( 'page' ), array_column( $this->rows, 'object_type' ) );
	}

	public function test_edits_log_changed_fields_and_ignore_the_trashed_slug(): void {
		$recorder = new PostsRecorder( $this->logger() );

		$recorder->updated( 7, $this->post( array( 'post_title' => 'Hello again', 'post_parent' => 3 ) ), $this->post( array() ) );
		$recorder->updated( 7, $this->post( array( 'post_status' => 'trash', 'post_name' => 'hello__trashed' ) ), $this->post( array() ) );
		$recorder->updated( 7, $this->post( array( 'post_title' => 'First' ) ), $this->post( array( 'post_status' => 'auto-draft' ) ) );

		$this->assertSame( array( 'post_updated', 'post_attributes_changed' ), array_column( $this->rows, 'event' ) );
		$this->assertSame( array( 'post_title' => 'Hello' ), $this->state( 1, 'before' ) );
		$this->assertSame( array( 'post_parent' => 3 ), $this->state( 2, 'after' ) );
	}

	public function test_featured_image_and_template_changes_are_logged_from_meta(): void {
		$recorder = new PostsRecorder( $this->logger() );
		Functions\when( 'get_post' )->justReturn( $this->post( array() ) );
		Functions\when( 'get_post_meta' )->justReturn( '12' );

		$recorder->before_meta( 1, 7, '_thumbnail_id' );
		$recorder->meta_changed( 1, 7, '_thumbnail_id', '15' );
		$recorder->meta_added( 2, 7, '_wp_page_template', 'wide.php' );
		$recorder->meta_added( 3, 7, '_edit_lock', '123' );

		$this->assertSame( array( 'post_featured_image_changed', 'post_template_changed' ), array_column( $this->rows, 'event' ) );
		$this->assertSame( array( '_thumbnail_id' => '12' ), $this->state( 1, 'before' ) );
		$this->assertSame( array( '_wp_page_template' => '' ), $this->state( 2, 'before' ) );
	}

	public function test_the_newest_matching_revision_is_found_within_the_search_limit(): void {
		Functions\when( 'get_post' )->justReturn( $this->post( array() ) );
		Functions\when( 'wp_revisions_enabled' )->justReturn( true );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Filters\expectApplied( 'activity_log_with_undo_revision_search_limit' )->once()->with( 50 )->andReturn( 9999 );
		Functions\expect( 'wp_get_post_revisions' )->once()->with( 7, array( 'posts_per_page' => 500 ) )->andReturn(
			array(
				new Post( array( 'ID' => 30, 'post_title' => 'New' ) ),
				new Post( array( 'ID' => 20, 'post_title' => 'Old' ) ),
			)
		);

		$this->assertSame( 20, PostsRecorder::matching_revision( 7, array( 'post_title' => 'Old' ) ) );
	}
}
