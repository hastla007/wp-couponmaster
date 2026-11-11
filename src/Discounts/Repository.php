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

	public static function build( \WP_Post $post ): array {
		$raw = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
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
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$campaign                   = self::build( $post );
			$this->cache[ $post->ID ] = $campaign;
			$out[]                      = $campaign;
		}
		return $out;
	}
}
