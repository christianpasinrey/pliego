<?php

// tests/Unit/Layout/InlineFlowContextTest.php
declare(strict_types=1);

use Pliego\Box\LineBreakRun;
use Pliego\Box\TextRun;
use Pliego\Layout\InlineFlowContext;
use Pliego\Layout\TextMeasurer;
use Pliego\Style\ComputedStyle;
use Pliego\Text\FontCatalog;

/** @param array<string, mixed> $declarations */
function inlineStyle(array $declarations = [], ?ComputedStyle $parent = null): ComputedStyle
{
    return ComputedStyle::compute($declarations, $parent ?? ComputedStyle::root(), 'span', 16.0);
}

beforeEach(function (): void {
    $this->measurer = new TextMeasurer();
    $this->catalog = FontCatalog::withDefaults();
});

it('mixes faces in one line sharing the baseline', function () {
    $normal = inlineStyle();
    $bold = inlineStyle(['font-weight' => 700, 'text-decoration' => true], $normal);
    $runs = [
        new TextRun('Hola ', $normal),
        new TextRun('mundo', $bold),
    ];

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout($runs, 0.0, 0.0, 500.0, $normal);

    expect($fragments)->toHaveCount(2);
    [$first, $second] = $fragments;
    expect($first->text)->toBe('Hola ');
    expect($second->text)->toBe('mundo');
    // Same line: shared baseline regardless of the different face metrics.
    expect($first->baselineY)->toBe($second->baselineY);
    expect($first->rect->y)->toBe($second->rect->y);
    // Distinct faces, correctly resolved through FontCatalog.
    expect($first->faceKey)->toBe('default:400:normal');
    expect($second->faceKey)->toBe('default:700:normal');
    // Underline flows from each run's own style, not shared across the line.
    expect($first->underline)->toBeFalse();
    expect($second->underline)->toBeTrue();
    // Fragments sit flush against each other (no gap, no overlap).
    expect($second->rect->x)->toBe($first->rect->x + $first->rect->width);
});

it('wraps using break opportunities across runs', function () {
    $normal = inlineStyle();
    $bold = inlineStyle(['font-weight' => 700], $normal);
    $runs = [
        new TextRun('uno dos ', $normal),
        new TextRun('tres cuatro ', $bold),
        new TextRun('cinco seis', $normal),
    ];

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout($runs, 0.0, 0.0, 80.0, $normal);

    expect(count($fragments))->toBeGreaterThan(1);

    $byLine = [];
    foreach ($fragments as $fragment) {
        $byLine[(string) $fragment->rect->y][] = $fragment;
    }
    expect(count($byLine))->toBeGreaterThan(1);

    foreach ($byLine as $lineFragments) {
        $lineWidth = array_sum(array_map(fn($f) => $f->rect->width, $lineFragments));
        expect($lineWidth)->toBeLessThanOrEqual(80.0);
    }

    // Word order/content is preserved across the wrap.
    $joined = implode('', array_map(fn($f) => $f->text, $fragments));
    expect($joined)->toBe('uno dos tres cuatro cinco seis');
});

it('centers and right-aligns lines', function () {
    $left = inlineStyle();
    $center = inlineStyle(['text-align' => 'center'], $left);
    $right = inlineStyle(['text-align' => 'right'], $left);
    $face = $this->catalog->select('default', 400, false);
    $wordWidth = $this->measurer->widthOf('Hola', $face, 16.0);

    $centered = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout([new TextRun('Hola', $left)], 10.0, 0.0, 200.0, $center);
    $righted = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout([new TextRun('Hola', $left)], 10.0, 0.0, 200.0, $right);
    $lefted = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout([new TextRun('Hola', $left)], 10.0, 0.0, 200.0, $left);

    expect($lefted[0]->rect->x)->toBe(10.0);
    expect($centered[0]->rect->x)->toEqualWithDelta(10.0 + (200.0 - $wordWidth) / 2, 0.001);
    expect($righted[0]->rect->x)->toEqualWithDelta(10.0 + (200.0 - $wordWidth), 0.001);
});

it('honours declared line-height', function () {
    $base = inlineStyle();
    $doubled = inlineStyle(['line-height' => 2.0], $base);

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout([new TextRun('Hola', $base)], 0.0, 0.0, 500.0, $doubled);

    expect($fragments)->toHaveCount(1);
    // 2 x font-size (32.0) beats the 1.2 x font-size normal default (19.2).
    expect($fragments[0]->rect->height)->toBe(32.0);
});

it('breaks on LineBreakRun', function () {
    $normal = inlineStyle();
    $runs = [
        new TextRun('Hola', $normal),
        new LineBreakRun(),
        new TextRun('mundo', $normal),
    ];

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout($runs, 0.0, 0.0, 500.0, $normal);

    expect($fragments)->toHaveCount(2);
    expect($fragments[0]->text)->toBe('Hola');
    expect($fragments[1]->text)->toBe('mundo');
    expect($fragments[1]->rect->y)->toBeGreaterThan($fragments[0]->rect->y);
    expect($fragments[1]->rect->x)->toBe($fragments[0]->rect->x);
});

it('keeps M0 single-style geometry stable', function () {
    // Regression golden test (M1-T6 brief): re-derives, by hand, exactly what M0's
    // (now-removed) BlockFlowContext::wrapText greedy word-wrap would have produced for
    // this input, and asserts InlineFlowContext matches it fragment-for-fragment.
    $style = inlineStyle();
    $face = $this->catalog->select('default', 400, false);
    $text = 'uno dos tres cuatro cinco seis siete ocho';
    $availableWidth = 120.0;

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout([new TextRun($text, $style)], 0.0, 0.0, $availableWidth, $style);

    $words = explode(' ', $text);
    $spaceWidth = $this->measurer->widthOf(' ', $face, 16.0);
    $expectedLineWidths = [];
    $currentWords = [];
    $currentWidth = 0.0;
    foreach ($words as $word) {
        $wordWidth = $this->measurer->widthOf($word, $face, 16.0);
        $projected = $currentWords === [] ? $wordWidth : $currentWidth + $spaceWidth + $wordWidth;
        if ($projected > $availableWidth && $currentWords !== []) {
            $expectedLineWidths[] = $currentWidth;
            $currentWords = [];
            $projected = $wordWidth;
        }
        $currentWords[] = $word;
        $currentWidth = $projected;
    }
    $expectedLineWidths[] = $currentWidth;

    $lineHeight = $this->measurer->lineHeight(16.0);
    $ascent = $this->measurer->ascent($face, 16.0);

    expect($fragments)->toHaveCount(count($expectedLineWidths));
    foreach ($fragments as $i => $fragment) {
        expect($fragment->rect->x)->toBe(0.0);
        expect($fragment->rect->y)->toEqualWithDelta($i * $lineHeight, 0.001);
        expect($fragment->rect->width)->toEqualWithDelta($expectedLineWidths[$i], 0.001);
        expect($fragment->rect->height)->toEqualWithDelta($lineHeight, 0.001);
        expect($fragment->baselineY)->toEqualWithDelta($i * $lineHeight + ($lineHeight - 16.0) / 2 + $ascent, 0.001);
        expect($fragment->fontSizePx)->toBe(16.0);
        expect($fragment->faceKey)->toBe('default:400:normal');
    }
});

it('never infinite-loops on a single word wider than the line', function () {
    $style = inlineStyle();
    $runs = [new TextRun('supercalifragilisticexpialidocious', $style)];

    $fragments = new InlineFlowContext($this->measurer, $this->catalog)
        ->layout($runs, 0.0, 0.0, 10.0, $style);

    expect($fragments)->toHaveCount(1);
    expect($fragments[0]->text)->toBe('supercalifragilisticexpialidocious');
    expect($fragments[0]->rect->width)->toBeGreaterThan(10.0);
});
