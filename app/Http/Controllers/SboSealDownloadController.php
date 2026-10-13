<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboSeal;
use App\Support\AdminCapabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SboSealDownloadController extends Controller
{
    public function __invoke(Request $request, SboSeal $seal): StreamedResponse
    {
        $user = $request->user();
        abort_unless(null !== $user, 403);

        $seal->loadMissing('sbo');

        $isOwner = $seal->sbo->created_by === $user->id;
        $isComplianceAdmin = $user->hasPermission(AdminCapabilities::APPROVE_SBOS);

        abort_unless($isOwner || $isComplianceAdmin, 403);
        abort_unless($seal->existsOnDisk(), 404);

        return Storage::disk('public')->download(
            $seal->image_path,
            $seal->downloadName(),
            ['Content-Type' => 'image/png']
        );
    }
}
