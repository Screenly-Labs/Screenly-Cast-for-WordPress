import { defineConfig, devices } from '@playwright/test'

/**
 * Browser tests for the signage render.
 *
 * These run against the real WordPress that wp-env serves, with a fixture corpus
 * seeded by tests/e2e/seed.ts. The suite is deliberately assertion-led rather than
 * pixel-baseline-led: font rasterisation differs between platforms, so committed
 * screenshot baselines would flake between a developer's machine and CI while
 * telling us little. Instead every render is asserted structurally, and the images
 * are published as an artifact plus a contact sheet for a human to look at.
 */
export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './test-results',

  // One shared WordPress instance, so tests must not race each other.
  fullyParallel: false,
  workers: 1,

  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 1 : 0,

  globalTeardown: './tests/e2e/contact-sheet.ts',

  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],

  use: {
    baseURL: process.env['SRLY_BASE_URL'] ?? 'http://localhost:8888',
    // Pin the scale factor so screenshot dimensions match the stated resolution.
    deviceScaleFactor: 1,
    screenshot: 'off',
    trace: process.env['CI'] ? 'retain-on-failure' : 'off'
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
})
