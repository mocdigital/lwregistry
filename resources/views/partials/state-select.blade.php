{{--
  Searchable state/region dropdown bound to a Livewire model.

  Params:
    $model        — Livewire property name (e.g. 'bizState')
    $value        — current value
    $states       — collection/array of state names
    $placeholder  — input placeholder text
    $disabled     — whether the picker should be disabled
    $selectMethod — optional Livewire method called on selection
--}}
@php
    $stateOptions = collect($states ?? [])->values();
    $placeholder = $placeholder ?? 'Select State / Region';
    $disabled = (bool) ($disabled ?? false);
    $selectMethod = $selectMethod ?? null;
@endphp

<div
    x-data="stateSelect(@js($value ?? ''), @js($model), @js($stateOptions), @js($disabled), @js($selectMethod))"
    class="position-relative"
    wire:ignore
    @click.outside="open = false"
>
    <div class="input-group">
        <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
        <input
            type="text"
            class="form-control"
            placeholder="{{ $placeholder }}"
            x-model="query"
            x-ref="searchInput"
            autocomplete="off"
            :disabled="disabled"
            @focus="openForSearch()"
            @click="openForSearch()"
            @keydown.escape.prevent="open = false"
            @change="commitTyped()"
            @input="resumeSearch(); syncTyped()"
        >
    </div>

    <ul
        class="list-group position-absolute w-100 shadow-sm"
        style="z-index:1084; max-height:240px; overflow-y:auto;"
        x-show="open && !disabled"
        x-cloak
    >
        <template x-for="state in filtered()" :key="state">
            <li
                class="list-group-item list-group-item-action"
                style="cursor:pointer;"
                @pointerdown.prevent="choose(state)"
                x-text="state"
            ></li>
        </template>
        <li class="list-group-item text-muted small" x-show="filtered().length === 0">No match</li>
    </ul>
</div>

@once
    <script>
        function stateSelect(initial, model, options, disabled, selectMethod) {
            return {
                open: false,
                query: initial || '',
                selected: initial || '',
                all: options || [],
                disabled: disabled === true,
                selecting: false,
                closeTimer: null,
                selectionSeq: 0,
                filtered() {
                    const q = (this.query || '').toLowerCase();

                    if (!q) return this.all.slice(0, 80);

                    return this.all
                        .filter(state => state.toLowerCase().includes(q))
                        .slice(0, 80);
                },
                openForSearch() {
                    if (this.disabled || this.selecting) return;

                    this.open = true;
                },
                resumeSearch() {
                    if (this.disabled) return;

                    this.selecting = false;

                    if (this.closeTimer) {
                        clearTimeout(this.closeTimer);
                    }

                    this.open = true;
                },
                closeAfterSelect() {
                    this.selecting = true;
                    this.open = false;

                    if (this.closeTimer) {
                        clearTimeout(this.closeTimer);
                    }

                    this.$nextTick(() => {
                        this.open = false;

                        if (this.$refs.searchInput) {
                            this.$refs.searchInput.blur();
                        }
                    });

                    this.closeTimer = setTimeout(() => {
                        this.selecting = false;
                    }, 250);
                },
                syncTyped() {
                    const q = (this.query || '').trim().toLowerCase();
                    const match = this.all.find(state => state.toLowerCase() === q) || '';

                    this.selected = match;
                },
                commitTyped() {
                    if (this.disabled || this.selecting) return;

                    const q = (this.query || '').trim().toLowerCase();
                    const match = this.all.find(state => state.toLowerCase() === q) || '';

                    this.selected = match;

                    if (selectMethod) {
                        const seq = ++this.selectionSeq;

                        this.$wire.call(selectMethod, match).then(() => {
                            if (seq !== this.selectionSeq) return;

                            this.selected = match;
                            this.query = match || this.query;
                            this.closeAfterSelect();
                        });

                        return;
                    }

                    this.$wire.set(model, match, false);
                },
                choose(state) {
                    const seq = ++this.selectionSeq;

                    this.selected = state;
                    this.query = state;
                    this.closeAfterSelect();

                    if (selectMethod) {
                        this.$wire.call(selectMethod, state).then(() => {
                            if (seq !== this.selectionSeq) return;

                            this.selected = state;
                            this.query = state;
                            this.closeAfterSelect();
                        });

                        return;
                    }

                    this.$wire.set(model, state, false);
                },
            };
        }
    </script>
@endonce
