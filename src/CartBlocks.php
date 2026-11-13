<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Cart and checkout blocks: what the classic cart prints with PHP hooks (voucher payment rows "Voucher CODE",
 * the note on paying coupons, remaining balances of gift cards, the "use store credit" hint, the warning that a
 * single-use voucher worth more than the cart loses its remaining value) is exposed through
 * the Store API cart endpoint (namespace "voucher-captain") and shown by assets/js/cart-blocks.js
 * (Compat\CartBlocksIntegration). The texts come translated from PHP.
 */
final class CartBlocks {
	public const NAMESPACE = 'voucher-captain';

	private bool $extended = false;

	public function __construct( private VoucherRepository $repo, private PaymentVouchers $payments, private StoreCredit $credit ) {}

	public function register(): void {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->extend_store_api();
		}
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
	}

	public function extend_store_api(): void {
		if ( $this->extended || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) {
			return;
		}
		$this->extended = true;
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/** @param object $registry Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry */
	public function register_integration( $registry ): void {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) && interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			$registry->register( new Compat\CartBlocksIntegration() );
		}
	}

	/**
	 * Store API cart data: extensions["voucher-captain"]. Amounts ("amount") are strings in minor units like the
	 * Store API's own prices; "formatted" and "text" are ready to show.
	 */
	public function cart_data(): array {
		$data = array(
			'payments'       => array(),
			'paying_coupons' => array(),
			'coupon_note'    => __( 'pays as voucher payment (see below)', 'voucher-captain' ),
			'balances'       => array(),
			'store_credit'   => null,
			'forfeits'       => array(),
		);
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return $data;
		}
		foreach ( $this->payments->cart_payments() as $code => $amount ) {
			$data['payments'][]       = array(
				'code'      => (string) $code,
				/* translators: %s: voucher code */
				'label'     => sprintf( __( 'Voucher %s', 'voucher-captain' ), strtoupper( (string) $code ) ),
				'amount'    => (string) (int) round( (float) $amount * ( 10 ** wc_get_price_decimals() ) ),
				'formatted' => self::plain_price( -(float) $amount ),
			);
			$data['paying_coupons'][] = (string) $code;
		}
		foreach ( $cart->get_applied_coupons() as $code ) {
			$voucher = $this->repo->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->repo->get_by_code( $code );
			if ( $voucher && $voucher->is_multi_use() ) {
				$data['balances'][] = array(
					'code'      => wc_format_coupon_code( $code ),
					/* translators: %s: voucher code */
					'label'     => sprintf( __( 'Voucher %s balance', 'voucher-captain' ), $voucher->code ),
					'formatted' => self::plain_price( (float) $voucher->balance, (string) $voucher->currency ),
				);
			}
		}
		// Same rows and text as the classic cart totals (ProductChecks::residual_rows()), as plain text.
		foreach ( ProductChecks::residual_losses( $cart ) as $loss ) {
			$lost               = (float) $loss['value'] - (float) $loss['used'];
			$data['forfeits'][] = array(
				'code'      => wc_format_coupon_code( (string) $loss['code'] ),
				'amount'    => (string) (int) round( $lost * ( 10 ** wc_get_price_decimals() ) ),
				'formatted' => self::plain_price( $lost, (string) $loss['currency'] ),
				'text'      => ProductChecks::residual_notice( $loss, true ),
			);
		}
		if ( $this->credit->enabled() && is_user_logged_in() ) {
			$account = $this->credit->account( get_current_user_id() );
			if ( $account && $account->balance > 0 && ! $cart->has_discount( $account->code ) ) {
				$data['store_credit'] = array(
					/* translators: 1: balance, 2: link */
					'text'       => trim( sprintf( __( 'You have %1$s store credit. %2$s', 'voucher-captain' ), self::plain_price( (float) $account->balance, (string) $account->currency ), '' ) ),
					'link_label' => __( 'Use store credit', 'voucher-captain' ),
					'url'        => $this->credit->apply_url(),
				);
			}
		}
		return $data;
	}

	public function cart_schema(): array {
		$string = array(
			'type'     => 'string',
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
		);
		$rows   = static fn( array $properties ) => array(
			'type'     => 'array',
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
			'items'    => array(
				'type'       => 'object',
				'properties' => $properties,
			),
		);
		return array(
			'payments'       => $rows(
				array(
					'code'      => $string,
					'label'     => $string,
					'amount'    => $string,
					'formatted' => $string,
				)
			),
			'paying_coupons' => array(
				'type'     => 'array',
				'context'  => array( 'view', 'edit' ),
				'readonly' => true,
				'items'    => array( 'type' => 'string' ),
			),
			'coupon_note'    => $string,
			'balances'       => $rows(
				array(
					'code'      => $string,
					'label'     => $string,
					'formatted' => $string,
				)
			),
			'store_credit'   => array(
				'type'       => array( 'object', 'null' ),
				'context'    => array( 'view', 'edit' ),
				'readonly'   => true,
				'properties' => array(
					'text'       => $string,
					'link_label' => $string,
					'url'        => $string,
				),
			),
			'forfeits'       => $rows(
				array(
					'code'      => $string,
					'amount'    => $string,
					'formatted' => $string,
					'text'      => $string,
				)
			),
		);
	}

	private static function plain_price( float $amount, string $currency = '' ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, '' !== $currency ? array( 'currency' => $currency ) : array() ) ), ENT_QUOTES, 'UTF-8' );
	}
}
