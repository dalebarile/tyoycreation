const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });

    const screenshotsDir = path.join(__dirname, '..', 'Screenshots');
    if (!fs.existsSync(screenshotsDir)) {
        fs.mkdirSync(screenshotsDir, { recursive: true });
    }

    // 1. Landing page -> Client Sign Modal
    await page.goto('http://localhost/Tyoy_Creation_QES_updated/qes/index.php');
    await page.waitForLoadState('networkidle');
    await page.locator('nav .btn-nav-book').click();
    await page.waitForSelector('#clientSignModal.active');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(screenshotsDir, 'client_sign_modal.png') });
    console.log('Saved client_sign_modal.png');

    // 2. Client Login Page
    await page.goto('http://localhost/Tyoy_Creation_QES_updated/qes/login.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(screenshotsDir, 'client_login_page.png') });
    console.log('Saved client_login_page.png');

    await browser.close();
})();
