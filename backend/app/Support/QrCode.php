<?php

namespace App\Support;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCode
{
    /** SVG markup for a QR code. Vector, so it prints sharp at any size. */
    public static function svg(string $text, int $size = 260): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        $svg = $writer->writeString($text);

        // Drop the XML prolog so it can be placed inside HTML.
        return trim((string) preg_replace('/^<\?xml.*?\?>/s', '', $svg));
    }
}
