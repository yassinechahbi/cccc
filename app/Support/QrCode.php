<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

class QrCode
{
    /** PNG data URI, the format dompdf renders most reliably. */
    public static function pngDataUri(string $data, int $scale = 6): string
    {
        return (new Generator(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M, // survives a crease or a postmark smudge
            'scale' => $scale,
            'quietzoneSize' => 2,
        ])))->render($data);
    }
}
