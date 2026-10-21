// @ts-check
import { test, expect } from '@playwright/test';

test.describe('AI Chatbot Concierge QA & Audit', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('index.php');
  });

  test('Chatbot launcher opens and closes chat window', async ({ page }) => {
    const launcher = page.locator('.chatbot-launcher-btn');
    const window = page.locator('#chatbotWindow');

    await expect(launcher).toBeVisible();
    await expect(window).not.toHaveClass(/active/);

    // Open chat
    await launcher.click();
    await expect(window).toHaveClass(/active/);

    // Close chat
    const closeBtn = page.locator('.chatbot-header button[onclick="toggleChatWindow()"]');
    await closeBtn.click();
    await expect(window).not.toHaveClass(/active/);
  });

  test('Chatbot answers location inquiry: San Jose del Monte Bulacan and on-site only (Tagalog)', async ({ page }) => {
    const launcher = page.locator('.chatbot-launcher-btn');
    await launcher.click();

    const textarea = page.locator('.chatbot-footer textarea');
    const sendBtn = page.locator('.chatbot-footer button[type="submit"]');

    await textarea.fill('taga saan po kayo?');
    await sendBtn.click();

    // Wait for bot response to appear
    const botReplies = page.locator('#chatbotMessages .chat-bubble.bot');
    await expect(botReplies.last()).toContainText(/San Jose del Monte.*Bulacan/i, { timeout: 10000 });
    await expect(botReplies.last()).toContainText(/on-site/i);
    await expect(botReplies.last()).toContainText(/wala.*physical.*walk-in.*office/i);
  });

  test('Chatbot answers location inquiry: San Jose del Monte Bulacan and strictly on-site (English)', async ({ page }) => {
    const launcher = page.locator('.chatbot-launcher-btn');
    await launcher.click();

    const textarea = page.locator('.chatbot-footer textarea');
    const sendBtn = page.locator('.chatbot-footer button[type="submit"]');

    await textarea.fill('where is your office located?');
    await sendBtn.click();

    const botReplies = page.locator('#chatbotMessages .chat-bubble.bot');
    await expect(botReplies.last()).toContainText(/San Jose del Monte.*Bulacan/i, { timeout: 10000 });
    await expect(botReplies.last()).toContainText(/strictly on-site/i);
  });

  test('Chatbot explains Staff Review verification workflow', async ({ page }) => {
    const launcher = page.locator('.chatbot-launcher-btn');
    await launcher.click();

    const textarea = page.locator('.chatbot-footer textarea');
    const sendBtn = page.locator('.chatbot-footer button[type="submit"]');

    await textarea.fill('why do bookings need staff review?');
    await sendBtn.click();

    const botReplies = page.locator('#chatbotMessages .chat-bubble.bot');
    await expect(botReplies.last()).toContainText(/Staff Review/i, { timeout: 10000 });
    await expect(botReplies.last()).toContainText(/Coordinator/i);
  });

  test('Chatbot answers wedding packages & live pricing', async ({ page }) => {
    const launcher = page.locator('.chatbot-launcher-btn');
    await launcher.click();

    const textarea = page.locator('.chatbot-footer textarea');
    const sendBtn = page.locator('.chatbot-footer button[type="submit"]');

    await textarea.fill('magkano ang wedding package?');
    await sendBtn.click();

    const botReplies = page.locator('#chatbotMessages .chat-bubble.bot');
    await expect(botReplies.last()).toContainText(/Wedding/i, { timeout: 10000 });
  });
});
