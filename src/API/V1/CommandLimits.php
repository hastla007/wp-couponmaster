<?php
/**
 * Fixed limits and switches of the four voucher commands (suite spec 8.3.1).
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The limits of voucher.issue, voucher.void, voucher.reactivate and voucher.redeem (VC-1, VOUCH-WF-13).
 *
 * The table below is the owner's decision of 16.11.2026 on F-05: the numbers are the default and at the same time the
 * ceiling. The operator can lower each of them in Voucher Captain → Settings → Integrations (API) and switch each
 * command off; nothing (no setting, no filter) raises a limit above the table. All four commands are off by default.
 *
 * Amounts (owner's decision of 23.11.2026 on F-05a): the core converts nothing and knows no exchange rates. The ceiling
 * is 100 in EUR and – only as the shop currency – the same number 100 in a short list of currencies of similar value
 * (SIMILAR_CURRENCIES). In a shop with any other currency the operator enters the maximum amount once himself; until
 * then amounts are refused (currency_not_allowed), so voucher.issue and voucher.redeem stay off there.
 *
 * @since 1.3.0 (contract 1.3.0)
 */
final class CommandLimits {
	/** The four commands (spec names). */
	public const COMMANDS = array( 'voucher.issue', 'voucher.void', 'voucher.reactivate', 'voucher.redeem' );

	/** Currency the fixed amount ceiling is set in. */
	public const FIXED_CURRENCY = 'EUR';

	/**
	 * Currencies whose unit is worth about as much as one euro: as the shop currency they get the same ceiling number
	 * (100) without an own entry by the operator (F-05a). Kept short on purpose; anything else needs the operator.
	 *
	 * @since 1.3.0
	 */
	public const SIMILAR_CURRENCIES = array( 'CHF', 'USD', 'GBP' );

	/**
	 * Fixed ceilings per command: amount per voucher/redemption in minor units of EUR (null = no amount), calls per
	 * calendar day and site (site time zone, all workflows together), calls per calendar day and recipient (issue).
	 */
	public const FIXED = array(
		'voucher.issue'      => array(
			'max_amount_minor' => 10000,
			'per_day'          => 50,
			'per_recipient'    => 1,
		),
		'voucher.void'       => array(
			'max_amount_minor' => null,
			'per_day'          => 50,
			'per_recipient'    => null,
		),
		'voucher.reactivate' => array(
			'max_amount_minor' => null,
			'per_day'          => 50,
			'per_recipient'    => null,
		),
		'voucher.redeem'     => array(
			'max_amount_minor' => 10000,
			'per_day'          => 50,
			'per_recipient'    => null,
		),
	);

	/** Setting suffixes: switch ('yes'/'no'), calls per day, calls per recipient and day, maximum amount (shop currency). */
	private const SUFFIX_ENABLED   = 'enabled';
	private const SUFFIX_PER_DAY   = 'per_day';
	private const SUFFIX_RECIPIENT = 'per_recipient';
	private const SUFFIX_AMOUNT    = 'max_amount';
	private const SUFFIX_CURRENCY  = 'max_amount_currency';

	/**
	 * Option name of a setting of a command, e.g. "vcap_command_issue_enabled".
	 *
	 * @param string $command Command (spec name).
	 * @param string $suffix  enabled, per_day, per_recipient or max_amount.
	 * @return string
	 */
	public static function option( string $command, string $suffix ): string {
		return 'vcap_command_' . substr( $command, strlen( 'voucher.' ) ) . '_' . $suffix;
	}

	/**
	 * Whether the operator switched the command on (default off, R-8.3d).
	 *
	 * @param string $command Command.
	 * @return bool
	 */
	public static function enabled( string $command ): bool {
		if ( ! isset( self::FIXED[ $command ] ) ) {
			return false;
		}
		return 'yes' === get_option( self::option( $command, self::SUFFIX_ENABLED ), 'no' ) && self::filtered( $command, array( 'enabled' => true ) + self::FIXED[ $command ], '' )['enabled'];
	}

	/**
	 * Effective limits of a command for an amount in $currency.
	 *
	 * @param string $command  Command.
	 * @param string $currency ISO 4217 currency of the amount ('' for commands without amount).
	 * @return array{per_day:int, per_recipient:int|null, max_amount_minor:int|null, amount_allowed:bool} amount_allowed
	 *         false: amounts in this currency are refused (F-05a).
	 */
	public static function effective( string $command, string $currency = '' ): array {
		$fixed = self::FIXED[ $command ];
		$out   = array(
			'per_day'          => self::lowered( $fixed['per_day'], get_option( self::option( $command, self::SUFFIX_PER_DAY ), '' ) ),
			'per_recipient'    => null === $fixed['per_recipient'] ? null : self::lowered( $fixed['per_recipient'], get_option( self::option( $command, self::SUFFIX_RECIPIENT ), '' ) ),
			'max_amount_minor' => null,
			'amount_allowed'   => true,
		);
		if ( null !== $fixed['max_amount_minor'] ) {
			$out['max_amount_minor'] = self::max_amount_minor( $command, strtoupper( $currency ) );
			$out['amount_allowed']   = null !== $out['max_amount_minor'];
		}
		$filtered = self::filtered( $command, array( 'enabled' => true ) + $out, strtoupper( $currency ) );
		foreach ( array( 'per_day', 'per_recipient', 'max_amount_minor' ) as $key ) {
			if ( null !== $out[ $key ] && isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ) {
				$out[ $key ] = max( 0, min( $out[ $key ], (int) $filtered[ $key ] ) ); // A filter only lowers.
			}
		}
		return $out;
	}

	/**
	 * Maximum amount in minor units of $currency, null when amounts in it are refused (F-05a).
	 *
	 * EUR: the fixed 100 EUR, or the operator's lower amount when the shop currency is EUR. A currency of
	 * SIMILAR_CURRENCIES as the shop currency: the same number 100 in it, or the operator's lower amount. Any other
	 * currency: only the shop currency, and only with an own maximum amount entered by the operator.
	 *
	 * @param string $command  Command.
	 * @param string $currency Currency (upper case).
	 * @return int|null
	 */
	private static function max_amount_minor( string $command, string $currency ): ?int {
		$fixed = (int) self::FIXED[ $command ]['max_amount_minor'];
		$shop  = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		$own   = $currency === $shop ? self::amount_setting( $command, $currency ) : null;
		if ( self::FIXED_CURRENCY === $currency ) {
			return null === $own ? $fixed : min( $fixed, $own );
		}
		$ceiling = $currency === $shop ? self::similar_ceiling( $command, $currency ) : null;
		if ( null !== $ceiling ) {
			return null === $own ? $ceiling : min( $ceiling, $own );
		}
		return $own;
	}

	/**
	 * The fixed ceiling number (100) in minor units of a currency of SIMILAR_CURRENCIES, null for any other currency.
	 *
	 * @param string $command  Command.
	 * @param string $currency Currency (upper case).
	 * @return int|null
	 */
	private static function similar_ceiling( string $command, string $currency ): ?int {
		if ( ! in_array( $currency, self::SIMILAR_CURRENCIES, true ) || null === self::FIXED[ $command ]['max_amount_minor'] ) {
			return null;
		}
		// FIXED is in EUR cents; the same number of major units in the other currency.
		return (int) round( self::FIXED[ $command ]['max_amount_minor'] / 100 * ( 10 ** RedemptionService::decimals( $currency ) ) );
	}

	/**
	 * The operator's maximum amount (setting, major units of the shop currency) in minor units, null when not set.
	 *
	 * @param string $command  Command.
	 * @param string $currency Shop currency.
	 * @return int|null
	 */
	private static function amount_setting( string $command, string $currency ): ?int {
		$raw = trim( (string) get_option( self::option( $command, self::SUFFIX_AMOUNT ), '' ) );
		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return null;
		}
		// PV-P3-4: an amount entered in another currency (shop currency changed, import) is no maximum here.
		if ( self::amount_currency( $command ) !== $currency ) {
			return null;
		}
		return (int) round( (float) $raw * ( 10 ** RedemptionService::decimals( $currency ) ) );
	}

	/**
	 * PV-P3-4: currency the operator's maximum amount was entered in (option "vcap_command_<cmd>_max_amount_currency",
	 * written when the settings page saves the amount; part of an export). An amount stored without a currency (before
	 * this option existed) is taken to be in the current shop currency once: that currency is recorded right away, so
	 * a later change of the shop currency no longer carries the number over. '' when no amount is stored.
	 *
	 * @param string $command Command.
	 * @return string
	 */
	public static function amount_currency( string $command ): string {
		$option   = self::option( $command, self::SUFFIX_CURRENCY );
		$currency = strtoupper( trim( (string) get_option( $option, '' ) ) );
		if ( '' !== $currency ) {
			return $currency;
		}
		if ( '' === trim( (string) get_option( self::option( $command, self::SUFFIX_AMOUNT ), '' ) ) ) {
			return '';
		}
		$currency = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		if ( '' !== $currency ) {
			update_option( $option, $currency, false );
		}
		return $currency;
	}

	/**
	 * PV-P3-4: whether the stored maximum amount of a command was entered in another currency than the shop currency
	 * (it is then not used: amounts are refused until a new maximum is saved).
	 *
	 * @param string $command Command.
	 * @return bool
	 */
	public static function amount_currency_mismatch( string $command ): bool {
		$recorded = self::amount_currency( $command );
		return '' !== $recorded && strtoupper( (string) get_option( 'woocommerce_currency', '' ) ) !== $recorded;
	}

	/**
	 * A count setting lowered to [0, ceiling]; '' (not set) = the ceiling. PV-P2-4: a whole number written as a decimal
	 * ("5.0", "1e1") counts as that number; anything else that is stored (e.g. "abc" or "-1" written around the settings
	 * page) never raises a limit: it counts as 0.
	 *
	 * @param int   $ceiling Fixed ceiling.
	 * @param mixed $setting Stored value.
	 * @return int
	 */
	private static function lowered( int $ceiling, mixed $setting ): int {
		if ( null === $setting || false === $setting || ( is_string( $setting ) && '' === trim( $setting ) ) ) {
			return $ceiling;
		}
		$count = self::whole_number( $setting );
		return null === $count ? 0 : max( 0, min( $ceiling, $count ) );
	}

	/**
	 * PV-P2-4: a value as a whole number from 0 ("5", "5.0", "1e1", 5, 5.0), null for anything else.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function whole_number( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		$text = is_float( $value ) || is_string( $value ) ? trim( (string) $value ) : '';
		if ( '' === $text || ! is_numeric( $text ) ) {
			return null;
		}
		$number = (float) $text;
		if ( $number < 0 || floor( $number ) !== $number ) {
			return null;
		}
		return (int) min( $number, 1000000000.0 ); // Far above every ceiling; avoids an integer overflow.
	}

	/**
	 * Filter "vcap_command_limits": lets code lower limits or switch a command off – never raise or switch on.
	 *
	 * @param string               $command  Command.
	 * @param array<string, mixed> $defaults Limits as they are without the filter.
	 * @param string               $currency Currency of the amount ('' = none).
	 * @return array<string, mixed>
	 */
	private static function filtered( string $command, array $defaults, string $currency ): array {
		$filtered =
			/**
			 * Lowers the limits of an automatic voucher command or switches it off (args: limits {enabled, per_day,
			 * per_recipient, max_amount_minor, amount_allowed}, command, currency of the amount). Values above the given
			 * ones, and enabled = true for a command the operator switched off, have no effect.
			 *
			 * @since 1.3.0 (contract 1.3.0)
			 */
			apply_filters( 'vcap_command_limits', $defaults, $command, $currency );
		$filtered            = is_array( $filtered ) ? $filtered : $defaults;
		$filtered['enabled'] = ! array_key_exists( 'enabled', $filtered ) || false !== $filtered['enabled'];
		return $filtered;
	}

	/* ---- Settings (section "Integrations (API)") --------------------------------------------------- */

	/**
	 * Settings fields of the four commands for the "Integrations (API)" section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		$labels = array(
			'voucher.issue'      => __( 'Issue vouchers (voucher.issue)', 'voucher-captain' ),
			'voucher.void'       => __( 'Void vouchers (voucher.void)', 'voucher-captain' ),
			'voucher.reactivate' => __( 'Reactivate vouchers (voucher.reactivate)', 'voucher-captain' ),
			'voucher.redeem'     => __( 'Redeem vouchers (voucher.redeem)', 'voucher-captain' ),
		);
		$fields = array(
			array(
				'type'  => 'title',
				'title' => __( 'Automatic voucher commands', 'voucher-captain' ),
				'id'    => 'vcap_commands',
				'desc'  => __( 'Add-ons such as workflow automations can issue, void, reactivate and redeem vouchers through these entry points. Each one is off until you switch it on. The limits below are the highest values Voucher Captain allows; you can only lower them. Every call is recorded in the voucher history without the code.', 'voucher-captain' ),
			),
		);
		foreach ( self::FIXED as $command => $fixed ) {
			// SET-VC-01: id, default and bounds from the settings register (config/settings.php, built from FIXED).
			$fields[] = SettingsRegistry::field(
				self::option( $command, self::SUFFIX_ENABLED ),
				array(
					'title' => $labels[ $command ],
					'type'  => 'checkbox',
					'desc'  => __( 'Allow this command', 'voucher-captain' ),
				)
			);
			$fields[] = SettingsRegistry::field(
				self::option( $command, self::SUFFIX_PER_DAY ),
				array(
					/* translators: %s: command, e.g. voucher.issue (PV-P5-5: every field names its command) */
					'title'             => sprintf( __( 'Calls per day (%s)', 'voucher-captain' ), $command ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					/* translators: %d: highest number of calls per day */
					'desc'              => sprintf( __( 'Per calendar day and site, all automations together (at most %d).', 'voucher-captain' ), $fixed['per_day'] ),
				)
			);
			if ( null !== $fixed['per_recipient'] ) {
				$fields[] = SettingsRegistry::field(
					self::option( $command, self::SUFFIX_RECIPIENT ),
					array(
						/* translators: %s: command, e.g. voucher.issue */
						'title'             => sprintf( __( 'Vouchers per recipient and day (%s)', 'voucher-captain' ), $command ),
						'type'              => 'number',
						'custom_attributes' => array( 'step' => 1 ),
						/* translators: %d: highest number per recipient and day */
						'desc'              => sprintf( __( 'Vouchers one recipient may get from this command per calendar day (at most %d).', 'voucher-captain' ), $fixed['per_recipient'] ),
					)
				);
			}
			if ( null !== $fixed['max_amount_minor'] ) {
				$desc = esc_html__( 'In the shop currency. Empty: 100 (in EUR, CHF, USD and GBP), at most 100 there. In a shop with another currency, the command refuses amounts until you enter a maximum here that is worth no more than 100 EUR.', 'voucher-captain' );
				if ( self::amount_currency_mismatch( $command ) ) {
					// PV-P3-4: the saved amount is in another currency and not used.
					$desc = '<strong>' . esc_html(
						sprintf(
							/* translators: 1: currency the amount was entered in, 2: shop currency */
							__( 'This maximum amount was entered in %1$s; the shop currency is now %2$s. It is not used: the command refuses amounts until you save a new maximum amount in %2$s.', 'voucher-captain' ),
							self::amount_currency( $command ),
							strtoupper( (string) get_option( 'woocommerce_currency', '' ) )
						)
					) . '</strong> ' . $desc;
				}
				$fields[] = SettingsRegistry::field(
					self::option( $command, self::SUFFIX_AMOUNT ),
					array(
						/* translators: %s: command, e.g. voucher.issue */
						'title' => sprintf( __( 'Maximum amount (%s)', 'voucher-captain' ), $command ),
						'type'  => 'text',
						'desc'  => $desc,
					)
				);
				// PV-P3-4: the currency the amount was entered in – shown read-only, recorded by sanitize_amount().
				$fields[] = SettingsRegistry::field(
					self::option( $command, self::SUFFIX_CURRENCY ),
					array(
						'title'             => __( 'Currency of the maximum amount', 'voucher-captain' ),
						'type'              => 'text',
						'custom_attributes' => array( 'readonly' => 'readonly' ),
						'desc'              => __( 'Recorded when you save the maximum amount. If the shop currency changes, the maximum amount is not used until you save a new one.', 'voucher-captain' ),
					)
				);
			}
		}
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'vcap_commands',
		);
		return $fields;
	}

	/**
	 * Sanitizes a saved count setting: a whole number within [0, ceiling] (also written as "5.0" or "1e1"), empty for
	 * the ceiling. PV-P2-4: anything else ("abc", "-1", "2.5") is refused with an error on the settings page and the
	 * stored value is kept – it never silently becomes the ceiling.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return string
	 */
	public static function sanitize_count( string $option, mixed $value ): string {
		foreach ( self::FIXED as $command => $fixed ) {
			foreach ( array(
				self::SUFFIX_PER_DAY   => $fixed['per_day'],
				self::SUFFIX_RECIPIENT => $fixed['per_recipient'],
			) as $suffix => $ceiling ) {
				if ( null === $ceiling || self::option( $command, $suffix ) !== $option ) {
					continue;
				}
				$text = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' === $text ) {
					return (string) $ceiling;
				}
				$count = self::whole_number( $text );
				if ( null !== $count ) {
					return (string) min( (int) $ceiling, $count );
				}
				$kept = (string) self::lowered( (int) $ceiling, get_option( $option, '' ) );
				if ( class_exists( '\WC_Admin_Settings' ) ) {
					\WC_Admin_Settings::add_error(
						sprintf(
							/* translators: 1: setting, e.g. "Calls per day", 2: command, e.g. "voucher.issue", 3: value entered, 4: highest number, 5: value kept */
							__( '%1$s (%2$s): "%3$s" is not a whole number from 0 to %4$d. The saved value %5$s was kept.', 'voucher-captain' ),
							self::SUFFIX_PER_DAY === $suffix ? __( 'Calls per day', 'voucher-captain' ) : __( 'Vouchers per recipient and day', 'voucher-captain' ),
							$command,
							sanitize_text_field( $text ),
							(int) $ceiling,
							$kept
						)
					);
				}
				return $kept;
			}
		}
		return '';
	}

	/**
	 * Sanitizes a saved maximum amount: '' or a non-negative decimal; in a shop in EUR or SIMILAR_CURRENCIES never above 100.
	 *
	 * PV-P3-4: with $record (the settings page save) the shop currency is stored with the amount
	 * ("vcap_command_<cmd>_max_amount_currency"). An unchanged amount that was entered in another currency is kept
	 * with that currency (still not used, the page says so) – only a newly entered amount counts in the shop currency.
	 * Without $record (checks of an import or WP-CLI) nothing is stored.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @param bool   $record Store the shop currency with the amount (settings page).
	 * @return string
	 */
	public static function sanitize_amount( string $option, mixed $value, bool $record = false ): string {
		$amount = self::clean_amount( $option, $value );
		if ( ! $record ) {
			return $amount;
		}
		$shop = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		foreach ( self::FIXED as $command => $fixed ) {
			if ( null === $fixed['max_amount_minor'] || self::option( $command, self::SUFFIX_AMOUNT ) !== $option ) {
				continue;
			}
			$recorded = self::amount_currency( $command );
			$stored   = trim( (string) get_option( $option, '' ) );
			if ( '' !== $amount && '' !== $recorded && $recorded !== $shop && is_numeric( $stored ) && (float) $stored === (float) $amount ) {
				return $stored; // Not entered again: stays in its currency and is not used.
			}
			if ( '' === $amount ) {
				delete_option( self::option( $command, self::SUFFIX_CURRENCY ) );
			} elseif ( '' !== $shop ) {
				update_option( self::option( $command, self::SUFFIX_CURRENCY ), $shop, false );
			}
		}
		return $amount;
	}

	/**
	 * The amount check of sanitize_amount(), without storing anything.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return string
	 */
	private static function clean_amount( string $option, mixed $value ): string {
		$value = is_scalar( $value ) ? trim( str_replace( ',', '.', (string) $value ) ) : '';
		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			return '';
		}
		$currency = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		$decimals = RedemptionService::decimals( $currency );
		$amount   = round( (float) $value, $decimals );
		foreach ( self::FIXED as $command => $fixed ) {
			if ( self::option( $command, self::SUFFIX_AMOUNT ) !== $option || null === $fixed['max_amount_minor'] ) {
				continue;
			}
			$ceiling = self::FIXED_CURRENCY === $currency ? $fixed['max_amount_minor'] : self::similar_ceiling( $command, $currency );
			if ( null !== $ceiling ) {
				$amount = min( $amount, $ceiling / ( 10 ** $decimals ) );
			}
		}
		return number_format( $amount, $decimals, '.', '' );
	}

	/**
	 * Option names of all count settings and all amount settings.
	 *
	 * @return array{counts: string[], amounts: string[], currencies: string[]}
	 */
	public static function setting_options(): array {
		$out = array(
			'counts'     => array(),
			'amounts'    => array(),
			'currencies' => array(),
		);
		foreach ( self::FIXED as $command => $fixed ) {
			$out['counts'][] = self::option( $command, self::SUFFIX_PER_DAY );
			if ( null !== $fixed['per_recipient'] ) {
				$out['counts'][] = self::option( $command, self::SUFFIX_RECIPIENT );
			}
			if ( null !== $fixed['max_amount_minor'] ) {
				$out['amounts'][]    = self::option( $command, self::SUFFIX_AMOUNT );
				$out['currencies'][] = self::option( $command, self::SUFFIX_CURRENCY );
			}
		}
		return $out;
	}
}
