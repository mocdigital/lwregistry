<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboComplianceDocument;
use App\Support\AdminCapabilities;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ComplianceDocumentDownloadController extends Controller
{
    /**
     * Stream a compliance document from the private disk to authorized users:
     * the SBO owner who submitted it, or an admin with the approve-SBOs capability.
     */
    public function __invoke(Request $request, SboComplianceDocument $document): StreamedResponse
    {
        $user = $request->user();
        abort_unless(null !== $user, 403);

        $sbo = $document->sbo;
        abort_if(null === $sbo, 404);

        $isOwner = $sbo->created_by === $user->id;
        $isComplianceAdmin = $user->hasPermission(AdminCapabilities::APPROVE_SBOS);

        abort_unless($isOwner || $isComplianceAdmin, 403);

        $media = $document->file();
        abort_if(null === $media, 404);

        // Inline view (PDFs/images open in-browser); Spatie streams from the disk.
        return $media->toResponse($request);
    }
}
