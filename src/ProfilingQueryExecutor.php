<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorProfiler;

use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Profiler\ProfilerInterface;

final readonly class ProfilingQueryExecutor implements QueryExecutorInterface
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private ProfilerInterface $profiler,
    ) {}

    public function execute(QueryInterface $query): int
    {
        $compiled = $query->compile();

        return $this->profiler->trace(
            'db.query',
            ['operation' => 'execute', 'sql' => $compiled->sql],
            fn (): int => $this->executor->execute($compiled),
        );
    }

    public function fetch(QueryInterface $query): array
    {
        $compiled = $query->compile();

        return $this->profiler->trace(
            'db.query',
            ['operation' => 'fetch', 'sql' => $compiled->sql],
            fn (): array => $this->executor->fetch($compiled),
        );
    }

    public function fetchAll(QueryInterface $query): array
    {
        $compiled = $query->compile();

        return $this->profiler->trace(
            'db.query',
            ['operation' => 'fetchAll', 'sql' => $compiled->sql],
            fn (): array => $this->executor->fetchAll($compiled),
        );
    }

    public function fetchColumn(QueryInterface $query, int $columnIndex = 0): mixed
    {
        if ($columnIndex < 0) {
            throw new \InvalidArgumentException('Column index cannot be negative.');
        }

        $compiled = $query->compile();

        return $this->profiler->trace(
            'db.query',
            ['operation' => 'fetchColumn', 'sql' => $compiled->sql, 'columnIndex' => $columnIndex],
            fn (): mixed => $this->executor->fetchColumn($compiled, $columnIndex),
        );
    }

    public function getLastInsertId(): string
    {
        return $this->executor->getLastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->executor->inTransaction()) {
            return $callback($this);
        }

        return $this->executor->transaction(
            fn (QueryExecutorInterface $_): mixed => $callback($this),
        );
    }

    public function inTransaction(): bool
    {
        return $this->executor->inTransaction();
    }
}
