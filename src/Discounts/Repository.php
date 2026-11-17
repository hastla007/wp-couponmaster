<?php
namespace VCAP\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Discount campaigns are stored as a custom post type: published = active, draft = inactive,
 * "Order" (menu_order) = priority, rules as JSON in post meta.
 */
final class Repository {
	public const POST_TYPE = 'vcap_discount';
	public const META      = '_vcap_rules';

	/** @var array<int, array|null> */
	private array $cache = array();

	public function flush(): void {
		$this->cache = array();
	}

	/** Keep the per-request cache in sync with edits made during the same request. */
	public function register(): void {
		add_action( 'clean_post_cache', array( $this, 'flush' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), 10, 3 );
		}
	}

	public function meta_changed( $meta_ids, $object_id, $meta_key ): void {
		if ( self::META === $meta_key ) {
			$this->flush();
		}
	}

	/**
	 * A published campaign.
	 *
	 * @return array{id:int,title:string,priority:int,rules:array}|null
	 */
	public function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $id, $this->cache ) ) {
			$post = get_post( $id );
			$this->cache[ $id ] = $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ? self::build( $post ) : null;
		}
		return $this->cache[ $id ];
	}

	/**
	 * A campaign in any status (for recalculating orders that already carry its discount).
	 */
	public function get_any( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? self::build( $post ) : null;
	}

	/**
	 * @param string|null $rules Stored rules JSON when already loaded (null: read from post meta).
	 */
	public static function build( \WP_Post $post, ?string $rules = null ): array {
		$raw = json_decode( null === $rules ? (string) get_post_meta( $post->ID, self::META, true ) : $rules, true );
		return array(
			'id'       => (int) $post->ID,
			'title'    => '' !== $post->post_title ? $post->post_title : __( 'Promotion', 'voucher-captain' ),
			'priority' => (int) $post->menu_order,
			'rules'    => Engine::normalize( is_array( $raw ) ? $raw : array() ),
		);
	}

	/** @return array<int, array{id:int,title:string,priority:int,rules:array}> */
	public function active(): array {
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				// Only the rules are needed: no meta or term cache priming for every promotion on every cart.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$rules = self::rules_of( wp_list_pluck( $posts, 'ID' ) );
		$out   = array();
		foreach ( $posts as $post ) {
			$campaign                   = self::build( $post, $rules[ $post->ID ] ?? '' );
			$this->cache[ $post->ID ] = $campaign;
			$out[]                      = $campaign;
		}
		return $out;
	}

	/**
	 * Stored rules of several promotions in one query.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, string> id => rules JSON
	 */
	private static function rules_of( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$in}) ORDER BY meta_id", self::META ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer ids.
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[ (int) $row['post_id'] ] ??= (string) $row['meta_value'];
		}
		return $out;
	}
}
