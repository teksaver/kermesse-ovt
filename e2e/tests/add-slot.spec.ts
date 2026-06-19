/**
 * Smoke regression: ajout de créneau sur kermesse ouverte — Story 6.3 AC8, AC9, AC11
 *
 * Verifies that an Owner can add a slot via the real UI form (CSRF + PRG flow),
 * that the slot appears in the dashboard and persists after reload,
 * and that it is visible on the public kermesse page.
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, KERMESSE_SLUG, watchConsoleErrors } from '../helpers/fixtures';

test.use({ storageState: storageStateFor('owner') });

const NEW_SLOT_START = '18:00';
const NEW_SLOT_END   = '20:00';
const NEW_SLOT_CAP   = '4';
const STAND_NAME     = 'Stand Jeux E2E';

async function openModificationTab(page: Page): Promise<void> {
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  /* The kermesse name is a <span>, not an <a>; find the link inside the matching card. */
  const card      = page.locator('.kermesse-card').filter({ has: page.getByText('Kermesse E2E') });
  const adminLink = card.getByRole('link', { name: 'Administration' });
  await expect(adminLink).toBeVisible();
  await adminLink.click();
  await page.waitForLoadState('networkidle');

  /* For owner, the first active tab is "Modification" — it opens by default */
  await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });
}

async function addSlotToJeux(page: Page): Promise<void> {
  /* Find the stand section for Stand Jeux E2E. Stand sections have id="slots-stand-{id}". */
  const jeuxSection = page.locator('[id^="slots-stand-"]').filter({
    has: page.getByText(STAND_NAME),
  }).first();

  /* Click "Ajouter un créneau" button inside that section */
  const addBtn = jeuxSection.getByRole('button', { name: 'Ajouter un créneau' });
  await expect(addBtn).toBeVisible();
  await addBtn.click();

  /*
   * The HTML <dialog> element gets the `open` attribute when opened via showModal().
   * Select the currently open add-slot dialog (not just the first in DOM, which may
   * be a different stand's dialog). Labels have no `for` attribute, so use name selector.
   */
  const dialog = page.locator('dialog[id^="modal-slot-add-"][open]');
  await expect(dialog).toBeVisible({ timeout: 5_000 });

  await dialog.locator('input[name="starts_at"]').fill(NEW_SLOT_START);
  await dialog.locator('input[name="ends_at"]').fill(NEW_SLOT_END);
  await dialog.locator('input[name="capacity"]').fill(NEW_SLOT_CAP);

  await dialog.getByRole('button', { name: 'Ajouter' }).click();

  /* PRG: wait for redirect back to the dashboard */
  await page.waitForLoadState('networkidle');
}

test.describe('Ajout de créneau sur kermesse ouverte', () => {
  test('le créneau apparaît dans le dashboard après PRG', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openModificationTab(page);
    await addSlotToJeux(page);

    /* After PRG, the modification tab is still active */
    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });

    /* The new slot times appear in the stand section */
    await expect(page.getByText(NEW_SLOT_START).first()).toBeVisible();
    await expect(page.getByText(NEW_SLOT_END).first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('le créneau persiste après rechargement complet du dashboard', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openModificationTab(page);
    await addSlotToJeux(page);

    /* Reload the page to confirm DB persistence */
    await page.reload();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });

    await expect(page.getByText(NEW_SLOT_START).first()).toBeVisible();
    await expect(page.getByText(NEW_SLOT_END).first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('le créneau apparaît sur la page publique /k/{slug}', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openModificationTab(page);
    await addSlotToJeux(page);

    /* Visit the public volunteer page */
    await page.goto(`/k/${KERMESSE_SLUG}`);
    await page.waitForLoadState('networkidle');

    /* Stand name appears in public content (.stand-group__name) and also in Debug Bar
     * SQL output — scope to the semantic element to avoid strict-mode violations. */
    await expect(page.locator('.stand-group__name').filter({ hasText: STAND_NAME })).toBeVisible();
    await expect(page.getByText(NEW_SLOT_START).first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
