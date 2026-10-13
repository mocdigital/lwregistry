<?php

declare(strict_types=1);

namespace App\Http\Controllers\Local;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SqliteBrowserController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless($request->user()?->isSuperAdmin(), Response::HTTP_FORBIDDEN);

        $connection = DB::connection();
        abort_unless('sqlite' === $connection->getDriverName(), Response::HTTP_BAD_REQUEST, 'The active database connection is not SQLite.');

        $tables = collect($connection->select("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
              AND name NOT LIKE 'sqlite_%'
            ORDER BY name
        "))
            ->map(static fn (object $table): string => (string) $table->name)
            ->values();

        $selectedTable = (string) $request->string('table')->trim();

        if ('' === $selectedTable || ! $tables->contains($selectedTable)) {
            $selectedTable = (string) $tables->first();
        }

        $columns = collect();
        $rows = null;
        $search = (string) $request->string('search')->trim();
        $perPage = (int) $request->integer('per_page', 50);
        $perPage = in_array($perPage, [25, 50, 100, 250], true) ? $perPage : 50;

        if ('' !== $selectedTable) {
            $columns = collect($connection->select("PRAGMA table_info({$this->quoteSqliteIdentifier($selectedTable)})"))
                ->map(static fn ($column): array => [
                    'name' => (string) $column->name,
                    'type' => (string) $column->type,
                    'nullable' => 0 === (int) $column->notnull,
                    'default' => $column->dflt_value,
                    'primary' => 1 === (int) $column->pk,
                ])
                ->values();

            $query = $connection->table($selectedTable);

            if ('' !== $search && $columns->isNotEmpty()) {
                $query->where(function ($builder) use ($columns, $search, $connection): void {
                    $grammar = $connection->getQueryGrammar();

                    foreach ($columns as $column) {
                        $wrapped = $grammar->wrap($column['name']);
                        $builder->orWhereRaw("CAST({$wrapped} AS TEXT) LIKE ?", ["%{$search}%"]);
                    }
                });
            }

            $primaryColumn = $columns->firstWhere('primary', true)['name'] ?? null;
            $firstColumn = $columns->first()['name'] ?? null;

            if (is_string($primaryColumn) && '' !== $primaryColumn) {
                $query->orderBy($primaryColumn);
            } elseif (is_string($firstColumn) && '' !== $firstColumn) {
                $query->orderBy($firstColumn);
            }

            $rows = $query
                ->paginate($perPage)
                ->withQueryString();
        }

        return view('local.sqlite-browser.index', [
            'connectionName' => $connection->getName(),
            'databasePath' => (string) config('database.connections.sqlite.database'),
            'perPage' => $perPage,
            'rows' => $rows,
            'search' => $search,
            'selectedTable' => $selectedTable,
            'tables' => $tables,
            'columns' => $columns,
        ]);
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return "'" . str_replace("'", "''", $identifier) . "'";
    }
}
