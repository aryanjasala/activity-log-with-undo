<?php
/**
 * REST route the log screen uses to undo without a page load.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Rest;

use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Service;
use AryanX\ActivityLogWithUndo\Undoer;

defined( 'ABSPATH' ) || exit;

/**
 * POST /activity-log-with-undo/v1/entries/<id>/undo with `force`.
 *
 * Answers 409 with the differences when things changed since, and tells the
 * screen where to go next either way.
 */
final class UndoController implements Service {

	public const NAMESPACE = 'activity-log-with-undo/v1';

	/**
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Sets up the route.
	 *
	 * @param Undoer $undoer Reverses changes.
	 */
	public function __construct( Undoer $undoer ) {
		$this->undoer = $undoer;
	}

	/**
	 * Registers the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Adds the route.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => static function () {
					return current_user_can( LogPage::capability() );
				},
				'args'                => array(
					'id'    => array(
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
	 * Undoes an entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->undoer->undo( $id, (bool) $request->get_param( 'force' ) );

		if ( $result instanceof \WP_Error ) {
			$data = (array) $result->get_error_data();
			if ( 'activity_log_with_undo_conflict' === $result->get_error_code() ) {
				$data['redirect'] = LogPage::url( array( 'entry' => $id ) );
			} else {
				LogPage::notice_for( $result );
				$data['redirect'] = LogPage::url();
			}
			$data['status'] = (int) ( $data['status'] ?? 400 );

			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}

		LogPage::notice_for( $result );

		return new \WP_REST_Response( $result + array( 'redirect' => LogPage::url() ) );
	}
}
