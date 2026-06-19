/**
 * Shared fixtures and constants for E2E specs.
 */
import { test as base, Page } from '@playwright/test';
import * as path from 'path';

export const AUTH_DIR       = path.join(__dirname, '..', '.auth');
export const KERMESSE_SLUG  = 'kermesse-e2e-test';

/*
 * The app's site_url() emits http://localhost:PORT URLs (the host-facing address).
 * Inside the Playwright Docker container, localhost is NOT the app host — all links,
 * form actions, and redirects that contain localhost:PORT must be rewritten to the
 * internal Docker service name (APP_URL = http://app).
 *
 * page.route() intercepts direct navigation requests (link clicks, form submissions,
 * goto() calls) before Chromium connects to the network. Server-side 3xx redirects
 * to localhost are handled separately in global-setup.ts via try/catch.
 */
const LOCALHOST_RE = /^http:\/\/localhost(:\d+)?/;
const APP_URL      = process.env.APP_URL ?? 'http://app';

/** Extended test fixture: every page rewrites localhost:PORT URLs to the internal service URL. */
export const test = base.extend({
  page: async ({ page }, use) => {
    await page.route(LOCALHOST_RE, async (route) => {
      const rewritten = route.request().url().replace(LOCALHOST_RE, APP_URL);
      await route.continue({ url: rewritten });
    });
    await use(page);
  },
});

/** Loads a saved session state for the given role. */
export function storageStateFor(role: 'owner' | 'admin' | 'benevole'): string {
  return path.join(AUTH_DIR, `${role}.json`);
}

/** Navigate to the dashboard then click the given tab button by its accessible label. */
export async function openTab(page: Page, kermesseUrl: string, tabLabel: string): Promise<void> {
  await page.goto(kermesseUrl);
  await page.waitForLoadState('networkidle');

  const tabBtn = page.getByRole('button', { name: tabLabel, exact: true });
  if (await tabBtn.isVisible()) {
    await tabBtn.click();
    await page.waitForLoadState('networkidle');
  }
}

/** Collect all browser-level JS errors from a page; used in afterEach assertions. */
export function watchConsoleErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (err) => errors.push(err.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      /* CI4 Debug Bar makes XHR to http://localhost:PORT/?debugbar_time=... from inside
       * Docker, which fails with a CORS error before the route handler can intercept it.
       * This is dev tooling noise, not an application error — filter it out. */
      if (text.includes('debugbar_time')) return;
      errors.push(text);
    }
  });
  return errors;
}
