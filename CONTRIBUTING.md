# Contributing

Thank you for helping improve Bonlineco Sales Exchange. Financial and inventory
workflows are high-risk, so changes must stay fail-closed and include focused
tests for success, replay, stale-state, authorization, and rollback behavior.

## Development setup

Use a clean Magento Open Source installation supported by the package's
`composer.json`. Install this repository as a Composer path repository or place
it at `app/code/Bonlineco/SalesExchange`. Do not develop against a production
database.

When using a path repository from a Magento root:

```bash
composer config repositories.bonlineco-sales-exchange path \
  /absolute/path/to/module
composer require bonlineco/module-sales-exchange:@dev
bin/magento module:enable Bonlineco_SalesExchange
bin/magento setup:upgrade
```

## Required checks

Run these from the Magento root before opening a pull request:

```bash
composer validate --strict --no-check-version \
  app/code/Bonlineco/SalesExchange/composer.json

find app/code/Bonlineco/SalesExchange -type f -name '*.php' \
  -exec php -l {} \;

vendor/bin/phpcs \
  --standard=Magento2 \
  --extensions=php,phtml \
  --error-severity=10 \
  --warning-severity=0 \
  --ignore-annotations \
  app/code/Bonlineco/SalesExchange

vendor/bin/phpunit \
  --no-extensions \
  -c dev/tests/unit/phpunit.xml.dist \
  app/code/Bonlineco/SalesExchange/Test/Unit

vendor/bin/phpunit \
  -c dev/tests/integration/phpunit.xml.dist \
  app/code/Bonlineco/SalesExchange/Test/Integration

vendor/bin/mftf generate:tests AdminSalesExchangeGridSmokeTest \
  AdminSalesExchangeAclTest AdminSalesExchangePostOnlyTest --force

php app/code/Bonlineco/SalesExchange/dev/ci/check-data-provider-metadata.php
php app/code/Bonlineco/SalesExchange/dev/ci/check-package-metadata.php
```

Run integration tests only against a dedicated disposable integration database;
their fixtures and Magento's test framework mutate and reset database state.

Also validate declarative schema installation, dependency injection compilation,
and the affected admin workflow in a disposable Magento environment. Changes to
credit memos, replacement orders, invoices, settlement rows, locking, or
idempotency require integration tests that exercise the real Magento services.

Tests with data providers must carry both the PHPUnit 9 doc-comment annotation
and the PHPUnit 10/12 attribute:

```php
/**
 * @dataProvider exampleProvider
 */
#[DataProvider('exampleProvider')]
public function testExample(string $value): void
```

## Pull requests

- Keep a pull request focused on one behavior or release concern.
- Describe the invariant being protected and the failure/replay cases tested.
- Add an entry under `Unreleased` in `CHANGELOG.md` for user-visible changes.
- Update service contracts and documentation together.
- Never commit credentials, customer data, order exports, database dumps, or
  proprietary store integrations.
- Do not weaken ACL, form-key, optimistic-lock, order-mutex, or idempotency
  checks to make a test pass.

By contributing, you agree that your contribution is licensed under the MIT
License.

## Release preparation

The Composer `version` field is retained because Magento Marketplace package
verification requires it. Keep it identical to the release tag and use
`composer validate --strict --no-check-version`; this suppresses only Composer's
Packagist-oriented warning about embedded versions.

Before tagging:

1. Move user-visible entries from `Unreleased` to the intended version and date.
2. Update the Composer version and verify it matches the planned tag.
3. Run both GitHub Actions workflows and require every matrix job to pass.
4. Install and upgrade the archive in disposable Magento environments for every
   claimed release line.
5. Inspect the Composer archive and keep credentials, local files, tests, and CI
   machinery out of the distribution ZIP.
6. Prepare the separate PDF user/installation guide required for a Magento
   Marketplace submission, if Marketplace distribution is planned.
