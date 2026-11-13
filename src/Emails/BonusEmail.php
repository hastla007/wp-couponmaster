<?php
namespace VCAP\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Bonus voucher from a promotion, sent to the customer after the qualifying order was paid.
 */
class BonusEmail extends \WC_Email {
	public ?\WC_Coupon $coupon = null;

	public function __construct() {
		$this->id             = $this->id ? $this->id : 'vcap_bonus_voucher';
		$this->customer_email = true;
		$this->title          = $this->title ? $this->title : __( 'Bonus voucher', 'voucher-captain' );
		$this->description    = $this->description ? $this->description : __( 'Sent when an order earns a bonus voucher from a promotion (Vouchers → Promotions, discount "Bonus voucher after the order").', 'voucher-captain' );
		$this->placeholders   = array(
			'{coupon_code}'   => '',
			'{coupon_amount}' => '',
			'{expiry_date}'   => '',
			'{promotion}'     => '',
			'{order_number}'  => '',
		);
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'A bonus voucher from {site_title} for you', 'voucher-captain' );
	}

	public function get_default_heading() {
		return __( 'Thank you – here is your bonus', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return __( 'Thank you for your order {order_number}. As a thank-you you receive {coupon_amount} off your next order with the code below. Valid until {expiry_date}.', 'voucher-captain' );
	}

	public function get_default_additional_content() {
		return '';
	}

	public static function amount_text( \WC_Coupon $coupon ): string {
		return 'percent' === $coupon->get_discount_type()
			? wc_format_localized_decimal( (string) (float) $coupon->get_amount() ) . ' %'
			: wp_strip_all_tags( wc_price( (float) $coupon->get_amount() ) );
	}

	public function trigger( \WC_Coupon $coupon, \WC_Order $order, string $promotion, string $recipient = '' ): bool {
		$this->setup_locale();
		$this->coupon    = $coupon;
		$this->object    = $order;
		$this->recipient = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		$this->fill( $promotion );
		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	/** Marks the mail as a Voucher Captain mail, so Site Health records it if it fails (see Health). */
	public function send( $to, $subject, $message, $headers, $attachments ) {
		\VCAP\Health::mail_begin( (string) $this->get_title() );
		try {
			return parent::send( $to, $subject, $message, $headers, $attachments );
		} finally {
			\VCAP\Health::mail_end();
		}
	}

	private function fill( string $promotion ): void {
		$coupon  = $this->coupon;
		$expires = $coupon ? $coupon->get_date_expires() : null;
		$this->placeholders['{coupon_code}']   = $coupon ? strtoupper( $coupon->get_code() ) : 'BONUS-AB12CD34EF';
		$this->placeholders['{coupon_amount}'] = $coupon ? self::amount_text( $coupon ) : wp_strip_all_tags( wc_price( 10 ) );
		$this->placeholders['{expiry_date}']   = $expires ? wc_format_datetime( $expires ) : __( 'no expiry', 'voucher-captain' );
		$this->placeholders['{promotion}']     = $promotion;
		$this->placeholders['{order_number}']  = $this->object instanceof \WC_Order ? $this->object->get_order_number() : '1234';
	}

	private function body( bool $plain ): string {
		if ( ! $this->coupon ) {
			$this->fill( __( 'Promotion', 'voucher-captain' ) ); // WooCommerce email preview.
		}
		$intro = $this->format_string( (string) $this->get_option( 'intro', $this->get_default_intro() ) );
		$code  = $this->placeholders['{coupon_code}'];
		$extra = $this->get_additional_content();
		if ( $plain ) {
			return wp_strip_all_tags( $this->get_heading() ) . "\n\n" . wp_strip_all_tags( $intro ) . "\n\n" . $code . "\n\n" . wp_strip_all_tags( $extra ) . "\n";
		}
		ob_start();
		do_action( 'woocommerce_email_header', $this->get_heading(), $this );
		echo wp_kses_post( wpautop( wptexturize( $intro ) ) );
		echo '<p style="font-size:24px;font-weight:bold;letter-spacing:2px;text-align:center;padding:12px;border:2px dashed #999;">' . esc_html( $code ) . '</p>';
		if ( $extra ) {
			echo wp_kses_post( wpautop( wptexturize( $extra ) ) );
		}
		do_action( 'woocommerce_email_footer', $this );
		return (string) ob_get_clean();
	}

	public function get_content_html() {
		return $this->body( false );
	}

	public function get_content_plain() {
		return $this->body( true );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$help   = sprintf(
			/* translators: %s: list of placeholders */
			__( 'Available placeholders: %s', 'voucher-captain' ),
			'<code>{site_title}</code>, <code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>'
		);
		$fields = array(
			'enabled' => $this->form_fields['enabled'],
			'subject' => array_merge( $this->form_fields['subject'], array( 'description' => $help ) ),
			'heading' => array_merge( $this->form_fields['heading'], array( 'description' => $help ) ),
			'intro'   => array(
				'title'       => __( 'Email body', 'voucher-captain' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height: 90px;',
				'placeholder' => $this->get_default_intro(),
				'default'     => $this->get_default_intro(),
				'description' => $help,
			),
		);
		foreach ( array( 'additional_content', 'email_type' ) as $key ) {
			if ( isset( $this->form_fields[ $key ] ) ) {
				$fields[ $key ] = $this->form_fields[ $key ];
			}
		}
		$this->form_fields = $fields;
	}
}
