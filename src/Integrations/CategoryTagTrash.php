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
	 * Whether a merge is running. Its delete and its moves are logged as one merge, not one by one.
	 *
	 * @var bool
	 */
	private bool $merging = false;

	/**
	 * Whether the hooks are in.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Whether something logs merges, so the delete inside a merge is not logged on its own.
	 *
	 * @var bool
	 */
	private bool $logs_merges = false;

	/**
	 * Page slug of the Category & Tag Trash screen.
	 */
	public const PAGE = 'axle-category-tag-trash';

	/**
	 * Listens for terms landing in the trash.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_action( 'axle_category_tag_trash_trashed', array( $this, 'trashed' ), 10, 2 );
		add_filter( 'activity_log_with_undo_should_log', array( $this, 'quiet_during_merge' ), 10, 2 );
		add_action( 'axle_category_tag_trash_before_merge', array( $this, 'merge_started' ), 10, 0 );
		add_action( 'axle_category_tag_trash_merged', array( $this, 'merge_finished' ), 99, 0 );
	}

	/**
	 * While a merge runs, the posts it moves are not logged one by one: the merge is one entry.
	 *
	 * @param mixed  $log   Whether to log.
	 * @param string $event Event.
	 * @return mixed
	 */
	public function quiet_during_merge( $log, $event = '' ) {
		return $this->merging() && 'post_terms_changed' === $event ? false : $log;
	}

	/**
	 * A merge starts.
	 */
	public function merge_started(): void {
		$this->merging = true;
	}

	/**
	 * A merge ended.
	 */
	public function merge_finished(): void {
		$this->merging = false;
	}

	/**
	 * Whether a merge is running right now.
	 *
	 * @return bool
	 */
	public function merging(): bool {
		return $this->merging && $this->logs_merges;
	}

	/**
	 * Says that merges are logged as merges.
	 */
	public function log_merges(): void {
		$this->logs_merges = true;
	}

	/**
	 * Whether a trash item is still in the trash.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool
	 */
	public function has_item( int $item_id ): bool {
		$plugin = $this->plugin();
		$trash  = $plugin && method_exists( $plugin, 'trash' ) ? $plugin->trash() : null;

		return is_object( $trash ) && method_exists( $trash, 'get' ) && null !== $trash->get( $item_id );
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
	 * Link to the Category & Tag Trash screen, optionally showing one term.
	 *
	 * @param string $search Term name to show.
	 * @return string
	 */
	public static function url( string $search = '' ): string {
		return add_query_arg( array_filter( array( 's' => $search ) ), admin_url( 'tools.php?page=' . self::PAGE ) );
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
