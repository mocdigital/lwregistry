<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedbackSubmission;
use App\Notifications\FeedbackSubmittedNotification;
use App\Support\AdminRecipients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FeedbackSubmissionController
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ( ! $request->user()) {
            return redirect()
                ->to(route('feedback') . '#feedback-form')
                ->with('feedback_auth_required', true)
                ->with('feedback_auth_message', 'Please register or log in before sending feedback.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kc_handle' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'feedback_type' => ['required', 'string', Rule::in(array_keys(FeedbackSubmission::typeOptions()))],
            'priority' => ['required', 'string', Rule::in(array_keys(FeedbackSubmission::priorityOptions()))],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'page_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $feedback = FeedbackSubmission::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => FeedbackSubmission::STATUS_NEW,
        ]);

        AdminRecipients::notify(new FeedbackSubmittedNotification($feedback->fresh(['user'])));

        return redirect()
            ->to(route('feedback') . '#feedback-form')
            ->with('feedback_success', 'Thank you. Your feedback has been sent to the registry team.');
    }
}
