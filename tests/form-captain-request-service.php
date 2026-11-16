<?php
/**
 * Form Captain adapter → request service of Voucher Captain (`vcap_voucher_request_service_v1`, contract 1.x).
 * When Voucher Captain has the service, the adapter hands requests over to it instead of its own table.
 *
 *   wp eval-file tests/form-captain-request-service.php
 *
 * @package VCAP
 */

// phpcs:ignoreFile

use VCAP\Integrations\FormCaptain\RequestRepository;
use VCAP\Integrations\FormCaptain\RequestService;

function vcap_fcs_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

if ( ! class_exists( RequestService::class ) ) {
	echo "SKIP: adapter not loaded.\n";
	return;
}

/** Stand-in with the interface of VCAP\Integrations\VoucherRequests (submit(), contract()). */
final class VCAP_FCS_Fake_Service {
	public $calls = array();
	public $by_ref = array();
	public $answer = null;
	public function contract(): string {
		return '1.0.0';
	}
	public function submit( array $request ) {
		$this->calls[] = $request;
		if ( null !== $this->answer ) {
			return $this->answer;
		}
		$ref = $request['reference'];
		if ( isset( $this->by_ref[ $ref ] ) ) {
			return array( 'request_id' => $this->by_ref[ $ref ], 'reference' => $ref, 'state' => 'open', 'replayed' => true );
		}
		$this->by_ref[ $ref ] = 100 + count( $this->by_ref );
		return array( 'request_id' => $this->by_ref[ $ref ], 'reference' => $ref, 'state' => 'open', 'replayed' => false );
	}
}

$fake   = new VCAP_FCS_Fake_Service();
$filter = static function ( $current ) use ( $fake ) {
	return null === $current ? $fake : $current;
};
add_filter( 'vcap_voucher_request_service_v1', $filter );
$service = new RequestService( new RequestRepository() );
$before  = array_sum( ( new RequestRepository() )->counts() );
$job     = array(
	'form_id'         => 7,
	'entry_id'        => 4711,
	'idempotency_key' => 'fc-job-1',
	'settings'        => array( 'product_id' => 0 ),
	'values'          => array(
		'company'         => 'Muster GmbH',
		'contact_name'    => 'Erika Muster',
		'email'           => 'erika@example.org',
		'quantity'        => '3',
		'value'           => '50,00',
		'personalization' => 'Frohe Feiertage',
		'consent'         => '1',
	),
);

vcap_fcs_assert( null !== RequestService::core_service(), 'the request service of Voucher Captain is detected' );
$r = $service->handle_job( $job );
vcap_fcs_assert( 'success' === $r['status'] && 'created' === $r['code'] && 'vcap-request-100' === ( $r['remote_id'] ?? '' ), 'handed over: created with the id of the service' );
$p = $fake->calls[0];
vcap_fcs_assert( 1 === preg_match( '#^formcaptain:[a-f0-9]{8}:7:4711$#', $p['reference'] ), 'reference from site, form and entry' );
vcap_fcs_assert( 'company_order' === $p['kind'] && 3 === $p['quantity'] && '50' === $p['amount'] && 'Muster GmbH' === $p['contact']['company'] && 'erika@example.org' === $p['contact']['email'] && 'Frohe Feiertage' === $p['message'], 'mapped to contract v1 of the service' );
vcap_fcs_assert( ! isset( $p['paid'] ) && ! isset( $p['send'] ) && ! isset( $p['code'] ), 'nothing about payment or issuing is passed' );
vcap_fcs_assert( $before === array_sum( ( new RequestRepository() )->counts() ), 'own fallback table untouched' );

$r = $service->handle_job( $job );
vcap_fcs_assert( 'success' === $r['status'] && 'already_created' === $r['code'] && 'vcap-request-100' === $r['remote_id'], 'duplicate delivery: same request, no second one' );

$personal              = $job;
$personal['entry_id']  = 4712;
$personal['values']['company'] = '';
$service->handle_job( $personal );
vcap_fcs_assert( 'personalisation' === end( $fake->calls )['kind'], 'without company and with a text: personalisation request' );

$bad                    = $job;
$bad['entry_id']        = 4713;
$bad['values']['email'] = 'nope';
$count                  = count( $fake->calls );
$r                      = $service->handle_job( $bad );
vcap_fcs_assert( 'fail' === $r['status'] && 'invalid_email' === $r['code'] && count( $fake->calls ) === $count, 'invalid data: final fail before the service is called' );

$fake->answer = new WP_Error( 'vcap_request_limit', 'busy', array( 'status' => 429 ) );
$r            = $service->handle_job( array_merge( $job, array( 'entry_id' => 4714 ) ) );
vcap_fcs_assert( 'retry' === $r['status'] && 'vcap_request_limit' === $r['code'], 'service busy (429): retry' );
$fake->answer = new WP_Error( 'vcap_invalid_product', 'no', array( 'status' => 400 ) );
$r            = $service->handle_job( array_merge( $job, array( 'entry_id' => 4715 ) ) );
vcap_fcs_assert( 'fail' === $r['status'] && 'vcap_invalid_product' === $r['code'], 'refused by the service (400): final fail' );
$fake->answer = array( 'request_id' => 0, 'reference' => 'x', 'state' => 'rejected', 'replayed' => true );
$r            = $service->handle_job( array_merge( $job, array( 'entry_id' => 4716 ) ) );
vcap_fcs_assert( 'success' === $r['status'] && 'already_rejected' === $r['code'], 'rejected earlier: no new request, no retry loop' );
$fake->answer = null;

$test = $service->handle_job( array_merge( $job, array( 'entry_id' => 4717, 'test_mode' => true ) ) );
vcap_fcs_assert( 'test_ok' === $test['code'] && ! isset( $fake->by_ref[ 'formcaptain:' . substr( md5( (string) home_url() ), 0, 8 ) . ':7:4717' ] ), 'test mode stores nothing in the service' );

remove_filter( 'vcap_voucher_request_service_v1', $filter );
vcap_fcs_assert( null === RequestService::core_service(), 'without the service the adapter uses its own table again' );
echo "Request service hand-over checks passed.\n";
