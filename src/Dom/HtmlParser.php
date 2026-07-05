<?php

declare(strict_types=1);

namespace Pliego\Dom;

final class HtmlParser
{
    public static function parse(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }
}
