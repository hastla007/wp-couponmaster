<?php
namespace VCAP\Emails;

use VCAP\Delivery;
use VCAP\Downloads;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Z6: several vouchers of one order in one email – for a large quantity of the same order line and recipient
 * (WooCommerce → Settings → Vouchers → Delivery & downloads, "Bulk orders"). The email lists every code in a table
 * and attaches one PDF with a page per voucher; above the PDF limit it links to the downloads instead. Sent to the
 * gift recipient (one email per order line) or to the buyer (one copy for the order).
 */
final class BundleEmail extends VoucherEmail {
	/** @var Voucher[] Vouchers of the email being prepared (in order). */
	public array $vouchers = array();
	/** Who receives it: 'recipient' (gift recipient) or 'buyer'. */
	public string $role = 'buyer';

	public function __construct() {
		$this->id          = 'vcap_voucher_bundle';
		$this->title       = __( 'Vouchers (bulk order)', 'voucher-captain' );
		$this->description = __( 'Sent instead of one email per voucher when an order line holds many vouchers for the same recipient: one email with all codes and one PDF (one page per voucher), or download links for very large quantities. The buyer gets one copy per order. The thresholds are set under WooCommerce → Settings → Vouchers → Delivery & downloads.', 'voucher-captain' );
		parent::__construct();
		$this->template_html                   = 'emails/voucher-bundle.php';
		$this->template_plain                  = 'emails/plain/voucher-bundle.php';
		$this->placeholders['{voucher_count}'] = '';
	}

	/**
	 * Sends the vouchers in one email to their recipient ('recipient') or their buyer ('buyer').
	 *
	 * @param Voucher[] $vouchers Vouchers of one order (for 'recipient': of one order line and address).
	 */
	public function trigger_bundle( array $vouchers, string $role ): bool {
		$this->vouchers                        = array_values( array_filter( $vouchers, static fn( $v ) => $v instanceof Voucher ) );
		$this->role                            = 'recipient' === $role ? 'recipient' : 'buyer';
		$this->placeholders['{voucher_count}'] = (string) count( $this->vouchers );
		if ( ! $this->vouchers ) {
			$this->last_failure = 'no_recipient';
			return false;
		}
		return parent::trigger( $this->vouchers[0] );
	}

	/** A single voucher handed to the bundled email is a bundle of one (to whom its routing sends it first). */
	public function trigger( Voucher $voucher ): bool {
		return $this->trigger_bundle( array( $voucher ), Delivery::to_recipient( $voucher ) ? 'recipient' : 'buyer' );
	}

	/** M12-49: every voucher of the bundled email in X-VCAP-Voucher. */
	protected function header_voucher_ids(): array {
		return array_values( array_filter( array_map( static fn( $v ) => (int) $v->id, $this->vouchers ) ) );
	}

	protected function header_role(): string {
		return $this->role;
	}

	protected function recipient_address( Voucher $voucher ): string {
		return (string) ( 'recipient' === $this->role ? $voucher->recipient_email : $voucher->buyer_email );
	}

	/** Whether the buyer's email is a copy of gifts that went to someone else. */
	private function is_gift_copy(): bool {
		return 'buyer' === $this->role && $this->voucher && Delivery::to_recipient( $this->voucher );
	}

	public function get_default_subject() {
		if ( 'recipient' === $this->role ) {
			return '' !== $this->placeholder( 'sender_name' )
				? self::as_sender( __( '{buyer_name} sent you {voucher_count} vouchers from {site_title}', 'voucher-captain' ) )
				: __( 'You received {voucher_count} vouchers from {site_title}', 'voucher-captain' );
		}
		return $this->is_gift_copy()
			? __( 'Your {voucher_count} gift vouchers are on their way', 'voucher-captain' )
			: __( 'Your {voucher_count} vouchers from {site_title}', 'voucher-captain' );
	}

	protected function default_subject_variants(): array {
		return array(
			static fn() => __( '{buyer_name} sent you {voucher_count} vouchers from {site_title}', 'voucher-captain' ),
			static fn() => __( 'You received {voucher_count} vouchers from {site_title}', 'voucher-captain' ),
			static fn() => __( 'Your {voucher_count} gift vouchers are on their way', 'voucher-captain' ),
			static fn() => __( 'Your {voucher_count} vouchers from {site_title}', 'voucher-captain' ),
		);
	}

	public function get_default_heading() {
		return 'recipient' === $this->role
			? __( 'You received {voucher_count} vouchers!', 'voucher-captain' )
			: __( 'Your {voucher_count} vouchers', 'voucher-captain' );
	}

	protected function default_heading_variants(): array {
		return array(
			static fn() => __( 'You received {voucher_count} vouchers!', 'voucher-captain' ),
			static fn() => __( 'Your {voucher_count} vouchers', 'voucher-captain' ),
		);
	}

	public function get_default_intro(): string {
		return __( 'Hi {buyer_name}, thank you for your order #{order_number}. Your {voucher_count} vouchers are listed below.', 'voucher-captain' );
	}

	/** The default body for the recipient, the buyer's own vouchers or the buyer's copy of gifts. */
	protected function default_intro_for( Voucher $voucher ): string {
		$buyer = '' !== $this->placeholder( 'buyer_name' );
		if ( 'recipient' === $this->role ) {
			$texts = array(
				'11' => __( 'Hi {recipient_name}, {buyer_name} sent you {voucher_count} vouchers. You find all codes below.', 'voucher-captain' ),
				'10' => __( 'Hi {recipient_name}, you received {voucher_count} vouchers as a gift. You find all codes below.', 'voucher-captain' ),
				'01' => __( 'Hello, {buyer_name} sent you {voucher_count} vouchers. You find all codes below.', 'voucher-captain' ),
				'00' => __( 'Hello, you received {voucher_count} vouchers as a gift. You find all codes below.', 'voucher-captain' ),
			);
			return self::as_sender( $texts[ (int) ( '' !== trim( $voucher->recipient_name ) ) . (int) ( '' !== $this->placeholder( 'sender_name' ) ) ] ); // M12-55.
		}
		if ( $this->is_gift_copy() ) {
			return $buyer
				? __( 'Hi {buyer_name}, your {voucher_count} gift vouchers are on their way. This copy with all codes is for your records.', 'voucher-captain' )
				: __( 'Hello, your {voucher_count} gift vouchers are on their way. This copy with all codes is for your records.', 'voucher-captain' );
		}
		if ( '' !== $this->placeholder( 'order_number' ) ) {
			return $buyer ? $this->get_default_intro() : __( 'Hello, thank you for your order #{order_number}. Your {voucher_count} vouchers are listed below.', 'voucher-captain' );
		}
		return $buyer ? __( 'Hi {buyer_name}, your {voucher_count} vouchers are listed below.', 'voucher-captain' ) : __( 'Hello, your {voucher_count} vouchers are listed below.', 'voucher-captain' );
	}

	/** Whether the PDF is attached: as set for the email, and only up to the PDF limit (Delivery::bundle_pdf_limit()). */
	protected function wants_pdf(): bool {
		$limit = Delivery::bundle_pdf_limit( $this->vouchers );
		return parent::wants_pdf() && $limit > 0 && count( $this->vouchers ) <= $limit;
	}

	/** One PDF with a page per voucher (one per page size, normally one). */
	protected function pdf_files(): array {
		return (array) vcap()->pdf->bundle_to_temp_files( $this->vouchers );
	}

	protected function template_args( bool $plain ): array {
		if ( ! $this->vouchers ) {
			// WooCommerce email preview (Settings → Emails): three sample vouchers.
			if ( ! $this->voucher ) {
				$this->voucher      = \VCAP\Preview::sample_voucher();
				$this->placeholders = array_merge( $this->placeholders, \VCAP\Placeholders::email_map( $this->voucher ) );
			}
			$this->vouchers = array();
			foreach ( array( 1, 2, 3 ) as $n ) {
				$sample           = clone $this->voucher;
				$sample->code     = $this->voucher->code . '-' . $n;
				$this->vouchers[] = $sample;
			}
			$this->placeholders['{voucher_count}'] = (string) count( $this->vouchers );
		}
		$args          = parent::template_args( $plain );
		// M12-48: what get_attachments() did (left off when too large or on a retry); the preview asks wants_pdf().
		$pdf           = null !== $this->pdf_attached ? $this->pdf_attached : ( $this->voucher && $this->voucher->id ? false : $this->wants_pdf() );
		$links         = ! $pdf || 'yes' === get_option( 'vcap_include_link', 'yes' );
		$download_urls = array();
		foreach ( $this->vouchers as $voucher ) {
			$download_urls[ $voucher->id ] = $links && $voucher->id ? vcap()->downloads->signed_url( $voucher, true ) : '';
		}
		$order       = $this->voucher ? $this->voucher->order() : null;
		$account_url = '';
		if ( 'buyer' === $this->role && $order instanceof \WC_Order && $order->get_customer_id() > 0 && function_exists( 'wc_get_account_endpoint_url' ) ) {
			$account_url = 'yes' === get_option( 'vcap_myaccount_tab', 'yes' ) ? wc_get_account_endpoint_url( Downloads::ENDPOINT ) : $order->get_view_order_url();
		}
		return array_merge(
			$args,
			array(
				'vouchers'      => $this->vouchers,
				'role'          => $this->role,
				'pdf_attached'  => $pdf,
				'download_urls' => $download_urls,
				'account_url'   => (string) $account_url,
				'show_product'  => count( array_unique( array_map( static fn( $v ) => $v->variation_id ?: $v->product_id, $this->vouchers ) ) ) > 1,
				'show_holder'   => 'buyer' === $this->role && count( array_filter( $this->vouchers, array( Delivery::class, 'to_recipient' ) ) ) > 0,
			)
		);
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['attach_pdf']['label']       = __( 'Attach one PDF with all vouchers (up to the PDF limit of the bulk order settings)', 'voucher-captain' );
		$this->form_fields['subject']['description']   .= ' ' . __( '{voucher_count} is the number of vouchers in the email.', 'voucher-captain' );
		$this->form_fields['heading']['description']   .= ' ' . __( '{voucher_count} is the number of vouchers in the email.', 'voucher-captain' );
		$this->form_fields['intro']['description']     .= ' ' . __( '{voucher_count} is the number of vouchers in the email.', 'voucher-captain' );
	}
}
