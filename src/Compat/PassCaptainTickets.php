<?php
namespace VCAP\Compat;

use VCAP\API\V1\RedemptionService;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket vouchers for Pass Captain: a voucher product can name a Pass Captain ticket type ("Concert X,
 * 2 tickets"). The sold voucher is exchanged for those tickets – on the redeem page ([vcap_redeem]), by an
 * admin (voucher page) or by code (vcap()->passcaptain_tickets->exchange()).
 *
 * Flow (CONCEPT: reserve → issue → commit): the voucher is reserved through the redemption API (target
 * "passcaptain:ticket:{type}", channel "passcaptain"), Pass Captain issues the tickets through its versioned
 * ticket service ("wp_pass_captain_ticket_service_v1", idempotent per voucher), and only then is the
 * redemption committed. A temporary Pass Captain failure keeps the reservation, so the same call can simply be
 * repeated; a final one (sold out, ticket type gone) releases it. If the voucher purchase is refunded later
 * (voucher voided), the tickets are revoked in Pass Captain (filter "vcap_passcaptain_revoke_on_void").
 *
 * D14: the work is done by the classes in src/Compat/PassCaptain (ticket contract, exchange, revocation, redeem
 * page, customer account, channel rules, product setup, voucher admin); this class registers the hooks and keeps
 * every public method and constant as a thin delegate.
 */
final class PassCaptainTickets {
	/** @see PassCaptain\TicketContract::SERVICE_FILTER */
	public const SERVICE_FILTER = PassCaptain\TicketContract::SERVICE_FILTER;

	/** @see PassCaptain\TicketContract::META_TYPE */
	public const META_TYPE = PassCaptain\TicketContract::META_TYPE;

	/** @see PassCaptain\TicketContract::META_QTY */
	public const META_QTY = PassCaptain\TicketContract::META_QTY;

	/** @see PassCaptain\TicketContract::META_EVENT */
	public const META_EVENT = PassCaptain\TicketContract::META_EVENT;

	/** @see PassCaptain\TicketContract::META_NAME */
	public const META_NAME = PassCaptain\TicketContract::META_NAME;

	/** @see PassCaptain\RedeemPage::SESSION_CODE */
	public const SESSION_CODE = PassCaptain\RedeemPage::SESSION_CODE;

	/** @see PassCaptain\RedeemPage::SESSION_CHOICE */
	public const SESSION_CHOICE = PassCaptain\RedeemPage::SESSION_CHOICE;

	/** @see PassCaptain\TicketContract::SNAPSHOT_KEY */
	public const SNAPSHOT_KEY = PassCaptain\TicketContract::SNAPSHOT_KEY;

	/** Exchange state per voucher (attempts, Pass Captain's view of the committed exchange). */
	private PassCaptain\ExchangeState $state;
	/** Taking tickets back and giving vouchers back. */
	private PassCaptain\Revocation $revocation;
	/** Exchanging a voucher for its tickets. */
	private PassCaptain\Exchange $exchange;
	/** The redeem page steps (date, attendees, email). */
	private PassCaptain\RedeemPage $redeem;
	/** Customer emails, placeholders and the account page. */
	private PassCaptain\Customer $customer;
	/** Where ticket vouchers may be redeemed (shop, counter, scanner). */
	private PassCaptain\ChannelRules $channels;
	/** Product settings, sale checks and the line snapshot. */
	private PassCaptain\ProductSetup $products;
	/** Voucher admin buttons, actions, notices and labels. */
	private PassCaptain\TicketAdmin $admin;

	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers ) {
		$this->state      = new PassCaptain\ExchangeState( $redemptions );
		$this->revocation = new PassCaptain\Revocation( $redemptions, $vouchers, $this->state );
		$this->exchange   = new PassCaptain\Exchange( $redemptions, $vouchers, $this->state, $this->revocation );
		$this->redeem     = new PassCaptain\RedeemPage( $vouchers, $this->exchange );
		$this->customer   = new PassCaptain\Customer( $vouchers, $this->state );
		$this->channels   = new PassCaptain\ChannelRules();
		$this->products   = new PassCaptain\ProductSetup();
		$this->admin      = new PassCaptain\TicketAdmin( $vouchers, $this->state, $this->revocation, $this->exchange );
	}

	public function register(): void {
		$this->register_lifecycle();
		$this->register_features();
	}

	/** New ticket vouchers: product settings and sale checks. */
	public function register_features(): void {
		add_action( 'vcap_product_settings_fields', array( $this, 'product_fields' ) );
		add_action( 'vcap_product_settings_save', array( $this, 'product_save' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'check_fixed_date_capacity' ), 25, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_capacity' ) );
		add_action( 'admin_notices', array( $this, 'redeem_page_notice' ) );
		// D14: Site Health asks through a filter whether the redeem page is needed (ticket vouchers are sold).
		add_filter( 'vcap_redeem_page_needed', static fn( $needed ) => (bool) $needed || self::sells_ticket_vouchers() );
		add_action( 'admin_notices', array( $this, 'attention_notice' ) );
	}

	/**
	 * Sold ticket vouchers: line snapshot at issue, exchange (redeem page, admin, account), channel rules, revocation, emails and placeholders.
	 */
	public function register_lifecycle(): void {
		add_filter( 'vcap_line_snapshot', array( $this, 'snapshot' ), 10, 2 );
		add_filter( 'vcap_voucher_expires_at', array( $this, 'expires_at_event' ), 10, 2 );
		add_filter( 'vcap_redemption_channel_allowed', array( $this, 'channel_allowed' ), 10, 4 );
		add_filter( 'vcap_shop_channel_refused_message', array( $this, 'shop_refused_message' ), 10, 2 );
		add_filter( 'vcap_redeem_code', array( $this, 'redeem_page' ), 10, 2 );
		add_filter( 'vcap_redeem_prefill', array( $this, 'redeem_prefill' ) );
		add_action( 'vcap_redeem_form_fields', array( $this, 'redeem_fields' ) );
		add_action( 'vcap_voucher_admin_actions', array( $this, 'admin_button' ), 10, 2 );
		add_filter( 'vcap_voucher_admin_action', array( $this, 'admin_action' ), 10, 3 );
		add_filter( 'vcap_voucher_admin_messages', array( $this, 'admin_messages' ) );
		add_filter( 'vcap_event_labels', array( $this, 'event_labels' ) );
		add_filter( 'vcap_scanner_describe', array( $this, 'scanner_describe' ), 10, 2 );
		add_filter( 'vcap_scanner_redeem_refused', array( $this, 'scanner_redeem_refused' ), 10, 2 );
		add_action( 'vcap_voucher_event', array( $this, 'on_voucher_event' ), 10, 2 );
		add_action( 'wp_pass_captain_voucher_tickets_revoked_v1', array( $this, 'on_tickets_revoked' ), 10, 1 );
		add_action( 'vcap_passcaptain_tickets_issued', function () { $this->forget_states(); }, 0 );
		add_filter( 'vcap_voucher_next_steps', array( $this, 'next_steps' ), 10, 2 );
		add_filter( 'vcap_email_intro', array( $this, 'email_intro' ), 10, 3 );
		add_filter( 'vcap_placeholder_definitions', array( $this, 'placeholder_definitions' ) );
		add_filter( 'vcap_placeholders', array( $this, 'placeholders' ), 10, 2 );
		add_action( 'vcap_account_voucher_status', array( $this, 'account_status' ) );
		add_action( 'vcap_account_voucher_actions', array( $this, 'account_actions' ) );
		add_filter( 'vcap_customer_can_resend', array( $this, 'customer_can_resend' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'customer_resend_tickets' ), 5 );
	}

	/** @see PassCaptain\Customer::next_steps() */
	public function next_steps( $steps, $voucher ) {
		return $this->customer->next_steps( $steps, $voucher );
	}

	/** @see PassCaptain\Customer::email_intro() */
	public function email_intro( $intro, $voucher, $email ) {
		return $this->customer->email_intro( $intro, $voucher, $email );
	}

	/** @see PassCaptain\Customer::placeholder_definitions() */
	public function placeholder_definitions( array $defs ): array {
		return $this->customer->placeholder_definitions( $defs );
	}

	/** @see PassCaptain\Customer::placeholders() */
	public function placeholders( $values, $voucher ) {
		return $this->customer->placeholders( $values, $voucher );
	}

	/** Forget cached exchange states (after an exchange, a revocation or a void; PassCaptain\ExchangeState). */
	private function forget_states(): void {
		$this->state->forget_states();
	}

	/** @see PassCaptain\Customer::account_status() */
	public function account_status( $voucher ): void {
		$this->customer->account_status( $voucher );
	}

	/** @see PassCaptain\Customer::account_actions() */
	public function account_actions( $voucher ): void {
		$this->customer->account_actions( $voucher );
	}

	/** @see PassCaptain\Customer::customer_can_resend() */
	public function customer_can_resend( $can, $voucher ) {
		return $this->customer->customer_can_resend( $can, $voucher );
	}

	/** @see PassCaptain\Customer::customer_resend_tickets() */
	public function customer_resend_tickets(): void {
		$this->customer->customer_resend_tickets();
	}

	/** @see PassCaptain\Revocation::on_tickets_revoked() */
	public function on_tickets_revoked( $info ): void {
		$this->revocation->on_tickets_revoked( $info );
	}

	/** PassCaptain\Revocation::restore() (kept for callers that reach the private method by reflection). */
	private function restore( string $key, array $row, Voucher $voucher, string $order_public_id ): bool {
		return $this->revocation->restore( $key, $row, $voucher, $order_public_id );
	}

	/** @see PassCaptain\TicketContract::service() */
	public static function service(): ?object {
		return PassCaptain\TicketContract::service();
	}

	/** @see PassCaptain\TicketContract::key() */
	public static function key( Voucher $voucher, int $attempt = 1 ): string {
		return PassCaptain\TicketContract::key( $voucher, $attempt );
	}

	/** @see PassCaptain\TicketContract::exchange_key() */
	public static function exchange_key( Voucher $voucher, int $attempt = 1 ): string {
		return PassCaptain\TicketContract::exchange_key( $voucher, $attempt );
	}

	/** @see PassCaptain\ExchangeState::attempt() */
	public function attempt( Voucher $voucher ): array {
		return $this->state->attempt( $voucher );
	}

	/** @see PassCaptain\TicketContract::target() */
	public static function target( Voucher $voucher ): ?array {
		return PassCaptain\TicketContract::target( $voucher );
	}

	/** @see PassCaptain\ChannelRules::value_use_allowed() */
	public static function value_use_allowed( Voucher $voucher ): bool {
		return PassCaptain\ChannelRules::value_use_allowed( $voucher );
	}

	/** @see PassCaptain\ChannelRules::channel_allowed() */
	public function channel_allowed( $allowed, $voucher, $channel, $channels ) {
		return $this->channels->channel_allowed( $allowed, $voucher, $channel, $channels );
	}

	/** @see PassCaptain\Revocation::ATTENTION_OPTION */
	public const ATTENTION_OPTION = PassCaptain\Revocation::ATTENTION_OPTION;

	/** @see PassCaptain\TicketAdmin::attention_notice() */
	public function attention_notice(): void {
		$this->admin->attention_notice();
	}

	/** PassCaptain\Revocation::revoke_checked() (kept for callers that reach the private method by reflection). */
	private function revoke_checked( Voucher $voucher, string $exchange_key, string $reason, string $release_key = '' ): bool {
		return $this->revocation->revoke_checked( $voucher, $exchange_key, $reason, $release_key );
	}

	/** @see PassCaptain\TicketAdmin::redeem_page_notice() */
	public function redeem_page_notice(): void {
		$this->admin->redeem_page_notice();
	}

	/** @see PassCaptain\TicketContract::sells_ticket_vouchers() */
	public static function sells_ticket_vouchers(): bool {
		return PassCaptain\TicketContract::sells_ticket_vouchers();
	}

	/** @see PassCaptain\ChannelRules::shop_refused_message() */
	public function shop_refused_message( $message, $voucher ) {
		return $this->channels->shop_refused_message( $message, $voucher );
	}

	/** @see PassCaptain\TicketContract::options() */
	public static function options( Voucher $voucher ): array {
		return PassCaptain\TicketContract::options( $voucher );
	}

	/** @see PassCaptain\TicketContract::fields() */
	public static function fields( int $ticket_type_id ): array {
		return PassCaptain\TicketContract::fields( $ticket_type_id );
	}

	/** @see PassCaptain\Exchange::exchange() */
	public function exchange( string $code, array $args = array() ) {
		return $this->exchange->exchange( $code, $args );
	}

	/** @see PassCaptain\Revocation::on_voucher_event() */
	public function on_voucher_event( $voucher_id, $event ): void {
		$this->revocation->on_voucher_event( $voucher_id, $event );
	}

	/** @see PassCaptain\RedeemPage::redeem_page() */
	public function redeem_page( $handled, $code ) {
		return $this->redeem->redeem_page( $handled, $code );
	}

	/** @see PassCaptain\Customer::mask_email() */
	public static function mask_email( string $email ): string {
		return PassCaptain\Customer::mask_email( $email );
	}

	/** @see PassCaptain\RedeemPage::redeem_prefill() */
	public function redeem_prefill( $value ) {
		return $this->redeem->redeem_prefill( $value );
	}

	/** @see PassCaptain\RedeemPage::redeem_fields() */
	public function redeem_fields(): void {
		$this->redeem->redeem_fields();
	}

	/** @see PassCaptain\ChannelRules::scanner_describe() */
	public function scanner_describe( $data, $voucher ) {
		return $this->channels->scanner_describe( $data, $voucher );
	}

	/** @see PassCaptain\ChannelRules::scanner_redeem_refused() */
	public function scanner_redeem_refused( $refused, $voucher ) {
		return $this->channels->scanner_redeem_refused( $refused, $voucher );
	}

	/** @see PassCaptain\ProductSetup::product_fields() */
	public function product_fields( $product ): void {
		$this->products->product_fields( $product );
	}

	/** @see PassCaptain\ProductSetup::product_save() */
	public function product_save( \WC_Product $product, $post ): void {
		$this->products->product_save( $product, $post );
	}

	/** @see PassCaptain\ProductSetup::expires_at_event() */
	public function expires_at_event( $expires, $item ) {
		return $this->products->expires_at_event( $expires, $item );
	}

	/** @see PassCaptain\ProductSetup::check_fixed_date_capacity() */
	public function check_fixed_date_capacity( $passed, $product_id, $quantity = 1 ) {
		return $this->products->check_fixed_date_capacity( $passed, $product_id, $quantity );
	}

	/** @see PassCaptain\ProductSetup::check_cart_capacity() */
	public function check_cart_capacity(): void {
		$this->products->check_cart_capacity();
	}

	/** @see PassCaptain\ProductSetup::fixed_date_problem() */
	public function fixed_date_problem( \WC_Product $product, int $vouchers ): string {
		return $this->products->fixed_date_problem( $product, $vouchers );
	}

	/** @see PassCaptain\ProductSetup::snapshot() */
	public function snapshot( $extra, $item ) {
		return $this->products->snapshot( $extra, $item );
	}

	/** @see PassCaptain\TicketAdmin::admin_button() */
	public function admin_button( $voucher, $form ): void {
		$this->admin->admin_button( $voucher, $form );
	}

	/** @see PassCaptain\TicketAdmin::admin_action() */
	public function admin_action( $msg, $do, $voucher ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.doFound -- public callback signature kept from before the split.
		return $this->admin->admin_action( $msg, $do, $voucher );
	}

	/** @see PassCaptain\TicketAdmin::admin_messages() */
	public function admin_messages( array $messages ): array {
		return $this->admin->admin_messages( $messages );
	}

	/** @see PassCaptain\TicketAdmin::event_labels() */
	public function event_labels( array $labels ): array {
		return $this->admin->event_labels( $labels );
	}
}
