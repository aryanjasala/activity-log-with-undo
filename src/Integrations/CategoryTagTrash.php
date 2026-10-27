<?php
/**
 * Works with the Category & Tag Trash plugin.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * When Category & Tag Trash is active, deleted terms go to its trash, with
 * their posts, meta, children and menu links. Undoing a term deletion then
 * restores the term from that trash instead of rebuilding it here.
 */
final class CategoryTagTrash {

	private const PLUGIN = 'AryanX\\Axle\\CategoryTagTrash\\Plugin';

	/**
	 * Trash items saved in this request, keyed by term ID.
	 *
	 * @var array<int, int>
	 */
	private array $trashed = array();

	/**
	 * Listens for terms landing in the trash.
	 */
	public function register(): void {
		add_action( 'category_tag_trash_trashed', array( $this, 'trashed' ), 10, 2 );
	}

	/**
	 * Whether Category & Tag Trash is running.
	 *
	 * @return bool
	 */
	public function active(): bool {
		return null !== $this->plugin();
	}

	/**
	 * A deleted term was saved in the trash.
	 *
	 * @param int   $item_id  Trash item ID.
	 * @param mixed $snapshot What was saved (a Category & Tag Trash Snapshot).
	 */
	public function trashed( $item_id, $snapshot ): void {
		if ( is_object( $snapshot ) && isset( $snapshot->term_id ) ) {
			$this->trashed[ (int) $snapshot->term_id ] = (int) $item_id;
		}
	}

	/**
	 * Trash item of a term deleted in this request, or 0.
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	public function item_for( int $term_id ): int {
		return $this->trashed[ $term_id ] ?? 0;
	}

	/**
	 * Restores a term from the trash.
	 *
	 * @param int $item_id Trash item ID.
	 * @return true|\WP_Error
	 */
	public function restore( int $item_id ) {
		$plugin   = $this->plugin();
		$restorer = $plugin && method_exists( $plugin, 'restorer' ) ? $plugin->restorer() : null;
		if ( ! is_object( $restorer ) || ! method_exists( $restorer, 'restore' ) ) {
			return new \WP_Error( 'activity_log_with_undo_ctt_inactive', __( 'This term is in the Category & Tag Trash, which is not active. Activate it to restore the term.', 'activity-log-with-undo' ) );
		}

		$result = $restorer->restore( $item_id, true );

		return $result instanceof \WP_Error ? $result : true;
	}

	/**
	 * Link to the Category & Tag Trash screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=category-tag-trash' );
	}

	/**
	 * The running Category & Tag Trash instance, if any.
	 *
	 * @return object|null
	 */
	private function plugin() {
		if ( ! class_exists( self::PLUGIN ) || ! is_callable( array( self::PLUGIN, 'instance' ) ) ) {
			return null;
		}
		$instance = call_user_func( array( self::PLUGIN, 'instance' ) );

		return is_object( $instance ) ? $instance : null;
	}
}
