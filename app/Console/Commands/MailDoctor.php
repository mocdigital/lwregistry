<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Why is no mail arriving?
 *
 * Verification mail used to be queued (`VerifyEmailQueued`, connection
 * `database`, queue `auth`). On installs without a worker, the applicant saw a
 * success message while the email sat in `jobs`. It is now sent synchronously,
 * but this command still reports old queue backlogs because a stale deployment
 * or an old job can produce the same symptom:
 *
 *   1. the user/email was not found    (nothing can be sent)
 *   2. an old job is queued/waiting    (no worker running)
 *   3. the send threw                  (SMTP refused, credentials, DNS)
 *   4. the send was accepted           (then bounced/spam-filtered downstream)
 *
 * This reports which. It reads configuration and the two queue tables, prints
 * no secrets, and only sends anything when asked to.
 *
 *   php artisan mail:doctor
 *   php artisan mail:doctor --send=someone@example.com
 */
final class MailDoctor extends Command
{
    protected $signature = 'mail:doctor {--send= : Send a real verification email to this address}';

    protected $description = 'Diagnose why verification and notification mail is not arriving';

    /** @var list<string> */
    private array $problems = [];

    public function handle(): int
    {
        $this->components->info('Mail');
        $mailer = (string) config('mail.default');

        $this->line('  mailer            ' . $mailer);
        $this->line('  from              ' . (string) config('mail.from.address') . ' (' . (string) config('mail.from.name') . ')');

        if ('smtp' === $mailer) {
            $this->line('  host              ' . (string) config('mail.mailers.smtp.host') . ':' . (string) config('mail.mailers.smtp.port'));
            $this->line('  encryption        ' . ((string) config('mail.mailers.smtp.encryption') ?: 'none'));
            // Whether a username is set, never what it is.
            $this->line('  credentials       ' . (filled(config('mail.mailers.smtp.username')) ? 'present' : 'MISSING'));
        }

        if ('log' === $mailer) {
            $this->fault('MAIL_MAILER=log — mail is written to the log and nothing is delivered.');
        }

        if ('array' === $mailer) {
            $this->fault('MAIL_MAILER=array — mail is discarded. This is a testing driver.');
        }

        if ('smtp' === $mailer && blank(config('mail.mailers.smtp.username'))) {
            $this->fault('SMTP has no username. Most relays reject unauthenticated mail.');
        }

        if ('resend' === $mailer) {
            $this->reportResend();
        }

        $this->newLine();
        $this->components->info('Queue');
        $this->line('  default connection ' . (string) config('queue.default'));
        $this->line('  verification uses  immediate send; old database/auth jobs are still reported');

        $this->reportQueue();
        $this->reportFailures();

        $this->newLine();
        $this->line('  app url            ' . (string) config('app.url'));
        $this->line('  <fg=gray>A wrong APP_URL still sends, but the link in the mail 404s.</>');

        $this->newLine();

        if ([] === $this->problems) {
            $this->line('  <fg=green>No configuration fault found.</> If mail still does not arrive it is');
            $this->line('  downstream: the relay accepted it and dropped it, or it is in a spam folder.');
        } else {
            $this->line('  <fg=red;options=bold>Problems found:</>');

            foreach ($this->problems as $problem) {
                $this->line('  - ' . $problem);
            }
        }

        if ($address = $this->option('send')) {
            $this->newLine();

            return $this->sendTest((string) $address);
        }

        $this->newLine();
        $this->line('  Add --send=you@example.com to put a real email through the whole path.');

        return self::SUCCESS;
    }

    /**
     * Resend will only send from a domain the account has verified.
     *
     * This is the check worth having: the default from address was
     * hello@example.com, which Resend rejects outright, and the rejection
     * arrives as a generic transport failure long after the fact — or, when
     * the mail is queued, as nothing visible at all. Asking the account which
     * domains it owns turns a silent dead end into one line.
     */
    private function reportResend(): void
    {
        $key = (string) config('services.resend.key');

        if (blank($key)) {
            $this->fault('MAIL_MAILER=resend but RESEND_KEY is empty. Nothing can be sent.');

            return;
        }

        $from = (string) config('mail.from.address');
        $domain = mb_strtolower(mb_trim((string) mb_substr($from, (int) mb_strpos($from, '@') + 1)));

        try {
            $response = Http::withToken($key)->timeout(10)->get('https://api.resend.com/domains');
        } catch (Throwable $exception) {
            // Offline, or Resend is down. Not a configuration fault, and
            // reporting it as one would send someone chasing the wrong thing.
            $this->line('  domains           <fg=gray>could not be checked (' . $exception->getMessage() . ')</>');

            return;
        }

        /*
         * Resend answers a bad key with 400 "API key is invalid", not the 401
         * you would expect — so status alone is not enough, and reading only
         * `data` reports "no verified domain" for what is actually a rejected
         * credential. Two very different fixes, so they are told apart here.
         */
        if ( ! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->body());

            $this->fault(sprintf(
                'Resend refused the domains lookup (HTTP %d): %s',
                $response->status(),
                Str::limit($message, 120),
            ));

            return;
        }

        /** @var array<int, array<string, mixed>> $domains */
        $domains = (array) ($response->json('data') ?? []);
        $verified = [];

        foreach ($domains as $row) {
            if ('verified' === ($row['status'] ?? null)) {
                $verified[] = mb_strtolower((string) ($row['name'] ?? ''));
            }
        }

        $this->line('  verified domains  ' . ([] === $verified ? 'none' : implode(', ', $verified)));

        if ([] === $verified) {
            $this->fault('No verified sending domain on this Resend account. Every send will be refused.');

            return;
        }

        /*
         * A subdomain is not covered by its parent unless separately verified,
         * so registrytest.lwcommerce.org does not inherit lwcommerce.org.
         */
        if ( ! in_array($domain, $verified, true)) {
            $this->fault(sprintf(
                'MAIL_FROM_ADDRESS is @%s, which is not verified on this Resend account (%s). Resend will refuse it.',
                $domain,
                implode(', ', $verified),
            ));
        }
    }

    /** Record a fault and show it where it was found. */
    private function fault(string $problem): void
    {
        $this->problems[] = $problem;
        $this->components->error($problem);
    }

    private function reportQueue(): void
    {
        try {
            $waiting = DB::table('jobs')->where('queue', 'auth')->count();
            $oldest = DB::table('jobs')->where('queue', 'auth')->min('created_at');
        } catch (Throwable $exception) {
            $this->components->warn('Could not read the jobs table: ' . $exception->getMessage());

            return;
        }

        $this->line('  waiting on `auth`  ' . $waiting);

        /*
         * A backlog is the single most common cause. The job is queued and
         * correct; nothing is running it, so it sits there forever and the
         * applicant waits for an email that was never attempted.
         */
        if ($waiting > 0 && null !== $oldest) {
            $age = Carbon::createFromTimestamp((int) $oldest)->diffForHumans();

            $this->fault(
                "{$waiting} verification job(s) waiting, oldest since {$age}. "
                    . 'Nothing is running them — check: supervisorctl status registry-worker'
            );
        }
    }

    private function reportFailures(): void
    {
        try {
            $failed = DB::table('failed_jobs')->count();
            $last = DB::table('failed_jobs')->latest('failed_at')->first();
        } catch (Throwable $exception) {
            $this->components->warn('Could not read the failed_jobs table: ' . $exception->getMessage());

            return;
        }

        $this->line('  failed jobs        ' . $failed);

        if (null === $last) {
            return;
        }

        /*
         * The first line of the exception is the useful part; the stack trace
         * that follows it is not, on a terminal.
         *
         * `explode`, not `strtok` — Pint's mb_str_functions rule rewrites
         * `strtok` to `mb_strtok`, which is not a function that exists, and the
         * command then fatals on the one path it was written to report.
         */
        $reason = explode("\n", (string) $last->exception)[0];

        $this->fault('Last failure (' . $last->failed_at . '): ' . $reason);
    }

    private function sendTest(string $address): int
    {
        $user = User::query()->where('email', $address)->first();

        if ( ! $user instanceof User) {
            $this->components->error("No account with the address {$address}. Verification mail is addressed to a user.");

            return self::FAILURE;
        }

        /*
         * Sent synchronously on purpose. Queueing it would only prove the job
         * can be written to the table, which is the half that already works —
         * this puts it through the mailer and reports what the mailer says.
         */
        try {
            Mail::mailer(config('mail.default'))->raw(
                'Mail doctor test from ' . config('app.name') . '. If you are reading this, SMTP works.',
                fn ($message) => $message->to($address)->subject('Mail doctor test'),
            );
        } catch (Throwable $exception) {
            $this->components->error('The mailer refused it: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Plain test accepted by the mailer for {$address}.");

        // Then the real thing, through the same path registration uses.
        $user->notifyNow(new VerifyEmailQueued());

        $this->components->info('Verification notification sent synchronously. If this arrives but registration mail does not, the worker is the problem, not the mailer.');

        return self::SUCCESS;
    }
}
