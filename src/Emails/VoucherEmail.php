<?php
namespace VCAP\Emails;

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
	private array $temp_files = array();

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
	 * @return bool sent
	 */
	public function trigger( Voucher $voucher ): bool {
		$this->setup_locale();
		$this->voucher   = $voucher;
		$this->object    = $voucher->order();
		$this->recipient = $this->recipient_address( $voucher );

		$extra = array_filter( array_map( 'trim', explode( ',', (string) $this->get_option( 'extra_recipients', '' ) ) ), 'is_email' );
		if ( $extra ) {
			$this->recipient = implode( ',', array_merge( array( $this->recipient ), $extra ) );
		}

		$this->placeholders = array_merge( $this->placeholders, Placeholders::email_map( $voucher ) );

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->cleanup();
		$this->restore_locale();
		return $sent;
	}

	public function get_attachments() {
		$attachments = parent::get_attachments();
		if ( $this->voucher && 'yes' === $this->get_option( 'attach_pdf', get_option( 'vcap_attach_pdf', 'yes' ) ) && class_exists( '\Dompdf\Dompdf' ) ) {
			try {
				$file = vcap()->pdf->to_temp_file( $this->voucher );
				if ( $file ) {
					$this->temp_files[] = $file;
					$attachments[]      = $file;
				}
			} catch ( \Throwable $e ) {
				wc_get_logger()->error( 'PDF attachment failed: ' . $e->getMessage(), array( 'source' => 'voucher-captain' ) );
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

	private function template_args( bool $plain ): array {
		if ( ! $this->voucher ) {
			// WooCommerce email preview (Settings → Emails) renders without a trigger.
			$this->voucher      = \VCAP\Preview::sample_voucher();
			$this->placeholders = array_merge( $this->placeholders, Placeholders::email_map( $this->voucher ) );
		}
		return array(
			'voucher'            => $this->voucher,
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'intro'              => $this->format_string( (string) $this->get_option( 'intro', $this->get_default_intro() ) ),
			'additional_content' => $this->get_additional_content(),
			'download_url'       => $this->voucher->id && 'yes' === get_option( 'vcap_include_link', 'yes' ) ? vcap()->downloads->signed_url( $this->voucher, true ) : '',
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return Plugin::template_html( $this->template_html, $this->template_args( false ) );
	}

	public function get_content_plain() {
		return Plugin::template_html( $this->template_plain, $this->template_args( true ) );
	}

	abstract public function get_default_intro(): string;

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
				'default'     => $this->get_default_intro(),
				'description' => $placeholder_text,
			),
			'additional_content' => $this->form_fields['additional_content'],
			'extra_recipients'   => array(
				'title'       => __( 'Additional recipients', 'voucher-captain' ),
				'type'        => 'text',
				'description' => __( 'Comma separated addresses that also receive this email (e.g. for your records).', 'voucher-captain' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'attach_pdf'         => array(
				'title'   => __( 'Attach PDF', 'voucher-captain' ),
				'type'    => 'checkbox',
				'label'   => __( 'Attach the voucher PDF', 'voucher-captain' ),
				'default' => 'yes',
			),
			'email_type'         => $this->form_fields['email_type'],
		);
		$this->form_fields = $fields;
	}
}
