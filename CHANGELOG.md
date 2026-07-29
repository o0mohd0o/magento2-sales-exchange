# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

No unreleased changes.

## [0.2.1] - 2026-07-29

### Fixed

- Compare registered regions by stable region and country identifiers instead
  of locale-dependent display labels, preventing valid order snapshots from
  drifting when an administrator uses a different interface locale.
- Accept Magento's native `same_as_billing` normalization while continuing to
  require separate, fully validated billing and shipping address objects.
- Validate converted order addresses using Magento's order-level customer email
  without requiring its unmapped address-level customer ID, while preserving
  the existing durable fingerprint fields.

## [0.2.0] - 2026-07-29

### Added

- Freeze the store's catalog-price tax mode on each new exchange and bind that
  mode into the immutable replacement-order intent.
- Restore server-approved replacement prices immediately before Magento
  subtotal collection, including repeated collections triggered by
  third-party observers.

### Fixed

- Attach the payment object to a new replacement quote before importing the
  payment method, preventing a null quote dereference.
- Preserve an unexpected PHP `Error` as the cause of the public save exception
  instead of masking it with an incompatible exception constructor argument.
- Validate tax-inclusive replacement prices against Magento's gross item and
  order totals while preserving net validation for tax-exclusive stores.
- Prove item-level net, base, and tax decomposition against quote totals before
  native order placement.
- Preserve pre-0.2 intent and native-document fingerprints for legacy rows
  whose tax-mode snapshot is `NULL`.

### Security

- Bind persisted quote buy-request prices to the active server-frozen
  replacement rows before restoring them.
- Disable quote and product super mode, clear tax calculation caches, and
  require Magento to apply tax to custom prices during trusted collection.
- Revalidate the exact Magento-submitted quote against its converted order
  before repository save, then validate both the repository result and a
  fresh persisted order before the outer transaction can commit.
- Reject replacement placement when quote and order resources do not share
  one database adapter, preserving rollback integrity.

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

[Unreleased]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/o0mohd0o/magento2-sales-exchange/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/o0mohd0o/magento2-sales-exchange/releases/tag/v0.1.0
