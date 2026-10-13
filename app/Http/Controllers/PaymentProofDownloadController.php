<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SboPaymentProof;
use App\Support\AdminCapabilities;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentProofDownloadController extends Controller
{
    public function __invoke(Request $request, SboPaymentProof $proof): StreamedResponse
    {
        $user = $request->user();
        abort_unless(null !== $user, 403);

        $isOwner = $proof->sbo->created_by === $user->id;
        $isComplianceAdmin = $user->hasPermission(AdminCapabilities::APPROVE_SBOS);

        abort_unless($isOwner || $isComplianceAdmin, 403);

        $media = $proof->file();
        abort_if(null === $media, 404);

        return $media->toResponse($request);
    }
}
