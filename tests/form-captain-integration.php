<?php
/**
 * Form Captain adapter (VC-FC-01..05): Form Captain + Voucher Captain active.
 *   wp eval-file tests/form-captain-integration.php
 *
 * Uses the contract test kit of Form Captain (tests/integration/Contract/ConnectorContractAssertions.php) for the
 * contract shape checks; Form Captain internals are used here only to set up forms, entries and jobs like the kit does.
 */

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\ConsentService;
use FormCaptain\Service\EntryDeletionService;
use VCAP\Integrations\FormCaptain\Bootstrap;
use VCAP\Integrations\FormCaptain\Connector;
use VCAP\Integrations\FormCaptain\RequestRepository;
use VCAP\Integrations\FormCaptain\RequestService;

function vcap_fc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

if ( ! function_exists( 'form_captain_contract_version' ) || ! class_exists( \FormCaptain\Plugin::class ) ) {
	echo "SKIP: Form Captain is not active.\n";
	return;
}

// Contract test kit of Form Captain, run with a minimal assertion adapter instead of PHPUnit.
$vcap_fc_kit = WP_PLUGIN_DIR . '/form-captain/tests/integration/Contract/ConnectorContractAssertions.php';
if ( ! is_readable( $vcap_fc_kit ) ) {
	$vcap_fc_kit = dirname( ( new ReflectionClass( \FormCaptain\Plugin::class ) )->getFileName(), 2 ) . '/tests/integration/Contract/ConnectorContractAssertions.php';
}
vcap_fc_assert( is_readable( $vcap_fc_kit ), 'the contract test kit of Form Captain is available' );
require_once $vcap_fc_kit;

final class VCAP_FC_Kit {
	use \FormCaptain\Tests\Integration\Contract\ConnectorContractAssertions {
		registry as public;
		enqueue_action_job as public;
		action_instance as public;
		assertConnectorShape as public;
		assertJobArrayShape as public;
		assertActionResultShape as public;
		assertOnlyMappedValues as public;
		assertIdempotentCallback as public;
	}

	private function check( bool $ok, string $message ): void {
		if ( ! $ok ) {
			throw new RuntimeException( 'FAIL (contract kit): ' . $message );
		}
	}
	public function assertMatchesRegularExpression( string $pattern, string $value, string $message = '' ): void {
		$this->check( (bool) preg_match( $pattern, $value ), $message . ' ' . $value );
	}
	public function assertNotSame( $a, $b, string $message = '' ): void {
		$this->check( $a !== $b, $message );
	}
	public function assertSame( $a, $b, string $message = '' ): void {
		$this->check( $a === $b, $message . ' ' . wp_json_encode( array( $a, $b ) ) );
	}
	public function assertTrue( $v, string $message = '' ): void {
		$this->check( true === $v, $message );
	}
	public function assertArrayHasKey( $key, array $a, string $message = '' ): void {
		$this->check( array_key_exists( $key, $a ), $message . ' ' . $key );
	}
	public function assertArrayNotHasKey( $key, array $a, string $message = '' ): void {
		$this->check( ! array_key_exists( $key, $a ), $message . ' ' . $key );
	}
	public function assertIsBool( $v, string $message = '' ): void {
		$this->check( is_bool( $v ), $message );
	}
	public function assertIsArray( $v, string $message = '' ): void {
		$this->check( is_array( $v ), $message );
	}
	public function assertIsInt( $v, string $message = '' ): void {
		$this->check( is_int( $v ), $message );
	}
	public function assertIsString( $v, string $message = '' ): void {
		$this->check( is_string( $v ), $message );
	}
	public function assertContains( $needle, array $haystack, string $message = '' ): void {
		$this->check( in_array( $needle, $haystack, true ), $message );
	}
	public function assertLessThanOrEqual( $max, $v, string $message = '' ): void {
		$this->check( $v <= $max, $message );
	}
}

$kit      = new VCAP_FC_Kit();
$fc       = \FormCaptain\Plugin::instance();
$registry = $fc->get( ConnectorRegistry::class );
$requests = new RequestRepository();
$service  = Bootstrap::service();
$action   = Connector::ACTION;

global $wpdb;
$vcap_fc_vouchers = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vcap_vouchers" ); // phpcs:ignore
$vcap_fc_orders   = static fn(): int => count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'created_via' => 'vcap_form_captain' ) ) );

// --- VC-FC-01: connector, action and explicit mapping --------------------------------------------------------
$connector = null;
foreach ( (array) apply_filters( 'form_captain_connectors_v1', array() ) as $key => $candidate ) {
	if ( Connector::KEY === $key ) {
		$connector = $candidate;
	}
}
vcap_fc_assert( $connector instanceof Connector, 'Voucher Captain registers its connector through form_captain_connectors_v1 after form_captain_loaded_v1' );
$kit->assertConnectorShape( $connector );
vcap_fc_assert( true, 'the connector passes assertConnectorShape() of the contract test kit' );
vcap_fc_assert( Bootstrap::compatible( '1.1.0' ) && Bootstrap::compatible( '1' ) && ! Bootstrap::compatible( '2.0.0' ) && ! Bootstrap::compatible( '' ), 'only contract major 1 boots the adapter' );

$definition = $registry->action( $action );
vcap_fc_assert( is_array( $definition ) && Connector::KEY === $definition['connector'], 'the action "voucher request" is registered with form_captain_register_action_v1()' );
$mapping_keys = array_column( $definition['mapping'], 'key' );
vcap_fc_assert( array( 'company', 'contact_name', 'email', 'quantity', 'value', 'personalization', 'consent' ) === $mapping_keys, 'the field mapping is an explicit allowlist (company, contact, e-mail, quantity, value, personalisation, consent)' );
vcap_fc_assert( ! array_filter( $definition['mapping'], static fn( $m ) => ! empty( $m['allow_sensitive'] ) ), 'no mapping entry asks for sensitive fields' );
vcap_fc_assert( empty( $definition['requires'] ), 'the action needs no entitlement (Voucher Captain is free)' );

wp_set_current_user( 1 );
$registry->deactivate( Connector::KEY );
vcap_fc_assert( ConnectorRegistry::STATE_DISABLED === $registry->state( Connector::KEY ), 'the connector stays disabled until the site opts in' );

// A form with every mapped field plus a phone field that is never mapped.
$forms  = $fc->get( FormRepository::class );
$fields = array(
	array( 'id' => 'f_comp01', 'type' => 'text', 'key' => 'company', 'label' => 'Firma' ),
	array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Ansprechperson', 'required' => true ),
	array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
	array( 'id' => 'f_qty001', 'type' => 'number', 'key' => 'quantity', 'label' => 'Anzahl' ),
	array( 'id' => 'f_val001', 'type' => 'number', 'key' => 'value', 'label' => 'Wert' ),
	array( 'id' => 'f_text01', 'type' => 'textarea', 'key' => 'text', 'label' => 'Personalisierung' ),
	array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Einwilligung' ),
	array( 'id' => 'f_tel001', 'type' => 'tel', 'key' => 'phone', 'label' => 'Telefon' ),
);
$map     = array(
	'company'         => 'f_comp01',
	'contact_name'    => 'f_name01',
	'email'           => 'f_mail01',
	'quantity'        => 'f_qty001',
	'value'           => 'f_val001',
	'personalization' => 'f_text01',
	'consent'         => 'f_cons01',
);
$schema  = array( 'schema_version' => 1, 'fields' => $fields );
$form_id = $forms->create(
	'Gutscheinanfrage ' . wp_generate_password( 6, false ),
	$schema,
	array(
		'notification' => array( 'enabled' => false ),
		'actions'      => array(
			VCAP_FC_Kit::action_instance( $action, 'a_vcap00001', $map ),
			VCAP_FC_Kit::action_instance( $action, 'a_vcap00002', $map ),
		),
	)
);
$forms->publish_version( $form_id, $schema );
$form = $forms->find( $form_id );

$vcap_fc_entry = static function ( array $overrides = array() ) use ( $fc, $form ): int {
	$values = array_merge(
		array(
			'f_comp01' => 'Muster GmbH',
			'f_name01' => 'Ada Muster',
			'f_mail01' => 'ada@example.org',
			'f_qty001' => '1',
			'f_val001' => '50',
			'f_text01' => 'Danke für 10 Jahre!',
			'f_cons01' => '1',
			'f_tel001' => '+43 1 234',
		),
		$overrides
	);
	$repo = $fc->get( EntryRepository::class );
	$id   = $repo->insert(
		array(
			'form_id'         => (int) $form['id'],
			'form_version_id' => (int) $form['published_version_id'],
			'status'          => 'new',
			'request_key'     => hash( 'sha256', uniqid( 'vcfc', true ) ),
		)
	);
	$repo->insert_values( $id, array_filter( $values, static fn( $v ) => null !== $v ) );
	return $id;
};
$vcap_fc_run = static function ( int $job_id ) use ( $fc ): array {
	$fc->get( JobRunner::class )->run_one( $job_id );
	return (array) $fc->get( JobRepository::class )->find( $job_id );
};

$vouchers_before = $vcap_fc_vouchers();
$orders_before   = $vcap_fc_orders();

// Opt-in: a job for the disabled connector is parked, nothing is stored.
$entry  = $vcap_fc_entry();
$job_id = $kit->enqueue_action_job( $form, $entry, $action, 'a_vcap00001' );
$job    = $vcap_fc_run( $job_id );
vcap_fc_assert( 'paused' === $job['status'] && ! $requests->for_entry( $entry ), 'without opt-in the job is paused and no request is stored' );

vcap_fc_assert( true === $registry->activate( Connector::KEY ), 'the site opts in to the connector' );
$job = $vcap_fc_run( $job_id );
vcap_fc_assert( 'succeeded' === $job['status'], 'after the opt-in the job runs and succeeds' );
$rows = $requests->for_entry( $entry );
vcap_fc_assert( 1 === count( $rows ), 'one request is stored for the entry' );
$request = $rows[0];
vcap_fc_assert( RequestService::remote_id( (int) $request['id'] ) === ( $job['result']['remote_id'] ?? '' ), 'the request reference is reported as remote_id' );
vcap_fc_assert(
	'Muster GmbH' === $request['company'] && 'Ada Muster' === $request['contact_name'] && 'ada@example.org' === $request['email']
		&& 1 === (int) $request['quantity'] && 50.0 === (float) $request['value'] && 'Danke für 10 Jahre!' === $request['personalization'] && 1 === (int) $request['consent'],
	'every mapped field arrives in the request'
);
vcap_fc_assert( false === strpos( wp_json_encode( $request ), '234' ), 'the unmapped phone field never reaches Voucher Captain' );
vcap_fc_assert( RequestService::idempotency_key( (int) $form['id'], $entry ) === $request['idempotency_key'] && sprintf( 'form-captain:%d:%d:%d', get_current_blog_id(), $form['id'], $entry ) === $request['idempotency_key'], 'the idempotency key is built from site, form and entry' );
vcap_fc_assert( RequestRepository::STATUS_PENDING === $request['status'], 'the request waits in status "to be reviewed"' );
vcap_fc_assert( $vouchers_before === $vcap_fc_vouchers() && $orders_before === $vcap_fc_orders(), 'a submission creates no voucher and no order' );

// The job array handed to the callback matches the contract (captured with a spy wrapper).
$captured = array();
$spy      = static function ( array $job ) use ( &$captured, $service ): array {
	$captured = $job;
	return $service->handle_job( $job );
};
$built    = ConnectorRegistry::build_values( $definition, $map, $fields, $fc->get( EntryRepository::class )->values_for( $entry ) );
$call     = array(
	'form_id'         => (int) $form['id'],
	'entry_id'        => $entry,
	'idempotency_key' => sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $entry, $action, 'a_vcap00001' ),
	'settings'        => array( 'product_id' => '' ),
	'values'          => $built['values'],
	'context'         => array(),
	'test_mode'       => false,
	'unknown_future'  => 'ignored',
);
$kit->assertJobArrayShape( $call );
$kit->assertOnlyMappedValues( $call, $mapping_keys );
vcap_fc_assert( true, 'job array and values pass assertJobArrayShape() and assertOnlyMappedValues()' );

// --- VC-FC-04: duplicate delivery, retry, final failure ---------------------------------------------------
$kit->assertIdempotentCallback( $spy, $call );
vcap_fc_assert( 1 === count( $requests->for_entry( $entry ) ), 'a repeated delivery (assertIdempotentCallback) gives the same remote_id and no second request' );
$second = $vcap_fc_run( $kit->enqueue_action_job( $form, $entry, $action, 'a_vcap00002' ) );
vcap_fc_assert( 'succeeded' === $second['status'] && ( $second['result']['remote_id'] ?? '' ) === ( $job['result']['remote_id'] ?? '-' ) && 1 === count( $requests->for_entry( $entry ) ), 'a second action instance on the same entry maps to the same request' );

add_filter( 'vcap_form_captain_available', '__return_false' );
$retry_entry = $vcap_fc_entry();
$retry       = $vcap_fc_run( $kit->enqueue_action_job( $form, $retry_entry, $action, 'a_vcap00001' ) );
remove_filter( 'vcap_form_captain_available', '__return_false' );
vcap_fc_assert( 'failed' === $retry['status'] && 'voucher_captain_unavailable' === $retry['last_error_code'] && ! $requests->for_entry( $retry_entry ), 'Voucher Captain unavailable: the job is retried later (retry), nothing stored' );

$invalid = array(
	'invalid_email'    => array( 'f_mail01' => 'not-an-email' ),
	'invalid_quantity' => array( 'f_qty001' => '0' ),
	'invalid_value'    => array( 'f_val001' => 'viel' ),
	'missing_contact'  => array( 'f_name01' => '   ' ),
	'consent_missing'  => array( 'f_cons01' => '' ),
);
foreach ( $invalid as $code => $override ) {
	$bad_entry = $vcap_fc_entry( $override );
	$bad       = $vcap_fc_run( $kit->enqueue_action_job( $form, $bad_entry, $action, 'a_vcap00001' ) );
	vcap_fc_assert( 'dead' === $bad['status'] && $code === $bad['last_error_code'] && ! $requests->for_entry( $bad_entry ), "invalid data is a final failure with code {$code}" );
}
vcap_fc_assert( 50.5 === RequestService::parse_amount( '50,50' ) && 1250.0 === RequestService::parse_amount( '1.250,00' ) && null === RequestService::parse_amount( '-5' ), 'amounts are parsed in German and English notation' );

$test_run = $registry->test_action( $action );
vcap_fc_assert( is_array( $test_run ) && 'success' === $test_run['status'] && 'test_ok' === $test_run['code'] && empty( $test_run['remote_id'] ), 'the Form Captain test run succeeds without storing anything' );
vcap_fc_assert( $vouchers_before === $vcap_fc_vouchers() && $orders_before === $vcap_fc_orders(), 'no failure path created a voucher or an order' );

// --- VC-FC-02: review, approval through the issuing service, rejection -------------------------------------
$product = new WC_Product_Simple();
$product->set_name( 'Firmengutschein' );
$product->set_regular_price( '25' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->save();
$plain = new WC_Product_Simple();
$plain->set_name( 'Kein Gutschein' );
$plain->set_regular_price( '5' );
$plain->save();

$id = (int) $request['id'];
vcap_fc_assert( is_wp_error( $service->approve( $id, (int) $plain->get_id() ) ) && RequestRepository::STATUS_PENDING === $requests->get( $id )['status'], 'approval refuses a product without vouchers and keeps the request open' );
$edited = $service->edit(
	$id,
	array(
		'company'         => 'Muster GmbH',
		'contact_name'    => 'Ada Muster',
		'email'           => 'einkauf@example.org',
		'quantity'        => '1',
		'value'           => '75,00',
		'personalization' => 'Danke für 10 Jahre!',
		'product_id'      => (string) $product->get_id(),
	)
);
vcap_fc_assert( true === $edited && 75.0 === (float) $requests->get( $id )['value'] && 'einkauf@example.org' === $requests->get( $id )['email'], 'a manager can change an open request before approving it' );
vcap_fc_assert( is_wp_error( $service->edit( $id, array( 'contact_name' => 'X', 'email' => 'bad' ) ) ), 'edits are validated like submissions' );

$issued_events = 0;
$count_issued  = static function () use ( &$issued_events ): void {
	++$issued_events;
};
add_action( 'vcap_vouchers_issued', $count_issued );
$approved = $service->approve( $id );
remove_action( 'vcap_vouchers_issued', $count_issued );
vcap_fc_assert( is_array( $approved ) && 1 === $approved['vouchers'], 'approval issues exactly one voucher for a request of one' );
vcap_fc_assert( 1 === $issued_events, 'the vouchers are issued through the issuing service (vcap_vouchers_issued fired once)' );
$order    = wc_get_order( $approved['order_id'] );
$vouchers = vcap()->vouchers->for_order( $order->get_id() );
vcap_fc_assert( 1 === count( $vouchers ) && $vouchers_before + 1 === $vcap_fc_vouchers(), 'exactly one voucher exists for the request' );
vcap_fc_assert( 75.0 === (float) $vouchers[0]->initial_amount && 'Danke für 10 Jahre!' === $vouchers[0]->message && 'einkauf@example.org' === $vouchers[0]->buyer_email, 'the voucher carries the approved value, the personalisation and the contact' );
vcap_fc_assert( 'on-hold' === $order->get_status() && 'Muster GmbH' === $order->get_billing_company() && $id === (int) $order->get_meta( RequestService::ORDER_META ), 'the order is on hold (invoice) and linked to the request' );
$after = $requests->get( $id );
vcap_fc_assert( RequestRepository::STATUS_APPROVED === $after['status'] && (int) $after['order_id'] === $order->get_id() && 1 === (int) $after['vouchers'], 'the request is approved and records order and voucher count' );
vcap_fc_assert( is_wp_error( $service->approve( $id ) ) && 1 === count( vcap()->vouchers->for_order( $order->get_id() ) ), 'a second approval issues nothing' );
$redelivered = $service->handle_job( $call );
vcap_fc_assert( 'success' === $redelivered['status'] && RequestService::remote_id( $id ) === $redelivered['remote_id'] && 1 === count( $requests->for_entry( $entry ) ), 'a late redelivery after approval still maps to the same request' );

$group_entry = $vcap_fc_entry( array( 'f_qty001' => '3', 'f_val001' => '20' ) );
$vcap_fc_run( $kit->enqueue_action_job( $form, $group_entry, $action, 'a_vcap00001' ) );
$group    = $requests->for_entry( $group_entry )[0];
$approved = $service->approve( (int) $group['id'], (int) $product->get_id() );
vcap_fc_assert( is_array( $approved ) && 3 === $approved['vouchers'] && array( 20.0, 20.0, 20.0 ) === array_map( static fn( $v ) => (float) $v->initial_amount, vcap()->vouchers->for_order( $approved['order_id'] ) ), 'a request of three issues exactly three vouchers of the requested value' );

$reject_entry = $vcap_fc_entry();
$vcap_fc_run( $kit->enqueue_action_job( $form, $reject_entry, $action, 'a_vcap00001' ) );
$rejected      = $requests->for_entry( $reject_entry )[0];
$vouchers_then = $vcap_fc_vouchers();
$orders_then   = $vcap_fc_orders();
vcap_fc_assert( true === $service->reject( (int) $rejected['id'], 'Kein Firmenkunde' ), 'a manager can reject a request' );
vcap_fc_assert( RequestRepository::STATUS_REJECTED === $requests->get( (int) $rejected['id'] )['status'] && $vouchers_then === $vcap_fc_vouchers() && $orders_then === $vcap_fc_orders(), 'rejection issues no voucher and creates no order' );
vcap_fc_assert( is_wp_error( $service->approve( (int) $rejected['id'], (int) $product->get_id() ) ) && $vouchers_then === $vcap_fc_vouchers(), 'a rejected request cannot be approved later' );

// Admin screen: list and detail render for a manager.
wp_set_current_user( 1 );
ob_start();
VCAP\Integrations\FormCaptain\RequestsAdmin::page();
$list_html = (string) ob_get_clean();
$_GET['request'] = (string) $rejected['id'];
ob_start();
VCAP\Integrations\FormCaptain\RequestsAdmin::page();
$detail_html = (string) ob_get_clean();
unset( $_GET['request'] );
vcap_fc_assert( false !== strpos( $list_html, 'Muster GmbH' ) && false !== strpos( $list_html, '#' . $id ), 'the request list shows the requests' );
vcap_fc_assert( false !== strpos( $detail_html, 'Kein Firmenkunde' ) && false === strpos( $detail_html, 'value="approve"' ), 'a closed request is shown read-only with its note' );

// --- VC-FC-03: entry deletion and consent withdrawal -----------------------------------------------------
$open_entry = $vcap_fc_entry();
$vcap_fc_run( $kit->enqueue_action_job( $form, $open_entry, $action, 'a_vcap00001' ) );
vcap_fc_assert( 1 === count( $requests->for_entry( $open_entry ) ), 'an open request exists before the entry is erased' );
vcap_fc_assert( $fc->get( EntryDeletionService::class )->delete( $open_entry, 'erasure' ), 'Form Captain erases the entry' );
vcap_fc_assert( ! $requests->for_entry( $open_entry ), 'form_captain_entry_deleted_v1 removes the open request' );

vcap_fc_assert( $fc->get( EntryDeletionService::class )->delete( $entry, 'manual' ), 'Form Captain deletes the entry of the approved request' );
$kept = $requests->get( $id );
vcap_fc_assert( $kept && '' === $kept['email'] && '' === $kept['contact_name'] && '' === $kept['personalization'] && 1 === (int) $kept['anonymized'] && (int) $kept['order_id'] === $order->get_id(), 'an approved request keeps its order link but loses its personal data' );
vcap_fc_assert( 1 === count( vcap()->vouchers->for_order( $order->get_id() ) ), 'deleting the entry never touches issued vouchers' );

$consent_entry = $vcap_fc_entry();
$vcap_fc_run( $kit->enqueue_action_job( $form, $consent_entry, $action, 'a_vcap00001' ) );
$withdrawn = $fc->get( ConsentService::class )->withdraw( $consent_entry, 'admin', 'keep' );
vcap_fc_assert( is_array( $withdrawn ) && $withdrawn['withdrawn'], 'Form Captain records a consent withdrawal' );
$closed = $requests->for_entry( $consent_entry )[0] ?? array();
vcap_fc_assert( RequestRepository::STATUS_WITHDRAWN === ( $closed['status'] ?? '' ) && '' === $closed['email'] && 1 === (int) $closed['anonymized'], 'form_captain_consent_withdrawn_v1 closes the open request and removes its personal data' );
vcap_fc_assert( is_wp_error( $service->approve( (int) $closed['id'], (int) $product->get_id() ) ), 'a withdrawn request can never be approved' );

$registry->deactivate( Connector::KEY );
echo "Form Captain adapter checks passed.\n";
