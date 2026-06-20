/**
 * Story 6.5 — Parcours critiques des organisateurs (E2E)
 *
 * Covers:
 *   AC2  — Lifecycle kermesse : ouvrir depuis preparation, fermer depuis open.
 *   AC3  — Gestionnaire : onglets autorisés visibles, onglets non autorisés absents.
 *   AC4  — Admin : ajouter / annuler / déplacer une inscription via les `<dialog>` et `<details>`.
 *   AC5  — Équipe : inviter un nouveau membre, relancer une invitation en attente.
 *   AC7  — Protection Owner : aucun bouton de révocation sur la ligne Owner.
 *
 * Coverage strategy: PHPUnit already covers server-side invariants (403, capacity, duplicates,
 * overlaps). These E2E tests focus exclusively on browser-rendered interactions: <dialog> modals,
 * <details> accordions, JS-gated form buttons, and tab activation via JS.
 *
 * State-mutating tests are marked desktop-only (skip on mobile) to prevent data contamination
 * when both profiles run sequentially in a single worker.
 */
import { expect, Page } from '@playwright/test';
import { test, storageStateFor, watchConsoleErrors } from '../helpers/fixtures';

const KERMESSE_NAME    = 'Kermesse E2E';
const LIFECYCLE_NAME   = 'Kermesse Lifecycle E2E';
const ORG_STAND_NAME   = 'Stand Organisateurs E2E';

// ---------------------------------------------------------------------------
// Navigation helpers
// ---------------------------------------------------------------------------

async function goToDashboard(page: Page, kermesseName: string): Promise<void> {
  await page.goto('/');
  await page.waitForLoadState('networkidle');
  const card = page.locator('.kermesse-card').filter({ has: page.getByText(kermesseName) });
  await card.getByRole('link', { name: 'Administration' }).click();
  await page.waitForLoadState('networkidle');
}

async function openInscritsTab(page: Page, kermesseName: string): Promise<void> {
  await goToDashboard(page, kermesseName);
  const inscritsBtn = page.locator('[data-tab="inscrits"]:visible').first();
  if (await inscritsBtn.isVisible()) {
    await inscritsBtn.click();
    await page.waitForLoadState('networkidle');
  }
  await expect(page.locator('#tab-panel-inscrits')).toHaveClass(/is-open/);
}

async function openEquipeTab(page: Page, kermesseName: string): Promise<void> {
  await goToDashboard(page, kermesseName);
  const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
  await expect(equipeBtn).toBeVisible();
  await equipeBtn.click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#tab-panel-equipe')).toHaveClass(/is-open/);
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

    await expect(page.locator('body')).toHaveClass(/js-active/, { timeout: 10_000 });

    const hasOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth,
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

    /* Find the Owner group section — contains "Propriétaire" heading. */
    const ownerSection = page.locator('.participants-list').filter({
      has: page.getByText('Alice Owner'),
    });
    await expect(ownerSection).toBeVisible();

    /* No revoke button (aria-label "Révoquer le rôle") on the owner row. */
    await expect(ownerSection.getByRole('button', { name: /révoquer/i })).toHaveCount(0);

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
    await goToDashboard(page, LIFECYCLE_NAME);
    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });

    const openBtn = page.getByRole('button', { name: 'Ouvrir les inscriptions' });
    await expect(openBtn).toBeVisible();
    await openBtn.click();
    await page.waitForLoadState('networkidle');

    /* Success flash confirms the transition. */
    await expect(page.locator('.form-success')).toContainText('La kermesse est ouverte.');

    /* Public volunteer page now shows available slots. */
    await page.goto('/k/kermesse-e2e-lifecycle');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.stand-group').first()).toBeVisible();
    await expect(page.locator('.slot-row--available').first()).toBeVisible();

    /* Step 2: Close the kermesse → 'closed'. */
    await goToDashboard(page, LIFECYCLE_NAME);
    await expect(page.locator('#tab-panel-modification')).toHaveClass(/is-open/, { timeout: 10_000 });

    const closeBtn = page.getByRole('button', { name: 'Fermer les inscriptions' });
    await expect(closeBtn).toBeVisible();
    await closeBtn.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.form-success')).toContainText('La kermesse est fermée.');

    /* Public page now shows the closed message and no signup links. */
    await page.goto('/k/kermesse-e2e-lifecycle');
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Les inscriptions sont clôturées')).toBeVisible();
    await expect(page.locator('.slot-row--available')).toHaveCount(0);

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

    /* Find the add-slot at 17:00 (Slot 2 — 2nd slot in Stand Organisateurs E2E).
     * Cannot filter by "17:00" alone: Slot 1 ends at 17:00, making the time filter ambiguous.
     * Use .nth(1) to reliably target the second slot. */
    const addSlot = orgStand.locator('.participants-slot').nth(1);
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

    /* Fill the required fields. */
    await dialog.locator('input[name="first_name"]').fill('Henri');
    await dialog.locator('input[name="last_name"]').fill('AjoutTest');
    await dialog.locator('input[name="email"]').fill('admin-added@e2e.test');

    /* The submit button becomes enabled once the form is valid (JS sync function). */
    const submitBtn = dialog.getByRole('button', { name: 'Inscrire' });
    await expect(submitBtn).toBeEnabled();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    /* PRG redirect back to the inscrits tab with a success flash. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'Henri AjoutTest a été inscrit(e) au créneau.',
    );

    /* The new volunteer must appear in the 17:00 slot after the redirect. */
    const orgStandAfter = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    const addSlotAfter = orgStandAfter.locator('.participants-slot').nth(1);
    await expect(addSlotAfter.locator('.participants-list__name', { hasText: 'AjoutTest' })).toBeVisible();

    /* Reload confirms the signup is persisted server-side. */
    await page.reload();
    await page.waitForLoadState('networkidle');
    const orgStandReload = page.locator('#tab-panel-inscrits .participants-stand').filter({ has: page.locator('h3.subsection-title', { hasText: ORG_STAND_NAME }) });
    await expect(orgStandReload.locator('.participants-slot').nth(1).locator('.participants-list__name', { hasText: 'AjoutTest' })).toBeVisible();

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
    await page.waitForLoadState('networkidle');

    /* Success flash confirms the cancellation. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(/a été annulée/i);

    /* After reload the volunteer should no longer appear in the active list. */
    await page.reload();
    await page.waitForLoadState('networkidle');

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
     * selectOption({ label: regex }) is not supported — find the option by text, then
     * select by its value attribute (the slot ID). */
    const targetSelect = movePanel.locator('select[name="target_slot_id"]');
    await expect(targetSelect).toBeVisible();
    /* Use Stand name + start time to disambiguate: "21:00–22:00" (Confirmations end time)
     * would also match a plain "22:00" filter. */
    const targetOption = targetSelect.locator('option').filter({
      hasText: /Stand Organisateurs.*22:00/,
    }).first();
    const targetSlotId = await targetOption.getAttribute('value');
    await targetSelect.selectOption(targetSlotId!);

    /* Submit the move. */
    await movePanel.getByRole('button', { name: 'Confirmer le déplacement' }).click();
    await page.waitForLoadState('networkidle');

    /* Success flash confirms the move. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(/a été déplacée/i);

    /* After reload: source slot should be empty, target slot should contain the volunteer. */
    await page.reload();
    await page.waitForLoadState('networkidle');

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
    await page.waitForLoadState('networkidle');

    /* PRG redirects to #participants (not a valid tab id) so JS opens the default tab.
     * Re-open the Équipe tab so the invite_success flash inside it becomes visible. */
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    if (await equipeBtn.isVisible()) { await equipeBtn.click(); }
    await page.waitForLoadState('networkidle');

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

    /* Click the resend button (🔄 — aria-label "Relancer l'invitation"). */
    await pendingRow.getByRole('button', { name: /relancer/i }).click();
    await page.waitForLoadState('networkidle');

    /* PRG redirects to #participants (invalid tab id) → default tab opens.
     * Re-open Équipe tab so the invite_success flash inside it is visible. */
    const equipeBtn = page.locator('[data-tab="equipe"]:visible').first();
    if (await equipeBtn.isVisible()) { await equipeBtn.click(); }
    await page.waitForLoadState('networkidle');

    /* Success flash confirms the invitation was resent. */
    await expect(page.locator('.form-success[role="status"]')).toContainText(
      'Invitation relancée avec succès.',
    );

    expect(errors, 'Unexpected JS/console errors').toHaveLength(0);
  });
});
