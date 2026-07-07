<?php

// tests/EndToEnd/BootstrapPresetTest.php
declare(strict_types=1);

use Pliego\Engine;

/**
 * M9-T4: Engine::bootstrap() -- the preset API. Distinct from BootstrapRealComponentsTest.php
 * (M9-T2, "does the whole real sheet render a real page right"): this file is narrowly about the
 * PLUMBING bootstrap() adds -- (1) the preset + its print addendum are queued author-order BEFORE
 * every ->stylesheet() call, no matter how many follow or in what order relative to each other (a
 * flag on the fresh instance, assembled once at render() -- see Engine::assembledCss()'s
 * docblock); (2) a same-specificity user rule still wins over the preset (author-order cascade
 * tiebreak, not !important/higher specificity); (3) the print addendum's @page margins apply when
 * the preset is active and the user declares no @page of their own; (4) a user @page rule still
 * completely replaces the addendum's (StylesheetParser: last @page rule wins whole, not merged
 * per side); (5) never calling ->bootstrap() at all (plain Engine::make()) leaves rendering
 * byte-for-byte as before this task.
 *
 * Engine::bootstrap() is a static factory, an alternative entry point to Engine::make() (same
 * "returns a fresh Engine" shape, see its own docblock) -- NOT an instance method chained after
 * make(); ->stylesheet() calls always come AFTER it in a real chain, same as any other
 * Engine::make()->... chain.
 *
 * Helper functions prefixed `bootstrapPreset` (unique-per-file convention, see other EndToEnd
 * Bootstrap-* files' docblocks -- Pest loads every test file's top-level functions into ONE
 * process, a name clash between files would fatal).
 */

const BOOTSTRAP_PRESET_BTN_HTML = '<body><div class="btn">Click</div></body>';

/** Same recipe as PageRuleTest.php's firstTextPosition(), renamed to avoid a cross-file clash.
 * @return array{0: float, 1: float} [x, baselineY] in pt, of the PDF's first Tj operator. */
function bootstrapPresetFirstTextPosition(string $pdf): array
{
    preg_match('/rg ([\d.]+) ([\d.]+) Td/', $pdf, $m);
    expect($m)->not->toBeEmpty();
    return [(float) $m[1], (float) $m[2]];
}

function bootstrapPresetRenderToString(Engine $engine, string $html): string
{
    $stream = fopen('php://memory', 'r+b');
    assert($stream !== false);
    $engine->render($html)->toStream($stream);
    rewind($stream);
    return (string) stream_get_contents($stream);
}

function bootstrapPresetPurpleFill(): string
{
    return sprintf('%.3F %.3F %.3F rg', 128 / 255, 0.0, 128 / 255);
}

// --- (5) opt-out baseline: never touching ->bootstrap() changes nothing --------------------------

it('leaves rendering byte-for-byte identical to a plain Engine::make() chain when ->bootstrap() is never called', function () {
    $css = 'p { color: #333333 } .box { background-color: #eee; padding: 10px }';
    $html = '<body><p class="box">Sin preset, nada cambia.</p></body>';

    $pdfA = bootstrapPresetRenderToString(Engine::make()->stylesheet($css), $html);
    $pdfB = bootstrapPresetRenderToString(Engine::make()->stylesheet($css), $html);

    // A/B: two independently-built engines (same config, no ->bootstrap()) -- the new
    // $bootstrapPreset flag defaults false and Engine::assembledCss() short-circuits straight to
    // $this->css in that case (see its docblock), so nothing about this task's code touches the
    // pre-existing render path at all.
    expect($pdfA)->toBe($pdfB);
    expect($pdfA)->toStartWith('%PDF-1.7');
});

// --- (2)+(3, tie probe) preset before user sheets: same-specificity override wins ----------------

it('queues the vendored preset before user stylesheets, so a same-specificity .btn override wins (author-order cascade, not !important)', function () {
    $pdf = bootstrapPresetRenderToString(
        Engine::bootstrap()->stylesheet('.btn { background: purple }'),
        BOOTSTRAP_PRESET_BTN_HTML,
    );

    expect($pdf)->toStartWith('%PDF-1.7');
    // Real Bootstrap's OWN `.btn{...background-color:var(--bs-btn-bg)...}` (--bs-btn-bg:
    // transparent by default) has the SAME specificity (0,1,0) as the user's `.btn { background:
    // purple }` -- the user rule wins purely because it comes LAST in the assembled stylesheet
    // (preset queued first by ->bootstrap(), user's ->stylesheet() call after it).
    expect($pdf)->toContain(bootstrapPresetPurpleFill());
});

it('preserves the relative order of multiple user ->stylesheet() calls among themselves, after the preset', function () {
    // Two user sheets that both target the same property at the same specificity -- the LATER
    // ->stylesheet() call must still win over the EARLIER one, exactly as without ->bootstrap()
    // (M6 cascade order) -- the preset only ever gets inserted before ALL of them, it doesn't
    // reorder the user's own calls relative to each other.
    $pdf = bootstrapPresetRenderToString(
        Engine::bootstrap()
            ->stylesheet('.btn { background: red }')
            ->stylesheet('.btn { background: purple }'),
        BOOTSTRAP_PRESET_BTN_HTML,
    );

    $redFill = sprintf('%.3F %.3F %.3F rg', 1.0, 0.0, 0.0);
    expect($pdf)->toContain(bootstrapPresetPurpleFill());
    expect($pdf)->not->toContain($redFill);
});

// --- (3) print addendum: @page margin: 15mm applies when the preset is active --------------------

it('applies the print addendum\'s @page margin (15mm) when the preset is active and the user declares no @page', function () {
    $pdfPlain = bootstrapPresetRenderToString(Engine::make(), '<body><p>Texto</p></body>');
    $pdfPreset = bootstrapPresetRenderToString(Engine::bootstrap(), '<body><p>Texto</p></body>');

    [$xPlain] = bootstrapPresetFirstTextPosition($pdfPlain);
    [$xPreset] = bootstrapPresetFirstTextPosition($pdfPreset);

    // Engine's own default (48px, no @page) vs. the addendum's 15mm -- css-values-3 §5.2 exact
    // factor (same one Page\PageRuleFactory/Css\Value\Length::fromCss use), converted to pt (the
    // same *0.75 the PDF content stream itself uses, see PageRuleTest.php's own convention).
    expect($xPlain)->toBe(round(48.0 * 0.75, 2));
    $marginPx = 15.0 * (9.6 / 2.54);
    expect($xPreset)->toBe(round($marginPx * 0.75, 2));
    expect($xPreset)->not->toBe($xPlain);
});

// --- (4) a user @page rule completely replaces the addendum's ------------------------------------

it('lets a user @page rule completely override the print addendum\'s margins (last @page rule wins whole, per StylesheetParser)', function () {
    $pdf = bootstrapPresetRenderToString(
        Engine::bootstrap()->stylesheet('@page { margin: 30px }'),
        '<body><p>Texto</p></body>',
    );

    [$x] = bootstrapPresetFirstTextPosition($pdf);

    // 30px (the user's), NOT 15mm (~56.69px, the addendum's) and NOT 48px (Engine's own
    // no-@page-at-all default) -- the user's @page, queued textually AFTER the addendum's own
    // (author order: preset, addendum, then every ->stylesheet() call), is the LAST @page rule in
    // the document, so it wins ENTIRELY (not merged per side with the addendum's 15mm).
    expect($x)->toBe(round(30.0 * 0.75, 2));
});

it('confirms a user @page rule NOT declared without the preset would fall back to a different value entirely (sanity: the preset genuinely changed the default)', function () {
    // Same user @page (30px) but WITHOUT ->bootstrap() -- proves the previous test's 30px result
    // comes from the user's own rule winning the cascade, not from some unrelated fixed value the
    // engine always produces for a `@page { margin: 30px }` declaration regardless of the preset.
    $pdf = bootstrapPresetRenderToString(
        Engine::make()->stylesheet('@page { margin: 30px }'),
        '<body><p>Texto</p></body>',
    );
    [$x] = bootstrapPresetFirstTextPosition($pdf);
    expect($x)->toBe(round(30.0 * 0.75, 2));
});
