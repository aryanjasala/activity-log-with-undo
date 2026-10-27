<?php
/**
 * Encodes the before and after states stored with each entry.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Turns any PHP value into a JSON string and back, without losing it.
 *
 * Values that survive a JSON round trip are stored as `{"v": value}`, so they
 * stay readable in the database and over the API. Anything else (objects,
 * strings that are not UTF-8) is stored as `{"s": "<serialized>"}`, the same
 * way WordPress stores options, and restored with maybe_unserialize().
 */
final class Payload {

	/**
	 * Encodes a value. Null means "no state" and is stored as SQL NULL.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );
		if ( false !== $json && json_decode( $json, true ) === $value ) {
			return (string) wp_json_encode( array( 'v' => $value ) );
		}

		return (string) wp_json_encode( array( 's' => maybe_serialize( $value ) ) );
	}

	/**
	 * Decodes a stored value.
	 *
	 * @param string|null $stored Stored JSON.
	 * @return mixed
	 */
	public static function decode( ?string $stored ) {
		if ( null === $stored || '' === $stored ) {
			return null;
		}

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( array_key_exists( 'v', $data ) ) {
			return $data['v'];
		}
		if ( isset( $data['s'] ) && is_string( $data['s'] ) ) {
			return maybe_unserialize( $data['s'] );
		}

		return null;
	}

	/**
	 * Why a state was left out: it was over the size limit.
	 */
	public const TOO_BIG = 'too_big';

	/**
	 * Why a state was left out: the log table was over its size limit.
	 */
	public const LOG_FULL = 'log_full';

	/**
	 * The marker stored instead of a state that is over the size limit.
	 *
	 * @param int    $bytes  Size of the state that was left out.
	 * @param string $reason TOO_BIG or LOG_FULL.
	 * @return string
	 */
	public static function over_limit( int $bytes, string $reason = self::TOO_BIG ): string {
		$marker = array( 'over_limit' => $bytes );
		if ( self::LOG_FULL === $reason ) {
			$marker['reason'] = self::LOG_FULL;
		}

		return (string) wp_json_encode( $marker );
	}

	/**
	 * Why a state was left out: TOO_BIG, LOG_FULL, or '' when it was kept.
	 *
	 * @param string|null $stored Stored JSON.
	 * @return string
	 */
	public static function left_out_reason( ?string $stored ): string {
		if ( ! self::left_out( $stored ) ) {
			return '';
		}
		$data = json_decode( (string) $stored, true );

		return is_array( $data ) && self::LOG_FULL === ( $data['reason'] ?? '' ) ? self::LOG_FULL : self::TOO_BIG;
	}

	/**
	 * Size of a state that was left out for being too big, or 0.
	 *
	 * @param string|null $stored Stored JSON.
	 * @return int
	 */
	public static function left_out( ?string $stored ): int {
		if ( null === $stored || false === strpos( $stored, 'over_limit' ) ) {
			return 0;
		}
		$data = json_decode( $stored, true );

		return is_array( $data ) && isset( $data['over_limit'] ) ? (int) $data['over_limit'] : 0;
	}

	/**
	 * Whether two states are the same, the way WordPress would read them back.
	 *
	 * The database returns scalars as strings, so 5 and "5", true and "1",
	 * false, null and "" all count as the same.
	 *
	 * @param mixed $a One state.
	 * @param mixed $b The other.
	 * @return bool
	 */
	public static function same( $a, $b ): bool {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Scalars to strings, objects to arrays, recursively, with keys sorted.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ (string) $key ] = self::normalize( $item );
			}
			ksort( $out );
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (string) (int) $value;
		}

		return null === $value ? '' : (string) $value;
	}
}
