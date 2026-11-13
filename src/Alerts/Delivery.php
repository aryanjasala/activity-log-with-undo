<?php
/**
 * Sends alert messages.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

use const AryanX\Axle\ActivityLogWithUndo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Email through wp_mail() and webhooks through the safe HTTP API. Errors never carry a webhook URL.
 */
final class Delivery {

	/**
	 * Sends one email.
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $body    Plain text body.
	 * @return true|\WP_Error
	 */
	public function email( string $to, string $subject, string $body ) {
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'activity_log_with_undo_alert_bad_email', __( 'The address is not valid.', 'activity-log-with-undo' ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One message per alert, not bulk mail.
		$sent = wp_mail( $to, $subject, $body );

		return $sent ? true : new \WP_Error( 'activity_log_with_undo_alert_mail_failed', __( 'The site could not send the email. Check that WordPress can send mail.', 'activity-log-with-undo' ) );
	}

	/**
	 * Posts JSON to a webhook.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $payload Data.
	 * @return true|\WP_Error
	 */
	public function webhook( string $url, array $payload ) {
		$valid = Rules::valid_webhook( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'ActivityLogWithUndo/' . VERSION,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $payload ),
		);

		/**
		 * Filters the HTTP arguments of a webhook request.
		 *
		 * @param array $args    Arguments for wp_safe_remote_post().
		 * @param array $payload The data sent.
		 */
		$args     = (array) apply_filters( 'activity_log_with_undo_alert_webhook_args', $args, $payload );
		$response = wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$text = str_replace( $url, '[webhook]', $response->get_error_message() );

			/* translators: %s: the error. */
			return new \WP_Error( 'activity_log_with_undo_alert_webhook_failed', sprintf( __( 'The webhook could not be reached: %s', 'activity-log-with-undo' ), $text ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'activity_log_with_undo_alert_webhook_status', sprintf( __( 'The webhook answered with HTTP %d. Check the URL.', 'activity-log-with-undo' ), $code ) );
		}

		return true;
	}
}
