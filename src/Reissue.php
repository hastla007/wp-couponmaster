<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * V10: "Reissue code" – a voucher whose code went to a wrong address, was intercepted or reported stolen gets a new
 * code and a new token. The voucher row (order, ledger, remaining value, state, expiry) stays the same; its
 * WooCommerce coupon is replaced by a copy under the new code (restrictions, expiry, remaining uses and value carried
 * over) and the old coupon is deleted, so the old code is no longer redeemable. Old download links stop working: the
 * token changes and the link signature carries the reissue count (Downloads::link_version()).
 *
 * The old code stays known as "previous code" on the new coupon (meta _vcap_previous_code), so refunds of orders
 * that were paid with it still book the value back onto the voucher (never a redemption: lookups for redemption go
 * by the current code only).
 */
final class Reissue {
	/** Ledger event of a reissue (its count is the link version). */
	public const EVENT = 'reissued';
	/** Coupon meta (one row per code) naming earlier codes of the voucher. */
	public const PREVIOUS_CODE_META = '_vcap_previous_code';

	/** Coupon properties copied to the new coupon (usage counts and "used by" start afresh). */
	private const COUPON_PROPS = array(
		'amount',
		'status',
		'date_expires',
		'discount_type',
		'description',
		'individual_use',
		'product_ids',
		'excluded_product_ids',
		'usage_limit_per_user',
		'limit_usage_to_x_items',
		'free_shipping',
		'product_categories',
		'excluded_product_categories',
		'exclude_sale_items',
		'minimum_amount',
		'maximum_amount',
		'email_restrictions',
	);

	public function __construct( private Settings $settings, private VoucherRepository $repo, private CouponManager $coupons ) {}

	/** Why a voucher cannot be reissued ('' when it can). */
	public function refusal( Voucher $voucher ): string {
		if ( 'credit' === $voucher->type ) {
			return __( 'store credit belongs to a customer account and is not passed on by code', 'voucher-captain' );
		}
		switch ( $voucher->status ) {
			case Voucher::STATUS_VOID:
				return __( 'the voucher is void', 'voucher-captain' );
			case Voucher::STATUS_REDEEMED:
				return __( 'the voucher has been redeemed – no value is left to carry over', 'voucher-captain' );
			case Voucher::STATUS_RESERVED:
				return __( 'the voucher is being redeemed right now', 'voucher-captain' );
			case Voucher::STATUS_EXPIRED:
				return __( 'the voucher has expired', 'voucher-captain' );
		}
		if ( $voucher->is_expired() ) {
			return __( 'the voucher has expired', 'voucher-captain' );
		}
		if ( $voucher->is_multi_use() && $voucher->balance <= 0 ) {
			return __( 'the voucher has been redeemed – no value is left to carry over', 'voucher-captain' );
		}
		if ( $this->coupons->open_holds( $voucher ) > 0 || CouponManager::held_by_pending_order( $voucher ) ) {
			return __( 'the voucher is in an unpaid order or reserved for a redemption – reissue it once that is paid or released', 'voucher-captain' );
		}
		if ( $voucher->coupon_id ) {
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			if ( ! $coupon->get_id() || 'trash' === $coupon->get_status() ) {
				return __( 'its WooCommerce coupon is missing', 'voucher-captain' );
			}
		}
		return '';
	}

	/**
	 * Reissue a voucher: new code and token, coupon replaced, history entry and order note.
	 *
	 * @param string $note Optional reason entered by the admin (kept in the history).
	 * @return Voucher|\WP_Error The reissued voucher, or why nothing was changed.
	 */
	public function reissue( Voucher $voucher, string $note = '' ) {
		$fresh  = $this->repo->get( $voucher->id );
		$reason = $fresh ? $this->refusal( $fresh ) : __( 'the voucher was not found', 'voucher-captain' );
		if ( '' !== $reason ) {
			return new \WP_Error( 'vcap_reissue_refused', $reason );
		}
		$old_code      = $fresh->code;
		$old_coupon_id = $fresh->coupon_id;
		try {
			$new_code = $this->new_code( $fresh );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'vcap_reissue_failed', __( 'no new unique code could be generated', 'voucher-captain' ) );
		}
		$new_coupon_id = 0;
		if ( $old_coupon_id ) {
			try {
				$new_coupon_id = $this->copy_coupon( new \WC_Coupon( $old_coupon_id ), $fresh, $new_code );
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'vcap_reissue_failed', wp_strip_all_tags( $e->getMessage() ) );
			}
			if ( ! $new_coupon_id ) {
				return new \WP_Error( 'vcap_reissue_failed', __( 'the new WooCommerce coupon could not be saved', 'voucher-captain' ) );
			}
		}
		$fresh->code  = $new_code;
		$fresh->token = VoucherRepository::new_token();
		if ( $new_coupon_id ) {
			$fresh->coupon_id = $new_coupon_id;
		}
		if ( ! $this->repo->update_columns( $fresh, array( 'code', 'token', 'coupon_id' ) ) ) {
			if ( $new_coupon_id ) {
				wp_delete_post( $new_coupon_id, true );
			}
			$error = Db::fail( 'voucher_reissue', array( 'voucher_id' => $fresh->id ) );
			return new \WP_Error( 'vcap_reissue_failed', $error->get_error_message() );
		}
		$warning = '';
		if ( $old_coupon_id ) {
			// The voucher no longer points to it: CouponManager::protect_coupon() lets it go.
			$deleted = wp_delete_post( $old_coupon_id, true );
			if ( ! $deleted ) {
				// Still not redeemable: a Voucher Captain coupon without its voucher is refused at checkout.
				wp_update_post( array( 'ID' => $old_coupon_id, 'post_status' => 'draft' ) );
				$warning = __( 'The old WooCommerce coupon could not be deleted; it was disabled instead.', 'voucher-captain' );
			}
			if ( class_exists( '\WC_Cache_Helper' ) ) {
				wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . wc_format_coupon_code( $old_code ), 'coupons' );
			}
			if ( $fresh->is_multi_use() ) {
				CouponManager::sync_coupon_amount( $fresh ); // Remaining value read under the row lock.
			}
		}
		$old_masked = Downloads::masked_code( $old_code );
		$new_masked = Downloads::masked_code( $new_code );
		$this->repo->log( $fresh->id, self::EVENT, null, null, Note::make( self::EVENT, array( $old_masked, $new_masked, trim( mb_substr( $note, 0, 200 ) ) ) ) );
		$order = $fresh->order();
		if ( $order instanceof \WC_Order ) {
			/* translators: 1: old code (masked), 2: new code (masked) */
			$order->add_order_note( sprintf( __( 'Voucher %1$s was reissued as %2$s by an admin. The old code and its download links no longer work.', 'voucher-captain' ), $old_masked, $new_masked ) );
		}
		if ( '' !== $warning ) {
			$this->repo->log( $fresh->id, 'note', null, null, $warning );
		}
		$fresh = $this->repo->get( $fresh->id ) ?? $fresh;
		/**
		 * Fires after a voucher was reissued with a new code and token (V10). The old code is no longer redeemable.
		 *
		 * @since 1.2.0
		 * @param Voucher $voucher  The voucher with its new code.
		 * @param string  $old_code The code it had before.
		 */
		do_action( 'vcap_voucher_reissued', $fresh, $old_code );
		return $fresh;
	}

	/** A new code in the format of the voucher's product (prefix, suffix, length) or the shop settings. */
	private function new_code( Voucher $voucher ): string {
		$product = $voucher->product();
		$config  = $product ? ProductConfig::get( $product ) : ProductConfig::DEFAULTS;
		$order   = $voucher->order();
		return CodeGenerator::unique(
			array(
				'prefix'    => '' !== (string) $config['code_prefix'] ? $config['code_prefix'] : $this->settings->get( 'vcap_code_prefix' ),
				'suffix'    => '' !== (string) $config['code_suffix'] ? $config['code_suffix'] : $this->settings->get( 'vcap_code_suffix' ),
				'length'    => (int) ( $config['code_length'] ?: $this->settings->get( 'vcap_code_length' ) ),
				'charset'   => $this->settings->get( 'vcap_code_charset' ),
				'separator' => $this->settings->get( 'vcap_code_separator' ),
				'order_ref' => $order instanceof \WC_Order && $this->settings->yes( 'vcap_code_order_ref' ) ? $order->get_order_number() : '',
				'min_bits'  => (int) apply_filters( 'vcap_min_code_bits', CodeGenerator::MIN_BITS ),
			),
			array( $this->coupons, 'code_exists' )
		);
	}

	/**
	 * A copy of the voucher's coupon under the new code: same restrictions, expiry, value and status (a scheduled
	 * voucher's coupon stays disabled), the uses left of a reusable voucher, and the earlier codes as meta.
	 *
	 * @throws \Throwable When WooCommerce refuses the coupon data (the copy is removed again).
	 */
	private function copy_coupon( \WC_Coupon $old, Voucher $voucher, string $code ): int {
		$new = new \WC_Coupon();
		try {
			$new->set_code( $code );
			foreach ( self::COUPON_PROPS as $prop ) {
				$new->{'set_' . $prop}( $old->{'get_' . $prop}( 'edit' ) );
			}
			$limit = (int) $old->get_usage_limit( 'edit' );
			if ( $limit > 0 ) {
				// Uses already made with the old code count: only the rest is carried over.
				$limit = max( 1, $limit - (int) $old->get_usage_count( 'edit' ) );
			}
			$new->set_usage_limit( $limit );
			foreach ( $old->get_meta_data() as $meta ) {
				$data = $meta->get_data();
				if ( in_array( $data['key'], array( '_vcap_voucher_code', self::PREVIOUS_CODE_META ), true ) ) {
					continue;
				}
				$new->add_meta_data( $data['key'], $data['value'] );
			}
			$new->update_meta_data( '_vcap_voucher_code', $code );
			$previous   = array_map( static fn( $m ) => is_object( $m ) ? (string) $m->value : (string) $m, (array) $old->get_meta( self::PREVIOUS_CODE_META, false ) );
			$previous[] = wc_format_coupon_code( $voucher->code );
			foreach ( array_unique( array_filter( $previous ) ) as $earlier ) {
				$new->add_meta_data( self::PREVIOUS_CODE_META, $earlier );
			}
			return (int) $new->save();
		} catch ( \Throwable $e ) {
			if ( $new->get_id() ) {
				wp_delete_post( $new->get_id(), true );
			}
			throw $e;
		}
	}

	/**
	 * The voucher an earlier (reissued) code belonged to – only for booking back value of orders paid with that
	 * code (refunds, cancellations), never to redeem it.
	 */
	public static function voucher_of_previous_code( VoucherRepository $repo, string $code ): ?Voucher {
		$code = wc_format_coupon_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'        => 'shop_coupon',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'meta_key'         => self::PREVIOUS_CODE_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- only for codes no coupon has any more (refund of an order paid before a reissue).
				'meta_value'       => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? $repo->get_by_coupon( (int) $ids[0] ) : null;
	}
}
