<?php
/**
 * Before and after, side by side.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows two states: a word diff for text, a key/value table for arrays.
 */
final class DiffView {

	/**
	 * Text longer than this, or with line breaks, gets a word diff.
	 */
	private const LONG = 80;

	/**
	 * HTML comparing two states.
	 *
	 * @param mixed  $left        Older state.
	 * @param mixed  $right       Newer state.
	 * @param string $left_title  Heading of the older side.
	 * @param string $right_title Heading of the newer side.
	 * @return string
	 */
	public static function compare( $left, $right, string $left_title, string $right_title ): string {
		if ( ! is_array( $left ) && ! is_array( $right ) ) {
			$old = self::plain( $left );
			$new = self::plain( $right );
			if ( strlen( $old ) > self::LONG || strlen( $new ) > self::LONG || false !== strpos( $old . $new, "\n" ) ) {
				$diff = self::text( $old, $new, $left_title, $right_title );
				if ( null !== $diff ) {
					return $diff;
				}
			}
			$left  = array( '' => $left );
			$right = array( '' => $right );
		}
		$keyed = array( '' ) !== array_unique( array_merge( array_keys( (array) $left ), array_keys( (array) $right ) ) );

		$left  = is_array( $left ) ? $left : array();
		$right = is_array( $right ) ? $right : array();
		$keys  = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
		$rows  = '';
		$texts = '';

		foreach ( $keys as $key ) {
			$old = $left[ $key ] ?? null;
			$new = $right[ $key ] ?? null;

			if ( is_string( $old ) && is_string( $new ) && ( strlen( $old ) > self::LONG || strlen( $new ) > self::LONG || false !== strpos( $old . $new, "\n" ) ) ) {
				$diff   = self::text( $old, $new, $left_title, $right_title );
				$texts .= sprintf( '<h4 class="alu-diff-field"><code>%s</code></h4>', esc_html( (string) $key ) ) . ( null === $diff ? '<p class="alu-muted">' . esc_html__( 'No difference.', 'activity-log-with-undo' ) . '</p>' : $diff );
				continue;
			}

			$changed = wp_json_encode( $old ) !== wp_json_encode( $new );
			$rows   .= sprintf(
				'<tr class="%1$s">%2$s<td>%3$s</td><td>%4$s</td></tr>',
				$changed ? 'alu-changed' : '',
				$keyed ? '<th scope="row"><code>' . esc_html( (string) $key ) . '</code></th>' : '',
				self::value( $old ),
				self::value( $new )
			);
		}

		$table = '' === $rows ? '' : sprintf(
			'<table class="widefat striped alu-diff-table"><thead><tr>%4$s<th scope="col">%1$s</th><th scope="col">%2$s</th></tr></thead><tbody>%3$s</tbody></table>',
			esc_html( $left_title ),
			esc_html( $right_title ),
			$rows,
			$keyed ? '<th scope="col"></th>' : ''
		);

		return $table . $texts;
	}

	/**
	 * A word diff of two texts, or null when they are the same.
	 *
	 * @param string $old         Older text.
	 * @param string $newer       Newer text.
	 * @param string $left_title  Heading of the older side.
	 * @param string $right_title Heading of the newer side.
	 * @return string|null
	 */
	private static function text( string $old, string $newer, string $left_title, string $right_title ): ?string {
		if ( $old === $newer ) {
			return null;
		}
		$diff = wp_text_diff(
			$old,
			$newer,
			array(
				'title_left'      => $left_title,
				'title_right'     => $right_title,
				'show_split_view' => true,
			)
		);

		return '' === $diff ? null : '<div class="alu-text-diff">' . $diff . '</div>';
	}

	/**
	 * A scalar as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function plain( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * A value for a table cell.
	 *
	 * @param mixed $value Value.
	 * @return string HTML.
	 */
	private static function value( $value ): string {
		if ( null === $value || '' === $value ) {
			return '<span class="alu-muted">' . esc_html__( '(none)', 'activity-log-with-undo' ) . '</span>';
		}
		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'yes', 'activity-log-with-undo' ) : esc_html__( 'no', 'activity-log-with-undo' );
		}
		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}

		// A list of menu items or posts: show their titles.
		if ( is_array( $value ) && $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) && is_array( $value[0] ) && isset( $value[0]['post_title'] ) ) {
			$items = '';
			foreach ( $value as $item ) {
				$items .= '<li>' . esc_html( '' !== (string) ( $item['post_title'] ?? '' ) ? (string) $item['post_title'] : '#' . (int) ( $item['ID'] ?? 0 ) ) . '</li>';
			}
			return '<ol class="alu-items">' . $items . '</ol>';
		}
		if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
			return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
		}

		return '<pre class="alu-json">' . esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}
}
