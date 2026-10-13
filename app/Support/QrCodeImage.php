<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Throwable;

/**
 * A QR code as a PNG data URI.
 *
 * The certificate template carries a QR code in its artwork, which is a picture
 * of one — it points nowhere. This draws a real one over it, encoding the
 * verification URL for that business.
 *
 * Painted with GD straight from the encoder's matrix rather than through a
 * renderer package: `imagick` is not installed here, and dompdf does not render
 * inline SVG reliably, so the two obvious routes are both closed. GD is already
 * a dependency — the seal itself is drawn with it.
 */
final class QrCodeImage
{
    /**
     * @param  int  $size  Target width in pixels; the module size is rounded
     *                     down to a whole number so the code stays crisp.
     */
    public static function dataUri(string $text, int $size = 320, int $quietZone = 2): ?string
    {
        if ('' === mb_trim($text)) {
            return null;
        }

        try {
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)
                ->getMatrix();
        } catch (Throwable) {
            // A certificate without a QR code is still a certificate.
            return null;
        }

        $modules = $matrix->getWidth();
        $scale = max(1, (int) floor($size / ($modules + $quietZone * 2)));
        $side = ($modules + $quietZone * 2) * $scale;

        $image = imagecreatetruecolor($side, $side);

        if (false === $image) {
            return null;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 17, 17, 17);
        imagefilledrectangle($image, 0, 0, $side, $side, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if (0 === $matrix->get($x, $y)) {
                    continue;
                }

                $left = ($x + $quietZone) * $scale;
                $top = ($y + $quietZone) * $scale;

                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
