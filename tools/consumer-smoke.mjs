import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

// Use an already installed Playwright module and browser; no global configuration changes.
const [moduleRoot, executablePath, outputDirectory, ...projects] = process.argv.slice(2);
assert.ok(moduleRoot && executablePath && outputDirectory && projects.length, 'Pass Playwright package root, browser, output directory and consumer directories.');
const require = createRequire(path.resolve(moduleRoot, 'package.json'));
const { chromium } = require('playwright');
await mkdir(outputDirectory, { recursive: true });
const browser = await chromium.launch({ executablePath, headless: true });
const reports = [];
try {
    for (const project of projects) {
        const name = path.basename(project);
        const env = Object.fromEntries((await readFile(path.join(project, '.env'), 'utf8'))
            .split(/\r?\n/).filter(line => /^(WP_HOME|WP_ADMIN_USERNAME|WP_ADMIN_PASSWORD)=/.test(line))
            .map(line => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1)]));
        assert.ok(env.WP_HOME && env.WP_ADMIN_USERNAME && env.WP_ADMIN_PASSWORD, 'Smoke credentials must exist in the local environment.');
        const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message.replaceAll(env.WP_ADMIN_PASSWORD, '[redacted]')));
        try {
            const home = await page.goto(env.WP_HOME, { waitUntil: 'networkidle' });
            assert.equal(home.status(), 200, 'Homepage must respond successfully.');
            assert.doesNotMatch(await page.locator('body').innerText(), /Fatal error:|There has been a critical error|Error establishing a database connection/);
            await page.screenshot({ path: path.join(outputDirectory, `${name}-home.png`), fullPage: true });
            await page.goto(`${env.WP_HOME}/wp-login.php`, { waitUntil: 'domcontentloaded' });
            assert.equal(new URL(page.url()).pathname, '/wp-login.php', 'Login must remain at the public root.');
            assert.equal(new URL(await page.locator('#loginform').getAttribute('action'), page.url()).pathname, '/wp-login.php', 'Login POST must use the public root.');
            await page.locator('#user_login').fill(env.WP_ADMIN_USERNAME);
            await page.locator('#user_pass').fill(env.WP_ADMIN_PASSWORD);
            await page.locator('#wp-submit').click();
            await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
            assert.equal(new URL(page.url()).pathname, '/wp-admin/', 'Dashboard must remain at the public root.');
            assert.equal(await page.locator('#adminmenu').count(), 1);
            await page.screenshot({ path: path.join(outputDirectory, `${name}-admin.png`), fullPage: true });
            assert.deepEqual(errors, [], 'Browser JavaScript must not fail.');
            reports.push({ project: name, homepage: 200, login: 'passed', dashboard: 'visible', javascriptErrors: errors });
        } finally {
            await context.close();
        }
    }
} finally {
    await browser.close();
    await writeFile(path.join(outputDirectory, 'browser-report.json'), JSON.stringify(reports, null, 2) + '\n');
}
console.log(JSON.stringify(reports));
