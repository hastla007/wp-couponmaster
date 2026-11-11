/* Voucher Captain – server-rendered blocks (same output as the shortcodes). */
( function ( wp, titles ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	var icons = { 'vcap/balance': 'search', 'vcap/redeem': 'tickets-alt', 'vcap/my-vouchers': 'id-alt', 'vcap/referral': 'groups' };
	Object.keys( titles || {} ).forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			apiVersion: 2,
			title: titles[ name ],
			icon: icons[ name ] || 'tickets-alt',
			category: 'widgets',
			supports: { html: false },
			edit: function () {
				var props = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
				return el( 'div', props, ServerSideRender ? el( ServerSideRender, { block: name } ) : titles[ name ] );
			},
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp, window.vcapBlocks );
