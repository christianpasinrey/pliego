// tools/oracle/probe-navbar.mjs
//
// M10-T2 (navbar investigation, ad-hoc dev tooling): NOT part of the oracle pipeline
// (render-chrome.mjs/render-pliego.php/compare.php) -- a one-off probe, same
// chromium/viewport/deviceScaleFactor setup as render-chrome.mjs, that dumps
// getBoundingClientRect() + a handful of getComputedStyle() properties for fixture 07's navbar
// element chain (.navbar > .container-fluid > .navbar-brand + .navbar-text), so this task can
// diff Chrome's REAL flex/collapse box model for that markup against pliego's own (dumped
// separately via a PHP probe using Layout\FragmentDumper). Deleted or left in place after the
// task -- it costs nothing sitting here (dev-only, this directory is already excluded from the
// published package, see tools/oracle's own docblocks) but is NOT wired into `composer oracle`.
import { chromium } from 'playwright';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(here, 'fixtures', '07-bootstrap-page.html');

const VIEWPORT = { width: 794, height: 1123 };
const DEVICE_SCALE_FACTOR = 2;

async function main() {
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: DEVICE_SCALE_FACTOR });
        const page = await context.newPage();
        await page.goto(pathToFileURL(fixturePath).href);
        await page.waitForLoadState('load');
        await page.evaluate(() => document.fonts.ready);

        const dump = await page.evaluate(() => {
            const selectors = ['nav.navbar', '.navbar > .container-fluid', '.navbar-brand', '.navbar-text'];
            const props = [
                'display', 'flexDirection', 'alignItems', 'justifyContent', 'flexWrap',
                'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
                'fontSize', 'lineHeight', 'fontFamily', 'fontWeight',
                'boxSizing', 'minHeight', 'height',
            ];
            const out = {};
            for (const sel of selectors) {
                const el = document.querySelector(sel);
                if (!el) { out[sel] = null; continue; }
                const rect = el.getBoundingClientRect();
                const cs = getComputedStyle(el);
                const styles = {};
                for (const p of props) styles[p] = cs[p];
                out[sel] = {
                    rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height, top: rect.top, bottom: rect.bottom },
                    styles,
                    tag: el.tagName,
                    className: el.className,
                };
            }
            return out;
        });

        console.log(JSON.stringify(dump, null, 2));

        await context.close();
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error('probe-navbar: fatal error:', err);
    process.exit(1);
});
