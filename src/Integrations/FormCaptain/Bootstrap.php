<?php
/**
 * Optional Form Captain adapter: loads only when Form Captain (contract major 1) is active.
 *
 * @package VCAP\Integrations\FormCaptain
 */

namespace VCAP\Integrations\FormCaptain;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the adapter. register() is called when the plugin file loads, before WooCommerce is checked, so the
 * connector also answers when Voucher Captain cannot boot: its jobs then retry (`voucher_captain_unavailable`)
 * instead of being lost. Without Form Captain nothing is registered except the request admin screen of a site that
 * already holds requests.
 *
 * Uses only the public contract of Form Captain: form_captain_loaded_v1, the filter form_captain_connectors_v1,
 * form_captain_register_action_v1() and the events form_captain_entry_deleted_v1 / form_captain_consent_withdrawn_v1.
 *
 * @since 1.2.0
 */
final class Bootstrap {
	/** Contract major this adapter speaks. */
	public const CONTRACT_MAJOR = 1;

	/**
	 * Shared request service.
	 *
	 * @var RequestService|null
	 */
	private static ?RequestService $service = null;

	/**
	 * Hooks the adapter (idempotent).
	 */
	public static function register(): void {
		if ( ! has_action( 'form_captain_loaded_v1', array( self::class, 'boot' ) ) ) {
			add_action( 'form_captain_loaded_v1', array( self::class, 'boot' ) );
		}
		if ( did_action( 'form_captain_loaded_v1' ) ) {
			self::boot( function_exists( 'form_captain_contract_version' ) ? (string) form_captain_contract_version() : '' );
		}
		if ( is_admin() && ! has_action( 'admin_menu', array( RequestsAdmin::class, 'menu' ) ) ) {
			RequestsAdmin::register();
		}
	}

	/**
	 * Runs on form_captain_loaded_v1: checks the contract major and hooks the connector and the entry events.
	 *
	 * @param mixed $contract Contract version of Form Captain (e.g. "1.1.0").
	 */
	public static function boot( $contract = '' ): void {
		if ( ! self::compatible( (string) $contract ) ) {
			return;
		}
		if ( ! interface_exists( \FormCaptain\Integrations\ConnectorInterface::class ) || ! function_exists( 'form_captain_register_action_v1' ) ) {
			return;
		}
		RequestRepository::maybe_install();
		if ( ! has_filter( 'form_captain_connectors_v1', array( self::class, 'add_connector' ) ) ) {
			add_filter( 'form_captain_connectors_v1', array( self::class, 'add_connector' ) );
		}
		if ( ! has_action( 'form_captain_entry_deleted_v1', array( self::class, 'entry_deleted' ) ) ) {
			add_action( 'form_captain_entry_deleted_v1', array( self::class, 'entry_deleted' ), 10, 3 );
		}
		if ( ! has_action( 'form_captain_consent_withdrawn_v1', array( self::class, 'consent_withdrawn' ) ) ) {
			add_action( 'form_captain_consent_withdrawn_v1', array( self::class, 'consent_withdrawn' ), 10, 3 );
		}
	}

	/**
	 * Whether a Form Captain contract version is one this adapter speaks.
	 *
	 * @param string $contract Contract version.
	 */
	public static function compatible( string $contract ): bool {
		return (bool) preg_match( '/^\d+(\.\d+){0,2}$/', $contract ) && self::CONTRACT_MAJOR === (int) $contract;
	}

	/**
	 * Shared request service.
	 */
	public static function service(): RequestService {
		if ( null === self::$service ) {
			self::$service = new RequestService( new RequestRepository() );
		}
		return self::$service;
	}

	/**
	 * Adds the connector (never replaces another plugin's key).
	 *
	 * @param mixed $connectors Registered connectors.
	 * @return array<string, object>
	 */
	public static function add_connector( $connectors ): array {
		$connectors = is_array( $connectors ) ? $connectors : array();
		return $connectors + array( Connector::KEY => new Connector( self::service() ) );
	}

	/**
	 * Form Captain deleted or anonymised an entry.
	 *
	 * @param mixed $entry_id Entry id.
	 * @param mixed $form_id  Form id.
	 * @param mixed $reason   Reason (manual, retention, erasure, anonymized, form_deleted, consent_withdrawn, …).
	 */
	public static function entry_deleted( $entry_id, $form_id = 0, $reason = '' ): void {
		unset( $reason );
		if ( RequestRepository::ready() ) {
			self::service()->entry_deleted( (int) $entry_id, (int) $form_id );
		}
	}

	/**
	 * Consent of an entry was withdrawn in Form Captain.
	 *
	 * @param mixed $entry_id Entry id.
	 * @param mixed $form_id  Form id.
	 * @param mixed $source   Source (admin, api, cli, privacy_request, system).
	 */
	public static function consent_withdrawn( $entry_id, $form_id = 0, $source = '' ): void {
		unset( $source );
		if ( RequestRepository::ready() ) {
			self::service()->consent_withdrawn( (int) $entry_id, (int) $form_id );
		}
	}
}
