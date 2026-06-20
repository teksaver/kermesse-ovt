/**
 * Story 6.4 — Profil bénévole: consulter et modifier ses coordonnées
 *
 * Covers AC8: profil visible pré-rempli, mise à jour persistée,
 * erreurs de validation conservent les valeurs saisies.
 */
import { expect } from '@playwright/test';
import { test, storageStateFor, watchConsoleErrors } from '../helpers/fixtures';

test.use({ storageState: storageStateFor('benevole') });

test.describe('Profil — consultation', () => {
  test('la page profil affiche les champs pré-remplis', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/profile');
    await page.waitForLoadState('networkidle');

    /* Page title */
    await expect(page.getByRole('heading', { name: 'Mon profil' })).toBeVisible();

    /* Fields pre-filled with benevole@e2e.test data */
    await expect(page.locator('#first_name')).toHaveValue('Dana');
    await expect(page.locator('#last_name')).toHaveValue('Benev');
    await expect(page.locator('#email')).toHaveValue('benevole@e2e.test');

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Profil — mise à jour', () => {
  test('la mise à jour du numéro de téléphone est persistée', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/profile');
    await page.waitForLoadState('networkidle');

    /* Update phone number (safe: other tests don't depend on this field) */
    await page.locator('#phone').fill('0612345678');

    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForLoadState('networkidle');

    /* PRG redirect back to /profile with success flash */
    await expect(page).toHaveURL(/\/profile/);
    await expect(page.locator('.form-success')).toContainText(
      'Votre profil a été mis à jour avec succès.',
    );

    /* Field persisted after redirect */
    await expect(page.locator('#phone')).toHaveValue('0612345678');

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('les erreurs de validation conservent les valeurs saisies', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/profile');
    await page.waitForLoadState('networkidle');

    /* Clear required first_name to trigger a server-side validation error.
     * We must bypass browser HTML5 required validation to hit the server.
     * Use JS to remove the required attribute before submission. */
    await page.evaluate(() => {
      const input = document.querySelector<HTMLInputElement>('#first_name');
      if (input) {
        input.removeAttribute('required');
        input.value = '';
      }
    });

    /* Type something into last_name so we can verify it's preserved */
    await page.locator('#last_name').fill('BenvTest');

    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForLoadState('networkidle');

    /* On validation failure the page re-displays the form with entered values.
     * CI4 redirects back with withInput() so last_name should be preserved. */
    await expect(page).toHaveURL(/\/profile/);

    /* No success message must appear */
    await expect(page.locator('.form-success')).toHaveCount(0);

    /* last_name value should be preserved via old() helper */
    await expect(page.locator('#last_name')).toHaveValue('BenvTest');

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

test.describe('Profil — mobile 320 px', () => {
  test('le formulaire de profil est accessible sans scroll horizontal', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await page.goto('/profile');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#first_name')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Enregistrer les modifications' })).toBeVisible();

    const hasOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(hasOverflow, 'Horizontal scroll detected at 320px').toBe(false);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
