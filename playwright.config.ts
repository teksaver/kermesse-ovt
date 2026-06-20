import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.APP_URL ?? 'http://app';

export default defineConfig({
  testDir: './e2e/tests',
  globalSetup: './e2e/global-setup.ts',

  /* Zero retries — flakiness is a defect, not a retry target. */
  retries: 0,
  workers: 1,

  timeout: 30_000,
  expect: { timeout: 10_000 },

  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['junit', { outputFile: 'playwright-report/junit.xml' }],
    ['list'],
  ],

  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    ignoreHTTPSErrors: false,
    /* Required when Chromium runs as root inside a Docker container. */
    launchOptions: {
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
    },
  },

  projects: [
    {
      name: 'chromium-desktop',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
      },
    },
    {
      name: 'chromium-mobile',
      use: {
        ...devices['Pixel 5'],
        viewport: { width: 320, height: 812 },
      },
    },
  ],

  outputDir: 'test-results',
});
