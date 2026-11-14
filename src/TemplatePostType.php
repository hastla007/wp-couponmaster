<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable PDF templates (custom post type) + the visual designer screen.
 */
final class TemplatePostType {
	public const POST_TYPE = 'vcap_template';
	public const META      = '_vcap_design';

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_defaults' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_vcap_template_preview', array( $this, 'ajax_preview' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_vcap_duplicate_template', array( $this, 'duplicate' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'PDF templates', 'voucher-captain' ),
					'singular_name' => __( 'PDF template', 'voucher-captain' ),
					'add_new'       => __( 'Add template', 'voucher-captain' ),
					'add_new_item'  => __( 'Add PDF template', 'voucher-captain' ),
					'edit_item'     => __( 'Edit PDF template', 'voucher-captain' ),
					'not_found'     => __( 'No templates found.', 'voucher-captain' ),
					'all_items'     => __( 'PDF templates', 'voucher-captain' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'vcap-vouchers',
				'supports'        => array( 'title' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::POST_TYPE === $screen->post_type && 'post' === $screen->base ) {
			$classes .= ' vcap-designer-screen';
		}
		return $classes;
	}

	public static function first_template_id(): int {
		static $by_site = array(); // Per site: switch_to_blog() must not return another site's template.
		$site = get_current_blog_id();
		if ( isset( $by_site[ $site ] ) ) {
			return $by_site[ $site ];
		}
		$ids = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'numberposts' => 1,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'post_status' => 'publish',
			)
		);
		return $by_site[ $site ] = $ids ? (int) $ids[0] : 0;
	}

	public static function get_design( int $template_id ): array {
		$raw    = $template_id ? get_post_meta( $template_id, self::META, true ) : '';
		$design = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $design ) || empty( $design['page'] ) ) {
			$design = self::default_designs()['giftcard'];
		}
		return apply_filters( 'vcap_template_design', $design, $template_id );
	}

	/**
	 * Built-in starter designs (also used as fallback).
	 */
	public static function default_designs(): array {
		return array(
			'giftcard' => array(
				'page'     => array(
					'format'      => 'A5',
					'orientation' => 'landscape',
					'background'  => '#fbf7f0',
				),
				'elements' => array(
					array( 'id' => 'e1', 'type' => 'rect', 'name' => 'Frame', 'x' => 6, 'y' => 6, 'w' => 198, 'h' => 136, 'fill' => 'transparent', 'borderColor' => '#b8925a', 'borderWidth' => 0.8, 'radius' => 3 ),
					array( 'id' => 'e2', 'type' => 'rect', 'name' => 'Band', 'x' => 6, 'y' => 6, 'w' => 62, 'h' => 136, 'fill' => '#1f3a4d' ),
					array( 'id' => 'e3', 'type' => 'heading', 'name' => 'Title', 'x' => 10, 'y' => 20, 'w' => 54, 'h' => 30, 'text' => __( 'GIFT VOUCHER', 'voucher-captain' ), 'font' => 'DejaVu Serif', 'size' => 20, 'color' => '#f3e6cf', 'align' => 'center', 'bold' => true, 'letterSpacing' => 1 ),
					array( 'id' => 'e4', 'type' => 'text', 'name' => 'Site', 'x' => 10, 'y' => 120, 'w' => 54, 'h' => 10, 'text' => '{site_name}', 'font' => 'DejaVu Sans', 'size' => 9, 'color' => '#f3e6cf', 'align' => 'center' ),
					array( 'id' => 'e5', 'type' => 'text', 'name' => 'Value', 'x' => 76, 'y' => 18, 'w' => 120, 'h' => 22, 'text' => '{coupon_value}', 'font' => 'DejaVu Serif', 'size' => 34, 'color' => '#1f3a4d', 'align' => 'left', 'bold' => true ),
					array( 'id' => 'e6', 'type' => 'text', 'name' => 'For', 'x' => 76, 'y' => 44, 'w' => 120, 'h' => 10, 'text' => __( 'For: {recipient_name}', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 12, 'color' => '#333333' ),
					array( 'id' => 'e7', 'type' => 'text', 'name' => 'Message', 'x' => 76, 'y' => 56, 'w' => 120, 'h' => 30, 'text' => '{message}', 'font' => 'DejaVu Serif', 'size' => 11, 'color' => '#555555', 'italic' => true ),
					array( 'id' => 'e8', 'type' => 'text', 'name' => 'Code label', 'x' => 76, 'y' => 96, 'w' => 80, 'h' => 6, 'text' => __( 'Voucher code', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 8, 'color' => '#8a8a8a', 'uppercase' => true ),
					array( 'id' => 'e9', 'type' => 'text', 'name' => 'Code', 'x' => 76, 'y' => 102, 'w' => 90, 'h' => 10, 'text' => '{coupon_code}', 'font' => 'DejaVu Sans Mono', 'size' => 15, 'color' => '#1f3a4d', 'bold' => true ),
					array( 'id' => 'e10', 'type' => 'text', 'name' => 'Expiry', 'x' => 76, 'y' => 118, 'w' => 90, 'h' => 8, 'text' => __( 'Valid until {expiry_date}', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 9, 'color' => '#555555' ),
					array( 'id' => 'e11', 'type' => 'qrcode', 'name' => 'QR code', 'x' => 170, 'y' => 100, 'w' => 28, 'h' => 28, 'qrContent' => 'code', 'color' => '#1f3a4d' ),
				),
			),
			'ticket'   => array(
				'page'     => array(
					'format'      => 'A6',
					'orientation' => 'portrait',
					'background'  => '#ffffff',
				),
				'elements' => array(
					array( 'id' => 't1', 'type' => 'rect', 'name' => 'Header', 'x' => 0, 'y' => 0, 'w' => 105, 'h' => 38, 'fill' => '#5b2a86' ),
					array( 'id' => 't2', 'type' => 'heading', 'name' => 'Event', 'x' => 6, 'y' => 8, 'w' => 93, 'h' => 14, 'text' => '{product_name}', 'font' => 'DejaVu Sans', 'size' => 16, 'color' => '#ffffff', 'bold' => true ),
					array( 'id' => 't3', 'type' => 'text', 'name' => 'When', 'x' => 6, 'y' => 24, 'w' => 93, 'h' => 10, 'text' => '{event_date} · {event_location}', 'font' => 'DejaVu Sans', 'size' => 9, 'color' => '#e9dcf5' ),
					array( 'id' => 't4', 'type' => 'text', 'name' => 'Guest', 'x' => 6, 'y' => 44, 'w' => 93, 'h' => 8, 'text' => __( 'Guest: {recipient_name}', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 10, 'color' => '#222222' ),
					array( 'id' => 't5', 'type' => 'text', 'name' => 'Admissions', 'x' => 6, 'y' => 52, 'w' => 93, 'h' => 8, 'text' => __( 'Admissions: {ticket_admissions}', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 10, 'color' => '#222222' ),
					array( 'id' => 't6', 'type' => 'qrcode', 'name' => 'Check-in QR', 'x' => 25, 'y' => 64, 'w' => 55, 'h' => 55, 'qrContent' => 'checkin', 'color' => '#000000' ),
					array( 'id' => 't7', 'type' => 'text', 'name' => 'Code', 'x' => 6, 'y' => 122, 'w' => 93, 'h' => 8, 'text' => '{coupon_code}', 'font' => 'DejaVu Sans Mono', 'size' => 11, 'color' => '#5b2a86', 'align' => 'center', 'bold' => true ),
					array( 'id' => 't8', 'type' => 'text', 'name' => 'Order', 'x' => 6, 'y' => 134, 'w' => 93, 'h' => 6, 'text' => __( 'Order #{order_number} · {site_name}', 'voucher-captain' ), 'font' => 'DejaVu Sans', 'size' => 7, 'color' => '#888888', 'align' => 'center' ),
				),
			),
		);
	}

	public function maybe_create_defaults(): void {
		if ( 'yes' !== get_option( 'vcap_needs_default_template' ) || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		update_option( 'vcap_needs_default_template', 'no' );
		if ( self::first_template_id() ) {
			return;
		}
		$titles = array(
			'giftcard' => __( 'Gift card (A5 landscape)', 'voucher-captain' ),
			'ticket'   => __( 'Event ticket (A6)', 'voucher-captain' ),
		);
		$first = 0;
		foreach ( self::default_designs() as $key => $design ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $titles[ $key ],
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, self::META, wp_slash( wp_json_encode( $design ) ) );
				$first = $first ?: (int) $id;
			}
		}
		if ( $first && ! get_option( 'vcap_default_template' ) ) {
			update_option( 'vcap_default_template', $first );
		}
	}

	public function meta_boxes(): void {
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
		add_meta_box( 'vcap-designer-box', __( 'Designer', 'voucher-captain' ), array( $this, 'designer_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function designer_box( \WP_Post $post ): void {
		$design = self::get_design( $post->ID );
		wp_nonce_field( 'vcap_save_design', 'vcap_design_nonce' );
		echo '<textarea name="vcap_design" id="vcap-design-json" hidden>' . esc_textarea( wp_json_encode( $design ) ) . '</textarea>';
		echo '<div id="vcap-designer" class="vcap-designer" aria-label="' . esc_attr__( 'Template designer', 'voucher-captain' ) . '"><p>' . esc_html__( 'Loading designer…', 'voucher-captain' ) . '</p></div>';
		echo '<noscript>' . esc_html__( 'The designer requires JavaScript.', 'voucher-captain' ) . '</noscript>';
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'vcap-designer', VCAP_URL . 'assets/css/designer.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-designer', VCAP_URL . 'assets/js/designer.js', array( 'jquery' ), VCAP_VERSION, true );

		$placeholders = array();
		foreach ( Placeholders::definitions() as $key => $label ) {
			$placeholders[] = array(
				'token' => '{' . $key . '}',
				'label' => $label,
			);
		}
		foreach ( Fields::all_field_keys() as $key => $label ) {
			$placeholders[] = array(
				'token' => '{field:' . $key . '}',
				'label' => $label,
			);
		}
		wp_localize_script(
			'vcap-designer',
			'vcapDesigner',
			array(
				'formats'      => PdfRenderer::FORMATS,
				'fonts'        => array_values( PdfRenderer::fonts() ),
				'placeholders' => $placeholders,
				'sample'       => Preview::sample_values(),
				'previewUrl'   => admin_url( 'admin-ajax.php?action=vcap_template_preview' ),
				'previewNonce' => wp_create_nonce( 'vcap_template_preview' ),
				'postId'       => get_the_ID(),
				'i18n'         => array(
					'text'        => __( 'Text', 'voucher-captain' ),
					'heading'     => __( 'Heading', 'voucher-captain' ),
					'image'       => __( 'Image', 'voucher-captain' ),
					'qrcode'      => __( 'QR code', 'voucher-captain' ),
					'barcode'     => __( 'Barcode', 'voucher-captain' ),
					'rect'        => __( 'Shape', 'voucher-captain' ),
					'line'        => __( 'Line', 'voucher-captain' ),
					'add'         => __( 'Add element', 'voucher-captain' ),
					'page'        => __( 'Page', 'voucher-captain' ),
					'layers'      => __( 'Layers', 'voucher-captain' ),
					'properties'  => __( 'Properties', 'voucher-captain' ),
					'format'      => __( 'Format', 'voucher-captain' ),
					'custom'      => __( 'Custom', 'voucher-captain' ),
					'width'       => __( 'Width (mm)', 'voucher-captain' ),
					'height'      => __( 'Height (mm)', 'voucher-captain' ),
					'orientation' => __( 'Orientation', 'voucher-captain' ),
					'portrait'    => __( 'Portrait', 'voucher-captain' ),
					'landscape'   => __( 'Landscape', 'voucher-captain' ),
					'background'  => __( 'Background colour', 'voucher-captain' ),
					'bgImage'     => __( 'Background image', 'voucher-captain' ),
					'choose'      => __( 'Choose image', 'voucher-captain' ),
					'remove'      => __( 'Remove', 'voucher-captain' ),
					'fit'         => __( 'Fit', 'voucher-captain' ),
					'contain'     => __( 'Contain', 'voucher-captain' ),
					'cover'       => __( 'Cover', 'voucher-captain' ),
					'stretch'     => __( 'Stretch', 'voucher-captain' ),
					'content'     => __( 'Content', 'voucher-captain' ),
					'insert'      => __( 'Insert placeholder…', 'voucher-captain' ),
					'font'        => __( 'Font', 'voucher-captain' ),
					'size'        => __( 'Size (pt)', 'voucher-captain' ),
					'color'       => __( 'Colour', 'voucher-captain' ),
					'fill'        => __( 'Fill', 'voucher-captain' ),
					'align'       => __( 'Alignment', 'voucher-captain' ),
					'valign'      => __( 'Vertical alignment', 'voucher-captain' ),
					'bold'        => __( 'Bold', 'voucher-captain' ),
					'italic'      => __( 'Italic', 'voucher-captain' ),
					'underline'   => __( 'Underline', 'voucher-captain' ),
					'uppercase'   => __( 'Uppercase', 'voucher-captain' ),
					'lineHeight'  => __( 'Line height', 'voucher-captain' ),
					'letterSpacing' => __( 'Letter spacing (pt)', 'voucher-captain' ),
					'rotation'    => __( 'Rotation (°)', 'voucher-captain' ),
					'opacity'     => __( 'Opacity', 'voucher-captain' ),
					'border'      => __( 'Border width (mm)', 'voucher-captain' ),
					'borderColor' => __( 'Border colour', 'voucher-captain' ),
					'radius'      => __( 'Corner radius (mm)', 'voucher-captain' ),
					'qrContent'   => __( 'QR content', 'voucher-captain' ),
					'qrCode'      => __( 'Coupon code', 'voucher-captain' ),
					'qrCheckin'   => __( 'Ticket check-in link', 'voucher-captain' ),
					'qrDownload'  => __( 'PDF download link', 'voucher-captain' ),
					'qrCustom'    => __( 'Custom text', 'voucher-captain' ),
					'value'       => __( 'Value', 'voucher-captain' ),
					'barcodeType' => __( 'Barcode type', 'voucher-captain' ),
					'showText'    => __( 'Show text', 'voucher-captain' ),
					'name'        => __( 'Layer name', 'voucher-captain' ),
					'lock'        => __( 'Lock', 'voucher-captain' ),
					'hide'        => __( 'Hide', 'voucher-captain' ),
					'up'          => __( 'Bring forward', 'voucher-captain' ),
					'down'        => __( 'Send backward', 'voucher-captain' ),
					'duplicate'   => __( 'Duplicate', 'voucher-captain' ),
					'delete'      => __( 'Delete', 'voucher-captain' ),
					'preview'     => __( 'Preview PDF', 'voucher-captain' ),
					'grid'        => __( 'Snap to guides', 'voucher-captain' ),
					'zoom'        => __( 'Zoom', 'voucher-captain' ),
					'undo'        => __( 'Undo', 'voucher-captain' ),
					'redo'        => __( 'Redo', 'voucher-captain' ),
					'starter'     => __( 'Load starter layout…', 'voucher-captain' ),
					'starterGift' => __( 'Gift card', 'voucher-captain' ),
					'starterTicket' => __( 'Event ticket', 'voucher-captain' ),
					'confirmStarter' => __( 'Replace the current layout?', 'voucher-captain' ),
					'noSelection' => __( 'Select an element on the canvas or in the layer list.', 'voucher-captain' ),
					'alignPage'   => __( 'Align to page', 'voucher-captain' ),
					'left'        => __( 'Left', 'voucher-captain' ),
					'center'      => __( 'Center', 'voucher-captain' ),
					'right'       => __( 'Right', 'voucher-captain' ),
					'justify'     => __( 'Justify', 'voucher-captain' ),
					'top'         => __( 'Top', 'voucher-captain' ),
					'middle'      => __( 'Middle', 'voucher-captain' ),
					'bottom'      => __( 'Bottom', 'voucher-captain' ),
					'position'    => __( 'Position & size (mm)', 'voucher-captain' ),
					'shortcuts'   => __( 'Arrow keys move the selection (Shift = 5 mm), Delete removes it, Ctrl+D duplicates, Ctrl+Z undoes.', 'voucher-captain' ),
				),
				'starters'     => self::default_designs(),
			)
		);
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['vcap_design_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_design_nonce'] ) ), 'vcap_save_design' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw    = isset( $_POST['vcap_design'] ) ? wp_unslash( $_POST['vcap_design'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in sanitize_design().
		$design = json_decode( (string) $raw, true );
		if ( is_array( $design ) ) {
			update_post_meta( $post_id, self::META, wp_slash( wp_json_encode( self::sanitize_design( $design ) ) ) );
		}
	}

	/**
	 * Whitelist-based sanitising of a design document.
	 */
	public static function sanitize_design( array $design ): array {
		$page_in = (array) ( $design['page'] ?? array() );
		$page    = array(
			'format'            => in_array( $page_in['format'] ?? 'A5', array_merge( array_keys( PdfRenderer::FORMATS ), array( 'custom' ) ), true ) ? ( $page_in['format'] ?? 'A5' ) : 'A5',
			'orientation'       => 'landscape' === ( $page_in['orientation'] ?? '' ) ? 'landscape' : 'portrait',
			'width'             => max( 20, min( 1000, (float) ( $page_in['width'] ?? 148 ) ) ),
			'height'            => max( 20, min( 1000, (float) ( $page_in['height'] ?? 210 ) ) ),
			'background'        => self::sanitize_color( $page_in['background'] ?? '#ffffff' ),
			'backgroundImageId' => absint( $page_in['backgroundImageId'] ?? 0 ),
			'backgroundImage'   => esc_url_raw( (string) ( $page_in['backgroundImage'] ?? '' ) ),
			'backgroundFit'     => in_array( $page_in['backgroundFit'] ?? 'cover', array( 'cover', 'contain', 'stretch' ), true ) ? ( $page_in['backgroundFit'] ?? 'cover' ) : 'cover',
		);

		$types    = array( 'text', 'heading', 'image', 'qrcode', 'barcode', 'rect', 'line' );
		$elements = array();
		foreach ( array_slice( (array) ( $design['elements'] ?? array() ), 0, 200 ) as $el ) {
			$el = (array) $el;
			if ( ! in_array( $el['type'] ?? '', $types, true ) ) {
				continue;
			}
			$clean = array(
				'id'       => preg_replace( '/[^a-z0-9_-]/i', '', (string) ( $el['id'] ?? uniqid( 'e' ) ) ),
				'type'     => $el['type'],
				'name'     => sanitize_text_field( (string) ( $el['name'] ?? '' ) ),
				'x'        => round( (float) ( $el['x'] ?? 0 ), 2 ),
				'y'        => round( (float) ( $el['y'] ?? 0 ), 2 ),
				'w'        => round( max( 0.5, (float) ( $el['w'] ?? 10 ) ), 2 ),
				'h'        => round( max( 0.1, (float) ( $el['h'] ?? 10 ) ), 2 ),
				'rotation' => round( fmod( (float) ( $el['rotation'] ?? 0 ), 360 ), 2 ),
				'opacity'  => max( 0, min( 1, (float) ( $el['opacity'] ?? 1 ) ) ),
				'locked'   => ! empty( $el['locked'] ),
				'hidden'   => ! empty( $el['hidden'] ),
			);
			$strings = array( 'text' => 'textarea', 'qrValue' => 'text', 'src' => 'url' );
			foreach ( $strings as $key => $kind ) {
				if ( isset( $el[ $key ] ) ) {
					$clean[ $key ] = 'url' === $kind ? esc_url_raw( (string) $el[ $key ] ) : ( 'textarea' === $kind ? sanitize_textarea_field( (string) $el[ $key ] ) : sanitize_text_field( (string) $el[ $key ] ) );
				}
			}
			foreach ( array( 'color', 'fill', 'borderColor' ) as $key ) {
				if ( isset( $el[ $key ] ) ) {
					$clean[ $key ] = self::sanitize_color( $el[ $key ] );
				}
			}
			foreach ( array( 'size', 'lineHeight', 'letterSpacing', 'borderWidth', 'radius' ) as $key ) {
				if ( isset( $el[ $key ] ) ) {
					$clean[ $key ] = round( (float) $el[ $key ], 2 );
				}
			}
			foreach ( array( 'bold', 'italic', 'underline', 'uppercase', 'showText' ) as $key ) {
				if ( isset( $el[ $key ] ) ) {
					$clean[ $key ] = ! empty( $el[ $key ] );
				}
			}
			if ( isset( $el['imageId'] ) ) {
				$clean['imageId'] = absint( $el['imageId'] );
			}
			$enums = array(
				'font'        => array_keys( PdfRenderer::fonts() ),
				'align'       => array( 'left', 'center', 'right', 'justify' ),
				'valign'      => array( 'top', 'middle', 'bottom' ),
				'fit'         => array( 'contain', 'cover', 'stretch' ),
				'qrContent'   => array( 'code', 'checkin', 'download', 'custom' ),
				'barcodeType' => array( 'C128', 'C39', 'EAN13' ),
			);
			foreach ( $enums as $key => $allowed ) {
				if ( isset( $el[ $key ] ) && in_array( $el[ $key ], $allowed, true ) ) {
					$clean[ $key ] = $el[ $key ];
				}
			}
			$elements[] = $clean;
		}
		return apply_filters(
			'vcap_sanitize_design',
			array(
				'page'     => $page,
				'elements' => $elements,
			),
			$design
		);
	}

	public static function sanitize_color( $value ): string {
		$value = (string) $value;
		if ( 'transparent' === $value ) {
			return 'transparent';
		}
		return sanitize_hex_color( $value ) ?: '#000000';
	}

	public function ajax_preview(): void {
		check_ajax_referer( 'vcap_template_preview', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$design = json_decode( wp_unslash( (string) ( $_POST['design'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		$design = is_array( $design ) ? self::sanitize_design( $design ) : self::get_design( absint( $_POST['post_id'] ?? 0 ) );
		$voucher = Preview::sample_voucher();
		Preview::stream( vcap()->pdf->render( $voucher, $design ), 'template-preview.pdf' );
	}

	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type && current_user_can( 'edit_products' ) ) {
			$url                  = wp_nonce_url( admin_url( 'admin.php?action=vcap_duplicate_template&post=' . $post->ID ), 'vcap_duplicate_' . $post->ID );
			$actions['duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'voucher-captain' ) . '</a>';
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	public function duplicate(): void {
		$id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'vcap_duplicate_' . $id );
		if ( ! current_user_can( 'edit_products' ) || self::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$new = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				/* translators: %s: template title */
				'post_title'  => sprintf( __( '%s (copy)', 'voucher-captain' ), get_the_title( $id ) ),
			)
		);
		if ( $new && ! is_wp_error( $new ) ) {
			update_post_meta( $new, self::META, wp_slash( (string) get_post_meta( $id, self::META, true ) ) );
			wp_safe_redirect( get_edit_post_link( $new, 'raw' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}

	public function columns( array $columns ): array {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['vcap_format'] = __( 'Format', 'voucher-captain' );
		$columns['vcap_usage']  = __( 'Used by products', 'voucher-captain' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public function column( string $column, int $post_id ): void {
		if ( 'vcap_format' === $column ) {
			$design = self::get_design( $post_id );
			list( $w, $h ) = PdfRenderer::page_size( $design['page'] );
			echo esc_html( ( 'custom' === $design['page']['format'] ? __( 'Custom', 'voucher-captain' ) : $design['page']['format'] ) . sprintf( ' · %s × %s mm', round( $w ), round( $h ) ) );
		} elseif ( 'vcap_usage' === $column ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_vcap_template' AND meta_value = %s", (string) $post_id ) ); // phpcs:ignore
			echo esc_html( (string) $count );
		}
	}
}
