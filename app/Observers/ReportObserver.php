<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Report;
use App\Notifications\AdminNewReportNotification;
use App\Support\AdminRecipients;

final class ReportObserver
{
    /**
     * Handle the Report "created" event.
     */
    public function created(Report $report): void
    {
        AdminRecipients::notify(new AdminNewReportNotification($report));
    }
}
