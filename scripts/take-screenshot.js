const puppeteer = require('puppeteer');

(async () => {
  console.log('Launching browser...');
  const browser = await puppeteer.launch({
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();

  console.log('Navigating to login page...');
  await page.goto('http://localhost:8080/wp-admin', { waitUntil: 'networkidle2' });

  console.log('Logging in...');
  await page.type('#user_login', 'admin');
  await page.type('#user_pass', 'password');
  await page.click('#wp-submit');

  console.log('Waiting for navigation after login...');
  await page.waitForNavigation({ waitUntil: 'networkidle2' });

  console.log('Navigating to performance-optimisation plugin page...');
  await page.goto('http://localhost:8080/wp-admin/admin.php?page=performance-optimisation', { waitUntil: 'networkidle2' });

  console.log('Waiting for React app to render...');
  await page.waitForSelector('#performance-optimisation', { visible: true });

  console.log('Taking screenshot...');
  await page.screenshot({ path: '/app/admin-design-check.png', fullPage: true });

  console.log('Screenshot saved to /app/admin-design-check.png');
  await browser.close();
})();
