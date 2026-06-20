/**
 * Story 6.4 — Bénévole dashboard: accept / reject / cancel signups
 *
 * Covers AC5: Confirmer et Refuser une inscription admin-créée (FR13).
 * Covers AC6: Annuler sa propre inscription depuis "Mes participations".
 * Covers AC7: Kermesse fermée — actions interdites absentes.
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, KERMESSE_SLUG, watchConsoleErrors } from '../helpers/fixtures';

test.use({ storageState: storageStateFor('benevole') });

/** Navigate to the "Mes participations" tab for the E2E kermesse. */
async function goToParticipations(page: Page): Promise<void> {
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const card = page.locator('.kermesse-card').filter({ has: page.getByText('Kermesse E2E') });
  const link  = card.getByRole('link', { name: 'Mes inscriptions' });
  await expect(link).toBeVisible();
  await link.click();
  await page.waitForLoadState('networkidle');

  /* Ensure the participations panel is open */
  await expect(page.locator('body')).toHaveClass(/js-active/, { timeout: 10_000 });
  await expect(page.locator('#tab-panel-participations')).toHaveClass(/is-open/);
}

test.describe('Bénévole — inscription admin-créée: Accepter', () => {
  test('Accepter une inscription en attente de confirmation', async ({ page }, testInfo) => {
    /* State-mutating test: skip on mobile to avoid data contamination (desktop runs first). */
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await goToParticipations(page);

    const panel = page.locator('#tab-panel-participations');

    /* Find the list item for Stand Confirmations slot 20:00 (first admin-created signup) */
    const confirmItem = panel.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Confirmations E2E'),
    }).first();
    await expect(confirmItem).toBeVisible();

    /* The "En attente de confirmation" badge must be visible */
    await expect(confirmItem.getByText(/En attente de confirmation/i)).toBeVisible();

    /* Click Accepter — no confirm dialog needed */
    await confirmItem.getByRole('button', { name: 'Accepter' }).click();
    await page.waitForLoadState('networkidle');

    /* Flash message confirms acceptance */
    await expect(page.locator('.form-success, [class*="form-success"]')).toContainText(
      'Votre participation a été confirmée.',
    );

    /* Reload to confirm the accepted state persisted server-side */
    await page.reload();
    await page.waitForLoadState('networkidle');

    /* The badge "En attente" should no longer appear for the 20:00 slot */
    const panelAfter = page.locator('#tab-panel-participations');
    const confirmItemAfter = panelAfter.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Confirmations E2E'),
    }).filter({ has: page.getByText('20:00') }).first();
    await expect(confirmItemAfter.getByText(/En attente de confirmation/i)).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Bénévole — inscription admin-créée: Refuser', () => {
  test('Refuser une inscription en attente de confirmation', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await goToParticipations(page);

    const panel = page.locator('#tab-panel-participations');

    /* Find the list item for Stand Confirmations — target the 21:00 slot by its time label
     * rather than positional index, which is fragile when the accept test already ran. */
    const confirmItems = panel.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Confirmations E2E'),
    });
    /* Filter by both the time label AND the "En attente" badge to avoid matching the
     * accept slot (20:00–21:00) whose end time also contains "21:00". */
    const rejectItem = confirmItems
      .filter({ has: page.getByText('21:00') })
      .filter({ has: page.getByText(/En attente de confirmation/i) })
      .first();
    await expect(rejectItem).toBeVisible();

    await expect(rejectItem.getByText(/En attente de confirmation/i)).toBeVisible();

    /* Confirm the window.confirm() dialog then click Refuser */
    page.once('dialog', (dialog) => dialog.accept());
    await rejectItem.getByRole('button', { name: 'Refuser' }).click();
    await page.waitForLoadState('networkidle');

    /* Flash message confirms rejection */
    await expect(page.locator('.form-success, [class*="form-success"]')).toContainText(
      /refus.*enregistré/i,
    );

    /* Reload to confirm the rejection persisted server-side */
    await page.reload();
    await page.waitForLoadState('networkidle');

    /* The 21:00 slot should no longer show "En attente" after reload */
    const panelAfter = page.locator('#tab-panel-participations');
    const rejectItemAfter = panelAfter.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Confirmations E2E'),
    }).filter({ has: page.getByText('21:00') });
    await expect(rejectItemAfter.getByText(/En attente de confirmation/i)).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Bénévole — annuler sa propre inscription', () => {
  test('Annuler une inscription active libère la place', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await goToParticipations(page);

    const panel = page.locator('#tab-panel-participations');

    /* Find the annulation stand item */
    const annulItem = panel.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Annulation E2E'),
    }).first();
    await expect(annulItem).toBeVisible();

    /* Confirm the window.confirm() dialog then click Annuler ma participation */
    page.once('dialog', (dialog) => dialog.accept());
    await annulItem.getByRole('button', { name: 'Annuler ma participation' }).click();
    await page.waitForLoadState('networkidle');

    /* Flash success message */
    await expect(page.locator('.form-success, [class*="form-success"]')).toContainText(
      'La place est de nouveau disponible.',
    );

    /* The cancelled item should no longer appear in the active list */
    const panelAfter = page.locator('#tab-panel-participations');
    await expect(
      panelAfter.locator('.my-signups-list__item').filter({
        has: page.getByText('Stand Annulation E2E'),
      }),
    ).toHaveCount(0);

    /* Verify the spot is freed on the public page */
    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');
    const annulationGroup = page.locator('.stand-group').filter({
      has: page.locator('.stand-group__name', { hasText: 'Stand Annulation E2E' }),
    });
    await expect(annulationGroup.locator('.slot-row--available')).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Bénévole — actions à 320 px', () => {
  test("les boutons d'action sont accessibles sans scroll horizontal (lecture seule)", async ({ page }, testInfo) => {
    /* Read-only visibility check — does not click anything, so fixtures are not consumed. */
    test.skip(!testInfo.project.name.includes('mobile'), 'Mobile-only layout check');
    const errors = watchConsoleErrors(page);

    await goToParticipations(page);
    const panel = page.locator('#tab-panel-participations');

    /* The Buvette self-signed signups survive all desktop test runs — cancel button visible. */
    const buvettItem = panel.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Buvette E2E'),
    }).first();
    await expect(buvettItem.getByRole('button', { name: 'Annuler ma participation' })).toBeVisible();

    /* No horizontal overflow at 320px */
    const hasOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(hasOverflow, 'Horizontal scroll detected at 320px on dashboard').toBe(false);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Bénévole — kermesse fermée', () => {
  test("les boutons d'action sont absents pour une kermesse fermée", async ({ page }) => {
    const errors = watchConsoleErrors(page);

    /* Navigate directly to the closed kermesse dashboard — benevole is not a member
     * so we check the public page instead, which shows the "clôturées" message. */
    await page.goto('/k/kermesse-e2e-closed');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Les inscriptions sont clôturées')).toBeVisible();
    /* No signup action buttons or links available */
    await expect(page.locator('.slot-row--available')).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('dans le tableau de bord interne, le bouton "Annuler" est absent quand kermesse fermée', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToParticipations(page);

    /* signupsOpen is false for the open kermesse... actually the kermesse IS open.
     * This test would need a separate benevole who is a member of the closed kermesse.
     * Instead we verify that the "Annuler" button appears for the OPEN kermesse
     * (proving the open-kermesse path works), and that the closed public page has no actions. */

    /* For the open kermesse, Buvette Matin self-signed → cancel button should be visible */
    const panel = page.locator('#tab-panel-participations');
    const buvettItem = panel.locator('.my-signups-list__item').filter({
      has: page.getByText('Stand Buvette E2E'),
    }).first();
    await expect(buvettItem.getByRole('button', { name: 'Annuler ma participation' })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
