<?php
namespace VCAP\Admin;

use VCAP\ProductConfig;
use VCAP\TemplatePostType;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * M12-19: Vouchers → New voucher. Issues a voucher or gift card without an order (goodwill, prize, sale at the
 * till) through Issuer::issue_standalone(): same codes, coupon, ledger and email delivery as a purchased one. The
 * value is created out of nothing, so the page needs "vcap_manage_vouchers" and the right to raise values
 * (VoucherAdmin::can_increase()). Such vouchers are marked as not sold ("paid" false) for the tax logic.
 * Shared with "wp vcap issue --amount=…" (CLI\Command::issue()).
 */
final class IssueVoucher {
	public const SLUG = 'vcap-issue';

	public function register(): void {
		add_action( 'admin_post_vcap_issue_voucher', array( $this, 'handle' ) );
	}

	/** Who may open the page and issue: manages vouchers and may create value. */
	public static function can(): bool {
		return ( current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ) ) && VoucherAdmin::can_increase(); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
	}

	/**
	 * Issues one voucher from form or command-line input. $in: product_id, template_id, amount, type
	 * (voucher|giftcard), expiry ('' = product or global default, 'never', or a local date Y-m-d), recipient_name,
	 * recipient_email, buyer_email, message, send (bool), reason, source.
	 *
	 * @return Voucher|\WP_Error
	 */
	public static function issue( array $in ) {
		$product_id = absint( $in['product_id'] ?? 0 );
		$raw_amount = trim( (string) ( $in['amount'] ?? '' ) );
		$args       = array(
			'recipient_name'  => sanitize_text_field( (string) ( $in['recipient_name'] ?? '' ) ),
			'recipient_email' => sanitize_email( (string) ( $in['recipient_email'] ?? '' ) ),
			'buyer_email'     => sanitize_email( (string) ( $in['buyer_email'] ?? '' ) ),
			'message'         => sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ),
			'send'            => ! empty( $in['send'] ),
			'source'          => sanitize_key( (string) ( $in['source'] ?? 'admin' ) ),
			'reason'          => sanitize_text_field( (string) ( $in['reason'] ?? '' ) ),
			// Not sold: no money was received for it (goodwill, prize). The tax logic decides what that means.
			'paid'            => false,
		);
		$email_raw = trim( (string) ( $in['recipient_email'] ?? '' ) );
		if ( '' !== $email_raw && ! is_email( $args['recipient_email'] ) ) {
			/* translators: %s: what was entered */
			return new \WP_Error( 'vcap_invalid_email', sprintf( __( '"%s" is not a valid email address.', 'voucher-captain' ), $email_raw ) );
		}
		if ( $product_id ) {
			$args['product_id'] = $product_id;
		} else {
			$type = sanitize_key( (string) ( $in['type'] ?? 'giftcard' ) );
			if ( ! in_array( $type, array( 'voucher', 'giftcard' ), true ) ) {
				return new \WP_Error( 'vcap_invalid_args', __( 'Please choose "Voucher" or "Gift card" as type, or a voucher product.', 'voucher-captain' ) );
			}
			$args['type']  = $type;
			$args['usage'] = ProductConfig::default_usage( $type );
		}
		if ( '' !== $raw_amount ) {
			// "35,50" is a decimal comma also in a shop set to a decimal point (a typo would create 3550).
			$amount = preg_match( '/^\d+,\d{1,2}$/', $raw_amount ) ? (float) str_replace( ',', '.', $raw_amount ) : (float) wc_format_decimal( $raw_amount );
			if ( $amount <= 0 ) {
				return new \WP_Error( 'vcap_invalid_amount', __( 'Please give a voucher value greater than zero.', 'voucher-captain' ) );
			}
			$args['amount'] = $amount;
		} elseif ( ! $product_id ) {
			return new \WP_Error( 'vcap_invalid_amount', __( 'Please give a voucher value greater than zero.', 'voucher-captain' ) );
		}
		$template = absint( $in['template_id'] ?? 0 );
		if ( $template ) {
			if ( TemplatePostType::POST_TYPE !== get_post_type( $template ) ) {
				return new \WP_Error( 'vcap_invalid_args', __( 'This PDF template does not exist.', 'voucher-captain' ) );
			}
			$args['template_id'] = $template;
		}
		$expiry = trim( (string) ( $in['expiry'] ?? '' ) );
		if ( 'never' === $expiry ) {
			$args['expires_at'] = null;
		} elseif ( '' !== $expiry ) {
			$parsed = VoucherAdmin::parse_expiry( $expiry, false, '' );
			if ( ! $parsed['ok'] || null === $parsed['date'] || $parsed['date'] < wp_date( 'Y-m-d' ) ) {
				/* translators: %s: date entered */
				return new \WP_Error( 'vcap_invalid_expiry', sprintf( __( 'The expiry date "%s" is not valid: please enter a future date.', 'voucher-captain' ), $expiry ) );
			}
			$args['expires_at'] = get_gmt_from_date( $parsed['date'] . ' 23:59:59' );
		}
		return \VCAP\Source::with(
			'cli' === $args['source'] ? \VCAP\Source::CLI : \VCAP\Source::ADMIN,
			static fn() => vcap()->issuer->issue_standalone( $args )
		);
	}

	/** Voucher products to choose from (id => name), newest first. */
	private static function products(): array {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'private', 'draft' ),
				'numberposts' => 200,
				'fields'      => 'ids',
				'meta_key'    => '_vcap_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = get_the_title( (int) $id );
		}
		return $out;
	}

	public function page(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$templates = get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'numberposts' => 200, 'post_status' => 'publish' ) );
		$max       = VoucherAdmin::expiry_bounds()[1];
		?>
		<div class="wrap vcap-wrap">
			<h1><?php esc_html_e( 'New voucher', 'voucher-captain' ); ?></h1>
			<p><?php esc_html_e( 'Issue a voucher or gift card without an order, e.g. as goodwill, a prize or for a sale at the till. It gets a code, a WooCommerce coupon and a history like a purchased one, and is marked as not sold (no payment received).', 'voucher-captain' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vcap-card">
				<input type="hidden" name="action" value="vcap_issue_voucher" />
				<?php wp_nonce_field( 'vcap_issue_voucher' ); ?>
				<?php echo FormToken::field(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in field(). ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vcap-issue-product"><?php esc_html_e( 'Product', 'voucher-captain' ); ?></label></th>
						<td><select id="vcap-issue-product" name="product_id">
							<option value="0"><?php esc_html_e( '— No product (settings below) —', 'voucher-captain' ); ?></option>
							<?php foreach ( self::products() as $id => $name ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'With a product, its settings apply (type, usage, validity, restrictions, conditions, template).', 'voucher-captain' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-type"><?php esc_html_e( 'Type', 'voucher-captain' ); ?></label></th>
						<td><select id="vcap-issue-type" name="type">
							<option value="giftcard"><?php esc_html_e( 'Gift card (stored balance)', 'voucher-captain' ); ?></option>
							<option value="voucher"><?php esc_html_e( 'Voucher (single use)', 'voucher-captain' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Only used without a product.', 'voucher-captain' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-amount"><?php esc_html_e( 'Value', 'voucher-captain' ); ?></label></th>
						<td><input type="text" inputmode="decimal" class="wc_input_price short" id="vcap-issue-amount" name="amount" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<p class="description"><?php esc_html_e( 'Required without a product; with a product empty means the product\'s value.', 'voucher-captain' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-template"><?php esc_html_e( 'PDF template', 'voucher-captain' ); ?></label></th>
						<td><select id="vcap-issue-template" name="template_id">
							<option value="0"><?php esc_html_e( 'Product or default template', 'voucher-captain' ); ?></option>
							<?php foreach ( $templates as $t ) : ?>
								<option value="<?php echo esc_attr( (string) $t->ID ); ?>"><?php echo esc_html( $t->post_title ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-expiry"><?php esc_html_e( 'Expiry date', 'voucher-captain' ); ?></label></th>
						<td><input type="date" id="vcap-issue-expiry" name="expiry" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( $max ); ?>" />
							<label><input type="checkbox" name="expires_never" value="1" /> <?php esc_html_e( 'Never expires', 'voucher-captain' ); ?></label>
							<p class="description"><?php esc_html_e( 'Empty: the product\'s validity, else the default validity of the settings.', 'voucher-captain' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-rn"><?php esc_html_e( 'Recipient name', 'voucher-captain' ); ?></label></th>
						<td><input type="text" class="regular-text" id="vcap-issue-rn" name="recipient_name" maxlength="100" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-re"><?php esc_html_e( 'Recipient email', 'voucher-captain' ); ?></label></th>
						<td><input type="email" class="regular-text" id="vcap-issue-re" name="recipient_email" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-msg"><?php esc_html_e( 'Message', 'voucher-captain' ); ?></label></th>
						<td><textarea class="large-text" rows="3" id="vcap-issue-msg" name="message" maxlength="1000"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'voucher-captain' ); ?></th>
						<td><label><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Send by email now (to the recipient email)', 'voucher-captain' ); ?></label>
						<p class="description"><?php esc_html_e( 'Untick to hand it over yourself (print the PDF); it can still be sent later from the voucher screen.', 'voucher-captain' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vcap-issue-reason"><?php esc_html_e( 'Reason', 'voucher-captain' ); ?></label></th>
						<td><input type="text" class="regular-text" id="vcap-issue-reason" name="reason" required maxlength="200" placeholder="<?php esc_attr_e( 'e.g. goodwill for order 1234, prize draw', 'voucher-captain' ); ?>" />
						<p class="description"><?php esc_html_e( 'Kept in the voucher history.', 'voucher-captain' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Issue voucher', 'voucher-captain' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle(): void {
		check_admin_referer( 'vcap_issue_voucher' );
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$back = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = wp_unslash( $_POST );
		// phpcs:enable
		$reason = trim( sanitize_text_field( (string) ( $post['reason'] ?? '' ) ) );
		if ( '' === $reason ) {
			Flash::add( 'error', __( 'Please give a reason: it is kept in the voucher history.', 'voucher-captain' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! FormToken::claim( 'issue_voucher' ) ) {
			Flash::add( 'warning', __( 'This form was already sent: no second voucher was issued.', 'voucher-captain' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'vcap-vouchers' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$voucher = self::issue(
			array(
				'product_id'      => $post['product_id'] ?? 0,
				'template_id'     => $post['template_id'] ?? 0,
				'amount'          => (string) ( $post['amount'] ?? '' ),
				'type'            => (string) ( $post['type'] ?? 'giftcard' ),
				'expiry'          => ! empty( $post['expires_never'] ) ? 'never' : sanitize_text_field( (string) ( $post['expiry'] ?? '' ) ),
				'recipient_name'  => (string) ( $post['recipient_name'] ?? '' ),
				'recipient_email' => sanitize_text_field( (string) ( $post['recipient_email'] ?? '' ) ),
				'message'         => (string) ( $post['message'] ?? '' ),
				'send'            => ! empty( $post['send'] ),
				'reason'          => $reason,
				'source'          => 'admin',
			)
		);
		if ( is_wp_error( $voucher ) ) {
			FormToken::release( 'issue_voucher' );
			/* translators: %s: reason */
			Flash::add( 'error', sprintf( __( 'No voucher was issued: %s', 'voucher-captain' ), $voucher->get_error_message() ) );
			wp_safe_redirect( $back );
			exit;
		}
		Flash::add(
			'success',
			! empty( $post['send'] )
				/* translators: %s: voucher code */
				? sprintf( __( 'Voucher %s issued; it is being sent by email.', 'voucher-captain' ), $voucher->code )
				/* translators: %s: voucher code */
				: sprintf( __( 'Voucher %s issued (not sent: print the PDF or send it from this screen).', 'voucher-captain' ), $voucher->code )
		);
		wp_safe_redirect( add_query_arg( array( 'page' => 'vcap-vouchers', 'voucher' => $voucher->id ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
