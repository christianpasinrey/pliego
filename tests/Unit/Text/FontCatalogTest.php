<?php

declare(strict_types=1);

use Pliego\Text\FontCatalog;
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

it('falls back to the default family', function (): void {
    $catalog = FontCatalog::withDefaults();

    $face = $catalog->select('unknown-family', 400, false);

    expect($face->key)->toBe('default:400:normal');
    expect($face->font->bytes())->toBe(
        file_get_contents(fontCatalogFixturesDir() . '/DejaVuSans.ttf'),
    );
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

it('lists the faces used for embedding', function (): void {
    $catalog = FontCatalog::withDefaults();
    $catalog->select('default', 400, false);
    $catalog->select('default', 700, true);

    $faces = $catalog->faces();

    $keys = array_map(fn(FontFace $f): string => $f->key, $faces);
    expect($keys)->toContain('default:400:normal');
    expect($keys)->toContain('default:700:italic');
});
