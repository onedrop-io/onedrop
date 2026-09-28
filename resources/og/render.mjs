// Renders og-image.html to public/images/og.png (1200x630).
// Run with: node resources/og/render.mjs
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const source = fileURLToPath(new URL('./og-image.html', import.meta.url));
const output = fileURLToPath(
    new URL('../../public/images/og.png', import.meta.url),
);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 } });
await page.goto(`file://${source}`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: output });
await browser.close();

console.log(`Wrote ${output}`);
