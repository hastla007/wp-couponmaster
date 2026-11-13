/*
 * Voucher Captain – server-rendered blocks (same output as the shortcodes). Name, title, icon, apiVersion and
 * style come from assets/blocks/{name}/block.json, registered on the server; only the editor view is added here.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	[ 'vcap/balance', 'vcap/redeem', 'vcap/my-vouchers', 'vcap/referral' ].forEach( function ( name ) {
		var type = wp.blocks.getBlockType ? wp.blocks.getBlockType( name ) : null;
		var settings = {
			edit: function () {
				var props = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
				return el( 'div', props, ServerSideRender ? el( ServerSideRender, { block: name } ) : name );
			},
			save: function () {
				return null;
			}
		};
		if ( ! type ) {
			wp.blocks.registerBlockType( name, settings );
		}
	} );
} )( window.wp );
