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

// Another plugin that refuses writes, the way Site Read-Only Mode does during a freeze.
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
