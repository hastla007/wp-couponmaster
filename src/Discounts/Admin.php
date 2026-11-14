<?php
namespace VCAP\Discounts;

defined( 'ABSPATH' ) || exit;

/**
 * Admin for discount campaigns (Vouchers → Promotions): rules editor and a test cart preview.
 */
final class Admin {
	public function __construct( private Repository $campaigns ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'post_type' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes_' . Repository::POST_TYPE, array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . Repository::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'wp_ajax_vcap_campaign_preview', array( $this, 'preview' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'manage_' . Repository::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Repository::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'disabled_notice' ) );
	}

	public function post_type(): void {
		register_post_type(
			Repository::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Promotions', 'voucher-captain' ),
					'singular_name' => __( 'Promotion', 'voucher-captain' ),
					'add_new'       => __( 'Add promotion', 'voucher-captain' ),
					'add_new_item'  => __( 'Add promotion', 'voucher-captain' ),
					'edit_item'     => __( 'Edit promotion', 'voucher-captain' ),
					'all_items'     => __( 'Promotions', 'voucher-captain' ),
					'not_found'     => __( 'No promotions found.', 'voucher-captain' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'vcap-vouchers',
				'supports'        => array( 'title', 'page-attributes' ),
				'capability_type' => 'shop_coupon',
				'map_meta_cap'    => true,
				'rewrite'         => false,
			)
		);
	}

	public function disabled_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Repository::POST_TYPE === $screen->post_type && ! \VCAP\Licensing\Entitlements::allows( 'promotions' ) ) {
			// Locked (free edition or no active license): promotions are kept but not applied.
			echo \VCAP\Licensing\Entitlements::locked_notice( 'promotions', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in locked_notice().
			return;
		}
		if ( $screen && Repository::POST_TYPE === $screen->post_type && 'yes' !== get_option( 'vcap_campaigns_enabled', 'no' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Promotions are switched off. Enable them under WooCommerce → Settings → Vouchers (“Automatic promotions”); until then no promotion is applied in the shop.', 'voucher-captain' ) . '</p></div>';
		}
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Repository::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'vcap-admin', VCAP_URL . 'assets/css/admin.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-campaigns', VCAP_URL . 'assets/js/campaigns.js', array( 'jquery', 'wc-enhanced-select' ), VCAP_VERSION, true );
		wp_localize_script(
			'vcap-campaigns',
			'vcapCampaigns',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vcap_campaign_preview' ),
			)
		);
	}

	public function meta_boxes(): void {
		add_meta_box( 'vcap-campaign-rules', __( 'Rules', 'voucher-captain' ), array( $this, 'rules_box' ), Repository::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'vcap-campaign-preview', __( 'Test cart', 'voucher-captain' ), array( $this, 'preview_box' ), Repository::POST_TYPE, 'normal', 'default' );
	}

	private static function date_value( int $ts ): string {
		return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
	}

	private static function product_select( string $name, array $ids, bool $multiple = true, string $label = '' ): void {
		$label = '' !== $label ? $label : __( 'Product', 'voucher-captain' );
		echo '<select class="wc-product-search" style="width:100%" ' . ( $multiple ? 'multiple' : '' ) . ' name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $label ) . '" data-placeholder="' . esc_attr__( 'Search for a product…', 'voucher-captain' ) . '" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				echo '<option value="' . esc_attr( (string) $id ) . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select>';
	}

	private static function category_select( string $name, array $ids, string $label = '' ): void {
		$label = '' !== $label ? $label : __( 'Categories', 'voucher-captain' );
		echo '<select class="wc-enhanced-select" multiple style="width:100%" name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $label ) . '" data-placeholder="' . esc_attr__( 'Choose categories…', 'voucher-captain' ) . '">';
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, $ids, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select>';
	}

	public function rules_box( \WP_Post $post ): void {
		$r = Repository::build( $post )['rules'];
		wp_nonce_field( 'vcap_campaign_rules', 'vcap_campaign_nonce' );
		$tiers = implode( "\n", array_map( static fn( $t ) => $t['min_qty'] . ':' . rtrim( rtrim( number_format( $t['percent'], 2, '.', '' ), '0' ), '.' ), $r['tiers'] ) );
		?>
		<table class="form-table vcap-campaign-form" role="presentation">
			<tr>
				<th scope="row"><label for="vcap-action"><?php esc_html_e( 'Discount', 'voucher-captain' ); ?></label></th>
				<td>
					<select id="vcap-action" name="vcap_rules[action]">
						<option value="percent" <?php selected( $r['action'], 'percent' ); ?>><?php esc_html_e( 'Percentage off', 'voucher-captain' ); ?></option>
						<option value="fixed" <?php selected( $r['action'], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'voucher-captain' ); ?></option>
						<option value="tiered" <?php selected( $r['action'], 'tiered' ); ?>><?php esc_html_e( 'Tiered by quantity', 'voucher-captain' ); ?></option>
						<option value="bxgy" <?php selected( $r['action'], 'bxgy' ); ?>><?php esc_html_e( 'Buy X, get Y', 'voucher-captain' ); ?></option>
						<option value="bonus" <?php selected( $r['action'], 'bonus' ); ?>><?php esc_html_e( 'Bonus voucher after the order', 'voucher-captain' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="vcap-for vcap-for-percent">
				<th scope="row"><label for="vcap-percent"><?php esc_html_e( 'Percentage', 'voucher-captain' ); ?></label></th>
				<td><input type="number" step="0.01" min="0" max="100" id="vcap-percent" name="vcap_rules[percent]" value="<?php echo esc_attr( (string) $r['percent'] ); ?>" class="small-text" /> %</td>
			</tr>
			<tr class="vcap-for vcap-for-fixed">
				<th scope="row"><label for="vcap-amount"><?php esc_html_e( 'Amount', 'voucher-captain' ); ?></label></th>
				<td><input type="text" id="vcap-amount" name="vcap_rules[amount]" value="<?php echo esc_attr( wc_format_localized_price( (string) $r['amount'] ) ); ?>" class="short wc_input_price" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
					<p class="description"><?php esc_html_e( 'Taken off the qualifying products in total, split proportionally.', 'voucher-captain' ); ?></p></td>
			</tr>
			<tr class="vcap-for vcap-for-tiered">
				<th scope="row"><label for="vcap-tiers"><?php esc_html_e( 'Tiers', 'voucher-captain' ); ?></label></th>
				<td><textarea id="vcap-tiers" name="vcap_rules[tiers]" rows="4" cols="20" placeholder="3:5&#10;5:10&#10;10:15"><?php echo esc_textarea( $tiers ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One tier per line: "from quantity:percent", e.g. 3:5 = 5 % from 3 items. The highest reached tier applies to all qualifying items.', 'voucher-captain' ); ?></p></td>
			</tr>
			<tr class="vcap-for vcap-for-bxgy">
				<th scope="row"><?php esc_html_e( 'Buy X, get Y', 'voucher-captain' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Buy', 'voucher-captain' ); ?> <input type="number" min="1" name="vcap_rules[buy_qty]" value="<?php echo esc_attr( (string) $r['buy_qty'] ); ?>" class="small-text" /></label>
					<label><?php esc_html_e( 'get', 'voucher-captain' ); ?> <input type="number" min="1" name="vcap_rules[get_qty]" value="<?php echo esc_attr( (string) $r['get_qty'] ); ?>" class="small-text" /></label>
					<label><?php esc_html_e( 'at', 'voucher-captain' ); ?> <input type="number" min="1" max="100" name="vcap_rules[get_percent]" value="<?php echo esc_attr( (string) $r['get_percent'] ); ?>" class="small-text" /> % <?php esc_html_e( 'off', 'voucher-captain' ); ?></label>
					<label><input type="checkbox" name="vcap_rules[repeat]" value="1" <?php checked( $r['repeat'] ); ?> /> <?php esc_html_e( 'Repeat (every group of X+Y)', 'voucher-captain' ); ?></label>
					<p class="description"><?php esc_html_e( 'The cheapest qualifying items are discounted, e.g. buy 2 get 1 at 100 % off = the cheapest of three is free.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
			<tr class="vcap-for vcap-for-bonus">
				<th scope="row"><?php esc_html_e( 'Bonus voucher', 'voucher-captain' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Earned by', 'voucher-captain' ); ?>
						<select name="vcap_rules[bonus_trigger]">
							<option value="order" <?php selected( $r['bonus_trigger'], 'order' ); ?>><?php esc_html_e( 'a qualifying order', 'voucher-captain' ); ?></option>
							<option value="gift_card" <?php selected( $r['bonus_trigger'], 'gift_card' ); ?>><?php esc_html_e( 'buying vouchers/gift cards', 'voucher-captain' ); ?></option>
						</select>
					</label><br>
					<label><?php esc_html_e( 'Value', 'voucher-captain' ); ?>
						<input type="text" name="vcap_rules[bonus_amount]" value="<?php echo esc_attr( $r['bonus_amount'] ? wc_format_localized_price( (string) $r['bonus_amount'] ) : '' ); ?>" class="short wc_input_price" />
						<select name="vcap_rules[bonus_type]">
							<option value="fixed_cart" <?php selected( $r['bonus_type'], 'fixed_cart' ); ?>><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></option>
							<option value="percent" <?php selected( $r['bonus_type'], 'percent' ); ?>>%</option>
						</select>
					</label>
					<label><?php esc_html_e( 'Valid for (days)', 'voucher-captain' ); ?> <input type="number" min="0" name="vcap_rules[bonus_days]" value="<?php echo esc_attr( (string) $r['bonus_days'] ); ?>" class="small-text" /></label>
					<label><?php esc_html_e( 'Minimum order value to redeem', 'voucher-captain' ); ?> <input type="text" name="vcap_rules[bonus_min_spend]" value="<?php echo esc_attr( $r['bonus_min_spend'] ? wc_format_localized_price( (string) $r['bonus_min_spend'] ) : '' ); ?>" class="short wc_input_price" /></label>
					<p class="description"><?php esc_html_e( 'After payment the customer receives a single-use code for the next order by email (WooCommerce → Settings → Emails → Bonus voucher), restricted to their billing email. The conditions below decide which orders qualify; the minimum cart value applies to the qualifying order. An unused bonus is withdrawn when the order is cancelled or refunded.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Products', 'voucher-captain' ); ?></th>
				<td><?php self::product_select( 'vcap_rules[products][]', $r['products'], true, __( 'Products', 'voucher-captain' ) ); ?>
					<p class="description"><?php esc_html_e( 'Leave products and categories empty for all products. Voucher products are excluded unless "Discount codes on vouchers" is allowed.', 'voucher-captain' ); ?></p></td>
			</tr>
			<tr><th scope="row"><?php esc_html_e( 'Categories', 'voucher-captain' ); ?></th><td><?php self::category_select( 'vcap_rules[categories][]', $r['categories'], __( 'Categories', 'voucher-captain' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Exclude products', 'voucher-captain' ); ?></th><td><?php self::product_select( 'vcap_rules[exclude_products][]', $r['exclude_products'], true, __( 'Exclude products', 'voucher-captain' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Exclude categories', 'voucher-captain' ); ?></th><td><?php self::category_select( 'vcap_rules[exclude_categories][]', $r['exclude_categories'], __( 'Exclude categories', 'voucher-captain' ) ); ?></td></tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Conditions', 'voucher-captain' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Minimum cart value', 'voucher-captain' ); ?> <input type="text" name="vcap_rules[min_subtotal]" value="<?php echo esc_attr( $r['min_subtotal'] ? wc_format_localized_price( (string) $r['min_subtotal'] ) : '' ); ?>" class="short wc_input_price" /></label><br>
					<label><?php esc_html_e( 'Minimum quantity of qualifying items', 'voucher-captain' ); ?> <input type="number" min="0" name="vcap_rules[min_qty]" value="<?php echo esc_attr( $r['min_qty'] ? (string) $r['min_qty'] : '' ); ?>" class="small-text" /></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="vcap-customer"><?php esc_html_e( 'Customers', 'voucher-captain' ); ?></label></th>
				<td>
					<select id="vcap-customer" name="vcap_rules[customer]">
						<option value="any" <?php selected( $r['customer'], 'any' ); ?>><?php esc_html_e( 'Everyone', 'voucher-captain' ); ?></option>
						<option value="logged_in" <?php selected( $r['customer'], 'logged_in' ); ?>><?php esc_html_e( 'Logged-in customers', 'voucher-captain' ); ?></option>
						<option value="roles" <?php selected( $r['customer'], 'roles' ); ?>><?php esc_html_e( 'Customer groups (user roles)', 'voucher-captain' ); ?></option>
						<option value="first_order" <?php selected( $r['customer'], 'first_order' ); ?>><?php esc_html_e( 'First order only', 'voucher-captain' ); ?></option>
						<option value="returning" <?php selected( $r['customer'], 'returning' ); ?>><?php esc_html_e( 'Returning customers (next order)', 'voucher-captain' ); ?></option>
					</select>
					<div class="vcap-for-roles">
						<select class="wc-enhanced-select" multiple style="width:100%" name="vcap_rules[roles][]" aria-label="<?php esc_attr_e( 'Customer groups (user roles)', 'voucher-captain' ); ?>" data-placeholder="<?php esc_attr_e( 'Choose roles…', 'voucher-captain' ); ?>">
							<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
								<option value="<?php echo esc_attr( $role ); ?>" <?php selected( in_array( $role, $r['roles'], true ) ); ?>><?php echo esc_html( translate_user_role( $name ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Period', 'voucher-captain' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'voucher-captain' ); ?> <input type="datetime-local" name="vcap_rules[date_from]" value="<?php echo esc_attr( self::date_value( $r['date_from'] ) ); ?>" /></label>
					<label><?php esc_html_e( 'until', 'voucher-captain' ); ?> <input type="datetime-local" name="vcap_rules[date_to]" value="<?php echo esc_attr( self::date_value( $r['date_to'] ) ); ?>" /></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Usage limits', 'voucher-captain' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Total uses', 'voucher-captain' ); ?> <input type="number" min="0" name="vcap_rules[usage_limit]" value="<?php echo esc_attr( $r['usage_limit'] ? (string) $r['usage_limit'] : '' ); ?>" class="small-text" placeholder="∞" /></label>
					<label><?php esc_html_e( 'Uses per customer', 'voucher-captain' ); ?> <input type="number" min="0" name="vcap_rules[usage_limit_per_customer]" value="<?php echo esc_attr( $r['usage_limit_per_customer'] ? (string) $r['usage_limit_per_customer'] : '' ); ?>" class="small-text" placeholder="∞" /></label>
					<p class="description"><?php esc_html_e( 'An order counts once it is paid or on hold and is given back when it is cancelled or fails. Customers are recognised by account and billing email; guests without an email are checked at checkout.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Combination', 'voucher-captain' ); ?></th>
				<td>
					<label><input type="checkbox" name="vcap_rules[stackable]" value="1" <?php checked( $r['stackable'] ); ?> /> <?php esc_html_e( 'Can be combined with other promotions', 'voucher-captain' ); ?></label><br>
					<label><input type="checkbox" name="vcap_rules[with_coupons]" value="1" <?php checked( $r['with_coupons'] ); ?> /> <?php esc_html_e( 'Also applies when the customer uses a coupon code', 'voucher-captain' ); ?></label>
					<p class="description"><?php esc_html_e( 'Priority = "Order" in the Attributes box (lower first). A promotion that cannot be combined only applies alone; if it has the best priority, others are skipped.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function preview_box( \WP_Post $post ): void {
		?>
		<p class="description"><?php esc_html_e( 'Try the rules above with a sample cart before publishing. Unsaved changes are used.', 'voucher-captain' ); ?></p>
		<table class="widefat vcap-preview-cart" role="presentation">
			<thead><tr><th><?php esc_html_e( 'Product', 'voucher-captain' ); ?></th><th style="width:90px"><?php esc_html_e( 'Quantity', 'voucher-captain' ); ?></th></tr></thead>
			<tbody>
			<?php for ( $i = 0; $i < 4; $i++ ) : ?>
				<tr>
					<td><?php /* translators: %d: row number */
					self::product_select( 'vcap_preview[product][' . $i . ']', array(), false, sprintf( __( 'Product %d', 'voucher-captain' ), $i + 1 ) ); ?></td>
					<td><input type="number" min="0" value="1" name="vcap_preview[qty][<?php echo esc_attr( (string) $i ); ?>]" class="small-text" aria-label="<?php esc_attr_e( 'Quantity', 'voucher-captain' ); ?>" /></td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<p>
			<label><?php esc_html_e( 'Customer', 'voucher-captain' ); ?>
				<select name="vcap_preview[customer]">
					<option value="guest"><?php esc_html_e( 'Guest, first order', 'voucher-captain' ); ?></option>
					<option value="new"><?php esc_html_e( 'Logged-in customer, first order', 'voucher-captain' ); ?></option>
					<option value="returning"><?php esc_html_e( 'Logged-in returning customer', 'voucher-captain' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Role', 'voucher-captain' ); ?>
				<select name="vcap_preview[role]">
					<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
						<option value="<?php echo esc_attr( $role ); ?>" <?php selected( 'customer', $role ); ?>><?php echo esc_html( translate_user_role( $name ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="button" class="button" id="vcap-campaign-preview"><?php esc_html_e( 'Calculate', 'voucher-captain' ); ?></button>
		</p>
		<div id="vcap-campaign-preview-result" aria-live="polite"></div>
		<?php
	}

	/**
	 * Build normalised rules from submitted form values.
	 */
	public static function rules_from_request( array $in ): array {
		$ts = static function ( $value ): int {
			$value = sanitize_text_field( (string) $value );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
				return 0;
			}
			$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
			return $date ? $date->getTimestamp() : 0;
		};
		$tiers = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $in['tiers'] ?? '' ) ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\s*[:=]\s*([\d.,]+)\s*%?\s*$/', $line, $m ) ) {
				$tiers[] = array( 'min_qty' => (int) $m[1], 'percent' => (float) str_replace( ',', '.', $m[2] ) );
			}
		}
		return Engine::normalize(
			array(
				'action'             => sanitize_key( $in['action'] ?? 'percent' ),
				'percent'            => (float) wc_format_decimal( $in['percent'] ?? 0 ),
				'amount'             => (float) wc_format_decimal( $in['amount'] ?? 0 ),
				'tiers'              => $tiers,
				'buy_qty'            => absint( $in['buy_qty'] ?? 2 ),
				'get_qty'            => absint( $in['get_qty'] ?? 1 ),
				'get_percent'        => (float) wc_format_decimal( $in['get_percent'] ?? 100 ),
				'repeat'             => ! empty( $in['repeat'] ),
				'products'           => array_map( 'absint', (array) ( $in['products'] ?? array() ) ),
				'categories'         => array_map( 'absint', (array) ( $in['categories'] ?? array() ) ),
				'exclude_products'   => array_map( 'absint', (array) ( $in['exclude_products'] ?? array() ) ),
				'exclude_categories' => array_map( 'absint', (array) ( $in['exclude_categories'] ?? array() ) ),
				'min_subtotal'       => (float) wc_format_decimal( $in['min_subtotal'] ?? 0 ),
				'min_qty'            => absint( $in['min_qty'] ?? 0 ),
				'customer'           => sanitize_key( $in['customer'] ?? 'any' ),
				'roles'              => array_map( 'sanitize_key', (array) ( $in['roles'] ?? array() ) ),
				'date_from'          => $ts( $in['date_from'] ?? '' ),
				'date_to'            => $ts( $in['date_to'] ?? '' ),
				'stackable'          => ! empty( $in['stackable'] ),
				'with_coupons'       => ! empty( $in['with_coupons'] ),
				'usage_limit'              => absint( $in['usage_limit'] ?? 0 ),
				'bonus_type'               => sanitize_key( $in['bonus_type'] ?? 'fixed_cart' ),
				'bonus_amount'             => (float) wc_format_decimal( $in['bonus_amount'] ?? 0 ),
				'bonus_days'               => absint( $in['bonus_days'] ?? 90 ),
				'bonus_min_spend'          => (float) wc_format_decimal( $in['bonus_min_spend'] ?? 0 ),
				'bonus_trigger'            => sanitize_key( $in['bonus_trigger'] ?? 'order' ),
				'usage_limit_per_customer' => absint( $in['usage_limit_per_customer'] ?? 0 ),
			)
		);
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['vcap_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_campaign_nonce'] ) ), 'vcap_campaign_rules' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rules = self::rules_from_request( isset( $_POST['vcap_rules'] ) ? (array) wp_unslash( $_POST['vcap_rules'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in rules_from_request().
		update_post_meta( $post_id, Repository::META, wp_slash( wp_json_encode( $rules ) ) );
		$this->campaigns->flush();
	}

	public static function summary( array $r ): string {
		switch ( $r['action'] ) {
			case 'fixed':
				return sprintf( /* translators: %s: amount */ __( '%s off', 'voucher-captain' ), wp_strip_all_tags( wc_price( $r['amount'] ) ) );
			case 'tiered':
				return __( 'Tiered:', 'voucher-captain' ) . ' ' . implode( ', ', array_map( static fn( $t ) => $t['min_qty'] . '+ → ' . \VCAP\Format::percent( $t['percent'] ), $r['tiers'] ) );
			case 'bonus':
				$value = 'percent' === $r['bonus_type'] ? \VCAP\Format::percent( $r['bonus_amount'] ) : wp_strip_all_tags( wc_price( $r['bonus_amount'] ) );
				/* translators: %s: bonus voucher value */
				return sprintf( __( 'Bonus voucher %s', 'voucher-captain' ), $value );
			case 'bxgy':
				/* translators: 1: buy quantity, 2: get quantity, 3: percent */
				return sprintf( __( 'Buy %1$d, get %2$d at %3$s %% off', 'voucher-captain' ), $r['buy_qty'], $r['get_qty'], $r['get_percent'] );
			default:
				return \VCAP\Format::percent( $r['percent'] );
		}
	}

	public function preview(): void {
		check_ajax_referer( 'vcap_campaign_preview', 'nonce' );
		if ( ! current_user_can( 'edit_shop_coupons' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$rules   = self::rules_from_request( isset( $_POST['vcap_rules'] ) ? (array) wp_unslash( $_POST['vcap_rules'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$preview = isset( $_POST['vcap_preview'] ) ? (array) wp_unslash( $_POST['vcap_preview'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lines   = array();
		$names   = array();
		foreach ( (array) ( $preview['product'] ?? array() ) as $i => $product_id ) {
			$product = wc_get_product( absint( $product_id ) );
			$qty     = absint( $preview['qty'][ $i ] ?? 0 );
			if ( ! $product || $qty <= 0 ) {
				continue;
			}
			$key            = 'line' . $i;
			$parent         = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$names[ $key ]  = wp_strip_all_tags( $product->get_name() );
			$lines[]        = array(
				'key'        => $key,
				'product_id' => $product->get_id(),
				'parent_id'  => $parent,
				'categories' => wc_get_product_cat_ids( $parent ),
				'unit'       => (float) $product->get_price(),
				'qty'        => $qty,
				'is_voucher' => \VCAP\ProductConfig::is_voucher( $product ),
			);
		}
		$customer = sanitize_key( $preview['customer'] ?? 'guest' );
		$context  = array(
			'now'                => time(),
			'logged_in'          => 'guest' !== $customer,
			'roles'              => 'guest' !== $customer ? array( sanitize_key( $preview['role'] ?? 'customer' ) ) : array(),
			'has_previous_order' => 'returning' === $customer,
			'other_coupons'      => array(),
			'allow_vouchers'     => 'yes' === get_option( 'vcap_discounts_on_vouchers', 'no' ),
			'decimals'           => wc_get_price_decimals(),
		);
		if ( ! $lines ) {
			wp_send_json_success( '<p>' . esc_html__( 'Choose at least one product.', 'voucher-captain' ) . '</p>' );
		}
		$reasons  = array(
			'not_started'  => __( 'The promotion has not started yet.', 'voucher-captain' ),
			'ended'        => __( 'The promotion has ended.', 'voucher-captain' ),
			'other_coupon' => __( 'Not combined with coupon codes.', 'voucher-captain' ),
			'customer'     => __( 'This customer does not qualify.', 'voucher-captain' ),
			'min_subtotal' => __( 'The minimum cart value is not reached.', 'voucher-captain' ),
			'no_items'     => __( 'No product in this cart qualifies.', 'voucher-captain' ),
			'min_qty'      => __( 'Not enough qualifying items.', 'voucher-captain' ),
		);
		if ( 'bonus' === $rules['action'] ) {
			$blocker = Engine::bonus_blocker( $rules, $lines, $context );
			$value   = 'percent' === $rules['bonus_type'] ? \VCAP\Format::percent( $rules['bonus_amount'] ) : wp_strip_all_tags( wc_price( $rules['bonus_amount'] ) );
			wp_send_json_success(
				'' === $blocker
					/* translators: %s: bonus voucher value */
					? '<p class="vcap-ok">' . esc_html( sprintf( __( 'This order earns a bonus voucher worth %s after payment.', 'voucher-captain' ), $value ) ) . '</p>'
					: '<p class="vcap-low">' . esc_html( $reasons[ $blocker ] ?? $blocker ) . '</p>'
			);
		}
		$blocker  = Engine::blocker( $rules, $lines, $context );
		$amounts  = Engine::discounts( $rules, $lines, $context );
		$html     = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Quantity', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Price', 'voucher-captain' ) . '</th><th>' . esc_html__( 'Discount', 'voucher-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $lines as $line ) {
			$html .= '<tr><td>' . esc_html( $names[ $line['key'] ] ) . ( $line['is_voucher'] ? ' <em>(' . esc_html__( 'voucher', 'voucher-captain' ) . ')</em>' : '' ) . '</td><td>' . esc_html( (string) $line['qty'] ) . '</td><td>' . wp_kses_post( wc_price( $line['unit'] * $line['qty'] ) ) . '</td><td>' . wp_kses_post( wc_price( $amounts[ $line['key'] ] ?? 0 ) ) . '</td></tr>';
		}
		$html .= '</tbody><tfoot><tr><th colspan="3">' . esc_html__( 'Total discount', 'voucher-captain' ) . '</th><th>' . wp_kses_post( wc_price( array_sum( $amounts ) ) ) . '</th></tr></tfoot></table>';
		if ( '' !== $blocker ) {
			$html = '<p class="vcap-low">' . esc_html( $reasons[ $blocker ] ?? $blocker ) . '</p>' . $html;
		}
		wp_send_json_success( $html );
	}

	public function columns( array $columns ): array {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['vcap_discount'] = __( 'Discount', 'voucher-captain' );
		$columns['vcap_period']   = __( 'Period', 'voucher-captain' );
		$columns['vcap_priority'] = __( 'Priority', 'voucher-captain' );
		$columns['vcap_usage']    = __( 'Used', 'voucher-captain' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public function column( string $column, int $post_id ): void {
		$c = Repository::build( get_post( $post_id ) );
		if ( 'vcap_discount' === $column ) {
			echo esc_html( self::summary( $c['rules'] ) );
		} elseif ( 'vcap_period' === $column ) {
			$from = $c['rules']['date_from'] ? wp_date( get_option( 'date_format' ), $c['rules']['date_from'] ) : '…';
			$to   = $c['rules']['date_to'] ? wp_date( get_option( 'date_format' ), $c['rules']['date_to'] ) : '…';
			echo esc_html( $from . ' – ' . $to );
		} elseif ( 'vcap_priority' === $column ) {
			echo esc_html( (string) $c['priority'] );
		} elseif ( 'vcap_usage' === $column ) {
			$used = Usage::count( $post_id );
			echo esc_html( $c['rules']['usage_limit'] ? $used . ' / ' . $c['rules']['usage_limit'] : (string) $used );
		}
	}
}
