<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorProfiler;

use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Profiler\ProfilerInterface;

final readonly class ProfilingBinder implements BinderInterface
{
    public function __construct(
        private BinderInterface $binder,
        private ProfilerInterface $profiler,
    ) {}

    public function bind(CompiledQuery $query): BoundQuery
    {
        return $this->profiler->measure(
            'db.bind',
            fn (): BoundQuery => $this->binder->bind($query),
        );
    }
}
