<?php

namespace App\Sandbox;

use App\Models\Project;
use App\Sandbox\Agents\OneOffPrompt;

/**
 * Writes a query from a plain-words request for the database browser's runner (DB-003): the project's AI gets the
 * database's tables and columns (never its rows) and the editor's current query, and answers in the database's own
 * language.
 */
class QueryWriter
{
    /** The most schema sent with a request, so a database with hundreds of tables can't fill the model's context. */
    public const MAX_SCHEMA_CHARACTERS = 30_000;

    public function __construct(protected OneOffPrompt $ai) {}

    /**
     * @param  'sqlite'|'pgsql'|'mysql'|'mongodb'  $driver
     * @param  list<array{name: string, type: string, columns: list<string>, database?: string, collection?: string}>  $tables
     *
     * @throws SandboxException when the AI can't be asked or doesn't write a query
     * @throws Agents\ChatGptSignInFailed when a ChatGPT sign-in can't be refreshed
     */
    public function write(Project $project, string $driver, array $tables, string $request, ?string $current = null): string
    {
        $query = $this->queryFrom($this->ai->ask($project, $this->prompt($driver, $tables, $request, $current)));

        if ($query === '') {
            throw new SandboxException(__("The AI didn't write a query. Try describing it another way."));
        }

        return $query;
    }

    /**
     * @param  list<array{name: string, type: string, columns: list<string>, database?: string, collection?: string}>  $tables
     */
    protected function prompt(string $driver, array $tables, string $request, ?string $current): string
    {
        $language = match ($driver) {
            'sqlite' => 'one SQLite SQL statement',
            'pgsql' => 'one PostgreSQL SQL statement',
            'mysql' => 'one MySQL SQL statement',
            default => <<<'TEXT'
                one MongoDB command in mongosh syntax. Only these are understood: db.<collection>.find(filter, projection)
                with .sort(), .skip(), .limit() or .count() chained on, findOne, aggregate([...]), countDocuments, distinct,
                insertOne, insertMany, updateOne, updateMany, replaceOne, deleteOne, deleteMany, db.getCollection('name')
                for names that aren't plain identifiers, and db.runCommand({...}). Literals like ObjectId('...'),
                ISODate('...') and /regex/i are fine; no other JavaScript (no variables, functions or loops). When a
                collection is listed as "database.collection", put "use database" on the line before the command and
                refer to the collection by its own name
                TEXT,
        };
        $schema = $this->schema($tables);
        $currentQuery = $current !== null && trim($current) !== ''
            ? "\n\nThe query in the editor now, which the request may be refining:\n<current-query>\n".trim($current)."\n</current-query>"
            : '';

        return <<<PROMPT
        Write {$language} for this request about the app's database: {$request}

        Only read data unless the request asks to change it. When searching for records, return at most 50 (LIMIT 50,
        or .limit(50)), and match text case-insensitively unless the request says otherwise. Use only the tables and
        columns listed here.

        <schema>
        {$schema}
        </schema>{$currentQuery}

        Reply with only the query, in one code block, with no explanation. Don't use any tools.
        PROMPT;
    }

    /**
     * One line per table: its name, (view,) and columns, cut short for very large databases.
     *
     * @param  list<array{name: string, type: string, columns: list<string>}>  $tables
     */
    protected function schema(array $tables): string
    {
        $lines = '';

        foreach ($tables as $table) {
            $line = $table['name'].($table['type'] === 'view' ? ' (view)' : '').': '.($table['columns'] === [] ? '(columns unknown)' : implode(', ', $table['columns']))."\n";

            if (strlen($lines) + strlen($line) > self::MAX_SCHEMA_CHARACTERS) {
                return $lines.'(more tables not shown)';
            }

            $lines .= $line;
        }

        return $lines === '' ? '(no tables yet)' : rtrim($lines);
    }

    /**
     * The query from the model's answer: its first code block, or the whole answer when it has none.
     */
    protected function queryFrom(string $text): string
    {
        return trim(preg_match('/```[\w+-]*[ \t]*\R(.*?)```/s', $text, $match) ? $match[1] : $text);
    }
}
