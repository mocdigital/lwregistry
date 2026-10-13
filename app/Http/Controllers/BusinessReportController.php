<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BusinessReportController extends Controller
{
    public function store(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(BusinessReport::reasonOptions()))],
            'details' => ['nullable', 'string', 'max:5000'],
            'contact' => ['nullable', 'boolean'],
        ]);

        if ($business->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot report your own business.',
            ], 403);
        }

        $report = BusinessReport::create([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'contact_requested' => (bool) ($data['contact'] ?? false),
            'status' => BusinessReport::STATUS_OPEN,
        ]);

        return response()->json([
            'message' => 'Your report has been submitted.',
            'report' => $report,
        ], 201);
    }
}
