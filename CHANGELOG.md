# Changelog

All notable changes to this project are documented here. The project follows
[Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

The first public tag is planned as `0.1.0`; it has not been published.

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
