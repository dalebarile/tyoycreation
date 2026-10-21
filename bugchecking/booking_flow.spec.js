// @ts-check
import { test, expect } from '@playwright/test';

test.describe('Booking Wizard QA & Audit', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('booking.php');
  });

  test('Booking page loads React app successfully without fatal crash', async ({ page }) => {
    await expect(page).toHaveTitle(/Booking|Tyoy Creation/i);

    // Verify root container mounts
    const root = page.locator('#reactBookingRoot');
    await expect(root).toBeVisible();

    // Verify wizard form is mounted and active
    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });
  });

  test('Footer on booking page contains Facebook link and removes Instagram/email', async ({ page }) => {
    const footer = page.locator('footer');
    await expect(footer).toBeVisible();

    const fbLink = footer.locator('a[href="https://www.facebook.com/tyoycreation"]');
    await expect(fbLink).toBeVisible();

    const igIcon = footer.locator('i.fa-brands.fa-instagram');
    await expect(igIcon).toHaveCount(0);
  });

  test('Mobile viewport responsiveness check (375x667)', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.reload();

    const nav = page.locator('.booking-nav');
    await expect(nav).toBeVisible();

    const root = page.locator('#reactBookingRoot');
    await expect(root).toBeVisible();
  });
});
