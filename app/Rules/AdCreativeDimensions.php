<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded ad creative must fit the slot it is for (from config/ads.php).
 *
 * "Fit" is the slot's shape at or above its size — not its exact pixels. The
 * old rule demanded an image be precisely, say, 300x250, so a perfectly good
 * 1375x1144 export of the same proportions was rejected and the advertiser
 * could not submit at all. What actually matters is that the banner is not the
 * wrong shape (it would be letterboxed or cropped in the slot) and not smaller
 * than the slot (it would be upscaled and blur), so those are what is checked.
 */
final class AdCreativeDimensions implements ValidationRule
{
    /** How far the image's aspect ratio may drift from the slot's, as a fraction. */
    private const RATIO_TOLERANCE = 0.02;

    public function __construct(
        private readonly string $slot,
        private readonly bool $mobile = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $config = config('ads.slots.' . $this->slot);

        if ( ! $config) {
            $fail('Invalid ad slot selected.');

            return;
        }

        if ( ! $value instanceof UploadedFile) {
            $fail('A valid image file is required.');

            return;
        }

        $dimensions = @getimagesize($value->getRealPath());

        if (false === $dimensions) {
            $fail('The uploaded file is not a valid image.');

            return;
        }

        [$width, $height] = $dimensions;

        $requiredWidth = $config['width'];
        $requiredHeight = $config['height'];

        if ($this->mobile) {
            $mobile = $config['mobile'] ?? null;

            if ( ! is_array($mobile) || 2 !== count($mobile)) {
                $fail('This ad slot does not define a mobile creative size.');

                return;
            }

            [$requiredWidth, $requiredHeight] = $mobile;
        }

        // Below the slot's size the image would be upscaled to fill it, which
        // blurs — so the slot dimensions are the minimum, not the exact size.
        if ((int) $width < (int) $requiredWidth || (int) $height < (int) $requiredHeight) {
            $fail(sprintf(
                'The :attribute must be at least %dx%d pixels for the "%s" slot (uploaded %dx%d).',
                $requiredWidth,
                $requiredHeight,
                $config['label'] ?? $this->slot,
                $width,
                $height,
            ));

            return;
        }

        // Any resolution is fine as long as it is the slot's shape; a banner in
        // the wrong proportion is cropped or letterboxed, so that is refused.
        $required = (int) $requiredWidth / (int) $requiredHeight;
        $actual = (int) $width / (int) $height;

        if (abs($actual - $required) > $required * self::RATIO_TOLERANCE) {
            $fail(sprintf(
                'The :attribute must match the %d×%d proportions of the "%s" slot (uploaded %dx%d). Crop it to the same shape.',
                $requiredWidth,
                $requiredHeight,
                $config['label'] ?? $this->slot,
                $width,
                $height,
            ));
        }
    }
}
