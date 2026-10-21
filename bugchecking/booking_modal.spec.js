// @ts-check
import { test, expect } from '@playwright/test';
import path from 'path';

test.describe('Booking Email Confirmation Modal QA', () => {
  test('Email confirmation modal opens fully visible without top clipping', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 750 });
    await page.goto('booking.php');

    // Agree to Data Privacy gate modal if visible
    const privacyAgreeBtn = page.locator('button:has-text("I Agree & Continue")');
    if (await privacyAgreeBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await privacyAgreeBtn.click();
    }

    // Wait for booking React app to mount
    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });

    // Fill client info
    await clientNameInput.fill('Dale Barile');
    await page.locator('#client_email_input').fill('dalebarile@gmail.com');
    await page.locator('#client_phone_input').fill('09171234567');

    // Fill event details
    await page.locator('#event_title_input').fill('Barile Grand Celebration');
    await page.locator('#location_venue_input').fill('San Jose del Monte, Bulacan');

    // Click submit booking request
    const submitBtn = page.locator('button.btn-submit-booking, button:has-text("Submit Booking Request")');
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    // Verify Email Warning Modal opens
    const modalOverlay = page.locator('.email-warning-overlay');
    await expect(modalOverlay).toBeVisible({ timeout: 5000 });

    const modalCard = page.locator('.email-warning-card');
    await expect(modalCard).toBeVisible();

    const metrics = await page.evaluate(() => {
      const overlay = document.querySelector('.email-warning-overlay');
      const card = document.querySelector('.email-warning-card');
      const oRect = overlay ? overlay.getBoundingClientRect() : null;
      const cRect = card ? card.getBoundingClientRect() : null;
      let el = overlay;
      const ancestors = [];
      while (el && el !== document.body) {
        const style = window.getComputedStyle(el);
        if (style.transform !== 'none' || style.filter !== 'none' || style.perspective !== 'none' || style.contain !== 'none') {
          ancestors.push({ tag: el.tagName, class: el.className, transform: style.transform, filter: style.filter });
        }
        el = el.parentElement;
      }
      return {
        scrollY: window.scrollY,
        innerHeight: window.innerHeight,
        oRect,
        cRect,
        problemAncestors: ancestors
      };
    });
    console.log('MODAL METRICS:', JSON.stringify(metrics, null, 2));

    expect(metrics.cRect.top).toBeGreaterThanOrEqual(10);

    // Verify all key elements inside the modal are visible
    const headerIcon = modalCard.locator('.email-warning-header-icon');
    await expect(headerIcon).toBeVisible();

    const title = modalCard.locator('.email-warning-title');
    await expect(title).toBeVisible();
    await expect(title).toContainText('Verify Email Before Sending Code');

    const emailDisplay = modalCard.locator('.email-display-text');
    await expect(emailDisplay).toBeVisible();
    await expect(emailDisplay).toContainText('dalebarile@gmail.com');

    const sendCodeBtn = modalCard.locator('.btn-confirm-email-send');
    await expect(sendCodeBtn).toBeVisible();

    const fixEmailBtn = modalCard.locator('.btn-edit-email-address');
    await expect(fixEmailBtn).toBeVisible();

    // Capture visual screenshot of the fixed modal
    await page.screenshot({ path: path.resolve('Screenshots/fixed_email_warning_modal.png') });
  });

  test('Email confirmation modal remains completely responsive on compact screen (1024x600)', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 600 });
    await page.goto('booking.php');

    // Agree to Data Privacy gate modal if visible
    const privacyAgreeBtn2 = page.locator('button:has-text("I Agree & Continue")');
    if (await privacyAgreeBtn2.isVisible({ timeout: 2000 }).catch(() => false)) {
      await privacyAgreeBtn2.click();
    }

    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });

    await clientNameInput.fill('Dale Barile');
    await page.locator('#client_email_input').fill('dalebarile@gmail.com');
    await page.locator('#client_phone_input').fill('09171234567');
    await page.locator('#event_title_input').fill('Compact Viewport Test');
    await page.locator('#location_venue_input').fill('Bulacan Venue');

    const submitBtn = page.locator('button.btn-submit-booking, button:has-text("Submit Booking Request")');
    await submitBtn.click();

    const modalCard = page.locator('.email-warning-card');
    await expect(modalCard).toBeVisible({ timeout: 5000 });

    // Bounding box top must be >= 0
    const box = await modalCard.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      expect(box.y).toBeGreaterThanOrEqual(0);
    }

    // Capture visual screenshot on compact screen
    await page.screenshot({ path: path.resolve('Screenshots/fixed_email_warning_modal_compact.png') });
  });
});
