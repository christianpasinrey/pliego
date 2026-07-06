<?php

declare(strict_types=1);

namespace Pliego\Image;

/**
 * JPEG (ITU-T T.81) passthrough: el fichero completo se embebe tal cual (filtro DCTDecode,
 * ISO 32000-1 §7.4.8) — el decodificador solo necesita caminar los marcadores hasta encontrar
 * un SOF0/SOF1/SOF2 (baseline / extended-sequential / progressive) para conocer dimensiones y
 * número de componentes. El resto de marcadores (DQT, DHT, APPn, ...) se saltan por longitud;
 * progresivo (SOF2) está permitido — el visor PDF lo decodifica igual que baseline.
 */
final readonly class JpegImage implements DecodedImage
{
    private function __construct(
        private int $width,
        private int $height,
        private ImagePdfData $pdfData,
    ) {}

    public static function fromBytes(string $bytes): self
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            throw new ImageException('Not a valid JPEG file: missing SOI marker (0xFFD8).');
        }

        $length = strlen($bytes);
        $pos = 2;
        $width = null;
        $height = null;
        $components = null;

        while ($pos < $length) {
            if (ord($bytes[$pos]) !== 0xFF) {
                throw new ImageException("Malformed JPEG: expected marker at byte offset $pos.");
            }
            $pos++;
            while ($pos < $length && ord($bytes[$pos]) === 0xFF) {
                $pos++; // fill bytes (ITU-T T.81 §B.1.1.3)
            }
            if ($pos >= $length) {
                break;
            }
            $marker = ord($bytes[$pos]);
            $pos++;

            if ($marker === 0xD9 || $marker === 0xDA) {
                break; // EOI, or SOS: entropy-coded scan data follows, no more headers to read
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue; // TEM / RSTn: standalone markers, no length field
            }
            if ($pos + 2 > $length) {
                break;
            }
            $segmentLength = (ord($bytes[$pos]) << 8) | ord($bytes[$pos + 1]);

            $isSof = $marker === 0xC0 || $marker === 0xC1 || $marker === 0xC2;
            if ($isSof) {
                if ($pos + 7 >= $length) {
                    throw new ImageException('Malformed JPEG: SOF segment shorter than expected.');
                }
                // SOF payload (offsets relative to the marker's 0xFF byte, ITU-T T.81 §B.2.2):
                // 0-1 marker, 2-3 Lf, 4 precision, 5-6 height, 7-8 width, 9 component count.
                $height = (ord($bytes[$pos + 3]) << 8) | ord($bytes[$pos + 4]);
                $width = (ord($bytes[$pos + 5]) << 8) | ord($bytes[$pos + 6]);
                $components = ord($bytes[$pos + 7]);
                break;
            }
            $pos += $segmentLength;
        }

        if ($width === null || $height === null || $components === null) {
            throw new ImageException('Malformed JPEG: no SOF0/SOF1/SOF2 marker found.');
        }
        $colorSpace = match ($components) {
            1 => 'DeviceGray',
            3 => 'DeviceRGB',
            4 => throw new ImageException('CMYK JPEG is not supported (M3).'),
            default => throw new ImageException("Unsupported JPEG component count: $components."),
        };

        return new self($width, $height, new ImagePdfData(
            filter: 'DCTDecode',
            colorSpace: $colorSpace,
            bitsPerComponent: 8,
            bytes: $bytes,
            smaskBytes: null,
        ));
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
