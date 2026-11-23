import { test, expect } from '@playwright/test';

// Needs one-time login links for a demo guardian with invoices, one per project (created by the CI job with
// `php artisan kasmin:login-link`): KASMIN_LINK_DESKTOP and KASMIN_LINK_PHONE. Skipped when not set.
test('legal guardian sees invoices with status and can open the PDF', async ({ page, request }, testInfo) => {
    const link = process.env[`KASMIN_LINK_${testInfo.project.name.toUpperCase()}`];
    test.skip(!link, 'no login link given');
    const path = new URL(link as string).pathname;

    await page.goto(path);
    await page.locator('form button').first().click();
    await expect(page).toHaveURL(/\/portal/);

    const invoices = page.locator('#invoices');
    await expect(invoices).toBeVisible();
    await expect(invoices.locator('li').first()).toContainText(/Invoice R\d+/);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const href = await invoices.locator('a').first().getAttribute('href');
    const pdf = await page.request.get(new URL(href as string, page.url()).pathname);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toBe('application/pdf');
});
