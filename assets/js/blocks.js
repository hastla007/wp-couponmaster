/*
 * Voucher Captain – server-rendered blocks (same output as the shortcodes). Name, title, icon, apiVersion,
 * attributes, keywords and example come from assets/blocks/{name}/block.json, registered on the server; only the
 * editor view is added here. The preview is wrapped in Disabled: Enter or a click in it sends no form and opens
 * no download. Texts come translated from PHP (window.vcapBlocks).
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	var components = wp.components || {};
	var blockEditor = wp.blockEditor || {};
	var t = window.vcapBlocks || {};

	/** Inspector settings of a block (only for blocks with attributes). */
	function inspector( name, props ) {
		if ( ! blockEditor.InspectorControls || ! components.PanelBody ) {
			return null;
		}
		var controls = [];
		if ( 'vcap/redeem' === name && components.TextControl ) {
			controls.push(
				el( components.TextControl, {
					key: 'buttonText',
					label: t.buttonText || 'Button text',
					help: t.buttonHelp || '',
					value: props.attributes.buttonText || '',
					onChange: function ( value ) {
						props.setAttributes( { buttonText: value } );
					},
				} )
			);
		}
		if ( 'vcap/my-vouchers' === name && components.ToggleControl ) {
			controls.push(
				el( components.ToggleControl, {
					key: 'showExtras',
					label: t.showExtras || 'Show store credit and referral code',
					checked: false !== props.attributes.showExtras,
					onChange: function ( value ) {
						props.setAttributes( { showExtras: value } );
					},
				} )
			);
		}
		if ( ! controls.length ) {
			return null;
		}
		return el( blockEditor.InspectorControls, { key: 'inspector' }, el( components.PanelBody, { title: t.settings || 'Settings' }, controls ) );
	}

	[ 'vcap/balance', 'vcap/redeem', 'vcap/my-vouchers', 'vcap/referral' ].forEach( function ( name ) {
		var type = wp.blocks.getBlockType ? wp.blocks.getBlockType( name ) : null;
		var settings = {
			edit: function ( props ) {
				var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
				var preview = ServerSideRender ? el( ServerSideRender, { block: name, attributes: props.attributes } ) : name;
				if ( components.Disabled ) {
					preview = el( components.Disabled, null, preview );
				}
				return el( wp.element.Fragment, null, inspector( name, props ), el( 'div', blockProps, preview ) );
			},
			save: function () {
				return null;
			},
		};
		if ( ! type ) {
			wp.blocks.registerBlockType( name, settings );
		}
	} );
} )( window.wp );
