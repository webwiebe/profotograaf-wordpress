import { defineConfig } from 'vitest/config';

// Unit tests for the block editor code. jsdom gives the components a DOM; the
// WordPress packages are replaced per test file (see blocks/test-support).
// The node:test files under scripts/ are gate tests and run with `node --test`.
export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'blocks/**/*.test.{ts,tsx}' ],
		coverage: {
			provider: 'v8',
			include: [ 'blocks/**/*.{ts,tsx}' ],
			exclude: [ 'blocks/**/*.test.{ts,tsx}', 'blocks/test-support/**', 'blocks/**/*.d.ts' ],
			reporter: [ 'text-summary', 'json', 'json-summary' ],
			reportsDirectory: 'coverage',
		},
	},
} );
