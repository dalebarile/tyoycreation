const { test, expect } = require('@playwright/test');

test.describe('Client Sign-In & Booking Modal Flow', () => {
    test('Landing page: clicking Book Now opens Client Sign Modal for guests', async ({ page }) => {
        // Go to landing page
        await page.goto('index.php');

        // Check page title
        await expect(page).toHaveTitle(/Tyoy Creation/i);

        // Click on "Book Now" in navbar
        const bookBtn = page.locator('nav .btn-nav-book');
        await expect(bookBtn).toBeVisible();
        await bookBtn.click();

        // Expect #clientSignModal to be active and visible
        const clientModal = page.locator('#clientSignModal');
        await expect(clientModal).toHaveClass(/active/);
        await expect(clientModal.locator('h3')).toContainText('Client Portal');

        // Verify Google Auth button is present
        await expect(clientModal.locator('#modalSupabaseGoogleBtn')).toBeVisible();

        // Verify tabs
        const tabLogin = clientModal.locator('#tabModalLogin');
        const tabRegister = clientModal.locator('#tabModalRegister');
        await expect(tabLogin).toBeVisible();
        await expect(tabRegister).toBeVisible();

        // Verify login form fields
        await expect(clientModal.locator('#modal_login_identifier')).toBeVisible();
        await expect(clientModal.locator('#modal_login_password')).toBeVisible();

        // Switch to Create Account tab
        await tabRegister.click();
        await page.waitForTimeout(300);
        await expect(clientModal.locator('#modal_reg_full_name')).toBeVisible();
        await expect(clientModal.locator('#modal_reg_username')).toBeVisible();
        await expect(clientModal.locator('#modal_reg_email')).toBeVisible();
        await expect(clientModal.locator('#modal_reg_phone')).toBeVisible();
        await expect(clientModal.locator('#modal_reg_password')).toBeVisible();

        // Verify guest continue button is available
        const guestBtn = clientModal.locator('button', { hasText: 'Continue as Guest' });
        await expect(guestBtn).toBeVisible();
    });

    test('Flow verification: landing page -> book -> register/login -> back to landing page -> book -> data privacy act modal -> booking form', async ({ page }) => {
        test.setTimeout(60000);
        // 1. Go to landing page
        await page.goto('index.php');

        // 2. Click Book Now -> opens sign modal
        await page.locator('nav .btn-nav-book').click();
        const clientModal = page.locator('#clientSignModal');
        await expect(clientModal).toHaveClass(/active/);

        // 3. Register a fresh client account
        await page.locator('#tabModalRegister').click();
        await page.waitForTimeout(300);

        const uniqueSuffix = Date.now();
        await page.locator('#modal_reg_full_name').fill('Maria Santos');
        await page.locator('#modal_reg_username').fill(`maria_${uniqueSuffix}`);
        await page.locator('#modal_reg_email').fill(`maria_${uniqueSuffix}@example.com`);
        await page.locator('#modal_reg_phone').fill('09171234567');
        await page.locator('#modal_reg_password').fill('ClientPass123!');
        await page.locator('#modal_reg_confirm_password').fill('ClientPass123!');
        await page.locator('#modal_reg_privacy_consent').check();

        const [regResponse] = await Promise.all([
            page.waitForResponse(resp => resp.url().includes('auth_action.php?action=register') && resp.status() === 200),
            page.locator('#modalRegSubmitBtn').click()
        ]);
        expect(regResponse.ok()).toBeTruthy();

        // Verify user is now logged in on landing page with client badge
        const clientBadge = page.locator('.client-user-badge');
        await expect(clientBadge).toBeVisible({ timeout: 25000 });
        await expect(clientBadge).toContainText('Maria Santos');

        // 5. Click Book Now on landing page as logged-in client
        await page.locator('nav .btn-nav-book').click();

        // 6. Expect Data Privacy Act modal (#indexPrivacyModal) to appear
        const privacyModal = page.locator('#indexPrivacyModal');
        await expect(privacyModal).toHaveClass(/active/);
        await expect(privacyModal.locator('h2')).toContainText('Philippine Data Privacy Notice');

        // 7. Click Agree & Continue
        await page.locator('#btnAcceptPrivacyIndex').click();

        // 8. Expect transition to booking.php where client can start booking details
        await page.waitForURL(/booking\.php/);
        expect(page.url()).toContain('booking.php');

        // Clean up session by logging out
        await page.goto('logout.php?redirect=index.php');
    });

    test('Real-time pricing modal: privacy act modal is removed when clicking package options', async ({ page }) => {
        // Go to landing page
        await page.goto('index.php');

        // Open Birthday Package Inclusions modal
        await page.locator('.btn-svc-details').first().click();
        const bdayModal = page.locator('#birthdayPackageModal');
        await expect(bdayModal).toHaveClass(/active/);

        // Verify Real-Time Pricing rows exist inside modal
        const priceRow = bdayModal.locator('.modal-price-row').first();
        await expect(priceRow).toBeVisible();

        // Click Select on the package
        const selectBtn = priceRow.locator('a[href^="booking.php"]');
        await selectBtn.click();

        // Verify Data Privacy Act modal does NOT open inside/over the real-time pricing modal
        const privacyModal = page.locator('#indexPrivacyModal');
        await expect(privacyModal).not.toHaveClass(/active/);
    });

    test('Dedicated client login.php page renders for clients', async ({ page }) => {
        await page.goto('login.php');

        // Check header title
        await expect(page).toHaveTitle(/Client Sign In/i);
        await expect(page.locator('h1')).toContainText('Client Sign In');

        // Verify admin portal link exists for staff
        const adminLink = page.locator('a.admin-link-discreet');
        await expect(adminLink).toBeVisible();
        await expect(adminLink).toHaveAttribute('href', 'loginadmin.php');

        // Verify Google connect and forms
        await expect(page.locator('#tabBtnLogin')).toBeVisible();
        await expect(page.locator('#tabBtnRegister')).toBeVisible();
    });

    test('Client booking flow: logged-in client submits booking without "Attention Needed" auth error', async ({ page }) => {
        test.setTimeout(60000);
        // 1. Go to landing page and register/login client
        await page.goto('index.php');
        await page.locator('nav .btn-nav-book').click();
        const clientModal = page.locator('#clientSignModal');
        await expect(clientModal).toHaveClass(/active/);

        // Register unique client
        await page.locator('#tabModalRegister').click();
        await page.waitForTimeout(300);

        const uniqueSuffix = Date.now();
        const testEmail = `client_${uniqueSuffix}@example.com`;
        await page.locator('#modal_reg_full_name').fill('Carmela Cruz');
        await page.locator('#modal_reg_username').fill(`carmela_${uniqueSuffix}`);
        await page.locator('#modal_reg_email').fill(testEmail);
        await page.locator('#modal_reg_phone').fill('09181234567');
        await page.locator('#modal_reg_password').fill('ClientPass123!');
        await page.locator('#modal_reg_confirm_password').fill('ClientPass123!');
        await page.locator('#modal_reg_privacy_consent').check();

        const [regResponse] = await Promise.all([
            page.waitForResponse(resp => resp.url().includes('auth_action.php?action=register') && resp.status() === 200),
            page.locator('#modalRegSubmitBtn').click()
        ]);
        expect(regResponse.ok()).toBeTruthy();

        // Verify logged in badge
        const clientBadge = page.locator('.client-user-badge');
        await expect(clientBadge).toBeVisible({ timeout: 25000 });

        // Navigate directly to booking.php as logged-in client
        await page.goto('booking.php');

        // Accept privacy modal if it appears
        const privacyAgreeBtn = page.locator('button:has-text("I Agree & Continue")');
        if (await privacyAgreeBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await privacyAgreeBtn.click();
        }

        // Wait for React booking wizard to mount
        const clientNameInput = page.locator('#client_name_input');
        await expect(clientNameInput).toBeVisible({ timeout: 15000 });

        // Fill form fields
        await page.locator('#event_title_input').fill('Carmela Elegant Debut');
        await page.locator('#location_venue_input').fill('Tagaytay Midlands');

        // Click Submit Booking Request
        const submitBtn = page.locator('button.btn-submit-booking, button:has-text("Submit Booking Request")');
        await expect(submitBtn).toBeVisible();
        await submitBtn.click();

        // In Email confirmation modal, click Send Code
        const modalOverlay = page.locator('.email-warning-overlay');
        await expect(modalOverlay).toBeVisible({ timeout: 8000 });
        const sendCodeBtn = modalOverlay.locator('.btn-confirm-email-send');
        await expect(sendCodeBtn).toBeVisible();
        await sendCodeBtn.click();

        // Crucial Assertion: Confirm NO red banner "Attention Needed: An active client account is required"
        const errorBanner = page.locator('.booking-alert-banner, .alert-danger, [role="alert"]');
        if (await errorBanner.isVisible({ timeout: 1500 }).catch(() => false)) {
            const errText = await errorBanner.textContent();
            expect(errText).not.toContain('Attention Needed');
            expect(errText).not.toContain('active client account is required');
        }

        // Verify OTP modal opens or booking proceeds to verification
        const verifyModal = page.locator('.verify-modal-overlay');
        await expect(verifyModal).toBeVisible({ timeout: 15000 });
        await expect(page.locator('#verifyModalTitle')).toContainText('Enter Verification Code');

        // Cleanup
        await page.goto('logout.php?redirect=index.php');
    });
});
