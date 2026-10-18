const { test, expect } = require('@playwright/test');

// Use a disposable active run without participant traffic; this reactivates it.
const runUrl = process.env.FORMR_QUOTA_ACTIVE_TEST_RUN_URL;
const storageState = process.env.FORMR_DEV_STORAGE_STATE;
test.use({ storageState: storageState || { cookies: [], origins: [] } });

test('reopening requires acknowledgement and retains counts after reactivation', async ({ page }) => {
    test.skip(!runUrl || !storageState, 'An active test run and authenticated admin session are required.');
    const editorUrl = runUrl.replace(/\/?$/, '/');
    await page.goto(`${editorUrl}quotas/`);
    const counts = () => page.locator('tr[data-quota-group]').evaluateAll(rows => rows.map(row => ({
        table: row.dataset.quotaTable, group: row.dataset.quotaGroup,
        count: row.querySelector('[data-quota-field="count"]').value,
    })));
    const before = await counts();
    expect(before.length).toBeGreaterThan(0);
    await page.goto(editorUrl);
    await page.locator('.quota-reopen').click();
    const dialog = page.locator('.modal:visible');
    await expect(dialog).toContainText('Questionnaire responses are not deleted.');
    await expect(dialog.locator('.btn-yes')).toBeDisabled();
    await dialog.locator('.btn-no').click();
    await expect(page.locator('.quota-editor')).toHaveAttribute('data-readonly', '1');
    await page.locator('.quota-reopen').click();
    await dialog.locator('.quota-reopen-confirmation').check();
    await expect(dialog.locator('.btn-yes')).toBeEnabled();
    await dialog.locator('.btn-yes').click();
    await expect(page.locator('.quota-editor')).toHaveAttribute('data-readonly', '0');
    await expect(page.locator('[name=provider_mode]')).toBeEnabled();
    await page.locator('.quota-activate').click();
    await dialog.locator('.btn-yes').click();
    await expect(page.locator('.quota-editor')).toHaveAttribute('data-readonly', '1');
    await expect(page.locator('.quota-reopen')).toBeVisible();
    await page.goto(`${editorUrl}quotas/`);
    expect(await counts()).toEqual(before);
});
