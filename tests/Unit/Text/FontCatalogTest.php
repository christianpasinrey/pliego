<?php

declare(strict_types=1);

use Pliego\Text\FontCatalog;
use Pliego\Text\FontException;
use Pliego\Text\FontFace;

function fontCatalogFixturesDir(): string
{
    return __DIR__ . '/../../../resources/fonts';
}

it('selects the exact face when registered', function (): void {
    $catalog = FontCatalog::withDefaults();

    $face = $catalog->select('default', 700, true);

    expect($face)->toBeInstanceOf(FontFace::class);
    expect($face->key)->toBe('default:700:italic');
    expect($face->font->bytes())->toBe(
        file_get_contents(fontCatalogFixturesDir() . '/DejaVuSans-BoldOblique.ttf'),
    );
});

it('falls back to closest face', function (): void {
    $catalog = new FontCatalog();
    $catalog->register('acme', 400, false, fontCatalogFixturesDir() . '/DejaVuSans.ttf');
    $catalog->register('acme', 700, false, fontCatalogFixturesDir() . '/DejaVuSans-Bold.ttf');
    // 'acme' bold-italic no está registrado: debe caer en (700, normal) de la misma familia.

    $face = $catalog->select('acme', 700, true);

    expect($face->key)->toBe('acme:700:normal');
    expect($face->font->bytes())->toBe(
        file_get_contents(fontCatalogFixturesDir() . '/DejaVuSans-Bold.ttf'),
    );
});

// M9-T1 housekeeping (M8 final-review finding): select() used to silently fall back to 'default'
// for a family that was NEVER registered at all -- no warning channel exists on FontCatalog to
// report it through, and every real caller (BlockFlowContext/InlineFlowContext/IntrinsicSizer)
// only ever reaches select() via FontFamilyResolver, which already guards with hasFamily() first
// (see FontFamilyResolver's docblock). So an unregistered, non-'default' family reaching select()
// is an internal invariant violation, not a legitimate "unknown font-family" path -- adjudicated
// to throw instead of silently substituting the wrong font.
it('throws FontException when asked for a family that was never registered at all (internal invariant violation)', function (): void {
    $catalog = FontCatalog::withDefaults();

    expect(fn() => $catalog->select('unknown-family', 400, false))
        ->toThrow(FontException::class, "select() called for family 'unknown-family', which was never registered");
});

it('loads each font file once', function (): void {
    $catalog = FontCatalog::withDefaults();

    $regular = $catalog->select('default', 400, false);
    $again = $catalog->select('default', 400, false);

    expect($again->font)->toBe($regular->font);
});

it('exposes distinct metrics per face', function (): void {
    $catalog = FontCatalog::withDefaults();

    $regular = $catalog->select('default', 400, false);
    $bold = $catalog->select('default', 700, false);

    $regularAdvance = $regular->font->advanceOf($regular->font->glyphId(0x41));
    $boldAdvance = $bold->font->advanceOf($bold->font->glyphId(0x41));

    expect($boldAdvance)->toBeGreaterThan($regularAdvance);
});

it('resolves a face back from its own key', function (): void {
    $catalog = FontCatalog::withDefaults();
    $selected = $catalog->select('default', 700, true);

    $byKey = $catalog->faceByKey($selected->key);

    expect($byKey)->toBe($selected); // misma instancia (cache de usedFaces)
    expect($byKey->key)->toBe('default:700:italic');
});

it('lists the faces used for embedding', function (): void {
    $catalog = FontCatalog::withDefaults();
    $catalog->select('default', 400, false);
    $catalog->select('default', 700, true);

    $faces = $catalog->faces();

    $keys = array_map(fn(FontFace $f): string => $f->key, $faces);
    expect($keys)->toContain('default:400:normal');
    expect($keys)->toContain('default:700:italic');
});

// --- M7-T2: monospace/serif registered by default + hasFamily() -------------------------------

it('registers monospace (DejaVu Sans Mono) and serif (DejaVu Serif) by default, regular + bold + italic + bold-italic', function (): void {
    $catalog = FontCatalog::withDefaults();

    $mono = $catalog->select('monospace', 400, false);
    expect($mono->key)->toBe('monospace:400:normal');
    expect($mono->font->bytes())->toBe(file_get_contents(fontCatalogFixturesDir() . '/DejaVuSansMono.ttf'));

    $monoBold = $catalog->select('monospace', 700, false);
    expect($monoBold->key)->toBe('monospace:700:normal');

    $monoItalic = $catalog->select('monospace', 400, true);
    expect($monoItalic->key)->toBe('monospace:400:italic');

    $serif = $catalog->select('serif', 400, false);
    expect($serif->key)->toBe('serif:400:normal');
    expect($serif->font->bytes())->toBe(file_get_contents(fontCatalogFixturesDir() . '/DejaVuSerif.ttf'));

    $serifBoldItalic = $catalog->select('serif', 700, true);
    expect($serifBoldItalic->key)->toBe('serif:700:italic');
});

it('hasFamily() is case-insensitive and reflects only what was actually registered', function (): void {
    $catalog = FontCatalog::withDefaults();

    expect($catalog->hasFamily('default'))->toBeTrue();
    expect($catalog->hasFamily('DEFAULT'))->toBeTrue();
    expect($catalog->hasFamily('monospace'))->toBeTrue();
    expect($catalog->hasFamily('Serif'))->toBeTrue();
    expect($catalog->hasFamily('Arial'))->toBeFalse();

    $catalog->register('Arial', 400, false, fontCatalogFixturesDir() . '/DejaVuSans.ttf');
    expect($catalog->hasFamily('arial'))->toBeTrue();
});

// --- select()/register() case-insensitivity end to end (reviewer-verified defect) --------------

it('selects a face registered under a differently-cased family name', function (): void {
    $catalog = new FontCatalog();
    $catalog->register('MiSerif', 400, false, fontCatalogFixturesDir() . '/DejaVuSerif.ttf');

    $face = $catalog->select('miserif', 400, false);

    expect($face->font->bytes())->toBe(
        file_get_contents(fontCatalogFixturesDir() . '/DejaVuSerif.ttf'),
    );
});

it('selects a face registered lowercase when asked with the original author casing', function (): void {
    $catalog = new FontCatalog();
    $catalog->register('miserif', 400, false, fontCatalogFixturesDir() . '/DejaVuSerif.ttf');

    $face = $catalog->select('MiSerif', 400, false);

    expect($face->font->bytes())->toBe(
        file_get_contents(fontCatalogFixturesDir() . '/DejaVuSerif.ttf'),
    );
});
