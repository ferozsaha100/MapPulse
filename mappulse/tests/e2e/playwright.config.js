// End-to-end run of the map builder in a real browser.
// MAPPULSE_E2E_URL, MAPPULSE_E2E_USER and MAPPULSE_E2E_PASS point it at a WordPress with MapPulse active.
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	timeout: 420000,
	expect: { timeout: 30000 },
	retries: 0,
	workers: 1,
	reporter: [ [ 'list' ] ],
	outputDir: 'results',
	use: {
		baseURL: process.env.MAPPULSE_E2E_URL || 'http://localhost:8888',
		viewport: { width: 1440, height: 900 },
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		channel: process.env.MAPPULSE_E2E_CHANNEL || undefined,
	},
} );
