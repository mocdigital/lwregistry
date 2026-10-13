<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SboNetworkReport;
use App\Notifications\Concerns\BroadcastsToBell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SboNetworkReportSubmittedNotification extends Notification implements ShouldQueue
{
    use BroadcastsToBell;
    use Queueable;

    public function __construct(private readonly SboNetworkReport $report)
    {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('SBO network report submitted - Loveworld Registry')
            ->greeting('Hello,')
            ->line(($this->report->reporterSbo->name ?? 'An SBO') . ' submitted a network moderation report.')
            ->line('Reason: ' . $this->report->reason)
            ->action('Open Network Reports', route('admin.network.reports'))
            ->line('Please review the report and take any required action.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Network report submitted',
            'message' => ($this->report->reporterSbo->name ?? 'An SBO') . ' reported a network interaction.',
            'url' => route('admin.network.reports'),
            'type' => 'sbo_network_report',
            'report_id' => $this->report->id,
        ];
    }
}
