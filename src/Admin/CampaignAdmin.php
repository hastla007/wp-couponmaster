<?php
namespace VCAP\Admin;

use VCAP\Campaigns;

defined( 'ABSPATH' ) || exit;

/**
 * "Generate codes" screen: bulk coupons from a template coupon or code pool batches, with CSV export.
 */
final class CampaignAdmin {
	private const REPORT = 'vcap_generate_report_';

	public function __construct( private Campaigns $campaigns ) {}

	public function register(): void {
		add_action( 'admin_post_vcap_generate_codes', array( $this, 'handle' ) );
		add_action( 'admin_post_vcap_export_codes', array( $this, 'export' ) );
	}

	private function can(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
	}

	private function template_coupons(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'publish',
				'numberposts' => 300,
				'fields'      => 'ids',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_vcap_voucher', 'compare' => 'NOT EXISTS' ),
					array( 'key' => Campaigns::META, 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$coupon      = new \WC_Coupon( $id );
			$out[ $id ] = $coupon->get_code() . ' – ' . ( 'percent' === $coupon->get_discount_type() ? \VCAP\Format::percent( $coupon->get_amount() ) : wp_strip_all_tags( wc_price( (float) $coupon->get_amount() ) ) );
		}
		return $out;
	}

	public function page(): void {
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		$report = get_transient( self::REPORT . get_current_user_id() );
		delete_transient( self::REPORT . get_current_user_id() );
		$templates = $this->template_coupons();
		?>
		<div class="wrap vcap-wrap">
			<h1><?php esc_html_e( 'Generate codes', 'voucher-captain' ); ?></h1>
			<p><?php esc_html_e( 'Create many unique codes at once, e.g. for partners, flyers or campaigns, and download them as CSV.', 'voucher-captain' ); ?></p>

			<?php if ( is_array( $report ) ) : ?>
				<div class="notice <?php echo $report['error'] ? 'notice-error' : 'notice-success'; ?>"><p>
					<?php
					echo esc_html(
						$report['error'] ? $report['error'] : ( ! empty( $report['message'] ) ? $report['message'] : sprintf(
							/* translators: 1: number of codes, 2: campaign or batch name */
							__( '%1$d codes created in "%2$s".', 'voucher-captain' ),
							$report['count'],
							$report['name']
						) )
					);
					?>
					<?php if ( ! $report['error'] && empty( $report['message'] ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $this->export_url( $report['target'], $report['name'] ) ); ?>"><?php esc_html_e( 'Download CSV', 'voucher-captain' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<div class="vcap-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vcap_generate_codes" />
					<?php wp_nonce_field( 'vcap_generate_codes' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Create', 'voucher-captain' ); ?></th>
							<td>
								<label><input type="radio" name="target" value="coupons" checked /> <?php esc_html_e( 'WooCommerce coupons (copy all settings of a template coupon)', 'voucher-captain' ); ?></label><br>
								<label><input type="radio" name="target" value="pool" /> <?php esc_html_e( 'Codes for voucher products (code pool batch)', 'voucher-captain' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-template"><?php esc_html_e( 'Template coupon', 'voucher-captain' ); ?></label></th>
							<td>
								<select id="vcap-template" name="template">
									<option value="0"><?php esc_html_e( '— Select —', 'voucher-captain' ); ?></option>
									<?php foreach ( $templates as $id => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Create a normal coupon under Marketing → Coupons with the discount, restrictions, expiry and usage limits you want; it is copied for every code.', 'voucher-captain' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-name"><?php esc_html_e( 'Campaign / batch name', 'voucher-captain' ); ?></label></th>
							<td><input type="text" id="vcap-name" name="name" class="regular-text" required maxlength="100" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vcap-count"><?php esc_html_e( 'Number of codes', 'voucher-captain' ); ?></label></th>
							<td><input type="number" id="vcap-count" name="count" value="100" min="1" max="<?php echo esc_attr( (string) Campaigns::MAX_RUN ); ?>" class="small-text" required /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Code format', 'voucher-captain' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Prefix', 'voucher-captain' ); ?> <input type="text" name="prefix" class="small-text" maxlength="20" /></label>
								<label><?php esc_html_e( 'Random part length', 'voucher-captain' ); ?> <input type="number" name="length" value="12" min="6" max="40" class="small-text" /></label>
								<label><?php esc_html_e( 'Suffix', 'voucher-captain' ); ?> <input type="text" name="suffix" class="small-text" maxlength="20" /></label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Generate codes', 'voucher-captain' ) ); ?>
				</form>
			</div>

			<h2><?php esc_html_e( 'Campaigns', 'voucher-captain' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Campaign', 'voucher-captain' ); ?></th><th><?php esc_html_e( 'Coupons', 'voucher-captain' ); ?></th><?php do_action( 'vcap_campaigns_table_header' ); ?><th></th></tr></thead>
				<tbody>
				<?php
				$campaigns = $this->campaigns->campaigns();
				if ( ! $campaigns ) {
					echo '<tr><td colspan="' . ( has_action( 'vcap_campaigns_table_cell' ) ? 4 : 3 ) . '">' . esc_html__( 'No campaigns yet.', 'voucher-captain' ) . '</td></tr>';
				}
				foreach ( $campaigns as $c ) :
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['campaign'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $c['total'] ); ?></td>
						<?php
						/**
						 * Extra cells of the campaign table (e.g. the partner of WP Affiliate Captain).
						 *
						 * @since 1.2.0
						 * @param string $campaign Campaign name.
						 */
						do_action( 'vcap_campaigns_table_cell', $c['campaign'] );
						?>
						<td><a class="button button-small" href="<?php echo esc_url( $this->export_url( 'coupons', $c['campaign'] ) ); ?>"><?php esc_html_e( 'Download CSV', 'voucher-captain' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Code pool batches and their CSV export are listed under Vouchers → Import codes.', 'voucher-captain' ); ?></p>
		</div>
		<?php
	}

	public function export_url( string $target, string $name ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'vcap_export_codes', 'target' => $target, 'name' => $name ), admin_url( 'admin-post.php' ) ), 'vcap_export_codes' );
	}

	public function handle(): void {
		check_admin_referer( 'vcap_generate_codes' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$target = 'pool' === sanitize_key( wp_unslash( $_POST['target'] ?? '' ) ) ? 'pool' : 'coupons';
		$name   = Campaigns::clean_name( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$count  = absint( $_POST['count'] ?? 0 );
		$opts   = array(
			'prefix' => sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) ),
			'suffix' => sanitize_text_field( wp_unslash( $_POST['suffix'] ?? '' ) ),
			'length' => absint( $_POST['length'] ?? 12 ),
		);
		$result = 'pool' === $target
			? $this->campaigns->generate_pool( $name, $count, $opts )
			: $this->campaigns->generate_coupons( absint( $_POST['template'] ?? 0 ), $name, $count, $opts );
		set_transient(
			self::REPORT . get_current_user_id(),
			array(
				'error'  => is_wp_error( $result ) ? $result->get_error_message() : '',
				'count'  => is_wp_error( $result ) ? 0 : count( $result ),
				'name'   => $name,
				'target' => $target,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=vcap-generate' ) );
		exit;
	}

	public function export(): void {
		check_admin_referer( 'vcap_export_codes' );
		if ( ! $this->can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$name   = Campaigns::clean_name( wp_unslash( (string) ( $_GET['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$target = 'pool' === sanitize_key( wp_unslash( $_GET['target'] ?? '' ) ) ? 'pool' : 'coupons';
		$rows   = 'pool' === $target ? $this->campaigns->pool_rows( $name ) : $this->campaigns->campaign_rows( $name );
		Campaigns::send_csv( 'codes-' . sanitize_title( $name ) . '-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
		exit;
	}
}
