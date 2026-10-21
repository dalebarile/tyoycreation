// @ts-check
import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

test.describe('UI Visual Testing & Screenshot Capture', () => {
  const screenshotsDir = path.resolve('Screenshots');

  test.beforeAll(() => {
    if (!fs.existsSync(screenshotsDir)) {
      fs.mkdirSync(screenshotsDir, { recursive: true });
    }
  });

  test('UI Test 1: Desktop Landing Page Full Layout', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('index.php');
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('.hero-content')).toBeVisible();
    await page.screenshot({ path: path.join(screenshotsDir, '01_landing_desktop.png'), fullPage: true });
  });

  test('UI Test 2: Mobile Responsive Landing Page (375x667 iPhone SE)', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('index.php');
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('.public-nav')).toBeVisible();
    await page.screenshot({ path: path.join(screenshotsDir, '02_landing_mobile.png'), fullPage: true });
  });

  test('UI Test 3: Contact Card Component & System Theme Color FB Logo', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('index.php');

    const contactCard = page.locator('#contact');
    await expect(contactCard).toBeVisible();

    // Scroll into view
    await contactCard.scrollIntoViewIfNeeded();

    // Verify Facebook icon styling
    const fbIcon = contactCard.locator('i.fa-brands.fa-facebook');
    await expect(fbIcon).toBeVisible();
    const fbColor = await fbIcon.evaluate(el => window.getComputedStyle(el).color);
    expect(fbColor).toMatch(/rgb\(24,\s*57,\s*43\)/); // Theme forest green

    await contactCard.screenshot({ path: path.join(screenshotsDir, '03_contact_card.png') });
  });

  test('UI Test 4: Footer Social Icons Cluster (FB & TikTok only)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('index.php');

    const footer = page.locator('footer');
    await expect(footer).toBeVisible();
    await footer.scrollIntoViewIfNeeded();

    await footer.screenshot({ path: path.join(screenshotsDir, '04_footer_social.png') });
  });

  test('UI Test 5: Birthday Package View Modal UI', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('index.php');

    const birthdayTrigger = page.locator('button[onclick*="birthdayPackageModal"], a[onclick*="birthdayPackageModal"]').first();
    if (await birthdayTrigger.isVisible()) {
      await birthdayTrigger.click();
      const modal = page.locator('#birthdayPackageModal');
      await expect(modal).toHaveClass(/active/);

      // Wait a moment for modal entrance animation
      await page.waitForTimeout(400);
      await page.screenshot({ path: path.join(screenshotsDir, '05_birthday_modal.png') });
    }
  });

  test('UI Test 6: AI Chatbot Concierge Window UI with Conversation', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('index.php');

    const launcher = page.locator('.chatbot-launcher-btn');
    await launcher.click();

    const window = page.locator('#chatbotWindow');
    await expect(window).toHaveClass(/active/);

    const textarea = page.locator('.chatbot-footer textarea');
    const sendBtn = page.locator('.chatbot-footer button[type="submit"]');

    await textarea.fill('taga saan po kayo?');
    await sendBtn.click();

    // Wait for bot response with location info
    const botReplies = page.locator('#chatbotMessages .chat-bubble.bot');
    await expect(botReplies.last()).toContainText(/San Jose del Monte/i, { timeout: 10000 });

    await page.waitForTimeout(400);
    await window.screenshot({ path: path.join(screenshotsDir, '06_chatbot_open.png') });
  });

  test('UI Test 7: Interactive Booking Form Wizard Desktop UI', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('booking.php');

    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });

    await page.screenshot({ path: path.join(screenshotsDir, '07_booking_desktop.png'), fullPage: false });
  });

  test('UI Test 8: Interactive Booking Form Wizard Mobile UI (375x667)', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('booking.php');

    const clientNameInput = page.locator('#client_name_input');
    await expect(clientNameInput).toBeVisible({ timeout: 10000 });

    await page.screenshot({ path: path.join(screenshotsDir, '08_booking_mobile.png'), fullPage: false });
  });

  test('UI Test 9: Admin & Staff Login Portal UI', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('loginadmin.php');

    const loginCard = page.locator('.login-card');
    await expect(loginCard).toBeVisible();

    await page.screenshot({ path: path.join(screenshotsDir, '09_admin_login.png') });
  });
});
