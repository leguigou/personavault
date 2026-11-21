<?php
declare(strict_types=1);

final class PersonaVaultDatabase extends PDO
{
    private const OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    private readonly string $dsn;
    private ?PDO $reader = null;
    private bool $sqlTransaction = false;

    public function __construct(string $path)
    {
        $this->dsn = 'sqlite:' . $path;
        $isNew = $path !== ':memory:' && !is_file($path);
        parent::__construct($this->dsn, null, null, self::OPTIONS);
        if ($isNew) {
            @chmod($path, 0600);
        }
        parent::exec('PRAGMA busy_timeout = 15000');
        parent::exec('PRAGMA journal_mode = WAL');
        parent::exec('PRAGMA foreign_keys = ON');
        parent::setAttribute(PDO::ATTR_STATEMENT_CLASS, [PersonaVaultWriteStatement::class, [function (): void {
            // Un curseur déjà ouvert reste utilisable. Une nouvelle lecture
            // doit voir les écritures même si cet ancien curseur vit encore.
            $this->reader = null;
        }]]);
    }

    public function readConnection(): PDO
    {
        if ($this->sqlTransaction || $this->inTransaction() || $this->dsn === 'sqlite::memory:') {
            return $this;
        }
        if ($this->reader === null) {
            $reader = new PDO($this->dsn, null, null, self::OPTIONS);
            $reader->exec('PRAGMA busy_timeout = 15000');
            $reader->exec('PRAGMA foreign_keys = ON');
            $reader->exec('PRAGMA query_only = ON');
            $this->reader = $reader;
        }
        return $this->reader;
    }

    private static function isRead(string $sql): bool
    {
        // SELECT et les seules lectures PRAGMA utilisées par l'application.
        return preg_match('/^\s*(?:SELECT\b|PRAGMA\s+(?:user_version\s*;?\s*$|table_info\s*\())/i', $sql) === 1;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (self::isRead($query) && ($reader = $this->readConnection()) !== $this) {
            return $reader->prepare($query, $options);
        }
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (self::isRead($query)) {
            $reader = $this->readConnection();
            if ($reader !== $this) {
                return $reader->query($query, $fetchMode, ...$fetchModeArgs);
            }
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        $stmt = parent::query($query, $fetchMode, ...$fetchModeArgs);
        if ($stmt !== false) {
            $this->reader = null;
        }
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $command = strtoupper(trim($statement));
        try {
            $affected = parent::exec($statement);
            if ($affected !== false) {
                $this->reader = null;
                if ($command === 'BEGIN IMMEDIATE') {
                    // PHP 8.2 ne suit pas BEGIN IMMEDIATE lancé en SQL dans inTransaction().
                    $this->sqlTransaction = true;
                }
            }
            return $affected;
        } finally {
            if ($command === 'COMMIT' || $command === 'ROLLBACK') {
                $this->sqlTransaction = false;
            }
        }
    }
}

final class PersonaVaultWriteStatement extends PDOStatement
{
    protected function __construct(private readonly Closure $afterExecute)
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        if ($ok) {
            ($this->afterExecute)();
        }
        return $ok;
    }
}
