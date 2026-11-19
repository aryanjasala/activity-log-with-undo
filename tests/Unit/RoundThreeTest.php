<?php
/**
 * Tests for round 3: recorded versions of updates, post terms and sticky, the sibling recorder, and the admin bar label.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\SmokeTest {

	/**
	 * Stand-in for Axle Smoke Test's backup index.
	 */
	final class Backups {
		/** @var array<string, array> */
		public static $index = array();

		public static function find( string $type, string $item, string $version = '' ) {
			return self::$index[ $type . '|' . $item . '|' . $version ] ?? null;
		}
	}

	/**
	 * Stand-in for Axle Smoke Test's plugin class.
	 */
	final class Plugin {
		/** @var object|null */
		public static $running;

		public static function instance() {
			return self::$running;
		}
	}
}

namespace AryanX\Axle\Leftovers {

	/**
	 * Stand-in for Leftovers' plugin class.
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

	use AryanX\Axle\ActivityLogWithUndo\Admin\AdminBar;
	use AryanX\Axle\ActivityLogWithUndo\Integrations\CategoryTagTrash;
	use AryanX\Axle\ActivityLogWithUndo\Recorders\PluginsRecorder;
	use AryanX\Axle\ActivityLogWithUndo\Recorders\PostsRecorder;
	use AryanX\Axle\ActivityLogWithUndo\Recorders\SiblingsRecorder;
	use AryanX\Axle\ActivityLogWithUndo\Rollback;
	use AryanX\Axle\SmokeTest\Backups;
	use AryanX\Axle\SmokeTest\Plugin as SmokePlugin;
	use Brain\Monkey\Functions;

	final class RoundThreeTest extends LogTestCase {

		protected function setUp(): void {
			parent::setUp();
			Backups::$index       = array();
			SmokePlugin::$running = null;
		}

		/**
		 * A real plugin file in a temp folder, so the readable check passes, and stubs for the admin functions.
		 */
		private function pluginFile(): void {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/alu-plugins-test' );
			}
			if ( ! is_dir( WP_PLUGIN_DIR . '/shop' ) ) {
				mkdir( WP_PLUGIN_DIR . '/shop', 0777, true );
			}
			file_put_contents( WP_PLUGIN_DIR . '/shop/shop.php', "<?php\n" );
			Functions\when( 'activate_plugin' )->justReturn( true );
		}

		public function test_before_six_am_this_morning_becomes_yesterday_at_six(): void {
			$zone  = new \DateTimeZone( 'UTC' );
			$early = Rollback::morning( strtotime( '2026-11-20 04:30:00 UTC' ), $zone );
			$late  = Rollback::morning( strtotime( '2026-11-20 14:00:00 UTC' ), $zone );

			$this->assertSame( 'Yesterday 6 am', $early['label'] );
			$this->assertSame( strtotime( '2026-11-19 06:00:00 UTC' ), $early['time'] );
			$this->assertSame( 'This morning (6 am)', $late['label'] );
			$this->assertSame( strtotime( '2026-11-20 06:00:00 UTC' ), $late['time'] );
		}

		public function test_the_admin_bar_label_names_the_change(): void {
			$this->assertSame( 'Undo: changed Site title', AdminBar::label( 'Changed Site title' ) );
			$long = 'Changed the Page "A very long title that cannot fit" content';
			$this->assertLessThan( strlen( 'Undo: ' . $long ), strlen( AdminBar::label( $long ) ) );
		}

		public function test_an_update_records_the_version_it_replaced_and_is_undoable_only_with_a_saved_copy(): void {
			Functions\when( 'get_plugin_data' )->justReturn( array( 'Name' => 'Shop', 'Version' => '1.0' ) );
			Functions\when( 'validate_file' )->justReturn( 0 );
			$this->pluginFile();

			$recorder = new PluginsRecorder( $this->logger() );
			$recorder->before_install( true, array( 'plugin' => 'shop/shop.php' ) );
			Functions\when( 'get_plugin_data' )->justReturn( array( 'Name' => 'Shop', 'Version' => '1.1' ) );

			$recorder->upgraded( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'shop/shop.php' ) ) );
			$this->assertSame( array( 'version' => '1.0' ), $this->state( 1, 'before' ) );
			$this->assertSame( array( 'version' => '1.1' ), $this->state( 1, 'after' ) );
			$this->assertSame( 0, $this->rows[1]['undoable'] );

			Backups::$index['plugin|shop/shop.php|1.0'] = array( 'version' => '1.0' );
			$recorder->before_install( true, array( 'plugin' => 'shop/shop.php' ) );
			$recorder->upgraded( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'shop/shop.php' ) ) );
			// No "before" this time (the version was read after the swap), so it cannot be undone.
			$this->assertSame( 0, $this->rows[2]['undoable'] );
		}

		public function test_an_update_with_a_copy_saved_is_undoable_and_goes_through_smoke_test(): void {
			Functions\when( 'get_plugin_data' )->alias(
				static function () {
					static $calls = 0;
					return array( 'Name' => 'Shop', 'Version' => 0 === $calls++ ? '1.0' : '1.1' );
				}
			);
			Functions\when( 'validate_file' )->justReturn( 0 );
			$this->pluginFile();
			Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
			Backups::$index['plugin|shop/shop.php|1.0'] = array( 'version' => '1.0' );
			$restored                                   = array();
			SmokePlugin::$running                       = new class( $restored ) {
				/** @var array */
				private $restored;

				public function __construct( array &$restored ) {
					$this->restored = &$restored;
				}

				public function rollback() {
					$sink = &$this->restored;
					return new class( $sink ) {
						/** @var array */
						private $sink;

						public function __construct( array &$sink ) {
							$this->sink = &$sink;
						}

						public function restore( array $candidate ) {
							$this->sink[] = $candidate['version'];
							return true;
						}
					};
				}
			};

			$recorder = new PluginsRecorder( $this->logger() );
			$recorder->before_install( true, array( 'plugin' => 'shop/shop.php' ) );
			$recorder->upgraded( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'shop/shop.php' ) ) );

			$this->assertSame( 1, $this->rows[1]['undoable'] );
			$entry = \AryanX\Axle\ActivityLogWithUndo\Entry::from_row( $this->rows[1] );
			$this->assertTrue( $recorder->can_undo( $entry ) );
			$this->assertTrue( $recorder->undo( $entry ) );
			$this->assertSame( array( '1.0' ), $restored );
			$this->assertStringContainsString( 'from 1.0 to 1.1', $recorder->describe( $entry ) );

			Backups::$index = array();
			$this->assertInstanceOf( \WP_Error::class, $recorder->can_undo( $entry ) );
		}

		public function test_replacing_a_posts_terms_is_logged_and_adding_to_them_is_not(): void {
			Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
			Functions\when( 'get_post' )->justReturn( new Post( array( 'ID' => 7, 'post_title' => 'Hello' ) ) );
			Functions\when( 'get_term_by' )->alias(
				static function ( $field, $tt_id ) {
					return new \WP_Term( array( 'term_id' => $tt_id * 10, 'name' => 'T' ) );
				}
			);
			Functions\when( 'is_object_in_taxonomy' )->justReturn( true );
			$recorder = new PostsRecorder( $this->logger() );

			$recorder->terms_set( 7, array(), array( 2, 3 ), 'category', true, array( 1 ) );
			$this->assertSame( array(), $this->rows );

			$recorder->terms_set( 7, array(), array( 1 ), 'category', false, array( 1 ) );
			$this->assertSame( array(), $this->rows );

			$recorder->terms_set( 7, array(), array( 2, 3 ), 'category', false, array( 1 ) );
			$this->assertSame( 'post_terms_changed', $this->rows[1]['event'] );
			$this->assertSame(
				array( 'taxonomy' => 'category', 'terms' => array( 10 ) ),
				$this->state( 1, 'before' )
			);
			$this->assertSame( array( 20, 30 ), $this->state( 1, 'after' )['terms'] );
			$this->assertSame( 1, $this->rows[1]['undoable'] );
		}

		public function test_a_post_password_is_never_shown_but_still_comes_back_on_undo(): void {
			$entry = $this->entry(
				array(
					'event'       => 'post_attributes_changed',
					'object_type' => 'post',
					'before'      => array( 'post_password' => 'secret' ),
					'after'       => array( 'post_password' => 'other' ),
				)
			);

			$this->assertSame( array( 'post_password' => '(a password)' ), $entry->shown_before() );
			$this->assertSame( array( 'post_password' => '(a password)' ), $entry->shown_after() );
			$this->assertSame( array( 'post_password' => 'secret' ), $entry->before() );
		}

		public function test_the_first_terms_of_a_new_post_are_part_of_its_creation(): void {
			Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
			Functions\when( 'get_post' )->justReturn( new Post( array( 'ID' => 7, 'post_title' => 'Hello' ) ) );
			Functions\when( 'is_object_in_taxonomy' )->justReturn( true );
			Functions\when( 'get_term_by' )->alias(
				static function ( $field, $tt_id ) {
					return new \WP_Term( array( 'term_id' => $tt_id, 'name' => 'T' ) );
				}
			);
			$recorder = new PostsRecorder( $this->logger() );

			$recorder->insert_starts( array(), array(), array(), false );
			$recorder->terms_set( 7, array(), array( 1 ), 'category', false, array() );
			$recorder->insert_ends();
			$this->assertSame( array(), $this->rows );

			$recorder->terms_set( 7, array(), array( 1 ), 'category', false, array() );
			$this->assertCount( 1, $this->rows );

			// Posts that lose a term because it was deleted are part of the deletion.
			$recorder->term_delete_starts();
			$recorder->terms_set( 7, array(), array( 1 ), 'category', false, array( 2 ) );
			$recorder->term_delete_ends();
			$this->assertCount( 1, $this->rows );
		}

		public function test_sticking_a_post_is_logged_with_the_old_value(): void {
			Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
			Functions\when( 'get_post' )->justReturn( new Post( array( 'ID' => 7, 'post_title' => 'Hello' ) ) );
			$recorder = new PostsRecorder( $this->logger() );

			$recorder->stuck( 7 );
			$recorder->unstuck( 7 );

			$this->assertSame( array( 'sticky' => false ), $this->state( 1, 'before' ) );
			$this->assertSame( array( 'sticky' => false ), $this->state( 2, 'after' ) );
			$this->assertSame( 'post_sticky_changed', $this->rows[2]['event'] );
		}

		public function test_the_sibling_recorder_logs_a_merge_and_undoes_it_through_the_trash(): void {
			$trash    = new CategoryTagTrash();
			$recorder = new SiblingsRecorder( $this->logger(), $trash );
			$source   = new \WP_Term( array( 'term_id' => 4, 'name' => 'Old', 'taxonomy' => 'category' ) );
			$target   = new \WP_Term( array( 'term_id' => 5, 'name' => 'New', 'taxonomy' => 'category' ) );

			$recorder->merged( 77, $source, $target, 3 );
			$entry = \AryanX\Axle\ActivityLogWithUndo\Entry::from_row( $this->rows[1] );

			$this->assertSame( 'ctt_merged', $this->rows[1]['event'] );
			$this->assertSame( 'category', $this->rows[1]['object_type'] );
			$this->assertSame( '5', $this->rows[1]['object_id'] );
			$this->assertSame( 1, $this->rows[1]['undoable'] );
			$this->assertSame( 77, $this->state( 1, 'before' )['ctt_item'] );
			$this->assertSame( 'Merged "Old" into "New" (3 moved)', $recorder->describe( $entry ) );
			// Category & Tag Trash is not running here, so the undo explains why not.
			$this->assertInstanceOf( \WP_Error::class, $recorder->can_undo( $entry ) );
		}

		public function test_other_sibling_events_without_an_owner_are_logged_only(): void {
			$recorder = new SiblingsRecorder( $this->logger(), new CategoryTagTrash() );
			Functions\when( 'get_post' )->justReturn( new Post( array( 'ID' => 7, 'post_title' => 'Hello' ) ) );

			$recorder->block_restored( 7, array( 'blockName' => 'core/paragraph' ), 12, 'replace' );
			$recorder->term_restored( 9, 'category', (object) array( 'name' => 'News' ), array( 1, 2 ) );
			$recorder->leftovers_restored( array( array() ), 'b1' );

			$this->assertSame( array( 0, 0, 0 ), array_map( 'intval', array_column( $this->rows, 'undoable' ) ) );
			$this->assertInstanceOf( \WP_Error::class, $recorder->can_undo( \AryanX\Axle\ActivityLogWithUndo\Entry::from_row( $this->rows[2] ) ) );
		}
	}
}
