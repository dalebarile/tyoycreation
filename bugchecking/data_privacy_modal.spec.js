// @ts-check
import { test, expect } from '@playwright/test';

test.describe('Data Privacy Act (RA 10173) Booking Flow QA', () => {
  test('Clicking Book Now on index.php opens Data Privacy Act modal, and agreeing proceeds to booking form', async ({ page }) => {
    await page.goto('index.php');

    // Clear any pre-existing sessionStorage
    await page.evaluate(() => sessionStorage.clear());

    // Click on Book Now button in navbar
    const bookNowNavBtn = page.locator('.btn-nav-book, a[href="booking.php"]').first();
    await expect(bookNowNavBtn).toBeVisible();
    await bookNowNavBtn.click();

    // Verify Client Sign Modal opens first (Client auth gate)
    const clientSignModal = page.locator('#clientSignModal');
    await expect(clientSignModal).toHaveClass(/active/);

    // Click "Continue as Guest" to proceed with Data Privacy consent
    const guestBtn = clientSignModal.locator('button', { hasText: 'Continue as Guest' });
    await expect(guestBtn).toBeVisible();
    await guestBtn.click();

    // Verify Data Privacy Modal opens on index.php
    const privacyModal = page.locator('#indexPrivacyModal');
    await expect(privacyModal).toHaveClass(/active/);
    await expect(privacyModal.locator('h2')).toContainText('Philippine Data Privacy Notice');

    // Click "I Agree & Fill Up Booking Form"
    const agreeBtn = page.locator('#btnAcceptPrivacyIndex');
    await expect(agreeBtn).toBeVisible();
    await agreeBtn.click();

    // Verify redirected to booking.php
    await expect(page).toHaveURL(/booking\.php/);

    // Verify client name input is visible and immediately fillable without blocking overlay
    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });
    await clientNameInput.fill('Juan Dela Cruz');
    await expect(clientNameInput).toHaveValue('Juan Dela Cruz');
  });

  test('Direct visit to booking.php shows Data Privacy modal gate until user agrees', async ({ page }) => {
    // Fresh context with clear storage
    await page.goto('booking.php');
    await page.evaluate(() => sessionStorage.clear());
    await page.reload();

    // Verify Data Privacy gate modal appears on booking.php
    const privacyHeader = page.locator('#privacyModalTitle');
    await expect(privacyHeader).toBeVisible({ timeout: 10000 });
    await expect(privacyHeader).toContainText('Data Privacy Notice & Rights');

    const agreeBtn = page.locator('button:has-text("I Agree & Continue")');
    await expect(agreeBtn).toBeVisible();

    // Click Agree
    await agreeBtn.click();

    // Modal should close
    await expect(agreeBtn).not.toBeVisible();

    // Form can now be filled
    const clientNameInput = page.locator('#client_name_input');
    await clientNameInput.fill('Maria Clara');
    await expect(clientNameInput).toHaveValue('Maria Clara');
  });
});
