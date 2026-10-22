<?php
/**
 * Classic navigation menus.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Payload;

defined( 'ABSPATH' ) || exit;

/**
 * Saving a menu touches every item one by one, so a snapshot of the whole
 * menu (its name and every item with its settings) is taken before the first
 * change in a request, and one entry is written at the end of the request
 * with the menu as it ended up. Undo puts the snapshot back: it removes items
 * that were added, adds back items that were removed, and resets the rest.
 */
final class MenusRecorder extends BaseRecorder {

	/**
	 * Menu item settings kept in post meta.
	 */
	private const ITEM_META = array( '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url' );

	/**
	 * Item fields kept in the post row.
	 */
	private const ITEM_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'menu_order', 'post_status' );

	/**
	 * Menus as they were before the first change in this request, keyed by menu ID.
	 *
	 * @var array<int, array|null>
	 */
	private array $before = array();

	/**
	 * Menus created in this request.
	 *
	 * @var array<int, bool>
	 */
	private array $created = array();

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'menus';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Menus', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'menu_created' => __( 'Menu created', 'activity-log-with-undo' ),
			'menu_updated' => __( 'Menu changed', 'activity-log-with-undo' ),
			'menu_deleted' => __( 'Menu deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into menu and menu item changes.
	 */
	public function register(): void {
		add_action( 'wp_create_nav_menu', array( $this, 'created' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'before_item_save' ), 10, 2 );
		// A new item is linked to its menu after it is inserted, so this still sees the menu without it.
		add_action( 'wp_add_nav_menu_item', array( $this, 'before_item_added' ) );
		add_action( 'before_delete_post', array( $this, 'before_item_delete' ), 10, 2 );
		add_action( 'edit_terms', array( $this, 'before_menu_edit' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'before_menu_edit' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * A menu was created.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function created( $menu_id ): void {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( $menu ) {
			$this->created[ (int) $menu_id ] = true;
			$this->record( 'menu_created', 'menu', $menu_id, $menu->name, null, array( 'name' => $menu->name ), true );
		}
	}

	/**
	 * Snapshots the menu of an item before the item is saved.
	 *
	 * @param mixed $data    Post data about to be saved.
	 * @param mixed $postarr Data passed to wp_insert_post().
	 * @return mixed Unchanged data.
	 */
	public function before_item_save( $data, $postarr ) {
		if ( is_array( $data ) && 'nav_menu_item' === ( $data['post_type'] ?? '' ) ) {
			$postarr = (array) $postarr;
			$menus   = (array) ( $postarr['tax_input']['nav_menu'] ?? array() );
			if ( ! empty( $postarr['ID'] ) ) {
				$terms = wp_get_object_terms( (int) $postarr['ID'], 'nav_menu', array( 'fields' => 'ids' ) );
				$menus = array_merge( $menus, is_array( $terms ) ? $terms : array() );
			}
			foreach ( $menus as $menu_id ) {
				$this->remember( (int) $menu_id );
			}
		}

		return $data;
	}

	/**
	 * Snapshots a menu when a new item is about to join it.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function before_item_added( $menu_id ): void {
		$this->remember( (int) $menu_id );
	}

	/**
	 * Snapshots the menu of an item before the item is deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public function before_item_delete( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post && 'nav_menu_item' === $post->post_type ) {
			$terms = wp_get_object_terms( $post->ID, 'nav_menu', array( 'fields' => 'ids' ) );
			foreach ( is_array( $terms ) ? $terms : array() as $menu_id ) {
				$this->remember( (int) $menu_id );
			}
		}
	}

	/**
	 * Snapshots a menu before it is renamed or deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function before_menu_edit( $term_id, $taxonomy ): void {
		if ( 'nav_menu' === $taxonomy ) {
			$this->remember( (int) $term_id );
		}
	}

	/**
	 * Keeps the first snapshot of a menu in this request.
	 *
	 * @param int $menu_id Menu ID.
	 */
	private function remember( int $menu_id ): void {
		if ( $menu_id && ! array_key_exists( $menu_id, $this->before ) && ! isset( $this->created[ $menu_id ] ) ) {
			$this->before[ $menu_id ] = self::snapshot( $menu_id );
		}
	}

	/**
	 * Writes one entry per menu that changed in this request.
	 */
	public function flush(): void {
		$before       = $this->before;
		$this->before = array();

		foreach ( $before as $menu_id => $old ) {
			if ( ! $old ) {
				continue;
			}
			$new = self::snapshot( $menu_id );
			if ( Payload::same( $old, $new ) ) {
				continue;
			}
			$this->record( $new ? 'menu_updated' : 'menu_deleted', 'menu', $menu_id, $new ? $new['name'] : $old['name'], $old, $new, true );
		}
	}

	/**
	 * A menu with all its items and their settings.
	 *
	 * @param int $menu_id Menu ID.
	 * @return array|null
	 */
	public static function snapshot( int $menu_id ): ?array {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return null;
		}

		$ids = get_objects_in_term( $menu->term_id, 'nav_menu' );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		_prime_post_caches( $ids, false, true );

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
				continue;
			}
			$item = array( 'ID' => $post->ID );
			foreach ( self::ITEM_FIELDS as $field ) {
				$item[ $field ] = 'menu_order' === $field ? (int) $post->menu_order : (string) $post->$field;
			}
			foreach ( self::ITEM_META as $key ) {
				$item['meta'][ $key ] = get_post_meta( $post->ID, $key, true );
			}
			$items[] = $item;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return array( $a['menu_order'], $a['ID'] ) <=> array( $b['menu_order'], $b['ID'] );
			}
		);

		return array(
			'name'        => $menu->name,
			'description' => $menu->description,
			'items'       => $items,
		);
	}

	/**
	 * Whether the menu can be put back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		if ( 'menu_created' === $entry->event && ! wp_get_nav_menu_object( (int) $entry->object_id ) ) {
			return self::refuse( __( 'The menu no longer exists.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The menu as it is now.
	 *
	 * @param Entry $entry Entry.
	 * @return array|null
	 */
	public function current_state( Entry $entry ): ?array {
		if ( 'menu_created' === $entry->event ) {
			$menu = wp_get_nav_menu_object( (int) $entry->object_id );
			return $menu ? array( 'name' => $menu->name ) : null;
		}

		return self::snapshot( (int) $entry->object_id );
	}

	/**
	 * Deletes a new menu, or puts the snapshot back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function undo( Entry $entry ) {
		if ( 'menu_created' === $entry->event ) {
			return self::result( wp_delete_nav_menu( (int) $entry->object_id ), __( 'WordPress did not delete the menu.', 'activity-log-with-undo' ) );
		}

		$snapshot = $entry->before();

		return is_array( $snapshot ) ? self::restore( (int) $entry->object_id, $snapshot ) : self::refuse( __( 'No copy of the menu was kept.', 'activity-log-with-undo' ) );
	}

	/**
	 * Makes a menu match a snapshot.
	 *
	 * @param int   $menu_id  Menu ID. A new menu is created when it no longer exists.
	 * @param array $snapshot Menu snapshot.
	 * @return true|\WP_Error
	 */
	public static function restore( int $menu_id, array $snapshot ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( (string) $snapshot['name'] );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
		} elseif ( (string) $snapshot['name'] !== $menu->name || (string) ( $snapshot['description'] ?? '' ) !== $menu->description ) {
			$result = wp_update_nav_menu_object(
				$menu_id,
				wp_slash(
					array(
						'menu-name'   => (string) $snapshot['name'],
						'description' => (string) ( $snapshot['description'] ?? '' ),
					)
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$current = array();
		foreach ( (array) ( self::snapshot( (int) $menu_id )['items'] ?? array() ) as $item ) {
			$current[ (int) $item['ID'] ] = $item;
		}
		$wanted = array();
		foreach ( (array) $snapshot['items'] as $item ) {
			$wanted[ (int) $item['ID'] ] = $item;
		}

		foreach ( array_diff_key( $current, $wanted ) as $id => $item ) {
			wp_delete_post( $id, true );
		}

		// Items that are gone get new IDs; children point at their parent's new ID.
		$ids = array();
		foreach ( $wanted as $old_id => $item ) {
			if ( isset( $current[ $old_id ] ) ) {
				$ids[ $old_id ] = $old_id;
				continue;
			}
			$new_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'nav_menu_item',
						'post_status'  => $item['post_status'],
						'post_title'   => $item['post_title'],
						'post_content' => $item['post_content'],
						'post_excerpt' => $item['post_excerpt'],
						'menu_order'   => $item['menu_order'],
						'tax_input'    => array( 'nav_menu' => array( (int) $menu_id ) ),
					)
				),
				true
			);
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			wp_set_object_terms( (int) $new_id, array( (int) $menu_id ), 'nav_menu' );
			$ids[ $old_id ] = (int) $new_id;
		}

		foreach ( $wanted as $old_id => $item ) {
			$id   = $ids[ $old_id ];
			$post = get_post( $id );
			$row  = array();
			foreach ( self::ITEM_FIELDS as $field ) {
				if ( $post && (string) $post->$field !== (string) $item[ $field ] ) {
					$row[ $field ] = $item[ $field ];
				}
			}
			if ( $row ) {
				wp_update_post( wp_slash( array( 'ID' => $id ) + $row ) );
			}
			foreach ( (array) $item['meta'] as $key => $value ) {
				if ( '_menu_item_menu_item_parent' === $key && isset( $ids[ (int) $value ] ) ) {
					$value = (string) $ids[ (int) $value ];
				}
				update_post_meta( $id, (string) $key, wp_slash( $value ) );
			}
		}

		return true;
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$name = '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;

		switch ( $entry->event ) {
			case 'menu_created':
				/* translators: %s: menu name. */
				return sprintf( __( 'Created the menu "%s"', 'activity-log-with-undo' ), $name );
			case 'menu_deleted':
				/* translators: %s: menu name. */
				return sprintf( __( 'Deleted the menu "%s"', 'activity-log-with-undo' ), $name );
			default:
				$old = count( (array) ( ( (array) $entry->before() )['items'] ?? array() ) );
				$new = count( (array) ( ( (array) $entry->after() )['items'] ?? array() ) );
				/* translators: 1: menu name, 2: items before, 3: items after. */
				return sprintf( __( 'Changed the menu "%1$s" (%2$d items before, %3$d after)', 'activity-log-with-undo' ), $name, $old, $new );
		}
	}
}
