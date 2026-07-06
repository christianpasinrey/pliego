<?php

declare(strict_types=1);

namespace Pliego\Image;

/** Formato-agnóstico: JPEG y PNG decodificados exponen la misma interfaz de consumo (Pdf\ImageRegistry, M3+). */
interface DecodedImage
{
    /** px de imagen = px CSS (96dpi asumido). */
    public function widthPx(): int;

    public function heightPx(): int;

    /** Datos listos para el content stream del XObject. */
    public function pdfData(): ImagePdfData;
}
