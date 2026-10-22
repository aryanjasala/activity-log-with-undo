<?php
/**
 * Categories, tags and custom terms.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\ActivityLogWithUndo\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Logs new, edited and deleted terms.
 *
 * Undoing an edit restores the name, slug, description and parent. Undoing a
 * deletion builds the term again with its meta, posts and child terms, when
 * the snapshot fits under the size limit. When Category & Tag Trash is
 * active, the deleted term is restored from its trash instead.
 */
final class TermsRecorder extends BaseRecorder {

	private const FIELDS = array( 'name', 'slug', 'description', 'parent' );

	/**
	 * Category & Tag Trash, when it is active.
	 *
	 * @var CategoryTagTrash
	 */
	private CategoryTagTrash $trash;

	/**
	 * Terms before an edit, keyed by term ID.
	 *
	 * @var array<int, array>
	 */
	private array $editing = array();

	/**
	 * Snapshots of terms being deleted, keyed by term ID.
	 *
	 * @var array<int, array>
	 */
	private array $deleting = array();

	/**
	 * Taxonomies logged, cached for the request.
	 *
	 * @var string[]|null
	 */
	private ?array $taxonomies = null;

	/**
	 * Sets up the recorder.
	 *
	 * @param Logger           $logger Writes entries.
	 * @param CategoryTagTrash $trash  Category & Tag Trash integration.
	 */
	public function __construct( Logger $logger, CategoryTagTrash $trash ) {
		parent::__construct( $logger );
		$this->trash = $trash;
	}

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'terms';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Categories, tags and other terms', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'term_created' => __( 'Term added', 'activity-log-with-undo' ),
			'term_updated' => __( 'Term edited', 'activity-log-with-undo' ),
			'term_deleted' => __( 'Term deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into term changes.
	 */
	public function register(): void {
		$this->trash->register();
		add_action( 'created_term', array( $this, 'created' ), 10, 3 );
		add_action( 'edit_terms', array( $this, 'editing' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'edited' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'before_delete' ), 10, 2 );
		// After Category & Tag Trash (priority 10) has saved the term.
		add_action( 'delete_term', array( $this, 'deleted' ), 20, 3 );
	}

	/**
	 * Whether a taxonomy is logged.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function covers( string $taxonomy ): bool {
		if ( null === $this->taxonomies ) {
			$excluded = array( 'nav_menu', 'wp_theme', 'wp_template_part_area', 'post_format' );
			$all      = array_diff( array_values( get_taxonomies( array( 'show_ui' => true ) ) ), $excluded );

			/**
			 * Filters the taxonomies whose terms are logged.
			 *
			 * Menus have their own recorder and are always left out here.
			 *
			 * @param string[] $taxonomies Default: every taxonomy with an admin screen.
			 */
			$this->taxonomies = array_values( array_diff( array_map( 'strval', (array) apply_filters( 'activity_log_with_undo_taxonomies', array_values( $all ) ) ), array( 'nav_menu' ) ) );
		}

		return in_array( $taxonomy, $this->taxonomies, true );
	}

	/**
	 * Name, slug, description and parent of a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array|null
	 */
	private static function fields( int $term_id, string $taxonomy ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
		);
	}

	/**
	 * A term was added.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function created( $term_id, $tt_id, $taxonomy ): void {
		if ( ! $this->covers( (string) $taxonomy ) ) {
			return;
		}
		$fields = self::fields( (int) $term_id, (string) $taxonomy );
		if ( $fields ) {
			$this->record( 'term_created', (string) $taxonomy, $term_id, $fields['name'], null, $fields, true );
		}
	}

	/**
	 * A term is about to be edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function editing( $term_id, $taxonomy ): void {
		if ( $this->covers( (string) $taxonomy ) ) {
			$this->editing[ (int) $term_id ] = (array) self::fields( (int) $term_id, (string) $taxonomy );
		}
	}

	/**
	 * A term was edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function edited( $term_id, $tt_id, $taxonomy ): void {
		$old = $this->editing[ (int) $term_id ] ?? null;
		unset( $this->editing[ (int) $term_id ] );
		$new = self::fields( (int) $term_id, (string) $taxonomy );
		if ( ! $old || ! $new ) {
			return;
		}

		$before = array();
		$after  = array();
		foreach ( self::FIELDS as $field ) {
			if ( (string) ( $old[ $field ] ?? '' ) !== (string) $new[ $field ] ) {
				$before[ $field ] = $old[ $field ] ?? '';
				$after[ $field ]  = $new[ $field ];
			}
		}

		if ( $after ) {
			$this->record( 'term_updated', (string) $taxonomy, $term_id, $new['name'], $before, $after, true );
		}
	}

	/**
	 * Captures a term before it is deleted: fields, meta, posts and children.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function before_delete( $term_id, $taxonomy ): void {
		$taxonomy = (string) $taxonomy;
		$term_id  = (int) $term_id;
		$fields   = $this->covers( $taxonomy ) ? self::fields( $term_id, $taxonomy ) : null;
		if ( ! $fields ) {
			return;
		}

		$objects = get_objects_in_term( $term_id, $taxonomy );
		$objects = is_array( $objects ) ? array_map( 'intval', $objects ) : array();

		/**
		 * Filters the most posts a deleted term may have and still be rebuilt by Undo.
		 *
		 * Terms with more are logged, but cannot be undone.
		 *
		 * @param int $limit Default 5000.
		 */
		$limit = max( 0, (int) apply_filters( 'activity_log_with_undo_term_object_limit', 5000 ) );
		if ( count( $objects ) > $limit ) {
			$this->deleting[ $term_id ] = $fields + array( 'objects_count' => count( $objects ) );
			return;
		}

		$this->deleting[ $term_id ] = $fields + array(
			'meta'     => self::meta( $term_id ),
			'objects'  => $objects,
			'sole'     => self::sole( $objects, $term_id, $taxonomy ),
			'children' => array_map( 'intval', get_terms( array( 'taxonomy' => $taxonomy, 'parent' => $term_id, 'fields' => 'ids', 'hide_empty' => false ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
		);
	}

	/**
	 * All meta of a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, array>
	 */
	private static function meta( int $term_id ): array {
		$meta = array();
		foreach ( (array) get_term_meta( $term_id ) as $key => $values ) {
			$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		return $meta;
	}

	/**
	 * Objects that have no other term in the taxonomy, so WordPress gives them the default term.
	 *
	 * @param int[]  $objects  Object IDs.
	 * @param int    $term_id  Term being deleted.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function sole( array $objects, int $term_id, string $taxonomy ): array {
		if ( ! $objects || ! self::default_term( $taxonomy ) ) {
			return array();
		}

		$counts = array();
		$terms  = wp_get_object_terms( $objects, $taxonomy, array( 'fields' => 'all_with_object_id' ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			// all_with_object_id adds object_id to each term.
			$row = get_object_vars( $term );
			if ( isset( $row['object_id'], $row['term_id'] ) && (int) $row['term_id'] !== $term_id ) {
				$counts[ (int) $row['object_id'] ] = true;
			}
		}

		return array_values(
			array_filter(
				$objects,
				static function ( $id ) use ( $counts ) {
					return ! isset( $counts[ $id ] );
				}
			)
		);
	}

	/**
	 * The term WordPress gives posts left without one, or 0.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private static function default_term( string $taxonomy ): int {
		if ( 'category' === $taxonomy ) {
			return (int) get_option( 'default_category' );
		}
		$object = get_taxonomy( $taxonomy );

		return $object && ! empty( $object->default_term ) ? (int) get_option( 'default_term_' . $taxonomy ) : 0;
	}

	/**
	 * A term was deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function deleted( $term_id, $tt_id, $taxonomy ): void {
		$snapshot = $this->deleting[ (int) $term_id ] ?? null;
		unset( $this->deleting[ (int) $term_id ] );
		if ( ! $snapshot ) {
			return;
		}

		$item = $this->trash->item_for( (int) $term_id );
		if ( $item ) {
			// Category & Tag Trash keeps everything; the log only needs to know where.
			$snapshot = array_intersect_key( $snapshot, array_flip( self::FIELDS ) ) + array( 'ctt_item' => $item );
		}

		$this->record( 'term_deleted', (string) $taxonomy, $term_id, $snapshot['name'], $snapshot, null, $item || isset( $snapshot['objects'] ) );
	}

	/**
	 * Whether the change can be reversed.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( ! taxonomy_exists( $entry->object_type ) ) {
			/* translators: %s: taxonomy name. */
			return self::refuse( sprintf( __( 'The "%s" taxonomy is not registered. Activate the plugin or theme that adds it, then try again.', 'activity-log-with-undo' ), $entry->object_type ) );
		}
		if ( 'term_deleted' === $entry->event && ! $entry->undoable && isset( ( (array) $entry->before() )['objects_count'] ) ) {
			return self::refuse( __( 'The term had too many posts to keep a copy of, so it cannot be rebuilt.', 'activity-log-with-undo' ) );
		}
		if ( 'term_deleted' !== $entry->event && ! get_term( (int) $entry->object_id, $entry->object_type ) instanceof \WP_Term ) {
			return self::refuse( __( 'The term no longer exists.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The term's current fields. For a deleted term, a term with the same slug if there is one.
	 *
	 * @param Entry $entry Entry.
	 * @return array|null
	 */
	public function current_state( Entry $entry ): ?array {
		if ( 'term_deleted' === $entry->event ) {
			$term = get_term_by( 'slug', (string) ( ( (array) $entry->before() )['slug'] ?? '' ), $entry->object_type );
			return $term instanceof \WP_Term ? self::fields( $term->term_id, $entry->object_type ) : null;
		}

		$fields = self::fields( (int) $entry->object_id, $entry->object_type );
		if ( ! $fields || 'term_created' === $entry->event ) {
			return $fields;
		}

		return array_intersect_key( $fields, (array) $entry->after() );
	}

	/**
	 * Deletes a new term, restores edited fields, or brings a deleted term back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$taxonomy = $entry->object_type;
		$before   = (array) $entry->before();

		switch ( $entry->event ) {
			case 'term_created':
				return self::result( wp_delete_term( (int) $entry->object_id, $taxonomy ), __( 'WordPress did not delete the term.', 'activity-log-with-undo' ) );

			case 'term_updated':
				$result = wp_update_term( (int) $entry->object_id, $taxonomy, wp_slash( $before ) );
				return is_wp_error( $result ) ? $result : true;

			case 'term_deleted':
				return ! empty( $before['ctt_item'] ) ? $this->trash->restore( (int) $before['ctt_item'] ) : self::rebuild( $taxonomy, $before );
		}

		return self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * Builds a deleted term again from its snapshot.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $snapshot Fields, meta, objects, sole and children.
	 * @return true|\WP_Error
	 */
	private static function rebuild( string $taxonomy, array $snapshot ) {
		$parent = (int) ( $snapshot['parent'] ?? 0 );
		$result = wp_insert_term(
			wp_slash( (string) $snapshot['name'] ),
			$taxonomy,
			wp_slash(
				array(
					'slug'        => (string) ( $snapshot['slug'] ?? '' ),
					'description' => (string) ( $snapshot['description'] ?? '' ),
					'parent'      => $parent && term_exists( $parent, $taxonomy ) ? $parent : 0,
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term_id = (int) $result['term_id'];

		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_term_meta( $term_id, (string) $key, wp_slash( $value ) );
			}
		}

		$default = self::default_term( $taxonomy );
		foreach ( array_map( 'intval', (array) ( $snapshot['objects'] ?? array() ) ) as $object_id ) {
			wp_add_object_terms( $object_id, $term_id, $taxonomy );
		}
		if ( $default && $default !== $term_id ) {
			foreach ( array_map( 'intval', (array) ( $snapshot['sole'] ?? array() ) ) as $object_id ) {
				wp_remove_object_terms( $object_id, $default, $taxonomy );
			}
		}

		foreach ( array_map( 'intval', (array) ( $snapshot['children'] ?? array() ) ) as $child ) {
			if ( get_term( $child, $taxonomy ) instanceof \WP_Term ) {
				wp_update_term( $child, $taxonomy, array( 'parent' => $term_id ) );
			}
		}

		return true;
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$taxonomy = get_taxonomy( $entry->object_type );
		$kind     = $taxonomy ? (string) $taxonomy->labels->singular_name : $entry->object_type;
		$name     = '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;

		switch ( $entry->event ) {
			case 'term_created':
				/* translators: 1: taxonomy, e.g. "Category", 2: term name. */
				return sprintf( __( 'Added the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $name );
			case 'term_updated':
				$names  = array(
					'name'        => __( 'name', 'activity-log-with-undo' ),
					'slug'        => __( 'slug', 'activity-log-with-undo' ),
					'description' => __( 'description', 'activity-log-with-undo' ),
					'parent'      => __( 'parent', 'activity-log-with-undo' ),
				);
				$fields = array_map(
					static function ( $field ) use ( $names ) {
						return $names[ $field ] ?? $field;
					},
					array_keys( (array) $entry->after() )
				);
				/* translators: 1: changed fields, e.g. "name, slug", 2: taxonomy, 3: term name. */
				return sprintf( __( 'Edited the %1$s of the %2$s "%3$s"', 'activity-log-with-undo' ), implode( ', ', $fields ), $kind, $name );
			default:
				$in_trash = ! empty( ( (array) $entry->before() )['ctt_item'] ) ? ' ' . __( '(kept in Category & Tag Trash)', 'activity-log-with-undo' ) : '';
				/* translators: 1: taxonomy, e.g. "Category", 2: term name. */
				return sprintf( __( 'Deleted the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $name ) . $in_trash;
		}
	}
}
