# Changelog

All notable changes to the Paymos for PrestaShop module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.1] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-06-18

### Added
- Initial release for PrestaShop 1.7.6+, including 8.x and 9.x.
- Hosted-checkout payment module: the customer pays in USDT or USDC across 13 networks and is redirected to the secure Paymos checkout.
- `PaymentModule` shell with `paymentOptions` and `paymentReturn` hooks and `validation` / `callback` / `pending` / `reconcile` front controllers.
- Three custom order states created on install — Awaiting Paymos payment, Paymos payment confirming, Paymos payment — manual review; paid/failed/cancelled map to PrestaShop core states.
- HMAC-SHA256 webhook signature verification with secret-rotation grace period.
- Reverse verification on every terminal callback before transitioning the order state.
- Amount-change protection: a paid webhook whose amount no longer matches the order is held for manual review, never marked paid.
- Roll-back guard: a late `cancelled` / `confirming` callback never downgrades an already-paid order.
- Duplicate-order guard: a refreshed or double-submitted checkout reuses the existing order instead of minting a second order and invoice.
- On-chain transaction hash and explorer link recorded as an order note on payment.
- Race-proof webhook deduplication backed by a `paymos_webhook_event` table keyed on `event_id`.
- Reconcile safety net for missed webhooks via the `reconcile` front controller (PrestaShop 9 blocks direct module `.php` access) — funnels through the same order mapper, throttled to 50 invoices per 24h window. A CLI entry (`paymos/cron/reconcile.php`) remains for cron.
- Sandbox / Live mode switch in the PrestaShop admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator; secrets are read-only and never typed in the admin.
