/**
 * Story 6.5 — Parcours critiques des organisateurs (E2E)
 *
 * Covers:
 *   AC1  — Stand + créneau : Owner crée un stand et un créneau, les voit persistés.
 *   AC2  — Lifecycle kermesse : ouvrir depuis preparation, fermer depuis open.
 *   AC3  — Gestionnaire : onglets autorisés visibles, onglets non autorisés absents.
 *   AC4  — Admin : ajouter / annuler / déplacer / corriger une inscription.
 *   AC5  — Équipe : inviter un nouveau membre, relancer une invitation, révoquer un rôle.
 *   AC6  — Identification de l'utilisateur courant (badge « Vous ») et protection auto-révocation.
 *   AC7  — Protection Owner : aucun bouton de révocation sur la ligne Owner.
 *
 * Coverage strategy: PHPUnit already covers server-side invariants (403, capacity, duplicates,
 * overlaps). These E2E tests focus exclusively on browser-rendered interactions: <dialog> modals,
 * <details> accordions, JS-gated form buttons, and tab activation via JS.
 *
 * State-mutating tests are marked desktop-only (skip on mobile) to prevent data contamination
 * when both profiles run sequentially in a single worker.
 *
 * [P1] waitForLoadState('networkidle') replaced throughout with content-based assertions:
 *      after page.goto() — wait for a stable anchor element; after form submit — wait for the
 *      resulting flash or redirected-page element. This eliminates unreliable timing dependencies.
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, watchConsoleErrors } from '../helpers/fixtures';

const KERMESSE_NAME    = 'Kermesse E2E';
const LIFECYCLE_NAME   = 'Kermesse Lifecycle E2E';
const PREP_NAME        = 'Kermesse Préparation E2E';
const ORG_STAND_NAME   = 'Stand Organisateurs E2E';

// ---------------------------------------------------------------------------
// Navigation helpers
// ---------------------------------------------------------------------------

async function goToDashboard(page: Page, kermesseName: string): Promise<void> {
  await page.goto('/');
  // Wait for at least one kermesse card — confirms home page rendered.
  const card = page.locator('.kermesse-card').filter({ has: page.getByText(kermesseName) });
  await expect(card).toBeVisible();
  await card.getByRole('link', { name: 'Administration' }).click();
  // Wait for any tab button — signals dashboard HTML is fully rendered.
  await expect(page.locator('[data-tab]:visible').first()).toBeVisible();
}

async function openModificationTab(page: Page, kermesseName: string): Promise<void> {
  await goToDashboard(page, kermesseName);
  const panel = page.locator('#tab-panel-modification');
  const alreadyOpen = await panel.evaluate((el) => el.classList.contains('is-open'));
  if (!alreadyOpen) {
    const modBtn = page.locator('[data-tab="modification"]:visible').first();
    await expect(modBtn).toBeVisible();
    await modBtn.click();
  }
  await expect(panel).toHaveClass(/is-open/);
}

async function openInscritsTab(page: Page, kermesseName: string): Promise<void> {
  await goToDashboard(page, kermesseName);
  const panel = page.locator('#tab-panel-inscrits');
  // On mobile the navigation uses accordion toggles: clicking an already-open section
  // closes it. When the kermesse is open, 'inscrits' is the default tab and the panel
  // starts open — skip the click in that case to avoid toggling it shut.
  const alreadyOpen = await panel.evaluate((el) => el.classList.contains('is-open'));
  if (!alreadyOpen) {
    const inscritsBtn = page.locator('[data-tab="inscrits"]:visible').first();
    await expect(inscritsBtn).toBeVisible();
    await inscritsBtn.click();
  }
  await expect(panel).toHaveClass(/is-open/);
}

async function openEquipeTab(page: Page, kermesseName: string): Promise<void> {
  await goToDashboard(page, kermesseName);
  const panel = page.locator('#tab-panel-equipe');
  const alreadyOpen = await panel.evaluate((el) => el.classList.contains('is-open'));
  if (!alreadyOpen) {
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    await expect(equipeBtn).toBeVisible();
    await equipeBtn.click();
  }
  await expect(panel).toHaveClass(/is-open/);
}

// ---------------------------------------------------------------------------
// AC3 — Gestionnaire : restrictions de rôle (read-only, both viewports)
// ---------------------------------------------------------------------------

test.describe('Gestionnaire — restrictions de rôle (AC3)', () => {
  test.use({ storageState: storageStateFor('gestionnaire') });

  test('voit l\'onglet "Gestion des inscrits"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, KERMESSE_NAME);

    /* The "inscrits" tab button (sidebar on desktop, accordion header on mobile). */
    await expect(page.locator('[data-tab="inscrits"]:visible').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('voit l\'onglet "Mes participations"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, KERMESSE_NAME);

    await expect(page.locator('[data-tab="participations"]:visible').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('ne voit PAS l\'onglet "Modification"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, KERMESSE_NAME);

    /* Modification tab button must not be rendered at all (not just hidden). */
    await expect(page.locator('[data-tab="modification"]')).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('ne voit PAS l\'onglet "Équipe"', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, KERMESSE_NAME);

    await expect(page.locator('[data-tab="equipe"]')).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('le panneau "Gestion des inscrits" s\'ouvre et affiche les stands', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openInscritsTab(page, KERMESSE_NAME);

    /* At least one stand section must be visible. */
    const panel = page.locator('#tab-panel-inscrits');
    await expect(panel.locator('.participants-stand').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('mobile 320px — aucun scroll horizontal sur le dashboard gestionnaire', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, KERMESSE_NAME);

    await expect(page.locator('body')).toHaveClass(/js-active/);

    /* Round both sides to absorb sub-pixel rendering differences. */
    const hasOverflow = await page.evaluate(() =>
      Math.ceil(document.documentElement.scrollWidth) > Math.ceil(window.innerWidth),
    );
    expect(hasOverflow, 'Horizontal scroll detected at 320px on gestionnaire dashboard').toBe(false);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC7 — Protection Owner : aucun bouton de révocation sur la ligne Owner
// (read-only, both viewports)
// ---------------------------------------------------------------------------

test.describe('Équipe — protection de l\'Owner (AC7)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('la ligne Owner n\'a pas de bouton de révocation', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    /* Find the Owner group section — contains "Alice Owner". */
    const ownerSection = page.locator('.participants-list').filter({
      has: page.getByText('Alice Owner'),
    });
    await expect(ownerSection).toBeVisible();

    /* No revoke button on the owner row. */
    await expect(ownerSection.getByRole('button', { name: /révoquer/i })).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC6 — Identification de l'utilisateur courant et protection auto-révocation
// (read-only, both viewports)
//
// Note: The "Vous" badge is only rendered for non-owner roles (the view suppresses
// it for the 'owner' role by design). Tests use admin session so the badge is visible.
// Complements team.spec.ts smoke tests by placing these assertions in the
// organizer-dashboard context.
// ---------------------------------------------------------------------------

test.describe('Équipe — badge « Vous » et protection auto-révocation (AC6)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('l\'admin voit le badge « Vous » sur sa propre ligne', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    const adminRow = page.locator('.participants-list__item').filter({
      has: page.getByText('admin@e2e.test'),
    });
    await expect(adminRow).toBeVisible();
    await expect(adminRow.getByText('Vous', { exact: true })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('aucun bouton de révocation sur sa propre ligne', async ({ page }) => {
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    const adminRow = page.locator('.participants-list__item').filter({
      has: page.getByText('admin@e2e.test'),
    });
    await expect(adminRow).toBeVisible();
    await expect(adminRow.getByRole('button', { name: /révoquer/i })).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC2 — Lifecycle kermesse : ouvrir depuis preparation, fermer depuis open
// (state-mutating — desktop only, uses dedicated kermesse-e2e-lifecycle)
// ---------------------------------------------------------------------------

test.describe('Owner — cycle de vie de la kermesse (AC2)', () => {
  test.use({ storageState: storageStateFor('owner') });

  test('ouvrir une kermesse en préparation puis la fermer', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    /* Step 1: Open kermesse-e2e-lifecycle from 'preparation' → 'open'. */
    await openModificationTab(page, LIFECYCLE_NAME);

    const openBtn = page.getByRole('button', { name: 'Ouvrir les inscriptions' });
    await expect(openBtn).toBeVisible();
    await openBtn.click();

    /* Success flash confirms the transition (replaces networkidle wait). */
    await expect(page.locator('.form-success')).toContainText('La kermesse est ouverte.');

    /* Public volunteer page now shows available slots. */
    await page.goto('/k/kermesse-e2e-lifecycle');
    await expect(page.locator('.stand-group').first()).toBeVisible();
    await expect(page.locator('.slot-row--available').first()).toBeVisible();

    /* Step 2: Close the kermesse → 'closed'. */
    await openModificationTab(page, LIFECYCLE_NAME);

    const closeBtn = page.getByRole('button', { name: 'Fermer les inscriptions' });
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();

    await expect(page.locator('.form-success')).toContainText('La kermesse est fermée.');

    /* Public page now shows the closed message and no signup links. */
    await page.goto('/k/kermesse-e2e-lifecycle');
    await expect(page.getByText('Les inscriptions sont clôturées')).toBeVisible();
    await expect(page.locator('.slot-row--available')).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC1 — Stand + créneau : Owner crée un stand et un créneau (state-mutating — desktop only)
// Uses kermesse-e2e-prep (stays in preparation throughout the test suite).
// ---------------------------------------------------------------------------

test.describe('Owner — créer un stand et un créneau (AC1)', () => {
  test.use({ storageState: storageStateFor('owner') });

  test('Owner crée un stand puis un créneau, les voit persistés après rechargement', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await goToDashboard(page, PREP_NAME);

    /* Owner's default tab on a preparation kermesse is "Modification". */
    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/);

    /* Open the "Ajouter un stand" dialog. */
    await page.getByRole('button', { name: 'Ajouter un stand' }).click();
    const addStandDialog = page.locator('#modal-stand-add');
    await expect(addStandDialog).toBeVisible({ timeout: 5_000 });

    await addStandDialog.locator('#stand-name').fill('Stand Création Test E2E');
    await addStandDialog.getByRole('button', { name: 'Créer le stand' }).click();

    /* PRG: flash confirms the stand was created. */
    await expect(page.locator('.form-success')).toContainText(/ajouté/i);

    /* The new stand section must appear in the modification panel. */
    const newStandSection = page.locator('[id^="slots-stand-"]').filter({
      has: page.getByText('Stand Création Test E2E'),
    });
    await expect(newStandSection).toBeVisible();

    /* Now add a slot to the new stand. */
    const addSlotBtn = newStandSection.getByRole('button', { name: 'Ajouter un créneau' });
    await expect(addSlotBtn).toBeVisible();
    await addSlotBtn.click();

    const addSlotDialog = page.locator('dialog[id^="modal-slot-add-"][open]');
    await expect(addSlotDialog).toBeVisible({ timeout: 5_000 });

    await addSlotDialog.locator('input[name="starts_at"]').fill('10:00');
    await addSlotDialog.locator('input[name="ends_at"]').fill('12:00');
    await addSlotDialog.locator('input[name="capacity"]').fill('3');
    await addSlotDialog.getByRole('button', { name: 'Ajouter' }).click();

    /* PRG: flash confirms the slot was created. */
    await expect(page.locator('.form-success')).toContainText('Créneau ajouté avec succès.');

    /* Reload confirms persistence. */
    await page.reload();
    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/);

    const reloadedSection = page.locator('[id^="slots-stand-"]').filter({
      has: page.getByText('Stand Création Test E2E'),
    });
    await expect(reloadedSection).toBeVisible();
    await expect(reloadedSection.getByText('10:00').first()).toBeVisible();
    await expect(reloadedSection.getByText('12:00').first()).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC4 — Admin : ajouter une inscription via modale <dialog>
// (state-mutating — desktop only)
// ---------------------------------------------------------------------------

test.describe('Admin — ajouter une inscription via modale (AC4)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('l\'admin ajoute un bénévole et le voit apparaître dans la liste', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openInscritsTab(page, KERMESSE_NAME);

    const panel = page.locator('#tab-panel-inscrits');

    /* Find the "Stand Organisateurs E2E" section in the inscrits panel.
     * hasText (not has+getByText) is required: has+page.getByText evaluates globally,
     * making all .participants-stand elements match if the text exists anywhere on the page. */
    const orgStand = panel.locator('.participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    await expect(orgStand).toBeVisible();

    /* Find the add-slot at 17:00 (slot starting at 17:00, ending at 18:00).
     * Regex matching both boundary times uniquely identifies this slot even if an adjacent
     * slot ends at 17:00 (16:00–17:00) and would match a plain '17:00' filter.
     * .nth(N) is avoided because inserting new slots at lower times shifts all indices. */
    const addSlot = orgStand.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: /17:00.+18:00/ }),
    }).first();
    await expect(addSlot).toBeVisible();

    /* Click "+ Ajouter un bénévole" to open the <dialog>. */
    const addBtn = addSlot.getByRole('button', { name: '+ Ajouter un bénévole' });
    await expect(addBtn).toBeVisible();
    await addBtn.click();

    /* The <dialog> element opens via showModal() — identify by its open state. */
    const dialog = page.locator('dialog[open]').filter({
      has: page.getByText('Ajouter un bénévole'),
    });
    await expect(dialog).toBeVisible({ timeout: 5_000 });

    /* Submit button must be DISABLED before required fields are filled (JS validation). */
    const submitBtn = dialog.getByRole('button', { name: 'Inscrire' });
    await expect(submitBtn).toBeDisabled();

    /* Fill the required fields. */
    await dialog.locator('input[name="first_name"]').fill('Henri');
    await dialog.locator('input[name="last_name"]').fill('AjoutTest');
    await dialog.locator('input[name="email"]').fill('admin-added@e2e.test');

    /* Submit button becomes enabled once the form is valid (JS sync function). */
    await expect(submitBtn).toBeEnabled();
    await submitBtn.click();

    /* PRG redirect back to the inscrits tab with a success flash. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'Henri AjoutTest a été inscrit(e) au créneau.',
    );

    /* The new volunteer must appear in the 17:00 slot after the redirect. */
    const orgStandAfter = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    const addSlotAfter = orgStandAfter.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: /17:00.+18:00/ }),
    }).first();
    await expect(addSlotAfter.locator('.participants-list__name', { hasText: 'AjoutTest' })).toBeVisible();

    /* Reload confirms the signup is persisted server-side. */
    await page.reload();
    const orgStandReload = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    const addSlotReload = orgStandReload.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: /17:00.+18:00/ }),
    }).first();
    await expect(addSlotReload.locator('.participants-list__name', { hasText: 'AjoutTest' })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC4 — Admin : annuler une inscription via <details>
// (state-mutating — desktop only)
// ---------------------------------------------------------------------------

test.describe('Admin — annuler une inscription (AC4)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('l\'admin annule une inscription et la place est libérée', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openInscritsTab(page, KERMESSE_NAME);

    const panel = page.locator('#tab-panel-inscrits');

    /* Find the cancel slot (16:00–17:00) in Stand Organisateurs E2E. */
    const orgStand = panel.locator('.participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });

    const cancelSlot = orgStand.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '16:00' }),
    }).first();
    await expect(cancelSlot).toBeVisible();

    /* There should be exactly one volunteer row (Eve Other — other@e2e.test). */
    const eveRow = cancelSlot.locator('.participants-list__item').first();
    await expect(eveRow).toBeVisible();
    await expect(eveRow.locator('.participants-list__name', { hasText: 'Other' })).toBeVisible();

    /* Expand the "Annuler l'inscription" <details> panel. */
    const cancelDetails = eveRow.locator('details.admin-cancel-details');
    await expect(cancelDetails).toBeVisible();
    await cancelDetails.locator('summary').click();

    /* The confirmation sub-panel should now be visible. */
    const confirmPanel = cancelDetails.locator('.admin-cancel-details__panel');
    await expect(confirmPanel).toBeVisible();

    /* Submit without notification. */
    await confirmPanel.getByRole('button', { name: 'Confirmer' }).click();

    /* Success flash confirms the cancellation (replaces networkidle wait). */
    await expect(page.locator('.form-success[role="status"]')).toContainText(/a été annulée/i);

    /* After reload the volunteer should no longer appear in the active list. */
    await page.reload();

    const orgStandAfter = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    const cancelSlotAfter = orgStandAfter.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '16:00' }),
    }).first();

    /* The 16:00 slot should show "Aucun bénévole inscrit sur ce créneau." */
    await expect(
      cancelSlotAfter.getByText('Aucun bénévole inscrit sur ce créneau.'),
    ).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC4 — Admin : déplacer une inscription via <details>
// (state-mutating — desktop only)
// ---------------------------------------------------------------------------

test.describe('Admin — déplacer une inscription (AC4)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('l\'admin déplace un bénévole sur un autre créneau', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openInscritsTab(page, KERMESSE_NAME);

    const panel = page.locator('#tab-panel-inscrits');

    /* Find the move-source slot (19:00–20:00) in Stand Organisateurs E2E. */
    const orgStand = panel.locator('.participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });

    const moveSourceSlot = orgStand.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '19:00' }),
    }).first();
    await expect(moveSourceSlot).toBeVisible();

    const eveRow = moveSourceSlot.locator('.participants-list__item').first();
    await expect(eveRow).toBeVisible();
    await expect(eveRow.locator('.participants-list__name', { hasText: 'Other' })).toBeVisible();

    /* Expand the "Déplacer" <details> panel. */
    const moveDetails = eveRow.locator('details.admin-move-details');
    await expect(moveDetails).toBeVisible();
    await moveDetails.locator('summary').click();

    const movePanel = moveDetails.locator('.admin-move-details__panel');
    await expect(movePanel).toBeVisible();

    /* Select the move target: the 22:00 slot (Stand Organisateurs E2E Slot 4).
     * selectOption({ label: regex }) is not supported — find the option by text,
     * assert it exists (guarding against a null value attribute), then select by value. */
    const targetSelect = movePanel.locator('select[name="target_slot_id"]');
    await expect(targetSelect).toBeVisible();
    /* Use Stand name + start time to disambiguate: "21:00–22:00" (Confirmations end time)
     * would also match a plain "22:00" filter. */
    const targetOption = targetSelect.locator('option').filter({
      hasText: /Stand Organisateurs.*22:00/,
    }).first();
    await expect(targetOption).toBeAttached();
    const targetSlotId = await targetOption.getAttribute('value');
    expect(targetSlotId, 'Target slot option is missing its value attribute').not.toBeNull();
    await targetSelect.selectOption(targetSlotId!);

    /* Submit the move. */
    await movePanel.getByRole('button', { name: 'Confirmer le déplacement' }).click();

    /* Success flash confirms the move (replaces networkidle wait). */
    await expect(page.locator('.form-success[role="status"]')).toContainText(/a été déplacée/i);

    /* After reload: source slot should be empty, target slot should contain the volunteer. */
    await page.reload();

    const orgStandAfter = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });

    /* 19:00 slot: volunteer should no longer appear. */
    const sourceSlotAfter = orgStandAfter.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '19:00' }),
    }).first();
    await expect(
      sourceSlotAfter.getByText('Aucun bénévole inscrit sur ce créneau.'),
    ).toBeVisible();

    /* 22:00 slot: the volunteer should now appear. */
    const targetSlotAfter = orgStandAfter.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '22:00' }),
    }).first();
    await expect(targetSlotAfter.locator('.participants-list__name', { hasText: 'Other' })).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC4 — Admin : corriger la fiche d'une inscription via <details>
// (state-mutating — desktop only)
// Uses Slot 5 (18:00–19:00) in Stand Organisateurs E2E — admin-created signup for
// Frank DupTest (locked = false because dup-test@e2e.test has no kermesse role and
// last_login_at = NULL).
// ---------------------------------------------------------------------------

test.describe('Admin — corriger une inscription (AC4)', () => {
  test.use({ storageState: storageStateFor('admin') });

  test('l\'admin modifie le prénom d\'un bénévole et le voit persisté', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openInscritsTab(page, KERMESSE_NAME);

    const panel = page.locator('#tab-panel-inscrits');
    const orgStand = panel.locator('.participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });

    /* Find the edit slot (13:00–14:00). Time chosen to avoid hasText ambiguity:
     * adjacent slots ending at 13:00 or 14:00 don't exist so '13:00' uniquely
     * matches the start time in ".participants-slot__when". */
    const editSlot = orgStand.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '13:00' }),
    }).first();
    await expect(editSlot).toBeVisible();

    /* Frank DupTest row — admin-created signup with stored name. */
    const frankRow = editSlot.locator('.participants-list__item').first();
    await expect(frankRow).toBeVisible();
    await expect(frankRow.locator('.participants-list__name', { hasText: 'DupTest' })).toBeVisible();

    /* Expand "Modifier la fiche". */
    const editDetails = frankRow.locator('details.admin-edit-details');
    await expect(editDetails).toBeVisible();
    await editDetails.locator('summary').click();

    const editPanel = editDetails.locator('.admin-edit-details__panel');
    await expect(editPanel).toBeVisible();

    /* First name field must be editable (locked = false). */
    const firstNameInput = editPanel.locator('input[name="first_name"]');
    await expect(firstNameInput).toBeEditable();
    await firstNameInput.fill('François');

    await editPanel.getByRole('button', { name: 'Enregistrer' }).click();

    /* Flash confirms the update. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'La fiche d\'inscription a été mise à jour.',
    );

    /* After reload, the updated name must persist. */
    await page.reload();

    /* Inscrits tab re-opens via hash redirect (#inscrits). */
    await expect(page.locator('#tab-panel-inscrits')).toHaveClass(/is-open/);

    const reloadedOrgStand = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    const reloadedEditSlot = reloadedOrgStand.locator('.participants-slot').filter({
      has: page.locator('.participants-slot__when', { hasText: '13:00' }),
    }).first();
    await expect(
      reloadedEditSlot.locator('.participants-list__name', { hasText: 'François' }),
    ).toBeVisible();

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC5 — Équipe : invitations (state-mutating — desktop only)
// ---------------------------------------------------------------------------

test.describe('Équipe — invitations (AC5)', () => {
  test.use({ storageState: storageStateFor('owner') });

  test('l\'Owner peut inviter un nouveau gestionnaire', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    /* Fill the invite form (always visible in Équipe tab for Owner). */
    const inviteForm = page.locator('.invite-form');
    await expect(inviteForm).toBeVisible();

    await inviteForm.locator('#invite-firstname').fill('Nouveau');
    await inviteForm.locator('#invite-lastname').fill('Gestionnaire');
    await inviteForm.locator('#invite-email').fill('invite-new@e2e.test');
    await inviteForm.locator('#invite-role').selectOption('gestionnaire');

    await inviteForm.getByRole('button', { name: "Envoyer l'invitation" }).click();

    /* PRG redirects to #participants (not a valid tab id) so JS opens the default tab.
     * Re-open the Équipe tab so the invite_success flash inside it becomes visible.
     * Always assert + click unconditionally to avoid racy isVisible() conditionals. */
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    await expect(equipeBtn).toBeVisible();
    await equipeBtn.click();
    await expect(page.locator('#tab-panel-equipe')).toHaveClass(/is-open/);

    /* In the Docker E2E environment SMTP is not configured, so the controller sets
     * invite_warning ("rôle attribué, email non envoyé") instead of invite_success.
     * Both messages contain the invited email; we accept either flash. */
    const inviteFeedback = page.locator(
      '.form-success[role="status"], .form-warning[role="alert"]',
    ).first();
    await expect(inviteFeedback).toContainText(/invite-new@e2e\.test/);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });

  test('l\'Owner peut relancer l\'invitation d\'un membre en attente', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    /* The pending-invite@e2e.test member must appear in "Invitations en attente". */
    const pendingSection = page.locator('.participants-list').filter({
      has: page.getByText('pending-invite@e2e.test'),
    });
    await expect(pendingSection).toBeVisible();

    /* Find the row for pending-invite@e2e.test. */
    const pendingRow = pendingSection.locator('.participants-list__item').filter({
      has: page.getByText('pending-invite@e2e.test'),
    });
    await expect(pendingRow).toBeVisible();

    /* The "Invitation envoyée" badge must be visible (confirms this is a pending row). */
    await expect(pendingRow.getByText('Invitation envoyée')).toBeVisible();

    /* Click the resend button. */
    await pendingRow.getByRole('button', { name: /relancer/i }).click();

    /* PRG redirects to #participants → re-open Équipe tab unconditionally. */
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    await expect(equipeBtn).toBeVisible();
    await equipeBtn.click();
    await expect(page.locator('#tab-panel-equipe')).toHaveClass(/is-open/);

    /* Success flash confirms the invitation was resent. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'Invitation relancée avec succès.',
    );

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// AC5 — Équipe : révoquer un rôle (state-mutating — desktop only)
// Uses the dedicated revoke-me@e2e.test gestionnaire to avoid breaking other
// gestionnaire tests that depend on gestionnaire@e2e.test remaining active.
// ---------------------------------------------------------------------------

test.describe('Équipe — révoquer un rôle (AC5)', () => {
  test.use({ storageState: storageStateFor('owner') });

  test('l\'Owner révoque un gestionnaire et le voit disparaître de l\'équipe', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name.includes('mobile'), 'State-mutating — runs once on desktop only');
    const errors = watchConsoleErrors(page);

    await openEquipeTab(page, KERMESSE_NAME);

    /* Find the revoke-me@e2e.test row. */
    const revokeRow = page.locator('.participants-list__item').filter({
      has: page.getByText('revoke-me@e2e.test'),
    });
    await expect(revokeRow).toBeVisible();

    /* Revoke button must be present on a non-owner row. */
    const revokeBtn = revokeRow.getByRole('button', { name: /révoquer/i });
    await expect(revokeBtn).toBeVisible();

    /* The revoke form uses onsubmit="return confirm(...)" — accept the native dialog. */
    page.once('dialog', dialog => dialog.accept());
    await revokeBtn.click();

    /* PRG redirects to #participants → re-open Équipe tab unconditionally. */
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    await expect(equipeBtn).toBeVisible();
    await equipeBtn.click();
    await expect(page.locator('#tab-panel-equipe')).toHaveClass(/is-open/);

    /* Success flash confirms the revocation. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'Membre révoqué avec succès.',
    );

    /* The revoked user must no longer appear in the team list. */
    await expect(page.locator('.participants-list__item').filter({
      has: page.getByText('revoke-me@e2e.test'),
    })).toHaveCount(0);

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
