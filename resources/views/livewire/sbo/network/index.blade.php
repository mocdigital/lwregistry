<?php

use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboNetworkReport;
use App\Notifications\SboConnectionAcceptedNotification;
use App\Notifications\SboConnectionRequestedNotification;
use App\Notifications\SboMessageReceivedNotification;
use App\Notifications\SboNetworkReportSubmittedNotification;
use App\Support\AdminRecipients;
use App\Support\SboNetworkAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class() extends Component
{
    #[Url(as: 'sbo', history: true, keep: true)]
    public ?int $activeSboId = null;

    public ?int $selectedConversationId = null;

    public string $messageBody = '';

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    public ?int $reportTargetSboId = null;

    public ?int $reportMessageId = null;

    public string $reportReason = 'Spam or suspicious activity';

    public string $reportDetails = '';

    public function mount(): void
    {
        $access = app(SboNetworkAccess::class);
        $this->activeSboId ??= $access->verifiedSbosFor(auth()->user())->first()?->id;
    }

    public function updatedActiveSboId(): void
    {
        $this->selectedConversationId = null;
        $this->messageBody = '';
        $this->resetValidation();
    }

    public function clearSearch(): void
    {
        $this->reset('search');
    }

    public function sendConnectionRequest(int $recipientSboId): void
    {
        $access = app(SboNetworkAccess::class);
        $activeSbo = $this->activeSbo();
        $recipient = Sbo::with('creator')->findOrFail($recipientSboId);

        if ((int) $activeSbo->id === (int) $recipient->id || ! $access->sboCanUseNetwork($recipient)) {
            $this->addError('search', 'Choose an eligible SBO to connect with.');

            return;
        }

        $existing = SboConnection::query()
            ->where(function ($query) use ($activeSbo, $recipient): void {
                $query->where('requester_sbo_id', $activeSbo->id)
                    ->where('recipient_sbo_id', $recipient->id);
            })
            ->orWhere(function ($query) use ($activeSbo, $recipient): void {
                $query->where('requester_sbo_id', $recipient->id)
                    ->where('recipient_sbo_id', $activeSbo->id);
            })
            ->first();

        if (SboConnection::STATUS_ACCEPTED === $existing?->status) {
            session()->flash('success', 'You are already connected.');

            return;
        }

        if ($existing && (int) $existing->recipient_sbo_id === (int) $activeSbo->id) {
            $this->acceptConnection($existing->id);

            return;
        }

        $connection = $existing ?: new SboConnection();
        $connection->fill([
            'requester_sbo_id' => $activeSbo->id,
            'recipient_sbo_id' => $recipient->id,
            'status' => SboConnection::STATUS_PENDING,
            'responded_at' => null,
        ])->save();

        $recipient->creator?->notify(new SboConnectionRequestedNotification($connection->fresh(['requester', 'recipient'])));

        session()->flash('success', 'Connection request sent.');
    }

    public function acceptConnection(int $connectionId): void
    {
        $activeSbo = $this->activeSbo();
        $connection = SboConnection::with(['requester.creator', 'recipient.creator', 'conversation'])
            ->where('recipient_sbo_id', $activeSbo->id)
            ->findOrFail($connectionId);

        DB::transaction(function () use ($connection): void {
            $connection->update([
                'status' => SboConnection::STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);

            $conversation = $connection->conversation ?: SboConversation::create([
                'connection_id' => $connection->id,
                'created_by_sbo_id' => $connection->requester_sbo_id,
                'type' => SboConversation::TYPE_DIRECT,
                'last_message_at' => now(),
            ]);

            $conversation->participants()->syncWithoutDetaching([
                $connection->requester_sbo_id,
                $connection->recipient_sbo_id,
            ]);
        });

        $connection->requester?->creator?->notify(new SboConnectionAcceptedNotification($connection->fresh(['requester', 'recipient'])));
        $this->selectedConversationId = $connection->fresh('conversation')->conversation?->id;

        session()->flash('success', 'Connection accepted. You can now message each other.');
    }

    public function declineConnection(int $connectionId): void
    {
        $activeSbo = $this->activeSbo();

        SboConnection::where('recipient_sbo_id', $activeSbo->id)
            ->whereKey($connectionId)
            ->update([
                'status' => SboConnection::STATUS_DECLINED,
                'responded_at' => now(),
            ]);

        session()->flash('success', 'Connection request declined.');
    }

    public function openConversation(int $conversationId): void
    {
        $activeSbo = $this->activeSbo();

        $conversation = SboConversation::whereHas('participants', function ($query) use ($activeSbo): void {
            $query->where('sbos.id', $activeSbo->id);
        })->findOrFail($conversationId);

        $this->selectedConversationId = $conversation->id;
        $conversation->participants()->updateExistingPivot($activeSbo->id, ['last_read_at' => now()]);
    }

    public function sendMessage(): void
    {
        $activeSbo = $this->activeSbo();

        $this->validate([
            'messageBody' => ['required', 'string', 'min:2', 'max:2000'],
            'selectedConversationId' => ['required', 'integer'],
        ]);

        $conversation = SboConversation::with(['sboConnection', 'participants.creator'])
            ->whereHas('participants', function ($query) use ($activeSbo): void {
                $query->where('sbos.id', $activeSbo->id);
            })
            ->findOrFail($this->selectedConversationId);

        if (SboConversation::TYPE_DIRECT === $conversation->type && ! $conversation->sboConnection?->isAccepted()) {
            $this->addError('messageBody', 'Messages can only be sent after the connection is accepted.');

            return;
        }

        $message = DB::transaction(function () use ($conversation, $activeSbo): SboMessage {
            $message = SboMessage::create([
                'conversation_id' => $conversation->id,
                'sbo_id' => $activeSbo->id,
                'user_id' => auth()->id(),
                'body' => mb_trim($this->messageBody),
            ]);

            $conversation->update(['last_message_at' => now()]);
            $conversation->participants()->updateExistingPivot($activeSbo->id, ['last_read_at' => now()]);

            return $message;
        });

        $recipientUsers = $conversation->participants
            ->where('id', '!=', $activeSbo->id)
            ->pluck('creator')
            ->filter()
            ->unique('id')
            ->values();

        Notification::send($recipientUsers, new SboMessageReceivedNotification($message->fresh(['sbo', 'conversation'])));

        $this->messageBody = '';
    }

    public function prepareReport(?int $reportedSboId = null, ?int $messageId = null): void
    {
        $this->reportTargetSboId = $reportedSboId;
        $this->reportMessageId = $messageId;
        $this->reportReason = 'Spam or suspicious activity';
        $this->reportDetails = '';
        $this->resetValidation();
        $this->dispatch('open-network-report-modal');
    }

    public function submitReport(): void
    {
        $activeSbo = $this->activeSbo();

        $data = $this->validate([
            'reportTargetSboId' => ['nullable', 'integer', 'exists:sbos,id'],
            'reportMessageId' => ['nullable', 'integer', 'exists:sbo_messages,id'],
            'reportReason' => ['required', 'string', 'max:255'],
            'reportDetails' => ['nullable', 'string', 'max:3000'],
        ]);

        $report = SboNetworkReport::create([
            'reporter_sbo_id' => $activeSbo->id,
            'reported_sbo_id' => $data['reportTargetSboId'] ?? null,
            'message_id' => $data['reportMessageId'] ?? null,
            'reason' => $data['reportReason'],
            'details' => $data['reportDetails'] ?: null,
        ]);

        AdminRecipients::notify(new SboNetworkReportSubmittedNotification($report->fresh(['reporterSbo', 'reportedSbo'])));

        $this->dispatch('close-network-report-modal');
        session()->flash('success', 'Report submitted to the admin team.');
    }

    public function with(): array
    {
        $access = app(SboNetworkAccess::class);
        $verifiedSbos = $access->verifiedSbosFor(auth()->user());
        $search = mb_trim($this->search);

        if ( ! $verifiedSbos->pluck('id')->contains($this->activeSboId)) {
            $this->activeSboId = $verifiedSbos->first()?->id;
        }

        $activeSbo = $verifiedSbos->firstWhere('id', $this->activeSboId);
        $directory = collect();
        $incoming = collect();
        $outgoing = collect();
        $accepted = collect();
        $conversations = collect();
        $selectedConversation = null;

        if ($activeSbo) {
            $ownedSboIds = $verifiedSbos->pluck('id')->all();

            $directory = Sbo::with('creator')
                ->whereNotIn('id', $ownedSboIds)
                ->when('' !== $search, function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $term = '%' . $search . '%';

                        $query->where('name', 'like', $term)
                            ->orWhere('category', 'like', $term)
                            ->orWhere('church_zone', 'like', $term);
                    });
                })
                ->orderBy('name')
                ->limit(40)
                ->get()
                ->filter(fn (Sbo $sbo): bool => $access->sboCanUseNetwork($sbo))
                ->values();

            $incoming = SboConnection::with(['requester', 'recipient'])
                ->where('recipient_sbo_id', $activeSbo->id)
                ->where('status', SboConnection::STATUS_PENDING)
                ->latest()
                ->get();

            $outgoing = SboConnection::with(['requester', 'recipient'])
                ->where('requester_sbo_id', $activeSbo->id)
                ->where('status', SboConnection::STATUS_PENDING)
                ->latest()
                ->get();

            $accepted = SboConnection::with(['requester', 'recipient', 'conversation'])
                ->where('status', SboConnection::STATUS_ACCEPTED)
                ->where(function ($query) use ($activeSbo): void {
                    $query->where('requester_sbo_id', $activeSbo->id)
                        ->orWhere('recipient_sbo_id', $activeSbo->id);
                })
                ->latest('responded_at')
                ->get();

            $conversations = SboConversation::with(['participants', 'messages.sbo'])
                ->whereHas('participants', function ($query) use ($activeSbo): void {
                    $query->where('sbos.id', $activeSbo->id);
                })
                ->latest('last_message_at')
                ->get();

            $selectedConversation = $this->selectedConversationId
                ? SboConversation::with(['participants', 'messages.sbo', 'messages.user', 'sboConnection'])
                    ->whereHas('participants', function ($query) use ($activeSbo): void {
                        $query->where('sbos.id', $activeSbo->id);
                    })
                    ->find($this->selectedConversationId)
                : null;
        }

        return [
            'verifiedSbos' => $verifiedSbos,
            'activeSbo' => $activeSbo,
            'directory' => $directory,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'accepted' => $accepted,
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
        ];
    }

    private function activeSbo(): Sbo
    {
        $access = app(SboNetworkAccess::class);
        $activeSbo = $access->verifiedSbosFor(auth()->user())->firstWhere('id', $this->activeSboId);

        abort_unless($activeSbo, 403, 'Only fully verified, active SBOs can use the company network.');

        return $activeSbo;
    }
};
?>

<div class="sbo-network-remake sbo-remake">
    @if (session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <x-ui.page-header
        title="SBO Network"
        description="Connect with fully verified companies, message accepted connections, and collaborate on procurement opportunities."
        :breadcrumbs="[
            ['label' => 'SBO Workspace', 'href' => route('sbo.dashboard'), 'wireNavigate' => true],
            ['label' => 'SBO Network', 'current' => true],
        ]"
    >
        <x-slot:actions>
            <a href="{{ route('sbo.rfqs.index', ['create' => 1]) }}" class="btn btn-primary rounded-pill" wire:navigate>
                <i class="bi bi-clipboard-plus me-1"></i> Post RFQ
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.sbo-context :sbo="$activeSbo" :options="$verifiedSbos" />

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-body">
                    <label class="form-label" for="networkActiveSbo">Acting as</label>
                    <select id="networkActiveSbo" class="form-select" wire:model.live="activeSboId" data-form-help="Choose which fully verified SBO should send requests, messages, and reports.">
                        @foreach($verifiedSbos as $sbo)
                            <option value="{{ $sbo->id }}">{{ $sbo->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="h6 mb-1">Network directory</h2>
                            <div class="small text-muted">Only fully verified, active SBOs appear here.</div>
                        </div>
                    </div>

                    <x-live-search
                        model="search"
                        :value="$search"
                        label="Search network directory"
                        placeholder="Search company, category, or zone"
                        help="Search the verified SBO network by company name, service category, or church zone."
                        class="mb-3"
                        min-width="100%" />

                    <div class="d-flex flex-column gap-3" wire:loading.class.delay="opacity-50" wire:target="search,clearSearch,activeSboId">
                        @forelse($directory as $company)
                            @php
                                $existing = $accepted->first(fn ($connection) => $connection->involves($company))
                                    ?? $incoming->first(fn ($connection) => $connection->requester_sbo_id === $company->id)
                                    ?? $outgoing->first(fn ($connection) => $connection->recipient_sbo_id === $company->id);
                            @endphp
                            <div class="border rounded-2 p-3">
                                <div class="d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ $company->name }}</div>
                                        <div class="small text-muted">{{ $company->category ?: 'General services' }}{{ $company->church_zone ? ' · '.$company->church_zone : '' }}</div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="prepareReport({{ $company->id }})">
                                        Report
                                    </button>
                                </div>

                                <div class="mt-3">
                                    @if($existing?->status === \App\Models\SboConnection::STATUS_ACCEPTED)
                                        <span class="badge bg-success-subtle text-success border">Connected</span>
                                    @elseif($existing?->status === \App\Models\SboConnection::STATUS_PENDING)
                                        <span class="badge bg-warning-subtle text-warning border">Pending</span>
                                    @else
                                        <button class="btn btn-sm btn-outline-primary rounded-pill" wire:click="sendConnectionRequest({{ $company->id }})">
                                            Connect
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted small py-4">No eligible SBOs found.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Requests</h2>
                    @forelse($incoming as $request)
                        <div class="border rounded-2 p-3 mb-3">
                            <div class="fw-semibold">{{ $request->requester?->name }}</div>
                            <div class="small text-muted mb-3">Wants to connect</div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-success rounded-pill" wire:click="acceptConnection({{ $request->id }})">Accept</button>
                                <button class="btn btn-sm btn-outline-secondary rounded-pill" wire:click="declineConnection({{ $request->id }})">Decline</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">No incoming requests.</p>
                    @endforelse

                    @if($outgoing->isNotEmpty())
                        <hr>
                        <h3 class="h6 mb-3">Sent</h3>
                        @foreach($outgoing as $request)
                            <div class="small d-flex justify-content-between gap-2 mb-2">
                                <span>{{ $request->recipient?->name }}</span>
                                <span class="badge bg-warning-subtle text-warning border">Pending</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-3">Connections</h2>
                    @forelse($accepted as $connection)
                        @php
                            $other = $connection->otherSbo($activeSbo);
                            $conversationId = $connection->conversation?->id;
                        @endphp
                        <button
                            type="button"
                            class="btn btn-light border w-100 text-start mb-2"
                            @if($conversationId) wire:click="openConversation({{ $conversationId }})" @endif
                        >
                            <div class="fw-semibold">{{ $other?->name }}</div>
                            <div class="small text-muted">Message connection</div>
                        </button>
                    @empty
                        <p class="text-muted small mb-0">Accepted connections will appear here.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100" wire:poll.15s>
                <div class="card-body d-flex flex-column" style="min-height: 620px;">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h2 class="h6 mb-1">Messages</h2>
                            <div class="small text-muted">Messaging opens after a connection request is accepted.</div>
                        </div>
                    </div>

                    @if($selectedConversation)
                        @php
                            $otherParticipants = $selectedConversation->participants->where('id', '!=', $activeSbo?->id);
                        @endphp
                        <div class="border rounded-2 p-3 mb-3">
                            <div class="fw-semibold">{{ $otherParticipants->pluck('name')->join(', ') ?: 'Conversation' }}</div>
                            <div class="small text-muted">Accepted connection</div>
                        </div>

                        <div class="flex-grow-1 overflow-auto border rounded-2 p-3 mb-3 bg-light" style="max-height: 390px;">
                            @forelse($selectedConversation->messages as $message)
                                @php $mine = (int) $message->sbo_id === (int) $activeSbo?->id; @endphp
                                <div class="mb-3 {{ $mine ? 'text-end' : '' }}">
                                    <div class="d-inline-block p-3 rounded-2 {{ $mine ? 'bg-primary text-white' : 'bg-white border' }}" style="max-width: 86%;">
                                        <div class="small {{ $mine ? 'text-white-50' : 'text-muted' }} mb-1">{{ $message->sbo?->name }} · {{ $message->created_at->format('M d, H:i') }}</div>
                                        <div>{{ $message->body }}</div>
                                    </div>
                                    @unless($mine)
                                        <div>
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="prepareReport({{ $message->sbo_id }}, {{ $message->id }})">
                                                Report message
                                            </button>
                                        </div>
                                    @endunless
                                </div>
                            @empty
                                <p class="text-muted text-center small py-5">No messages yet. Start the conversation.</p>
                            @endforelse
                        </div>

                        <form wire:submit.prevent="sendMessage" class="mt-auto">
                            <label class="form-label" for="networkMessage">Message</label>
                            <textarea id="networkMessage" class="form-control @error('messageBody') is-invalid @enderror" rows="3" wire:model.defer="messageBody" placeholder="Write a clear, professional message." data-form-help="Messages are shared only with accepted SBO connections in this conversation." @error('messageBody') aria-describedby="networkMessageError" aria-invalid="true" @enderror></textarea>
                            @error('messageBody') <div id="networkMessageError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                            <div class="text-end mt-2">
                                <button class="btn btn-primary rounded-pill" type="submit" wire:loading.attr="disabled" wire:target="sendMessage">
                                    Send Message
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="d-flex flex-column align-items-center justify-content-center flex-grow-1 text-center text-muted">
                            <i class="bi bi-chat-dots fs-1 mb-3"></i>
                            <h3 class="h6">Select a connection</h3>
                            <p class="small mb-0">Choose an accepted connection to open the message thread.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="networkReportModal" tabindex="-1" aria-labelledby="networkReportModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="submitReport">
                    <div class="modal-header">
                        <h5 class="modal-title" id="networkReportModalLabel">Report network activity</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="networkReportReason">Reason</label>
                            <select id="networkReportReason" class="form-select @error('reportReason') is-invalid @enderror" wire:model="reportReason" @error('reportReason') aria-describedby="networkReportReasonError" aria-invalid="true" @enderror>
                                <option>Spam or suspicious activity</option>
                                <option>Unprofessional conduct</option>
                                <option>Misleading bid information</option>
                                <option>Harassment or abuse</option>
                                <option>Other</option>
                            </select>
                            @error('reportReason') <div id="networkReportReasonError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label" for="networkReportDetails">Details <small class="text-muted">(optional)</small></label>
                            <textarea id="networkReportDetails" class="form-control @error('reportDetails') is-invalid @enderror" rows="4" wire:model.defer="reportDetails" placeholder="Add context that will help admins review this report." @error('reportDetails') aria-describedby="networkReportDetailsError" aria-invalid="true" @enderror></textarea>
                            @error('reportDetails') <div id="networkReportDetailsError" class="invalid-feedback" role="alert">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill">Submit Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @script
    <script>
        window.addEventListener('open-network-report-modal', () => {
            new bootstrap.Modal(document.getElementById('networkReportModal')).show();
        });

        window.addEventListener('close-network-report-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('networkReportModal'))?.hide();
        });
    </script>
    @endscript
</div>
