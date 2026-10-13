<?php

declare(strict_types=1);

use App\Models\SboPaymentProof;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Move payment proofs out of the collection nobody reads.
 *
 * The two SPA upload paths filed the receipt under the literal 'proofs' while
 * SboPaymentProof registers 'payment_proof'. Nothing reads 'proofs': the admin
 * proof row, the Payments approval queue and the secure download all go through
 * SboPaymentProof::file(), which asks for the registered collection. So every
 * manual payment made through the Vue application showed as "Not uploaded" and
 * could not be approved, while the fee line beside it said "Proof submitted" —
 * the two read different sources.
 *
 * The misfiled ones also went to the PUBLIC disk, because only the registered
 * collection carries ->useDisk('compliance'). This moves the bytes onto the
 * private disk as it re-points the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('media')
            ->where('model_type', SboPaymentProof::class)
            ->where('collection_name', 'proofs')
            ->get();

        foreach ($rows as $row) {
            $from = Storage::disk($row->disk);
            $to = Storage::disk('compliance');
            $path = $row->id . '/' . $row->file_name;

            /*
             * Copy before re-pointing, and only delete the original once the
             * copy is in place: a half-moved receipt is evidence somebody is
             * waiting on, and there is no second copy of it anywhere.
             */
            if ($row->disk !== 'compliance' && $from->exists($path)) {
                $to->put($path, $from->get($path));
            }

            DB::table('media')
                ->where('id', $row->id)
                ->update([
                    'collection_name' => SboPaymentProof::COLLECTION,
                    'disk' => 'compliance',
                    'conversions_disk' => 'compliance',
                ]);

            // Only once the copy is verifiably there, and the file itself
            // rather than the folder — the folder is not ours to assume empty.
            if ($row->disk !== 'compliance' && $to->exists($path) && $from->exists($path)) {
                $from->delete($path);
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Going back would re-hide these receipts
        // from every reviewer screen and republish them on the public disk.
    }
};
