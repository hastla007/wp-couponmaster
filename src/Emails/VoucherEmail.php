<?php
namespace VCAP\Emails;

use VCAP\Compat\Multilingual;
use VCAP\I18n;
use VCAP\Placeholders;
use VCAP\Plugin;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Shared implementation of the voucher emails: configurable subject/heading/body, extra recipients, PDF attachment.
 */
abstract class VoucherEmail extends \WC_Email {
	public ?Voucher $voucher = null;
	/** @var string[] */
	protected array $temp_files = array();
	/**
	 * Why the last trigger() sent nothing (D4): 'disabled' (email switched off), 'no_recipient' (no valid address),
	 * 'mail_failed' (the mail system refused it) or '' after a successful send.
	 */
	public string $last_failure = '';
	/** Whether the last trigger() was to attach the PDF but could not create it (D5: "delivered without PDF"). */
	public bool $pdf_failed = false;

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = VCAP_PATH . 'templates/';
		$this->template_html  = 'emails/voucher.php';
		$this->template_plain = 'emails/plain/voucher.php';
		$this->placeholders   = array();
		parent::__construct();
	}

	abstract protected function recipient_address( Voucher $voucher ): string;

	public function get_default_additional_content(): string {
		return '';
	}

	/**
	 * Sends the mail in the order language (WPML/Polylang, filter "vcap_email_locale"; else the site language).
	 * "Additional recipients" get their own copy: only the main address decides whether the mail was sent, a copy
	 * that fails is logged (an archive address the mail server refuses must not make the gift count as undelivered,
	 * and the customer never sees those addresses).
	 *
	 * Why nothing was sent is kept in $last_failure ('disabled', 'no_recipient', 'mail_failed').
	 *
	 * @return bool sent
	 */
	public function trigger( Voucher $voucher ): bool {
		$this->last_failure = '';
		$this->pdf_failed   = false;
		$state = Multilingual::switch_to( Multilingual::email_locale( $voucher, null, $this ), Multilingual::order_language( $voucher->order() ) );
		if ( ! $state['ready'] ) {
			$this->setup_locale();
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$this->init_settings(); // WPML translates the texts (wpml-config.xml) in the language now active.
		}
		try {
			$this->voucher      = $voucher;
			$this->object       = $voucher->order();
			$this->recipient    = $this->recipient_address( $voucher );
			$this->placeholders = array_merge( $this->placeholders, Placeholders::email_map( $voucher ) );

			$sent = false;
			if ( ! $this->is_enabled() ) {
				$this->last_failure = 'disabled';
			} elseif ( ! $this->get_recipient() ) {
				$this->last_failure = 'no_recipient';
			} else {
				$to          = $this->get_recipient();
				$subject     = $this->get_subject();
				$content     = $this->get_content();
				$headers     = $this->get_headers();
				$attachments = $this->get_attachments();
				$sent        = (bool) $this->send( $to, $subject, $content, $headers, $attachments );
				if ( ! $sent ) {
					$this->last_failure = 'mail_failed';
				} else {
					foreach ( $this->extra_recipients( $to ) as $copy ) {
						if ( ! $this->send( $copy, $subject, $content, $headers, $attachments ) ) {
							\VCAP\Logger::warning( 'Copy of a voucher email to an additional recipient could not be sent.', array( 'voucher_id' => $voucher->id, 'email' => $this->id ) );
						}
					}
				}
			}
			return $sent;
		} finally {
			$this->cleanup();
			if ( ! $state['ready'] ) {
				$this->restore_locale();
			}
			Multilingual::restore( $state );
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$this->init_settings();
			}
		}
	}

	/**
	 * Valid "Additional recipients" other than the main address(es).
	 *
	 * @return string[]
	 */
	public function extra_recipients( string $main = '' ): array {
		return \VCAP\Delivery::extra_addresses( (string) $this->get_option( 'extra_recipients', '' ), $main );
	}

	/**
	 * Default subjects this email chooses from per voucher (callables returning the __() text), e.g. with or
	 * without the giver's name.
	 *
	 * @return callable[]
	 */
	protected function default_subject_variants(): array {
		return array();
	}

	/**
	 * Default headings this email chooses from per mail (callables returning the __() text); see
	 * default_subject_variants().
	 *
	 * @return callable[]
	 */
	protected function default_heading_variants(): array {
		return array();
	}

	/**
	 * Settings texts in the language of the mail (Polylang; WPML translates the option itself).
	 *
	 * WooCommerce caches the first default it hands out in the settings (WC_Settings_API::get_option()), which
	 * would freeze a subject with variants to the first voucher's. As long as the shop keeps a default (in any
	 * language), the subject is chosen per voucher.
	 */
	public function get_option( $key, $empty_value = null ) {
		$variants = 'subject' === $key ? $this->default_subject_variants() : ( 'heading' === $key ? $this->default_heading_variants() : array() );
		if ( $variants ) {
			if ( empty( $this->settings ) ) {
				$this->init_settings();
			}
			$stored = (string) ( $this->settings[ $key ] ?? '' );
			foreach ( $variants as $variant ) {
				if ( I18n::is_default_text( $stored, $variant ) ) {
					return 'subject' === $key ? $this->get_default_subject() : $this->get_default_heading();
				}
			}
		}
		$value = parent::get_option( $key, $empty_value );
		return is_string( $value ) && in_array( $key, Multilingual::EMAIL_KEYS, true ) ? Multilingual::translate( $value, true ) : $value;
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

	/** Whether this mail carries the PDF (setting "Attach PDF" of the email, else the global setting). */
	protected function wants_pdf(): bool {
		return $this->voucher && 'yes' === $this->get_option( 'attach_pdf', get_option( 'vcap_attach_pdf', 'yes' ) ) && class_exists( '\Dompdf\Dompdf' );
	}

	/**
	 * The PDF file(s) to attach, written to the private temp folder (deleted after the mail).
	 *
	 * @return string[] Empty when the PDF could not be created (logged by the renderer).
	 */
	protected function pdf_files(): array {
		$file = vcap()->pdf->to_temp_file( $this->voucher );
		return $file ? array( $file ) : array();
	}

	public function get_attachments() {
		$attachments = parent::get_attachments();
		if ( $this->wants_pdf() ) {
			try {
				$files = $this->pdf_files();
				if ( $files ) {
					$this->temp_files = array_merge( $this->temp_files, $files );
					$attachments      = array_merge( (array) $attachments, $files );
				} else {
					$this->pdf_failed = true; // The renderer logged why.
				}
			} catch ( \Throwable $e ) {
				$this->pdf_failed = true;
				\VCAP\Logger::error( 'PDF attachment failed: ' . \VCAP\Mask::addresses( $e->getMessage() ), array( 'voucher_id' => (int) $this->voucher->id, 'element' => 'pdf', 'email' => $this->id ) );
			}
		}
		return $attachments;
	}

	private function cleanup(): void {
		foreach ( $this->temp_files as $file ) {
			wp_delete_file( $file );
		}
		$this->temp_files = array();
	}

	protected function template_args( bool $plain ): array {
		if ( ! $this->voucher ) {
			// WooCommerce email preview (Settings → Emails) renders without a trigger.
			$this->voucher      = \VCAP\Preview::sample_voucher();
			$this->placeholders = array_merge( $this->placeholders, Placeholders::email_map( $this->voucher ) );
		}
		return array(
			'voucher'            => $this->voucher,
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			/** Intro text of a voucher email (e.g. a ticket voucher's reminder speaks of tickets, not of a remaining value). */
			'intro'              => $this->format_string( (string) apply_filters( 'vcap_email_intro', $this->intro_text( $this->voucher ), $this->voucher, $this ) ),
			'additional_content' => $this->get_additional_content(),
			'download_url'       => $this->voucher->id && 'yes' === get_option( 'vcap_include_link', 'yes' ) ? vcap()->downloads->signed_url( $this->voucher, true ) : '',
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'colors'             => self::colors(),
		);
	}

	/**
	 * M12-83: colours of the voucher emails from WooCommerce → Settings → Emails: base colour (buttons, frame),
	 * the readable text on it, a light accent, the muted text and table borders from the text colour
	 * (filter "vcap_email_colors").
	 *
	 * @return array{base:string, on_base:string, accent:string, muted:string, border:string}
	 */
	public static function colors(): array {
		$base = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '' ) );
		$text = sanitize_hex_color( (string) get_option( 'woocommerce_email_text_color', '' ) );
		$base = $base ? $base : '#1f3a4d';
		$text = $text ? $text : '#3c3c3c';
		$colors = array(
			'base'    => $base,
			'on_base' => function_exists( 'wc_light_or_dark' ) ? (string) wc_light_or_dark( $base, '#202020', '#ffffff' ) : '#ffffff',
			'accent'  => function_exists( 'wc_hex_lighter' ) ? '#' . ltrim( (string) wc_hex_lighter( $base, 45 ), '#' ) : $base,
			'muted'   => function_exists( 'wc_hex_lighter' ) ? '#' . ltrim( (string) wc_hex_lighter( $text, 25 ), '#' ) : '#666666',
			'border'  => function_exists( 'wc_hex_lighter' ) ? '#' . ltrim( (string) wc_hex_lighter( $text, 85 ), '#' ) : '#e5e5e5',
		);
		/** Colours of the voucher emails (base, on_base, accent, muted, border), from the WooCommerce email colours. */
		$filtered = (array) apply_filters( 'vcap_email_colors', $colors );
		foreach ( $colors as $key => $default ) {
			$value          = sanitize_hex_color( (string) ( $filtered[ $key ] ?? '' ) );
			$colors[ $key ] = $value ? $value : $default;
		}
		return $colors;
	}

	public function get_content_html() {
		return Plugin::template_html( $this->template_html, $this->template_args( false ) );
	}

	public function get_content_plain() {
		return Plugin::template_html( $this->template_plain, $this->template_args( true ) );
	}

	abstract public function get_default_intro(): string;

	/**
	 * The body text: the shop's own text, or – while the default is kept – the default that fits this voucher
	 * (no greeting by an empty name, no order number for vouchers without an order, no value for tickets).
	 */
	protected function intro_text( Voucher $voucher ): string {
		$stored = trim( (string) $this->get_option( 'intro', '' ) );
		return I18n::is_default_text( $stored, array( $this, 'get_default_intro' ) ) ? $this->default_intro_for( $voucher ) : $stored;
	}

	/**
	 * "Email body" saved as shipped (in whatever language the admin saw it): stored empty, so every mail gets the
	 * default that fits its voucher, in the mail's language.
	 */
	public function validate_intro_field( $key, $value ) {
		$value = (string) $this->validate_textarea_field( $key, $value );
		return I18n::is_default_text( $value, array( $this, 'get_default_intro' ) ) ? '' : $value;
	}

	/** Default body for one voucher (subclasses adapt it to the voucher). */
	protected function default_intro_for( Voucher $voucher ): string {
		return $this->get_default_intro();
	}

	/** Placeholder value of a voucher (e.g. "{buyer_name}"), '' when unknown. */
	protected function placeholder( string $token ): string {
		return trim( (string) ( $this->placeholders[ '{' . $token . '}' ] ?? '' ) );
	}

	/** A voucher without monetary value (ticket voucher, admission pass). */
	protected static function has_no_value( Voucher $voucher ): bool {
		return 'percent' !== $voucher->discount_type && (float) $voucher->initial_amount <= 0;
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$placeholder_text = sprintf(
			/* translators: %s: list of placeholders */
			__( 'Available placeholders: %s', 'voucher-captain' ),
			'<code>' . implode( '</code>, <code>', array_keys( Placeholders::email_map( null ) ) ) . '</code>'
		);
		$fields = array(
			'enabled'            => $this->form_fields['enabled'],
			'subject'            => array_merge( $this->form_fields['subject'], array( 'description' => $placeholder_text ) ),
			'heading'            => array_merge( $this->form_fields['heading'], array( 'description' => $placeholder_text ) ),
			'intro'              => array(
				'title'       => __( 'Email body', 'voucher-captain' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height: 90px;',
				'placeholder' => $this->get_default_intro(),
				// Empty = the default in the mail's language (a translated default would be frozen once saved).
				'default'     => '',
				'description' => $placeholder_text,
			),
			'additional_content' => $this->form_fields['additional_content'],
			'extra_recipients'   => array(
				'title'       => __( 'Additional recipients', 'voucher-captain' ),
				'type'        => 'text',
				'description' => __( 'Comma separated addresses that each receive their own copy of this email (e.g. for your records). Whether the voucher counts as delivered depends only on the customer\'s address.', 'voucher-captain' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'attach_pdf'         => array(
				'title'   => __( 'Attach PDF', 'voucher-captain' ),
				'type'    => 'checkbox',
				'label'   => __( 'Attach the voucher PDF', 'voucher-captain' ),
				'default' => 'yes',
			),
			// M12-83: HTML with a plain text part by default (spam filters, text-only clients).
			'email_type'         => array_merge( $this->form_fields['email_type'], array( 'default' => 'multipart' ) ),
		);
		$this->form_fields = $fields;
	}
}
