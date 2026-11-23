<?php
/**
 * Symbols of Form Captain (hastla007/wp-formmaster, contract v1) for the static analysis of the optional
 * adapter in src/Integrations/FormCaptain. Never loaded at runtime or by the tests; listed under scanFiles in
 * phpstan-wide.neon.dist. Copied from src/Integrations/ConnectorInterface.php and includes/functions.php.
 *
 * @package VoucherCaptain
 */

// phpcs:disable

namespace FormCaptain\Integrations {

	interface ConnectorInterface {
		public function key(): string;

		public function label(): string;

		public function version(): string;

		public function contract(): string;

		/**
		 * @return string[]
		 */
		public function capabilities(): array;

		/**
		 * @return array{ok:bool,messages:string[]}
		 */
		public function requirements(): array;

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public function settings_schema(): array;

		public function register_actions(): void;
	}
}

namespace {

	/**
	 * @param array<string,mixed> $definition
	 * @return true|\WP_Error
	 */
	function form_captain_register_action_v1( string $key, array $definition, callable $callback ) {
		return true;
	}
}
