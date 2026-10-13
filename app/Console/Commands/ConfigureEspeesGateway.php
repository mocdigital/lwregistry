<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\SboFeeSetting;
use App\Support\SboFeeCalculator;
use Illuminate\Console\Command;

/**
 * Turn Espees checkout on (or off), and show what it is currently set to.
 *
 * The gateway is not env config — it lives in `sbo_fee_settings`, scoped by
 * country, and is normally edited from the admin Fees page. On a fresh
 * database nothing is set, so `espeesGateway()['configured']` is false and
 * `SboEspeesPaymentController@start` refuses with "Espees checkout is not
 * configured yet", which from the screen looks like a broken button.
 *
 * This is the CLI equivalent of that admin page, so a local environment can be
 * put into a working state in one line.
 *
 * The token and wallet are real credentials — they are not invented here and
 * there is no default. Without them from the Espees merchant dashboard,
 * checkout cannot run and manual payment is the working path.
 */
final class ConfigureEspeesGateway extends Command
{
    protected $signature = 'sbo:espees
        {--token= : Espees merchant API token}
        {--wallet= : Espees merchant wallet address}
        {--test-mode : Mark the gateway as test mode}
        {--country= : Country name to scope these settings to (default: global)}
        {--disable : Turn Espees checkout off}
        {--show : Print the current gateway state and exit}';

    protected $description = 'Show or set the Espees merchant gateway used by SBO registration-fee checkout';

    public function handle(): int
    {
        $countryId = null;

        if ($country = $this->option('country')) {
            $countryId = Country::where('name', $country)->value('id');

            if (null === $countryId) {
                $this->error("No country named \"{$country}\".");

                return self::FAILURE;
            }
        }

        if ($this->option('show')) {
            return $this->show($countryId);
        }

        if ($this->option('disable')) {
            SboFeeSetting::set('espees_enabled', '0', $countryId);
            SboFeeCalculator::flushCache();
            $this->info('Espees checkout disabled' . ($countryId ? " for {$this->option('country')}" : ' globally') . '.');

            return self::SUCCESS;
        }

        $token = (string) ($this->option('token') ?? '');
        $wallet = (string) ($this->option('wallet') ?? '');

        if ('' === $token || '' === $wallet) {
            $this->error('Both --token and --wallet are required to enable Espees checkout.');
            $this->newLine();
            $this->line('  These are real merchant credentials from the Espees dashboard.');
            $this->line('  There is no safe default, so nothing is guessed here.');
            $this->newLine();
            $this->line('  php artisan sbo:espees --token=… --wallet=… --test-mode');
            $this->line('  php artisan sbo:espees --show');
            $this->newLine();
            $this->comment('Manual payment (upload proof of transfer) works without any of this.');

            return self::FAILURE;
        }

        SboFeeSetting::set('espees_enabled', '1', $countryId);
        SboFeeSetting::set('espees_token', $token, $countryId);
        SboFeeSetting::set('espees_merchant_wallet', $wallet, $countryId);
        SboFeeSetting::set('espees_test_mode', $this->option('test-mode') ? '1' : '0', $countryId);

        SboFeeCalculator::flushCache();

        $this->info('Espees checkout enabled' . ($countryId ? " for {$this->option('country')}" : ' globally') . '.');

        return $this->show($countryId);
    }

    private function show(?int $countryId): int
    {
        SboFeeCalculator::flushCache();
        $gateway = SboFeeCalculator::espeesGateway($countryId);

        $this->newLine();
        $this->table(['Setting', 'Value'], [
            ['Scope', $countryId ? (string) $this->option('country') : 'global'],
            ['Enabled', $gateway['enabled'] ? 'yes' : 'no'],
            // Never echoed back in full: this is a live credential.
            ['Token', '' === $gateway['token'] ? '(not set)' : 'set (' . mb_strlen($gateway['token']) . ' chars)'],
            ['Merchant wallet', '' === $gateway['merchant_wallet'] ? '(not set)' : $gateway['merchant_wallet']],
            ['Test mode', $gateway['test_mode'] ? 'yes' : 'no'],
            ['Usable by checkout', $gateway['configured'] ? 'YES' : 'no'],
            ['Fee currency', SboFeeCalculator::currency($countryId)],
            ['Flat fee', (string) SboFeeCalculator::flatFee($countryId)],
        ]);

        if ( ! $gateway['configured']) {
            $this->newLine();
            $this->comment('Checkout will refuse to start. Manual payment still works.');
        }

        return self::SUCCESS;
    }
}
