<?php
/**
 * Plugin Name: Activity Log with Undo E2E fixture
 * Description: Test-only hooks for the end-to-end tests. Copied into mu-plugins for the run, removed after.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

// phpcs:ignoreFile -- Test fixture, never shipped.

// Always log this option, in any request (the filterable allowlist).
add_filter(
	'activity_log_with_undo_logged_options',
	static function ( $patterns ) {
		$patterns[] = 'alu_e2e_always';
		return $patterns;
	}
);

// A lower per-option rate limit while a test asks for one.
add_filter(
	'activity_log_with_undo_option_rate_limit',
	static function ( $limit ) {
		$low = (int) get_option( 'alu_e2e_rate', 0 );
		return $low > 0 ? $low : $limit;
	}
);

// A visitor's page view that changes a setting, an always-logged option and an internal one.
add_action(
	'template_redirect',
	static function () {
		if ( isset( $_GET['alu_e2e_visit'] ) ) {
			update_option( 'blogdescription', 'Changed by a visitor' );
			update_option( 'alu_e2e_always', 'visited ' . microtime( true ) );
			update_option( 'alu_e2e_internal', 'visited ' . microtime( true ) );
			echo 'ok';
			exit;
		}
	}
);

// A setting changed before init, on wp-admin screens, while a test asks for it.
add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'alu_e2e_early' ) && is_admin() && ! wp_doing_ajax() ) {
			update_option( 'blogdescription', 'Early ' . microtime( true ) );
		}
	},
	20
);

// Another plugin that refuses writes, the way Axle Read-Only Mode does during a freeze.
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		if ( get_option( 'alu_e2e_freeze' ) && 0 === strpos( $request->get_route(), '/activity-log-with-undo/' ) ) {
			return new WP_Error( 'alu_e2e_frozen', 'This site is frozen for the test. Try again later.', array( 'status' => 423 ) );
		}
		return $result;
	},
	10,
	3
);

// Lets a test open the log to a user who is not an administrator.
add_filter(
	'activity_log_with_undo_capability',
	static function ( $capability ) {
		$custom = (string) get_option( 'alu_e2e_log_cap', '' );
		return '' !== $custom ? $custom : $capability;
	}
);

// Application passwords on the plain HTTP test site.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Alerts: keep every email and webhook request instead of sending it, and answer the webhook with a 200.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		if ( ! get_option( 'alu_e2e_capture' ) ) {
			return $short;
		}
		$log   = (array) get_option( 'alu_e2e_mails', array() );
		$log[] = array(
			'to'      => (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
		);
		update_option( 'alu_e2e_mails', $log, false );
		return true;
	},
	10,
	2
);
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! get_option( 'alu_e2e_capture' ) || false === strpos( $url, 'hooks.slack.com' ) ) {
			return $pre;
		}
		$log   = (array) get_option( 'alu_e2e_posts', array() );
		$log[] = array(
			'url'  => $url,
			'body' => (string) $args['body'],
		);
		update_option( 'alu_e2e_posts', $log, false );
		return array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => (int) get_option( 'alu_e2e_webhook_code', 200 ),
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
