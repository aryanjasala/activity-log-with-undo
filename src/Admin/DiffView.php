<?php
/**
 * Before and after, side by side.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows two states: a word diff for text, a key/value table for arrays.
 *
 * Field keys are shown with names people know ("Title", not post_title).
 * Code, markup and JSON read left to right, also on right-to-left sites;
 * plain values follow their own direction.
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
				$texts .= sprintf( '<h4 class="alu-diff-field">%s</h4>', self::field( (string) $key ) ) . ( null === $diff ? '<p class="alu-muted">' . esc_html__( 'No difference.', 'activity-log-with-undo' ) . '</p>' : $diff );
				continue;
			}

			$changed = wp_json_encode( $old ) !== wp_json_encode( $new );
			$rows   .= sprintf(
				'<tr class="%1$s">%2$s<td dir="auto">%3$s</td><td dir="auto">%4$s</td></tr>',
				$changed ? 'alu-changed' : '',
				$keyed ? '<th scope="row">' . self::field( (string) $key ) . '</th>' : '',
				self::value( $old, (string) $key ),
				self::value( $new, (string) $key )
			);
		}

		$table = '' === $rows ? '' : sprintf(
			'<table class="widefat striped alu-diff-table"><thead><tr>%4$s<th scope="col">%1$s</th><th scope="col">%2$s</th></tr></thead><tbody>%3$s</tbody></table>',
			esc_html( $left_title ),
			esc_html( $right_title ),
			$rows,
			$keyed ? '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Field', 'activity-log-with-undo' ) . '</span></th>' : ''
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

		// Markup reads left to right everywhere; prose follows its own direction.
		$dir = preg_match( '/[<>{}]/', $old . $newer ) ? 'ltr' : 'auto';

		return '' === $diff ? null : '<div class="alu-text-diff" dir="' . $dir . '">' . $diff . '</div>';
	}

	/**
	 * Names of the fields recorders store, for people.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function field_labels(): array {
		$labels = array(
			'post_title'        => __( 'Title', 'activity-log-with-undo' ),
			'post_content'      => __( 'Content', 'activity-log-with-undo' ),
			'post_excerpt'      => __( 'Excerpt', 'activity-log-with-undo' ),
			'post_status'       => __( 'Status', 'activity-log-with-undo' ),
			'post_name'         => __( 'Slug', 'activity-log-with-undo' ),
			'post_parent'       => __( 'Parent', 'activity-log-with-undo' ),
			'post_author'       => __( 'Author', 'activity-log-with-undo' ),
			'menu_order'        => __( 'Order', 'activity-log-with-undo' ),
			'_thumbnail_id'     => __( 'Featured image', 'activity-log-with-undo' ),
			'_wp_page_template' => __( 'Template', 'activity-log-with-undo' ),
			'name'              => __( 'Name', 'activity-log-with-undo' ),
			'slug'              => __( 'Slug', 'activity-log-with-undo' ),
			'description'       => __( 'Description', 'activity-log-with-undo' ),
			'parent'            => __( 'Parent', 'activity-log-with-undo' ),
			'meta'              => __( 'Term meta', 'activity-log-with-undo' ),
			'objects'           => __( 'Posts', 'activity-log-with-undo' ),
			'objects_count'     => __( 'Number of posts', 'activity-log-with-undo' ),
			'children'          => __( 'Child terms', 'activity-log-with-undo' ),
			'items'             => __( 'Menu items', 'activity-log-with-undo' ),
			'user_login'        => __( 'Username', 'activity-log-with-undo' ),
			'display_name'      => __( 'Display name', 'activity-log-with-undo' ),
			'user_email'        => __( 'Email', 'activity-log-with-undo' ),
			'user_url'          => __( 'Website', 'activity-log-with-undo' ),
			'roles'             => __( 'Roles', 'activity-log-with-undo' ),
			'stylesheet'        => __( 'Theme', 'activity-log-with-undo' ),
			'version'           => __( 'Version', 'activity-log-with-undo' ),
			'url'               => __( 'Address', 'activity-log-with-undo' ),
		);

		/**
		 * Filters the names shown for stored fields in the before and after table.
		 *
		 * Fields without a name show their key.
		 *
		 * @param array<string, string> $labels Key => label.
		 */
		/**
		 * Whatever the filter returned.
		 *
		 * @var array<mixed> $filtered
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_field_labels', $labels );

		return array_map( 'strval', array_filter( $filtered, 'is_scalar' ) );
	}

	/**
	 * A field name for a row header: its label, or the key as code.
	 *
	 * @param string $key Field key.
	 * @return string HTML.
	 */
	private static function field( string $key ): string {
		$labels = self::field_labels();
		if ( isset( $labels[ $key ] ) && '' !== $labels[ $key ] ) {
			return esc_html( $labels[ $key ] );
		}

		return '<code dir="ltr">' . esc_html( $key ) . '</code>';
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
	 * @param mixed  $value Value.
	 * @param string $key   Its field key, if any.
	 * @return string HTML.
	 */
	private static function value( $value, string $key = '' ): string {
		if ( null === $value || '' === $value ) {
			return '<span class="alu-muted">' . esc_html__( '(none)', 'activity-log-with-undo' ) . '</span>';
		}
		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'yes', 'activity-log-with-undo' ) : esc_html__( 'no', 'activity-log-with-undo' );
		}
		if ( 'post_status' === $key && is_string( $value ) && function_exists( 'get_post_status_object' ) ) {
			$status = get_post_status_object( $value );
			if ( $status && ! empty( $status->label ) ) {
				return esc_html( (string) $status->label );
			}
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

		return '<pre class="alu-json" dir="ltr">' . esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}
}
