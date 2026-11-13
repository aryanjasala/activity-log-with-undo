<?php
/**
 * Tests for alert rules, matching, messages and delivery.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Tests\Unit;

use AryanX\Axle\ActivityLogWithUndo\Abilities;
use AryanX\Axle\ActivityLogWithUndo\Alerts\AlertPrivacy;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Alerts;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Delivery;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Matcher;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Message;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Rules;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Undoer;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AlertsTest extends LogTestCase {

	private const HOOK_URL = 'https://hooks.example.com/services/T000/B000/SECRETSECRET';

	/**
	 * Emails sent, as [to, subject, body].
	 *
	 * @var array
	 */
	private array $mails = array();

	/**
	 * Single events scheduled.
	 *
	 * @var int
	 */
	private int $scheduled = 0;

	/**
	 * Webhook bodies posted, as [url, body].
	 *
	 * @var array
	 */
	private array $posts = array();

	/**
	 * Users by ID, as objects.
	 *
	 * @var array<int, object>
	 */
	private array $users = array();

	/**
	 * User IDs who may edit things, so entries show to them.
	 *
	 * @var int[]
	 */
	private array $editors = array();

	protected function setUp(): void {
		parent::setUp();

		$this->mails     = array();
		$this->scheduled = 0;
		$this->posts   = array();
		$this->editors = array( 1 );
		$this->users   = array(
			1 => (object) array(
				'ID'         => 1,
				'user_login' => 'admin',
				'roles'      => array( 'administrator' ),
				'user_email' => 'admin@example.com',
			),
			2 => (object) array(
				'ID'         => 2,
				'user_login' => 'sam',
				'roles'      => array( 'editor' ),
				'user_email' => 'sam@example.com',
			),
		);

		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '&' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp = null ) {
				return gmdate( $format, $timestamp ?? time() );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->justReturn( true );
		Functions\when( '_n' )->alias(
			static function ( $one, $many, $count ) {
				return 1 === (int) $count ? $one : $many;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				return $this->users[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->users as $user ) {
					if ( 'email' === $field && $user->user_email === $value ) {
						return $user;
					}
				}
				return false;
			}
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				return in_array( (int) $user->ID, $this->editors, true );
			}
		);
		Functions\when( 'wp_set_current_user' )->alias(
			function ( $id ) {
				$GLOBALS['alu_test_user'] = (int) $id;
			}
		);
		Functions\when( 'get_current_user_id' )->alias(
			static function () {
				return $GLOBALS['alu_test_user'] ?? 1;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function () {
				return in_array( $GLOBALS['alu_test_user'] ?? 1, $this->editors, true );
			}
		);
		Functions\when( 'wp_get_current_user' )->alias(
			function () {
				return new class() {
					public $user_login = 'admin';

					public function exists() {
						return true;
					}
				};
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mails[] = array( $to, $subject, $body );
				return true;
			}
		);
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( $url, $args['body'] );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () {
				++$this->scheduled;
				return true;
			}
		);
		defined( 'AryanX\\Axle\\ActivityLogWithUndo\\VERSION' ) || define( 'AryanX\\Axle\\ActivityLogWithUndo\\VERSION', '0.0.0-test' );
		unset( $GLOBALS['alu_test_user'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['alu_test_user'] );
		parent::tearDown();
	}

	private function settings(): Settings {
		return new Settings();
	}

	private function make_entry( array $fields = array() ): Entry {
		$id                = count( $this->rows ) + 1;
		$row               = $fields + array(
			'id'            => $id,
			'created_gmt'   => '2026-11-14 03:00:00',
			'event'         => 'plugin_deactivated',
			'recorder'      => 'plugins',
			'object_type'   => 'plugin',
			'object_id'     => 'hello.php',
			'object_label'  => 'Hello Dolly',
			'actor_user_id' => 2,
			'before_state'  => wp_json_encode_stub( 'active' ),
			'after_state'   => wp_json_encode_stub( 'inactive' ),
		);
		$this->rows[ $id ] = $row;

		return Entry::from_row( $row );
	}

	private function rule( array $fields = array() ): array {
		return Rules::sanitize( $fields + array( 'id' => 'r1', 'name' => 'Plugins', 'enabled' => true ) );
	}

	private function alerts( ?Rules $rules = null ): Alerts {
		$settings = $this->settings();
		$registry = new Registry( array( new FakeRecorder( 'plugins' ) ), $settings );

		return new Alerts( $this->store, $settings, $rules ?? new Rules(), new Matcher( $settings ), new Message( $registry ), new Delivery() );
	}

	public function test_sanitize_cleans_a_rule_and_drops_bad_values(): void {
		$rule = Rules::sanitize(
			array(
				'id'      => 'My Rule!',
				'name'    => '  Plugins  ',
				'events'  => array( 'plugin_deactivated', 'Plugin Deleted', '' ),
				'emails'  => 'a@example.com, nope, b@example.com',
				'format'  => 'xml',
				'mode'    => 'hourly',
				'objects' => 'siteurl, home',
			)
		);

		$this->assertSame( 'myrule', $rule['id'] );
		$this->assertSame( 'Plugins', $rule['name'] );
		$this->assertSame( array( 'plugin_deactivated', 'plugindeleted' ), $rule['events'] );
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $rule['emails'] );
		$this->assertSame( 'json', $rule['format'] );
		$this->assertSame( 'instant', $rule['mode'] );
		$this->assertSame( array( 'siteurl', 'home' ), $rule['objects'] );
		$this->assertArrayNotHasKey( 'webhook_url', $rule );
	}

	public function test_the_webhook_url_is_kept_apart_and_only_its_host_is_shown(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_rule_saved' )->twice();
		$rules = new Rules();
		$saved = $rules->save(
			array(
				'name'    => 'Hook',
				'webhook' => true,
				'email'   => false,
			),
			self::HOOK_URL
		);

		$this->assertIsArray( $saved );
		$this->assertStringNotContainsString( 'SECRETSECRET', wp_json_encode_stub( $this->options[ Rules::OPTION ] ) );
		$this->assertTrue( $rules->has_webhook( $saved['id'] ) );
		$this->assertSame( 'hooks.example.com', $rules->webhook_host( $saved['id'] ) );

		// Saving again without a URL keeps the saved one.
		$rules->save( $saved + array( 'name' => 'Hook 2' ) );
		$this->assertSame( self::HOOK_URL, $rules->webhook_url( $saved['id'] ) );
	}

	public function test_a_webhook_needs_https_and_a_url(): void {
		$rules = new Rules();

		$http = $rules->save( array( 'name' => 'x', 'webhook' => true ), 'http://hooks.example.com/x' );
		$none = $rules->save( array( 'name' => 'x', 'webhook' => true ) );

		$this->assertInstanceOf( \WP_Error::class, $http );
		$this->assertInstanceOf( \WP_Error::class, $none );
		Filters\expectApplied( 'activity_log_with_undo_alert_webhook_schemes' )->once()->andReturn( array( 'https', 'http' ) );
		$this->assertTrue( Rules::valid_webhook( 'http://hooks.example.com/x' ) );
	}

	public function test_deleting_a_rule_removes_its_secret_and_queue(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_rule_deleted' )->once();
		$rules = new Rules();
		$saved = $rules->save( array( 'name' => 'Hook', 'webhook' => true ), self::HOOK_URL );
		$rules->save_state( array( 'rules' => array( $saved['id'] => array( 'last_sent' => 5 ) ), 'queue' => array( $saved['id'] => array( 'daily' => array( 1 ) ) ) ) );

		$this->assertTrue( $rules->delete( $saved['id'] ) );

		$this->assertFalse( $rules->has_webhook( $saved['id'] ) );
		$this->assertSame( array(), $rules->state()['queue'] );
		$this->assertFalse( $rules->delete( $saved['id'] ) );
	}

	public function test_the_rule_limit_is_filterable(): void {
		Filters\expectApplied( 'activity_log_with_undo_alert_max_rules' )->andReturn( 1 );
		$rules = new Rules();
		$rules->save( array( 'name' => 'one' ) );

		$this->assertInstanceOf( \WP_Error::class, $rules->save( array( 'name' => 'two' ) ) );
	}

	public function test_the_six_starter_rules_are_filterable_and_none_is_on(): void {
		$starters = Rules::starters();

		$this->assertCount( 6, $starters );
		$this->assertSame( array( 'siteurl', 'home' ), $starters['site-address']['objects'] );
		foreach ( $starters as $starter ) {
			$this->assertArrayNotHasKey( 'enabled', $starter );
		}
	}

	public function test_matching_by_event_area_object_user_and_role(): void {
		$matcher = new Matcher( $this->settings() );
		$entry   = $this->make_entry();

		$this->assertTrue( $matcher->matches( $this->rule(), $entry ) );
		$this->assertTrue( $matcher->matches( $this->rule( array( 'events' => array( 'plugin_deactivated' ) ) ), $entry ) );
		$this->assertFalse( $matcher->matches( $this->rule( array( 'events' => array( 'plugin_deleted' ) ) ), $entry ) );
		$this->assertFalse( $matcher->matches( $this->rule( array( 'areas' => array( 'users' ) ) ), $entry ) );
		$this->assertTrue( $matcher->matches( $this->rule( array( 'objects' => 'hello.php' ) ), $entry ) );
		$this->assertFalse( $matcher->matches( $this->rule( array( 'objects' => 'other.php' ) ), $entry ) );
		$this->assertTrue( $matcher->matches( $this->rule( array( 'users' => array( 2 ) ) ), $entry ) );
		$this->assertFalse( $matcher->matches( $this->rule( array( 'users' => array( 9 ) ) ), $entry ) );
		$this->assertTrue( $matcher->matches( $this->rule( array( 'roles' => array( 'editor' ) ) ), $entry ) );
		$this->assertFalse( $matcher->matches( $this->rule( array( 'roles' => array( 'author' ) ) ), $entry ) );
	}

	public function test_a_filter_can_veto_a_match(): void {
		Filters\expectApplied( 'activity_log_with_undo_alert_matches' )->once()->andReturn( false );

		$this->assertFalse( ( new Matcher( $this->settings() ) )->matches( $this->rule(), $this->make_entry() ) );
	}

	public function test_the_new_administrator_condition(): void {
		$matcher = new Matcher( $this->settings() );
		$rule    = $this->rule( array( 'events' => array( 'user_created', 'user_role_changed' ), 'condition' => 'becomes_administrator' ) );

		$created_admin  = $this->make_entry( array( 'event' => 'user_created', 'before_state' => null, 'after_state' => wp_json_encode_stub( array( 'roles' => array( 'administrator' ) ) ) ) );
		$created_author = $this->make_entry( array( 'event' => 'user_created', 'before_state' => null, 'after_state' => wp_json_encode_stub( array( 'roles' => array( 'author' ) ) ) ) );
		$promoted       = $this->make_entry( array( 'event' => 'user_role_changed', 'before_state' => wp_json_encode_stub( array( 'editor' ) ), 'after_state' => wp_json_encode_stub( array( 'administrator' ) ) ) );
		$already        = $this->make_entry( array( 'event' => 'user_role_changed', 'before_state' => wp_json_encode_stub( array( 'administrator' ) ), 'after_state' => wp_json_encode_stub( array( 'administrator', 'editor' ) ) ) );

		$this->assertTrue( $matcher->matches( $rule, $created_admin ) );
		$this->assertFalse( $matcher->matches( $rule, $created_author ) );
		$this->assertTrue( $matcher->matches( $rule, $promoted ) );
		$this->assertFalse( $matcher->matches( $rule, $already ) );
	}

	public function test_the_turned_on_condition_needs_off_to_on(): void {
		$matcher = new Matcher( $this->settings() );
		$rule    = $this->rule( array( 'condition' => 'turned_on' ) );

		$this->assertTrue( $matcher->matches( $rule, $this->make_entry( array( 'before_state' => wp_json_encode_stub( '0' ), 'after_state' => wp_json_encode_stub( '1' ) ) ) ) );
		$this->assertFalse( $matcher->matches( $rule, $this->make_entry( array( 'before_state' => wp_json_encode_stub( '1' ), 'after_state' => wp_json_encode_stub( '0' ) ) ) ) );
	}

	public function test_outside_working_hours_uses_days_and_times(): void {
		$matcher = new Matcher( $this->settings() );
		$rule    = $this->rule( array( 'outside_hours' => true ) );

		// 2026-11-13 is a Friday; 2026-11-14 a Saturday.
		$this->assertFalse( $matcher->matches( $rule, $this->make_entry( array( 'created_gmt' => '2026-11-13 10:00:00' ) ) ) );
		$this->assertTrue( $matcher->matches( $rule, $this->make_entry( array( 'created_gmt' => '2026-11-13 22:00:00' ) ) ) );
		$this->assertTrue( $matcher->matches( $rule, $this->make_entry( array( 'created_gmt' => '2026-11-14 10:00:00' ) ) ) );
		$this->assertTrue( $matcher->matches( $rule, $this->make_entry( array( 'created_gmt' => '2026-11-13 08:59:00' ) ) ) );
	}

	public function test_settings_keep_working_hours_valid(): void {
		$clean = Settings::sanitize( array( 'work_days' => array( 0, 3, 3, 9, '5' ), 'work_start' => '20:00', 'work_end' => '08:00', 'alert_throttle' => 99999 ) );

		$this->assertSame( array( 3, 5 ), $clean['work_days'] );
		$this->assertSame( '09:00', $clean['work_start'] );
		$this->assertSame( '18:00', $clean['work_end'] );
		$this->assertSame( 1440, $clean['alert_throttle'] );
	}

	public function test_an_entry_the_reader_may_not_edit_is_masked_in_the_message(): void {
		$registry = new Registry( array( new FakeRecorder( 'plugins' ) ), $this->settings() );
		$message  = new Message( $registry );
		$entry    = $this->make_entry();

		$this->editors = array( 1 );
		$for_stranger  = $message->build( $this->rule(), array( $entry ), 'instant', 0 );
		$for_sam       = $message->build( $this->rule(), array( $entry ), 'instant', 2 );
		$for_admin     = $message->build( $this->rule(), array( $entry ), 'instant', 1 );

		$this->assertTrue( $for_stranger['items'][0]['masked'] );
		$this->assertSame( '', $for_stranger['items'][0]['url'] );
		$this->assertSame( '', $for_stranger['items'][0]['after'] );
		$this->assertSame( 'sam', $for_stranger['items'][0]['who'] );
		$this->assertTrue( $for_sam['items'][0]['masked'] );
		$this->assertFalse( $for_admin['items'][0]['masked'] );
		$this->assertSame( 'inactive', $for_admin['items'][0]['after'] );
		$this->assertStringContainsString( 'entry=' . $entry->id, $for_admin['items'][0]['url'] );
	}

	public function test_values_that_look_like_secrets_and_big_structures_are_not_printed(): void {
		$registry = new Registry( array( new FakeRecorder( 'plugins' ) ), $this->settings() );
		$message  = new Message( $registry );
		$secret   = $this->make_entry( array( 'object_id' => 'my_api_key', 'before_state' => wp_json_encode_stub( 'old-secret' ), 'after_state' => wp_json_encode_stub( 'new-secret' ) ) );
		$bulk     = $this->make_entry( array( 'before_state' => wp_json_encode_stub( array( 'a' => 1 ) ), 'after_state' => wp_json_encode_stub( array( 'a' => 2, 'b' => 3 ) ) ) );

		$built = $message->build( $this->rule(), array( $secret, $bulk ), 'instant', 1 );

		$this->assertSame( '(hidden)', $built['items'][0]['after'] );
		$this->assertSame( '2 fields', $built['items'][1]['after'] );
	}

	public function test_the_email_has_no_undo_link_and_points_to_the_log(): void {
		$registry = new Registry( array( new FakeRecorder( 'plugins' ) ), $this->settings() );
		$message  = new Message( $registry );
		$rule     = $this->rule();
		$text     = Message::to_text( $message->build( $rule, array( $this->make_entry() ), 'instant', 1 ), $rule );

		$this->assertStringContainsString( 'Open it in the log', $text );
		$this->assertStringContainsString( 'sign in to the site', $text );
		$this->assertStringNotContainsString( 'alu_undo', $text );
		$this->assertStringNotContainsString( 'undo_action', $text );
	}

	public function test_the_slack_format_escapes_and_the_json_format_has_the_entries(): void {
		$rule    = $this->rule();
		$message = array(
			'kind'    => 'instant',
			'subject' => '[Site] Alert: <b>',
			'title'   => 'x',
			'count'   => 1,
			'more'    => 0,
			'items'   => array(
				array(
					'id'     => 4,
					'time'   => '2026-11-13T10:00:00+00:00',
					'when'   => '13 Nov 2026 10:00',
					'who'    => 'sam',
					'via'    => 'admin',
					'what'   => 'Plugin <script> & more',
					'before' => 'a',
					'after'  => 'b',
					'url'    => 'https://example.test/x',
					'masked' => false,
				),
			),
		);

		$slack = Message::to_slack( $message );
		$json  = Message::to_json( $message, $rule );

		$this->assertStringContainsString( 'Plugin &lt;script&gt; &amp; more', $slack['text'] );
		$this->assertSame( 'mrkdwn', $slack['blocks'][0]['text']['type'] );
		$this->assertSame( 4, $json['entries'][0]['id'] );
		$this->assertSame( 'activity_log_with_undo.alert', $json['type'] );
	}

	public function test_a_bulk_change_is_one_message_per_rule_and_the_webhook_never_carries_its_url(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_sent' )->twice();
		$rules = new Rules();
		$saved = $rules->save( array( 'name' => 'Plugins', 'enabled' => true, 'email' => true, 'emails' => 'admin@example.com', 'webhook' => true, 'format' => 'slack' ), self::HOOK_URL );
		$alerts = $this->alerts( $rules );

		for ( $i = 0; $i < 5; $i++ ) {
			$alerts->collect( $this->make_entry() );
		}
		$alerts->process();

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( self::HOOK_URL, $this->posts[0][0] );
		$this->assertStringNotContainsString( 'SECRETSECRET', $this->posts[0][1] );
		$this->assertStringNotContainsString( 'SECRETSECRET', $this->mails[0][2] );
		$this->assertSame( 5, substr_count( $this->mails[0][2], 'By sam' ) );
		unset( $saved );
	}

	public function test_a_rule_that_sent_a_moment_ago_holds_the_next_message(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_throttled' )->once();
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'enabled' => true, 'email' => true, 'emails' => 'admin@example.com' ) );
		$rules->save_state( array( 'rules' => array( 'r1' => array( 'last_sent' => time() - 30 ) ), 'queue' => array() ) );
		$alerts = $this->alerts( $rules );

		$alerts->collect( $this->make_entry() );
		$alerts->process();

		$this->assertCount( 0, $this->mails );
		$this->assertSame( 1, $this->scheduled );
		$this->assertCount( 1, $rules->state()['queue']['r1']['held'] );
	}

	public function test_held_changes_go_out_together_when_the_window_is_over(): void {
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'enabled' => true, 'email' => true, 'emails' => 'admin@example.com' ) );
		$first  = $this->make_entry();
		$second = $this->make_entry();
		$rules->save_state( array( 'rules' => array( 'r1' => array( 'last_sent' => time() - 3600 ) ), 'queue' => array( 'r1' => array( 'held' => array( $first->id, $second->id ) ) ) ) );

		$this->alerts( $rules )->send_held();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 2, substr_count( $this->mails[0][2], 'By sam' ) );
		$this->assertArrayNotHasKey( 'r1', $rules->state()['queue'] );
	}

	public function test_a_daily_rule_queues_and_the_digest_sends_once(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_digest_sent' )->once()->with( 1 );
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'enabled' => true, 'mode' => 'daily', 'email' => true, 'emails' => 'admin@example.com' ) );
		$alerts = $this->alerts( $rules );

		$alerts->collect( $this->make_entry() );
		$alerts->collect( $this->make_entry() );
		$alerts->process();
		$this->assertCount( 0, $this->mails );

		$this->assertSame( 1, $alerts->send_digests() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Daily digest', $this->mails[0][1] );
		$this->assertStringContainsString( '2 changes', $this->mails[0][1] );
		$this->assertSame( 0, $alerts->send_digests() );
	}

	public function test_an_address_that_is_not_a_user_gets_a_masked_message(): void {
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'enabled' => true, 'email' => true, 'emails' => 'outsider@example.net,admin@example.com' ) );
		$alerts = $this->alerts( $rules );

		$alerts->collect( $this->make_entry() );
		$alerts->process();

		$this->assertCount( 2, $this->mails );
		$this->assertStringNotContainsString( 'Open it in the log', $this->mails[0][2] );
		$this->assertStringContainsString( 'not allowed to see', $this->mails[0][2] );
		$this->assertStringContainsString( 'Open it in the log', $this->mails[1][2] );
	}

	public function test_nothing_is_buffered_when_no_rule_is_on(): void {
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Off', 'enabled' => false ) );
		$alerts = $this->alerts( $rules );

		$alerts->collect( $this->make_entry() );
		$alerts->process();

		$this->assertCount( 0, $this->mails );
	}

	public function test_a_test_goes_through_every_channel_and_does_not_start_a_throttle_window(): void {
		Actions\expectDone( 'activity_log_with_undo_alert_test_sent' )->once();
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'email' => true, 'emails' => 'admin@example.com', 'webhook' => true ), self::HOOK_URL );
		$alerts = $this->alerts( $rules );

		$results = $alerts->test( 'r1' );

		$this->assertCount( 2, $results );
		$this->assertTrue( $results[0]['ok'] );
		$this->assertSame( 'hooks.example.com', $results[1]['target'] );
		$this->assertSame( 0, $rules->state()['rules']['r1']['last_sent'] );
		$this->assertStringContainsString( 'Test of the alert', $this->mails[0][1] );
		$this->assertInstanceOf( \WP_Error::class, $alerts->test( 'nope' ) );
	}

	public function test_a_failed_webhook_says_why_without_the_url_and_is_remembered(): void {
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( $url ) {
				return new \WP_Error( 'http_request_failed', 'Could not resolve ' . $url );
			}
		);
		Actions\expectDone( 'activity_log_with_undo_alert_failed' )->once();
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Plugins', 'email' => false, 'webhook' => true ), self::HOOK_URL );
		$alerts = $this->alerts( $rules );

		$results = $alerts->test( 'r1' );

		$this->assertFalse( $results[0]['ok'] );
		$this->assertStringNotContainsString( 'SECRETSECRET', $results[0]['error'] );
		$this->assertStringContainsString( '[webhook]', $results[0]['error'] );
		$this->assertNotSame( '', $rules->state()['rules']['r1']['error'] );
	}

	public function test_a_non_success_status_is_an_error(): void {
		Functions\when( 'wp_safe_remote_post' )->justReturn( array( 'response' => array( 'code' => 404 ) ) );

		$result = ( new Delivery() )->webhook( self::HOOK_URL, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( '404', $result->get_error_message() );
	}

	public function test_recipients_are_filterable_and_default_to_the_admin_email(): void {
		$this->options['admin_email'] = 'owner@example.com';
		Filters\expectApplied( 'activity_log_with_undo_alert_recipients' )->once()->andReturnFirstArg();

		$this->assertSame( array( 'owner@example.com' ), $this->alerts()->recipients( $this->rule() ) );
	}

	public function test_the_abilities_never_return_a_webhook_url_or_an_address(): void {
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Hook', 'email' => true, 'emails' => 'admin@example.com', 'webhook' => true ), self::HOOK_URL );
		$logger   = $this->logger();
		$registry = new Registry( array( new FakeRecorder( 'plugins' ) ), $this->settings() );
		$undoer   = new Undoer( $this->store, $logger, $registry );
		$abilities = new Abilities( $this->store, $registry, $undoer, new Rollback( $this->store, $logger, $undoer, $registry ), $rules, $this->alerts( $rules ) );

		$listed = $abilities->list_alert_rules();
		$set    = $abilities->set_alert_enabled( array( 'id' => 'r1', 'enabled' => true ) );

		$this->assertTrue( $listed[0]['webhook'] );
		$this->assertTrue( $set['enabled'] );
		$this->assertStringNotContainsString( 'SECRETSECRET', wp_json_encode_stub( array( $listed, $set ) ) );
		$this->assertStringNotContainsString( 'admin@example.com', wp_json_encode_stub( $listed ) );
		$this->assertInstanceOf( \WP_Error::class, $abilities->set_alert_enabled( array( 'id' => 'nope', 'enabled' => true ) ) );
	}

	public function test_privacy_exports_and_erases_an_address_from_the_rules(): void {
		$rules = new Rules();
		$rules->save( array( 'id' => 'r1', 'name' => 'Hook', 'email' => true, 'emails' => 'sam@example.com, admin@example.com', 'owner' => 2 ) );
		$privacy = new AlertPrivacy( $rules );

		$export = $privacy->export( 'sam@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertCount( 0, $privacy->export( 'nobody@example.com' )['data'] );

		$erased = $privacy->erase( 'sam@example.com' );
		$this->assertTrue( $erased['items_removed'] );
		$rule = $rules->get( 'r1' );
		$this->assertSame( array( 'admin@example.com' ), $rule['emails'] );
		$this->assertSame( 0, $rule['owner'] );
	}
}

/**
 * JSON for a state column.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_stub( $value ): string {
	return \AryanX\Axle\ActivityLogWithUndo\Payload::encode( $value ) ?? 'null';
}
