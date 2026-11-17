<?php
namespace VCAP\Compat;

use VCAP\TemplatePostType;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * WPML / WooCommerce Multilingual: the PDF uses the template translation matching the order language.
 * Post type / meta / settings text translation lives in wpml-config.xml; emails switch to the order language in
 * Multilingual (WCML does not know Voucher Captain's emails sent later by Action Scheduler or cron).
 */
final class Wpml {
	public function register(): void {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}
		add_filter( 'vcap_render_template_id', array( $this, 'template_for_voucher' ), 10, 2 );
	}

	public static function order_language( Voucher $voucher ): string {
		$lang = Multilingual::order_language( $voucher->order() );
		return $lang ?: (string) apply_filters( 'wpml_current_language', null );
	}

	public function template_for_voucher( int $template_id, Voucher $voucher ): int {
		if ( ! $template_id ) {
			return $template_id;
		}
		$tid = (int) apply_filters( 'wpml_object_id', $template_id, TemplatePostType::POST_TYPE, true, self::order_language( $voucher ) );
		return $tid ?: $template_id;
	}
}
