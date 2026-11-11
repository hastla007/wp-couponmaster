<?php
namespace WCVS\Compat;

use WCVS\TemplatePostType;
use WCVS\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * WPML / WooCommerce Multilingual: the PDF uses the template translation matching the order language.
 * Post type / meta translation settings live in wpml-config.xml; WCML already sends customer emails in the order language.
 */
final class Wpml {
	public function register(): void {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}
		add_filter( 'wcvs_render_template_id', array( $this, 'template_for_voucher' ), 10, 2 );
	}

	public static function order_language( Voucher $voucher ): string {
		$order = $voucher->order();
		$lang  = $order ? (string) $order->get_meta( 'wpml_language' ) : '';
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
