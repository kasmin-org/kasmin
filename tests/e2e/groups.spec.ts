import { test, expect } from '@playwright/test';

// Groups, rooms and duties of an event in a real browser. Found by clicking: moving a person with the selection
// failed with a server error, although the tests that called the method directly were green.
// Needs one login link of an administrator per project: KASMIN_GROUPS_DESKTOP and KASMIN_GROUPS_PHONE. Skipped when not set.
test('people are moved, groups are renamed, added and removed', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    const link = process.env[`KASMIN_GROUPS_${testInfo.project.name.toUpperCase()}`];
    test.skip(!link, 'no login link given');
    const failures: string[] = [];
    page.on('pageerror', (e) => failures.push(e.message));
    page.on('response', (r) => {
        if (r.status() >= 500) failures.push(`${r.status()} ${r.url()}`);
    });

    await page.goto(new URL(link as string).pathname);
    await page.locator('form button').first().click();
    await page.waitForURL(/\/(admin|portal)/);
    await page.goto('/admin/events');
    const edit = await page.locator('.fi-ta-row a[href*="/edit"]').first().getAttribute('href');
    await page.goto((edit as string).replace('/edit', '/groups'));

    // Each project makes its own division, so both can run against the same instance.
    await page.getByRole('button', { name: 'New division' }).click();
    const name = `Teams ${testInfo.project.name}`;
    const dialog = page.locator('.fi-modal').filter({ hasText: 'How many' });
    await dialog.locator('input[type="text"]').first().fill(name); // the name
    await dialog.locator('input[type="number"]').first().fill('3'); // how many
    await dialog.getByRole('button', { name: 'Submit' }).click();
    const division = page.locator(`[data-division-name="${name}"]`);
    await expect(division).toBeVisible();

    await division.getByRole('button', { name: 'Propose for those without a place' }).click();
    const units = division.locator('[data-unit]');
    await expect(units).toHaveCount(3);
    await expect(units.first().locator('select').first()).toBeVisible();
    const before = await units.nth(1).locator('select').count();

    // Move the first person of the first group into the second one.
    const target = await units.nth(1).locator('input').first().inputValue();
    await units.first().locator('select').first().selectOption({ label: target });
    await expect(units.nth(1).locator('select')).toHaveCount(before + 1);

    // Take somebody out: the person appears under "Not placed yet", and the page says so.
    await units.nth(1).locator('select').first().selectOption({ label: '– not placed –' });
    await expect(division.getByText('Not placed yet')).toBeVisible();
    await expect(division.getByText('has no place yet')).toBeVisible();

    // Rename a group and give it a note; both stay after a reload.
    await units.first().locator('input').nth(0).fill('Foxes');
    await units.first().locator('input').nth(0).blur();
    await units.first().locator('input').nth(1).fill('led by Kim');
    await units.first().locator('input').nth(1).blur();
    await division.getByRole('button', { name: 'One more' }).click();
    await expect(units).toHaveCount(4);
    await page.reload();
    const again = page.locator(`[data-division-name="${name}"]`);
    await expect(again.locator('[data-unit] input').first()).toHaveValue('Foxes');
    await expect(again.locator('[data-unit] input').nth(1)).toHaveValue('led by Kim');
    await again.locator('[data-unit]').nth(3).getByRole('button', { name: 'Remove' }).click();
    await expect(again.locator('[data-unit]')).toHaveCount(3);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, 'horizontal scrolling').toBeLessThanOrEqual(0);
    expect(failures).toEqual([]);
});
