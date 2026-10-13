<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class SqliteDumpImporter
{
    /**
     * Framework/runtime tables that should not be replayed into a live database.
     *
     * @var list<string>
     */
    private const DEFAULT_EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'sessions',
        'sqlite_sequence',
    ];

    /**
     * @param  list<string>  $excludedTables
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly array $excludedTables = self::DEFAULT_EXCLUDED_TABLES,
    ) {}

    /**
     * @var array<string, list<string>>
     */
    private array $generatedColumnsByTable = [];

    public function import(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException("SQLite dump not found at [{$path}].");
        }

        $columnsByTable = $this->columnsByTableFromDump($path);
        $tables = $this->tablesFromDump($path);

        if ($tables === []) {
            return;
        }

        $this->assertTablesExist($tables);
        $this->assertDumpColumnsExist($columnsByTable, $tables);

        $this->withoutForeignKeyChecks(function () use ($path, $tables, $columnsByTable): void {
            foreach (array_reverse($tables) as $table) {
                $this->connection->table($table)->delete();
            }

            foreach ($this->insertStatementsFromDump($path, $columnsByTable) as $statement) {
                $this->connection->unprepared($statement);
            }
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function columnsByTableFromDump(string $path): array
    {
        $columns = [];

        foreach ($this->statementsFromDump($path) as $statement) {
            $table = $this->tableForCreateStatement($statement);

            if ($table === null || $this->shouldSkipTable($table)) {
                continue;
            }

            $columns[$table] = $this->columnsForCreateStatement($statement);
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function tablesFromDump(string $path): array
    {
        $tables = [];

        foreach ($this->statementsFromDump($path) as $statement) {
            $table = $this->tableForInsertStatement($statement);

            if ($table === null || $this->shouldSkipTable($table)) {
                continue;
            }

            $tables[$table] = true;
        }

        return array_keys($tables);
    }

    /**
     * @param  array<string, list<string>>  $columnsByTable
     * @return iterable<string>
     */
    private function insertStatementsFromDump(string $path, array $columnsByTable): iterable
    {
        foreach ($this->statementsFromDump($path) as $statement) {
            $table = $this->tableForInsertStatement($statement);

            if ($table === null || $this->shouldSkipTable($table)) {
                continue;
            }

            if (! array_key_exists($table, $columnsByTable)) {
                throw new RuntimeException("SQLite dump inserts into [{$table}] before declaring its columns.");
            }

            yield $this->rewriteInsertStatementWithSourceColumns($statement, $columnsByTable[$table], $table);
        }
    }

    /**
     * @return iterable<string>
     */
    private function statementsFromDump(string $path): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read SQLite dump at [{$path}].");
        }

        try {
            $statement = '';

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($trimmed === '' || $trimmed === 'BEGIN TRANSACTION;' || $trimmed === 'COMMIT;' || $trimmed === 'PRAGMA foreign_keys=OFF;') {
                    continue;
                }

                $statement .= $trimmed;

                if (! str_ends_with($trimmed, ';')) {
                    continue;
                }

                yield $statement;

                $statement = '';
            }

            if ($statement !== '') {
                throw new RuntimeException("SQLite dump [{$path}] ended mid-statement.");
            }
        } finally {
            fclose($handle);
        }
    }

    private function tableForCreateStatement(string $statement): ?string
    {
        if (! preg_match('/^CREATE TABLE IF NOT EXISTS\s+"?([^"\s(]+)"?\s*\(/i', $statement, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function tableForInsertStatement(string $statement): ?string
    {
        if (! preg_match('/^INSERT INTO\s+(?:"([^"]+)"|`([^`]+)`|([^\s(]+))\s+VALUES\b/is', $statement, $matches)) {
            return null;
        }

        return $matches[1] !== ''
            ? $matches[1]
            : ($matches[2] !== '' ? $matches[2] : $matches[3]);
    }

    /**
     * @return list<string>
     */
    private function columnsForCreateStatement(string $statement): array
    {
        $definition = $this->betweenOuterParentheses($statement);
        $columns = [];

        foreach ($this->splitSqlList($definition) as $part) {
            if (preg_match('/^\s*["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s+/u', $part, $matches)) {
                $name = mb_strtolower($matches[1]);

                if (! in_array($name, ['constraint', 'foreign', 'primary', 'unique', 'check'], true)) {
                    $columns[] = $matches[1];
                }
            }
        }

        if ($columns === []) {
            throw new RuntimeException('Unable to read columns from SQLite dump statement: ' . mb_substr($statement, 0, 160));
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     */
    private function rewriteInsertStatementWithSourceColumns(string $statement, array $columns, string $table): string
    {
        $statement = $this->normalizeSqliteFunctions($statement);

        if (! preg_match('/^INSERT INTO\s+(?:"[^"]+"|`[^`]+`|[^\s(]+)\s+(VALUES\b.*);$/is', $statement, $matches)) {
            throw new RuntimeException("Unable to parse INSERT statement for [{$table}].");
        }

        [$columns, $valuesExpression] = $this->withoutGeneratedColumns($columns, $matches[1], $table);

        return sprintf(
            'INSERT INTO %s (%s) %s;',
            $this->quoteIdentifier($table),
            implode(',', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns)),
            $valuesExpression,
        );
    }

    /**
     * @param  list<string>  $columns
     * @return array{0: list<string>, 1: string}
     */
    private function withoutGeneratedColumns(array $columns, string $valuesExpression, string $table): array
    {
        $generatedColumns = array_fill_keys(
            array_map(fn (string $column): string => mb_strtolower($column), $this->generatedColumnsForTable($table)),
            true,
        );

        if ($generatedColumns === []) {
            return [$columns, $valuesExpression];
        }

        $generatedIndexes = [];
        $writableColumns = [];

        foreach ($columns as $index => $column) {
            if (array_key_exists(mb_strtolower($column), $generatedColumns)) {
                $generatedIndexes[$index] = true;

                continue;
            }

            $writableColumns[] = $column;
        }

        if ($generatedIndexes === []) {
            return [$columns, $valuesExpression];
        }

        if (! preg_match('/^VALUES\s*\((.*)\)$/is', trim($valuesExpression), $matches)) {
            throw new RuntimeException("Unable to remove generated column values from INSERT statement for [{$table}].");
        }

        $values = $this->splitSqlList($matches[1]);

        if (count($columns) !== count($values)) {
            throw new RuntimeException(
                "SQLite dump column/value mismatch for [{$table}]: " . count($columns) . ' columns, ' . count($values) . ' values.'
            );
        }

        $writableValues = [];

        foreach ($values as $index => $value) {
            if (! array_key_exists($index, $generatedIndexes)) {
                $writableValues[] = $value;
            }
        }

        return [$writableColumns, 'VALUES(' . implode(',', $writableValues) . ')'];
    }

    private function shouldSkipTable(string $table): bool
    {
        return in_array($table, $this->excludedTables, true);
    }

    private function normalizeSqliteFunctions(string $statement): string
    {
        return (string) preg_replace_callback(
            "/unistr\\('((?:[^']|'')*)'\\)/i",
            function (array $matches): string {
                $value = str_replace("''", "'", $matches[1]);
                $value = preg_replace_callback(
                    '/\\\\u([0-9a-fA-F]{4})|\\\\U([0-9a-fA-F]{8})/',
                    static function (array $unicodeMatches): string {
                        $codepoint = $unicodeMatches[1] !== ''
                            ? hexdec($unicodeMatches[1])
                            : hexdec($unicodeMatches[2]);

                        return mb_chr($codepoint, 'UTF-8');
                    },
                    $value,
                ) ?? $value;

                $value = strtr($value, [
                    '\\n' => "\n",
                    '\\r' => "\r",
                    '\\t' => "\t",
                    "\\'" => "'",
                    '\\"' => '"',
                    '\\\\' => '\\',
                ]);

                return "'" . str_replace("'", "''", $value) . "'";
            },
            $statement,
        );
    }

    private function betweenOuterParentheses(string $statement): string
    {
        $start = strpos($statement, '(');
        $end = strrpos($statement, ')');

        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Unable to find SQL definition parentheses.');
        }

        return substr($statement, $start + 1, $end - $start - 1);
    }

    /**
     * @return list<string>
     */
    private function splitSqlList(string $sql): array
    {
        $items = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                $current .= $char;

                if ($inString && ($sql[$i + 1] ?? '') === "'") {
                    $current .= "'";
                    $i++;
                    continue;
                }

                $inString = ! $inString;
                continue;
            }

            if (! $inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $items[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if ($current !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    /**
     * @param  list<string>  $tables
     */
    private function assertTablesExist(array $tables): void
    {
        $missing = [];

        foreach ($tables as $table) {
            if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
                $missing[] = $table;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'SQLite dump references tables missing from the current schema: ' . implode(', ', $missing)
        );
    }

    /**
     * @param  array<string, list<string>>  $columnsByTable
     * @param  list<string>  $tables
     */
    private function assertDumpColumnsExist(array $columnsByTable, array $tables): void
    {
        $missing = [];
        $schema = $this->connection->getSchemaBuilder();

        foreach ($tables as $table) {
            if ($this->shouldSkipTable($table) || ! $schema->hasTable($table)) {
                continue;
            }

            if (! array_key_exists($table, $columnsByTable)) {
                $missing[] = "{$table}.[source columns not declared]";

                continue;
            }

            $columns = $columnsByTable[$table];

            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'SQLite dump references columns missing from the current schema: ' . implode(', ', $missing)
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return list<string>
     */
    private function generatedColumnsForTable(string $table): array
    {
        if (array_key_exists($table, $this->generatedColumnsByTable)) {
            return $this->generatedColumnsByTable[$table];
        }

        $columns = match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlGeneratedColumnsForTable($table),
            'sqlite' => $this->sqliteGeneratedColumnsForTable($table),
            default => [],
        };

        return $this->generatedColumnsByTable[$table] = $columns;
    }

    /**
     * @return list<string>
     */
    private function mysqlGeneratedColumnsForTable(string $table): array
    {
        $rows = $this->connection->select(
            <<<'SQL'
select column_name
from information_schema.columns
where table_schema = ?
  and table_name = ?
  and (
      coalesce(generation_expression, '') <> ''
      or extra like '%GENERATED%'
  )
SQL,
            [$this->connection->getDatabaseName(), $table],
        );

        return array_values(array_map(
            fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME),
            $rows,
        ));
    }

    /**
     * @return list<string>
     */
    private function sqliteGeneratedColumnsForTable(string $table): array
    {
        $rows = $this->connection->select('PRAGMA table_xinfo(' . $this->quoteSqliteIdentifier($table) . ')');
        $columns = [];

        foreach ($rows as $row) {
            if ((int) ($row->hidden ?? 0) > 0) {
                $columns[] = (string) $row->name;
            }
        }

        return $columns;
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function withoutForeignKeyChecks(callable $callback): void
    {
        $driver = $this->connection->getDriverName();
        $originalSqlMode = null;

        if ($driver === 'sqlite') {
            $this->connection->statement('PRAGMA foreign_keys = OFF');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $originalSqlMode = $this->sessionSqlMode();

            // SQLite string literals do not treat backslashes as escapes. Match
            // that while replaying raw dump INSERTs, especially for JSON values.
            $this->connection->statement('SET SESSION sql_mode = ?', [
                $this->sqlModeWithNoBackslashEscapes($originalSqlMode),
            ]);
            $this->connection->statement('SET FOREIGN_KEY_CHECKS=0');
        } else {
            throw new RuntimeException("SQLite dump import is not supported on the [{$driver}] driver.");
        }

        try {
            $callback();
        } finally {
            if ($driver === 'sqlite') {
                $this->connection->statement('PRAGMA foreign_keys = ON');
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $this->connection->statement('SET FOREIGN_KEY_CHECKS=1');

                if ($originalSqlMode !== null) {
                    $this->connection->statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
                }
            }
        }
    }

    private function sessionSqlMode(): string
    {
        $row = $this->connection->selectOne('select @@SESSION.sql_mode as sql_mode');

        return (string) ($row->sql_mode ?? '');
    }

    private function sqlModeWithNoBackslashEscapes(string $sqlMode): string
    {
        $modes = array_values(array_filter(array_map('trim', explode(',', $sqlMode))));

        foreach ($modes as $mode) {
            if (strcasecmp($mode, 'NO_BACKSLASH_ESCAPES') === 0) {
                return implode(',', $modes);
            }
        }

        $modes[] = 'NO_BACKSLASH_ESCAPES';

        return implode(',', $modes);
    }
}
