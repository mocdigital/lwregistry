<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\SboSeal;
use GdImage;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

final class SboSealIssuer
{
    private const TEMPLATE_WIDTH = 1254;

    private const TEMPLATE_HEIGHT = 1254;

    /**
     * Coordinates are measured against the source seal image. They scale when
     * the template size changes, keeping the replacement text on the plaque.
     *
     * @var array<string, array{x: int, y: int, w: int, h: int}>
     */
    private const PLAQUE_FIELDS = [
        'prefix' => ['x' => 324, 'y' => 736, 'w' => 167, 'h' => 55],
        'year' => ['x' => 494, 'y' => 736, 'w' => 184, 'h' => 55],
        'serial' => ['x' => 681, 'y' => 736, 'w' => 239, 'h' => 55],
    ];

    /**
     * Issue (or upgrade) an SBO's seal.
     *
     * Paying the registration fee earns a **provisional** seal straight away —
     * the member has paid, and holding the artifact back while compliance
     * reviews documents left them with nothing to show. It becomes
     * **verified** once the registration is approved and the documents are
     * compliant, at which point the badge is re-rendered.
     *
     * Previously this refused outright unless `isFullyVerified()`, so nothing
     * existed to download until the very end.
     */
    public function issueFor(Sbo $sbo): SboSeal
    {
        if ( ! $sbo->feesPaid()) {
            throw new LogicException('SBO seals are issued once the registration fee is paid.');
        }

        [$year, $serial] = $this->numberParts($sbo);
        $issuedNumber = 'MBN-' . $year . '-' . $serial;
        $status = $sbo->isFullyVerified() ? SboSeal::STATUS_VERIFIED : SboSeal::STATUS_PROVISIONAL;

        // Status is in the filename so upgrading cannot serve a cached
        // provisional badge from the verified record's path.
        $path = 'sbo-seals/' . $issuedNumber . '-' . $status . '.png';

        $seal = $sbo->seal()->first();

        // Already at this status with the file present: nothing to redraw.
        if ($seal && $seal->status === $status && $seal->existsOnDisk()) {
            return $seal;
        }

        $this->renderSeal($issuedNumber, $year, $serial, $path, $status);

        // The provisional image is replaced, not orphaned on disk.
        if ($seal && $seal->image_path !== $path) {
            $seal->deleteFile();
        }

        return $sbo->seal()->updateOrCreate(
            ['sbo_id' => $sbo->id],
            [
                'issued_number' => $issuedNumber,
                'issued_year' => $year,
                'serial_number' => $serial,
                'image_path' => $path,
                'status' => $status,
                // The issue date is when it was paid for; upgrading must not
                // move it.
                'issued_at' => $seal?->issued_at ?? now(),
                'verified_at' => SboSeal::STATUS_VERIFIED === $status
                    ? ($seal?->verified_at ?? now())
                    : null,
            ]
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function numberParts(Sbo $sbo): array
    {
        if (is_string($sbo->application_id) && preg_match('/^MBN-(\d{4})-(.+)$/', $sbo->application_id, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [now()->format('Y'), mb_str_pad((string) $sbo->id, 4, '0', STR_PAD_LEFT)];
    }

    private function renderSeal(string $issuedNumber, string $year, string $serial, string $path, string $status): void
    {
        if ( ! extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required to generate SBO seals.');
        }

        $image = $this->loadTemplate() ?? $this->createFallbackTemplate();

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $this->drawPersonalizedPlaque($image, $year, $serial);

        /*
         * A provisional seal is visibly distinct — it is a real artifact, but
         * it must not read as a completed verification to anyone shown it.
         */
        if (SboSeal::STATUS_PROVISIONAL === $status) {
            $this->drawProvisionalBanner($image);
        }

        Storage::disk('public')->makeDirectory(dirname($path));

        $absolutePath = Storage::disk('public')->path($path);

        if ( ! imagepng($image, $absolutePath, 9)) {
            throw new RuntimeException('Unable to write generated SBO seal: ' . $issuedNumber);
        }
    }

    private function loadTemplate(): ?GdImage
    {
        $template = resource_path('images/sbo-seal-template.png');

        if ( ! is_file($template)) {
            return null;
        }

        $image = imagecreatefrompng($template);

        if ( ! $image instanceof GdImage) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private function createFallbackTemplate(): GdImage
    {
        $image = imagecreatetruecolor(self::TEMPLATE_WIDTH, self::TEMPLATE_HEIGHT);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagealphablending($image, true);

        $bronze = imagecolorallocate($image, 122, 69, 35);
        $dark = imagecolorallocate($image, 50, 31, 23);
        $gold = imagecolorallocate($image, 220, 172, 91);
        $cream = imagecolorallocate($image, 248, 236, 205);

        imagefilledellipse($image, 627, 627, 1040, 1040, $bronze);
        imagefilledellipse($image, 627, 627, 900, 900, $dark);
        imageellipse($image, 627, 627, 1040, 1040, $gold);
        imageellipse($image, 627, 627, 900, 900, $gold);

        $font = $this->fontPath();
        $this->drawCenteredText($image, 'LOVEWORLD REGISTRY', 277, 245, 700, 80, 34, $font, $cream);
        $this->drawCenteredText($image, 'MINISTRY OF COMMERCE', 315, 640, 620, 52, 25, $font, $cream);

        return $image;
    }

    private function drawPersonalizedPlaque(GdImage $image, string $year, string $serial): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scaleX = $width / self::TEMPLATE_WIDTH;
        $scaleY = $height / self::TEMPLATE_HEIGHT;

        $fill = imagecolorallocate($image, 54, 35, 23);
        $border = imagecolorallocate($image, 190, 143, 78);
        $innerBorder = imagecolorallocate($image, 105, 72, 42);
        $text = imagecolorallocate($image, 246, 227, 181);
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, 35);
        $font = $this->fontPath();

        $values = [
            'prefix' => 'MBN',
            'year' => $year,
            'serial' => $serial,
        ];

        foreach ($values as $key => $value) {
            $field = self::PLAQUE_FIELDS[$key];
            $x = (int) round($field['x'] * $scaleX);
            $y = (int) round($field['y'] * $scaleY);
            $w = (int) round($field['w'] * $scaleX);
            $h = (int) round($field['h'] * $scaleY);

            imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $fill);
            imagerectangle($image, $x, $y, $x + $w, $y + $h, $border);
            imagerectangle($image, $x + 4, $y + 4, $x + $w - 4, $y + $h - 4, $innerBorder);

            $fontSize = 'serial' === $key ? 27 : 31;
            $this->drawCenteredText($image, $value, $x, $y + 1, $w, $h, $fontSize, $font, $shadow, 2, 2);
            $this->drawCenteredText($image, $value, $x, $y, $w, $h, $fontSize, $font, $text);
        }
    }

    /**
     * A band across the foot of the badge reading PROVISIONAL.
     *
     * Deliberately unmissable: this seal is real and downloadable, but the
     * registration behind it is still under review, and it must not be
     * mistaken for a completed verification by whoever is shown it.
     */
    private function drawProvisionalBanner(GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $height / self::TEMPLATE_HEIGHT;

        $bandHeight = (int) round(96 * $scale);
        $top = (int) round($height - ($bandHeight * 1.6));

        $band = imagecolorallocatealpha($image, 122, 69, 35, 30);
        $edge = imagecolorallocate($image, 220, 172, 91);
        $text = imagecolorallocate($image, 248, 236, 205);

        imagefilledrectangle($image, 0, $top, $width, $top + $bandHeight, $band);
        imagerectangle($image, 0, $top, $width - 1, $top + $bandHeight, $edge);

        $this->drawCenteredText(
            $image,
            'PROVISIONAL',
            0,
            $top,
            $width,
            $bandHeight,
            (int) round(34 * $scale),
            $this->fontPath(),
            $text,
        );
    }

    private function drawCenteredText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $width,
        int $height,
        int $fontSize,
        ?string $font,
        int $color,
        int $offsetX = 0,
        int $offsetY = 0,
    ): void {
        if (null !== $font) {
            $box = imagettfbbox($fontSize, 0, $font, $text);

            if (is_array($box)) {
                $textWidth = abs($box[2] - $box[0]);
                $textHeight = abs($box[7] - $box[1]);
                $textX = $x + (int) (($width - $textWidth) / 2) + $offsetX;
                $textY = $y + (int) (($height + $textHeight) / 2) - 3 + $offsetY;

                imagettftext($image, $fontSize, 0, $textX, $textY, $color, $font, $text);

                return;
            }
        }

        $textX = $x + (int) (($width - imagefontwidth(5) * mb_strlen($text)) / 2) + $offsetX;
        $textY = $y + (int) (($height - imagefontheight(5)) / 2) + $offsetY;
        imagestring($image, 5, $textX, $textY, $text, $color);
    }

    private function fontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/Georgia-Bold.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Georgia Bold.ttf',
            '/System/Library/Fonts/Supplemental/Times New Roman Bold.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
