const { test, expect } = require('@playwright/test');

// Supply fresh, disposable draft runs. Successful activation is permanent.
const draftRun = process.env.FORMR_QUOTA_DRAFT_TEST_RUN_URL;
const missingCompleteRun = process.env.FORMR_QUOTA_MISSING_COMPLETE_TEST_RUN_URL;
const storageState = process.env.FORMR_DEV_STORAGE_STATE;
test.use({ storageState: storageState || { cookies: [], origins: [] } });

async function chooseVisibility(page, value) {
    const response = page.waitForResponse(r => r.url().includes('ajax_run_public_toggle') && r.request().method() === 'POST');
    await page.locator(`.public-toggle[href$="public=${value}"]`).click();
    return response;
}

test('incomplete setup prevents publication in the admin editor', async ({ page }) => {
    test.skip(!missingCompleteRun || !storageState, 'A disposable incomplete run and admin session are required.');
    await page.goto(missingCompleteRun);
    await expect(page.locator('.quota-editor')).toBeVisible();
    const previous = await page.locator('.public-toggle.btn-checked').getAttribute('href');
    expect((await chooseVisibility(page, 2)).status()).toBe(400);
    await expect(page.locator('.alerts-container')).toContainText('needs exactly one Quota and one Complete');
    await expect(page.locator('.public-toggle.btn-checked')).toHaveAttribute('href', previous);
});

test('Complete activates both modules without a provider and enables publication', async ({ page }) => {
    test.skip(!draftRun || !storageState, 'A fresh disposable draft run and admin session are required.');
    await page.goto(draftRun);
    const quota = page.locator('.run_unit').filter({ has: page.locator('.quota-editor') });
    const complete = page.locator('.run_unit').filter({ has: page.locator('[name=provider_mode]') });
    await expect(complete.locator('[name=provider_mode]')).toHaveValue('none');
    await expect(complete.locator('.quota-provider-details')).toBeHidden();
    await expect(quota.locator('.quota-activate')).toHaveCount(0);
    expect((await chooseVisibility(page, 2)).status()).toBe(400);
    await complete.getByRole('button', { name: 'Activate quotas and completion', exact: true }).click();
    await page.locator('.modal:visible .btn-no').click();
    await expect(quota.locator('.quota-editor')).toHaveAttribute('data-readonly', '0');
    await complete.locator('[name=provider_mode]').selectOption('provider');
    await expect(complete.locator('.quota-provider-details')).toBeVisible();
    await complete.locator('[name=provider_mode]').selectOption('none');
    await complete.getByRole('button', { name: 'Activate quotas and completion', exact: true }).click();
    await page.locator('.modal:visible .btn-yes').click();
    await expect(quota.locator('.quota-editor')).toHaveAttribute('data-readonly', '1');
    await expect(complete.locator('.quota-provider')).toBeDisabled();
    await expect(complete.locator('.quota-activate')).toHaveCount(0);
    try {
        expect((await chooseVisibility(page, 2)).status()).toBe(200);
        await expect(page.locator('.public-toggle.btn-checked')).toHaveAttribute('href', /public=2$/);
    } finally {
        await chooseVisibility(page, 0);
    }
});
