// Browser tests against a real WordPress + WooCommerce + Voucher Captain (see .github/workflows/plugin-smoke.yml).
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	timeout: 90000,
	expect: { timeout: 15000 },
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [ [ 'list' ] ],
	outputDir: 'test-results',
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080',
		trace: 'off',
		screenshot: 'only-on-failure',
		locale: 'en-US',
	},
} );
