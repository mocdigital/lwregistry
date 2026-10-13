@extends('layouts.app')

@section('title', 'Local SQLite Browser')

@push('styles')
    <style>
        .sqlite-browser-shell {
            background: linear-gradient(180deg, #f4f9ff 0%, #ffffff 100%);
            min-height: calc(100vh - 120px);
            padding: 2rem 0 3rem;
        }

        .sqlite-browser-panel,
        .sqlite-browser-sidebar {
            background: #fff;
            border: 1px solid #dbe7f3;
            border-radius: 1rem;
            box-shadow: 0 18px 45px rgba(11, 35, 63, 0.08);
        }

        .sqlite-browser-sidebar {
            position: sticky;
            top: 1rem;
            max-height: calc(100vh - 2rem);
            overflow: auto;
        }

        .sqlite-browser-table-link {
            border-radius: 0.75rem;
            color: #17365d;
            display: block;
            padding: 0.75rem 0.9rem;
            text-decoration: none;
        }

        .sqlite-browser-table-link.active,
        .sqlite-browser-table-link:hover {
            background: #e9f4ff;
            color: #0d3b66;
        }

        .sqlite-browser-meta {
            color: #56708b;
            font-size: 0.95rem;
        }

        .sqlite-browser-code {
            background: #f3f7fb;
            border-radius: 0.5rem;
            color: #102a43;
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.9rem;
            padding: 0.2rem 0.45rem;
            word-break: break-all;
        }

        .sqlite-browser-cell {
            max-width: 22rem;
            vertical-align: top;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .sqlite-browser-empty {
            border: 1px dashed #bfd3e6;
            border-radius: 1rem;
            color: #486581;
            padding: 2rem;
            text-align: center;
        }
    </style>
@endpush

@section('content')
    <section class="sqlite-browser-shell">
        <div class="container">
            <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
                <div>
                    <p class="text-uppercase fw-semibold text-success mb-2">Local-only database tools</p>
                    <h1 class="display-6 fw-bold mb-2">SQLite Browser</h1>
                    <p class="sqlite-browser-meta mb-0">
                        Active connection: <span class="sqlite-browser-code">{{ $connectionName }}</span>
                    </p>
                    <p class="sqlite-browser-meta mb-0">
                        File: <span class="sqlite-browser-code">{{ $databasePath }}</span>
                    </p>
                </div>

                <div class="sqlite-browser-meta">
                    <strong>{{ $tables->count() }}</strong> tables available
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-3">
                    <aside class="sqlite-browser-sidebar p-3">
                        <h2 class="h5 mb-3">Tables</h2>

                        @forelse ($tables as $table)
                            <a
                                href="{{ route('local.sqlite-browser', ['table' => $table]) }}"
                                class="sqlite-browser-table-link {{ $selectedTable === $table ? 'active' : '' }}"
                            >
                                <div class="fw-semibold">{{ $table }}</div>
                            </a>
                        @empty
                            <p class="sqlite-browser-meta mb-0">No tables found in this SQLite database.</p>
                        @endforelse
                    </aside>
                </div>

                <div class="col-lg-9">
                    <div class="sqlite-browser-panel p-4">
                        @if ($selectedTable === '')
                            <div class="sqlite-browser-empty">
                                No user tables are available in the current SQLite database.
                            </div>
                        @else
                            <div class="d-flex flex-column flex-xl-row justify-content-between gap-3 mb-4">
                                <div>
                                    <h2 class="h4 mb-1">{{ $selectedTable }}</h2>
                                    <p class="sqlite-browser-meta mb-0">
                                        {{ $columns->count() }} columns
                                        @if ($rows)
                                            • {{ number_format($rows->total()) }} rows
                                        @endif
                                    </p>
                                </div>

                                <form method="GET" action="{{ route('local.sqlite-browser') }}" class="row g-2">
                                    <input type="hidden" name="table" value="{{ $selectedTable }}">
                                    <div class="col-sm-6">
                                        <input
                                            type="search"
                                            name="search"
                                            value="{{ $search }}"
                                            class="form-control"
                                            placeholder="Search across all columns"
                                        >
                                    </div>
                                    <div class="col-sm-3">
                                        <select name="per_page" class="form-select">
                                            @foreach ([25, 50, 100, 250] as $size)
                                                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} / page</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-sm-3 d-grid">
                                        <button type="submit" class="btn btn-primary">Apply</button>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive mb-4">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Column</th>
                                            <th>Type</th>
                                            <th>Flags</th>
                                            <th>Default</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($columns as $column)
                                            <tr>
                                                <td><span class="sqlite-browser-code">{{ $column['name'] }}</span></td>
                                                <td>{{ $column['type'] !== '' ? $column['type'] : 'TEXT' }}</td>
                                                <td>
                                                    @if ($column['primary'])
                                                        <span class="badge text-bg-primary">Primary key</span>
                                                    @endif

                                                    @if (! $column['nullable'])
                                                        <span class="badge text-bg-dark">Required</span>
                                                    @endif
                                                </td>
                                                <td class="sqlite-browser-cell">
                                                    {{ $column['default'] ?? 'NULL' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            @foreach ($columns as $column)
                                                <th>{{ $column['name'] }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($rows as $row)
                                            <tr>
                                                @foreach ($columns as $column)
                                                    <td class="sqlite-browser-cell">
                                                        @php($value = data_get($row, $column['name']))
                                                        @if (is_bool($value))
                                                            {{ $value ? 'true' : 'false' }}
                                                        @elseif ($value === null)
                                                            <span class="text-muted">NULL</span>
                                                        @elseif (is_array($value) || is_object($value))
                                                            <code>{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code>
                                                        @else
                                                            {{ (string) $value }}
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="{{ max($columns->count(), 1) }}" class="text-center py-4 text-muted">
                                                    No rows matched the current view.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            @if ($rows)
                                <div class="mt-3">
                                    {{ $rows->links('pagination::bootstrap-5') }}
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
