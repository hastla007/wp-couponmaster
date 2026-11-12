<?php
namespace VCAP\Compat;

use VCAP\Campaigns;
use VCAP\ProductConfig;

defined( 'ABSPATH' ) || exit;

/**
 * WP Affiliate Captain (integration contract v1): assign whole code campaigns to a partner.
 *
 * WP Affiliate Captain records WooCommerce orders itself and asks "which partner does this coupon belong
 * to?" through the `wpac_coupon_affiliate` filter. This adapter answers for coupons generated in a Voucher
 * Captain campaign: Vouchers → Generate codes → Campaigns → Partner. A coupon that was linked to a partner
 * directly in Affiliate Captain keeps priority (it answers first, at priority 10).
 *
 * Only public functions and hooks of Affiliate Captain are used; no tables are read. The partner is stored
 * as partner code and resolved on every order, so a paused or ended partner no longer earns commission.
 * Purchased vouchers and gift cards (`_vcap_voucher`) are means of payment and never map to a partner.
 */
final class AffiliateCaptain {
	public const OPTION   = 'vcap_affiliate_campaigns';
	public const SOURCE   = 'voucher-captain';
	public const CONTRACT = '1.0.0';
	/** Capability of WP Affiliate Captain needed to assign campaign partners. */
	public const CAPABILITY = 'wpac_manage_affiliates';

	private bool $done = false;

	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		$this->init();
	}

	public function init(): void {
		if ( $this->done || ! self::available() ) {
			return;
		}
		$this->done = true;
		add_filter( 'wpac_coupon_affiliate', array( $this, 'coupon_affiliate' ), 20, 2 );
		add_filter( 'wpac_registered_integrations', array( $this, 'register_integration' ) );
		add_filter( 'wpac_commission_base', array( $this, 'commission_base' ), 10, 3 );
		add_action( 'vcap_campaigns_table_header', array( $this, 'table_header' ) );
		add_action( 'vcap_campaigns_table_cell', array( $this, 'table_cell' ) );
		add_action( 'admin_post_vcap_campaign_partner', array( $this, 'save_partner' ) );
	}

	/** Affiliate Captain is active with a contract of the same major version. */
	public static function available(): bool {
		if ( ! function_exists( 'wpac_contract_version' ) || ! function_exists( 'wpac_resolve_partner_code' ) ) {
			return false;
		}
		return strtok( (string) wpac_contract_version(), '.' ) === strtok( self::CONTRACT, '.' );
	}

	/** @return array<string,string> campaign name ⇒ partner code */
	public static function assignments(): array {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? array_filter( array_map( 'strval', $map ) ) : array();
	}

	public static function partner_for_campaign( string $campaign ): string {
		return self::assignments()[ $campaign ] ?? '';
	}

	/**
	 * Saves (or removes, with an empty code) the partner of a campaign.
	 *
	 * @return true|\WP_Error
	 */
	public static function assign( string $campaign, string $partner_code ) {
		$campaign = Campaigns::clean_name( $campaign );
		$code     = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', $partner_code ) );
		if ( '' === $campaign ) {
			return new \WP_Error( 'vcap_affiliate_campaign', __( 'Unknown campaign.', 'voucher-captain' ) );
		}
		$map = self::assignments();
		if ( '' === $code ) {
			unset( $map[ $campaign ] );
		} else {
			if ( ! self::available() || ! wpac_resolve_partner_code( $code, array( 'context' => 'admin', 'source' => self::SOURCE ) ) ) {
				return new \WP_Error( 'vcap_affiliate_code', __( 'This partner code is unknown or not active in WP Affiliate Captain.', 'voucher-captain' ) );
			}
			$map[ $campaign ] = substr( $code, 0, 64 );
		}
		update_option( self::OPTION, $map, false );
		return true;
	}

	/**
	 * `wpac_coupon_affiliate`: coupon of an assigned campaign → partner.
	 *
	 * @param array|null $resolved Answer of an earlier callback (e.g. a direct coupon link).
	 */
	public function coupon_affiliate( $resolved, $coupon ) {
		if ( null !== $resolved || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return $resolved;
		}
		$code = wc_format_coupon_code( (string) $coupon );
		$id   = '' !== $code ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		if ( ! $id || 'yes' === get_post_meta( $id, '_vcap_voucher', true ) ) {
			return $resolved;
		}
		$campaign = (string) get_post_meta( $id, Campaigns::META, true );
		$partner  = '' !== $campaign ? self::partner_for_campaign( $campaign ) : '';
		if ( '' === $partner ) {
			return $resolved;
		}
		$found = wpac_resolve_partner_code( $partner, array( 'context' => 'coupon', 'coupon' => $code, 'source' => self::SOURCE ) );
		return $found ? $found : $resolved;
	}

	/**
	 * `wpac_commission_base`: buying a voucher or gift card is a prepayment; the money is earned when it is
	 * redeemed (and the redeeming order is then reduced by the voucher). By default the partner who brought
	 * the voucher buyer is paid on the voucher sale; return false from `vcap_affiliate_commission_on_voucher_sales`
	 * to pay nothing on voucher products instead.
	 */
	public function commission_base( $base, $item, $sale ) {
		if ( ! is_array( $item ) || ! is_array( $sale ) || 'woocommerce' !== ( $sale['source'] ?? '' ) || (int) $base <= 0 ) {
			return $base;
		}
		if ( apply_filters( 'vcap_affiliate_commission_on_voucher_sales', true ) ) {
			return $base;
		}
		$product = (int) ( ! empty( $item['product_id'] ) ? $item['product_id'] : 0 );
		return $product && ProductConfig::is_voucher( $product ) ? 0 : $base;
	}

	public function register_integration( $list ) {
		$list                 = is_array( $list ) ? $list : array();
		$list[ self::SOURCE ] = array(
			'name'     => 'Voucher Captain',
			'version'  => defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '',
			'contract' => self::CONTRACT,
			'active'   => true,
		);
		return $list;
	}

	public function table_header(): void {
		echo '<th>' . esc_html__( 'Partner (WP Affiliate Captain)', 'voucher-captain' ) . '</th>';
	}

	public function table_cell( string $campaign ): void {
		$code = self::partner_for_campaign( $campaign );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			echo '<td><code>' . esc_html( '' !== $code ? $code : '—' ) . '</code></td>';
			return;
		}
		?>
		<td>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-inline-form">
				<input type="hidden" name="action" value="vcap_campaign_partner" />
				<input type="hidden" name="campaign" value="<?php echo esc_attr( $campaign ); ?>" />
				<?php wp_nonce_field( 'vcap_campaign_partner' ); ?>
				<label class="screen-reader-text" for="<?php echo esc_attr( 'vcap-partner-' . md5( $campaign ) ); ?>"><?php esc_html_e( 'Partner code', 'voucher-captain' ); ?></label>
				<input type="text" id="<?php echo esc_attr( 'vcap-partner-' . md5( $campaign ) ); ?>" name="partner_code" value="<?php echo esc_attr( $code ); ?>" class="regular-text code" maxlength="64" placeholder="<?php esc_attr_e( 'Partner code', 'voucher-captain' ); ?>" />
				<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'voucher-captain' ); ?></button>
			</form>
		</td>
		<?php
	}

	public function save_partner(): void {
		check_admin_referer( 'vcap_campaign_partner' );
		// Routing commissions is a partner-program decision: WP Affiliate Captain's own
		// capability is required (voucher or shop rights alone are not enough).
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$campaign = wp_unslash( (string) ( $_POST['campaign'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Cleaned in assign().
		$code     = sanitize_text_field( wp_unslash( (string) ( $_POST['partner_code'] ?? '' ) ) );
		$before   = self::partner_for_campaign( Campaigns::clean_name( $campaign ) );
		$result   = self::assign( $campaign, $code );
		if ( ! is_wp_error( $result ) && $before !== strtoupper( $code ) ) {
			/**
			 * Fires after the partner of a voucher campaign was changed (for audit logs).
			 *
			 * @param string $campaign Campaign.
			 * @param string $before   Previous partner code ('' = none).
			 * @param string $code     New partner code ('' = removed).
			 * @param int    $user_id  Acting user.
			 */
			do_action( 'vcap_affiliate_campaign_partner_changed', Campaigns::clean_name( $campaign ), $before, strtoupper( $code ), get_current_user_id() );
		}
		set_transient(
			'vcap_generate_report_' . get_current_user_id(),
			array(
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
				'message' => is_wp_error( $result ) ? '' : ( '' === $code ? __( 'Partner removed from the campaign.', 'voucher-captain' ) : __( 'Partner saved. New orders with codes of this campaign are credited to the partner.', 'voucher-captain' ) ),
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=vcap-generate' ) );
		exit;
	}
}
