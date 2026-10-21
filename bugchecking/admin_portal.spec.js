// @ts-check
import { test, expect } from '@playwright/test';

test.describe('Admin Authentication & Security QA', () => {
  test('Login page loads with CSRF protection', async ({ page }) => {
    await page.goto('loginadmin.php');
    await expect(page).toHaveTitle(/Admin|Staff|Tyoy Creation/i);

    // Verify form elements exist
    const usernameInput = page.locator('input[name="username"], input[name="email"], input[type="text"]').first();
    const passwordInput = page.locator('input[type="password"]').first();
    const submitBtn = page.locator('button[type="submit"]').first();

    await expect(usernameInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(submitBtn).toBeVisible();

    // Verify CSRF input token exists in DOM
    const csrfToken = page.locator('input[name="csrf_token"]');
    await expect(csrfToken).toBeAttached();
    await expect(csrfToken).toHaveAttribute('value', /.+/);
  });

  test('Invalid login credentials rejection', async ({ page }) => {
    await page.goto('loginadmin.php');

    const usernameInput = page.locator('input[name="username"], input[name="email"], input[type="text"]').first();
    const passwordInput = page.locator('input[type="password"]').first();
    const submitBtn = page.locator('button[type="submit"]').first();

    await usernameInput.fill('nonexistent_admin_user');
    await passwordInput.fill('InvalidPassword!123');
    await submitBtn.click();

    // Verify error alert or validation message appears
    const errorAlert = page.locator('#loginAlertBox, .login-error');
    await expect(errorAlert).toBeVisible({ timeout: 5000 });
    await expect(errorAlert).toContainText(/invalid|incorrect|failed|Turnstile|security check/i);
  });

  test('Unauthorized dashboard access redirects to login', async ({ page }) => {
    // Attempting to access protected admin home without active session
    await page.goto('a_home.php');
    
    // Expect URL redirect to loginadmin.php
    await expect(page).toHaveURL(/loginadmin\.php/);
  });
});
