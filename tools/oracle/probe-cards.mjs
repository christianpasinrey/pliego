// tools/oracle/probe-cards.mjs
//
// M10-T2 (navbar investigation follow-up, ad-hoc dev tooling -- same status as probe-navbar.mjs's
// own docblock): probe-buttons.mjs traced fixture 07's SECOND diff mass (after the navbar fix) to
// a cumulative vertical offset starting above the buttons row -- this probe dumps Chrome's real
// geometry for the 3-card row (.row.row-cols-3) that precedes it, which is where that offset
// actually originates. Found: `.card-title` (h6, line-height:1.2, Bootstrap's real heading ratio)
// already matched pliego exactly (19.2px both); `.card-text.small` (font-size:14px, NO own
// line-height declared) did NOT -- Chrome resolved 21px (14 * inherited 1.5), pliego resolved
// 24px (16 * 1.5, the PARENT's already-resolved px leaking through unchanged instead of
// re-deriving the inherited multiplier against its own smaller font-size) -- the root cause fixed
// in Style\ComputedStyle (see $lineHeightMultiplier and the task report).
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
            const props = ['display','paddingTop','paddingBottom','marginTop','marginBottom','fontSize','lineHeight','fontWeight'];
            const selectors = ['.row.row-cols-3', '.row.row-cols-3 > .col:first-child', '.card.h-100', '.card-body.p-2', '.card-title', '.card-text'];
            const out = {};
            for (const sel of selectors) {
                const el = document.querySelector(sel);
                if (!el) { out[sel] = null; continue; }
                const rect = el.getBoundingClientRect();
                const cs = getComputedStyle(el);
                const styles = {};
                for (const p of props) styles[p] = cs[p];
                out[sel] = { rect: { y: rect.y, height: rect.height, bottom: rect.bottom }, styles };
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
