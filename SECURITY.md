# Security policy

## Supported versions

Before the first public tag, there is no supported release; fixes land on the
development branch. After publication, fixes are provided for the latest tagged
`0.2.x` release while the project remains pre-1.0. Merchants should run the
latest security patch of a supported Magento release and the latest module tag.

## Reporting a vulnerability

Please do not disclose a suspected vulnerability in a public issue, pull
request, discussion, or social-media post. Use the repository's **Security >
Report a vulnerability** form so the maintainers can investigate privately. If
private vulnerability reporting is unavailable, contact the repository owner
privately before sharing technical details.

Include:

- the affected module and Magento versions;
- prerequisites and the lowest-privileged actor that can reproduce the issue;
- reproducible steps or a minimal proof of concept;
- expected and observed behavior;
- potential impact on authorization, customer data, money, inventory, or
  idempotency; and
- any suggested mitigation.

Do not include real customer data, live credentials, payment tokens, or
production database extracts.

## Deployment safety

This module coordinates native Magento orders, credit memos, and invoices.
Before enabling it in production, validate installation, DI compilation,
role-specific ACLs, cancellation/shipment/delivery rollback and replay,
rounding, inventory mode, and third-party sales observers in a staging copy of
the target deployment. Direct writes that bypass Magento's supported
`OrderService`, `ShipOrder`, or `RefundAdapter` boundaries are not protected by
the lifecycle synchronizers.

Database rollback cannot undo network calls made synchronously by third-party
observers. External email, ERP, analytics, payment, and webhook side effects
must be deferred until the module's post-commit events or handled through a
transactional outbox.
