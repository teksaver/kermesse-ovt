/**
 * Story 6.4 — Public volunteer page: lifecycle states & PII privacy
 *
 * Covers AC1: page publique dans les états preparation / open / closed,
 * affichage des informations appropriées, et absence stricte de PII.
 */
import { expect } from '@playwright/test';
import { test, KERMESSE_SLUG, watchConsoleErrors } from '../helpers/fixtures';

/* Anonymous visitor — no auth state */

test.describe('Page publique — état preparation', () => {
  test('affiche le message "inscriptions pas encore ouvertes"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/k/kermesse-e2e-prep');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Les inscriptions ne sont pas encore ouvertes')).toBeVisible();
    /* No signup links should exist */
    await expect(page.getByRole('link', { name: "M'inscrire" })).toHaveCount(0);

    /* PII must not appear in the full HTML (including attributes) */
    const html = await page.content();
    expect(html, 'Email PII exposed in prep state').not.toContain('@e2e.test');
    expect(html, 'Magic-link token exposed in prep state').not.toMatch(/auth\/magic-link/);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Page publique — état open', () => {
  test('affiche les stands et créneaux disponibles', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');

    /* Stand groups are visible */
    await expect(page.locator('.stand-group').first()).toBeVisible();
    await expect(page.locator('.stand-group__name').first()).toBeVisible();

    /* At least one available slot (M'inscrire link exists) */
    await expect(page.locator('.slot-row--available').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('affiche le créneau complet avec le badge "Complet"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');

    const complet = page.locator('.stand-group').filter({
      has: page.locator('.stand-group__name', { hasText: 'Stand Complet E2E' }),
    });
    await expect(complet).toBeVisible();
    /* The "Complet" label or status must appear — use .first() to avoid strict-mode
     * violation: stand name + mobile badge + desktop badge all match /Complet/. */
    await expect(complet.getByText(/Complet/).first()).toBeVisible();
    /* No signup link inside the full stand */
    await expect(complet.locator('.slot-row--available')).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test("n'expose aucune donnée personnelle (PII) dans le DOM", async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');

    /* Use page.content() to scan the full HTML including hidden attributes, not just visible text */
    const html = await page.content();

    /* No e2e.test emails should appear on the public page */
    expect(html, 'Email PII exposed').not.toContain('@e2e.test');

    /* No magic-link tokens or paths */
    expect(html, 'Magic-link token exposed').not.toMatch(/auth\/magic-link/);

    /* No raw phone numbers seeded for these users (all '' so just sanity check) */
    expect(html, 'Phone number pattern detected').not.toMatch(/\b0[67]\d{8}\b/);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('mode mobile 320 px — stands et créneaux accessibles sans scroll horizontal', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.stand-group').first()).toBeVisible();
    await expect(page.locator('.slot-row--available').first()).toBeVisible();

    /* No horizontal overflow: scrollWidth should equal clientWidth */
    const hasOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(hasOverflow, 'Horizontal scroll detected at 320px').toBe(false);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Page publique — état closed', () => {
  test('affiche le message "inscriptions clôturées"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/k/kermesse-e2e-closed');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Les inscriptions sont clôturées')).toBeVisible();
    /* Slot list must not be shown */
    await expect(page.locator('.stand-group')).toHaveCount(0);
    /* No signup links */
    await expect(page.locator('.slot-row--available')).toHaveCount(0);

    /* PII must not appear in the full HTML (including attributes) */
    const html = await page.content();
    expect(html, 'Email PII exposed in closed state').not.toContain('@e2e.test');
    expect(html, 'Magic-link token exposed in closed state').not.toMatch(/auth\/magic-link/);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
