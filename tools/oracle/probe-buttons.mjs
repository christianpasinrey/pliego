// tools/oracle/probe-buttons.mjs
//
// M10-T2 (navbar investigation follow-up, ad-hoc dev tooling -- same status as probe-navbar.mjs's
// own docblock: NOT part of the oracle pipeline, never wired into `composer oracle`): after
// fixing the navbar (see probe-navbar.mjs/the task report), fixture 07's own diff % barely moved
// -- this probe dumps Chrome's real geometry for the buttons/badges/alerts region (the fixture's
// SECOND-largest diff mass at the time) to find out why. It found the buttons/badges/alerts
// heights themselves already matched pliego exactly (31px buttons, 42px alerts, byte-for-byte) --
// the real cause was a CUMULATIVE vertical offset inherited from further up the page (the card
// row being ~12px too tall, see probe-cards.mjs), which this probe's y-position comparison is what
// surfaced.
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
            const selectors = ['.container.py-3 > .mb-2', '.badge', 'p.mb-2', '.alert-primary', '.alert-danger', 'table.table'];
            const out = {};
            for (const sel of selectors) {
                const els = document.querySelectorAll(sel);
                out[sel] = Array.from(els).map(el => {
                    const rect = el.getBoundingClientRect();
                    return { rect: { y: rect.y, height: rect.height, bottom: rect.bottom }, text: el.textContent.trim().slice(0, 20) };
                });
            }
            return out;
        });
        console.log(JSON.stringify(dump, null, 2));
        await context.close();
    } finally {
        await browser.close();
    }
}
main().catch((err) => { console.error(err); process.exit(1); });
