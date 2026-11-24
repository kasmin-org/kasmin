import { test, expect } from '@playwright/test';

// Needs one login link of an administrator per project (created by the CI job, which switches the second factor off in
// its throwaway instance): KASMIN_ADMIN_DESKTOP and KASMIN_ADMIN_PHONE. Skipped when not set.
const pages = ['', '/reports', '/privacy', '/audit-events', '/consents', '/api-tokens', '/webhooks/create', '/import/people',
    '/people', '/households', '/events', '/invoices', '/payments', '/mailings', '/automations', '/automation-runs', '/settings/organisation', '/system'];

test('admin pages load without errors, styled and without horizontal scrolling', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    const link = process.env[`KASMIN_ADMIN_${testInfo.project.name.toUpperCase()}`];
    test.skip(!link, 'no login link given');
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto(new URL(link as string).pathname);
    await page.locator('form button').first().click();
    await page.waitForURL(/\/(admin|portal)/);
    for (const path of pages) {
        const response = await page.goto('/admin' + path);
        expect(response?.status(), path).toBe(200);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `horizontal scrolling on ${path}`).toBeLessThanOrEqual(0);
    }
    expect(errors).toEqual([]);

    // The admin theme contains the utility classes of Kasmin's own views.
    await page.goto('/admin');
    const value = page.locator('dd.text-xl').first();
    await expect(value).toBeVisible();
    expect(await value.evaluate((e) => Number(getComputedStyle(e).fontWeight))).toBeGreaterThanOrEqual(600);
});
