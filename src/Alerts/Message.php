<?php
/**
 * Builds what an alert says, for one reader.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

use AryanX\Axle\ActivityLogWithUndo\Access;
use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Each reader gets their own message. What an entry says depends on what that reader may edit:
 * an object they cannot edit shows as "a change you are not allowed to see", with only who and when.
 * A reader the plugin cannot match to a user sees every entry that way.
 */
final class Message {

	/**
	 * Recorders, for descriptions.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the builder.
	 *
	 * @param Registry $registry Recorders.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Most entries a message lists.
	 *
	 * @return int
	 */
	public static function max_entries(): int {
		/**
		 * Filters how many entries one alert message lists. The rest is counted.
		 *
		 * @param int $max Default 20.
		 */
		return max( 1, (int) apply_filters( 'activity_log_with_undo_alert_max_entries', 20 ) );
	}

	/**
	 * Builds the message.
	 *
	 * @param array   $rule    Rule.
	 * @param Entry[] $entries Entries.
	 * @param string  $kind    instant, held, digest or test.
	 * @param int     $viewer  User ID the message is for, 0 when it is not a known user.
	 * @return array{kind: string, subject: string, title: string, count: int, items: array, more: int}
	 */
	public function build( array $rule, array $entries, string $kind, int $viewer ): array {
		$total = count( $entries );
		$items = array();
		foreach ( array_slice( $entries, 0, self::max_entries() ) as $entry ) {
			$items[] = $this->item( $entry, $viewer );
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( 'digest' === $kind ) {
			/* translators: 1: site name, 2: rule name, 3: number of changes. */
			$subject = sprintf( _n( '[%1$s] Daily digest, %2$s: %3$d change', '[%1$s] Daily digest, %2$s: %3$d changes', $total, 'activity-log-with-undo' ), $site, $rule['name'], $total );
		} elseif ( 'test' === $kind ) {
			/* translators: 1: site name, 2: rule name. */
			$subject = sprintf( __( '[%1$s] Test of the alert "%2$s"', 'activity-log-with-undo' ), $site, $rule['name'] );
		} else {
			/* translators: 1: site name, 2: rule name. */
			$subject = sprintf( __( '[%1$s] Alert: %2$s', 'activity-log-with-undo' ), $site, $rule['name'] );
		}

		$message = array(
			'kind'    => $kind,
			'subject' => trim( preg_replace( '/[\r\n]+/', ' ', $subject ) ),
			'title'   => $rule['name'],
			'count'   => $total,
			'items'   => $items,
			'more'    => max( 0, $total - count( $items ) ),
		);

		/**
		 * Filters an alert message before it is sent.
		 *
		 * The message already leaves out what its reader may not see; keep it that way.
		 *
		 * @param array $message kind, subject, title, count, items (id, when, who, what, before, after, url, masked) and more.
		 * @param array $rule    The rule. It holds no webhook URL.
		 * @param int   $viewer  User ID the message is for, 0 for an unknown reader.
		 */
		$filtered = apply_filters( 'activity_log_with_undo_alert_message', $message, $rule, $viewer );

		return (array) $filtered + $message;
	}

	/**
	 * One entry as the reader may see it.
	 *
	 * @param Entry $entry  Entry.
	 * @param int   $viewer User ID, 0 for an unknown reader.
	 * @return array
	 */
	private function item( Entry $entry, int $viewer ): array {
		$item = array(
			'id'     => $entry->id,
			'time'   => gmdate( 'c', $entry->timestamp() ),
			'when'   => wp_date( 'j M Y H:i', $entry->timestamp() ),
			'who'    => $entry->actor_name(),
			'via'    => $entry->actor_via,
			'what'   => '',
			'before' => '',
			'after'  => '',
			'url'    => '',
			'masked' => true,
		);

		$visible = $viewer > 0 && $this->visible( $entry, $viewer );
		if ( ! $visible ) {
			$item['what'] = Access::hidden_text();

			return $item;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $viewer );
		try {
			$what = $this->registry->describe( $entry );
		} finally {
			wp_set_current_user( $previous );
		}

		$item['what']   = self::plain( $what );
		$item['before'] = $this->value( $entry, $entry->before() );
		$item['after']  = $this->value( $entry, $entry->after() );
		$item['url']    = LogPage::url( array( 'entry' => $entry->id ) );
		$item['masked'] = false;

		return $item;
	}

	/**
	 * Whether a user may see an entry: they may open the log and edit what it is about.
	 *
	 * @param Entry $entry  Entry.
	 * @param int   $viewer User ID.
	 * @return bool
	 */
	private function visible( Entry $entry, int $viewer ): bool {
		$user = get_userdata( $viewer );
		if ( ! $user || ! user_can( $user, LogPage::capability() ) ) {
			return false;
		}
		$previous = get_current_user_id();
		wp_set_current_user( $viewer );
		try {
			return Access::can_view( $entry );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Text without markup or entities.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( string $text ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) );
	}

	/**
	 * A short, safe text for a before or after state. Lists of words are listed, other
	 * structures are counted, and values of anything that looks like a secret are left out.
	 *
	 * @param Entry $entry Entry.
	 * @param mixed $state Decoded state.
	 * @return string
	 */
	private function value( Entry $entry, $state ): string {
		if ( null === $state ) {
			return '';
		}
		if ( preg_match( '/(pass|secret|token|key|salt|hash|auth)/i', $entry->object_id . ' ' . $entry->object_label ) ) {
			return __( '(hidden)', 'activity-log-with-undo' );
		}

		if ( is_bool( $state ) ) {
			$text = $state ? __( 'on', 'activity-log-with-undo' ) : __( 'off', 'activity-log-with-undo' );
		} elseif ( is_scalar( $state ) ) {
			$text = (string) $state;
		} elseif ( is_array( $state ) && array_values( $state ) === $state && ! array_filter( $state, 'is_array' ) && ! array_filter( $state, 'is_object' ) ) {
			$text = implode( ', ', array_map( 'strval', $state ) );
		} elseif ( is_array( $state ) ) {
			/* translators: %d: number of fields. */
			$text = sprintf( _n( '%d field', '%d fields', count( $state ), 'activity-log-with-undo' ), count( $state ) );
		} else {
			$text = '';
		}

		/**
		 * Filters how many characters of a before or after value an alert shows.
		 *
		 * @param int $length Default 120.
		 */
		$length = max( 10, (int) apply_filters( 'activity_log_with_undo_alert_value_length', 120 ) );

		return wp_html_excerpt( self::plain( $text ), $length, '…' );
	}

	/**
	 * The message as plain text, for email.
	 *
	 * @param array $message Message from {@see Message::build()}.
	 * @param array $rule    Rule.
	 * @return string
	 */
	public static function to_text( array $message, array $rule ): string {
		$lines = array();
		if ( 'digest' === $message['kind'] ) {
			/* translators: %d: number of changes. */
			$lines[] = sprintf( _n( '%d change matched this rule since the last digest.', '%d changes matched this rule since the last digest.', $message['count'], 'activity-log-with-undo' ), $message['count'] );
		} elseif ( 'test' === $message['kind'] ) {
			$lines[] = __( 'This is a test. Nothing changed on the site.', 'activity-log-with-undo' );
		}
		$lines[] = '';

		foreach ( $message['items'] as $index => $item ) {
			$lines[] = ( $index + 1 ) . '. ' . $item['what'];
			/* translators: 1: user, 2: date and time. */
			$lines[] = '   ' . sprintf( __( 'By %1$s on %2$s', 'activity-log-with-undo' ), $item['who'], $item['when'] );
			if ( ! $item['masked'] && ( '' !== $item['before'] || '' !== $item['after'] ) ) {
				/* translators: %s: value. */
				$lines[] = '   ' . sprintf( __( 'Before: %s', 'activity-log-with-undo' ), '' !== $item['before'] ? $item['before'] : __( '(nothing)', 'activity-log-with-undo' ) );
				/* translators: %s: value. */
				$lines[] = '   ' . sprintf( __( 'After: %s', 'activity-log-with-undo' ), '' !== $item['after'] ? $item['after'] : __( '(nothing)', 'activity-log-with-undo' ) );
			}
			if ( '' !== $item['url'] ) {
				/* translators: %s: link. */
				$lines[] = '   ' . sprintf( __( 'Open it in the log: %s', 'activity-log-with-undo' ), $item['url'] );
			}
			$lines[] = '';
		}
		if ( $message['more'] > 0 ) {
			/* translators: %d: number of changes. */
			$lines[] = sprintf( _n( 'And %d more change. Open the log to see it.', 'And %d more changes. Open the log to see them.', $message['more'], 'activity-log-with-undo' ), $message['more'] );
			$lines[] = LogPage::url();
			$lines[] = '';
		}

		$lines[] = __( 'To undo a change, sign in to the site and press Undo in the log. This message has no undo link on purpose.', 'activity-log-with-undo' );
		/* translators: 1: rule name, 2: link. */
		$lines[] = sprintf( __( 'You get this because of the alert rule "%1$s". Manage alerts: %2$s', 'activity-log-with-undo' ), $rule['name'], LogPage::url( array( 'tab' => 'alerts' ) ) );

		return implode( "\n", $lines );
	}

	/**
	 * The message as JSON data for a webhook.
	 *
	 * @param array $message Message.
	 * @param array $rule    Rule.
	 * @return array
	 */
	public static function to_json( array $message, array $rule ): array {
		return array(
			'type'    => 'activity_log_with_undo.alert',
			'kind'    => $message['kind'],
			'site'    => array(
				'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url(),
			),
			'rule'    => array(
				'id'   => $rule['id'],
				'name' => $rule['name'],
			),
			'count'   => $message['count'],
			'more'    => $message['more'],
			'entries' => array_map(
				static function ( $item ) {
					return array(
						'id'     => $item['id'],
						'time'   => $item['time'],
						'who'    => $item['who'],
						'via'    => $item['via'],
						'what'   => $item['what'],
						'before' => $item['before'],
						'after'  => $item['after'],
						'url'    => $item['url'],
						'masked' => $item['masked'],
					);
				},
				$message['items']
			),
		);
	}

	/**
	 * Escapes text for Slack's mrkdwn.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function slack_escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * The message in Slack's incoming webhook format. Many chat tools accept it too.
	 *
	 * @param array $message Message.
	 * @return array
	 */
	public static function to_slack( array $message ): array {
		$title = self::slack_escape( $message['subject'] );
		$lines = array( '*' . $title . '*' );
		foreach ( $message['items'] as $item ) {
			$line = '• ' . self::slack_escape( $item['what'] ) . ' (' . self::slack_escape( $item['who'] ) . ', ' . self::slack_escape( $item['when'] ) . ')';
			if ( ! $item['masked'] && ( '' !== $item['before'] || '' !== $item['after'] ) ) {
				$line .= "\n    " . self::slack_escape( '' !== $item['before'] ? $item['before'] : '-' ) . ' → ' . self::slack_escape( '' !== $item['after'] ? $item['after'] : '-' );
			}
			if ( '' !== $item['url'] ) {
				$line .= "\n    <" . esc_url_raw( $item['url'] ) . '|' . self::slack_escape( __( 'Open in the log', 'activity-log-with-undo' ) ) . '>';
			}
			$lines[] = $line;
		}
		if ( $message['more'] > 0 ) {
			/* translators: %d: number of changes. */
			$lines[] = self::slack_escape( sprintf( _n( 'And %d more change.', 'And %d more changes.', $message['more'], 'activity-log-with-undo' ), $message['more'] ) );
		}
		$lines[] = '_' . self::slack_escape( __( 'To undo, sign in to the site and use the log.', 'activity-log-with-undo' ) ) . '_';

		$text = implode( "\n", $lines );

		return array(
			'text'   => $text,
			'blocks' => array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2900 ) : substr( $text, 0, 2900 ),
					),
				),
			),
		);
	}
}
