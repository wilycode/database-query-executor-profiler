# Contributing to WilyCode Database Query Executor Profiler

Thank you for considering a contribution.

## Development

This package targets PHP 8.4 or newer and depends on published WilyCode interfaces. Install the development dependencies and run the test suite:

```bash
composer install
composer validate --strict
composer test
```

Keep changes focused on this optional adapter. Do not add profiling requirements to `wilycode/database-query-executor` or `wilycode/database-query`.

## Pull requests

- Preserve the public contracts and add tests for changed behavior.
- Never include bound parameter values or credentials in profiler trace context.
- Update the English `README.md` and `CHANGELOG.md` whenever behavior or public APIs change.
- Run PHPUnit and ensure GitHub Actions is green.

See the [WilyCode PHP Ecosystem](https://github.com/wilycode/wilycode) for other related libraries.
