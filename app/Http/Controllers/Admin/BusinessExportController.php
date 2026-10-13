<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sbo;
use App\Support\AdminCapabilities;
use App\Support\DataLabels;
use App\Support\SboIndustries;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export the admin business list as CSV, Excel or PDF.
 *
 * Same scope and filters as the Organizations screen: a reviewer with the
 * approve-SBOs capability exports every business, anyone else only their own,
 * and the status and industry filters carry through as query parameters so the
 * file matches what the admin is looking at.
 */
final class BusinessExportController extends Controller
{
    /** The columns exported, in order: header => how to read it off an Sbo. */
    private const COLUMNS = [
        'Business' => 'name',
        'Entity' => 'entity',
        'Category' => 'category',
        'Industry' => 'industry',
        'Owner' => 'owner',
        'Email' => 'email',
        'Phone' => 'phone',
        'Country' => 'country',
        'State' => 'state',
        'Verification' => 'verification',
        'Payment' => 'payment',
        'Documents' => 'documents',
        'Registered' => 'registered',
    ];

    public function __invoke(Request $request): Response|StreamedResponse
    {
        $format = in_array($request->query('format'), ['csv', 'xlsx', 'pdf'], true)
            ? $request->query('format')
            : 'csv';

        $rows = $this->rows($request);
        $stamp = now()->format('Y-m-d');

        return match ($format) {
            'pdf' => $this->pdf($rows, $stamp),
            'xlsx' => $this->excel($rows, $stamp),
            default => $this->csv($rows, $stamp),
        };
    }

    /**
     * The businesses to export, scoped and filtered like the admin screen.
     *
     * @return Collection<int, array<string, string>>
     */
    private function rows(Request $request): Collection
    {
        $user = $request->user();

        $query = Sbo::query()->with('creator')->latest();

        // Reviewers see everything; other admins only what they created.
        if ( ! $user->hasPermission(AdminCapabilities::APPROVE_SBOS)) {
            $query->where('created_by', $user->id);
        }

        $status = $request->query('status');

        if (in_array($status, ['pending', 'approved', 'declined'], true)) {
            $query->where('verification_status', $status);
        }

        // Scope to one side of the directory when the SBO or COB page asks for
        // it; without the parameter the export spans both, as it always has.
        $entity = $request->query('entity');

        if (in_array($entity, [Sbo::ENTITY_SBO, Sbo::ENTITY_COB], true)) {
            $query->where('entity_type', $entity);
        }

        $industry = $request->query('industry');

        if ('all' !== $industry && in_array($industry, SboIndustries::all(), true)) {
            $query->where('industry', $industry);
        }

        // Free-text search, matching the businesses screen's box.
        $search = mb_trim((string) $request->query('search', ''));

        if ('' !== $search) {
            $term = '%' . $search . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhere('primary_contact_name', 'like', $term)
                    ->orWhere('primary_contact_email', 'like', $term)
                    ->orWhere('owner_first_name', 'like', $term)
                    ->orWhere('owner_surname', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('state', 'like', $term)
                    ->orWhere('country', 'like', $term)
                    ->orWhereHas('creator', fn ($c) => $c
                        ->where('firstname', 'like', $term)
                        ->orWhere('lastname', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }

        return $query->get()->map(fn (Sbo $sbo): array => [
            'name' => (string) $sbo->name,
            'entity' => (string) ($sbo->entity_type ?: Sbo::ENTITY_SBO),
            'category' => (string) DataLabels::get(DataLabels::CATEGORY, $sbo->category),
            'industry' => (string) $sbo->industry,
            'owner' => (string) ($sbo->primary_contact_name ?: $sbo->creator?->name ?: ''),
            'email' => (string) ($sbo->primary_contact_email ?: $sbo->creator?->email ?: ''),
            'phone' => (string) $sbo->phone,
            'country' => (string) DataLabels::country($sbo->country),
            'state' => (string) $sbo->state,
            'verification' => (string) ($sbo->verification_status ?? ''),
            'payment' => (string) ($sbo->payment_status ?? ''),
            'documents' => (string) ($sbo->documents_status ?? ''),
            'registered' => (string) $sbo->created_at?->format('Y-m-d'),
        ]);
    }

    private function csv(Collection $rows, string $stamp): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 accents (café, Zürich) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys(self::COLUMNS));

            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, "businesses-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * An Excel-openable workbook. Served as a SpreadsheetML table rather than
     * pulling in a spreadsheet library — Excel reads it as a real sheet.
     */
    private function excel(Collection $rows, string $stamp): Response
    {
        $html = view('exports.businesses', [
            'headers' => array_keys(self::COLUMNS),
            'rows' => $rows,
            'generatedAt' => now()->format('M j, Y H:i'),
            'forExcel' => true,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"businesses-{$stamp}.xls\"",
        ]);
    }

    private function pdf(Collection $rows, string $stamp): Response
    {
        $pdf = Pdf::loadView('exports.businesses', [
            'headers' => array_keys(self::COLUMNS),
            'rows' => $rows,
            'generatedAt' => now()->format('M j, Y H:i'),
            'forExcel' => false,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("businesses-{$stamp}.pdf");
    }
}
