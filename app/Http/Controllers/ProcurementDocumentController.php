<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Procurement documents: uploading them, and the Documents tab's "Download
 * Selected Items".
 *
 * The tab drew checkboxes beside a hardcoded file list and a button that did
 * nothing — there was no route, and a request could only hold one file anyway.
 */
final class ProcurementDocumentController extends Controller
{
    /** Guard against a request that would zip the entire disk. */
    private const MAX_SELECTION = 50;

    /**
     * Download one selected file, or a zip of several.
     *
     * A single file is served as itself rather than a one-entry archive, which
     * is what someone ticking one box expects.
     */
    public function download(Request $request, PurchaseOrder $procurement): BinaryFileResponse|RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_SELECTION],
            'ids.*' => ['integer', 'min:0'],
        ]);

        // Query-string ids arrive as strings; cast so the sentinel 0 and the
        // real ids compare as integers below.
        $ids = array_map('intval', $data['ids']);

        /*
         * Each selected file as [path, name]. Two sources feed it: the rows in
         * the documents table, and the request's legacy single upload — which
         * has no row and is selected as the sentinel id 0. Both are scoped to
         * this request, so an id from someone else's RFQ downloads nothing.
         *
         * @var \Illuminate\Support\Collection<int, array{path: string, name: string}> $files
         */
        $files = collect();

        // The legacy single upload (purchase_orders.document_path), id 0.
        if (in_array(0, $ids, true) && filled($procurement->document_path)) {
            $legacy = Storage::disk('public')->path($procurement->document_path);

            if (is_file($legacy)) {
                $files->push(['path' => $legacy, 'name' => basename((string) $procurement->document_path)]);
            }
        }

        PurchaseOrderDocument::query()
            ->where('purchase_order_id', $procurement->id)
            ->whereIn('id', array_filter($ids, static fn (int $id): bool => $id > 0))
            ->get()
            ->filter(fn (PurchaseOrderDocument $d): bool => $d->existsOnDisk())
            ->each(function (PurchaseOrderDocument $d) use ($files): void {
                $files->push(['path' => Storage::disk('public')->path($d->path), 'name' => $d->name]);
            });

        if ($files->isEmpty()) {
            return back()->with('status', 'documents-missing');
        }

        if (1 === $files->count()) {
            $file = $files->first();

            return response()->download($file['path'], $file['name']);
        }

        return $this->zip($procurement, $files);
    }

    /**
     * Attach files to a request. The RFQ's own SBO only — a bidder attaches
     * through their bid, not to the request itself.
     */
    public function store(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        abort_unless(
            $request->user()->sbos()->pluck('id')->contains($procurement->sbo_id),
            403,
        );

        $data = $request->validate([
            'documents' => ['required', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            'section' => ['nullable', 'in:project,attachment'],
        ]);

        foreach ($request->file('documents') as $file) {
            PurchaseOrderDocument::create([
                'purchase_order_id' => $procurement->id,
                'section' => $data['section'] ?? PurchaseOrderDocument::SECTION_PROJECT,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('procurement-documents', 'public'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return back()->with('status', 'documents-uploaded');
    }

    public function destroy(
        Request $request,
        PurchaseOrder $procurement,
        PurchaseOrderDocument $document,
    ): RedirectResponse {
        abort_unless(
            $request->user()->sbos()->pluck('id')->contains($procurement->sbo_id),
            403,
        );
        abort_if($document->purchase_order_id !== $procurement->id, 404);

        Storage::disk('public')->delete($document->path);
        $document->delete();

        return back()->with('status', 'document-deleted');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{path: string, name: string}>  $files
     */
    private function zip(PurchaseOrder $procurement, $files): BinaryFileResponse
    {
        $archive = tempnam(sys_get_temp_dir(), 'rfq-docs-');
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $used = [];

        foreach ($files as $file) {
            /*
             * Two files uploaded under the same name would otherwise overwrite
             * each other inside the archive, and the download would silently be
             * short.
             */
            $name = $file['name'];
            $i = 1;

            while (isset($used[mb_strtolower($name)])) {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $base = pathinfo($file['name'], PATHINFO_FILENAME);
                $name = $base . ' (' . $i++ . ')' . ($extension ? '.' . $extension : '');
            }

            $used[mb_strtolower($name)] = true;
            $zip->addFile($file['path'], $name);
        }

        $zip->close();

        $filename = Str::slug($procurement->title ?: 'procurement-' . $procurement->id) . '-documents.zip';

        // deleteFileAfterSend, or the temp file survives every download.
        return response()->download($archive, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
