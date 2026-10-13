# WilyCode Database Query Executor Profiler

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-query-executor-profiler/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-query-executor-profiler/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/wilycode/database-query-executor-profiler)](https://packagist.org/packages/wilycode/database-query-executor-profiler)

**Optional, driver-independent profiling for WilyCode database query executors and binders.** Record query durations, operation names, and canonical SQL while leaving the base executor completely independent of profiling.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — independent, reusable PHP packages with their own repositories, versioning, and Composer installation.
>
> **[Explore the WilyCode ecosystem →](https://github.com/wilycode/wilycode)**

## Table of contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start (PDO)](#quick-start-pdo)
- [How profiling works](#how-profiling-works)
- [Query traces](#query-traces)
  - [Read operations](#read-operations)
  - [Write operations](#write-operations)
  - [Column indexing](#column-indexing)
- [Binding measurements](#binding-measurements)
- [Reading metrics and traces](#reading-metrics-and-traces)
- [MySQLi integration](#mysqli-integration)
- [Transactions](#transactions)
- [Dependency injection](#dependency-injection)
- [Security and privacy](#security-and-privacy)
- [Errors and edge cases](#errors-and-edge-cases)
- [API reference](#api-reference)
- [Related packages](#related-packages)
- [Development](#development)
- [Contributing and license](#contributing-and-license)

## Overview

This package contains two **optional decorators**:

| Component | Implements | Records | Responsibility |
| --- | --- | --- | --- |
| `ProfilingQueryExecutor` | `QueryExecutorInterface` | `db.query` | Trace selected query-executor operations with safe context. |
| `ProfilingBinder` | `BinderInterface` | `db.bind` | Measure the time spent converting a compiled query into driver-ready parameters. |

```text
Application / repository
         |
         v
ProfilingQueryExecutor --- trace db.query ---> ProfilerInterface
         |
         v
    QueryExecutor
         |
         v
   ProfilingBinder ----- measure db.bind ----> ProfilerInterface
         |
         v
  PDO / MySQLi binder
         |
         v
  Database driver
```

The decorators do **not** build SQL, establish database connections, store query results, hydrate model objects, format HTTP headers, or write log files. You can use the base query executor without this package.

## Requirements

- **PHP 8.4+**.
- [`wilycode/database-query:^0.1`](https://github.com/wilycode/database-query) — `QueryInterface`, `CompiledQuery`, and binding contracts.
- [`wilycode/database-query-executor:^0.1`](https://github.com/wilycode/database-query-executor) — `QueryExecutorInterface` and the base executor.
- [`wilycode/profiler:^0.1`](https://github.com/wilycode/profiler) — `ProfilerInterface` and metric/trace contracts.
- A **concrete profiler implementation**, such as `wilycode/profiler-php` (installed separately at runtime).
- A **database driver and matching binder**, such as PDO or MySQLi (also installed separately).

`wilycode/profiler-php` is used as a development dependency for this library's tests. It is not a required runtime dependency of the profiling adapter.

## Installation

Install the adapter and a concrete profiler:

```bash
composer require wilycode/database-query-executor-profiler wilycode/profiler-php
```

For PDO integration, also install:

```bash
composer require wilycode/database-driver-pdo
```

For MySQLi, use `wilycode/database-driver-mysqli` instead. The base executor and profiling adapter do not require both drivers.

## Quick start (PDO)

The example assumes MySQL is running and contains a `users` table with `id`, `email`, and `active` columns. Replace the placeholder connection credentials with values from your application's configuration (for example, environment variables).

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\DriverPdo\PdoDatabase;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\QueryExecutorProfiler\ProfilingBinder;
use WilyCode\Database\QueryExecutorProfiler\ProfilingQueryExecutor;
use WilyCode\ProfilerPhp\Profiler;

$profiler = new Profiler();

$database = PdoDatabase::connect(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app_user',
    'app_password',
);

$base = new QueryExecutor(
    $database,
    new ProfilingBinder(new PdoBinder(), $profiler),
);

$executor = new ProfilingQueryExecutor($base, $profiler);

$users = $executor->fetchAll(
    Query::select('users', 'u')
        ->columns(['id' => 'id', 'email' => 'email'])
        ->where('u.active', true)
        ->limit(10),
);

$queryCount = $profiler->getMetrics()['db.query']->count(); // 1
$bindCount = $profiler->getMetrics()['db.bind']->count();   // 1
```

The outer executor records a `db.query` trace, and the inner binding decorator records a `db.bind` duration. Both use the **same** `Profiler` instance. Without `ProfilingBinder`, query traces still work, but there will be no `db.bind` metric.

## How profiling works

For each query method (`execute`, `fetch`, `fetchAll`, `fetchColumn`):

1. The decorator calls `$query->compile()` **once** and obtains an immutable `CompiledQuery`.
2. It starts a `db.query` trace with the operation and **canonical SQL containing placeholders**.
3. It delegates the same `CompiledQuery` to the wrapped executor. `CompiledQuery::compile()` returns itself, so the original query builder is not compiled again.
4. If the inner executor uses `ProfilingBinder`, the binder separately records `db.bind` while converting parameters.
5. The result is returned unchanged, or an exception is propagated.

### Timing boundaries

`db.query` is the duration of the **decorated executor call**, so it can include binder time, database-driver execution, and result fetching. It is **not** a measurement of driver-only or server-side SQL time.

`db.bind` records the binder's work only. Since it occurs within the executor call, **do not add `db.bind` to `db.query`** to calculate overall query duration.

The initial `$query->compile()` call happens *before* the `db.query` trace starts, so compilation time is not included in either metric.

## Query traces

### Read operations

```php
$users = $executor->fetchAll(
    Query::select('users', 'u')
        ->where('u.active', true)
        ->orderBy('u.id', 'DESC')
        ->limit(10),
);

$user = $executor->fetch(
    Query::select('users', 'u')->where('u.id', 15),
);
```

An example query compiled from the first builder:

```sql
SELECT *
FROM users AS u
WHERE u.active = :param_1
ORDER BY u.id DESC
LIMIT 10
```

The trace context contains the operation and SQL, **not** the value of `:param_1`:

```php
[
    'operation' => 'fetchAll',
    'sql' => 'SELECT * FROM users AS u WHERE u.active = :param_1 ORDER BY u.id DESC LIMIT 10',
]
```

Values are supplied to the database driver separately. Trace SQL is not modified or inlined with bound values.

### Write operations

```php
$affectedRows = $executor->execute(
    Query::update('users')
        ->set(['active' => false])
        ->where('id', 15),
);
```

The same `db.query` trace name is used, with `['operation' => 'execute', 'sql' => ...]`. The returned affected-row count is passed through without modification.

### Column indexing

```php
$email = $executor->fetchColumn(
    Query::select('users', 'u')->column('u.email')->where('u.id', 15),
    columnIndex: 0,
);
```

For `fetchColumn()`, the trace context also contains `columnIndex`. Negative indices are rejected with `InvalidArgumentException` **before** compilation or tracing.

## Binding measurements

`ProfilingBinder` is a small implementation of `BinderInterface` that wraps a driver-specific binder:

```php
$profilingBinder = new ProfilingBinder(new PdoBinder(), $profiler);

$compiled = Query::select('users')->where('id', 15)->compile();
$bound = $profilingBinder->bind($compiled);

// $bound->sql, $bound->params, $bound->types
```

Its sole metric is `db.bind` (via `ProfilerInterface::measure()`). It does **not** add a detailed trace and does not transform or inspect the bound values itself. The wrapped binder determines how placeholders and types are converted.

## Reading metrics and traces

Using `wilycode/profiler-php`, retrieve recorded metrics:

```php
foreach ($profiler->getMetrics() as $name => $metric) {
    printf(
        "%s: %d calls, %.3f ms total\n",
        $name,
        $metric->count(),
        $metric->durationMilliseconds(),
    );
}

foreach ($profiler->getTraces() as $trace) {
    echo $trace->name() . ': ' . $trace->durationMilliseconds() . " ms\n";
    print_r($trace->context());
}
```

`ProfilerInterface` is implementation-independent; the concrete implementation decides how to store or export measurements. `wilycode/profiler-php` keeps the metrics and traces in memory. This adapter does not provide a dashboard, persistence, or `Server-Timing` formatting.

## MySQLi integration

The same decorators work with the native MySQLi adapter:

```php
use WilyCode\Database\DriverMysqli\MysqliBinder;
use WilyCode\Database\DriverMysqli\MysqliDatabase;
use WilyCode\Database\QueryExecutor\QueryExecutor;
use WilyCode\Database\QueryExecutorProfiler\ProfilingBinder;
use WilyCode\Database\QueryExecutorProfiler\ProfilingQueryExecutor;
use WilyCode\ProfilerPhp\Profiler;

$profiler = new Profiler();
$database = MysqliDatabase::connect(
    host: '127.0.0.1',
    username: 'app_user',
    password: 'app_password',
    database: 'app',
);
$base = new QueryExecutor($database, new ProfilingBinder(new MysqliBinder(), $profiler));
$executor = new ProfilingQueryExecutor($base, $profiler);

$rows = $executor->fetchAll(Query::select('users', 'u')->where('u.active', true));
```

`MysqliBinder` maps canonical named placeholders to the positional `?` parameters expected by MySQLi. The profiling adapter does not depend on either PDO or MySQLi.

## Transactions

The profiling executor retains the underlying executor's transaction semantics:

```php
$executor->transaction(function (QueryExecutorInterface $tx): void {
    $tx->execute(Query::update('users')->set(['active' => true])->where('id', 15));
    $tx->fetchAll(Query::select('users'));
});
```

The callback is always passed the **profiling decorator** (`$tx`), not the underlying executor. Queries made through `$tx` therefore remain profiled.

If a transaction is already active, the decorator joins it without opening a nested transaction or savepoint. It does not commit or roll back the outer transaction. An outer transaction's owner remains responsible for its lifecycle. The transaction call itself is not separately recorded as `db.query`.

## Dependency injection

The profiler wraps `QueryExecutorInterface` and also implements that interface. Application repositories should depend on the contract:

```php
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

final class UserRepository
{
    public function __construct(
        private QueryExecutorInterface $executor,
    ) {}
}
```

This lets you enable or disable the profiling adapter without changing repositories. Be careful about decorator composition: **outer decorators only observe operations forwarded to them**. For example, a caching decorator may return cache hits without invoking an inner profiling decorator, so those hits produce no `db.query` trace.

## Security and privacy

The adapter deliberately does not copy `CompiledQuery::params` or parameter-type arrays into profiler trace contexts. It stores only:

| Operation | Trace context |
| --- | --- |
| `execute` | `operation`, `sql` |
| `fetch` | `operation`, `sql` |
| `fetchAll` | `operation`, `sql` |
| `fetchColumn` | `operation`, `sql`, `columnIndex` |

**Important limitation:** the SQL text comes from `CompiledQuery::sql`. If your application embeds literal secrets, personal data, or other sensitive values directly into raw SQL, they **can appear in the recorded SQL text**. Use bound parameters instead, and do not expose detailed traces to end users or public logs. The adapter does not sanitize arbitrary SQL literals.

## Errors and edge cases

| Scenario | Behavior |
| --- | --- |
| Query compilation throws | Exception propagates **before** tracing begins; no `db.query` trace. |
| Binder throws during execution | Exception propagates; `db.query` and (when using `ProfilerPhp\Profiler`) `db.bind` duration are still recorded. |
| Driver throws during execution | Exception propagates; `db.query` trace is still recorded by `ProfilerPhp\Profiler`. |
| Negative `fetchColumn()` index | Throws `InvalidArgumentException` before compilation and tracing. |
| `fetch()` returns `[]` / `fetchAll()` returns `[]` | Empty results are returned unchanged. |
| `fetchColumn()` returns `null` | `null` is returned unchanged. |
| `getLastInsertId()` / `inTransaction()` | Delegated without query traces. |
| `transaction()` | Passes the profiling decorator to callback; does not create its own `db.query` trace. |

Recording behavior on errors depends on the `ProfilerInterface` implementation; the `wilycode/profiler-php` implementation records measurements in a `finally` block.

## API reference

### ProfilingQueryExecutor

`final readonly class ProfilingQueryExecutor implements QueryExecutorInterface`

| Method | Return type | Trace | Description |
| --- | --- | --- | --- |
| `__construct(QueryExecutorInterface $executor, ProfilerInterface $profiler)` | — | — | Wrap the inner executor. |
| `execute(QueryInterface $query)` | `int` | `db.query` | Execute a write query. |
| `fetch(QueryInterface $query)` | `array` | `db.query` | Fetch one row. |
| `fetchAll(QueryInterface $query)` | `array` | `db.query` | Fetch all rows. |
| `fetchColumn(QueryInterface $query, int $columnIndex = 0)` | `mixed` | `db.query` | Fetch one result column. |
| `getLastInsertId()` | `string` | — | Delegate without tracing. |
| `transaction(callable $callback)` | `mixed` | — | Run/join a transaction; callback receives the decorator. |
| `inTransaction()` | `bool` | — | Delegate without tracing. |

### ProfilingBinder

`final readonly class ProfilingBinder implements BinderInterface`

| Method | Return type | Metric | Description |
| --- | --- | --- | --- |
| `__construct(BinderInterface $binder, ProfilerInterface $profiler)` | — | — | Wrap the driver-specific binder. |
| `bind(CompiledQuery $query)` | `BoundQuery` | `db.bind` | Measure binding and return its result unchanged. |

## Related packages

| Package | Purpose |
| --- | --- |
| [`wilycode/database-query`](https://github.com/wilycode/database-query) | Build and compile SQL queries. |
| [`wilycode/database-query-executor`](https://github.com/wilycode/database-query-executor) | Execute queries through an injected driver and binder. |
| [`wilycode/database-driver`](https://github.com/wilycode/database-driver) | Database driver contracts. |
| [`wilycode/database-driver-pdo`](https://github.com/wilycode/database-driver-pdo) | PDO adapter and binder. |
| [`wilycode/database-driver-mysqli`](https://github.com/wilycode/database-driver-mysqli) | MySQLi adapter and binder. |
| [`wilycode/profiler`](https://github.com/wilycode/profiler) | Profiling contracts and metric/trace types. |
| [`wilycode/profiler-php`](https://github.com/wilycode/profiler-php) | Native PHP profiler implementation. |
| [`wilycode/database-query-executor-cache`](https://github.com/wilycode/database-query-executor-cache) | Optional PSR-16 caching decorator. |

The base executor, query builder, and profiling adapter remain independent Composer packages.

## Development

```bash
composer install
composer validate --strict
composer test
```

GitHub Actions runs PHPUnit on PHP 8.4 for pushes and pull requests. Tests are versioned in Git but excluded from Composer distribution archives.

## Contributing and license

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and the [issue tracker](https://github.com/wilycode/database-query-executor-profiler/issues).

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
