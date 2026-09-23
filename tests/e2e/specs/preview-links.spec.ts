import { type Browser, type Page, expect, test } from '@playwright/test';

/**
 * Full-stack journey for the one thing only a browser can prove: an editor
 * mints a preview link from the block-editor sidebar, an anonymous visitor
 * loads that link and sees the draft, and once the editor revokes it the same
 * visitor is turned away with a friendly notice.
 *
 * The narrow rules behind each step (token hashing, distinct-viewer caps,
 * revocation-beats-expiry, bot handling) are already covered by the PHP unit
 * and integration suites, so they are deliberately not re-tested here.
 */

// A distinctive marker we can assert on in the anonymously rendered draft.
const BODY_MARKER = 'Live preview body marker 4f9a2c';

// A plain desktop UA so the gate's bot heuristic treats the anonymous visitor
// as a human rather than serving the contentless stub.
const HUMAN_USER_AGENT =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

/** Expand a collapsible control (settings toggle, panel) if present and not already open. */
async function ensureExpanded( control: ReturnType<Page['getByRole']>, attribute: string ): Promise<void> {
	if ( ( await control.count() ) === 0 ) {
		return;
	}
	if ( ( await control.first().getAttribute( attribute ) ) === 'false' ) {
		await control.first().click();
	}
}

/**
 * Turn off the "Welcome to the editor" guide, which renders a focus-trapping
 * overlay a beat after the editor mounts. Flipping the preference unmounts it
 * even if it is already open; the Close button is a fallback for older cores.
 */
async function dismissWelcomeGuide( page: Page ): Promise<void> {
	await page.evaluate( () => {
		const preferences = ( window as unknown as { wp?: { data?: { dispatch?: ( store: string ) => { set?: ( scope: string, name: string, value: boolean ) => void } } } } ).wp?.data?.dispatch?.( 'core/preferences' );
		preferences?.set?.( 'core/edit-post', 'welcomeGuide', false );
		preferences?.set?.( 'core', 'welcomeGuide', false );
	} );

	const close = page.getByRole( 'dialog', { name: /Welcome to/i } ).getByRole( 'button', { name: 'Close' } );
	if ( await close.isVisible().catch( () => false ) ) {
		await close.click();
	}
}

/** Open a fresh, logged-out browser context and read the given preview URL as a visitor would. */
async function visitAsAnonymous( browser: Browser, url: string ): Promise<Page> {
	const context = await browser.newContext( { storageState: undefined, userAgent: HUMAN_USER_AGENT } );
	const page = await context.newPage();
	await page.goto( url );
	return page;
}

test.describe( 'Preview links', () => {
	// Uses the editor session captured by setup.ts.
	test.use( { storageState: '.playwright/state.json' } );

	test( 'an editor mints, shares, and revokes a preview link', async ( { page, browser } ) => {
		// The block editor loads at 30s under a cold container; give it headroom.
		test.setTimeout( 90000 );

		// --- Draft a post in the block editor -------------------------------
		await page.goto( './wp-admin/post-new.php' );

		// The post title and body live inside the editor-canvas iframe; the
		// sidebar, panels, and modals stay in the main document.
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas.getByRole( 'textbox', { name: 'Add title' } ).fill( 'Live preview E2E draft' );

		// With the editor now interactive, suppress the welcome guide before it
		// can overlay the sidebar we are about to drive.
		await dismissWelcomeGuide( page );

		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( BODY_MARKER );

		// Saving the draft gives the post an ID (enabling the panel buttons).
		// Use the keyboard shortcut rather than the toolbar "Save draft" button,
		// whose enabled state races with Gutenberg's own autosave.
		await page.keyboard.press( 'ControlOrMeta+s' );
		await page.waitForURL( /[?&]post=\d+/u );

		// --- The Share a Draft sidebar panel --------------------------------
		// Editing the paragraph switched the sidebar to the Block tab; the plugin
		// panel lives under the document (Post) tab. Match on the tab's visible
		// text rather than its computed accessible name, which WP leaves empty.
		await page.locator( '[role="tab"]' ).filter( { hasText: 'Post' } ).click();
		await ensureExpanded( page.getByRole( 'button', { name: 'Settings', exact: true } ), 'aria-pressed' );
		await ensureExpanded( page.getByRole( 'button', { name: 'Share a Draft' } ), 'aria-expanded' );

		const generateButton = page.getByRole( 'button', { name: 'Generate preview link' } );
		const manageButton = page.getByRole( 'button', { name: 'Manage preview links' } );
		await expect( generateButton ).toBeVisible();
		await expect( manageButton ).toBeVisible();

		// --- Generate a link ------------------------------------------------
		await generateButton.click();
		const generateModal = page.getByRole( 'dialog', { name: 'Generate preview link' } );
		await expect( generateModal ).toBeVisible();

		await generateModal.getByRole( 'button', { name: 'Generate link' } ).click();

		// The minted URL is surfaced in a read-only field once the request lands.
		const linkField = generateModal.getByRole( 'textbox', { name: 'Preview link' } );
		await expect( linkField ).toBeVisible();
		const previewUrl = await linkField.inputValue();
		expect( previewUrl ).toContain( 'shareadraft-token=' );

		// Copying again must reuse that link, not mint a second one.
		await generateModal.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( linkField ).toHaveValue( previewUrl );

		await generateModal.getByRole( 'button', { name: 'Close' } ).click();
		await expect( generateModal ).toBeHidden();

		// --- An anonymous visitor sees the draft ----------------------------
		const visitor = await visitAsAnonymous( browser, previewUrl );
		await expect( visitor.getByText( BODY_MARKER ) ).toBeVisible();
		await expect( visitor.getByText( 'This preview link has been revoked.' ) ).toHaveCount( 0 );

		// --- Manage and revoke the link -------------------------------------
		await manageButton.click();
		const manageModal = page.getByRole( 'dialog', { name: 'Manage preview links' } );
		await expect( manageModal ).toBeVisible();

		const revokeButton = manageModal.getByRole( 'button', { name: 'Revoke' } );
		await expect( revokeButton ).toBeVisible();

		// The modal drops the link from the list optimistically, before the
		// revoke request lands. Wait for that request to complete so the
		// revocation has taken effect server-side before the visitor tries the
		// link again. apiFetch sends the DELETE over the wire as a POST to the
		// id-bearing path, which is what distinguishes it from the earlier mint.
		const revoked = page.waitForResponse(
			( response ) =>
				/\/shareadraft\/v1\/preview-links\/[a-f0-9]{64}/u.test( response.url() ) &&
				[ 'DELETE', 'POST' ].includes( response.request().method() ) &&
				response.ok()
		);
		await revokeButton.click();
		await revoked;
		await expect( manageModal.getByText( 'No active preview links.' ) ).toBeVisible();

		// --- The same link now turns the visitor away -----------------------
		await visitor.goto( previewUrl );
		await expect( visitor.getByText( 'This preview link has been revoked.' ) ).toBeVisible();
		await expect( visitor.getByText( BODY_MARKER ) ).toHaveCount( 0 );

		await visitor.context().close();
	} );
} );
