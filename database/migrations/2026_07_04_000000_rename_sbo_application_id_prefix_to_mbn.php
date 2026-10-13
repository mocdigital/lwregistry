<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        $renamed = $this->renameSboApplicationIds('SBO-', 'MBN-');

        DB::table('support_enquiries')
            ->where('sbo_number', 'like', 'SBO-%')
            ->orderBy('id')
            ->select(['id', 'sbo_number'])
            ->get()
            ->each(function (object $enquiry) use ($renamed): void {
                $oldNumber = (string) $enquiry->sbo_number;

                DB::table('support_enquiries')
                    ->where('id', $enquiry->id)
                    ->update([
                        'sbo_number' => $renamed[$oldNumber] ?? 'MBN-' . mb_substr($oldNumber, 4),
                    ]);
            });
    }

    public function down(): void
    {
        $renamed = $this->renameSboApplicationIds('MBN-', 'SBO-');

        DB::table('support_enquiries')
            ->where('sbo_number', 'like', 'MBN-%')
            ->orderBy('id')
            ->select(['id', 'sbo_number'])
            ->get()
            ->each(function (object $enquiry) use ($renamed): void {
                $oldNumber = (string) $enquiry->sbo_number;

                DB::table('support_enquiries')
                    ->where('id', $enquiry->id)
                    ->update([
                        'sbo_number' => $renamed[$oldNumber] ?? 'SBO-' . mb_substr($oldNumber, 4),
                    ]);
            });
    }

    /**
     * Rename SBO application ids while avoiding unique-index collisions if the
     * destination prefix already has newly generated records.
     *
     * @return array<string, string>
     */
    private function renameSboApplicationIds(string $fromPrefix, string $toPrefix): array
    {
        $used = DB::table('sbos')
            ->where('application_id', 'like', $toPrefix . '%')
            ->pluck('application_id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();

        $nextSerial = $this->nextSerial($toPrefix);
        $renamed = [];

        DB::table('sbos')
            ->where('application_id', 'like', $fromPrefix . '%')
            ->orderBy('application_id')
            ->select(['id', 'application_id'])
            ->get()
            ->each(function (object $sbo) use ($fromPrefix, $toPrefix, &$used, &$nextSerial, &$renamed): void {
                $oldId = (string) $sbo->application_id;
                $candidate = $toPrefix . mb_substr($oldId, mb_strlen($fromPrefix));

                if (isset($used[$candidate])) {
                    do {
                        $candidate = $toPrefix . now()->format('Y') . '-' . mb_str_pad((string) $nextSerial, 4, '0', STR_PAD_LEFT);
                        $nextSerial++;
                    } while (isset($used[$candidate]));
                }

                DB::table('sbos')
                    ->where('id', $sbo->id)
                    ->update(['application_id' => $candidate]);

                $used[$candidate] = true;
                $renamed[$oldId] = $candidate;
            });

        return $renamed;
    }

    private function nextSerial(string $prefix): int
    {
        $last = DB::table('sbos')
            ->where('application_id', 'like', $prefix . now()->format('Y') . '-%')
            ->orderByDesc('application_id')
            ->value('application_id');

        return $last ? ((int) mb_substr((string) $last, -4)) + 1 : 1;
    }
};
