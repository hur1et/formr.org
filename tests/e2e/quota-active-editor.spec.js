const { test, expect } = require('@playwright/test');

// Point at a disposable, activated quota run and an authenticated admin session.
// This test temporarily toggles the run lock, then restores its original state.
const runUrl = process.env.FORMR_QUOTA_ACTIVE_TEST_RUN_URL;
const storageState = process.env.FORMR_DEV_STORAGE_STATE;
test.use({ storageState: storageState || { cookies: [], origins: [] } });

test('active quotas remain read-only when the run is unlocked', async ({ page }) => {
    test.skip(!runUrl || !storageState, 'An activated test run and admin storage state are required.');
    await page.goto(runUrl);
    const editor = page.locator('.quota-editor[data-readonly="1"]');
    await expect(editor).toBeVisible();
    await expect(page.getByRole('status')).toContainText('Quotas are active.');
    const lock = page.locator('.lock-toggle');
    const wasLocked = (await lock.getAttribute('class')).includes('btn-checked');
    const assertReadOnly = async () => {
        const controls = editor.locator('input:not([type="hidden"]), select, button');
        expect(await controls.count()).toBeGreaterThan(0);
        for (const control of await controls.all()) await expect(control).toBeDisabled();
        await expect(page.locator('.quota-activate')).toHaveCount(0);
    };
    try {
        await assertReadOnly();
        const response = page.waitForResponse(r => r.url().includes('ajax_run_locked_toggle') && r.request().method() === 'POST');
        await lock.click();
        expect((await response).ok()).toBeTruthy();
        await assertReadOnly();
    } finally {
        const isLocked = (await lock.getAttribute('class')).includes('btn-checked');
        if (isLocked !== wasLocked) {
            const response = page.waitForResponse(r => r.url().includes('ajax_run_locked_toggle') && r.request().method() === 'POST');
            await lock.click();
            expect((await response).ok()).toBeTruthy();
        }
    }
    await assertReadOnly();
    await page.reload();
    await expect(editor).toBeVisible();
    await assertReadOnly();
});
