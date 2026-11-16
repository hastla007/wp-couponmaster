<?php
/**
 * Voucher requests from Form Captain: intake, review, approval through the voucher issuing service.
 *
 * @package VCAP\Integrations\FormCaptain
 */

namespace VCAP\Integrations\FormCaptain;

use VCAP\ProductConfig;

defined( 'ABSPATH' ) || exit;

/**
 * The Form Captain action only ever creates a request in status "pending" (to be reviewed). Nothing else happens on a
 * form submission: no voucher, no coupon, no order, no email, no payment. A manager edits, approves or rejects the
 * request under Vouchers → Voucher requests. Only the approval issues vouchers, and only through the existing issuing
 * service (Issuer::issue_for_order() on an order created for the request); voucher tables are never written here.
 *
 * Form Captain data arrives only as the mapped values of the action job (contract v1). Form Captain tables are never
 * read; entry erasure and consent withdrawal arrive as the public events of Form Captain.
 *
 * @since 1.2.0
 */
final class RequestService {
	/** Default of the filter `vcap_form_captain_max_quantity`. */
	public const MAX_QUANTITY = 100;
	/** Default of the filter `vcap_form_captain_max_value` (shop base currency). */
	public const MAX_VALUE = 10000;
	/** Longest personalisation text that is kept. */
	public const MAX_TEXT = 1000;
	/** Order meta linking an order to its request. */
	public const ORDER_META = '_vcap_fc_request';

	/**
	 * Construct the service.
	 *
	 * @param RequestRepository $repo Request storage.
	 */
	public function __construct( private RequestRepository $repo ) {}

	/**
	 * Whether Voucher Captain can take requests right now (WooCommerce and Voucher Captain booted, table present).
	 * A missing service is a temporary error for Form Captain (`retry`), never a lost request.
	 */
	public static function available(): bool {
		$ok = class_exists( 'WooCommerce' )
			&& function_exists( 'wc_create_order' )
			&& ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.9', '>=' ) )
			&& RequestRepository::ready();
		/**
		 * Filters whether Voucher Captain accepts voucher requests from Form Captain right now.
		 * False makes the Form Captain job retry later (code `voucher_captain_unavailable`).
		 *
		 * @since 1.2.0
		 * @param bool $ok Whether the request service is available.
		 */
		return (bool) apply_filters( 'vcap_form_captain_available', $ok );
	}

	/**
	 * Idempotency key of a request: site, form and entry. Every delivery of the same entry (retries, a second
	 * action instance on the same form) maps to the same request.
	 *
	 * @param int $form_id  Form Captain form id.
	 * @param int $entry_id Form Captain entry id.
	 */
	public static function idempotency_key( int $form_id, int $entry_id ): string {
		return sprintf( 'form-captain:%d:%d:%d', get_current_blog_id(), $form_id, $entry_id );
	}

	/**
	 * Remote id reported back to Form Captain.
	 *
	 * @param int $id Request id.
	 */
	public static function remote_id( int $id ): string {
		return 'vcap-request-' . $id;
	}

	/**
	 * Action callback (Form Captain contract v1). Unknown job keys are ignored on purpose.
	 *
	 * @param array<string, mixed> $job Contract v1 job array.
	 * @return array{status:string, code:string, message:string, remote_id?:string}
	 */
	public function handle_job( array $job ): array {
		$values = is_array( $job['values'] ?? null ) ? $job['values'] : array();
		$clean  = self::sanitize( $values, (array) ( $job['settings'] ?? array() ) );
		if ( ! empty( $job['test_mode'] ) ) {
			// Test run: check the input only, never store anything.
			return is_wp_error( $clean )
				? self::result( 'fail', (string) $clean->get_error_code(), 'Test values are invalid.' )
				: self::result( 'success', 'test_ok', 'Test mode - no request stored.' );
		}
		if ( ! self::available() ) {
			return self::result( 'retry', 'voucher_captain_unavailable', 'Voucher Captain cannot take requests right now.' );
		}
		$form_id  = (int) ( $job['form_id'] ?? 0 );
		$entry_id = (int) ( $job['entry_id'] ?? 0 );
		if ( $form_id < 1 || $entry_id < 1 ) {
			return self::result( 'fail', 'invalid_job', 'Form or entry id missing.' );
		}
		$key      = self::idempotency_key( $form_id, $entry_id );
		$existing = $this->repo->find_by_key( $key );
		if ( $existing ) {
			// Duplicate delivery: same answer, never a second request.
			return self::result( 'success', 'already_created', '', self::remote_id( (int) $existing['id'] ) );
		}
		if ( is_wp_error( $clean ) ) {
			return self::result( 'fail', (string) $clean->get_error_code(), 'The request data is invalid.' );
		}
		$stored = $this->repo->insert_once(
			array_merge(
				$clean,
				array(
					'idempotency_key' => $key,
					'job_key'         => substr( (string) ( $job['idempotency_key'] ?? '' ), 0, 191 ),
					'form_id'         => $form_id,
					'entry_id'        => $entry_id,
				)
			)
		);
		if ( null === $stored ) {
			return self::result( 'retry', 'storage_failed', 'The request could not be stored.' );
		}
		if ( $stored['created'] ) {
			/**
			 * A voucher request from Form Captain was stored (status pending). Nothing has been issued.
			 *
			 * @since 1.2.0
			 * @param int $id Request id.
			 */
			do_action( 'vcap_form_captain_request_created', $stored['id'] );
		}
		return self::result( 'success', $stored['created'] ? 'created' : 'already_created', '', self::remote_id( $stored['id'] ) );
	}

	/**
	 * Validates and sanitises request values (mapping keys of the action, or the admin edit form).
	 *
	 * @param array<string, mixed> $values   Mapping key => value.
	 * @param array<string, mixed> $settings Action settings (optional product suggestion).
	 * @return array<string, mixed>|\WP_Error Column values or an error with a stable code.
	 */
	public static function sanitize( array $values, array $settings = array() ) {
		$text    = static function ( $value ): string {
			return trim( sanitize_text_field( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value ) );
		};
		$company = mb_substr( $text( $values['company'] ?? '' ), 0, 200 );
		$contact = mb_substr( $text( $values['contact_name'] ?? '' ), 0, 200 );
		$email   = sanitize_email( $text( $values['email'] ?? '' ) );
		if ( '' === $contact ) {
			return new \WP_Error( 'missing_contact', __( 'Please enter a contact person.', 'voucher-captain' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'voucher-captain' ) );
		}
		$max_qty  = max( 1, (int) apply_filters( 'vcap_form_captain_max_quantity', self::MAX_QUANTITY ) );
		$quantity = $text( $values['quantity'] ?? '' );
		if ( ! preg_match( '/^\d{1,6}$/', $quantity ) || (int) $quantity < 1 || (int) $quantity > $max_qty ) {
			/* translators: %d: maximum number of vouchers */
			return new \WP_Error( 'invalid_quantity', sprintf( __( 'Please request between 1 and %d vouchers.', 'voucher-captain' ), $max_qty ) );
		}
		$value     = self::parse_amount( $text( $values['value'] ?? '' ) );
		$max_value = (float) apply_filters( 'vcap_form_captain_max_value', self::MAX_VALUE );
		if ( null === $value || $value <= 0 || $value > $max_value ) {
			return new \WP_Error( 'invalid_value', __( 'Please enter a valid voucher value.', 'voucher-captain' ) );
		}
		$raw_text = $values['personalization'] ?? '';
		$personal = trim( sanitize_textarea_field( is_array( $raw_text ) ? implode( "\n", array_map( 'strval', $raw_text ) ) : (string) $raw_text ) );
		if ( mb_strlen( $personal ) > self::MAX_TEXT ) {
			return new \WP_Error( 'text_too_long', __( 'The personalisation text is too long.', 'voucher-captain' ) );
		}
		if ( ! self::consent_given( $values['consent'] ?? '' ) ) {
			return new \WP_Error( 'consent_missing', __( 'The request needs the consent of the sender.', 'voucher-captain' ) );
		}
		return array(
			'company'         => $company,
			'contact_name'    => $contact,
			'email'           => $email,
			'quantity'        => (int) $quantity,
			'value'           => $value,
			'personalization' => $personal,
			'consent'         => 1,
			'product_id'      => max( 0, (int) ( $settings['product_id'] ?? 0 ) ),
		);
	}

	/**
	 * Parses "50", "50.5", "50,50" or "1.250,00" into a float; null for anything else.
	 *
	 * @param string $raw Entered amount.
	 */
	public static function parse_amount( string $raw ): ?float {
		$raw = str_replace( array( ' ', "\u{00a0}" ), '', $raw );
		if ( preg_match( '/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $raw ) ) {
			$raw = str_replace( array( '.', ',' ), array( '', '.' ), $raw );
		} elseif ( preg_match( '/^\d+,\d{1,2}$/', $raw ) ) {
			$raw = str_replace( ',', '.', $raw );
		}
		return preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ? round( (float) $raw, 2 ) : null;
	}

	/**
	 * Consent field (Form Captain field type consent: "1"; checkbox: the ticked options).
	 *
	 * @param mixed $value Mapped value.
	 */
	private static function consent_given( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== array_filter( array_map( 'strval', $value ), static fn( $v ) => '' !== trim( $v ) );
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'on', 'true' ), true );
	}

	/**
	 * Result array of the action callback.
	 *
	 * @param string $status    success, retry or fail.
	 * @param string $code      Stable code.
	 * @param string $message   Message without personal data.
	 * @param string $remote_id Request reference.
	 * @return array{status:string, code:string, message:string, remote_id?:string}
	 */
	private static function result( string $status, string $code, string $message, string $remote_id = '' ): array {
		$result = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $remote_id ) {
			$result['remote_id'] = $remote_id;
		}
		return $result;
	}

	/**
	 * Saves a manager's changes to an open request.
	 *
	 * @param int                  $id     Request id.
	 * @param array<string, mixed> $values Mapping keys (company, contact_name, email, quantity, value, personalization) and product_id.
	 * @return true|\WP_Error
	 */
	public function edit( int $id, array $values ) {
		$request = $this->repo->get( $id );
		if ( ! $request || RequestRepository::STATUS_PENDING !== $request['status'] ) {
			return new \WP_Error( 'vcap_fc_not_pending', __( 'Only open requests can be changed.', 'voucher-captain' ) );
		}
		// Consent was given with the submission; an edit never asks for it again.
		$clean = self::sanitize( array( 'consent' => '1' ) + $values, array( 'product_id' => $values['product_id'] ?? $request['product_id'] ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		unset( $clean['consent'] );
		return $this->repo->update( $id, $clean ) ? true : new \WP_Error( 'vcap_fc_storage', __( 'The request could not be saved.', 'voucher-captain' ) );
	}

	/**
	 * Rejects an open request. Nothing is issued.
	 *
	 * @param int    $id     Request id.
	 * @param string $reason Internal note.
	 * @return true|\WP_Error
	 */
	public function reject( int $id, string $reason = '' ) {
		if ( ! $this->repo->transition( $id, RequestRepository::STATUS_PENDING, RequestRepository::STATUS_REJECTED ) ) {
			return new \WP_Error( 'vcap_fc_not_pending', __( 'Only open requests can be rejected.', 'voucher-captain' ) );
		}
		$this->repo->update(
			$id,
			array(
				'decided_by' => get_current_user_id(),
				'decided_at' => current_time( 'mysql', true ),
				'note'       => mb_substr( sanitize_text_field( $reason ), 0, 255 ),
			)
		);
		/**
		 * A voucher request from Form Captain was rejected.
		 *
		 * @since 1.2.0
		 * @param int $id Request id.
		 */
		do_action( 'vcap_form_captain_request_rejected', $id );
		return true;
	}

	/**
	 * Approves an open request: creates an order for the requester (status on hold, to be invoiced) with the voucher
	 * product and issues the vouchers through the issuing service of Voucher Captain. Exactly one approval per request.
	 *
	 * @param int $id         Request id.
	 * @param int $product_id Voucher product (0 = the product stored with the request).
	 * @return array{order_id:int, vouchers:int}|\WP_Error
	 */
	public function approve( int $id, int $product_id = 0 ) {
		$request = $this->repo->get( $id );
		if ( ! $request || RequestRepository::STATUS_PENDING !== $request['status'] ) {
			return new \WP_Error( 'vcap_fc_not_pending', __( 'Only open requests can be approved.', 'voucher-captain' ) );
		}
		$product = self::voucher_product( $product_id ? $product_id : (int) $request['product_id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( ! $this->repo->transition( $id, RequestRepository::STATUS_PENDING, RequestRepository::STATUS_APPROVING ) ) {
			return new \WP_Error( 'vcap_fc_not_pending', __( 'Only open requests can be approved.', 'voucher-captain' ) );
		}
		try {
			$order = $this->create_order( $request, $product );
			$this->repo->update(
				$id,
				array(
					'order_id'   => $order->get_id(),
					'product_id' => $product->get_id(),
				)
			);
			$created = vcap()->issuer->issue_for_order( $order, true );
		} catch ( \Throwable $e ) {
			$this->repo->transition( $id, RequestRepository::STATUS_APPROVING, RequestRepository::STATUS_PENDING );
			return new \WP_Error( 'vcap_fc_approval_failed', __( 'The vouchers could not be issued. The request is still open.', 'voucher-captain' ) );
		}
		if ( ! $created ) {
			$order->update_status( 'cancelled', __( 'No voucher could be issued for this request.', 'voucher-captain' ) );
			$this->repo->update( $id, array( 'order_id' => 0 ) );
			$this->repo->transition( $id, RequestRepository::STATUS_APPROVING, RequestRepository::STATUS_PENDING );
			return new \WP_Error( 'vcap_fc_nothing_issued', __( 'No voucher could be issued for this product. The request is still open.', 'voucher-captain' ) );
		}
		$order->update_status( 'on-hold', __( 'Voucher request approved, vouchers issued. Payment by invoice.', 'voucher-captain' ) );
		$this->repo->update(
			$id,
			array(
				'status'     => RequestRepository::STATUS_APPROVED,
				'vouchers'   => count( $created ),
				'decided_by' => get_current_user_id(),
				'decided_at' => current_time( 'mysql', true ),
			)
		);
		/**
		 * A voucher request from Form Captain was approved and its vouchers issued.
		 *
		 * @since 1.2.0
		 * @param int             $id       Request id.
		 * @param \WC_Order       $order    Order created for the request.
		 * @param \VCAP\Voucher[] $vouchers Issued vouchers.
		 */
		do_action( 'vcap_form_captain_request_approved', $id, $order, $created );
		return array(
			'order_id' => $order->get_id(),
			'vouchers' => count( $created ),
		);
	}

	/**
	 * Checks that a product can carry the requested vouchers.
	 *
	 * @param int $product_id Product id.
	 * @return \WC_Product|\WP_Error
	 */
	public static function voucher_product( int $product_id ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || $product->is_type( 'variable' ) || ! ProductConfig::is_voucher( $product ) ) {
			return new \WP_Error( 'vcap_fc_product', __( 'Please choose a voucher product (a simple product or a variation with vouchers enabled).', 'voucher-captain' ) );
		}
		if ( 1 !== (int) ProductConfig::get( $product )['per_item'] ) {
			return new \WP_Error( 'vcap_fc_product_per_item', __( 'Please choose a voucher product that issues one voucher per item.', 'voucher-captain' ) );
		}
		return $product;
	}

	/**
	 * Order for an approved request: one line with the requested quantity at the requested value per voucher.
	 *
	 * @param array<string, mixed> $request Request row.
	 * @param \WC_Product          $product Voucher product.
	 * @throws \RuntimeException When the order or its line cannot be created (approval is rolled back).
	 */
	private function create_order( array $request, \WC_Product $product ): \WC_Order {
		$order = wc_create_order( array( 'created_via' => 'vcap_form_captain' ) );
		if ( is_wp_error( $order ) ) {
			throw new \RuntimeException( 'order' );
		}
		$name  = trim( (string) $request['contact_name'] );
		$parts = explode( ' ', $name );
		$last  = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
		$order->set_billing_first_name( implode( ' ', $parts ) );
		$order->set_billing_last_name( $last );
		$order->set_billing_company( (string) $request['company'] );
		$order->set_billing_email( (string) $request['email'] );

		$qty     = max( 1, (int) $request['quantity'] );
		$value   = (float) $request['value'];
		$item_id = $order->add_product(
			$product,
			$qty,
			array(
				'subtotal' => $value * $qty,
				'total'    => $value * $qty,
			)
		);
		$item    = $order->get_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			throw new \RuntimeException( 'item' );
		}
		// Custom amount products read the face value from here; price based products from the line total.
		$item->add_meta_data( '_vcap_custom_amount', wc_format_decimal( $value ), true );
		if ( '' !== (string) $request['personalization'] ) {
			$label = __( 'Personal message', 'voucher-captain' );
			$item->add_meta_data(
				'_vcap_fields',
				array(
					'message' => array(
						'label' => $label,
						'value' => (string) $request['personalization'],
					),
				),
				true
			);
			$item->add_meta_data( $label, (string) $request['personalization'], true );
		}
		$item->save();
		$order->update_meta_data( self::ORDER_META, (int) $request['id'] );
		$order->calculate_totals( false );
		/* translators: %d: request id */
		$order->add_order_note( sprintf( __( 'Created from voucher request #%d (Form Captain).', 'voucher-captain' ), (int) $request['id'] ) );
		$order->save();
		return $order;
	}

	/**
	 * Form Captain entry deleted (manual, retention, erasure, anonymised, form deleted, consent withdrawn): open,
	 * rejected and withdrawn requests are deleted; approved requests keep their numbers and order link but lose
	 * every personal field (the order belongs to WooCommerce and its privacy tools).
	 *
	 * @param int $entry_id Entry id.
	 * @param int $form_id  Form id.
	 */
	public function entry_deleted( int $entry_id, int $form_id ): void {
		foreach ( $this->repo->for_entry( $entry_id ) as $request ) {
			if ( (int) $request['form_id'] !== $form_id ) {
				continue;
			}
			if ( in_array( $request['status'], array( RequestRepository::STATUS_APPROVED, RequestRepository::STATUS_APPROVING ), true ) ) {
				$this->anonymize( (int) $request['id'] );
				continue;
			}
			$this->repo->delete( (int) $request['id'] );
		}
	}

	/**
	 * Consent withdrawn in Form Captain: an open request is closed (status withdrawn) and its personal data removed,
	 * so it can never be approved; rejected requests are anonymised. Approved requests stay as they are – the vouchers
	 * were issued under a contract; Form Captain sends entry_deleted_v1 when the entry is erased.
	 *
	 * @param int $entry_id Entry id.
	 * @param int $form_id  Form id.
	 */
	public function consent_withdrawn( int $entry_id, int $form_id ): void {
		foreach ( $this->repo->for_entry( $entry_id ) as $request ) {
			if ( (int) $request['form_id'] !== $form_id ) {
				continue;
			}
			$id = (int) $request['id'];
			if ( RequestRepository::STATUS_PENDING === $request['status'] && $this->repo->transition( $id, RequestRepository::STATUS_PENDING, RequestRepository::STATUS_WITHDRAWN ) ) {
				$this->anonymize( $id );
			} elseif ( RequestRepository::STATUS_REJECTED === $request['status'] ) {
				$this->anonymize( $id );
			}
		}
	}

	/**
	 * Removes the personal data of a request.
	 *
	 * @param int $id Request id.
	 */
	private function anonymize( int $id ): void {
		$data               = array_fill_keys( RequestRepository::PERSONAL_COLUMNS, '' );
		$data['anonymized'] = 1;
		$this->repo->update( $id, $data );
	}
}
