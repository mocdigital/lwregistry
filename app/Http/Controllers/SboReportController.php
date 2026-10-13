<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Models\SboMessage;
use App\Models\SboNetworkReport;
use App\Support\SboNetworkAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reporting a business.
 *
 * The profile drew no report control at all. `BusinessReportController` exists
 * but targets the legacy `Business` model, so it cannot report an SBO — and
 * `SboNetworkReport`, which the admin's Network Reports queue already reads,
 * had nothing writing to it from the front end.
 *
 * WHO MAY REPORT
 * A verified business only. A report puts a listing in front of a reviewer and
 * can end in suspension, so the power to raise one belongs to members who have
 * been through verification themselves — otherwise anyone who signs up and pays
 * can point it at a competitor. That is the same bar the network gate uses:
 * approved, documents compliant, fee settled.
 */
final class SboReportController extends Controller
{
    /**
     * Why a business gets reported.
     *
     * A fixed list rather than free text: the admin queue groups by reason, and
     * "reason" typed differently by every reporter cannot be grouped at all.
     * The detail box carries the specifics.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            'misrepresentation' => __('report.reason.misrepresentation'),
            'not-trading' => __('report.reason.not_trading'),
            'impersonation' => __('report.reason.impersonation'),
            'spam' => __('report.reason.spam'),
            'conduct' => __('report.reason.conduct'),
            'fraud' => __('report.reason.fraud'),
            'other' => __('report.reason.other'),
        ];
    }

    /**
     * Report a message, and the business that sent it.
     *
     * `sbo_network_reports.message_id` has existed all along and nothing wrote
     * to it — the admin queue renders the reported message when there is one,
     * so the column was built for exactly this and the chat had no way in.
     */
    public function message(Request $request, SboMessage $message): RedirectResponse
    {
        $user = $request->user();
        $access = app(SboNetworkAccess::class);
        $mine = $user->sbos()->pluck('id');

        // You can only report a message in a conversation you are part of.
        abort_if(
            null === $message->conversation?->participantSboId($mine),
            403,
            'This is not your conversation.',
        );

        $reporter = $access->verifiedSbosFor($user)->first();

        abort_if(null === $reporter, 403, 'Only a verified business can report a message.');
        abort_if($mine->contains($message->sbo_id), 403, 'You cannot report your own message.');

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(self::reasons()))],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        // One open report per message: a reviewer reading the same complaint
        // twice is a reviewer not reading somebody else's.
        $existing = SboNetworkReport::query()
            ->where('reporter_sbo_id', $reporter->id)
            ->where('message_id', $message->id)
            ->whereIn('status', [SboNetworkReport::STATUS_OPEN, SboNetworkReport::STATUS_REVIEWING])
            ->exists();

        if ($existing) {
            return back()->with('status', 'report-already-open');
        }

        SboNetworkReport::create([
            'reporter_sbo_id' => $reporter->id,
            'reported_sbo_id' => $message->sbo_id,
            'message_id' => $message->id,
            'reason' => self::reasons()[$data['reason']],
            'details' => $data['details'] ?? null,
            'status' => SboNetworkReport::STATUS_OPEN,
        ]);

        return back()->with('status', 'report-submitted');
    }

    public function store(Request $request, Sbo $business): RedirectResponse
    {
        $user = $request->user();
        $access = app(SboNetworkAccess::class);

        // The SBO the report is filed as: a verified one this user owns.
        $reporter = $access->verifiedSbosFor($user)->first();

        abort_if(
            null === $reporter,
            403,
            'Only a verified business can report a listing.',
        );

        abort_if(
            (int) $business->created_by === (int) $user->id,
            403,
            'You cannot report your own listing.',
        );

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(self::reasons()))],
            'details' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        /*
         * One open report per pair. Without this a single reporter can file the
         * same complaint repeatedly and bury the queue — and a reviewer working
         * through duplicates is a reviewer not reading the other reports.
         */
        $existing = SboNetworkReport::query()
            ->where('reporter_sbo_id', $reporter->id)
            ->where('reported_sbo_id', $business->id)
            ->whereIn('status', [SboNetworkReport::STATUS_OPEN, SboNetworkReport::STATUS_REVIEWING])
            ->exists();

        if ($existing) {
            return back()->with('status', 'report-already-open');
        }

        SboNetworkReport::create([
            'reporter_sbo_id' => $reporter->id,
            'reported_sbo_id' => $business->id,
            'reason' => self::reasons()[$data['reason']],
            'details' => $data['details'],
            'status' => SboNetworkReport::STATUS_OPEN,
        ]);

        return back()->with('status', 'report-submitted');
    }
}
