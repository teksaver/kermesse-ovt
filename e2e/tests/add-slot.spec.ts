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

  /* Explicitly activate the modification tab — default tab depends on kermesse status. */
  const modBtn = page.locator('[data-tab="modification"]:visible').first();
  await expect(modBtn).toBeVisible();
  await modBtn.click();
  await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });
}

/** Locator for the Jeux E2E stand section — re-evaluate after navigation. */
function jeuxSection(page: Page) {
  return page.locator('[id^="slots-stand-"]').filter({
    has: page.getByText(STAND_NAME),
  }).first();
}

async function addSlotToJeux(page: Page): Promise<void> {
  const section = jeuxSection(page);

  /* Click "Ajouter un créneau" button inside that section */
  const addBtn = section.getByRole('button', { name: 'Ajouter un créneau' });
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

    /* Flash success message confirms the PRG round-trip */
    await expect(page.locator('.form-success')).toContainText('Créneau ajouté avec succès.');

    /* The new slot times are visible inside the Jeux stand section (not page-wide) */
    const section = jeuxSection(page);
    await expect(section.getByText(NEW_SLOT_START).first()).toBeVisible();
    await expect(section.getByText(NEW_SLOT_END).first()).toBeVisible();

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

    /* Scope to the Jeux section to isolate from other stands' slots */
    const section = jeuxSection(page);
    await expect(section.getByText(NEW_SLOT_START).first()).toBeVisible();
    await expect(section.getByText(NEW_SLOT_END).first()).toBeVisible();

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
    const publicJeuxGroup = page.locator('.stand-group').filter({
      has: page.locator('.stand-group__name', { hasText: STAND_NAME }),
    });
    await expect(publicJeuxGroup.locator('.stand-group__name')).toBeVisible();
    await expect(publicJeuxGroup.getByText(NEW_SLOT_START).first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
