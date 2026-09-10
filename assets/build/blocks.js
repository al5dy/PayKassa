( function( blocksRegistry, settings, element, i18n ) {
	if ( ! blocksRegistry || ! settings || ! element ) { return; }
	var data = settings.getSetting( 'paykassa_data', {} );
	var label = element.createElement( 'span', null, data.title || i18n.__( 'Cryptocurrency (PayKassa)', 'paykassa' ) );
	var Content = function( props ) {
		var choices = data.systems || [];
		var selection = element.useState( choices[ 0 ] ? choices[ 0 ].value : '' );
		var system = selection[ 0 ], setSystem = selection[ 1 ];
		element.useEffect( function() {
			if ( ! props.eventRegistration || ! props.emitResponse ) { return function() {}; }
			return props.eventRegistration.onPaymentSetup( function() {
				return { type: props.emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: { paykassa_system: system } } };
			} );
		}, [ system, props.eventRegistration, props.emitResponse ] );
		return element.createElement( 'div', { className: 'wc-paykassa-block', role: 'group', 'aria-label': i18n.__( 'PayKassa payment options', 'paykassa' ) },
			element.createElement( 'p', { role: 'status', 'aria-live': 'polite' }, data.description || '' ),
			element.createElement( 'label', null, i18n.__( 'Cryptocurrency network', 'paykassa' ),
				element.createElement( 'select', { value: system, onChange: function( event ) { setSystem( event.target.value ); } }, choices.map( function( choice ) { return element.createElement( 'option', { key: choice.value, value: choice.value }, choice.label ); } ) )
			)
		);
	};
	blocksRegistry.registerPaymentMethod( {
		name: 'paykassa', paymentMethodId: 'paykassa', ariaLabel: data.title || i18n.__( 'Pay with PayKassa', 'paykassa' ), label: label,
		content: element.createElement( Content, null ), edit: element.createElement( Content, null ),
		canMakePayment: function() { return !! data.available; }, supports: { features: data.supports || [ 'products' ] }
	} );
} )( window.wc && window.wc.wcBlocksRegistry, window.wc && window.wc.wcSettings, window.wp && window.wp.element, window.wp && window.wp.i18n );
