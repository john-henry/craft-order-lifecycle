# Testing

## Running Tests

Pest lives in the parent Craft project's `vendor/`: the plugin does not have its own `vendor/` directory. All test commands run from the project root.

### Unit and Feature tests

No Craft application is bootstrapped.

```shell
ddev exec vendor/bin/pest plugins/craft-order-lifecycle/tests \
  --test-directory=plugins/craft-order-lifecycle/tests --colors
```

### Integration tests (require Craft + Commerce)

These live in `tests/Integration/` and use craft-pest's `TestCase` + `RefreshesDatabase`, which wraps each test in a DB transaction that rolls back on teardown, preventing test orders and logs from persisting to the database. They require `markhuot/craft-pest-core` in the parent project.

Both traits are applied globally in `Pest.php` via `uses(...)->in('Integration')`, so individual test files do not need their own `uses()` calls.

Run all tests (Unit, Feature, and Integration):

```shell
ddev exec vendor/bin/pest plugins/craft-order-lifecycle/tests \
  --test-directory=plugins/craft-order-lifecycle/tests --colors
```

Run only integration tests:

```shell
ddev exec vendor/bin/pest plugins/craft-order-lifecycle/tests/Integration \
  --test-directory=plugins/craft-order-lifecycle/tests/Integration --colors
```

To filter to a specific test:

```shell
ddev exec vendor/bin/pest plugins/craft-order-lifecycle/tests \
  --test-directory=plugins/craft-order-lifecycle/tests --filter=OrderLifecycleLogger
```

### Regenerating TESTS.md

`TESTS.md` is auto-generated from JUnit XML output using [craft-generate-test-spec](https://github.com/putyourlightson/craft-generate-test-spec). Run both commands to update it after adding or changing tests:

```shell
ddev exec vendor/bin/pest plugins/craft-order-lifecycle/tests \
  --test-directory=plugins/craft-order-lifecycle/tests \
  --log-junit=plugins/craft-order-lifecycle/tests/test-results.xml

ddev exec php craft generate-test-spec/markdown plugins/craft-order-lifecycle/tests
```

## Test Structure

```
tests/
├── Pest.php               # Bootstrap: applies TestCase to Integration/
├── Unit/
│   └── FormatterTest.php  # Pure unit tests for Formatter helper
├── Feature/
│   └── EventTypeTest.php  # EventType enum category and boolean helpers
└── Integration/
    ├── OrderLifecycleLoggerTest.php  # log(), getLogsForOrder(), logCheckoutStarted(), getLastLogForOrderAndType(), updateLog()
    ├── EventDrivenLoggingTest.php    # EVENT_AFTER_SAVE: coupon, customer, shipping, fallback
    ├── AsyncLoggingTest.php          # asyncLogging setting: queue dispatch, job execution
    ├── FormatterTest.php             # priceChange() - requires Craft::$app formatter
    ├── SettingsModelTest.php         # SettingsModel defaults and validation (autoPruneLogs range, booleans)
    └── StatsServiceTest.php          # StatsService::getStats() shape, COUNT DISTINCT correctness, period filtering, order deletion cleanup
```

**Unit tests** cover the pure static methods of `Formatter`; no Craft instance needed.

**Feature tests** cover `EventType` enum behaviour; also no Craft instance needed.

**Integration tests** require the full Craft + Commerce app context. craft-pest wraps each test in a DB transaction and rolls it back on teardown, so no manual cleanup is needed.

Three helpers are used across integration tests:
- `makeOrder()`: saves an order and wipes its auto-logs, giving a clean log slate
- `orderWithSnapshot()`: saves an order and keeps the CART_CREATED auto-log, which stores the initial snapshot needed for EVENT_AFTER_SAVE diff logic
- `reSave()`: re-saves an existing order with `RECALCULATION_MODE_NONE` to trigger EVENT_AFTER_SAVE listeners

When `asyncLogging = true`, `makeOrder()`'s DELETE finds 0 rows (the CART_CREATED log is queued, not yet written). `AsyncLoggingTest.php` sets this flag in `beforeEach` and drains the queue in `afterEach` to avoid bleed between tests.

## Static Analysis

```shell
ddev composer phpstan --working-dir=plugins/craft-order-lifecycle
```

## Coding Standards

```shell
ddev composer check-cs --working-dir=plugins/craft-order-lifecycle
ddev composer fix-cs --working-dir=plugins/craft-order-lifecycle
```
