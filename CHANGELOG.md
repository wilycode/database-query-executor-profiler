# Changelog

All notable changes to this package will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-13

### Added

- Initial public release of the optional WilyCode query-executor profiling adapters.
- `ProfilingQueryExecutor` for `db.query` traces around `execute()`, `fetch()`, `fetchAll()` and `fetchColumn()`.
- `ProfilingBinder` for `db.bind` duration metrics.
- Preserved canonical SQL with named placeholders in trace contexts, without copying bound parameter arrays.
- Support for transactions that expose the profiling decorator to callbacks.
- Automated PHPUnit tests and GitHub Actions on PHP 8.4.
- Documentation for integrations, transaction behavior, security boundaries, and API reference.
