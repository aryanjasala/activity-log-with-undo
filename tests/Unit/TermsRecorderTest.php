<?php
/**
 * Tests for the terms recorder, and handing deleted terms to Category & Tag Trash.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\CategoryTagTrash {

	/**
	 * Stand-in for Category & Tag Trash's plugin class.
	 */
	final class Plugin {
		/** @var object|null */
		public static $running;

		public static function instance() {
			return self::$running;
		}
	}
}

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit {

	use AryanX\Axle\ActivityLogWithUndo\Integrations\CategoryTagTrash;
	use AryanX\Axle\ActivityLogWithUndo\Recorders\TermsRecorder;
	use AryanX\Axle\CategoryTagTrash\Plugin as TrashPlugin;
	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;

	final class TermsRecorderTest extends LogTestCase {

		protected function setUp(): void {
			parent::setUp();
			TrashPlugin::$running = null;
			Functions\when( 'get_taxonomies' )->justReturn(
				array(
					'category' => 'category',
					'post_tag' => 'post_tag',
					'nav_menu' => 'nav_menu',
				)
			);
			Functions\when( 'get_term' )->alias(
				static function ( $term_id ) {
					return new \WP_Term(
						array(
							'term_id' => $term_id,
							'name'    => 'News',
							'slug'    => 'news',
						)
					);
				}
			);
			Functions\when( 'get_objects_in_term' )->justReturn( array( '11', '12' ) );
			Functions\when( 'get_term_meta' )->justReturn( array( 'color' => array( 'blue' ) ) );
			Functions\when( 'get_terms' )->justReturn( array() );
			Functions\when( 'get_taxonomy' )->justReturn(
				(object) array(
					'default_term' => null,
					'labels'       => (object) array( 'singular_name' => 'Tag' ),
				)
			);
			Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		}

		public function test_a_deleted_term_keeps_its_meta_posts_and_children(): void {
			$recorder = new TermsRecorder( $this->logger(), new CategoryTagTrash() );

			$recorder->before_delete( 4, 'post_tag' );
			$recorder->deleted( 4, 40, 'post_tag' );

			$snapshot = $this->state( 1, 'before' );
			$this->assertSame( 'term_deleted', $this->rows[1]['event'] );
			$this->assertSame( 1, $this->rows[1]['undoable'] );
			$this->assertSame( array( 11, 12 ), $snapshot['objects'] );
			$this->assertSame( array( 'color' => array( 'blue' ) ), $snapshot['meta'] );
		}

		public function test_terms_with_more_posts_than_the_limit_are_logged_but_not_undoable(): void {
			Filters\expectApplied( 'activity_log_with_undo_term_object_limit' )->once()->with( 5000 )->andReturn( 1 );
			$recorder = new TermsRecorder( $this->logger(), new CategoryTagTrash() );

			$recorder->before_delete( 4, 'post_tag' );
			$recorder->deleted( 4, 40, 'post_tag' );

			$this->assertSame( 0, $this->rows[1]['undoable'] );
			$this->assertSame( 2, $this->state( 1, 'before' )['objects_count'] );
		}

		public function test_with_category_tag_trash_active_the_term_is_restored_from_its_trash(): void {
			$restorer             = new class() {
				/** @var array */
				public $calls = array();

				public function restore( $item_id, $finish_now ) {
					$this->calls[] = array( $item_id, $finish_now );
					return array( 'term_id' => 9 );
				}
			};
			TrashPlugin::$running = new class( $restorer ) {
				/** @var object */
				private $restorer;

				public function __construct( $restorer ) {
					$this->restorer = $restorer;
				}

				public function restorer() {
					return $this->restorer;
				}
			};
			$trash    = new CategoryTagTrash();
			$recorder = new TermsRecorder( $this->logger(), $trash );

			$recorder->before_delete( 4, 'post_tag' );
			$trash->trashed( 77, (object) array( 'term_id' => 4 ) );
			$recorder->deleted( 4, 40, 'post_tag' );

			$snapshot = $this->state( 1, 'before' );
			$this->assertSame( 77, $snapshot['ctt_item'] );
			$this->assertArrayNotHasKey( 'objects', $snapshot );
			$this->assertStringContainsString( 'Category & Tag Trash', $recorder->describe( \AryanX\Axle\ActivityLogWithUndo\Entry::from_row( $this->rows[1] ) ) );

			$this->assertTrue( $recorder->undo( \AryanX\Axle\ActivityLogWithUndo\Entry::from_row( $this->rows[1] ) ) );
			$this->assertSame( array( array( 77, true ) ), $restorer->calls );
		}

		public function test_without_category_tag_trash_its_items_cannot_be_restored(): void {
			$result = ( new CategoryTagTrash() )->restore( 77 );

			$this->assertInstanceOf( \WP_Error::class, $result );
		}

		public function test_edits_log_changed_fields_and_menus_are_left_to_their_own_recorder(): void {
			Filters\expectApplied( 'activity_log_with_undo_taxonomies' )->once()->with( array( 'category', 'post_tag' ) )->andReturn( array( 'category', 'nav_menu' ) );
			$recorder = new TermsRecorder( $this->logger(), new CategoryTagTrash() );

			$this->assertFalse( $recorder->covers( 'nav_menu' ) );
			$this->assertFalse( $recorder->covers( 'post_tag' ) );
			$this->assertTrue( $recorder->covers( 'category' ) );

			$recorder->editing( 4, 'category' );
			Functions\when( 'get_term' )->justReturn(
				new \WP_Term(
					array(
						'term_id' => 4,
						'name'    => 'Latest news',
						'slug'    => 'news',
					)
				)
			);
			$recorder->edited( 4, 40, 'category' );

			$this->assertSame( array( 'name' => 'News' ), $this->state( 1, 'before' ) );
			$this->assertSame( array( 'name' => 'Latest news' ), $this->state( 1, 'after' ) );
		}
	}
}
