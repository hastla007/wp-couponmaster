<?php
namespace VCAP\Emails;

use VCAP\Compat\Multilingual;
use VCAP\Delivery;
use VCAP\I18n;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * M12-84: optional notice to the buyer of a gift – "your gift was delivered" (kind "delivered") or "your gift was
 * used" (kind "used", on its first redemption). Without the code: the buyer only learns that the gift arrived or
 * was used. Off by default (WooCommerce → Settings → Emails).
 */
final class GiftNoticeEmail extends \WC_Email {
	public ?Voucher $voucher = null;
	/** "delivered" or "used". */
	public string $kind = 'delivered';

	public function __construct( string $kind = 'delivered' ) {
		$this->kind           = 'used' === $kind ? 'used' : 'delivered';
		$this->id             = 'vcap_gift_delivered';
		if ( 'used' === $this->kind ) {
			$this->id = 'vcap_gift_used';
		}
		$this->customer_email = true;
		$this->title          = 'used' === $this->kind ? __( 'Gift used (buyer)', 'voucher-captain' ) : __( 'Gift delivered (buyer)', 'voucher-captain' );
		$this->description    = 'used' === $this->kind
			? __( 'Optional: tells the buyer of a gift that the recipient used it for the first time (without the code).', 'voucher-captain' )
			: __( 'Optional: tells the buyer of a gift that it was delivered to the recipient (without the code).', 'voucher-captain' );
		$this->placeholders   = array(
			'{recipient}'    => '',
			'{product_name}' => '',
			'{order_number}' => '',
			'{date}'         => '',
		);
		parent::__construct();
	}

	public function get_default_subject() {
		return 'used' === $this->kind ? __( 'Your gift from {site_title} was used', 'voucher-captain' ) : __( 'Your gift from {site_title} was delivered', 'voucher-captain' );
	}

	public function get_default_heading() {
		return 'used' === $this->kind ? __( 'Your gift was used', 'voucher-captain' ) : __( 'Your gift was delivered', 'voucher-captain' );
	}

	public function get_default_intro(): string {
		return 'used' === $this->kind
			? __( 'Good news: {recipient} used your gift ({product_name}) on {date}.', 'voucher-captain' )
			: __( 'Good news: your gift ({product_name}) was delivered to {recipient} on {date}.', 'voucher-captain' );
	}

	public function get_default_additional_content() {
		return '';
	}

	/** Whether the voucher is a gift the buyer should hear about (sent to someone else, buyer address known). */
	public static function applies( Voucher $voucher ): bool {
		return Delivery::to_recipient( $voucher ) && is_email( $voucher->buyer_email ) && 'credit' !== $voucher->type;
	}

	/** Sends the notice in the order language. */
	public function trigger( Voucher $voucher ): bool {
		if ( ! $this->is_enabled() || ! self::applies( $voucher ) ) {
			return false;
		}
		$state = Multilingual::switch_to( Multilingual::email_locale( $voucher, null, $this ), Multilingual::order_language( $voucher->order() ) );
		if ( ! $state['ready'] ) {
			$this->setup_locale();
		}
		try {
			$this->voucher   = $voucher;
			$this->object    = $voucher->order();
			$this->recipient = $voucher->buyer_email;
			$this->fill();
			return (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), array() );
		} finally {
			if ( ! $state['ready'] ) {
				$this->restore_locale();
			}
			Multilingual::restore( $state );
		}
	}

	private function fill(): void {
		$voucher = $this->voucher ? $this->voucher : \VCAP\Preview::sample_voucher();
		$product = $voucher->product();
		$order   = $voucher->order();
		$this->placeholders['{recipient}']    = '' !== trim( $voucher->recipient_name ) ? trim( $voucher->recipient_name ) : $voucher->recipient_email;
		$this->placeholders['{product_name}'] = $product ? wp_strip_all_tags( $product->get_name() ) : __( 'Voucher', 'voucher-captain' );
		$this->placeholders['{order_number}'] = $order ? (string) $order->get_order_number() : '';
		$this->placeholders['{date}']         = wp_date( vcap()->settings->date_format() );
	}

	/** Settings texts in the language of the mail (Polylang; WPML translates the option itself). */
	public function get_option( $key, $empty_value = null ) {
		$value = parent::get_option( $key, $empty_value );
		return is_string( $value ) && in_array( $key, Multilingual::EMAIL_KEYS, true ) ? Multilingual::translate( $value, true ) : $value;
	}

	/** "Email body" saved as shipped: stored empty, so each mail gets it in its language. */
	public function validate_intro_field( $key, $value ) {
		$value = (string) $this->validate_textarea_field( $key, $value );
		return I18n::is_default_text( $value, array( $this, 'get_default_intro' ) ) ? '' : $value;
	}

	/** Marks the mail as a Voucher Captain mail, so Site Health records it if it fails (see Health). */
	public function send( $to, $subject, $message, $headers, $attachments ) {
		\VCAP\Health::mail_begin( (string) $this->get_title(), $this->voucher ? (int) $this->voucher->id : 0 );
		try {
			return parent::send( $to, $subject, $message, $headers, $attachments );
		} finally {
			\VCAP\Health::mail_end();
		}
	}

	private function body( bool $plain ): string {
		if ( ! $this->voucher ) {
			$this->fill(); // WooCommerce email preview.
		}
		$stored = trim( (string) $this->get_option( 'intro', '' ) );
		$intro  = $this->format_string( I18n::is_default_text( $stored, array( $this, 'get_default_intro' ) ) ? $this->get_default_intro() : $stored );
		$extra  = $this->get_additional_content();
		if ( $plain ) {
			return wp_strip_all_tags( $this->get_heading() ) . "\n\n" . wp_strip_all_tags( $intro ) . "\n\n" . wp_strip_all_tags( $extra ) . "\n";
		}
		ob_start();
		do_action( 'woocommerce_email_header', $this->get_heading(), $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
		echo wp_kses_post( wpautop( wptexturize( $intro ) ) );
		if ( $extra ) {
			echo wp_kses_post( wpautop( wptexturize( $extra ) ) );
		}
		do_action( 'woocommerce_email_footer', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
		return (string) ob_get_clean();
	}

	/** SET-VC-29: rendered with the shop's email logo and accent colour (Branding). */
	public function get_content() {
		return Branding::during( fn() => parent::get_content() );
	}

	/** SET-VC-29: WooCommerce's email styles with the shop's accent colour (Branding). */
	public function style_inline( $content ) {
		return Branding::during( fn() => parent::style_inline( $content ) );
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
			'enabled' => array_merge( $this->form_fields['enabled'], array( 'default' => 'no' ) ),
			'subject' => array_merge( $this->form_fields['subject'], array( 'description' => $help ) ),
			'heading' => array_merge( $this->form_fields['heading'], array( 'description' => $help ) ),
			'intro'   => array(
				'title'       => __( 'Email body', 'voucher-captain' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height: 90px;',
				'placeholder' => $this->get_default_intro(),
				'default'     => '',
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
