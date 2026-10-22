// @ts-check
import { test, expect } from '@playwright/test';
import path from 'path';

test.describe('Responsive Cross-Device & Viewport QA Suite', () => {
  const screenshotsDir = path.resolve('Screenshots');

  const DEVICE_VIEWPORTS = [
    { name: 'Mobile_Foldable_320x568', width: 320, height: 568 },
    { name: 'Mobile_Android_360x780', width: 360, height: 780 },
    { name: 'Mobile_iPhone_390x844', width: 390, height: 844 },
    { name: 'Tablet_Portrait_768x1024', width: 768, height: 1024 },
    { name: 'Tablet_Landscape_1024x768', width: 1024, height: 768 },
    { name: 'Desktop_MacBook_1440x900', width: 1440, height: 900 },
    { name: 'Desktop_FHD_1920x1080', width: 1920, height: 1080 },
  ];

  test.beforeEach(async ({ page }) => {
    // Pre-accept cookies and privacy consent in automated tests so modal doesn't intercept pointer events
    await page.addInitScript(() => {
      localStorage.setItem('tyoy_cookie_consent', 'accepted');
      sessionStorage.setItem('tyoy_privacy_agreed', '1');
      document.cookie = 'cookie_consent=accepted; path=/';
    });
  });

  for (const vp of DEVICE_VIEWPORTS) {
    test(`Landing Page Layout & No Horizontal Overflow on ${vp.name}`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('index.php');
      await page.waitForLoadState('domcontentloaded');

      // Verify no horizontal page body blowout (scrollWidth vs clientWidth)
      const offending = await page.evaluate(() => {
        const docW = document.documentElement.clientWidth;
        const bad = [];
        document.querySelectorAll('*').forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.right > docW + 1) {
            bad.push({
              tag: el.tagName,
              id: el.id,
              cls: typeof el.className === 'string' ? el.className : '',
              right: r.right,
              diff: r.right - docW
            });
          }
        });
        return bad.slice(0, 5);
      });
      if (offending.length > 0) {
        console.log(`OFFENDING on ${vp.name}:`, JSON.stringify(offending));
      }
      expect(offending.length).toBe(0);

      if (vp.width <= 768) {
        // Mobile navigation hamburger toggle should exist and be visible
        const toggle = page.locator('#mobileNavToggle');
        await expect(toggle).toBeVisible();

        // Clicking toggle opens drawer
        await toggle.click();
        const navLinks = page.locator('#publicNavLinks');
        await expect(navLinks).toHaveClass(/nav-open/);

        // Clicking toggle again closes drawer
        await toggle.click();
        await expect(navLinks).not.toHaveClass(/nav-open/);
      } else {
        // Desktop nav links are visible directly
        const navLinks = page.locator('.public-nav-links');
        await expect(navLinks).toBeVisible();
      }
    });

    test(`Booking Engine Wizard on ${vp.name}`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('booking.php');
      await page.waitForLoadState('domcontentloaded');

      const clientNameInput = page.locator('#client_name_input');
      await expect(clientNameInput).toBeVisible({ timeout: 10000 });

      // Verify no horizontal window overflow
      const offending = await page.evaluate(() => {
        const docW = document.documentElement.clientWidth;
        const bad = [];
        document.querySelectorAll('*').forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.right > docW + 1) {
            bad.push({
              tag: el.tagName,
              id: el.id,
              cls: typeof el.className === 'string' ? el.className : '',
              right: r.right,
              diff: r.right - docW
            });
          }
        });
        return bad.slice(0, 5);
      });
      if (offending.length > 0) {
        console.log(`OFFENDING BOOKING on ${vp.name}:`, JSON.stringify(offending));
      }
      expect(offending.length).toBe(0);

      if (vp.width <= 768) {
        // Verify booking mobile hamburger toggle works
        const bookingToggle = page.locator('#bookingNavToggle');
        await expect(bookingToggle).toBeVisible();

        await bookingToggle.click();
        const bookingNavLinks = page.locator('#bookingNavLinks');
        await expect(bookingNavLinks).toHaveClass(/nav-open/);

        await bookingToggle.click();
        await expect(bookingNavLinks).not.toHaveClass(/nav-open/);
      }
    });
  }

  test('Admin Login Portal is fully responsive on all viewports without overflow', async ({ page }) => {
    for (const vp of [320, 375, 414, 768, 1024, 1280]) {
      await page.setViewportSize({ width: vp, height: 750 });
      await page.goto('loginadmin.php', { waitUntil: 'domcontentloaded' });

      const loginCard = page.locator('.login-card');
      await expect(loginCard).toBeVisible();

      const isOverflown = await page.evaluate(() => {
        return document.documentElement.scrollWidth > document.documentElement.clientWidth;
      });
      expect(isOverflown).toBeFalsy();
    }
  });

  test('AI Chatbot Concierge Widget fits nicely on mobile and desktop', async ({ page }) => {
    // Test on Mobile (375x667)
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('index.php');
    await page.waitForLoadState('domcontentloaded');

    const launcher = page.locator('.chatbot-launcher-btn');
    await expect(launcher).toBeVisible();
    await launcher.click();

    const windowEl = page.locator('#chatbotWindow');
    await expect(windowEl).toHaveClass(/active/);

    const windowBox = await windowEl.boundingBox();
    expect(windowBox).not.toBeNull();
    if (windowBox) {
      // Chatbot should not overflow right edge of viewport
      expect(windowBox.x + windowBox.width).toBeLessThanOrEqual(375 + 2);
      expect(windowBox.x).toBeGreaterThanOrEqual(-2);
    }
  });
});
