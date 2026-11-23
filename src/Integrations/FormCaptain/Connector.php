<?php
/**
 * Form Captain connector of Voucher Captain (contract v1).
 *
 * @package VCAP\Integrations\FormCaptain
 */

namespace VCAP\Integrations\FormCaptain;

use FormCaptain\Integrations\ConnectorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the form action "Voucher request to Voucher Captain". Loaded only after form_captain_loaded_v1 with a
 * compatible contract (Bootstrap), so the interface always exists here. Form Captain keeps the connector disabled
 * until a manager activates it (opt-in per site under Form Captain → Integrations).
 *
 * @since 1.3.0
 */
final class Connector implements ConnectorInterface {
	public const KEY    = 'voucher_captain';
	public const ACTION = 'voucher_captain.voucher_request';

	/**
	 * Explicit field mapping (allowlist): only these mapping keys ever reach Voucher Captain.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mapping(): array {
		return array(
			array(
				'key'   => 'company',
				'label' => __( 'Company', 'voucher-captain' ),
				'types' => array( 'text' ),
			),
			array(
				'key'      => 'contact_name',
				'label'    => __( 'Contact person', 'voucher-captain' ),
				'types'    => array( 'text' ),
				'required' => true,
			),
			array(
				'key'      => 'email',
				'label'    => __( 'Email', 'voucher-captain' ),
				'types'    => array( 'email' ),
				'required' => true,
			),
			array(
				'key'      => 'quantity',
				'label'    => __( 'Number of vouchers', 'voucher-captain' ),
				'types'    => array( 'number', 'select', 'radio', 'text', 'hidden' ),
				'required' => true,
			),
			array(
				'key'      => 'value',
				'label'    => __( 'Value per voucher', 'voucher-captain' ),
				'types'    => array( 'number', 'select', 'radio', 'text', 'hidden' ),
				'required' => true,
			),
			array(
				'key'   => 'personalization',
				'label' => __( 'Personalisation text', 'voucher-captain' ),
				'types' => array( 'textarea', 'text' ),
			),
			array(
				'key'      => 'consent',
				'label'    => __( 'Consent', 'voucher-captain' ),
				'types'    => array( 'consent', 'checkbox' ),
				'required' => true,
			),
		);
	}

	/**
	 * Construct the connector.
	 *
	 * @param RequestService $service Request service.
	 */
	public function __construct( private RequestService $service ) {}

	/**
	 * Stable connector key.
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * Human readable name.
	 */
	public function label(): string {
		return __( 'Voucher Captain', 'voucher-captain' );
	}

	/**
	 * Plugin version.
	 */
	public function version(): string {
		return defined( 'VCAP_VERSION' ) ? (string) VCAP_VERSION : '1.0.0';
	}

	/**
	 * Supported contract (Form Captain 1.0 and newer within major 1).
	 */
	public function contract(): string {
		return '1.0';
	}

	/**
	 * Action keys.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( self::ACTION );
	}

	/**
	 * Requirements: WooCommerce and Voucher Captain must be running.
	 *
	 * @return array{ok:bool, messages:string[]}
	 */
	public function requirements(): array {
		$ok = RequestService::available();
		return array(
			'ok'       => $ok,
			'messages' => array(
				$ok
					? __( 'Submissions become voucher requests in Voucher Captain. Nothing is issued before a manager approves the request.', 'voucher-captain' )
					: __( 'Voucher Captain cannot take requests right now (WooCommerce inactive or the request table is missing).', 'voucher-captain' ),
			),
		);
	}

	/**
	 * No global settings (Voucher Captain is part of the site, no credentials).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Registers the form action.
	 */
	public function register_actions(): void {
		form_captain_register_action_v1(
			self::ACTION,
			array(
				'label'     => __( 'Voucher request to Voucher Captain', 'voucher-captain' ),
				'version'   => '1.0.0',
				'contract'  => '^1.0',
				'connector' => self::KEY,
				'settings'  => array(
					array(
						'key'   => 'product_id',
						'type'  => 'number',
						'label' => __( 'Suggested voucher product (ID, optional)', 'voucher-captain' ),
					),
				),
				'mapping'   => self::mapping(),
				'events'    => array( 'entry_created' ),
			),
			array( $this->service, 'handle_job' )
		);
	}
}
