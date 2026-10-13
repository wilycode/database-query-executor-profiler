<?php

declare(strict_types=1);

namespace WilyCode\Database\QueryExecutorProfiler\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Statement\QueryInterface;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;
use WilyCode\Database\QueryExecutorProfiler\ProfilingBinder;
use WilyCode\Database\QueryExecutorProfiler\ProfilingQueryExecutor;
use WilyCode\ProfilerPhp\Profiler;

final class ProfilingQueryExecutorTest extends TestCase
{
    public function testFetchAllTracesCanonicalSqlAndDelegatesCompiledQuery(): void
    {
        $compiled = new CompiledQuery(
            'SELECT id FROM users WHERE active = :active',
            [':active' => true],
        );
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::once())->method('compile')->willReturn($compiled);

        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('fetchAll')->with(self::identicalTo($compiled))
            ->willReturn([['id' => 1]]);

        $profiler = new Profiler();
        $executor = new ProfilingQueryExecutor($inner, $profiler);

        self::assertSame([['id' => 1]], $executor->fetchAll($query));
        self::assertSame(1, $profiler->getMetrics()['db.query']->count());
        self::assertCount(1, $profiler->getTraces());
        self::assertSame([
            'operation' => 'fetchAll',
            'sql' => 'SELECT id FROM users WHERE active = :active',
        ], $profiler->getTraces()[0]->context());
        self::assertStringNotContainsString('true', $profiler->getTraces()[0]->context()['sql']);
    }

    public function testExecuteReturnsAffectedRowCountAndTracesOperation(): void
    {
        $compiled = new CompiledQuery('DELETE FROM users WHERE id = :id', [':id' => 17]);
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::once())->method('compile')->willReturn($compiled);

        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('execute')->with(self::identicalTo($compiled))->willReturn(2);

        $profiler = new Profiler();
        self::assertSame(2, (new ProfilingQueryExecutor($inner, $profiler))->execute($query));
        self::assertSame('execute', $profiler->getTraces()[0]->context()['operation']);
        self::assertSame('DELETE FROM users WHERE id = :id', $profiler->getTraces()[0]->context()['sql']);
    }

    public function testFetchReturnsEmptyRowUnchanged(): void
    {
        $compiled = new CompiledQuery('SELECT id FROM users');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('fetch')->with(self::identicalTo($compiled))->willReturn([]);

        $profiler = new Profiler();
        self::assertSame([], (new ProfilingQueryExecutor($inner, $profiler))->fetch($compiled));
        self::assertSame('fetch', $profiler->getTraces()[0]->context()['operation']);
    }

    public function testFetchAllReturnsEmptyListUnchanged(): void
    {
        $compiled = new CompiledQuery('SELECT id FROM users');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('fetchAll')->with(self::identicalTo($compiled))->willReturn([]);
        self::assertSame([], (new ProfilingQueryExecutor($inner, new Profiler()))->fetchAll($compiled));
    }

    public function testFetchColumnForwardsIndexAndRecordsContext(): void
    {
        $compiled = new CompiledQuery('SELECT id, email FROM users');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('fetchColumn')
            ->with(self::identicalTo($compiled), 1)->willReturn('person@example.test');

        $profiler = new Profiler();
        self::assertSame('person@example.test',
            (new ProfilingQueryExecutor($inner, $profiler))->fetchColumn($compiled, 1));
        self::assertSame([
            'operation' => 'fetchColumn',
            'sql' => 'SELECT id, email FROM users',
            'columnIndex' => 1,
        ], $profiler->getTraces()[0]->context());
    }

    public function testFetchColumnPreservesNull(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('fetchColumn')->willReturn(null);
        $profiler = new Profiler();
        self::assertNull((new ProfilingQueryExecutor($inner, $profiler))
            ->fetchColumn(new CompiledQuery('SELECT id FROM users')));
        self::assertSame(1, $profiler->getMetrics()['db.query']->count());
    }

    public function testNegativeColumnIndexIsRejectedBeforeCompilationOrTracing(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->expects(self::never())->method('compile');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::never())->method('fetchColumn');
        $profiler = new Profiler();
        try {
            (new ProfilingQueryExecutor($inner, $profiler))->fetchColumn($query, -1);
            self::fail('Negative index should throw.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Column index cannot be negative.', $exception->getMessage());
        }
        self::assertSame([], $profiler->getTraces());
    }

    public function testCompilationErrorIsNotTraced(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('compile')->willThrowException(new RuntimeException('compile failed'));
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::never())->method('execute');
        $profiler = new Profiler();
        try {
            (new ProfilingQueryExecutor($inner, $profiler))->execute($query);
            self::fail('Compilation error should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('compile failed', $exception->getMessage());
        }
        self::assertSame([], $profiler->getTraces());
    }

    public function testExecutionExceptionIsPropagatedAndTraceStillRecorded(): void
    {
        $compiled = new CompiledQuery('SELECT * FROM missing');
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('fetch')->willThrowException(new RuntimeException('database error'));
        $profiler = new Profiler();
        try {
            (new ProfilingQueryExecutor($inner, $profiler))->fetch($compiled);
            self::fail('Expected exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('database error', $exception->getMessage());
        }
        self::assertSame(1, $profiler->getMetrics()['db.query']->count());
        self::assertSame('SELECT * FROM missing', $profiler->getTraces()[0]->context()['sql']);
    }

    public function testGetLastInsertIdAndTransactionStatusAreDelegatedWithoutTracing(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('getLastInsertId')->willReturn('123');
        $inner->expects(self::once())->method('inTransaction')->willReturn(true);

        $profiler = new Profiler();
        $executor = new ProfilingQueryExecutor($inner, $profiler);
        self::assertSame('123', $executor->getLastInsertId());
        self::assertTrue($executor->inTransaction());
        self::assertSame([], $profiler->getTraces());
        self::assertSame([], $profiler->getMetrics());
    }

    public function testTransactionPassesDecoratorToCallback(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('inTransaction')->willReturn(false);
        $inner->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($inner));

        $executor = new ProfilingQueryExecutor($inner, new Profiler());
        $received = null;
        $result = $executor->transaction(function (QueryExecutorInterface $transaction) use (&$received): string {
            $received = $transaction;
            return 'done';
        });
        self::assertSame('done', $result);
        self::assertSame($executor, $received);
    }

    public function testTransactionJoinsExistingTransactionWithoutNesting(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->expects(self::once())->method('inTransaction')->willReturn(true);
        $inner->expects(self::never())->method('transaction');
        $executor = new ProfilingQueryExecutor($inner, new Profiler());

        self::assertSame('joined', $executor->transaction(
            static fn (QueryExecutorInterface $transaction): string => $transaction === $executor ? 'joined' : 'other',
        ));
    }

    public function testQueriesExecutedInsideTransactionRemainProfiled(): void
    {
        $inner = $this->createMock(QueryExecutorInterface::class);
        $inner->method('inTransaction')->willReturn(false);
        $inner->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback($inner));
        $inner->expects(self::once())->method('fetchAll')->willReturn([['id' => 3]]);

        $profiler = new Profiler();
        $executor = new ProfilingQueryExecutor($inner, $profiler);
        $result = $executor->transaction(static function (QueryExecutorInterface $transaction): array {
            return $transaction->fetchAll(new CompiledQuery('SELECT id FROM users'));
        });
        self::assertSame([['id' => 3]], $result);
        self::assertSame(1, $profiler->getMetrics()['db.query']->count());
    }

    public function testProfilingBinderMeasuresBindingWithoutChangingResult(): void
    {
        $compiled = new CompiledQuery('SELECT id FROM users');
        $bound = new BoundQuery('SELECT id FROM users');
        $inner = $this->createMock(BinderInterface::class);
        $inner->expects(self::once())->method('bind')->with($compiled)->willReturn($bound);

        $profiler = new Profiler();
        $binder = new ProfilingBinder($inner, $profiler);
        self::assertSame($bound, $binder->bind($compiled));
        self::assertSame(1, $profiler->getMetrics()['db.bind']->count());
        self::assertSame([], $profiler->getTraces());
    }

    public function testBinderExceptionIsPropagatedAndStillMeasured(): void
    {
        $inner = $this->createMock(BinderInterface::class);
        $inner->method('bind')->willThrowException(new RuntimeException('bind failed'));
        $profiler = new Profiler();
        try {
            (new ProfilingBinder($inner, $profiler))->bind(new CompiledQuery('SELECT 1'));
            self::fail('Expected bind failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('bind failed', $exception->getMessage());
        }
        self::assertSame(1, $profiler->getMetrics()['db.bind']->count());
    }
}
