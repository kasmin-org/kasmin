import { test, expect } from '@playwright/test';

test('health endpoint answers', async ({ request }) => {
    const response = await request.get('/up');
    expect(response.status()).toBe(200);
});

test('start page loads without horizontal scrolling', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle(/.+/);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});

test('favicon and manifest are served', async ({ request }) => {
    expect((await request.get('/favicon.svg')).status()).toBe(200);
    expect((await request.get('/site.webmanifest')).status()).toBe(200);
});
