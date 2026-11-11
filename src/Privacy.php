<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal data export / erasure for buyer and recipient data stored on vouchers.
 */
final class Privacy {
	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	public function exporters( array $exporters ): array {
		$exporters['voucher-captain'] = array(
			'exporter_friendly_name' => __( 'Vouchers', 'voucher-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function erasers( array $erasers ): array {
		$erasers['voucher-captain'] = array(
			'eraser_friendly_name' => __( 'Vouchers', 'voucher-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	public function export( string $email, int $page = 1 ): array {
		$items = array();
		foreach ( $this->repo->for_email( $email, 500 ) as $voucher ) {
			$data = array(
				array( 'name' => __( 'Code', 'voucher-captain' ), 'value' => $voucher->code ),
				array( 'name' => __( 'Status', 'voucher-captain' ), 'value' => $voucher->status_label() ),
				array( 'name' => __( 'Buyer name', 'voucher-captain' ), 'value' => $voucher->buyer_name ),
				array( 'name' => __( 'Buyer email', 'voucher-captain' ), 'value' => $voucher->buyer_email ),
				array( 'name' => __( 'Recipient name', 'voucher-captain' ), 'value' => $voucher->recipient_name ),
				array( 'name' => __( 'Recipient email', 'voucher-captain' ), 'value' => $voucher->recipient_email ),
				array( 'name' => __( 'Message', 'voucher-captain' ), 'value' => $voucher->message ),
			);
			foreach ( $voucher->fields as $field ) {
				$data[] = array( 'name' => $field['label'] ?? '', 'value' => $field['value'] ?? '' );
			}
			$items[] = array(
				'group_id'    => 'vcap-vouchers',
				'group_label' => __( 'Vouchers', 'voucher-captain' ),
				'item_id'     => 'vcap-voucher-' . $voucher->id,
				'data'        => $data,
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	public function erase( string $email, int $page = 1 ): array {
		$removed = false;
		foreach ( $this->repo->for_email( $email, 500 ) as $voucher ) {
			if ( $voucher->recipient_email === $email ) {
				$voucher->recipient_email = '';
				$voucher->recipient_name  = '';
				$voucher->message         = '';
				$voucher->fields          = array();
			}
			if ( $voucher->buyer_email === $email ) {
				$voucher->buyer_email = '';
				$voucher->buyer_name  = '';
			}
			$this->repo->update( $voucher );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Voucher Captain', 'voucher-captain' ),
				wp_kses_post( wpautop( __( 'When you buy a voucher, we store the recipient name, recipient email address, personal message and any personalisation you enter so that we can create and deliver the voucher. The recipient receives an email with the voucher if you entered their address. This data is kept as long as the voucher can be used and is then removed according to our retention settings.', 'voucher-captain' ) ) )
			);
		}
	}
}
