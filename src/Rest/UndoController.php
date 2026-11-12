<?php
/**
 * REST route the log screen uses to undo without a page load.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Rest;

use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Undoer;

defined( 'ABSPATH' ) || exit;

/**
 * POST /activity-log-with-undo/v1/entries/<id>/undo with `force`, and
 * POST /rollback/<job>/step and /rollback/<job>/cancel for a roll back.
 *
 * Answers 409 with the differences when things changed since, and the page
 * that shows them. Other errors carry only their message, which the screen
 * shows in place.
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
	 * Rolls the site back to a moment.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Sets up the routes.
	 *
	 * @param Undoer   $undoer   Reverses changes.
	 * @param Rollback $rollback Rolls the site back to a moment.
	 */
	public function __construct( Undoer $undoer, Rollback $rollback ) {
		$this->undoer   = $undoer;
		$this->rollback = $rollback;
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

		foreach ( array( 'step', 'cancel' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/rollback/(?P<job>[a-f0-9]{32})/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( Rollback::class, 'allowed' ),
					'args'                => array(
						'job' => array(
							'type'     => 'string',
							'pattern'  => '^[a-f0-9]{32}$',
							'required' => true,
						),
					),
				)
			);
		}
	}

	/**
	 * Reverses the next few change sets of a roll back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function step( $request ) {
		return $this->answer( $this->rollback->step( (string) $request->get_param( 'job' ) ) );
	}

	/**
	 * Stops a roll back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( $request ) {
		return $this->answer( $this->rollback->cancel( (string) $request->get_param( 'job' ) ) );
	}

	/**
	 * A roll back's state as a response. When it ended, the log screen shows what happened.
	 *
	 * @param array|\WP_Error $state State, or the reason it failed.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function answer( $state ) {
		if ( $state instanceof \WP_Error ) {
			$data           = (array) $state->get_error_data();
			$data['status'] = (int) ( $data['status'] ?? 400 );

			return new \WP_Error( $state->get_error_code(), $state->get_error_message(), $data );
		}

		if ( 'running' !== $state['state'] ) {
			LogPage::notice_for_rollback( $state );
			$state['redirect'] = LogPage::url( array( 'tab' => 'rollback' ) );
		}

		return new \WP_REST_Response( $state );
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
			// A conflict opens the entry with the differences; any other error is shown where the person is.
			if ( 'activity_log_with_undo_conflict' === $result->get_error_code() ) {
				$data['redirect'] = LogPage::url( array( 'entry' => $id ) );
			}
			$data['status'] = (int) ( $data['status'] ?? 400 );

			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}

		LogPage::notice_for( $result );

		return new \WP_REST_Response( $result + array( 'redirect' => LogPage::url() ) );
	}
}
