/**
 * Global Playwright setup: authenticates three roles and saves session state.
 *
 * Runs once before all specs. Tokens must already be seeded (via e2e.sh / e2e-setup.sql).
 * Each magic-link token is single-use and is consumed here; tests reuse saved sessions.
 *
 * Redirect handling: the app's site_url() generates http://localhost:8080/ (the host-facing
 * URL) which is inaccessible from inside Docker. The 302 response already sets the session
 * cookie in the browser before Chromium tries to follow the redirect, so we catch the
 * ERR_CONNECTION_REFUSED and re-navigate to the internal base URL to land on the home page.
 */
import { chromium, FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const AUTH_DIR = path.join(__dirname, '.auth');

/* Docker: Chromium runs as root and requires --no-sandbox. */
const LAUNCH_ARGS = ['--no-sandbox', '--disable-setuid-sandbox'];

async function authenticate(
  baseURL: string,
  rawToken: string,
  stateFile: string,
): Promise<void> {
  const browser = await chromium.launch({ args: LAUNCH_ARGS });
  const context = await browser.newContext();
  const page    = await context.newPage();

  /*
   * Navigate to the magic-link URL. The app validates the token, sets the session cookie
   * in the 302 response, then redirects to http://localhost:8080/ (inaccessible inside
   * Docker). We swallow ERR_CONNECTION_REFUSED / ERR_FAILED — the cookie is already set.
   *
   * Important: after the failed redirect Chromium is still navigating to chrome-error://.
   * Open a NEW page from the same context (cookies are shared) to avoid "interrupted by
   * another navigation" when we verify the session.
   */
  try {
    await page.goto(`${baseURL}/auth/magic-link/${rawToken}`);
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : String(e);
    if (!msg.includes('ERR_CONNECTION_REFUSED') && !msg.includes('ERR_FAILED') && !msg.includes('net::ERR_')) {
      throw e;
    }
    /* redirect to localhost failed — expected inside Docker, session cookie already set */
  }

  /*
   * Use a fresh page so we avoid racing with the chrome-error:// navigation that Chromium
   * initiates after the failed redirect. Cookies are shared within the context.
   */
  const verifyPage = await context.newPage();
  await verifyPage.goto(baseURL, { waitUntil: 'networkidle' });

  const url = verifyPage.url();
  if (url.includes('magic_link_invalid') || url.includes('auth/login') || url.includes('auth/magic-link')) {
    throw new Error(
      `Authentication failed for token ${rawToken} — landed on ${url}. ` +
      'Re-run e2e.sh to reseed the fixtures.',
    );
  }

  await context.storageState({ path: stateFile });
  await browser.close();
}

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = config.projects[0].use.baseURL ?? 'http://app';

  fs.mkdirSync(AUTH_DIR, { recursive: true });

  await authenticate(baseURL, 'e2e-magic-owner-01',    path.join(AUTH_DIR, 'owner.json'));
  await authenticate(baseURL, 'e2e-magic-admin-01',    path.join(AUTH_DIR, 'admin.json'));
  await authenticate(baseURL, 'e2e-magic-benevole-01', path.join(AUTH_DIR, 'benevole.json'));
}
