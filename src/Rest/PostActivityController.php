<?php
/**
 * The activity of one post, for the editor.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Rest;

use AryanX\Axle\ActivityLogWithUndo\Access;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\PostsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Store;
use AryanX\Axle\ActivityLogWithUndo\Undoer;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the changes revisions do not keep (status, slug, author, template,
 * featured image, terms, sticky, password) for one post, and undoes one of them.
 *
 * Both routes need the right to edit that post, not only the right to open the
 * log. Entries a person may not see are masked, and an undo narrows to the one
 * change picked, so the rest of its request is left alone.
 */
final class PostActivityController implements Service {

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Recorders, for descriptions.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Sets up the routes.
	 *
	 * @param Store    $store    Entries.
	 * @param Registry $registry Recorders.
	 * @param Undoer   $undoer   Reverses changes.
	 */
	public function __construct( Store $store, Registry $registry, Undoer $undoer ) {
		$this->store    = $store;
		$this->registry = $registry;
		$this->undoer   = $undoer;
	}

	/**
	 * Registers the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Adds the routes.
	 */
	public function routes(): void {
		register_rest_route(
			UndoController::NAMESPACE,
			'/posts/(?P<id>\d+)/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			UndoController::NAMESPACE,
			'/posts/(?P<id>\d+)/activity/(?P<entry>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'allowed' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'entry' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Whether the user may edit the post, and it is a post the log covers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function allowed( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'activity_log_with_undo_forbidden', __( 'Sorry, you are not allowed to see the activity of this post.', 'activity-log-with-undo' ), array( 'status' => rest_authorization_required_code() ) );
		}

		/**
		 * Filters whether a user may see and undo the activity of a post in the editor.
		 *
		 * The default is the right to edit that post. Each entry is still checked
		 * with `activity_log_with_undo_can_view_entry`.
		 *
		 * @param bool     $allowed Default true for people who may edit the post.
		 * @param \WP_Post $post    The post.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_post_panel_allowed', true, $post );
	}

	/**
	 * How many entries the panel lists.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many changes the editor panel lists for one post.
		 *
		 * @param int $limit Default 20, at most 100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'activity_log_with_undo_post_panel_limit', 20 ) ) );
	}

	/**
	 * Lists the changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list( $request ) {
		$post  = get_post( (int) $request->get_param( 'id' ) );
		$found = $this->store->find(
			array(
				'object_type' => $post ? $post->post_type : '',
				'object_id'   => (string) (int) $request->get_param( 'id' ),
				'event'       => PostsRecorder::PANEL_EVENTS,
				'per_page'    => self::limit(),
			)
		);

		$items = array();
		foreach ( $found['entries'] as $entry ) {
			$items[] = $this->item( $entry );
		}

		return new \WP_REST_Response(
			array(
				'entries' => $items,
				'total'   => $found['total'],
			)
		);
	}

	/**
	 * One entry for the panel, masked when the user may not see it.
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	private function item( Entry $entry ): array {
		$visible = Access::can_view( $entry );
		$can     = $visible && ! $entry->is_undone() && $entry->undoable ? $this->undoer->can_undo( $entry ) : false;
		$events  = $this->registry->events();

		return array(
			'id'          => $entry->id,
			'event'       => $entry->event,
			'kind'        => $visible ? (string) ( $events[ $entry->event ] ?? $entry->event ) : '',
			'description' => $this->registry->describe( $entry ),
			'who'         => $visible ? $entry->actor_name() : '',
			'when'        => mysql2date( 'c', $entry->created_gmt, false ),
			'timestamp'   => $entry->timestamp(),
			'undone'      => $entry->is_undone(),
			'can_undo'    => true === $can,
			'reason'      => $can instanceof \WP_Error ? $can->get_error_message() : '',
			'masked'      => ! $visible,
		);
	}

	/**
	 * Undoes one change, and only that one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$entry   = $this->store->get( (int) $request->get_param( 'entry' ) );
		$post    = get_post( $post_id );

		if ( ! $entry || ! $post || $entry->object_id !== (string) $post_id || $entry->object_type !== $post->post_type || ! in_array( $entry->event, PostsRecorder::PANEL_EVENTS, true ) ) {
			return new \WP_Error( 'activity_log_with_undo_not_found', __( 'That change does not belong to this post.', 'activity-log-with-undo' ), array( 'status' => 404 ) );
		}

		$result = $this->undoer->undo( $entry->id, (bool) $request->get_param( 'force' ), array( $entry->id ) );
		if ( $result instanceof \WP_Error ) {
			$data           = (array) $result->get_error_data();
			$data['status'] = (int) ( $data['status'] ?? 400 );
			unset( $data['conflicts'] );

			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}

		/**
		 * Fires after a change was undone from the editor panel.
		 *
		 * @param int   $post_id Post ID.
		 * @param Entry $entry   The entry that was undone.
		 * @param array $result  What the undo returned: undone, failed, entries, forced.
		 */
		do_action( 'activity_log_with_undo_panel_undone', $post_id, $entry, $result );

		return new \WP_REST_Response( $result );
	}
}
