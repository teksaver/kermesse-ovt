/**
 * Smoke regression: "Mes participations" — Story 6.3 AC6, AC9, AC11
 *
 * Verifies that a bénévole sees all their active inscriptions after JS initialisation,
 * and that the list persists after a full page reload.
 *
 * Context: the JS dashboard code (dashboard.php inline <script>) adds 'js-active' to
 * <body> and adds 'is-open' to the matching .tab-panel. For benevole (no sidebar),
 * activateSection falls back to panels[0] which IS the participations panel.
 * Without this fallback the panel was hidden by `body.js-active .tab-panel:not(.is-open)`.
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, KERMESSE_SLUG, watchConsoleErrors } from '../helpers/fixtures';

test.use({ storageState: storageStateFor('benevole') });

const STAND_MATIN = 'Stand Buvette E2E';

async function goToDashboard(page: Page): Promise<string> {
  /* Navigate to the connected home, then follow the benevole "Mes inscriptions" link. */
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  /* The kermesse name is a <span>, not an <a>; find the link inside the matching card. */
  const card = page.locator('.kermesse-card').filter({ has: page.getByText('Kermesse E2E') });
  const link  = card.getByRole('link', { name: 'Mes inscriptions' });
  await expect(link).toBeVisible();
  await link.click();
  await page.waitForLoadState('networkidle');
  return page.url();
}

test.describe('Mes participations — régression JS', () => {
  test('le bénévole voit ses deux inscriptions actives après initialisation JS', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page);

    /* JS marks body.js-active; the participations panel should receive is-open */
    await expect(page.locator('body')).toHaveClass(/js-active/, { timeout: 10_000 });

    const panel = page.locator('#tab-panel-participations');
    await expect(panel).toHaveClass(/is-open/);

    /* Both active signups must be visible */
    await expect(page.getByText(STAND_MATIN).first()).toBeVisible();
    await expect(page.getByText('Stand Buvette E2E').nth(1)).toBeVisible();

    /* Dates and times from e2e-setup.sql */
    await expect(page.getByText('09:00').first()).toBeVisible();
    await expect(page.getByText('14:00').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('la liste reste visible après rechargement complet', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page);

    await page.reload();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).toHaveClass(/js-active/, { timeout: 10_000 });

    const panel = page.locator('#tab-panel-participations');
    await expect(panel).toHaveClass(/is-open/);

    /* Both active inscriptions must remain visible after a full reload. */
    await expect(page.getByText(STAND_MATIN).first()).toBeVisible();
    await expect(page.getByText('14:00').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('mode mobile 320 px — le panneau unique reçoit is-open sans sidebar', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page);

    /* No sidebar rendered for benevole; activateSection(panels[0]) covers the single panel */
    await expect(page.locator('body')).toHaveClass(/js-active/, { timeout: 10_000 });
    const panel = page.locator('#tab-panel-participations');
    await expect(panel).toHaveClass(/is-open/);

    await expect(page.getByText(STAND_MATIN).first()).toBeVisible();
    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
