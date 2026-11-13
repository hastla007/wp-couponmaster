<?php
namespace VCAP\Admin;

use VCAP\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * First steps after activation: a dismissible checklist (redeem and balance pages with one click, a first
 * voucher product, issue status, redemption conditions). It disappears once everything is done or dismissed.
 */
final class Onboarding {
	public const DISMISSED = 'vcap_onboarding_dismissed';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_vcap_onboarding', array( $this, 'handle' ) );
	}

	private function can(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/** Published page containing one of the shortcodes or blocks, 0 when there is none. */
	private static function page_with( array $shortcodes, string $block ): int {
		global $wpdb;
		$where = array( $wpdb->prepare( 'post_content LIKE %s', '%<!-- wp:' . $wpdb->esc_like( $block ) . '%' ) );
		foreach ( $shortcodes as $shortcode ) {
			$where[] = $wpdb->prepare( 'post_content LIKE %s', '%[' . $wpdb->esc_like( $shortcode ) . '%' );
		}
		return (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( " . implode( ' OR ', $where ) . ' ) ORDER BY ID LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- parts prepared above.
	}

	/**
	 * The checklist with the state of each step.
	 *
	 * @return array<string, array{label:string, done:bool, action:string, url:string, button:string}>
	 */
	public static function steps(): array {
		$settings = admin_url( 'admin.php?page=wc-settings&tab=vcap' );
		$has_product = (bool) get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_vcap_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array(
			'redeem_page'  => array(
				'label'  => __( 'A page where customers check and redeem their voucher codes (links in emails and PDFs lead there)', 'voucher-captain' ),
				'done'   => '' !== Shortcodes::redeem_url(),
				'action' => 'redeem_page',
				'url'    => '',
				'button' => __( 'Create page', 'voucher-captain' ),
			),
			'balance_page' => array(
				'label'  => __( 'A page where customers check the remaining value of a gift card', 'voucher-captain' ),
				'done'   => self::page_with( array( 'vcap_balance', 'vcap_voucher_status' ), 'vcap/balance' ) > 0,
				'action' => 'balance_page',
				'url'    => '',
				'button' => __( 'Create page', 'voucher-captain' ),
			),
			'product'      => array(
				'label'  => __( 'A voucher product: create a virtual product, tick "Voucher" and place a test order', 'voucher-captain' ),
				'done'   => $has_product,
				'action' => '',
				'url'    => admin_url( 'post-new.php?post_type=product' ),
				'button' => __( 'Add product', 'voucher-captain' ),
			),
			'issue_status' => array(
				'label'  => __( 'Check when vouchers are issued (default: order "Processing" or "Completed", i.e. paid)', 'voucher-captain' ),
				'done'   => false !== get_option( 'vcap_issue_statuses', false ),
				'action' => '',
				'url'    => $settings,
				'button' => __( 'Open settings', 'voucher-captain' ),
			),
			'terms'        => array(
				'label'  => __( 'Redemption conditions printed on vouchers and shown in emails (have them checked legally)', 'voucher-captain' ),
				'done'   => '' !== trim( (string) get_option( 'vcap_default_terms', '' ) ),
				'action' => '',
				'url'    => $settings,
				'button' => __( 'Open settings', 'voucher-captain' ),
			),
		);
	}

	public function notice(): void {
		if ( ! $this->can() || 'yes' === get_option( self::DISMISSED ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' ), true ) || str_contains( (string) $screen->id, 'vcap' ) ) ) {
			return;
		}
		$steps = self::steps();
		if ( ! array_filter( $steps, static fn( $step ) => ! $step['done'] ) ) {
			return;
		}
		echo '<div class="notice notice-info vcap-onboarding"><p><strong>' . esc_html__( 'Voucher Captain – first steps', 'voucher-captain' ) . '</strong></p><ul style="list-style:none;margin-left:0">';
		foreach ( $steps as $step ) {
			echo '<li>' . ( $step['done'] ? '<span aria-hidden="true">&#10003;</span> <span class="screen-reader-text">' . esc_html__( 'Done:', 'voucher-captain' ) . '</span>' : '<span aria-hidden="true">&#9675;</span> ' ) . esc_html( $step['label'] );
			if ( ! $step['done'] ) {
				if ( '' !== $step['action'] ) {
					echo ' ' . $this->button( $step['action'], $step['button'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().
				} else {
					echo ' <a class="button button-small" href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['button'] ) . '</a>';
				}
			}
			echo '</li>';
		}
		echo '</ul><p>' . $this->button( 'dismiss', __( 'Hide this checklist', 'voucher-captain' ), 'button-link' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button().
	}

	private function button( string $do, string $label, string $class = 'button button-small' ): string {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'vcap_onboarding', 'do' => $do ), admin_url( 'admin-post.php' ) ), 'vcap_onboarding_' . $do );
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public function handle(): void {
		$do = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'vcap_onboarding_' . $do );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$back = wp_get_referer() ?: admin_url();
		if ( 'dismiss' === $do ) {
			update_option( self::DISMISSED, 'yes', false );
		} elseif ( in_array( $do, array( 'redeem_page', 'balance_page' ), true ) ) {
			$page = self::create_page( $do );
			if ( $page ) {
				/* translators: %s: page title */
				Flash::add( 'success', sprintf( __( 'The page "%s" was created and published. Adjust it like any other page.', 'voucher-captain' ), get_the_title( $page ) ) );
			} else {
				Flash::add( 'error', __( 'The page could not be created.', 'voucher-captain' ) );
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	/** Create and publish the redeem or balance page; the redeem page is also selected in the settings. */
	public static function create_page( string $which ): int {
		$redeem = 'redeem_page' === $which;
		$id     = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $redeem ? __( 'Redeem voucher', 'voucher-captain' ) : __( 'Check voucher balance', 'voucher-captain' ),
				'post_content' => $redeem ? '<!-- wp:shortcode -->[vcap_redeem]<!-- /wp:shortcode -->' : '<!-- wp:shortcode -->[vcap_balance]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		if ( $redeem ) {
			update_option( 'vcap_redeem_page_id', (int) $id );
			delete_transient( 'vcap_redeem_page_guess' );
		}
		return (int) $id;
	}
}
