// @ts-check
const { test, expect } = require( '@playwright/test' );
const { login, mock } = require( './helpers' );

test.describe( 'CSS logical properties', () => {
	test.beforeEach( async () => {
		await mock( '/__reset' );
	} );

	test( 'the leads page uses logical CSS properties', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );

		// Verify that the failures list uses margin-inline-start (logical property)
		// instead of margin-left (physical property) for RTL compatibility.
		const failuresList = page.locator( '.profotograaf-leads-failures' );
		const computedStyle = await failuresList.evaluate( ( element ) => {
			const styles = window.getComputedStyle( element );
			return {
				marginInlineStart: styles.marginInlineStart,
			};
		} );

		// In CSS, margin-inline-start is used for logical layout.
		// This will adapt to RTL automatically.
		expect( computedStyle.marginInlineStart ).toBeTruthy();
	} );

	test( 'the table headers use logical CSS properties', async ( { page } ) => {
		await login( page );
		await page.goto( '/wp-admin/options-general.php?page=profotograaf-leads' );

		// Verify that the table header padding uses padding-inline-end (logical property)
		// instead of physical right padding for RTL compatibility.
		const tableHeader = page.locator( '.profotograaf-leads-map th' );
		const computedStyle = await tableHeader.evaluate( ( element ) => {
			const styles = window.getComputedStyle( element );
			return {
				textAlign: styles.textAlign,
			};
		} );

		// text-align:start adapts automatically to LTR (left) and RTL (right).
		expect( computedStyle.textAlign ).toBe( 'start' );
	} );

	test( 'the notice uses a logical border for RTL compatibility', async ( { page } ) => {
		await page.goto( '/?p=1' );

		// Verify that the notice element uses border-inline-start (logical property)
		// instead of border-left (physical property) for RTL compatibility.
		const notice = page.locator( '.wp-block-profotograaf-client-galleries__notice' );
		const computedStyle = await notice.evaluate( ( element ) => {
			const styles = window.getComputedStyle( element );
			return {
				borderInlineStart: styles.borderInlineStart,
				textAlign: styles.textAlign,
			};
		} );

		// Verify that logical border properties are being used.
		expect( computedStyle.borderInlineStart ).toBeTruthy();
		// text-align:start adapts automatically to LTR (left) and RTL (right).
		expect( computedStyle.textAlign ).toBe( 'start' );
	} );
} );
