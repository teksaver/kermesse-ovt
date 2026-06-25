/**
 * Smoke regression: "Vous" dans l'onglet Équipe — Story 6.3 AC7, AC9, AC11
 *
 * Verifies that when an admin views the Équipe tab:
 *   - their own row displays the "Vous" badge
 *   - the self-revoke button is absent on their row
 *   - another team member (gestionnaire) retains an authorized revoke action
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, watchConsoleErrors } from '../helpers/fixtures';

test.use({ storageState: storageStateFor('admin') });

async function openEquipeTab(page: Page): Promise<void> {
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  /* The kermesse name is a <span>, not an <a>; find the link inside the matching card. */
  const card     = page.locator('.kermesse-card').filter({ has: page.getByText('Kermesse E2E') });
  const adminLink = card.getByRole('link', { name: 'Administration' });
  await expect(adminLink).toBeVisible();
  await adminLink.click();
  await page.waitForLoadState('networkidle');

  /*
   * Navigate to the Équipe tab. On desktop (≥769px) the sidebar button is visible;
   * on mobile (<769px) it is hidden and the accordion header inside the panel is
   * used instead. Both share the same data-tab="equipe" attribute — :visible picks
   * whichever is shown for the current viewport.
   */
  const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
  await expect(equipeBtn).toBeVisible();
  await equipeBtn.click();
  await page.waitForLoadState('networkidle');

  /* Confirm the panel is open */
  await expect(page.locator('#tab-panel-equipe')).toHaveClass(/is-open/);
}

test.describe('Onglet Équipe — badge « Vous » et actions', () => {
  test('la ligne admin affiche le badge « Vous »', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page);

    /*
     * "Vous" badge: exact:true prevents matching "vous" inside modal strings
     * like "Êtes-vous sûr de vouloir supprimer…" which are in the DOM but hidden.
     */
    await expect(page.getByText('Vous', { exact: true })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('aucune auto-révocation sur la ligne courante', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page);

    /* Find the list item containing the current user's email */
    const selfRow = page.locator('.participants-list__item').filter({
      has: page.getByText('admin@e2e.test'),
    });
    await expect(selfRow).toBeVisible();

    /* The "Vous" badge confirms this is the self row */
    await expect(selfRow.getByText('Vous', { exact: true })).toBeVisible();

    /* The revoke button (🗑️) must NOT appear on the self row */
    await expect(selfRow.getByRole('button', { name: /révoquer/i })).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('le gestionnaire conserve son bouton de révocation', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page);

    /* Find the gestionnaire row by email */
    const gestionRow = page.locator('.participants-list__item').filter({
      has: page.getByText('gestionnaire@e2e.test'),
    });
    await expect(gestionRow).toBeVisible();

    /* "Vous" must NOT appear on the gestionnaire row */
    await expect(gestionRow.getByText('Vous')).toHaveCount(0);

    /* The revoke button must be present (aria-label "Révoquer le rôle") */
    await expect(gestionRow.getByRole('button', { name: /révoquer/i })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
