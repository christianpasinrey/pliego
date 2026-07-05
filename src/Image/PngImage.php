<?php

declare(strict_types=1);

namespace Pliego\Image;

/**
 * PNG (RFC 2083), pure PHP + ext-zlib: firma + IHDR (dims/bitDepth/colorType/interlace), IDAT
 * concatenados e inflados con zlib_decode(), des-filtrado por scanline (tipos 0-4, §6.2/§6.3)
 * y, para RGBA, separación del canal alfa en un SMask de 8 bits en escala de grises
 * (ISO 32000-1 §11.6.5.3) — ambos planos se re-comprimen con zlib_encode() (RFC 1950 zlib,
 * ZLIB_ENCODING_DEFLATE) para el filtro FlateDecode del PDF.
 *
 * Soportado (M3): bit depth 8; color type 0 (gray), 2 (RGB), 6 (RGBA); sin interlace.
 * No soportado (ImageException M3, documentado): bit depth != 8, color type 3 (paleta) o
 * cualquier otro no listado (p.ej. 4, gray+alpha), interlace Adam7.
 */
final readonly class PngImage implements DecodedImage
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private function __construct(
        private int $width,
        private int $height,
        private ImagePdfData $pdfData,
    ) {}

    public static function fromBytes(string $bytes): self
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            throw new ImageException('Not a valid PNG file: missing signature.');
        }

        [$width, $height, $bitDepth, $colorType, $interlace, $idat] = self::readChunks($bytes);

        if ($width === null || $height === null || $bitDepth === null || $colorType === null || $interlace === null) {
            throw new ImageException('Malformed PNG: missing IHDR chunk.');
        }
        if ($bitDepth !== 8) {
            throw new ImageException("Unsupported PNG bit depth: $bitDepth (only 8-bit is supported in M3).");
        }
        if ($interlace !== 0) {
            throw new ImageException('Interlaced PNG (Adam7) is not supported (M3).');
        }
        $channels = match ($colorType) {
            0 => 1, // gray
            2 => 3, // RGB
            6 => 4, // RGBA
            3 => throw new ImageException('Indexed/palette PNG (color type 3) is not supported (M3).'),
            default => throw new ImageException("Unsupported PNG color type: $colorType."),
        };

        if ($idat === '') {
            throw new ImageException('Malformed PNG: no IDAT chunk found.');
        }
        $raw = zlib_decode($idat);
        if ($raw === false) {
            throw new ImageException('Malformed PNG: could not inflate IDAT data (invalid zlib stream).');
        }

        $stride = $width * $channels;
        $pixels = self::unfilter($raw, $height, $channels, $stride);

        $pdfData = $channels === 4
            ? self::rgbaPdfData($pixels, $width, $height)
            : new ImagePdfData(
                filter: 'FlateDecode',
                colorSpace: $channels === 1 ? 'DeviceGray' : 'DeviceRGB',
                bitsPerComponent: 8,
                bytes: self::deflate($pixels),
                smaskBytes: null,
            );

        return new self($width, $height, $pdfData);
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?int, 4: ?int, 5: string}
     *         [width, height, bitDepth, colorType, interlace, concatenated IDAT data]
     */
    private static function readChunks(string $bytes): array
    {
        $length = strlen($bytes);
        $pos = 8; // past the 8-byte signature
        $width = null;
        $height = null;
        $bitDepth = null;
        $colorType = null;
        $interlace = null;
        $idat = '';

        while ($pos + 8 <= $length) {
            $chunkLength = self::uint32($bytes, $pos);
            $type = substr($bytes, $pos + 4, 4);
            $dataStart = $pos + 8;
            if ($dataStart + $chunkLength > $length) {
                throw new ImageException('Malformed PNG: chunk length exceeds file size.');
            }
            $data = substr($bytes, $dataStart, $chunkLength);

            if ($type === 'IHDR') {
                if ($chunkLength !== 13) {
                    throw new ImageException('Malformed PNG: IHDR chunk has an unexpected length.');
                }
                $width = self::uint32($data, 0);
                $height = self::uint32($data, 4);
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
                $interlace = ord($data[12]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }

            $pos = $dataStart + $chunkLength + 4; // + 4-byte CRC
        }

        return [$width, $height, $bitDepth, $colorType, $interlace, $idat];
    }

    /**
     * Des-filtra cada scanline (RFC 2083 §6.3: None/Sub/Up/Average/Paeth), devolviendo los
     * bytes de píxel reconstruidos, concatenados sin bytes de filtro.
     */
    private static function unfilter(string $raw, int $height, int $channels, int $stride): string
    {
        $out = '';
        $prior = str_repeat("\x00", $stride);
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            $filterType = ord($raw[$offset]);
            $rowRaw = substr($raw, $offset + 1, $stride);
            $offset += 1 + $stride;

            $recon = '';
            for ($i = 0; $i < $stride; $i++) {
                $x = ord($rowRaw[$i]);
                $a = $i >= $channels ? ord($recon[$i - $channels]) : 0;
                $b = ord($prior[$i]);
                $c = $i >= $channels ? ord($prior[$i - $channels]) : 0;

                $value = match ($filterType) {
                    0 => $x,
                    1 => $x + $a,
                    2 => $x + $b,
                    3 => $x + intdiv($a + $b, 2),
                    4 => $x + self::paethPredictor($a, $b, $c),
                    default => throw new ImageException("Unsupported PNG filter type: $filterType."),
                };
                $recon .= chr($value & 0xFF);
            }
            $out .= $recon;
            $prior = $recon;
        }
        return $out;
    }

    /** Paeth predictor, RFC 2083 §6.6 (verbatim). */
    private static function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        return $pb <= $pc ? $b : $c;
    }

    /** Separa RGBA intercalado en un plano RGB y un SMask (ISO 32000-1 §11.6.5.3), ambos re-deflated. */
    private static function rgbaPdfData(string $pixels, int $width, int $height): ImagePdfData
    {
        $rgb = '';
        $alpha = '';
        $pixelCount = $width * $height;
        for ($i = 0; $i < $pixelCount; $i++) {
            $base = $i * 4;
            $rgb .= substr($pixels, $base, 3);
            $alpha .= $pixels[$base + 3];
        }
        return new ImagePdfData(
            filter: 'FlateDecode',
            colorSpace: 'DeviceRGB',
            bitsPerComponent: 8,
            bytes: self::deflate($rgb),
            smaskBytes: self::deflate($alpha),
        );
    }

    private static function deflate(string $data): string
    {
        $encoded = zlib_encode($data, ZLIB_ENCODING_DEFLATE);
        return $encoded !== false ? $encoded : throw new ImageException('zlib_encode failed while re-compressing PNG data.');
    }

    private static function uint32(string $bytes, int $offset): int
    {
        /** @var array{1: int} $v */
        $v = unpack('N', substr($bytes, $offset, 4));
        return $v[1];
    }

    public function widthPx(): int
    {
        return $this->width;
    }

    public function heightPx(): int
    {
        return $this->height;
    }

    public function pdfData(): ImagePdfData
    {
        return $this->pdfData;
    }
}
