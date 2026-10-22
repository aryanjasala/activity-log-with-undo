<?php
/**
 * Shared set-up for the log tests.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Tests\Unit;

use AryanX\ActivityLogWithUndo\Context;
use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Logger;
use AryanX\ActivityLogWithUndo\Payload;
use AryanX\ActivityLogWithUndo\Settings;
use AryanX\ActivityLogWithUndo\Store;
use Brain\Monkey\Functions;
use Mockery;

require_once __DIR__ . '/Fakes.php';

/**
 * Stubs the WordPress functions the logger needs, and keeps the rows a mocked Store receives.
 */
abstract class LogTestCase extends TestCase {

	/**
	 * Rows inserted into the mocked store, keyed by ID.
	 *
	 * @var array<int, array>
	 */
	protected $rows = array();

	/**
	 * Mocked store.
	 *
	 * @var Store&\Mockery\MockInterface
	 */
	protected $store;

	protected function setUp(): void {
		parent::setUp();

		$this->rows = array();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'maybe_serialize' )->alias(
			static function ( $value ) {
				return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		);
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				return is_string( $value ) && preg_match( '/^[aOs]:\d+:/', $value ) ? unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( $defaults, (array) $args );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_html_excerpt' )->alias(
			static function ( $text, $length ) {
				return substr( (string) $text, 0, $length );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-10-01 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function () {
				$hex = bin2hex( random_bytes( 16 ) );
				return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_privacy_anonymize_ip' )->alias(
			static function ( $ip ) {
				return preg_replace( '/\.\d+$/', '.0', (string) $ip );
			}
		);

		$this->store = Mockery::mock( Store::class );
		$this->store->shouldReceive( 'insert' )->andReturnUsing(
			function ( array $row ) {
				$id                = count( $this->rows ) + 1;
				$this->rows[ $id ] = array( 'id' => $id ) + $row;
				return $id;
			}
		)->byDefault();
		$this->store->shouldReceive( 'update' )->andReturnUsing(
			function ( int $id, array $fields ) {
				$this->rows[ $id ] = $fields + ( $this->rows[ $id ] ?? array() );
				return true;
			}
		)->byDefault();
		$this->store->shouldReceive( 'mark_undone' )->andReturnUsing(
			function ( int $id, int $by ) {
				$this->rows[ $id ]['undone_by'] = $by;
			}
		)->byDefault();
		$this->store->shouldReceive( 'get' )->andReturnUsing(
			function ( int $id ) {
				return isset( $this->rows[ $id ] ) ? Entry::from_row( $this->rows[ $id ] ) : null;
			}
		)->byDefault();
		$this->store->shouldReceive( 'change_set' )->andReturnUsing(
			function ( string $set ) {
				$out = array();
				foreach ( $this->rows as $row ) {
					if ( ( $row['change_set'] ?? '' ) === $set ) {
						$out[] = Entry::from_row( $row );
					}
				}
				return $out;
			}
		)->byDefault();
	}

	/**
	 * A logger writing to the mocked store.
	 *
	 * @return Logger
	 */
	protected function logger(): Logger {
		$settings = new Settings();

		return new Logger( $this->store, $settings, new Context( $settings ) );
	}

	/**
	 * Adds a row to the mocked store and returns it as an entry.
	 *
	 * @param array $row Columns; before and after are raw values.
	 * @return Entry
	 */
	protected function entry( array $row ): Entry {
		$id   = count( $this->rows ) + 1;
		$data = array_merge(
			array(
				'id'          => $id,
				'change_set'  => 'set' . $id,
				'recorder'    => 'fake',
				'event'       => 'fake_changed',
				'object_type' => 'thing',
				'object_id'   => 'a',
				'undoable'    => 1,
			),
			$row
		);
		foreach ( array( 'before', 'after' ) as $which ) {
			if ( array_key_exists( $which, $data ) ) {
				$data[ $which . '_state' ] = Payload::encode( $data[ $which ] );
				unset( $data[ $which ] );
			}
		}
		$this->rows[ $id ] = $data;

		return Entry::from_row( $data );
	}

	/**
	 * Decoded before or after state of a stored row.
	 *
	 * @param int    $id    Row ID.
	 * @param string $which before or after.
	 * @return mixed
	 */
	protected function state( int $id, string $which ) {
		return Payload::decode( $this->rows[ $id ][ $which . '_state' ] ?? null );
	}
}
