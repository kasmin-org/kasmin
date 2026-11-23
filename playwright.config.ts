import { defineConfig, devices } from '@playwright/test';

// GUI tests run against a running Kasmin instance (Docker image in CI).
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.KASMIN_URL ?? 'http://127.0.0.1:8000',
        trace: 'off',
    },
    // Phone and desktop are equally important: every test runs on both.
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'phone', use: { ...devices['Pixel 7'] } },
    ],
});
