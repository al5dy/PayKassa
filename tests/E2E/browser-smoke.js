async page => {
	const initialUrl = page.url();
	const baseUrl = initialUrl.replace( /\/\?.*$/, '' );
	const productMatch = initialUrl.match( /[?&]paykassa_e2e_product=(\d+)/ );
	const unauthorizedOrderMatch = initialUrl.match( /[?&]paykassa_e2e_unauthorized_order=(\d+)/ );
	const productId = productMatch ? productMatch[ 1 ] : '';
	const unauthorizedOrderId = unauthorizedOrderMatch ? unauthorizedOrderMatch[ 1 ] : '';
	const consoleErrors = [];
	page.on( 'console', message => {
		if ( message.type() === 'error' && message.location().url.startsWith( baseUrl || '' ) ) {
			consoleErrors.push( message.text() );
		}
	} );

	if ( ! baseUrl || ! productId || ! unauthorizedOrderId ) {
		throw new Error( 'The initial browser URL must contain PayKassa product and unauthorized-order fixtures.' );
	}

	const assert = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( message );
		}
	};
	const originOf = url => {
		const match = String( url ).match( /^(https?):\/\/([^/]+)/i );
		return match ? `${ match[ 1 ].toLowerCase() }://${ match[ 2 ].toLowerCase() }` : '';
	};
	const queryValue = ( url, name ) => {
		const match = String( url ).match( new RegExp( `[?&]${ name }=([^&#]*)` ) );
		return match ? match[ 1 ] : '';
	};
	const readMerchantUrls = async () => {
		const response = await page.request.get( `${ baseUrl }/?paykassa_browser_merchant_urls=1` );
		assert( response.status() === 200, 'The disposable site must expose its test-only generated Merchant URLs.' );
		return response.json();
	};
	let merchantUrls = await readMerchantUrls();
	const canonicalHomeUrl = String( merchantUrls.canonical_home_url_reversed || '' ).split( '' ).reverse().join( '' );
	assert(
		canonicalHomeUrl.startsWith( 'https://127.0.0.1:' ) &&
			originOf( canonicalHomeUrl ) !== originOf( baseUrl ),
		`The browser smoke must use a private canonical WordPress origin distinct from the public checkout origin (canonical=${ canonicalHomeUrl }, public=${ baseUrl }).`
	);
	assert(
		merchantUrls.callback_source === 'external_override' && merchantUrls.browser_return_source === 'external_override',
		'Same-origin fixture must explicitly configure both independent public base overrides.'
	);
	assert(
		merchantUrls.invoice_notification_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa`,
		'Same-origin configuration must apply the public checkout base to the Invoice Payment Notification URL.'
	);
	assert(
		merchantUrls.transaction_notification_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_transaction`,
		'Same-origin configuration must apply the public checkout base to the Transaction Processor URL.'
	);
	assert(
		merchantUrls.success_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_return`,
		'Same-origin success return must use the public origin where checkout is performed.'
	);
	assert(
		merchantUrls.failure_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_cancel`,
		'Same-origin failure return must use the public origin where checkout is performed.'
	);
	assert(
		merchantUrls.invoice_notification_url.startsWith( `${ baseUrl }/` ) &&
			merchantUrls.success_return_url.startsWith( `${ baseUrl }/` ),
		'The browser smoke must begin with callback and browser returns on one public checkout origin.'
	);
	const orderIdFromHostedUrl = () => {
		const match = page.url().match( /^https:\/\/paykassa\.app\/browser-smoke\?[^#]*\border_id=(\d+)/ );
		assert( !! match, 'Checkout must produce the reviewed PayKassa hosted URL.' );
		return match[ 1 ];
	};
	const postNotification = async ( endpointUrl, orderId, privateHash, rawHints = {} ) => {
		const response = await page.request.post( endpointUrl, {
			form: {
				private_hash: privateHash,
				order_id: orderId,
				...rawHints,
			},
			maxRedirects: 0,
		} );
		const body = await response.text();
		assert( response.status() === 200, `${ endpointUrl } must return HTTP 200 after verified processing.` );
		assert( response.headers()[ 'content-type' ] === 'text/plain; charset=utf-8', `${ endpointUrl } must return text/plain.` );
		assert( body === `${ orderId }|success`, `${ endpointUrl } response must be the exact PayKassa acknowledgement without HTML or whitespace.` );
	};
	const addProduct = async () => {
		await page.goto( `${ baseUrl }/?add-to-cart=${ productId }`, { waitUntil: 'networkidle' } );
	};
	const waitForHostedRedirect = async button => {
		await button.click();
		await page.waitForURL( /https:\/\/paykassa\.app\/browser-smoke\?/, { timeout: 30000 } );
	};
	const returnRedirect = async ( endpointUrl, orderId ) => {
		const response = await page.request.get( `${ endpointUrl }&order_id=${ orderId }`, { maxRedirects: 0 } );
		const location = response.headers().location || '';
		assert( response.status() === 302, 'Browser return must respond with a redirect.' );
		assert( originOf( location ) === originOf( baseUrl ), 'Final WooCommerce destination must remain on the configured public browser origin.' );
		return location;
	};

	await addProduct();
	await page.goto( `${ baseUrl }/classic-checkout/`, { waitUntil: 'networkidle' } );
	await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'Browser' );
	await page.getByRole( 'textbox', { name: 'Last name' } ).fill( 'Customer' );
	await page.getByRole( 'textbox', { name: 'Street address' } ).fill( '1 Test Street' );
	await page.getByRole( 'textbox', { name: 'Town / City' } ).fill( 'Los Angeles' );
	await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '90001' );
	await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '5551234567' );
	await page.getByRole( 'textbox', { name: 'Email address' } ).fill( 'browser@example.invalid' );
	await page.getByRole( 'combobox', { name: 'Pay with cryptocurrency' } ).selectOption( 'ethereum_erc20:USDT' );
	await waitForHostedRedirect( page.getByRole( 'button', { name: 'Place order' } ) );
	const classicOrderId = orderIdFromHostedUrl();

	await postNotification( merchantUrls.invoice_notification_url, classicOrderId, `browser-invoice-${ classicOrderId }-0123456789abcdef` );
	await postNotification(
		merchantUrls.transaction_notification_url,
		classicOrderId,
		`browser-transaction-confirmed-${ classicOrderId }-0123456789abcdef`,
		{ currency: 'UNTRUSTED', system: 'UNTRUSTED' }
	);
	const classicReturnLocation = await returnRedirect( merchantUrls.success_return_url, classicOrderId );
	const classicOrderKey = queryValue( classicReturnLocation, 'key' );
	assert( !! classicOrderKey, 'Mapped thank-you redirect must preserve the WooCommerce order key.' );
	await page.goto( classicReturnLocation, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/order-received/${ classicOrderId }/` ), 'Classic success return must reach the native order-received URL.' );
	assert( originOf( page.url() ) === originOf( baseUrl ), 'Classic thank-you page must stay on the public checkout origin.' );
	assert( queryValue( page.url(), 'key' ) === classicOrderKey, 'Classic thank-you navigation must preserve the mapped order key exactly.' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Classic success return must render the WooCommerce thank-you page.' );

	const originSwitch = await page.request.post( `${ baseUrl }/?paykassa_browser_url_scenario=split`, {
		form: { token: 'paykassa-browser-fixture' },
		maxRedirects: 0,
	} );
	assert( originSwitch.status() === 204, 'The disposable site must switch to its split callback/browser-origin fixture.' );
	merchantUrls = await readMerchantUrls();
	assert(
		merchantUrls.callback_source === 'external_override' && merchantUrls.browser_return_source === 'external_override',
		'Split callback fixture must retain the explicit public browser-return origin.'
	);
	assert(
		merchantUrls.invoice_notification_url.startsWith( 'https://127.0.0.1:' ) &&
			merchantUrls.transaction_notification_url.startsWith( 'https://127.0.0.1:' ),
		'Split-origin configuration must use the independent callback base for both server notifications.'
	);
	assert(
		merchantUrls.success_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_return` &&
			merchantUrls.failure_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_cancel`,
		'Split callback configuration must keep browser returns on the independent public checkout origin.'
	);
	assert(
		! merchantUrls.invoice_notification_url.startsWith( `${ baseUrl }/` ),
		'Split-origin fixture must genuinely use a different callback origin.'
	);
	const checkoutSwitch = await page.request.post( `${ baseUrl }/?paykassa_browser_checkout=blocks`, {
		form: { token: 'paykassa-browser-fixture' },
		maxRedirects: 0,
	} );
	assert( checkoutSwitch.status() === 204, 'The disposable site must switch its native checkout page to the Blocks fixture.' );

	await addProduct();
	await page.goto( `${ baseUrl }/blocks-checkout/`, { waitUntil: 'networkidle' } );
	await page.getByRole( 'radio', { name: 'Cryptocurrency (PayKassa)' } ).waitFor();
	await page.getByRole( 'combobox', { name: 'Pay with cryptocurrency' } ).selectOption( 'ethereum_erc20:USDT' );
	await waitForHostedRedirect( page.getByRole( 'button', { name: 'Place Order' } ) );
	const blocksOrderId = orderIdFromHostedUrl();

	await postNotification( merchantUrls.transaction_notification_url, blocksOrderId, `browser-transaction-confirmed-${ blocksOrderId }-0123456789abcdef` );
	await postNotification( merchantUrls.invoice_notification_url, blocksOrderId, `browser-invoice-${ blocksOrderId }-0123456789abcdef` );
	const blocksReturnLocation = await returnRedirect( merchantUrls.success_return_url, blocksOrderId );
	const blocksOrderKey = queryValue( blocksReturnLocation, 'key' );
	assert( !! blocksOrderKey, 'Blocks thank-you redirect must preserve the WooCommerce order key.' );
	await page.goto( blocksReturnLocation, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/order-received/${ blocksOrderId }/` ), 'Blocks success return must reach the native order-received URL.' );
	assert( originOf( page.url() ) === originOf( baseUrl ), 'Blocks thank-you page must stay on the public checkout origin.' );
	assert( queryValue( page.url(), 'key' ) === blocksOrderKey, 'Blocks thank-you navigation must preserve the mapped order key exactly.' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Blocks success return must render the WooCommerce thank-you page.' );

	await addProduct();
	await page.goto( `${ baseUrl }/blocks-checkout/`, { waitUntil: 'networkidle' } );
	await page.getByRole( 'radio', { name: 'Cryptocurrency (PayKassa)' } ).waitFor();
	await waitForHostedRedirect( page.getByRole( 'button', { name: 'Place Order' } ) );
	const failedOrderId = orderIdFromHostedUrl();
	await postNotification( merchantUrls.transaction_notification_url, failedOrderId, `browser-transaction-pending-${ failedOrderId }-0123456789abcdef` );
	const failureReturnLocation = await returnRedirect( merchantUrls.failure_return_url, failedOrderId );
	const failureOrderKey = queryValue( failureReturnLocation, 'key' );
	assert( !! failureOrderKey, 'Mapped retry redirect must preserve the WooCommerce order key.' );
	assert( queryValue( failureReturnLocation, 'pay_for_order' ) === 'true', 'Mapped retry redirect must preserve pay_for_order=true.' );
	await page.goto( failureReturnLocation, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/blocks-checkout/order-pay/${ failedOrderId }/` ), 'Failure return must reach the native retry-payment URL.' );
	assert( originOf( page.url() ) === originOf( baseUrl ), 'Retry page must stay on the public checkout origin.' );
	assert( queryValue( page.url(), 'key' ) === failureOrderKey, 'Retry navigation must preserve the mapped order key exactly.' );
	assert( queryValue( page.url(), 'pay_for_order' ) === 'true', 'Retry navigation must preserve pay_for_order=true.' );
	assert( await page.getByText( 'The PayKassa payment was not completed.' ).isVisible(), 'Failure return must show a generic retry notice.' );
	assert( await page.getByRole( 'button', { name: 'Pay for order' } ).isVisible(), 'Failure return must leave the unpaid order retryable.' );
	const failureStateResponse = await page.request.get(
		`${ baseUrl }/?paykassa_browser_order_state=${ failedOrderId }&token=paykassa-browser-fixture`
	);
	const failureState = await failureStateResponse.json();
	assert(
		failureStateResponse.status() === 200 && failureState.paid === false && failureState.order_status === 'pending' && failureState.payment_state === 'awaiting_payment',
		'Browser failure return must not mutate or settle the awaiting-payment order.'
	);

	await postNotification( merchantUrls.invoice_notification_url, failedOrderId, `browser-invoice-mismatch-${ failedOrderId }-0123456789abcdef` );
	await postNotification( merchantUrls.invoice_notification_url, failedOrderId, `browser-invoice-mismatch-${ failedOrderId }-0123456789abcdef` );
	const mismatchStateResponse = await page.request.get(
		`${ baseUrl }/?paykassa_browser_order_state=${ failedOrderId }&token=paykassa-browser-fixture`
	);
	assert( mismatchStateResponse.status() === 200, 'Mismatch state fixture must remain readable.' );
	const mismatchState = await mismatchStateResponse.json();
	assert(
		mismatchState.paid === false && mismatchState.order_status === 'on-hold' && mismatchState.payment_state === 'manual_review',
		'Verified invoice mismatch and its duplicate must be acknowledged without payment settlement.'
	);

	const invoiceGet = await page.request.get( merchantUrls.invoice_notification_url, { maxRedirects: 0 } );
	const invoiceMissingHash = await page.request.post( merchantUrls.invoice_notification_url, { form: {}, maxRedirects: 0 } );
	const transactionMissingHash = await page.request.post( merchantUrls.transaction_notification_url, { form: {}, maxRedirects: 0 } );
	const returnPost = await page.request.post( `${ merchantUrls.success_return_url }&order_id=${ failedOrderId }`, { form: {}, maxRedirects: 0 } );
	const unknownReturn = await page.request.get( `${ merchantUrls.success_return_url }&order_id=999999999`, { maxRedirects: 0 } );
	const unauthorizedReturn = await page.request.get( `${ merchantUrls.success_return_url }&order_id=${ unauthorizedOrderId }`, { maxRedirects: 0 } );
	assert( invoiceGet.status() === 405, 'Invoice notification must be POST-only.' );
	assert( invoiceMissingHash.status() === 400, 'Invoice notification without private_hash must fail closed.' );
	assert( transactionMissingHash.status() === 400, 'Transaction notification without private_hash must fail closed.' );
	assert( returnPost.status() === 405, 'Browser return must be GET-only.' );
	assert( unknownReturn.status() === 302, 'Unknown browser return must use a safe generic redirect.' );
	assert( ! ( unknownReturn.headers().location || '' ).includes( '999999999' ), 'Unknown browser return must not disclose an order-specific URL.' );
	assert( unauthorizedReturn.status() === 302, 'Unauthorized existing order must use a safe generic redirect.' );
	assert( originOf( unauthorizedReturn.headers().location || '' ) === originOf( baseUrl ), 'Unauthorized fallback must remain on the safe public browser origin.' );
	assert( ! ( unauthorizedReturn.headers().location || '' ).includes( unauthorizedOrderId ), 'Unauthorized fallback must not disclose the existing order ID.' );
	assert( queryValue( unauthorizedReturn.headers().location || '', 'key' ) === '', 'Unauthorized fallback must not leak the existing order key.' );

	assert( consoleErrors.length === 0, 'Store-owned pages must have no browser console errors.' );

	return {
		classicOrderId,
		blocksOrderId,
		failedOrderId,
		invoiceFirstThenTransaction: true,
		transactionFirstThenInvoice: true,
		samePublicOriginSessionPreserved: true,
		splitOriginSessionPreserved: true,
		privateCanonicalToPublicDestinationMapped: true,
		orderKeysPreserved: true,
		unauthorizedOrderKeyProtected: true,
	};
}
