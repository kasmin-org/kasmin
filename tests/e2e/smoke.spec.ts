import { test, expect } from '@playwright/test';

async function noHorizontalScroll(page: import('@playwright/test').Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
}

test('health endpoint answers', async ({ request }) => {
    expect((await request.get('/up')).status()).toBe(200);
});

test('start page shows the logo and fits the screen', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByAltText('Kasmin')).toBeVisible();
    await noHorizontalScroll(page);
});

test('login page asks only for the e-mail address', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('input[type=email]')).toBeVisible();
    await expect(page.locator('input[type=password]')).toHaveCount(0);
    await noHorizontalScroll(page);
});

test('requesting a link leads to the code page without revealing whether the address exists', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type=email]').fill('nobody@example.org');
    await page.locator('button[type=submit], form button').first().click();
    await expect(page).toHaveURL(/\/login\/code/);
    await expect(page.locator('input[name=code]')).toBeVisible();
    await noHorizontalScroll(page);
});

test('admin interface requires a login', async ({ page }) => {
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/login/);
});

test('favicon and manifest are served', async ({ request }) => {
    expect((await request.get('/favicon.svg')).status()).toBe(200);
    expect((await request.get('/site.webmanifest')).status()).toBe(200);
});
