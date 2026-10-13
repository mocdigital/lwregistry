<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GbpcConferenceRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Global Business Prayer Conference registrations from the GBPC
 * landing page (gbpc.lwregistry.org).
 *
 * Public and CSRF-exempt: the page is a static site on another host, so it
 * cannot carry a session CSRF token. It is cross-origin, so CORS allows the
 * gbpc subdomain (see config/cors.php), and the route is rate-limited.
 */
final class GbpcConferenceRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:50'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'kingschat_handle' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'country_city' => ['required', 'string', 'max:255'],
            'zone' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'owns_business' => ['required', 'string', 'max:10'],
            // Required only when they said they own a business.
            'business_name' => ['nullable', 'required_if:owns_business,Yes', 'string', 'max:255'],
            'expectations' => ['required', 'string', 'max:5000'],
        ]);

        GbpcConferenceRegistration::create($data);

        return response()->json([
            'ok' => true,
            'message' => 'Thank you! Your registration has been received.',
        ], 201);
    }
}
