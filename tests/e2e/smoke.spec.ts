import { test, expect } from '@playwright/test';

async function noHorizontalScroll(page: import('@playwright/test').Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
}

test('health endpoint answers', async ({ request }) => {
    expect((await request.get('/up')).status()).toBe(200);
});

// The bare address is where people arrive. It once showed a leftover text and no way to log in.
test('start page shows the logo, fits the screen and leads to the login and to the sign-up', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByAltText('Kasmin')).toBeVisible();
    await noHorizontalScroll(page);
    await page.locator('[data-start] a').first().click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('input[type=email]')).toBeVisible();
    await page.goto('/');
    await page.locator('[data-start] a').nth(1).click();
    // The sign-up is a public form (B16); the demo data switches it on.
    await expect(page).toHaveURL(/\/f\/[a-z0-9-]+$/);
    await expect(page.locator('input[name=first_name]')).toBeVisible();
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

test('public sign-up form for legal guardians fits the screen', async ({ page }) => {
    await page.goto('/register');
    await expect(page.locator('input[name=first_name]')).toBeVisible();
    await expect(page.locator('input[name=privacy]')).toBeVisible();
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

test('API documentation is public, the API itself needs a token', async ({ page, request }) => {
    const spec = await request.get('/docs/api.json');
    expect(spec.status()).toBe(200);
    expect((await spec.json()).openapi).toMatch(/^3\.1/);
    expect((await request.get('/api/v1/me')).status()).toBe(401);
    await page.goto('/docs/api');
    await expect(page.locator('body')).toContainText('Kasmin', { timeout: 15_000 });
});
