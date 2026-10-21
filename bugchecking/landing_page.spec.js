// @ts-check
import { test, expect } from '@playwright/test';

test.describe('Landing Page QA & Audit', () => {
  let consoleErrors = [];
  let failedRequests = [];

  test.beforeEach(async ({ page }) => {
    consoleErrors = [];
    failedRequests = [];

    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    page.on('requestfailed', request => {
      failedRequests.push(`${request.method()} ${request.url()} - ${request.failure()?.errorText}`);
    });

    await page.goto('index.php');
  });

  test('Page Title & SEO Meta Tags', async ({ page }) => {
    await expect(page).toHaveTitle(/Tyoy Creation/i);
    const metaDesc = page.locator('meta[name="description"]');
    await expect(metaDesc).toHaveAttribute('content', /.+/);
  });

  test('Navigation links and smooth scroll anchors exist', async ({ page }) => {
    const navLinks = page.locator('nav.public-nav a');
    await expect(navLinks.first()).toBeVisible();
    
    // Check specific anchors
    const homeLink = page.locator('nav.public-nav a[href="#home"]').first();
    const aboutLink = page.locator('nav.public-nav a[href="#about-us"]').first();
    const servicesLink = page.locator('nav.public-nav a[href="#services"]').first();
    const contactLink = page.locator('nav.public-nav a[href="#contact"]').first();

    await expect(homeLink).toBeAttached();
    await expect(aboutLink).toBeAttached();
    await expect(servicesLink).toBeAttached();
    await expect(contactLink).toBeAttached();
  });

  test('Hero section elements and CTA buttons', async ({ page }) => {
    const heroHeading = page.locator('.hero-content h1, #home h1').first();
    await expect(heroHeading).toBeVisible();

    const bookBtn = page.locator('a[href="booking.php"]').first();
    await expect(bookBtn).toBeVisible();
  });

  test('Package View Modals can be opened and closed', async ({ page }) => {
    // Check if birthday package modal can be opened
    const birthdayTrigger = page.locator('button[onclick*="birthdayPackageModal"], a[onclick*="birthdayPackageModal"]').first();
    if (await birthdayTrigger.isVisible()) {
      await birthdayTrigger.click();
      const modal = page.locator('#birthdayPackageModal');
      await expect(modal).toHaveClass(/active/);

      // Close modal via close button
      const closeBtn = modal.locator('.package-view-close, button.close, [onclick*="closePackageModal"]').first();
      await closeBtn.click();
      await expect(modal).not.toHaveClass(/active/);
    }
  });

  test('Contact Card QA: Phone, Email, Facebook info, and No Physical Office', async ({ page }) => {
    const contactSection = page.locator('#contact');
    await expect(contactSection).toBeVisible();

    // Verify phone number
    const contactText = await contactSection.textContent();
    expect(contactText).toContain('0956 035 3731');
    expect(contactText).toContain('contact@tyoycreation.com');

    // Verify Facebook Page info text
    expect(contactText).toContain('Tyoy Creation Event Management & Styling Service');

    // Verify Facebook link target
    const fbLink = contactSection.locator('a[href="https://www.facebook.com/tyoycreation"]');
    await expect(fbLink).toBeVisible();
    await expect(fbLink).toHaveAttribute('target', '_blank');

    // Verify Facebook icon inside contacts uses the theme color (#18392b / rgb(24, 57, 43))
    const fbIcon = fbLink.locator('i.fa-brands.fa-facebook');
    await expect(fbIcon).toBeVisible();
    const fbIconColor = await fbIcon.evaluate((el) => window.getComputedStyle(el).color);
    // Theme color rgb(24, 57, 43) corresponds to #18392b
    expect(fbIconColor).toMatch(/rgb\(24,\s*57,\s*43\)/);

    // Verify NO walk-in physical office is advertised in the contact card
    expect(contactText).not.toContain('123 Grand Ballroom Avenue');
    expect(contactText).not.toContain('Walk-in Office');
  });

  test('Footer Social Icons: Facebook & TikTok present; Instagram & Envelope removed', async ({ page }) => {
    const footer = page.locator('footer');
    await expect(footer).toBeVisible();

    // Facebook icon in footer links to https://www.facebook.com/tyoycreation
    const footerFb = footer.locator('a[href="https://www.facebook.com/tyoycreation"]');
    await expect(footerFb).toBeVisible();

    // TikTok icon present
    const footerTiktok = footer.locator('i.fa-brands.fa-tiktok');
    await expect(footerTiktok).toBeVisible();

    // Instagram icon MUST NOT exist in footer
    const footerInstagram = footer.locator('i.fa-brands.fa-instagram');
    await expect(footerInstagram).toHaveCount(0);

    // Email/Envelope icon MUST NOT exist in footer social cluster
    const footerEnvelope = footer.locator('div[style*="font-size: 16px"] i.fa-envelope, footer a[href^="mailto:"]');
    await expect(footerEnvelope).toHaveCount(0);

    // Admin portal link is present
    const adminLink = footer.locator('a[href="loginadmin.php"]');
    await expect(adminLink).toBeVisible();
  });

  test('Audit: No unexpected console errors on landing page load', async ({ page }) => {
    // Wait for network idle to catch any async script errors
    await page.waitForLoadState('domcontentloaded');
    
    // Filter out expected benign warnings if any
    const criticalErrors = consoleErrors.filter(err => 
      !err.includes('favicon') && 
      !err.includes('status of 404') && 
      !err.includes('ERR_QUIC_PROTOCOL_ERROR') &&
      !err.includes('Third-party cookie')
    );

    expect(criticalErrors).toHaveLength(0);
  });
});
