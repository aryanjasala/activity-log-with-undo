<?php
/**
 * Site Editor: global styles, templates, template parts and navigation.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Logs saves in the Site Editor. Undoing an edit restores the previous
 * revision (or the stored content when there is none). Undoing a new
 * customization deletes it, so the theme's own template comes back. Undoing
 * "Clear customizations" puts the customized version back.
 */
final class SiteEditorRecorder extends BaseRecorder {

	public const TYPES = array( 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'site_editor';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Site Editor: styles, templates and navigation', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'site_editor_created' => __( 'Site Editor customization added', 'activity-log-with-undo' ),
			'site_editor_updated' => __( 'Site Editor change saved', 'activity-log-with-undo' ),
			'site_editor_deleted' => __( 'Site Editor customization cleared', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into saves of the Site Editor post types.
	 */
	public function register(): void {
		add_action( 'wp_insert_post', array( $this, 'inserted' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'updated' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'deleting' ), 10, 2 );
	}

	/**
	 * A customization was created.
	 *
	 * Global styles are left out: WordPress creates that post on its own the
	 * first time styles are read.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post (WP_Post).
	 * @param bool  $update  Whether it was an update.
	 */
	public function inserted( $post_id, $post, $update ): void {
		if ( $update || ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::TYPES, true ) || 'wp_global_styles' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->record( 'site_editor_created', $post->post_type, $post_id, self::title( $post ), null, self::fields( $post ), true );
	}

	/**
	 * A customization was saved.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $after   Post after (WP_Post).
	 * @param mixed $before  Post before (WP_Post).
	 */
	public function updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ! in_array( $after->post_type, self::TYPES, true ) ) {
			return;
		}

		$old = self::fields( $before );
		$new = self::fields( $after );
		if ( $old !== $new ) {
			$this->record( 'site_editor_updated', $after->post_type, $post_id, self::title( $after ), $old, $new, true );
		}
	}

	/**
	 * A customization is about to be deleted ("Clear customizations").
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post (WP_Post).
	 */
	public function deleting( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::TYPES, true ) ) {
			return;
		}

		$terms = array();
		foreach ( array( 'wp_theme', 'wp_template_part_area' ) as $taxonomy ) {
			$names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_array( $names ) && $names ) {
				$terms[ $taxonomy ] = $names;
			}
		}

		$snapshot = self::fields( $post ) + array(
			'post_type'    => $post->post_type,
			'post_name'    => $post->post_name,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'terms'        => $terms,
		);

		$this->record( 'site_editor_deleted', $post->post_type, $post->ID, self::title( $post ), $snapshot, null, true );
	}

	/**
	 * Title and content.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function fields( \WP_Post $post ): array {
		return array(
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
		);
	}

	/**
	 * Name of a customization for people.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function title( \WP_Post $post ): string {
		if ( 'wp_global_styles' === $post->post_type ) {
			return __( 'Styles', 'activity-log-with-undo' );
		}

		return '' !== $post->post_title ? $post->post_title : $post->post_name;
	}

	/**
	 * A deleted customization of the same name, if one was saved again since.
	 *
	 * @param array $snapshot Deleted post.
	 * @return \WP_Post|null
	 */
	private static function same_name( array $snapshot ): ?\WP_Post {
		$query = new \WP_Query(
			array(
				'post_type'      => (string) ( $snapshot['post_type'] ?? '' ),
				'name'           => (string) ( $snapshot['post_name'] ?? '' ),
				'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'tax_query'      => isset( $snapshot['terms']['wp_theme'] ) ? array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One indexed lookup when undoing.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'slug',
						'terms'    => (array) $snapshot['terms']['wp_theme'],
					),
				) : array(),
			)
		);

		return $query->posts && $query->posts[0] instanceof \WP_Post ? $query->posts[0] : null;
	}

	/**
	 * Whether the change can be reversed.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( 'site_editor_deleted' !== $entry->event && ! get_post( (int) $entry->object_id ) ) {
			return self::refuse( __( 'The customization no longer exists.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * Current title and content, or for a cleared customization the one saved since, if any.
	 *
	 * @param Entry $entry Entry.
	 * @return array|null
	 */
	public function current_state( Entry $entry ): ?array {
		if ( 'site_editor_deleted' === $entry->event ) {
			$post = self::same_name( (array) $entry->before() );
			return $post ? self::fields( $post ) : null;
		}
		$post = get_post( (int) $entry->object_id );

		return $post ? self::fields( $post ) : null;
	}

	/**
	 * Restores the previous version, deletes a new customization, or brings a cleared one back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		$post_id = (int) $entry->object_id;
		$before  = (array) $entry->before();
		$message = __( 'WordPress did not save the change.', 'activity-log-with-undo' );

		switch ( $entry->event ) {
			case 'site_editor_created':
				return self::result( wp_delete_post( $post_id, true ), $message );

			case 'site_editor_updated':
				$revision = PostsRecorder::matching_revision( $post_id, $before );
				if ( $revision ) {
					return self::result( wp_restore_post_revision( $revision, array_keys( $before ) ), $message );
				}
				return self::result( wp_update_post( wp_slash( array( 'ID' => $post_id ) + $before ), true ), $message );

			case 'site_editor_deleted':
				if ( self::same_name( $before ) ) {
					return self::refuse( __( 'It was customized again since, so the cleared version was not put back.', 'activity-log-with-undo' ) );
				}
				$new_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => (string) $before['post_type'],
							'post_name'    => (string) $before['post_name'],
							'post_title'   => (string) $before['post_title'],
							'post_content' => (string) $before['post_content'],
							'post_excerpt' => (string) $before['post_excerpt'],
							'post_status'  => (string) $before['post_status'],
						)
					),
					true
				);
				if ( is_wp_error( $new_id ) ) {
					return $new_id;
				}
				foreach ( (array) ( $before['terms'] ?? array() ) as $taxonomy => $slugs ) {
					wp_set_object_terms( (int) $new_id, (array) $slugs, (string) $taxonomy );
				}
				return true;
		}

		return self::refuse( __( 'This change cannot be undone.', 'activity-log-with-undo' ) );
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$kinds = array(
			'wp_global_styles' => __( 'global styles', 'activity-log-with-undo' ),
			'wp_template'      => __( 'template', 'activity-log-with-undo' ),
			'wp_template_part' => __( 'template part', 'activity-log-with-undo' ),
			'wp_navigation'    => __( 'navigation menu', 'activity-log-with-undo' ),
		);
		$kind  = $kinds[ $entry->object_type ] ?? $entry->object_type;
		$name  = $entry->object_label;

		if ( 'wp_global_styles' === $entry->object_type ) {
			return 'site_editor_updated' === $entry->event ? __( 'Changed the global styles', 'activity-log-with-undo' ) : __( 'Reset the global styles', 'activity-log-with-undo' );
		}

		switch ( $entry->event ) {
			case 'site_editor_created':
				/* translators: 1: kind, e.g. "template", 2: its name. */
				return sprintf( __( 'Customized the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $name );
			case 'site_editor_deleted':
				/* translators: 1: kind, e.g. "template", 2: its name. */
				return sprintf( __( 'Cleared the customizations of the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $name );
			default:
				/* translators: 1: kind, e.g. "template", 2: its name. */
				return sprintf( __( 'Edited the %1$s "%2$s"', 'activity-log-with-undo' ), $kind, $name );
		}
	}
}
