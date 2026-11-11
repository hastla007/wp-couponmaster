<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Refer a friend: every logged-in customer gets a personal code (My Account → Vouchers). A friend uses it on their
 * first order and gets a discount; once that order is completed, the referring customer is rewarded with a coupon
 * (sent by email) or store credit. Own codes cannot be used, and every order rewards at most once.
 */
final class Referrals {
	public const OWNER      = '_vcap_referral_owner';
	public const USER_META  = '_vcap_referral_coupon';
	public const COUNT_META = '_vcap_referral_count';
	public const REWARDED   = '_vcap_referral_rewarded';

	public function __construct( private Settings $settings, private StoreCredit $credit ) {}

	public function register(): void {
		add_action( 'vcap_account_vouchers_top', array( $this, 'account_block' ), 20 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 28, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'reward' ), 30 );
	}

	public function enabled(): bool {
		return $this->settings->yes( 'vcap_referral_enabled' );
	}

	/**
	 * The customer's referral coupon (created on demand); its value follows the current settings.
	 */
	public function coupon_for( int $user_id, bool $create = true ): ?\WC_Coupon {
		if ( $user_id <= 0 ) {
			return null;
		}
		$id     = (int) get_user_meta( $user_id, self::USER_META, true );
		$coupon = $id && 'shop_coupon' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ? new \WC_Coupon( $id ) : null;
		if ( ! $coupon && ! $create ) {
			return null;
		}
		if ( ! $coupon ) {
			try {
				$code = CodeGenerator::unique( array( 'prefix' => (string) apply_filters( 'vcap_referral_code_prefix', 'FRIEND' ), 'length' => 8 ), static fn( $c ) => wc_get_coupon_id_by_code( $c ) > 0 );
			} catch ( \RuntimeException $e ) {
				return null;
			}
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->update_meta_data( self::OWNER, $user_id );
			$coupon->update_meta_data( FirstOrderCoupons::META, 'yes' );
			/* translators: %d: user id */
			$coupon->set_description( sprintf( __( 'Refer a friend – code of customer #%d', 'voucher-captain' ), $user_id ) );
		}
		$type   = 'percent' === $this->settings->get( 'vcap_referral_friend_type' ) ? 'percent' : 'fixed_cart';
		$amount = (float) $this->settings->get( 'vcap_referral_friend_amount' );
		$min    = (float) $this->settings->get( 'vcap_referral_min_spend' );
		if ( ! $coupon->get_id() || $coupon->get_discount_type() !== $type || (float) $coupon->get_amount() !== $amount || (float) $coupon->get_minimum_amount() !== $min ) {
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			$coupon->set_minimum_amount( $min > 0 ? $min : '' );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->save();
			update_user_meta( $user_id, self::USER_META, $coupon->get_id() );
		}
		return $coupon;
	}

	public static function owner( \WC_Coupon $coupon ): int {
		return (int) $coupon->get_meta( self::OWNER );
	}

	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! self::owner( $coupon ) ) {
			return $valid;
		}
		if ( ! $this->enabled() ) {
			throw new \Exception( esc_html__( 'Referral codes are currently not available.', 'voucher-captain' ), 9060 );
		}
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( $object instanceof \WC_Order ) {
			$user  = (int) $object->get_customer_id();
			$email = (string) $object->get_billing_email();
		} else {
			$user  = get_current_user_id();
			$email = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		}
		$owner = get_userdata( self::owner( $coupon ) );
		if ( $user === self::owner( $coupon ) || ( $owner && '' !== $email && strtolower( $email ) === strtolower( (string) $owner->user_email ) ) ) {
			throw new \Exception( esc_html__( 'You cannot use your own referral code.', 'voucher-captain' ), 9061 );
		}
		return $valid;
	}

	/**
	 * Reward the referring customer once the friend's order is completed.
	 */
	public function reward( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->enabled() || $order->get_meta( self::REWARDED ) ) {
			return;
		}
		$owner = 0;
		foreach ( $order->get_coupon_codes() as $code ) {
			$id = wc_get_coupon_id_by_code( $code );
			if ( $id && (int) get_post_meta( $id, self::OWNER, true ) ) {
				$owner = (int) get_post_meta( $id, self::OWNER, true );
				break;
			}
		}
		$user = $owner ? get_userdata( $owner ) : null;
		if ( ! $user || (int) $order->get_customer_id() === $owner ) {
			return;
		}
		$amount = (float) $this->settings->get( 'vcap_referral_reward_amount' );
		if ( $amount <= 0 ) {
			return;
		}
		// Mark first, so a failure later can never reward twice.
		$order->update_meta_data( self::REWARDED, $owner );
		$order->save_meta_data();
		update_user_meta( $owner, self::COUNT_META, (int) get_user_meta( $owner, self::COUNT_META, true ) + 1 );

		if ( 'credit' === $this->settings->get( 'vcap_referral_reward_as' ) && $this->credit->enabled() ) {
			/* translators: %s: order number */
			$result = $this->credit->adjust( $owner, $amount, sprintf( __( 'Referral reward for order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id(), 'credit' );
			if ( ! is_wp_error( $result ) ) {
				/* translators: 1: amount, 2: customer email */
				$order->add_order_note( sprintf( __( 'Referral reward: %1$s store credit for %2$s.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ), $user->user_email ) );
				return;
			}
		}
		$coupon = $this->reward_coupon( $user, $amount, $order );
		if ( ! $coupon ) {
			return;
		}
		/* translators: 1: coupon code, 2: customer email */
		$order->add_order_note( sprintf( __( 'Referral reward: coupon %1$s for %2$s.', 'voucher-captain' ), $coupon->get_code(), $user->user_email ) );
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['VCAP_Email_Referral'] ) ) {
			$emails['VCAP_Email_Referral']->trigger( $coupon, $order, __( 'Refer a friend', 'voucher-captain' ), (string) $user->user_email );
		}
	}

	private function reward_coupon( \WP_User $user, float $amount, \WC_Order $order ): ?\WC_Coupon {
		try {
			$code = CodeGenerator::unique( array( 'prefix' => 'THANKS', 'length' => 10 ), static fn( $c ) => wc_get_coupon_id_by_code( $c ) > 0 );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$days   = (int) $this->settings->get( 'vcap_referral_reward_days' );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		$coupon->set_email_restrictions( array( strtolower( (string) $user->user_email ) ) );
		if ( $days > 0 ) {
			$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );
		}
		/* translators: %s: order number */
		$coupon->set_description( sprintf( __( 'Referral reward for order #%s', 'voucher-captain' ), $order->get_order_number() ) );
		$coupon->update_meta_data( '_vcap_referral_reward_for', $order->get_id() );
		return $coupon->save() ? $coupon : null;
	}

	public function account_block(): void {
		if ( ! $this->enabled() || ! is_user_logged_in() ) {
			return;
		}
		$coupon = $this->coupon_for( get_current_user_id() );
		if ( ! $coupon ) {
			return;
		}
		$code   = strtoupper( $coupon->get_code() );
		$friend = 'percent' === $coupon->get_discount_type() ? wc_format_localized_decimal( (string) (float) $coupon->get_amount() ) . ' %' : wp_strip_all_tags( wc_price( (float) $coupon->get_amount() ) );
		$reward = wp_strip_all_tags( wc_price( (float) $this->settings->get( 'vcap_referral_reward_amount' ) ) );
		$link   = $this->settings->yes( 'vcap_coupon_link' ) ? add_query_arg( (string) $this->settings->get( 'vcap_coupon_link_param' ), rawurlencode( $code ), home_url( '/' ) ) : '';
		$count  = (int) get_user_meta( get_current_user_id(), self::COUNT_META, true );
		echo '<section class="vcap-referral"><h2>' . esc_html__( 'Refer a friend', 'voucher-captain' ) . '</h2><p>';
		/* translators: 1: friend discount, 2: reward */
		echo esc_html( sprintf( __( 'Your friends get %1$s off their first order with your code, and you receive %2$s once their order is completed.', 'voucher-captain' ), $friend, $reward ) );
		echo '</p><p><strong class="vcap-referral-code">' . esc_html( $code ) . '</strong>';
		if ( $link ) {
			echo ' · <input type="text" readonly class="input-text" value="' . esc_attr( $link ) . '" onfocus="this.select()" aria-label="' . esc_attr__( 'Link to share', 'voucher-captain' ) . '" style="max-width:100%;width:28em" />';
		}
		/* translators: %d: number of completed referrals */
		echo '</p><p>' . esc_html( sprintf( _n( '%d friend has ordered so far.', '%d friends have ordered so far.', $count, 'voucher-captain' ), $count ) ) . '</p></section>';
	}
}
