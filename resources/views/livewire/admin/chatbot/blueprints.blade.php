<?php

use App\Models\ChatbotBlueprint;
use App\Support\Chatbot\DefaultBlueprint;
use App\Support\Chatbot\SiteKnowledge;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * The conversation blueprints.
 *
 * Greeting somebody, asking their name, offering four things to ask about and
 * falling back to the knowledge bank are the same kind of step with different
 * settings. Drawn as nodes and wires they are something the people who talk to
 * customers can rearrange; written as code they are a state machine only a
 * developer can change.
 *
 * This component owns the LIST and which blueprint is live. The canvas beside
 * it is JavaScript talking to its own two endpoints — a node editor sends a
 * whole graph on save, and routing that through component state would mean
 * every drag round-tripping to the server.
 */
new #[Layout(
    'components.layouts.admin',
    [
        'title' => 'Assistant Blueprints – Admin Dashboard',
        'dataPage' => 'chatbot-blueprints',
        'pageTitle' => 'Assistant Blueprints',
    ]
)] class extends Component {
    public ?int $selectedId = null;

    public string $name = '';

    public function mount(): void
    {
        // Nothing drawn yet: install the flow that was described, as a
        // starting point rather than as the only option.
        if (0 === ChatbotBlueprint::query()->count()) {
            DefaultBlueprint::install(auth()->id());

            // The graph answers what it was drawn for; the bank answers the
            // rest. A new installation should have both from the first visitor.
            SiteKnowledge::refresh();
        }

        $this->selectedId = ChatbotBlueprint::query()->where('is_active', true)->value('id')
            ?? ChatbotBlueprint::query()->value('id');
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $blueprint = ChatbotBlueprint::create([
            'name' => $this->name,
            'created_by' => auth()->id(),
        ]);

        $this->name = '';
        $this->selectedId = $blueprint->id;
    }

    /** One live blueprint at a time; publishing another retires the current. */
    public function publish(int $id): void
    {
        ChatbotBlueprint::findOrFail($id)->publish();
    }

    public function remove(int $id): void
    {
        $blueprint = ChatbotBlueprint::findOrFail($id);

        // The live one is what visitors are talking to. Publish another first,
        // so there is never a moment with no conversation at all.
        abort_if($blueprint->is_active, 422, 'Publish another blueprint before deleting this one.');

        $blueprint->delete();
        $this->selectedId = ChatbotBlueprint::query()->value('id');
    }

    public function with(): array
    {
        return ['blueprints' => ChatbotBlueprint::query()->latest()->get()];
    }
}; ?>

<div class="row g-3" wire:ignore.self>
    <div class="col-12 col-lg-3">
        <div class="card mb-3">
            <div class="card-header fw-semibold">New blueprint</div>
            <div class="card-body">
                <form wire:submit="create">
                    <input type="text" class="form-control form-control-sm mb-2" placeholder="Name" wire:model="name">
                    @error('name') <div class="small text-danger mb-2">{{ $message }}</div> @enderror
                    <button class="btn btn-sm btn-primary w-100" type="submit">Create</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Blueprints</div>
            <div class="list-group list-group-flush">
                @foreach ($blueprints as $row)
                    <div class="list-group-item {{ $row->id === $selectedId ? 'bg-light' : '' }}">
                        <button type="button" class="btn btn-link p-0 text-decoration-none text-start" wire:click="select({{ $row->id }})">
                            <span class="fw-semibold small">{{ $row->name }}</span>
                        </button>
                        @if ($row->is_active)
                            <span class="badge bg-success ms-1">Live</span>
                        @endif

                        <div class="mt-2 d-flex gap-1">
                            @unless ($row->is_active)
                                <button class="btn btn-sm btn-outline-success" wire:click="publish({{ $row->id }})">Publish</button>
                                <button class="btn btn-sm btn-outline-danger" wire:click="remove({{ $row->id }})">Delete</button>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">Canvas</span>
                <div class="d-flex gap-1 flex-wrap" id="bp-palette"></div>
            </div>

            {{-- wire:ignore: the canvas is drawn and owned by JavaScript, and
                 Livewire re-rendering this subtree would wipe every node. --}}
            <div class="card-body p-0" wire:ignore>
                <div id="bp-canvas" data-blueprint="{{ $selectedId }}" style="height: 62vh; background: #f8fafc;"></div>
            </div>

            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted" id="bp-status">Drag a node from the buttons above. Drag pin to pin to wire them.</span>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="bp-reload" type="button">Revert</button>
                    <button class="btn btn-sm btn-primary" id="bp-save" type="button">Save graph</button>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">Selected node</div>
            <div class="card-body" id="bp-inspector">
                <p class="small text-muted mb-0">Click a node to edit what it says.</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="{{ Vite::asset('resources/js/admin/blueprint-canvas.js') }}" type="module"></script>
@endpush
