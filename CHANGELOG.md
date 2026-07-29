# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

No unreleased changes.

## [0.1.3] - 2026-07-29

### Fixed

- Render exchange detail workflow statuses as sequential label/status rows
  instead of using translated `Phrase` objects as PHP array keys, preventing
  `Illegal offset type` errors on every valid exchange detail page.

## [0.1.2] - 2026-07-29

### Fixed

- Accept Magento-native decimal values with zero padding beyond the module's
  four-decimal calculation scale, such as catalog price `12999.000000`,
  without allowing non-zero excess precision to be truncated.

## [0.1.1] - 2026-07-29

### Added

- A permission-aware `Create Exchange` action on the Exchange Orders grid that
  opens the existing original-order selection workflow when exchanges are
  enabled.

### Fixed

- Preserve Magento's native admin grid collection registry when registering the
  exchange grid, preventing `Not registered handle
  sales_order_grid_data_source` on the regular Sales Orders grid.
- Use the backend context authorization service for the eligible-order toolbar
  action so rendering a native order view cannot access an undefined block
  property.

## [0.1.0] - 2026-07-28

### Added

- Admin exchange-case grid, detail page, original-order integration, workflow
  controls, store-scoped configuration, ACL resources, and Arabic labels.
- Declarative exchange, return-line, replacement-line, document-link,
  settlement-ledger, and append-only history storage.
- Canonical eligibility, allocation, financial snapshot, optimistic-lock, and
  state-transition services.
- Idempotent offline native credit-memo creation with reservation protection
  for Magento refund entry points.
- Recoverable native replacement quote/order creation with immutable exchange,
  intent, and line markers.
- Atomic native replacement cancellation, fail-closed replacement refunds,
  one full native shipment with immutable replay, and an adapter-owned
  delivery-proof service.
- Settlement reconciliation with full native replacement invoice validation,
  canonical return-credit validation, append-only ledger entries, and
  post-commit events.
- PHPUnit 9/10/12-compatible unit tests, real nested-transaction integration
  coverage, admin grid/ACL/POST-only MFTF definitions, Magento coding-standard
  gates, Composer package metadata, release hygiene, and GitHub Actions
  workflows.

### Security

- Server-owned financial intent, form-key protected POST controllers,
  layered Magento/custom ACL checks, deterministic order locking, immutable
  document fingerprints and delivery proofs, and fail-closed handling of
  unsupported totals, partial shipments, and replacement refunds.

[Unreleased]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/o0mohd0o/magento2-sales-exchange/releases/tag/v0.1.0
