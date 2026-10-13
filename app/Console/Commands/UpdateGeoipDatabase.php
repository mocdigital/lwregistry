<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetch the current DB-IP country database (see config/locales.php `geoip`).
 *
 * DB-IP publishes one file a month under a dated name, early in the month. The
 * current month is tried first and the previous one second, so a run on the
 * 1st before the new file is out still refreshes to the newest there is. The
 * file is written beside its destination and moved into place in one step, so
 * a request mid-download never reads a half-written database.
 */
final class UpdateGeoipDatabase extends Command
{
    protected $signature = 'geoip:update {--month= : The edition to fetch, as YYYY-MM (default: this month, then last month)}';

    protected $description = 'Download the DB-IP country database used to pick a first-time visitor\'s language';

    public function handle(): int
    {
        $destination = (string) config('locales.geoip.database');
        $source = (string) config('locales.geoip.source');

        $months = filled($this->option('month'))
            ? [(string) $this->option('month')]
            : [Carbon::now()->format('Y-m'), Carbon::now()->subMonthNoOverflow()->format('Y-m')];

        foreach ($months as $month) {
            $url = sprintf($source, $month);

            try {
                $response = Http::timeout(120)->get($url);
            } catch (Throwable $e) {
                $this->warn("{$url}: {$e->getMessage()}");

                continue;
            }

            if ( ! $response->successful()) {
                $this->line("{$url}: HTTP {$response->status()}, trying the previous edition.");

                continue;
            }

            $database = @gzdecode($response->body());

            if (false === $database || '' === $database) {
                $this->error("{$url}: the download was not a gzip file.");

                return self::FAILURE;
            }

            $directory = dirname($destination);

            if ( ! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Could not create {$directory}.");

                return self::FAILURE;
            }

            $temporary = $destination . '.download';

            if (false === file_put_contents($temporary, $database) || ! rename($temporary, $destination)) {
                $this->error("Could not write {$destination}.");

                return self::FAILURE;
            }

            $this->info(sprintf('GeoIP database updated to the %s edition (%s bytes).', $month, number_format(mb_strlen($database, '8bit'))));

            return self::SUCCESS;
        }

        $this->error('No DB-IP edition could be downloaded.');

        return self::FAILURE;
    }
}
