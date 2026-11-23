<?php
/**
 * Role × right table of the "Privacy & advanced" settings section (SET-VC-21).
 *
 * @package VCAP\Admin
 */

namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Which WordPress role holds which Voucher Captain right: manage vouchers, scan tickets and vouchers, raise a remaining
 * value. The administrator always holds all three, and so does every role with "manage_woocommerce" (e.g. the shop
 * manager: the plugin's checks accept that right in place of its own) – the table shows them fixed; saving can never
 * take them away.
 * Saving needs the table's own nonce and both manage_woocommerce and manage_options – a shop manager sees the table
 * but cannot change it (granting rights to roles is an administrator's decision). Once saved, upgrades keep the saved
 * assignment (Install::add_caps() then only ensures the administrator's); every uninstall removes the rights from
 * every role (uninstall.php).
 *
 * @since 1.3.0
 */
final class RoleCaps {
	/** The plugin's own rights. */
	public const CAPS = array( 'vcap_manage_vouchers', 'vcap_scan_tickets', 'vcap_increase_balance' );

	/** Role that always holds every right. */
	public const FIXED_ROLE = 'administrator';

	/** Field type rendered by render(); also the name of the posted checkboxes (field[role][cap]). */
	public const FIELD_TYPE = 'vcap_role_caps';

	/** Nonce field and action of the table. */
	public const NONCE = 'vcap_role_caps_nonce';
	public const NONCE_ACTION = 'vcap_save_role_caps';

	/** Internal state: 'yes' once an administrator saved the table (upgrades then keep the assignment). */
	public const CUSTOMISED_OPTION = 'vcap_role_caps_customised';

	/** Hooks: table and saving (after the settings fields of the section were saved). */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_action( 'woocommerce_settings_save_vcap', array( self::class, 'save' ), 20 );
	}

	/**
	 * Whether a role always holds every right: the administrator, and roles with manage_woocommerce (the plugin's
	 * checks accept that right in place of its own, so taking the rights away would change nothing).
	 *
	 * @param string $key  Role key.
	 * @param mixed  $role Role object.
	 */
	public static function is_fixed( string $key, $role ): bool {
		return self::FIXED_ROLE === $key || ( is_object( $role ) && method_exists( $role, 'has_cap' ) && $role->has_cap( 'manage_woocommerce' ) );
	}

	/**
	 * Labels of the rights (right => label).
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'vcap_manage_vouchers'  => __( 'Manage vouchers', 'voucher-captain' ),
			'vcap_scan_tickets'     => __( 'Scanner (redeem at the counter)', 'voucher-captain' ),
			'vcap_increase_balance' => __( 'Raise remaining values', 'voucher-captain' ),
		);
	}

	/**
	 * Settings block with the table (no option of its own: the rights live on the WordPress roles).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Rights per role', 'voucher-captain' ),
				'id'    => 'vcap_role_caps_block',
				'desc'  => __( '"Manage vouchers" opens the voucher lists, reports, templates and promotions; "Scanner" redeems and checks codes at the counter (also for the role "Voucher cashier"); "Raise remaining values" lets a person add value to a voucher, which creates value – give it only to people you trust with money. The administrator and roles with the right "Manage WooCommerce" (e.g. shop manager) always keep all rights. Only administrators can change this table.', 'voucher-captain' ),
			),
			array(
				'title'     => __( 'Rights', 'voucher-captain' ),
				'id'        => self::FIELD_TYPE,
				'type'      => self::FIELD_TYPE,
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vcap_role_caps_block',
			),
		);
	}

	/** Whether the current user may change the table. */
	public static function can_edit(): bool {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'manage_options' );
	}

	/**
	 * The table (roles in rows, rights in columns).
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function render( $field ): void {
		$labels   = self::labels();
		$editable = self::can_edit();
		$caption  = (string) ( is_array( $field ) ? ( $field['title'] ?? '' ) : '' );
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $caption ) . '</th><td class="forminp">';
		echo '<table class="widefat striped vcap-role-caps" style="max-width:720px"><caption class="screen-reader-text">' . esc_html__( 'Voucher Captain rights per role', 'voucher-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Role', 'voucher-captain' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( self::role_objects() as $key => $role ) {
			$name  = self::role_name( (string) $key );
			$fixed = self::is_fixed( (string) $key, $role );
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th>';
			foreach ( $labels as $cap => $label ) {
				$checked = $fixed || $role->has_cap( $cap );
				/* translators: 1: right, 2: role */
				$aria = sprintf( __( '%1$s for %2$s', 'voucher-captain' ), $label, $name );
				echo '<td><input type="checkbox" name="' . esc_attr( self::FIELD_TYPE . '[' . $key . '][' . $cap . ']' ) . '" value="1" aria-label="' . esc_attr( $aria ) . '"' . ( $checked ? ' checked' : '' ) . ( $fixed || ! $editable ? ' disabled' : '' ) . '></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $editable ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE, false );
		} else {
			echo '<p class="description">' . esc_html__( 'Only administrators can change the rights.', 'voucher-captain' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'The administrator and roles with the right "Manage WooCommerce" (e.g. shop manager) always keep all rights (fixed).', 'voucher-captain' ) . '</p></td></tr>';
	}

	/**
	 * Saves the table when it was part of the saved form (own nonce, manage_woocommerce and manage_options).
	 *
	 * @return bool Whether the table was saved.
	 */
	public static function save(): bool {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized here.
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! self::can_edit() ) {
			return false;
		}
		$posted = isset( $_POST[ self::FIELD_TYPE ] ) && is_array( $_POST[ self::FIELD_TYPE ] ) ? wp_unslash( $_POST[ self::FIELD_TYPE ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only keys of known roles and rights are read (apply()).
		self::apply( (array) $posted, self::role_objects() );
		update_option( self::CUSTOMISED_OPTION, 'yes', false );
		return true;
	}

	/**
	 * Grants and removes the rights as ticked. Unknown roles and rights in $posted are ignored; fixed roles (is_fixed())
	 * get all rights whatever was posted.
	 *
	 * @param array<mixed, mixed>  $posted Ticked boxes: role => right => '1'.
	 * @param array<string, mixed> $roles  Role key => role object (has_cap, add_cap, remove_cap).
	 * @return array<string, bool> Changes made ("role:right" => granted).
	 */
	public static function apply( array $posted, array $roles ): array {
		$changes = array();
		foreach ( $roles as $key => $role ) {
			if ( ! is_object( $role ) ) {
				continue;
			}
			$ticked = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
			foreach ( self::CAPS as $cap ) {
				$want = self::is_fixed( (string) $key, $role ) || ! empty( $ticked[ $cap ] );
				$has  = (bool) $role->has_cap( $cap );
				if ( $want && ! $has ) {
					$role->add_cap( $cap );
					$changes[ $key . ':' . $cap ] = true;
				} elseif ( ! $want && $has ) {
					$role->remove_cap( $cap );
					$changes[ $key . ':' . $cap ] = false;
				}
			}
		}
		return $changes;
	}

	/**
	 * Removes the plugin's rights from every role (uninstall).
	 *
	 * @param array<string, mixed> $roles Role key => role object.
	 */
	public static function remove_all( array $roles ): void {
		foreach ( $roles as $role ) {
			if ( is_object( $role ) ) {
				foreach ( self::CAPS as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Roles of the site (key => WP_Role).
	 *
	 * @return array<string, mixed>
	 */
	private static function role_objects(): array {
		return function_exists( 'wp_roles' ) ? (array) wp_roles()->role_objects : array();
	}

	/** Translated name of a role. */
	private static function role_name( string $key ): string {
		$names = function_exists( 'wp_roles' ) ? (array) wp_roles()->role_names : array();
		$name  = (string) ( $names[ $key ] ?? $key );
		return function_exists( 'translate_user_role' ) ? translate_user_role( $name ) : $name;
	}
}
