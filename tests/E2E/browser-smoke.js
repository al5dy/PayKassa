async page => {
	const initialUrl = page.url();
	const baseUrl = initialUrl.replace( /\/\?.*$/, '' );
	const productMatch = initialUrl.match( /[?&]paykassa_e2e_product=(\d+)/ );
	const productId = productMatch ? productMatch[ 1 ] : '';
	const consoleErrors = [];
	page.on( 'console', message => {
		if ( message.type() === 'error' && message.location().url.startsWith( baseUrl || '' ) ) {
			consoleErrors.push( message.text() );
		}
	} );

	if ( ! baseUrl || ! productId ) {
		throw new Error( 'The initial browser URL must contain paykassa_e2e_product.' );
	}

	const assert = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( message );
		}
	};
	const readMerchantUrls = async () => {
		const response = await page.request.get( `${ baseUrl }/?paykassa_browser_merchant_urls=1` );
		assert( response.status() === 200, 'The disposable site must expose its test-only generated Merchant URLs.' );
		return response.json();
	};
	let merchantUrls = await readMerchantUrls();
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
	await page.goto( `${ merchantUrls.success_return_url }&order_id=${ classicOrderId }`, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/order-received/${ classicOrderId }/` ), 'Classic success return must reach the native order-received URL.' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Classic success return must render the WooCommerce thank-you page.' );

	const originSwitch = await page.request.post( `${ baseUrl }/?paykassa_browser_url_scenario=split`, {
		form: { token: 'paykassa-browser-fixture' },
		maxRedirects: 0,
	} );
	assert( originSwitch.status() === 204, 'The disposable site must switch to its split callback/browser-origin fixture.' );
	merchantUrls = await readMerchantUrls();
	assert(
		merchantUrls.callback_source === 'external_override' && merchantUrls.browser_return_source === 'wordpress_home',
		'Split-origin fixture must configure only the callback override and independently default browser returns.'
	);
	assert(
		merchantUrls.invoice_notification_url.startsWith( 'https://127.0.0.1:' ) &&
			merchantUrls.transaction_notification_url.startsWith( 'https://127.0.0.1:' ),
		'Split-origin configuration must use the independent callback base for both server notifications.'
	);
	assert(
		merchantUrls.success_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_return` &&
			merchantUrls.failure_return_url === `${ baseUrl }/?wc-api=wc_gateway_paykassa_cancel`,
		'Split-origin configuration must default browser returns independently to the canonical checkout origin.'
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
	await page.goto( `${ merchantUrls.success_return_url }&order_id=${ blocksOrderId }`, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/order-received/${ blocksOrderId }/` ), 'Blocks success return must reach the native order-received URL.' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Blocks success return must render the WooCommerce thank-you page.' );

	await addProduct();
	await page.goto( `${ baseUrl }/blocks-checkout/`, { waitUntil: 'networkidle' } );
	await page.getByRole( 'radio', { name: 'Cryptocurrency (PayKassa)' } ).waitFor();
	await waitForHostedRedirect( page.getByRole( 'button', { name: 'Place Order' } ) );
	const failedOrderId = orderIdFromHostedUrl();
	await postNotification( merchantUrls.transaction_notification_url, failedOrderId, `browser-transaction-pending-${ failedOrderId }-0123456789abcdef` );
	await page.goto( `${ merchantUrls.failure_return_url }&order_id=${ failedOrderId }`, { waitUntil: 'networkidle' } );
	assert( page.url().includes( `/blocks-checkout/order-pay/${ failedOrderId }/` ), 'Failure return must reach the native retry-payment URL.' );
	assert( await page.getByText( 'The PayKassa payment was not completed.' ).isVisible(), 'Failure return must show a generic retry notice.' );
	assert( await page.getByRole( 'button', { name: 'Pay for order' } ).isVisible(), 'Failure return must leave the unpaid order retryable.' );

	const invoiceGet = await page.request.get( merchantUrls.invoice_notification_url, { maxRedirects: 0 } );
	const invoiceMissingHash = await page.request.post( merchantUrls.invoice_notification_url, { form: {}, maxRedirects: 0 } );
	const transactionMissingHash = await page.request.post( merchantUrls.transaction_notification_url, { form: {}, maxRedirects: 0 } );
	const returnPost = await page.request.post( `${ merchantUrls.success_return_url }&order_id=${ failedOrderId }`, { form: {}, maxRedirects: 0 } );
	const unknownReturn = await page.request.get( `${ merchantUrls.success_return_url }&order_id=999999999`, { maxRedirects: 0 } );
	assert( invoiceGet.status() === 405, 'Invoice notification must be POST-only.' );
	assert( invoiceMissingHash.status() === 400, 'Invoice notification without private_hash must fail closed.' );
	assert( transactionMissingHash.status() === 400, 'Transaction notification without private_hash must fail closed.' );
	assert( returnPost.status() === 405, 'Browser return must be GET-only.' );
	assert( unknownReturn.status() === 302, 'Unknown browser return must use a safe generic redirect.' );
	assert( ! ( unknownReturn.headers().location || '' ).includes( '999999999' ), 'Unknown browser return must not disclose an order-specific URL.' );

	assert( consoleErrors.length === 0, 'Store-owned pages must have no browser console errors.' );

	return {
		classicOrderId,
		blocksOrderId,
		failedOrderId,
		invoiceFirstThenTransaction: true,
		transactionFirstThenInvoice: true,
		samePublicOriginSessionPreserved: true,
		splitOriginSessionPreserved: true,
	};
}
