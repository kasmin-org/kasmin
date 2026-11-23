import { test, expect } from '@playwright/test';

// Needs approval links of a pending automation run, one per project (created by the CI job):
// KASMIN_APPROVAL_DESKTOP and KASMIN_APPROVAL_PHONE. Skipped when not set.
test('approval page shows the plan, decides only on the button and fits the screen', async ({ page }, testInfo) => {
    const link = process.env[`KASMIN_APPROVAL_${testInfo.project.name.toUpperCase()}`];
    test.skip(!link, 'no approval link given');

    await page.goto(link as string);
    await expect(page.getByRole('button', { name: 'Yes, run it' })).toBeVisible();
    await expect(page.locator('input[name="included[]"]').first()).toBeChecked();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    // Opening the link again must not have decided anything.
    await page.reload();
    await expect(page.getByRole('button', { name: 'No, cancel' })).toBeVisible();
    await page.getByRole('button', { name: 'No, cancel' }).click();
    await expect(page).toHaveURL(/\/approvals\/done/);
    await expect(page.locator('body')).toContainText('Denied');
});
