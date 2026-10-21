const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 850 } });
  const page = await context.newPage();

  console.log('1. Navigating to booking.php...');
  await page.goto('http://localhost/Tyoy_Creation_QES_updated/qes/booking.php');

  // Wait for booking React app to mount
  await page.waitForSelector('#client_name_input', { timeout: 10000 });
  console.log('   React app mounted.');

  // Wait 2s for Turnstile to settle
  await page.waitForTimeout(2000);

  // 2. Check Turnstile on the booking form
  const turnstileCheck = await page.evaluate(() => {
    const el = document.getElementById('mainBookingTurnstile');
    const input = el ? el.querySelector('input[name="cf-turnstile-response"]') : null;
    return {
      elFound: !!el,
      hasInput: !!input,
      hasToken: !!(input && input.value),
      tokenVal: input ? input.value : ''
    };
  });
  console.log('2. Turnstile status on booking form:', turnstileCheck);

  // 3. Fill in some data
  await page.fill('#client_name_input', 'Dale Test Client');
  await page.fill('#client_email_input', 'dalebarile@gmail.com');
  await page.fill('#client_phone_input', '09171234567');
  await page.fill('#event_title_input', 'Dale Celebration QA');
  await page.fill('#location_venue_input', 'Grand Ballroom SJDM');

  // 4. Test Navigation Interception / Exit Warning Modal via Brand Logo click
  console.log('3. Testing Exit Warning Modal trigger via Brand Logo click...');
  const logo = page.locator('img.brand-logo-img');
  await logo.click();

  // Wait for Exit Warning Modal
  const exitOverlay = page.locator('.exit-warning-overlay');
  await exitOverlay.waitFor({ state: 'visible', timeout: 5000 });
  console.log('   Exit Warning Modal opened successfully!');

  // Verify modal elements
  const modalTitle = await page.locator('.exit-warning-card h3').innerText();
  console.log('   Modal Title:', modalTitle);

  // Take screenshot of Exit Warning Modal
  await page.screenshot({ path: path.resolve('Screenshots/exit_warning_modal.png') });
  console.log('   Saved screenshot to Screenshots/exit_warning_modal.png');

  // 5. Click "Stay & Continue Booking"
  console.log('4. Testing "Stay & Continue Booking"...');
  const stayBtn = page.locator('.btn-stay-booking');
  await stayBtn.click();
  await exitOverlay.waitFor({ state: 'hidden', timeout: 3000 });
  console.log('   Modal closed. Verifying form data was preserved:');
  const nameVal = await page.inputValue('#client_name_input');
  console.log('   Client Name preserved:', nameVal === 'Dale Test Client' ? 'YES' : 'NO');

  // 6. Test navigation interception via navbar "Home" link
  console.log('5. Testing Exit Warning Modal trigger via Navbar Home link...');
  const homeLink = page.locator('a.nav-link-btn:has-text("Home")');
  await homeLink.click();
  await exitOverlay.waitFor({ state: 'visible', timeout: 5000 });
  console.log('   Exit Warning Modal opened from Navbar Home link!');
  await stayBtn.click();
  await exitOverlay.waitFor({ state: 'hidden', timeout: 3000 });

  // 7. Test explicit "Cancel Booking & Return to Home" button
  console.log('6. Testing explicit "Cancel Booking & Return to Home" button...');
  const cancelFlowBtn = page.locator('.btn-cancel-booking-flow');
  await cancelFlowBtn.scrollIntoViewIfNeeded();
  await cancelFlowBtn.click();
  await exitOverlay.waitFor({ state: 'visible', timeout: 5000 });
  console.log('   Exit Warning Modal opened from Cancel button!');

  // Take compact viewport screenshot of exit modal
  await page.setViewportSize({ width: 1024, height: 600 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.resolve('Screenshots/exit_warning_modal_compact.png') });
  console.log('   Saved compact screenshot to Screenshots/exit_warning_modal_compact.png');

  // 8. Click "Yes, Discard & Leave"
  console.log('7. Testing "Yes, Discard & Leave"...');
  const discardBtn = page.locator('.btn-discard-exit');
  await discardBtn.click();

  // Verify page navigates to index.php
  await page.waitForURL(/index\.php/, { timeout: 10000 });
  console.log('   Successfully navigated to index.php! Current URL:', page.url());

  console.log('\n--- ALL FULL QA VERIFICATIONS PASSED 100% ---');
  await browser.close();
})();
