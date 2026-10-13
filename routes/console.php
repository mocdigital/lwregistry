<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// use Illuminate\Foundation\Inspiring;
// use Illuminate\Support\Facades\Artisan;

Schedule::command('queue:work --queue=auth,mail,default --stop-when-empty --timeout=300 --tries=3')
    ->everyTenSeconds()
    ->withoutOverlapping();

Schedule::command('queue:retry all')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
 * The sales assistant's knowledge bank: the curated Q&A, the registry's own
 * facts (with their live counts) and the site's page copy. Rewritten on a
 * schedule so the figures stay current and — the reason this is here — the bank
 * refills itself if it is ever cleared. An empty bank is a bot that answers
 * nothing, which is exactly how it looked when the import had never been run on
 * a fresh database. Only the platform's own documents are rewritten; an
 * administrator's uploads and the answers learned from support are left alone.
 */
Schedule::command('chatbot:import-training')
    ->hourly()
    ->runInBackground()
    ->withoutOverlapping();

/*
 * The IP → country database behind the first-visit language choice (see
 * Locales::resolve). DB-IP publishes a fresh edition early each month; its
 * licence asks for a reasonably current copy. The 3rd gives the new file time
 * to appear, and the command falls back to the previous edition if it has not.
 */
Schedule::command('geoip:update')
    ->monthlyOn(3, '04:10')
    ->withoutOverlapping();
