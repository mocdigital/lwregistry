<?php

use App\Models\ChatbotConversation;
use App\Models\ChatbotDocument;
use App\Models\ChatbotLead;
use App\Models\ChatbotLesson;
use App\Models\ChatbotSetting;
use App\Support\Chatbot\LearningBank;
use App\Support\Chatbot\KnowledgeBank;
use App\Support\Chatbot\PageKnowledge;
use App\Support\Chatbot\SiteKnowledge;
use App\Support\Chatbot\TextExtractor;
use App\Support\Chatbot\TrainingLibrary;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/*
 * The sales assistant's knowledge bank.
 *
 * Upload a document, its text is read once and split into passages, and a
 * visitor's question is answered from those passages. Nothing leaves this
 * server: the assistant retrieves, it does not generate, so everything it says
 * is something written here and pointable at.
 *
 * The unanswered questions below the documents are the other half of the tool.
 * They are a list of what customers are asking that the material does not
 * cover — which is the brief for the next document to upload.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Sales Assistant – Admin Dashboard',
        'dataPage' => 'assistant',
        'pageTitle' => 'Sales Assistant',
    ]
)] class extends Component {
    use WithFileUploads;

    public $file;

    public string $title = '';

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'mimetypes:' . implode(',', array_keys(TextExtractor::ACCEPTED)),
                'mimes:' . implode(',', TextExtractor::EXTENSIONS),
                'max:20480',
            ],
        ]);

        app(KnowledgeBank::class)->add($this->file, $this->title, auth()->id());

        $this->reset(['file', 'title']);
    }

    /**
     * Re-read the registry's own facts.
     *
     * Fee settings, category counts and the number of verified listings all
     * change, and the assistant quoting last year's figures is worse than it
     * saying it does not know. This rewrites only the entries the application
     * maintains; anything an administrator uploaded is untouched.
     */
    public function refreshSiteKnowledge(): void
    {
        SiteKnowledge::refresh();
        // The curated Q&A library rides along on the same button: both are
        // knowledge the platform ships rather than an administrator uploads, so
        // refreshing one without the other would leave the bank half current.
        TrainingLibrary::refresh();
        // And the pages' own copy, so the assistant answers from what the site
        // actually says.
        PageKnowledge::refresh();
    }

    /** Re-read a document, for a failure that has since been fixed. */
    public function reindex(int $id): void
    {
        app(KnowledgeBank::class)->index(ChatbotDocument::findOrFail($id));
    }

    /*
     * Learning from support. When an administrator answers a question the bank
     * could not, that answer is captured as a lesson; approving it writes it
     * into the bank so the next visitor to ask is answered by the assistant.
     */
    public function approveLesson(int $id): void
    {
        LearningBank::approve(ChatbotLesson::findOrFail($id));
    }

    public function dismissLesson(int $id): void
    {
        LearningBank::dismiss(ChatbotLesson::findOrFail($id));
    }

    /** Learn from every reply at once, rather than holding each for review. */
    public function toggleAutoLearn(): void
    {
        ChatbotSetting::put(
            ChatbotSetting::AUTO_LEARN,
            ! ChatbotSetting::bool(ChatbotSetting::AUTO_LEARN),
        );
    }

    public function remove(int $id): void
    {
        ChatbotDocument::findOrFail($id)->deleteWithFile();
    }

    public function with(): array
    {
        return [
            'documents' => ChatbotDocument::query()->latest()->get(),
            'passages' => ChatbotDocument::query()->sum('passage_count'),
            /*
             * What the bank could not answer. Newest first, and only the
             * unanswered — the answered ones are working as intended and
             * reading them is not what this page is for.
             */
            'unanswered' => ChatbotConversation::query()
                ->where('answered', false)
                ->latest()
                ->take(50)
                ->get(),
            /*
             * Who the assistant has been talking to. The other half of running
             * one: a visitor who asks about fees and leaves is a lost enquiry,
             * and with a name and an address somebody can follow it up.
             */
            'leads' => ChatbotLead::query()->latest()->take(50)->get(),
            'leadCount' => ChatbotLead::query()->count(),
            'asked' => ChatbotConversation::query()->count(),
            'answeredCount' => ChatbotConversation::query()->where('answered', true)->count(),
            // Lessons waiting to be learned, and whether learning is automatic.
            'lessons' => ChatbotLesson::query()
                ->where('status', ChatbotLesson::STATUS_PENDING)
                ->latest()
                ->take(50)
                ->get(),
            'learnedCount' => ChatbotLesson::query()->where('status', ChatbotLesson::STATUS_APPROVED)->count(),
            'autoLearn' => ChatbotSetting::bool(ChatbotSetting::AUTO_LEARN),
        ];
    }
}; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header fw-semibold">Add to the knowledge bank</div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="mb-2">
                        <label for="doc-title" class="form-label small">What is this about?</label>
                        <input id="doc-title" type="text" class="form-control form-control-sm" wire:model="title" placeholder="Registration fees and what they cover">
                        @error('title') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-2">
                        <label for="doc-file" class="form-label small">Document</label>
                        <input id="doc-file" type="file" class="form-control form-control-sm" wire:model="file" accept=".pdf,.docx,.txt,.md,.csv">
                        <div class="form-text small">
                            PDF, Word, text, Markdown or CSV. A scanned PDF has no text in it and will be
                            rejected — run it through OCR first.
                        </div>
                        @error('file') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                    </div>

                    <button class="btn btn-primary btn-sm" type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">Add document</span>
                        <span wire:loading wire:target="save">Reading it…</span>
                    </button>
                </form>

                <hr class="my-3">

                {{-- The registry describes itself already; this reads those
                     facts rather than asking somebody to retype them. --}}
                <form wire:submit="refreshSiteKnowledge">
                    <button class="btn btn-outline-secondary btn-sm w-100" type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="refreshSiteKnowledge">Refresh registry knowledge</span>
                        <span wire:loading wire:target="refreshSiteKnowledge">Reading the registry…</span>
                    </button>
                    <div class="form-text small">
                        Rewrites the entries titled “Registry: …” and “Common questions — …” from the
                        application's own settings and content. Documents you uploaded are untouched.
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Documents</span>
                <span class="small text-muted">{{ $passages }} passages</span>
            </div>
            <div class="list-group list-group-flush">
                @forelse ($documents as $document)
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small">{{ $document->title }}</div>
                                <div class="small text-muted text-truncate">{{ $document->original_name }}</div>

                                @if ($document->isReady())
                                    <span class="badge bg-success mt-1">{{ $document->passage_count }} passages</span>
                                @else
                                    <span class="badge bg-danger mt-1">{{ $document->status }}</span>
                                    {{-- A document that yielded nothing must be visibly broken rather
                                         than quietly useless. --}}
                                    <div class="small text-danger mt-1">{{ $document->error }}</div>
                                @endif
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0 align-items-center">
                                {{-- Generated documents (facts, Q&A, pages, learned) have no
                                     file to re-read; they are rebuilt by "Refresh". --}}
                                @if ($document->isGenerated())
                                    <span class="badge bg-light text-muted">Generated</span>
                                @else
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="reindex({{ $document->id }})">
                                        Re-read
                                    </button>
                                @endif
                                <button class="btn btn-sm btn-outline-danger" wire:click="remove({{ $document->id }})">
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-3 small text-muted">
                        Nothing in the bank yet. The assistant will say it cannot help until something is added.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        {{-- Learning from support: an admin's answers become the assistant's. --}}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-semibold">Learning from support</span>
                    <div class="small text-muted">{{ $learnedCount }} learned so far</div>
                </div>
                <label class="form-check form-switch mb-0 small" title="Learn from every reply at once, instead of reviewing each">
                    <input class="form-check-input" type="checkbox" wire:click="toggleAutoLearn" @checked($autoLearn)>
                    <span class="form-check-label">Auto-learn</span>
                </label>
            </div>
            <div class="card-body py-2 small text-muted">
                When you answer a visitor in Live Chat, the reply is captured here with the
                question it answered. Approve one and the assistant will use it next time
                someone asks the same thing.
            </div>
            <div class="list-group list-group-flush overflow-auto" style="max-height: 40vh;">
                @forelse ($lessons as $lesson)
                    <div class="list-group-item" wire:key="lesson-{{ $lesson->id }}">
                        <div class="small text-muted">They asked</div>
                        <div class="small fw-semibold mb-1">{{ \Illuminate\Support\Str::limit($lesson->question, 160) }}</div>
                        <div class="small text-muted">You answered</div>
                        <div class="small mb-2">{{ \Illuminate\Support\Str::limit($lesson->answer, 240) }}</div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-primary" wire:click="approveLesson({{ $lesson->id }})">
                                Teach the assistant
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" wire:click="dismissLesson({{ $lesson->id }})">
                                Dismiss
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="p-3 small text-muted">
                        Nothing to review. Replies you send in Live Chat show up here to teach the assistant.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">People the assistant met</span>
                <span class="small text-muted">{{ $leadCount }}</span>
            </div>
            <div class="list-group list-group-flush overflow-auto" style="max-height: 26vh;">
                @forelse ($leads as $lead)
                    <div class="list-group-item">
                        <div class="small fw-semibold">{{ $lead->name }}</div>
                        <div class="small text-muted">
                            {{ $lead->email ?: 'No email given' }} · {{ $lead->created_at?->diffForHumans() }}
                        </div>
                    </div>
                @empty
                    <div class="p-3 small text-muted">
                        Nobody yet. The flow asks for a name before it answers anything.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="fw-semibold">What people asked and it could not answer</div>
                <div class="small text-muted">{{ $answeredCount }} of {{ $asked }} answered</div>
            </div>
            <div class="list-group list-group-flush overflow-auto" style="max-height: 60vh;">
                @forelse ($unanswered as $row)
                    <div class="list-group-item">
                        <div class="small">{{ $row->question }}</div>
                        <div class="small text-muted">{{ $row->created_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <div class="p-3 small text-muted">
                        Nothing unanswered. Either the bank covers what people are asking, or nobody has asked yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
