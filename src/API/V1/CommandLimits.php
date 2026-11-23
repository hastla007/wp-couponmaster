<?php
/**
 * Fixed limits and switches of the four voucher commands (suite spec 8.3.1).
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

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
		return (int) round( (float) $raw * ( 10 ** RedemptionService::decimals( $currency ) ) );
	}

	/**
	 * A count setting lowered to [0, ceiling]; '' (not set) or anything that is no whole number = the ceiling.
	 *
	 * @param int   $ceiling Fixed ceiling.
	 * @param mixed $setting Stored value.
	 * @return int
	 */
	private static function lowered( int $ceiling, mixed $setting ): int {
		if ( is_int( $setting ) || ( is_string( $setting ) && preg_match( '/^\s*\d+\s*$/', $setting ) ) ) {
			return max( 0, min( $ceiling, (int) $setting ) );
		}
		return $ceiling;
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
			$fields[] = array(
				'title'   => $labels[ $command ],
				'id'      => self::option( $command, self::SUFFIX_ENABLED ),
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Allow this command', 'voucher-captain' ),
			);
			$fields[] = array(
				'title'             => __( 'Calls per day', 'voucher-captain' ),
				'id'                => self::option( $command, self::SUFFIX_PER_DAY ),
				'type'              => 'number',
				'default'           => (string) $fixed['per_day'],
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => $fixed['per_day'],
					'step' => 1,
				),
				/* translators: %d: highest number of calls per day */
				'desc'              => sprintf( __( 'Per calendar day and site, all automations together (at most %d).', 'voucher-captain' ), $fixed['per_day'] ),
			);
			if ( null !== $fixed['per_recipient'] ) {
				$fields[] = array(
					'title'             => __( 'Vouchers per recipient and day', 'voucher-captain' ),
					'id'                => self::option( $command, self::SUFFIX_RECIPIENT ),
					'type'              => 'number',
					'default'           => (string) $fixed['per_recipient'],
					'custom_attributes' => array(
						'min'  => 0,
						'max'  => $fixed['per_recipient'],
						'step' => 1,
					),
					/* translators: %d: highest number per recipient and day */
					'desc'              => sprintf( __( 'At most %d.', 'voucher-captain' ), $fixed['per_recipient'] ),
				);
			}
			if ( null !== $fixed['max_amount_minor'] ) {
				$fields[] = array(
					'title'   => __( 'Maximum amount', 'voucher-captain' ),
					'id'      => self::option( $command, self::SUFFIX_AMOUNT ),
					'type'    => 'text',
					'default' => '',
					'desc'    => __( 'In the shop currency. Empty: 100 (in EUR, CHF, USD and GBP), at most 100 there. In a shop with another currency, the command refuses amounts until you enter a maximum here that is worth no more than 100 EUR.', 'voucher-captain' ),
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
	 * Sanitizes a saved count setting: a whole number within [0, ceiling], '' for the ceiling.
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
				if ( null !== $ceiling && self::option( $command, $suffix ) === $option ) {
					$value = is_scalar( $value ) ? trim( (string) $value ) : '';
					return preg_match( '/^\d+$/', $value ) ? (string) min( (int) $ceiling, (int) $value ) : (string) $ceiling;
				}
			}
		}
		return '';
	}

	/**
	 * Sanitizes a saved maximum amount: '' or a non-negative decimal; in a shop in EUR or SIMILAR_CURRENCIES never above 100.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return string
	 */
	public static function sanitize_amount( string $option, mixed $value ): string {
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
	 * @return array{counts: string[], amounts: string[]}
	 */
	public static function setting_options(): array {
		$out = array(
			'counts'  => array(),
			'amounts' => array(),
		);
		foreach ( self::FIXED as $command => $fixed ) {
			$out['counts'][] = self::option( $command, self::SUFFIX_PER_DAY );
			if ( null !== $fixed['per_recipient'] ) {
				$out['counts'][] = self::option( $command, self::SUFFIX_RECIPIENT );
			}
			if ( null !== $fixed['max_amount_minor'] ) {
				$out['amounts'][] = self::option( $command, self::SUFFIX_AMOUNT );
			}
		}
		return $out;
	}
}
