// tools/oracle/render-chrome.mjs
//
// M9-T5: renders every tools/oracle/fixtures/*.html through headless Chromium (Playwright),
// producing tools/oracle/out/NN-chrome.png -- the "oracle" side of the comparison, i.e. the
// ground truth pliego's own PDF-then-Ghostscript raster (render-pliego.php) is judged against.
//
// Viewport is the CSS-px A4 page size at 96dpi (794x1123, see PaperSize::A4 in src/Page --
// 210mm/297mm * 96/25.4, rounded to the nearest px the same way the fixtures' own
// `body { width: 794px }` wrapper does), deviceScaleFactor 2 so the screenshot lands at the same
// effective 192dpi as render-pliego.php's `gs -r192` (so a 1 CSS-px edge maps to exactly 2 device
// pixels on both sides of the comparison -- no additional up/downscaling either renderer has to
// agree on). `fullPage: true` because fixture content height is intentionally variable
// (compare.php normalizes the two rasters to their overlapping top-left region, see PixelDiff's
// docblock, rather than forcing every fixture to fill exactly one page vertically).
//
// document.fonts.ready is awaited before the screenshot: the fixtures' own @font-face rules
// point at the SAME DejaVu .ttf files pliego embeds (resources/fonts/), specifically so both
// renderers use identical glyph outlines/metrics -- but Chrome loads @font-face asynchronously,
// and a screenshot taken before the font finishes downloading would silently fall back to a
// system font and blow every text-heavy fixture's threshold for a reason that has nothing to do
// with pliego.

import { chromium } from 'playwright';
import { readdirSync, mkdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const fixturesDir = path.join(here, 'fixtures');
const outDir = path.join(here, 'out');

const VIEWPORT = { width: 794, height: 1123 };
const DEVICE_SCALE_FACTOR = 2;

function fixtureNumber(filename) {
    const match = filename.match(/^(\d+)-/);
    if (!match) {
        throw new Error(`Fixture filename does not start with a numeric prefix: ${filename}`);
    }
    return match[1];
}

async function main() {
    mkdirSync(outDir, { recursive: true });

    const fixtureFiles = readdirSync(fixturesDir)
        .filter((f) => f.endsWith('.html'))
        .sort();

    if (fixtureFiles.length === 0) {
        console.error('render-chrome: no fixtures found under tools/oracle/fixtures/.');
        process.exit(1);
    }

    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({
            viewport: VIEWPORT,
            deviceScaleFactor: DEVICE_SCALE_FACTOR,
        });

        for (const filename of fixtureFiles) {
            const number = fixtureNumber(filename);
            const fixturePath = path.join(fixturesDir, filename);
            const outputPath = path.join(outDir, `${number}-chrome.png`);

            const page = await context.newPage();
            await page.goto(pathToFileURL(fixturePath).href);
            await page.waitForLoadState('load');
            await page.evaluate(() => document.fonts.ready);
            await page.screenshot({ path: outputPath, fullPage: true });
            await page.close();

            console.log(`render-chrome: ${filename} -> ${path.relative(here, outputPath)}`);
        }

        await context.close();
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error('render-chrome: fatal error:', err);
    process.exit(1);
});
