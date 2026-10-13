<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\GbpcLiveStream;
use Illuminate\Http\JsonResponse;

/**
 * The current GBPC live-stream URL, for the landing page (gbpc.lwregistry.org)
 * to fetch and play. Public and CORS-scoped to the gbpc subdomain (see
 * config/cors.php), the same as the conference-registration endpoint.
 */
final class GbpcStreamUrlController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['url' => GbpcLiveStream::url()]);
    }
}
