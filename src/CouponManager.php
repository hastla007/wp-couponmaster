<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates WooCommerce coupons for vouchers and keeps redemption state (single use / stored balance) in sync.
 *
 * D14: the work is done by the classes in src/Coupons (coupon objects, stored balances, checkout holds, order
 * settlement and book-back, single-use tracking, cart rules); this class registers the hooks and keeps every public
 * method and constant as a thin delegate, so callers, hook callbacks and integrations stay unchanged.
 */
final class CouponManager {
	/** Lookups of the vouchers behind an order's coupon lines. */
	private Coupons\OrderCoupons $lookup;
	/** Coupon objects: state sync and protection. */
	private Coupons\CouponSync $sync;
	/** Coupon objects: creation and email restrictions. */
	private Coupons\CouponFactory $factory;
	/** Stored balances: atomic bookings under the voucher row lock. */
	private Coupons\Balances $balances;
	/** Balance holds of orders: commit, release and the stale-hold sweeps. */
	private Coupons\Reservations $reservations;
	/** Single-use vouchers: usage counts, conflicts and cancellations. */
	private Coupons\SingleUse $single_use;
	/** Checkout: reserving balances before payment. */
	private Coupons\Checkout $checkout;
	/** Booking an order's charges back (cancellation, refund). */
	private Coupons\BookBack $book_back;
	/** Order lifecycle: charging balances, shortfalls. */
	private Coupons\Settlement $settlement;
	/** Cart and discount calculation. */
	private Coupons\CartCoupons $cart;

	public function __construct( private Settings $settings, private VoucherRepository $repo ) {
		$this->lookup       = new Coupons\OrderCoupons( $repo );
		$this->sync         = new Coupons\CouponSync( $repo );
		$this->factory      = new Coupons\CouponFactory( $repo );
		$this->balances     = new Coupons\Balances( $repo );
		$this->reservations = new Coupons\Reservations( $repo, $this->balances, $this->lookup );
		$this->single_use   = new Coupons\SingleUse( $settings, $repo, $this->lookup );
		$this->checkout     = new Coupons\Checkout( $this->balances, $this->reservations, $this->single_use, $this->lookup );
		$this->book_back    = new Coupons\BookBack( $repo, $this->balances, $this->lookup );
		$this->settlement   = new Coupons\Settlement( $settings, $repo, $this->balances, $this->reservations, $this->book_back, $this->single_use, $this->lookup );
		$this->cart         = new Coupons\CartCoupons( $settings, $repo, $this->lookup );
	}

	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 20, 3 );
		add_filter( 'woocommerce_coupon_sort', array( $this, 'sort' ), 20, 2 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'prepare_gross_balance' ), 20, 3 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'gross_balance_amount' ), 20, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'order_coupon_object' ), 10, 4 );
		// A code marked "Individual use only" does not push out gift cards, store credit and other multi-purpose
		// vouchers: they are a means of payment, not a discount (classic cart and Store API use both filters).
		add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'keep_payment_means' ), 10, 3 );
		add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'allow_payment_means' ), 10, 2 );
		// Late priority: other plugins (e.g. Pass Captain's capacity hold at 10) validate first, so a checkout they
		// refuse never leaves a balance reservation behind. A previous unpaid checkout order of the same session
		// gives its holds back first (classic checkout creates a new order after a failed attempt).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'release_previous_checkout_order' ), 49, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'release_previous_checkout_order' ), 49, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reserve_order' ), 51, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reserve_store_api_order' ), 51, 1 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'reserve_retry_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
		// Charge stored balances before anything is fulfilled (vouchers, bonus coupons, Pass Captain tickets run
		// on the same status hooks with later priorities) and hold fulfilment while a shortfall is unresolved.
		// Registered on init so a theme's or late plugin's `vcap_balance_deduct_statuses` filter is respected.
		if ( did_action( 'init' ) ) {
			$this->register_settle_hooks();
		} else {
			add_action( 'init', array( $this, 'register_settle_hooks' ), 0 );
		}
		add_filter( 'vcap_should_issue_for_order', array( $this, 'issue_unless_shortfall' ), 5, 2 );
		add_filter( 'wpcap_woocommerce_hold_fulfilment', array( $this, 'hold_on_shortfall' ), 10, 2 );
		add_action( 'woocommerce_increase_coupon_usage_count', array( $this, 'usage_increased' ), 10, 3 );
		// Right before WooCommerce counts the order's coupons (priority 10): a single-use voucher that was voided or
		// redeemed elsewhere since checkout must not be fulfilled with its discount.
		foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'check_single_use_conflicts' ), 9, 2 );
			// The order whose coupons WooCommerce counts next (priority 10): the redemption of a single-use
			// voucher is recorded with it (D8), the usage hook itself names no order.
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'remember_usage_order' ), 8, 1 );
		}
		// WooCommerce before 9.x keeps the coupon usage of a failed order counted, so the check above skips it: an
		// unpaid order that is paid now is checked once more for vouchers voided meanwhile (before order_status_changed()).
		add_action( 'woocommerce_order_status_changed', array( $this, 'check_single_use_conflicts_on_payment' ), 5, 4 );
		add_action( 'woocommerce_decrease_coupon_usage_count', array( $this, 'usage_decreased' ), 10, 3 );
		// WooCommerce gives coupon uses back on "Cancelled" (priority 10): with "Restore balance on cancellation"
		// off, single-use vouchers stay redeemed like balances.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'keep_single_use_start' ), 1, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'keep_single_use_end' ), 99, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'cart_balance_notice' ) );
		// Free balance holds of unpaid orders that are trashed, deleted or abandoned.
		add_action( 'woocommerce_trash_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'wp_trash_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'before_delete_post', array( $this, 'release_deleted_order' ), 5 );
		add_action( 'vcap_release_stale_order_reservations', array( $this, 'release_stale_order_reservations' ) );
		// A voucher's coupon follows the voucher row: an amount edited or saved in WooCommerce is set back to the
		// balance, and trashing or deleting it in wp-admin or over the REST API is refused (logged otherwise).
		add_action( 'woocommerce_update_coupon', array( $this, 'coupon_saved' ), 20, 2 );
		add_filter( 'pre_trash_post', array( $this, 'protect_coupon' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'protect_coupon' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'log_coupon_removed' ), 20 );
		add_action( 'before_delete_post', array( $this, 'log_coupon_removed' ), 20 );
	}

	/** @see Coupons\CouponSync::sync_coupon_amount() */
	public static function sync_coupon_amount( Voucher $voucher ): bool {
		return Coupons\CouponSync::sync_coupon_amount( $voucher );
	}

	/** @see Coupons\CouponSync::SYNC_ASPECTS */
	public const SYNC_ASPECTS = Coupons\CouponSync::SYNC_ASPECTS;

	/** @see Coupons\CouponSync::sync_coupon() */
	public static function sync_coupon( Voucher $voucher, array $what ): bool {
		return Coupons\CouponSync::sync_coupon( $voucher, $what );
	}

	/** @see Coupons\CouponSync::coupon_usage_for() */
	public static function coupon_usage_for( string $usage_mode, string $status, int $limit, int $count ): array {
		return Coupons\CouponSync::coupon_usage_for( $usage_mode, $status, $limit, $count );
	}

	/** @see Coupons\CouponSync::coupon_enabled_for() */
	public static function coupon_enabled_for( string $status ): ?bool {
		return Coupons\CouponSync::coupon_enabled_for( $status );
	}

	/** @see Coupons\CouponSync::set_coupon_post_status() */
	public static function set_coupon_post_status( int $coupon_id, string $code, bool $enabled ): bool {
		return Coupons\CouponSync::set_coupon_post_status( $coupon_id, $code, $enabled );
	}

	/** @see Coupons\CouponSync::coupon_saved() */
	public function coupon_saved( $coupon_id, $coupon = null ): void {
		$this->sync->coupon_saved( $coupon_id, $coupon );
	}

	/** @see Coupons\CouponSync::protect_coupon() */
	public function protect_coupon( $check, $post = null ) {
		return $this->sync->protect_coupon( $check, $post );
	}

	/** @see Coupons\CouponSync::log_coupon_removed() */
	public function log_coupon_removed( $post_id ): void {
		$this->sync->log_coupon_removed( $post_id );
	}

	/** @see Coupons\Reservations::release_deleted_order() */
	public function release_deleted_order( $order_or_id ): void {
		$this->reservations->release_deleted_order( $order_or_id );
	}

	/** @see Coupons\Reservations::schedule_stale_release() */
	public function schedule_stale_release(): void {
		$this->reservations->schedule_stale_release();
	}

	/** @see Coupons\Reservations::release_stale_order_reservations() */
	public function release_stale_order_reservations(): void {
		$this->reservations->release_stale_order_reservations();
	}

	/** @see Coupons\OrderCoupons::order_map() */
	public static function order_map( \WC_Order $order, string $key ): array {
		return Coupons\OrderCoupons::order_map( $order, $key );
	}

	/** @see Coupons\CouponFactory::code_exists() */
	public function code_exists( string $code ): bool {
		return $this->factory->code_exists( $code );
	}

	/** @see Coupons\CouponFactory::create_coupon() */
	public function create_coupon( Voucher $voucher, array $config, string $description = '' ): int {
		return $this->factory->create_coupon( $voucher, $config, $description );
	}

	/** @see Coupons\CouponFactory::email_restrictions() */
	public static function email_restrictions( Voucher $voucher, string $restrict ): array {
		return Coupons\CouponFactory::email_restrictions( $voucher, $restrict );
	}

	/**
	 * @see Coupons\CouponFactory::refresh_email_restrictions()
	 *
	 * @param string $old_recipient Recipient email before the change.
	 */
	public function refresh_email_restrictions( Voucher $voucher, string $old_recipient = '' ): bool {
		$done                   = $this->factory->refresh_email_restrictions( $voucher, $old_recipient );
		$this->restriction_kept = $this->factory->restriction_kept;
		return $done;
	}

	/** Whether the last refresh_email_restrictions() kept the old restriction for want of a valid address. */
	public bool $restriction_kept = false;

	/** @see Coupons\CouponFactory::set_coupon_enabled() */
	public function set_coupon_enabled( Voucher $voucher, bool $enabled ): bool {
		return $this->factory->set_coupon_enabled( $voucher, $enabled );
	}

	/** @see Coupons\Checkout::reserve_order() */
	public function reserve_order( $order_or_id, $posted_data = null, $order = null ): void {
		$this->checkout->reserve_order( $order_or_id, $posted_data, $order );
	}

	/** @see Coupons\Checkout::SESSION_ORDER */
	public const SESSION_ORDER = Coupons\Checkout::SESSION_ORDER;

	/** @see Coupons\Checkout::release_previous_checkout_order() */
	public function release_previous_checkout_order( $order_or_id ): void {
		$this->checkout->release_previous_checkout_order( $order_or_id );
	}

	/** @see Coupons\Checkout::reserve_store_api_order() */
	public function reserve_store_api_order( $order ): void {
		$this->checkout->reserve_store_api_order( $order );
	}

	/** @see Coupons\Checkout::BALANCE_CHANGED */
	public const BALANCE_CHANGED = Coupons\Checkout::BALANCE_CHANGED;

	/** @see Coupons\Checkout::reserve_retry_order() */
	public function reserve_retry_order( $order ): void {
		$this->checkout->reserve_retry_order( $order );
	}

	/**
	 * Public order-scoped operations for the versioned integration service.
	 * The order id is the idempotency scope used by the event ledger.
	 * Atomically reserve stored balance for a specific order.
	 *
	 * @return array{amount:float,balance:float,state:string}|null
	 */
	public function reserve_voucher_balance( Voucher $voucher, float $amount, \WC_Order $order, string $note = '' ): ?array {
		return $this->balances->reserve_balance_atomic( $voucher, $amount, (int) $order->get_id(), $note );
	}

	/** @return array{amount:float,balance:float}|null */
	public function commit_voucher_reservation( Voucher $voucher, \WC_Order $order ): ?array {
		return $this->balances->commit_reservation_atomic( $voucher, (int) $order->get_id() );
	}

	public function release_voucher_reservation( Voucher $voucher, \WC_Order $order, string $note = '' ): bool {
		return $this->balances->release_reservation_atomic( $voucher, (int) $order->get_id(), $note );
	}

	/** @see Coupons\Balances::ORDER_CHARGE_EVENTS */
	public const ORDER_CHARGE_EVENTS = Coupons\Balances::ORDER_CHARGE_EVENTS;

	/** @see Coupons\Balances::ORDER_CHARGE_EVENTS_SQL */
	public const ORDER_CHARGE_EVENTS_SQL = Coupons\Balances::ORDER_CHARGE_EVENTS_SQL;

	/** Coupons\Balances::reserve_balance_atomic() (kept for callers that reach the private method by reflection). */
	private function reserve_balance_atomic( Voucher $voucher, float $requested, int $order_id, string $note ): ?array {
		return $this->balances->reserve_balance_atomic( $voucher, $requested, $order_id, $note );
	}

	/** @see Coupons\Balances::open_holds() */
	public function open_holds( Voucher $voucher ): float {
		return $this->balances->open_holds( $voucher );
	}

	/** @see Coupons\Reservations::release_orphaned_holds() */
	public function release_orphaned_holds( int $cutoff ): int {
		return $this->reservations->release_orphaned_holds( $cutoff );
	}

	/** @see Coupons\Reservations::ROUNDING_SHORTFALL_META */
	public const ROUNDING_SHORTFALL_META = Coupons\Reservations::ROUNDING_SHORTFALL_META;

	/** @see Coupons\Balances::ROUNDING_TOLERANCE_UNITS */
	public const ROUNDING_TOLERANCE_UNITS = Coupons\Balances::ROUNDING_TOLERANCE_UNITS;

	/** @see Coupons\Balances::rounding_tolerance() */
	public static function rounding_tolerance( ?\WC_Order $order, float $amount, ?Voucher $voucher ): float {
		return Coupons\Balances::rounding_tolerance( $order, $amount, $voucher );
	}

	/** @see Coupons\Balances::tolerance_for() */
	public static function tolerance_for( string $currency, string $base, float $amount, int $decimals ): float {
		return Coupons\Balances::tolerance_for( $currency, $base, $amount, $decimals );
	}

	/** @see Coupons\Balances::set_balance() */
	public function set_balance( Voucher $voucher, float $balance, string $event, ?float $amount = null, string $note = '', int $order_id = 0 ): bool {
		return $this->balances->set_balance( $voucher, $balance, $event, $amount, $note, $order_id );
	}

	/** @see Coupons\Balances::adjust_balance() */
	public function adjust_balance( Voucher $voucher, float $delta, string $event, string $note = '', int $order_id = 0, ?float &$booked = null ): bool {
		return $this->balances->adjust_balance( $voucher, $delta, $event, $note, $order_id, $booked );
	}

	/** @see Coupons\Balances::balance_refusal() */
	public static function balance_refusal( ?Voucher $voucher ): string {
		return Coupons\Balances::balance_refusal( $voucher );
	}

	/** @see Coupons\Balances::minor_units() */
	public static function minor_units( float $amount, int $decimals ): int {
		return Coupons\Balances::minor_units( $amount, $decimals );
	}

	/** @see Coupons\Balances::deduct_balance_atomic() */
	public function deduct_balance_atomic( Voucher $voucher, float $requested, string $note = '', int $order_id = 0, bool $allow_partial = true, ?string &$reason = null ): ?array {
		return $this->balances->deduct_balance_atomic( $voucher, $requested, $note, $order_id, $allow_partial, $reason );
	}

	/** @see Coupons\CartCoupons::sort() */
	public function sort( $sort, $coupon ) {
		return $this->cart->sort( $sort, $coupon );
	}

	/** @see Coupons\CartCoupons::validate() */
	public function validate( $valid, $coupon, $discounts = null ) {
		return $this->cart->validate( $valid, $coupon, $discounts );
	}

	/** @see Coupons\CartCoupons::is_payment_means() */
	public function is_payment_means( $coupon ): bool {
		return $this->cart->is_payment_means( $coupon );
	}

	/** @see Coupons\CartCoupons::keep_payment_means() */
	public function keep_payment_means( $keep, $coupon = null, $applied = array() ) {
		return $this->cart->keep_payment_means( $keep, $coupon, $applied );
	}

	/** @see Coupons\CartCoupons::allow_payment_means() */
	public function allow_payment_means( $allow, $coupon = null ) {
		return $this->cart->allow_payment_means( $allow, $coupon );
	}

	/** @see Coupons\CartCoupons::cart_balance_notice() */
	public function cart_balance_notice(): void {
		$this->cart->cart_balance_notice();
	}

	/** @see Coupons\CartCoupons::remaining_after_cart() */
	public static function remaining_after_cart( Voucher $voucher, \WC_Cart $cart, string $code, array $payments ): float {
		return Coupons\CartCoupons::remaining_after_cart( $voucher, $cart, $code, $payments );
	}

	/** @see Coupons\CartCoupons::remaining_balance_note() */
	public static function remaining_balance_note( Voucher $voucher, float $remaining ): string {
		return Coupons\CartCoupons::remaining_balance_note( $voucher, $remaining );
	}

	/** @see Coupons\CartCoupons::order_coupon_object() */
	public function order_coupon_object( $coupon, $code, $item = null, $order = null ) {
		return $this->cart->order_coupon_object( $coupon, $code, $item, $order );
	}

	/** @see Coupons\CartCoupons::prepare_gross_balance() */
	public function prepare_gross_balance( $items, $coupon, $discounts = null ) {
		return $this->cart->prepare_gross_balance( $items, $coupon, $discounts );
	}

	/** @see Coupons\CartCoupons::gross_balance_amount() */
	public function gross_balance_amount( $amount, $coupon ) {
		return $this->cart->gross_balance_amount( $amount, $coupon );
	}

	/** @see Coupons\CartCoupons::currency_message() */
	public static function currency_message( string $currency ): string {
		return Coupons\CartCoupons::currency_message( $currency );
	}

	/** @see Coupons\Settlement::SHORTFALL_META */
	public const SHORTFALL_META = Coupons\Settlement::SHORTFALL_META;

	/** @see Coupons\Settlement::SHORTFALL_AMOUNTS_META */
	public const SHORTFALL_AMOUNTS_META = Coupons\Settlement::SHORTFALL_AMOUNTS_META;

	/** @see Coupons\Settlement::has_shortfall() */
	public static function has_shortfall( \WC_Order $order ): bool {
		return Coupons\Settlement::has_shortfall( $order );
	}

	/** @see Coupons\Settlement::deduct_statuses() */
	public static function deduct_statuses(): array {
		return Coupons\Settlement::deduct_statuses();
	}

	public function register_settle_hooks(): void {
		foreach ( self::deduct_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'settle_before_fulfilment' ), 1, 2 );
		}
	}

	/** @see Coupons\Settlement::settle_before_fulfilment() */
	public function settle_before_fulfilment( $order_id, $order = null ): void {
		$this->settlement->settle_before_fulfilment( $order_id, $order );
	}

	/** @see Coupons\Settlement::issue_unless_shortfall() */
	public function issue_unless_shortfall( $issue, $order ) {
		return $this->settlement->issue_unless_shortfall( $issue, $order );
	}

	/** @see Coupons\Settlement::hold_on_shortfall() */
	public function hold_on_shortfall( $hold, $order ) {
		return $this->settlement->hold_on_shortfall( $hold, $order );
	}

	/** @see Coupons\Settlement::accept_shortfall() */
	public function accept_shortfall( \WC_Order $order ): void {
		$this->settlement->accept_shortfall( $order );
	}

	/** @see Coupons\Settlement::order_status_changed() */
	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		$this->settlement->order_status_changed( $order_id, $from, $to, $order );
	}

	/** @see Coupons\Settlement::deduct_order() */
	public function deduct_order( \WC_Order $order, bool $hold = true ): void {
		$this->settlement->deduct_order( $order, $hold );
	}

	/** @see Coupons\Settlement::WRITTEN_OFF_EVENT */
	public const WRITTEN_OFF_EVENT = Coupons\Settlement::WRITTEN_OFF_EVENT;

	/** @see Coupons\Settlement::WRITTEN_OFF_META */
	public const WRITTEN_OFF_META = Coupons\Settlement::WRITTEN_OFF_META;

	/** Coupons\Balances::restore_balance_atomic() (kept for callers that reach the private method by reflection). */
	private function restore_balance_atomic( Voucher $voucher, float $amount, int $order_id, string $note, ?float $charged = null ): bool {
		return $this->balances->restore_balance_atomic( $voucher, $amount, $order_id, $note, $charged );
	}

	/** @see Coupons\BookBack::restore_order() */
	public function restore_order( \WC_Order $order ): void {
		$this->book_back->restore_order( $order );
	}

	/** @see Coupons\BookBack::RETURNED_META */
	public const RETURNED_META = Coupons\BookBack::RETURNED_META;

	/** @see Coupons\BookBack::RETURNED_EVENT */
	public const RETURNED_EVENT = Coupons\BookBack::RETURNED_EVENT;

	/** @see Coupons\BookBack::check_restored_card() */
	public static function check_restored_card( VoucherRepository $repo, \WC_Order $order, Voucher $voucher, float $amount ): void {
		Coupons\BookBack::check_restored_card( $repo, $order, $voucher, $amount );
	}

	/** @see Coupons\SingleUse::SINGLE_USE_RETURNED_META */
	public const SINGLE_USE_RETURNED_META = Coupons\SingleUse::SINGLE_USE_RETURNED_META;

	/** @see Coupons\SingleUse::keep_single_use_start() */
	public function keep_single_use_start( $order_id ): void {
		$this->single_use->keep_single_use_start( $order_id );
	}

	/** @see Coupons\SingleUse::keep_single_use_end() */
	public function keep_single_use_end( $order_id, $order = null ): void {
		$this->single_use->keep_single_use_end( $order_id, $order );
	}

	/** @see Coupons\SingleUse::held_by_pending_order() */
	public static function held_by_pending_order( Voucher $voucher ): bool {
		return Coupons\SingleUse::held_by_pending_order( $voucher );
	}

	/** @see Coupons\SingleUse::open_orders_with() */
	public static function open_orders_with( Voucher $voucher ): array {
		return Coupons\SingleUse::open_orders_with( $voucher );
	}

	/** @see Coupons\SingleUse::single_use_conflicts() */
	public function single_use_conflicts( \WC_Order $order, bool $unpaid = false ): array {
		return $this->single_use->single_use_conflicts( $order, $unpaid );
	}

	/** @see Coupons\SingleUse::check_single_use_conflicts() */
	public function check_single_use_conflicts( $order_id, $order = null ): void {
		$this->single_use->check_single_use_conflicts( $order_id, $order );
	}

	/** @see Coupons\SingleUse::check_single_use_conflicts_on_payment() */
	public function check_single_use_conflicts_on_payment( $order_id, $from, $to, $order = null ): void {
		$this->single_use->check_single_use_conflicts_on_payment( $order_id, $from, $to, $order );
	}

	/** @see Coupons\SingleUse::remember_usage_order() */
	public function remember_usage_order( $order_id ): void {
		$this->single_use->remember_usage_order( $order_id );
	}

	/** @see Coupons\SingleUse::usage_increased() */
	public function usage_increased( $coupon, $new_count, $used_by ): void {
		$this->single_use->usage_increased( $coupon, $new_count, $used_by );
	}

	/** @see Coupons\SingleUse::usage_decreased() */
	public function usage_decreased( $coupon, $new_count, $used_by ): void {
		$this->single_use->usage_decreased( $coupon, $new_count, $used_by );
	}
}
