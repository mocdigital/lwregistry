<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboSeal;
use App\Support\SboSealIssuer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class SboSealImageController extends Controller
{
    public function __invoke(SboSeal $seal): StreamedResponse
    {
        $seal->loadMissing('sbo');

        if (! $seal->existsOnDisk()) {
            try {
                $seal = app(SboSealIssuer::class)->issueFor($seal->sbo);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        abort_unless($seal->existsOnDisk(), 404);

        return Storage::disk('public')->response(
            $seal->image_path,
            $seal->downloadName(),
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ],
            'inline',
        );
    }
}
