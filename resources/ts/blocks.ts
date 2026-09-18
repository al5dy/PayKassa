interface PaymentDirection {
	value: string;
	label: string;
}

interface PaykassaBlockData {
	title?: string;
	description?: string;
	supports?: string[];
	available?: boolean;
	directions?: PaymentDirection[];
}

interface WcBlocksRegistry {
	registerPaymentMethod: ( config: Record<string, unknown> ) => void;
}

interface WcSettings {
	getSetting: <T>( name: string, fallback: T ) => T;
}

interface PaymentSetupEventRegistration {
	onPaymentSetup: ( callback: () => unknown ) => () => void;
}

interface EmitResponseTypes {
	SUCCESS: string;
}

interface EmitResponse {
	responseTypes: EmitResponseTypes;
}

interface PaymentContentProps {
	eventRegistration?: PaymentSetupEventRegistration;
	emitResponse?: EmitResponse;
}

interface WpElement {
	createElement: ( type: unknown, props: Record<string, unknown> | null, ...children: unknown[] ) => unknown;
	useState: <T>( initial: T ) => [ T, ( value: T ) => void ];
	useEffect: ( effect: () => void | ( () => void ), deps: unknown[] ) => void;
}

interface WpI18n {
	__: ( text: string, domain?: string ) => string;
}

declare global {
	interface Window {
		wc?: {
			wcBlocksRegistry?: WcBlocksRegistry;
			wcSettings?: WcSettings;
		};
		wp?: {
			element?: WpElement;
			i18n?: WpI18n;
		};
	}
}

( function ( blocksRegistry?: WcBlocksRegistry, settings?: WcSettings, element?: WpElement, i18n?: WpI18n ): void {
	if ( ! blocksRegistry || ! settings || ! element ) {
		return;
	}

	const t = i18n as WpI18n;
	const data = settings.getSetting<PaykassaBlockData>( 'paykassa_data', {} );
	const label = element.createElement( 'span', null, data.title || t.__( 'Cryptocurrency (PayKassa)', 'paykassa' ) );

	const Content = ( props: PaymentContentProps ) => {
		const choices = data.directions || [];
		const [ system, setSystem ] = element.useState( choices[ 0 ] ? choices[ 0 ].value : '' );

		element.useEffect( () => {
			if ( ! props.eventRegistration || ! props.emitResponse ) {
				return () => {};
			}
			return props.eventRegistration.onPaymentSetup( () => ( {
				type: props.emitResponse!.responseTypes.SUCCESS,
				meta: { paymentMethodData: { paykassa_direction: system } },
			} ) );
		}, [ system, props.eventRegistration, props.emitResponse ] );

		return element.createElement(
			'div',
			{ className: 'wc-paykassa-block', role: 'group', 'aria-label': t.__( 'PayKassa payment options', 'paykassa' ) },
			element.createElement( 'p', { role: 'status', 'aria-live': 'polite' }, data.description || '' ),
			element.createElement(
				'label',
				null,
				t.__( 'Pay with cryptocurrency', 'paykassa' ),
				element.createElement(
					'select',
					{
						value: system,
						onChange: ( event: { target: { value: string } } ) => setSystem( event.target.value ),
					},
					choices.map( ( choice ) => element.createElement( 'option', { key: choice.value, value: choice.value }, choice.label ) )
				)
			)
		);
	};

	blocksRegistry.registerPaymentMethod( {
		name: 'paykassa',
		paymentMethodId: 'paykassa',
		ariaLabel: data.title || t.__( 'Pay with PayKassa', 'paykassa' ),
		label,
		content: element.createElement( Content, null ),
		edit: element.createElement( Content, null ),
		canMakePayment: () => !! data.available,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )( window.wc && window.wc.wcBlocksRegistry, window.wc && window.wc.wcSettings, window.wp && window.wp.element, window.wp && window.wp.i18n );

export {};
