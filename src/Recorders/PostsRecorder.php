<?php
/**
 * Posts, pages and other content types.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Logs status changes (publish, draft, trash, restore), edits to the title,
 * content and excerpt, and changes to the slug, parent, author, featured
 * image and template.
 *
 * Content edits are undone by restoring the matching revision when the post
 * has one, so revisioned meta such as footnotes comes back too, and from the
 * stored fields otherwise. Permanent deletions are logged only.
 */
final class PostsRecorder extends BaseRecorder {

	/**
	 * Post types covered by other recorders, or not content at all.
	 */
	public const EXCLUDED = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * Fields of a content edit.
	 */
	private const CONTENT = array( 'post_title', 'post_content', 'post_excerpt' );

	/**
	 * Fields of an attribute change.
	 */
	private const ATTRIBUTES = array( 'post_name', 'post_parent', 'post_author', 'post_password' );

	/**
	 * Events the editor panel lists: changes that revisions do not keep.
	 */
	public const PANEL_EVENTS = array( 'post_status_changed', 'post_trashed', 'post_untrashed', 'post_attributes_changed', 'post_featured_image_changed', 'post_template_changed', 'post_terms_changed', 'post_sticky_changed' );

	/**
	 * Meta keys logged, with their event.
	 */
	private const META = array(
		'_thumbnail_id'     => 'post_featured_image_changed',
		'_wp_page_template' => 'post_template_changed',
	);

	/**
	 * Meta values before a change, keyed by post ID and meta key.
	 *
	 * @var array<string, string>
	 */
	private array $meta_before = array();

	/**
	 * Whether a new post is being inserted: its first terms (the default category, tax_input) belong to its creation.
	 *
	 * @var bool
	 */
	private bool $inserting = false;

	/**
	 * Whether a term is being deleted: posts that lose it, or fall back to the default term, are part of that deletion.
	 *
	 * @var bool
	 */
	private bool $deleting_term = false;

	/**
	 * Post types logged, cached for the request.
	 *
	 * @var string[]|null
	 */
	private ?array $types = null;

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'posts';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Posts, pages and other content', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'post_created'                => __( 'Content created', 'activity-log-with-undo' ),
			'post_status_changed'         => __( 'Status changed', 'activity-log-with-undo' ),
			'post_trashed'                => __( 'Moved to the trash', 'activity-log-with-undo' ),
			'post_untrashed'              => __( 'Restored from the trash', 'activity-log-with-undo' ),
			'post_updated'                => __( 'Content edited', 'activity-log-with-undo' ),
			'post_attributes_changed'     => __( 'Slug, parent or author changed', 'activity-log-with-undo' ),
			'post_featured_image_changed' => __( 'Featured image changed', 'activity-log-with-undo' ),
			'post_template_changed'       => __( 'Template changed', 'activity-log-with-undo' ),
			'post_terms_changed'          => __( 'Categories, tags or other terms changed', 'activity-log-with-undo' ),
			'post_sticky_changed'         => __( 'Stick to the top changed', 'activity-log-with-undo' ),
			'post_deleted'                => __( 'Deleted permanently', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into post changes.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'updated' ), 10, 3 );
		add_action( 'update_post_meta', array( $this, 'before_meta' ), 10, 3 );
		add_action( 'delete_post_meta', array( $this, 'before_meta_delete' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'meta_added' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_deleted' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'deleting' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'insert_starts' ), 10, 4 );
		add_action( 'wp_insert_post', array( $this, 'insert_ends' ), 10, 0 );
		add_action( 'pre_delete_term', array( $this, 'term_delete_starts' ), 5, 0 );
		add_action( 'deleted_term', array( $this, 'term_delete_ends' ), 10, 0 );
		add_action( 'set_object_terms', array( $this, 'terms_set' ), 10, 6 );
		add_action( 'post_stuck', array( $this, 'stuck' ), 10, 1 );
		add_action( 'post_unstuck', array( $this, 'unstuck' ), 10, 1 );
	}

	/**
	 * A term is about to be deleted.
	 */
	public function term_delete_starts(): void {
		$this->deleting_term = true;
	}

	/**
	 * A term was deleted.
	 */
	public function term_delete_ends(): void {
		$this->deleting_term = false;
	}

	/**
	 * A post is about to be written. Remembers when it is a new one.
	 *
	 * @param mixed $data      Post data; returned untouched.
	 * @param mixed $postarr   What was passed to wp_insert_post().
	 * @param mixed $sanitized Unsanitized post data.
	 * @param mixed $update    Whether this updates a post.
	 * @return mixed
	 */
	public function insert_starts( $data, $postarr = array(), $sanitized = array(), $update = false ) {
		$this->inserting = ! $update;

		return $data;
	}

	/**
	 * A post was written.
	 */
	public function insert_ends(): void {
		$this->inserting = false;
	}

	/**
	 * A post's terms were replaced.
	 *
	 * Adding terms to the existing ones (append) is left out: that is how
	 * Category & Tag Trash re-attaches posts, and it logs those itself.
	 *
	 * @param int    $object_id Post ID.
	 * @param mixed  $terms     Terms given.
	 * @param array  $tt_ids    New term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 * @param bool   $append    Whether the terms were added to the old ones.
	 * @param array  $old_tt    Old term taxonomy IDs.
	 */
	public function terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt = array() ): void {
		$post = get_post( (int) $object_id );
		// Only posts: a taxonomy can also hold users, links or other things whose IDs look like a post's.
		if ( $append || $this->inserting || $this->deleting_term || ! $this->watching( $post ) || 'auto-draft' === $post->post_status || ! is_object_in_taxonomy( $post->post_type, (string) $taxonomy ) ) {
			return;
		}

		$old = self::term_ids( (array) $old_tt, (string) $taxonomy );
		$new = self::term_ids( (array) $tt_ids, (string) $taxonomy );
		if ( $old === $new ) {
			return;
		}

		$this->record(
			'post_terms_changed',
			$post->post_type,
			$post->ID,
			$post->post_title,
			array(
				'taxonomy' => (string) $taxonomy,
				'terms'    => $old,
			),
			array(
				'taxonomy' => (string) $taxonomy,
				'terms'    => $new,
			),
			true
		);
	}

	/**
	 * Term IDs, sorted, of term taxonomy IDs.
	 *
	 * @param array  $tt_ids   Term taxonomy IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function term_ids( array $tt_ids, string $taxonomy ): array {
		$ids = array();
		foreach ( array_unique( array_map( 'intval', $tt_ids ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * A post was made sticky.
	 *
	 * @param int $post_id Post ID.
	 */
	public function stuck( $post_id ): void {
		$this->sticky_logged( (int) $post_id, true );
	}

	/**
	 * A post is not sticky any more.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unstuck( $post_id ): void {
		$this->sticky_logged( (int) $post_id, false );
	}

	/**
	 * Logs a change to the sticky list.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $sticky  Whether the post is sticky now.
	 */
	private function sticky_logged( int $post_id, bool $sticky ): void {
		$post = get_post( $post_id );
		if ( $this->watching( $post ) && 'auto-draft' !== $post->post_status ) {
			$this->record( 'post_sticky_changed', $post->post_type, $post->ID, $post->post_title, array( 'sticky' => ! $sticky ), array( 'sticky' => $sticky ), true );
		}
	}

	/**
	 * Whether a post type is logged.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public function covers( string $type ): bool {
		if ( null === $this->types ) {
			$types = array_diff( array_values( get_post_types( array( 'show_ui' => true ) ) ), self::EXCLUDED );

			/**
			 * Filters the post types whose changes are logged.
			 *
			 * Templates, template parts, global styles and navigation have their
			 * own recorder, and menu items are logged as part of their menu, so
			 * they are always left out here.
			 *
			 * @param string[] $types Default: every post type with an admin screen.
			 */
			$types       = (array) apply_filters( 'activity_log_with_undo_post_types', array_values( $types ) );
			$this->types = array_values( array_diff( array_map( 'strval', $types ), self::EXCLUDED ) );
		}

		return in_array( $type, $this->types, true );
	}

	/**
	 * Whether a post should be logged at all right now.
	 *
	 * @param mixed $post Post (WP_Post).
	 * @return bool
	 */
	private function watching( $post ): bool {
		return $post instanceof \WP_Post && $this->covers( $post->post_type ) && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
	}

	/**
	 * A post changed status.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       Post (WP_Post).
	 */
	public function status_changed( $new_status, $old_status, $post ): void {
		$skip = array( 'auto-draft', 'inherit' );
		if ( $new_status === $old_status || in_array( $new_status, $skip, true ) || 'inherit' === $old_status || ! $this->watching( $post ) ) {
			return;
		}

		if ( 'trash' === $new_status ) {
			$event = 'post_trashed';
		} elseif ( 'trash' === $old_status ) {
			$event = 'post_untrashed';
		} elseif ( 'new' === $old_status || 'auto-draft' === $old_status ) {
			$event = 'post_created';
		} else {
			$event = 'post_status_changed';
		}

		$this->record( $event, $post->post_type, $post->ID, $post->post_title, array( 'post_status' => (string) $old_status ), array( 'post_status' => (string) $new_status ), true );
	}

	/**
	 * A post was saved: logs content edits and attribute changes.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $after   Post after the save (WP_Post).
	 * @param mixed $before  Post before the save (WP_Post).
	 */
	public function updated( $post_id, $after, $before ): void {
		if ( ! $this->watching( $after ) || ! $before instanceof \WP_Post || in_array( $before->post_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		list( $old, $new ) = self::changed( $before, $after, self::CONTENT );
		if ( $new ) {
			$this->record( 'post_updated', $after->post_type, $post_id, $after->post_title, $old, $new, true );
		}

		$fields = self::ATTRIBUTES;
		// Trashing adds "__trashed" to the slug and restoring takes it off, and a first publish fills an empty slug.
		if ( 'trash' === $before->post_status || 'trash' === $after->post_status || '' === $before->post_name ) {
			$fields = array_diff( $fields, array( 'post_name' ) );
		}
		list( $old, $new ) = self::changed( $before, $after, $fields );
		if ( $new ) {
			$this->record( 'post_attributes_changed', $after->post_type, $post_id, $after->post_title, $old, $new, true );
		}
	}

	/**
	 * Fields that differ between two versions of a post.
	 *
	 * @param \WP_Post $before Before.
	 * @param \WP_Post $after  After.
	 * @param string[] $fields Fields to compare.
	 * @return array{0: array, 1: array}
	 */
	private static function changed( \WP_Post $before, \WP_Post $after, array $fields ): array {
		$old = array();
		$new = array();
		foreach ( $fields as $field ) {
			if ( (string) $before->$field !== (string) $after->$field ) {
				$old[ $field ] = $before->$field;
				$new[ $field ] = $after->$field;
			}
		}

		return array( $old, $new );
	}

	/**
	 * Keeps a meta value before it changes.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	public function before_meta( $meta_id, $post_id, $meta_key ): void {
		if ( isset( self::META[ $meta_key ] ) ) {
			$this->meta_before[ $post_id . '|' . $meta_key ] = (string) get_post_meta( (int) $post_id, (string) $meta_key, true );
		}
	}

	/**
	 * Keeps a meta value before it is deleted.
	 *
	 * @param string[] $meta_ids Meta IDs.
	 * @param int      $post_id  Post ID.
	 * @param string   $meta_key Meta key.
	 */
	public function before_meta_delete( $meta_ids, $post_id, $meta_key ): void {
		$this->before_meta( 0, $post_id, $meta_key );
	}

	/**
	 * A meta value was added.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value.
	 */
	public function meta_added( $meta_id, $post_id, $meta_key, $value ): void {
		$this->meta_logged( (int) $post_id, (string) $meta_key, '', (string) maybe_serialize( $value ) );
	}

	/**
	 * A meta value changed.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value.
	 */
	public function meta_changed( $meta_id, $post_id, $meta_key, $value ): void {
		$this->meta_logged( (int) $post_id, (string) $meta_key, $this->meta_before[ $post_id . '|' . $meta_key ] ?? '', (string) maybe_serialize( $value ) );
	}

	/**
	 * A meta value was deleted.
	 *
	 * @param string[] $meta_ids Meta IDs.
	 * @param int      $post_id  Post ID.
	 * @param string   $meta_key Meta key.
	 */
	public function meta_deleted( $meta_ids, $post_id, $meta_key ): void {
		$this->meta_logged( (int) $post_id, (string) $meta_key, $this->meta_before[ $post_id . '|' . $meta_key ] ?? '', '' );
	}

	/**
	 * Logs a featured image or template change.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $old     Old value.
	 * @param string $value   New value.
	 */
	private function meta_logged( int $post_id, string $key, string $old, string $value ): void {
		if ( ! isset( self::META[ $key ] ) ) {
			return;
		}
		unset( $this->meta_before[ $post_id . '|' . $key ] );

		$post = get_post( $post_id );
		if ( $old === $value || ! $this->watching( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->record( self::META[ $key ], $post->post_type, $post_id, $post->post_title, array( $key => $old ), array( $key => $value ), true );
	}

	/**
	 * A post is about to be deleted for good.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post (WP_Post).
	 */
	public function deleting( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $this->watching( $post ) && ! in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			$this->record( 'post_deleted', $post->post_type, $post->ID, $post->post_title, array( 'post_status' => $post->post_status ), null, false );
		}
	}

	/**
	 * Whether the change can be reversed.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( 'post_deleted' === $entry->event ) {
			return self::refuse( __( 'The post was deleted permanently, with its revisions and settings. Only posts moved to the trash can be brought back.', 'activity-log-with-undo' ) );
		}
		if ( ! get_post( (int) $entry->object_id ) ) {
			return self::refuse( __( 'The post no longer exists.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * Current values of the fields the entry changed.
	 *
	 * @param Entry $entry Entry.
	 * @return array|null
	 */
	public function current_state( Entry $entry ): ?array {
		$post = get_post( (int) $entry->object_id );
		if ( ! $post ) {
			return null;
		}
		if ( 'post_sticky_changed' === $entry->event ) {
			return array( 'sticky' => is_sticky( $post->ID ) );
		}
		if ( 'post_terms_changed' === $entry->event ) {
			$taxonomy = (string) ( ( (array) $entry->after() )['taxonomy'] ?? '' );
			$ids      = taxonomy_exists( $taxonomy ) ? wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) ) : array();
			$ids      = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );

			return array(
				'taxonomy' => $taxonomy,
				'terms'    => $ids,
			);
		}

		$state = array();
		foreach ( array_keys( (array) $entry->after() ) as $field ) {
			$state[ $field ] = 0 === strpos( (string) $field, '_' ) ? (string) get_post_meta( $post->ID, (string) $field, true ) : $post->$field;
		}

		return $state;
	}

	/**
	 * Puts the post back the way it was.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$post_id = (int) $entry->object_id;
		$before  = (array) $entry->before();
		$message = __( 'WordPress did not save the post.', 'activity-log-with-undo' );

		switch ( $entry->event ) {
			case 'post_created':
			case 'post_untrashed':
				return self::result( wp_trash_post( $post_id ), $message );

			case 'post_trashed':
				$status = static function () use ( $before ) {
					return (string) $before['post_status'];
				};
				add_filter( 'wp_untrash_post_status', $status );
				$result = wp_untrash_post( $post_id );
				remove_filter( 'wp_untrash_post_status', $status );
				return self::result( $result, $message );

			case 'post_status_changed':
				return self::result(
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => (string) $before['post_status'],
						),
						true
					),
					$message
				);

			case 'post_updated':
				$revision = self::matching_revision( $post_id, $before );
				if ( $revision ) {
					return self::result( wp_restore_post_revision( $revision, array_keys( $before ) ), $message );
				}
				return self::result( wp_update_post( wp_slash( array( 'ID' => $post_id ) + $before ), true ), $message );

			case 'post_attributes_changed':
				return self::result( wp_update_post( wp_slash( array( 'ID' => $post_id ) + $before ), true ), $message );

			case 'post_sticky_changed':
				if ( ! empty( $before['sticky'] ) ) {
					stick_post( $post_id );
				} else {
					unstick_post( $post_id );
				}
				return true;

			case 'post_terms_changed':
				$taxonomy = (string) ( $before['taxonomy'] ?? '' );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					return self::refuse( __( 'The taxonomy is not registered any more.', 'activity-log-with-undo' ) );
				}
				// Terms deleted since are skipped; the rest go back.
				$ids = array_values(
					array_filter(
						array_map( 'intval', (array) ( $before['terms'] ?? array() ) ),
						static function ( $id ) use ( $taxonomy ) {
							return get_term( $id, $taxonomy ) instanceof \WP_Term;
						}
					)
				);
				return self::result( wp_set_object_terms( $post_id, $ids, $taxonomy, false ), __( 'WordPress did not save the terms.', 'activity-log-with-undo' ) );

			case 'post_featured_image_changed':
			case 'post_template_changed':
				foreach ( $before as $key => $value ) {
					if ( '' === (string) $value ) {
						delete_post_meta( $post_id, (string) $key );
					} else {
						update_post_meta( $post_id, (string) $key, $value );
					}
				}
				return true;
		}

		return self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * Newest revision whose fields equal the given ones.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Field values to look for.
	 * @return int Revision ID or 0.
	 */
	public static function matching_revision( int $post_id, array $fields ): int {
		if ( ! wp_revisions_enabled( get_post( $post_id ) ) ) {
			return 0;
		}

		/**
		 * Filters how many revisions are searched for the one to restore.
		 *
		 * @param int $limit Default 50.
		 */
		$limit = max( 1, min( 500, (int) apply_filters( 'activity_log_with_undo_revision_search_limit', 50 ) ) );

		foreach ( wp_get_post_revisions( $post_id, array( 'posts_per_page' => $limit ) ) as $revision ) {
			if ( wp_is_post_autosave( $revision ) ) {
				continue;
			}
			$same = true;
			foreach ( $fields as $field => $value ) {
				if ( ! Payload::same( $revision->$field ?? null, $value ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return (int) $revision->ID;
			}
		}

		return 0;
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$type   = get_post_type_object( $entry->object_type );
		$kind   = $type ? (string) $type->labels->singular_name : $entry->object_type;
		$title  = '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;
		$names  = array(
			'post_title'    => __( 'title', 'activity-log-with-undo' ),
			'post_content'  => __( 'content', 'activity-log-with-undo' ),
			'post_excerpt'  => __( 'excerpt', 'activity-log-with-undo' ),
			'post_name'     => __( 'slug', 'activity-log-with-undo' ),
			'post_parent'   => __( 'parent', 'activity-log-with-undo' ),
			'post_author'   => __( 'author', 'activity-log-with-undo' ),
			'post_password' => __( 'password', 'activity-log-with-undo' ),
		);
		$fields = implode(
			', ',
			array_map(
				static function ( $field ) use ( $names ) {
					return $names[ $field ] ?? $field;
				},
				array_keys( (array) $entry->after() )
			)
		);

		switch ( $entry->event ) {
			case 'post_created':
				/* translators: 1: content type, e.g. "Page", 2: title, 3: status. */
				return sprintf( __( 'Created the %1$s "%2$s" as %3$s', 'activity-log-with-undo' ), $kind, $title, self::status_name( (string) ( ( (array) $entry->after() )['post_status'] ?? '' ) ) );
			case 'post_trashed':
				/* translators: 1: content type, e.g. "Page", 2: title. */
				return sprintf( __( 'Moved the %1$s "%2$s" to the trash', 'activity-log-with-undo' ), $kind, $title );
			case 'post_untrashed':
				/* translators: 1: content type, e.g. "Page", 2: title. */
				return sprintf( __( 'Restored the %1$s "%2$s" from the trash', 'activity-log-with-undo' ), $kind, $title );
			case 'post_status_changed':
				/* translators: 1: content type, e.g. "Page", 2: title, 3: old status, 4: new status. */
				return sprintf( __( 'Changed the %1$s "%2$s" from %3$s to %4$s', 'activity-log-with-undo' ), $kind, $title, self::status_name( (string) ( ( (array) $entry->before() )['post_status'] ?? '' ) ), self::status_name( (string) ( ( (array) $entry->after() )['post_status'] ?? '' ) ) );
			case 'post_updated':
			case 'post_attributes_changed':
				/* translators: 1: changed fields, e.g. "title, content", 2: content type, 3: title. */
				return sprintf( __( 'Edited the %1$s of the %2$s "%3$s"', 'activity-log-with-undo' ), $fields, $kind, $title );
			case 'post_featured_image_changed':
				/* translators: 1: content type, e.g. "Page", 2: title. */
				return sprintf( __( 'Changed the featured image of the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $title );
			case 'post_template_changed':
				/* translators: 1: content type, e.g. "Page", 2: title. */
				return sprintf( __( 'Changed the template of the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $title );
			case 'post_sticky_changed':
				if ( ! empty( ( (array) $entry->after() )['sticky'] ) ) {
					/* translators: 1: content type, e.g. "Post", 2: title. */
					return sprintf( __( 'Stuck the %1$s "%2$s" to the top of the blog', 'activity-log-with-undo' ), $kind, $title );
				}
				/* translators: 1: content type, e.g. "Post", 2: title. */
				return sprintf( __( 'Unstuck the %1$s "%2$s" from the top of the blog', 'activity-log-with-undo' ), $kind, $title );
			case 'post_terms_changed':
				return $this->describe_terms( $entry, $kind, $title );
			default:
				/* translators: 1: content type, e.g. "Page", 2: title. */
				return sprintf( __( 'Deleted the %1$s "%2$s" permanently', 'activity-log-with-undo' ), $kind, $title );
		}
	}

	/**
	 * One line about a terms change: which were added and which removed.
	 *
	 * @param Entry  $entry Entry.
	 * @param string $kind  Content type name.
	 * @param string $title Post title.
	 * @return string
	 */
	private function describe_terms( Entry $entry, string $kind, string $title ): string {
		$before   = (array) $entry->before();
		$after    = (array) $entry->after();
		$taxonomy = (string) ( $after['taxonomy'] ?? '' );
		$object   = get_taxonomy( $taxonomy );
		$label    = $object ? strtolower( (string) $object->labels->name ) : $taxonomy;
		$names    = static function ( array $ids ) use ( $taxonomy ): string {
			return implode(
				', ',
				array_map(
					static function ( $id ) use ( $taxonomy ) {
						$term = get_term( (int) $id, $taxonomy );
						return $term instanceof \WP_Term ? $term->name : '#' . (int) $id;
					},
					$ids
				)
			);
		};
		$added    = array_values( array_diff( array_map( 'intval', (array) ( $after['terms'] ?? array() ) ), array_map( 'intval', (array) ( $before['terms'] ?? array() ) ) ) );
		$removed  = array_values( array_diff( array_map( 'intval', (array) ( $before['terms'] ?? array() ) ), array_map( 'intval', (array) ( $after['terms'] ?? array() ) ) ) );
		$parts    = array();
		if ( $added ) {
			/* translators: %s: term names. */
			$parts[] = sprintf( __( 'added %s', 'activity-log-with-undo' ), $names( $added ) );
		}
		if ( $removed ) {
			/* translators: %s: term names. */
			$parts[] = sprintf( __( 'removed %s', 'activity-log-with-undo' ), $names( $removed ) );
		}

		/* translators: 1: taxonomy name, e.g. "categories", 2: content type, 3: title, 4: what changed, e.g. "added News; removed Sport". */
		return sprintf( __( 'Changed the %1$s of the %2$s "%3$s": %4$s', 'activity-log-with-undo' ), $label, $kind, $title, implode( '; ', $parts ) );
	}

	/**
	 * Name of a post status for people.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_name( string $status ): string {
		$object = get_post_status_object( $status );

		return $object ? (string) $object->label : $status;
	}
}
