<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GbpcConferenceRegistration;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download the GBPC conference registrations as CSV.
 */
final class GbpcRegistrationExportController extends Controller
{
    private const HEADERS = [
        'Title', 'Full name', 'Mobile phone', 'KingsChat username', 'Email',
        'Country/City', 'Zone', 'Occupation', 'Owns a business', 'Business name',
        'Expectations', 'Registered',
    ];

    public function __invoke(): StreamedResponse
    {
        $stamp = now()->format('Y-m-d');

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS);

            GbpcConferenceRegistration::query()
                ->latest()
                ->chunk(500, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            (string) $row->title,
                            (string) $row->full_name,
                            (string) $row->phone,
                            (string) $row->kingschat_handle,
                            (string) $row->email,
                            (string) $row->country_city,
                            (string) $row->zone,
                            (string) $row->occupation,
                            (string) $row->owns_business,
                            (string) $row->business_name,
                            (string) $row->expectations,
                            (string) $row->created_at?->format('Y-m-d H:i'),
                        ]);
                    }
                });

            fclose($out);
        }, "gbpc-registrations-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
