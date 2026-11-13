<?php
/**
 * Alerts and the daily digest.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Watches new log entries, matches them against the rules and sends the messages.
 *
 * Matching runs at shutdown, so one request that logs forty entries is one message, and an entry a
 * recorder updated later in the request is read as it ended up. A rule sends at most one instant message
 * per throttle window; later changes wait and go out together. Daily rules queue and send once a day.
 */
final class Alerts implements Service {

	public const DIGEST_HOOK = 'activity_log_with_undo_alert_digest';
	public const HELD_HOOK   = 'activity_log_with_undo_alert_held';

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Matching.
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Message builder.
	 *
	 * @var Message
	 */
	private Message $message;

	/**
	 * Sender.
	 *
	 * @var Delivery
	 */
	private Delivery $delivery;

	/**
	 * Entry IDs logged in this request.
	 *
	 * @var int[]
	 */
	private array $buffer = array();

	/**
	 * Whether any rule is on, read once per request.
	 *
	 * @var bool|null
	 */
	private ?bool $watching = null;

	/**
	 * Sets up the alerts.
	 *
	 * @param Store    $store    Entries.
	 * @param Settings $settings Saved settings.
	 * @param Rules    $rules    Rules.
	 * @param Matcher  $matcher  Matching.
	 * @param Message  $message  Message builder.
	 * @param Delivery $delivery Sender.
	 */
	public function __construct( Store $store, Settings $settings, Rules $rules, Matcher $matcher, Message $message, Delivery $delivery ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->rules    = $rules;
		$this->matcher  = $matcher;
		$this->message  = $message;
		$this->delivery = $delivery;
	}

	/**
	 * Hooks the watcher and the two jobs.
	 */
	public function register(): void {
		add_action( 'activity_log_with_undo_logged', array( $this, 'collect' ) );
		add_action( 'shutdown', array( $this, 'process' ), 100 );
		add_action( self::DIGEST_HOOK, array( $this, 'run_digests' ) );
		add_action( self::HELD_HOOK, array( $this, 'send_held' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Capability needed to manage alerts.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to manage alert rules and send test alerts.
		 *
		 * @param string $capability Default: the capability that opens the log.
		 */
		$capability = (string) apply_filters( 'activity_log_with_undo_alerts_capability', LogPage::capability() );

		return '' !== $capability ? $capability : LogPage::capability();
	}

	/**
	 * Schedules the daily digest, at the start of the working day.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::DIGEST_HOOK ) ) {
			return;
		}

		$start = (string) ( new Settings() )->get( 'work_start' );
		$next  = strtotime( 'tomorrow ' . $start, time() ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Only the clock of the site's day is wanted.
		$next  = false === $next ? time() + DAY_IN_SECONDS : $next;

		/**
		 * Filters when the first daily digest is sent.
		 *
		 * @param int $timestamp Unix time. Default: tomorrow at the start of working hours, server time.
		 */
		$first = max( time() + MINUTE_IN_SECONDS, (int) apply_filters( 'activity_log_with_undo_alert_digest_time', $next ) );
		wp_schedule_event( $first, 'daily', self::DIGEST_HOOK );
	}

	/**
	 * Removes both jobs. Runs on deactivation.
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::DIGEST_HOOK );
		wp_unschedule_hook( self::HELD_HOOK );
	}

	/**
	 * Notes a new entry. Matching waits for shutdown.
	 *
	 * @param Entry $entry The new entry.
	 */
	public function collect( Entry $entry ): void {
		if ( $entry->id <= 0 ) {
			return;
		}
		if ( null === $this->watching ) {
			$this->watching = (bool) $this->rules->enabled();
		}
		if ( $this->watching && count( $this->buffer ) < 1000 ) {
			$this->buffer[] = $entry->id;
		}
	}

	/**
	 * Matches this request's entries against the rules and sends or queues them.
	 */
	public function process(): void {
		if ( ! $this->buffer ) {
			return;
		}

		$entries      = array();
		$ids          = $this->buffer;
		$this->buffer = array();
		foreach ( $ids as $id ) {
			$entry = $this->store->get( $id );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}

		foreach ( $this->rules->enabled() as $rule ) {
			$matched = array_values(
				array_filter(
					$entries,
					function ( Entry $entry ) use ( $rule ) {
						return $this->matcher->matches( $rule, $entry );
					}
				)
			);
			if ( $matched ) {
				$this->dispatch( $rule, $matched );
			}
		}
	}

	/**
	 * Sends the matches now, or queues them.
	 *
	 * @param array   $rule    Rule.
	 * @param Entry[] $entries Matching entries.
	 */
	private function dispatch( array $rule, array $entries ): void {
		if ( 'daily' === $rule['mode'] ) {
			$this->enqueue( $rule['id'], 'daily', $entries );
			return;
		}

		$state    = $this->rules->state();
		$last     = (int) ( $state['rules'][ $rule['id'] ]['last_sent'] ?? 0 );
		$throttle = $this->settings->alert_throttle();
		if ( time() - $last >= $throttle ) {
			$this->deliver( $rule, $entries, 'instant' );
			return;
		}

		$this->enqueue( $rule['id'], 'held', $entries );
		if ( ! wp_next_scheduled( self::HELD_HOOK ) ) {
			wp_schedule_single_event( $last + $throttle + 1, self::HELD_HOOK );
		}

		/**
		 * Fires when a rule's message is held back because it sent one a moment ago.
		 *
		 * @param array   $rule    The rule. It holds no webhook URL.
		 * @param Entry[] $entries The entries held.
		 */
		do_action( 'activity_log_with_undo_alert_throttled', $rule, $entries );
	}

	/**
	 * Adds entries to a rule's queue.
	 *
	 * @param string  $rule_id Rule ID.
	 * @param string  $queue   daily or held.
	 * @param Entry[] $entries Entries.
	 */
	private function enqueue( string $rule_id, string $queue, array $entries ): void {
		/**
		 * Filters how many entries a rule queues for its next message. Past it, the count still grows but the entries are dropped.
		 *
		 * @param int $limit Default 500.
		 */
		$limit = max( 10, (int) apply_filters( 'activity_log_with_undo_alert_queue_limit', 500 ) );
		$state = $this->rules->state();
		$held  = $state['queue'][ $rule_id ][ $queue ] ?? array();
		$held  = is_array( $held ) ? $held : array();
		foreach ( $entries as $entry ) {
			if ( count( $held ) < $limit ) {
				$held[] = $entry->id;
			}
		}
		$state['queue'][ $rule_id ][ $queue ] = $held;
		$this->rules->save_state( $state );
	}

	/**
	 * Takes a rule's queue.
	 *
	 * @param string $rule_id Rule ID.
	 * @param string $queue   daily or held.
	 * @return Entry[]
	 */
	private function take( string $rule_id, string $queue ): array {
		$state = $this->rules->state();
		$ids   = $state['queue'][ $rule_id ][ $queue ] ?? array();
		unset( $state['queue'][ $rule_id ][ $queue ] );
		if ( empty( $state['queue'][ $rule_id ] ) ) {
			unset( $state['queue'][ $rule_id ] );
		}
		$this->rules->save_state( $state );

		$entries = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$entry = $this->store->get( (int) $id );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Cron entry point for the digests.
	 */
	public function run_digests(): void {
		$this->send_digests();
	}

	/**
	 * Sends each daily rule's digest.
	 *
	 * @return int Digests sent.
	 */
	public function send_digests(): int {
		$sent = 0;
		foreach ( $this->rules->all() as $rule ) {
			if ( 'daily' !== $rule['mode'] ) {
				continue;
			}
			$entries = $this->take( $rule['id'], 'daily' );
			if ( $entries && $rule['enabled'] ) {
				$this->deliver( $rule, $entries, 'digest' );
				++$sent;
			}
		}

		/**
		 * Fires after the daily digests are sent.
		 *
		 * @param int $sent Number of digests sent.
		 */
		do_action( 'activity_log_with_undo_alert_digest_sent', $sent );

		return $sent;
	}

	/**
	 * Cron: sends what throttled rules held back, once their window is over.
	 */
	public function send_held(): void {
		$state    = $this->rules->state();
		$throttle = $this->settings->alert_throttle();
		$again    = 0;
		foreach ( array_keys( $state['queue'] ) as $rule_id ) {
			$rule = $this->rules->get( (string) $rule_id );
			if ( ! $rule || ! $rule['enabled'] || 'instant' !== $rule['mode'] ) {
				$this->take( (string) $rule_id, 'held' );
				continue;
			}
			$last = (int) ( $state['rules'][ $rule_id ]['last_sent'] ?? 0 );
			if ( time() - $last < $throttle ) {
				$again = max( $again, $last + $throttle + 1 );
				continue;
			}
			$entries = $this->take( (string) $rule_id, 'held' );
			if ( $entries ) {
				$this->deliver( $rule, $entries, 'held' );
			}
		}
		if ( $again ) {
			wp_schedule_single_event( $again, self::HELD_HOOK );
		}
	}

	/**
	 * Sends a rule's message to every channel.
	 *
	 * @param array   $rule    Rule.
	 * @param Entry[] $entries Entries. Empty for a test.
	 * @param string  $kind    instant, held, digest or test.
	 * @return array<int, array{channel: string, target: string, ok: bool, error: string}>
	 */
	public function deliver( array $rule, array $entries, string $kind ): array {
		$results = array();

		if ( $rule['email'] ) {
			foreach ( $this->recipients( $rule ) as $address ) {
				$user      = get_user_by( 'email', $address );
				$viewer    = $user ? (int) $user->ID : 0;
				$message   = $this->message_for( $rule, $entries, $kind, $viewer );
				$sent      = $this->delivery->email( $address, $message['subject'], Message::to_text( $message, $rule ) );
				$results[] = $this->result( 'email', $address, $sent, $rule, $entries, $kind );
			}
		}

		if ( $rule['webhook'] ) {
			$url = $this->rules->webhook_url( $rule['id'] );
			if ( '' === $url ) {
				$sent = new \WP_Error( 'activity_log_with_undo_alert_no_webhook', __( 'There is no webhook URL saved for this rule.', 'activity-log-with-undo' ) );
			} else {
				$message = $this->message_for( $rule, $entries, $kind, (int) $rule['owner'] );
				$payload = 'slack' === $rule['format'] ? Message::to_slack( $message ) : Message::to_json( $message, $rule );

				/**
				 * Filters the data a webhook receives.
				 *
				 * @param array  $payload The data, in the rule's format.
				 * @param array  $rule    The rule. It holds no webhook URL.
				 * @param array  $message The message it was built from.
				 * @param string $format  json or slack.
				 */
				$payload = (array) apply_filters( 'activity_log_with_undo_alert_webhook_payload', $payload, $rule, $message, $rule['format'] );
				$sent    = $this->delivery->webhook( $url, $payload );
			}
			$results[] = $this->result( 'webhook', $this->rules->webhook_host( $rule['id'] ), $sent, $rule, $entries, $kind );
		}

		$this->remember( $rule['id'], $results, 'test' !== $kind );

		return $results;
	}

	/**
	 * Sends a test message through a rule's channels.
	 *
	 * @param string $rule_id Rule ID.
	 * @return array|\WP_Error Results per channel.
	 */
	public function test( string $rule_id ) {
		$rule = $this->rules->get( $rule_id );
		if ( ! $rule ) {
			return new \WP_Error( 'activity_log_with_undo_alert_unknown', __( 'That alert rule does not exist.', 'activity-log-with-undo' ) );
		}

		$results = $this->deliver( $rule, array(), 'test' );

		/**
		 * Fires after a test alert is sent.
		 *
		 * @param array $rule    The rule. It holds no webhook URL.
		 * @param array $results One result per channel: channel, target, ok, error.
		 */
		do_action( 'activity_log_with_undo_alert_test_sent', $rule, $results );

		return $results;
	}

	/**
	 * Whom a rule's email goes to.
	 *
	 * @param array $rule Rule.
	 * @return string[]
	 */
	public function recipients( array $rule ): array {
		$emails = $rule['emails'] ? $rule['emails'] : array( (string) get_option( 'admin_email' ) );

		/**
		 * Filters the email addresses of an alert rule.
		 *
		 * Each address gets its own message, which leaves out what that person may not see.
		 *
		 * @param string[] $emails Addresses. Default: the rule's own, or the site admin email.
		 * @param array    $rule   The rule. It holds no webhook URL.
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_alert_recipients', $emails, $rule );

		return array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $filtered ) ), 'is_email' ) ) );
	}

	/**
	 * Builds a message, or the sample a test sends.
	 *
	 * @param array   $rule    Rule.
	 * @param Entry[] $entries Entries.
	 * @param string  $kind    Kind.
	 * @param int     $viewer  Reader's user ID.
	 * @return array
	 */
	private function message_for( array $rule, array $entries, string $kind, int $viewer ): array {
		$message = $this->message->build( $rule, $entries, $kind, $viewer );
		if ( 'test' === $kind ) {
			$user             = wp_get_current_user();
			$message['count'] = 1;
			$message['items'] = array(
				array(
					'id'     => 0,
					'time'   => gmdate( 'c' ),
					'when'   => wp_date( 'j M Y H:i' ),
					'who'    => $user->exists() ? $user->user_login : __( 'System', 'activity-log-with-undo' ),
					'via'    => '',
					/* translators: %s: rule name. */
					'what'   => sprintf( __( 'A test of the alert rule "%s"', 'activity-log-with-undo' ), $rule['name'] ),
					'before' => '',
					'after'  => '',
					'url'    => LogPage::url( array( 'tab' => 'alerts' ) ),
					'masked' => false,
				),
			);
		}

		return $message;
	}

	/**
	 * One channel's result, with the action for it.
	 *
	 * @param string         $channel email or webhook.
	 * @param string         $target  Address or webhook host.
	 * @param true|\WP_Error $sent    Outcome.
	 * @param array          $rule    Rule.
	 * @param Entry[]        $entries Entries.
	 * @param string         $kind    Kind.
	 * @return array{channel: string, target: string, ok: bool, error: string}
	 */
	private function result( string $channel, string $target, $sent, array $rule, array $entries, string $kind ): array {
		$ok     = true === $sent;
		$result = array(
			'channel' => $channel,
			'target'  => $target,
			'ok'      => $ok,
			'error'   => $ok ? '' : $sent->get_error_message(),
		);

		if ( $ok ) {
			/**
			 * Fires after an alert message is sent.
			 *
			 * @param array   $rule    The rule. It holds no webhook URL.
			 * @param string  $channel email or webhook.
			 * @param Entry[] $entries The entries in the message.
			 * @param string  $kind    instant, held, digest or test.
			 */
			do_action( 'activity_log_with_undo_alert_sent', $rule, $channel, $entries, $kind );
		} else {
			/**
			 * Fires when an alert message could not be sent.
			 *
			 * @param array   $rule    The rule. It holds no webhook URL.
			 * @param string  $channel email or webhook.
			 * @param string  $error   Why, without the webhook URL.
			 * @param Entry[] $entries The entries in the message.
			 */
			do_action( 'activity_log_with_undo_alert_failed', $rule, $channel, $result['error'], $entries );
		}

		return $result;
	}

	/**
	 * Keeps the outcome for the screen and Site Health.
	 *
	 * @param string $rule_id   Rule ID.
	 * @param array  $results   Channel results.
	 * @param bool   $real      False for a test, which does not start a throttle window.
	 */
	private function remember( string $rule_id, array $results, bool $real ): void {
		$errors = array();
		foreach ( $results as $result ) {
			if ( ! $result['ok'] ) {
				$errors[] = $result['error'];
			}
		}
		$state                      = $this->rules->state();
		$old                        = $state['rules'][ $rule_id ] ?? array();
		$state['rules'][ $rule_id ] = array(
			'last_sent' => $real ? time() : (int) ( $old['last_sent'] ?? 0 ),
			'last_try'  => time(),
			'error'     => $errors ? implode( ' ', array_unique( $errors ) ) : '',
		);
		$this->rules->save_state( $state );
	}
}
