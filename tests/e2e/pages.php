<?php
/**
 * Switch the cart/checkout pages between the classic shortcodes and the blocks: wp eval-file pages.php classic|blocks
 */
$state = json_decode( (string) file_get_contents( __DIR__ . '/.state.json' ), true );
$mode  = ( $args[0] ?? 'blocks' ) === 'classic' ? 'classic' : 'block';
update_option( 'woocommerce_cart_page_id', $state[ $mode . '_cart' ] );
update_option( 'woocommerce_checkout_page_id', $state[ $mode . '_checkout' ] );
echo "Cart and checkout pages: {$mode}.\n";
