<?php
/**
 * Test doubles: a recorder, and stand-ins for WordPress classes the unit tests touch.
 *
 * @package AryanX\ActivityLogWithUndo
 */

// phpcs:ignoreFile

namespace AryanX\ActivityLogWithUndo\Tests\Unit {

	use AryanX\ActivityLogWithUndo\Entry;
	use AryanX\ActivityLogWithUndo\Recorders\Recorder;

	/**
	 * A recorder whose behaviour each test sets.
	 */
	final class FakeRecorder implements Recorder {

		/** @var string */
		public $id;
		/** @var mixed Current state, or a callable that returns it. */
		public $current;
		/** @var true|\WP_Error */
		public $can = true;
		/** @var true|\WP_Error */
		public $result = true;
		/** @var callable|null Runs during undo, e.g. to log the reversal. */
		public $on_undo;
		/** @var int[] */
		public $undone = array();
		/** @var int */
		public $registered = 0;
		/** @var int */
		public $flushed = 0;

		public function __construct( string $id = 'fake' ) {
			$this->id = $id;
		}

		public function id(): string {
			return $this->id;
		}

		public function label(): string {
			return 'Fake things';
		}

		public function events(): array {
			return array( 'fake_changed' => 'Fake changed' );
		}

		public function register(): void {
			++$this->registered;
		}

		public function can_undo( Entry $entry ) {
			return $this->can;
		}

		public function current_state( Entry $entry ) {
			return is_callable( $this->current ) ? call_user_func( $this->current, $entry ) : $this->current;
		}

		public function undo( Entry $entry ) {
			$this->undone[] = $entry->id;
			if ( $this->on_undo ) {
				call_user_func( $this->on_undo, $entry );
			}
			return $this->result;
		}

		public function describe( Entry $entry ): string {
			return 'Fake ' . $entry->object_id;
		}

		public function flush(): void {
			++$this->flushed;
		}
	}
}

namespace AryanX\ActivityLogWithUndo\Tests\Unit {

	/**
	 * A post with every field the recorders read declared, so PHP 8.2+ does not warn.
	 */
	final class Post extends \WP_Post {
		public $post_type    = 'post';
		public $post_status  = 'publish';
		public $post_title   = '';
		public $post_excerpt = '';
		public $post_name    = '';
		public $post_parent  = 0;
		public $post_author  = 0;
		public $menu_order   = 0;
	}
}

namespace {

	if ( ! class_exists( 'WP_List_Table' ) ) {
		class WP_List_Table {
		}
	}

	if ( ! class_exists( 'WP_User' ) ) {
		class WP_User {
			public $ID = 0;
			public $user_login = '';
			public $user_email = '';
			public $user_url = '';
			public $display_name = '';
			public $roles = array();
			public $calls = array();

			public function __construct( $fields = array() ) {
				foreach ( (array) $fields as $key => $value ) {
					$this->$key = $value;
				}
			}

			public function set_role( $role ) {
				$this->calls[] = array( 'set_role', $role );
			}

			public function add_role( $role ) {
				$this->calls[] = array( 'add_role', $role );
			}
		}
	}

	if ( ! class_exists( 'WP_Term' ) ) {
		class WP_Term {
			public $term_id = 0;
			public $name = '';
			public $slug = '';
			public $description = '';
			public $parent = 0;
			public $taxonomy = '';

			public function __construct( $fields = array() ) {
				foreach ( (array) $fields as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}
}
