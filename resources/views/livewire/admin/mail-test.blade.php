<?php

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\Mime\Email;

new #[Layout('components.layouts.admin', ['title' => 'Mail Test - Admin Dashboard', 'dataPage' => 'mail-test', 'pageTitle' => 'Mail Test'])] class extends Component
{
    public string $to = '';

    public string $subject = 'Loveworld Registry mail test';

    public string $html = '<p>Congrats on sending your <strong>first Loveworld Registry email</strong> through the configured mailer.</p>';

    public ?string $lastMessageId = null;

    public function mount(): void
    {
        $this->to = (string) auth()->user()?->email;
    }

    public function send(): void
    {
        $data = $this->validate([
            'to' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'html' => ['required', 'string', 'max:10000'],
        ]);

        $mailer = (string) config('mail.default');

        if ('resend' === $mailer && blank(config('services.resend.key'))) {
            $this->addError('to', 'MAIL_MAILER is resend, but neither RESEND_KEY nor RESEND_API_KEY is loaded.');

            return;
        }

        try {
            $sent = Mail::mailer($mailer)->html(
                $data['html'],
                fn ($message) => $message
                    ->to($data['to'])
                    ->subject($data['subject']),
            );
        } catch (Throwable $exception) {
            $this->addError('to', 'The mailer refused the message: ' . Str::limit($exception->getMessage(), 220));

            return;
        }

        $this->lastMessageId = $this->messageId($sent);

        session()->flash(
            'success',
            $this->lastMessageId
                ? "Test email accepted by {$mailer}. Message ID: {$this->lastMessageId}"
                : "Test email accepted by {$mailer}."
        );
    }

    public function with(): array
    {
        $from = (string) config('mail.from.address');
        $fromDomain = str_contains($from, '@') ? Str::afterLast($from, '@') : '';
        $mailer = (string) config('mail.default');

        return [
            'mailer' => $mailer,
            'from' => $from,
            'fromName' => (string) config('mail.from.name'),
            'resendKeyLoaded' => filled(config('services.resend.key')),
            'fromDomain' => $fromDomain,
            'usesResendDevSender' => 'resend' === $mailer && 'resend.dev' === mb_strtolower($fromDomain),
        ];
    }

    private function messageId(?SentMessage $sent): ?string
    {
        if ( ! $sent instanceof SentMessage) {
            return null;
        }

        $original = $sent->getSymfonySentMessage()->getOriginalMessage();

        if ( ! $original instanceof Email) {
            return null;
        }

        $resendId = $original->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString();

        return filled($resendId) ? $resendId : $sent->getMessageId();
    }
}; ?>

<div>
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">Send a test email</h2>
            <p class="text-muted mb-0">
                This sends immediately through the active Laravel mailer and reports the mailer error on this page.
            </p>
        </div>

        <div class="text-end small text-muted">
            <div><span class="fw-semibold text-body">Mailer:</span> {{ $mailer }}</div>
            <div><span class="fw-semibold text-body">From:</span> {{ $fromName }} &lt;{{ $from }}&gt;</div>
        </div>
    </div>

    @if('resend' === $mailer)
        <div @class(['alert py-2', 'alert-info' => $resendKeyLoaded && ! $usesResendDevSender, 'alert-warning' => ! $resendKeyLoaded || $usesResendDevSender])>
            @if(! $resendKeyLoaded)
                Resend is selected, but no API key is loaded. Set <code>RESEND_API_KEY</code> or <code>RESEND_KEY</code>.
            @elseif($usesResendDevSender)
                <code>resend.dev</code> is a testing sender. Use a verified sending domain for real recipients.
            @else
                Resend is selected and an API key is loaded.
            @endif
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="admin-card p-3 p-md-4">
                <form wire:submit.prevent="send" class="vstack gap-3">
                    <div>
                        <label for="mailTestTo" class="form-label">Recipient</label>
                        <input id="mailTestTo" type="email" class="form-control" wire:model.defer="to" autocomplete="email">
                        @error('to') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label for="mailTestSubject" class="form-label">Subject</label>
                        <input id="mailTestSubject" class="form-control" wire:model.defer="subject">
                        @error('subject') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label for="mailTestHtml" class="form-label">HTML body</label>
                        <textarea id="mailTestHtml" class="form-control font-monospace" rows="9" wire:model.defer="html"></textarea>
                        @error('html') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="send">
                            <span wire:loading.remove wire:target="send">
                                <i class="bi bi-send me-1" aria-hidden="true"></i>
                                Send test email
                            </span>
                            <span wire:loading wire:target="send">Sending...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="admin-card p-3 p-md-4">
                <h3 class="h6 mb-3">Current mail configuration</h3>

                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">MAIL_MAILER</dt>
                    <dd class="col-7">{{ $mailer }}</dd>

                    <dt class="col-5 text-muted">MAIL_FROM_ADDRESS</dt>
                    <dd class="col-7 text-break">{{ $from }}</dd>

                    <dt class="col-5 text-muted">MAIL_FROM_NAME</dt>
                    <dd class="col-7">{{ $fromName }}</dd>

                    <dt class="col-5 text-muted">Resend key</dt>
                    <dd class="col-7">{{ $resendKeyLoaded ? 'Loaded' : 'Missing' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
