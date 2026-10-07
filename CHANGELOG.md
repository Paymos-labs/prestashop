# Changelog

All notable changes to the Paymos for PrestaShop module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.3.18] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- The module claimed PrestaShop 1.7.6 and PHP 7.4, a pair that cannot exist:
  PrestaShop 1.7.6 and 1.7.7 run only up to PHP 7.3 (BUG-187). The minimum is
  now PrestaShop 1.7.8, the first release that runs on PHP 7.4, in
  `ps_versions_compliancy`, the README, `composer.json` and the documentation;
  the README no longer claims 9.x, which the declared maximum of 8.99.99 does
  not allow. `install()` refuses PHP below 7.4, because PrestaShop 1.7.8 still
  runs on 7.1-7.3 and core checks only the PrestaShop version. The README now
  gives both causes of manual review: a paid amount that no longer matches the
  order, or an invoice replacement that was refused because the old invoice
  was paid, still payable or could not be read (BUG-188).
- A blocked invoice replacement told the buyer the payment was being confirmed
  on-chain and could offer "Continue payment" on the old invoice, which may
  already be paid or be for an amount the order no longer has (BUG-190).
  `validation.php` now adds `paymos_review=1` to the status-page link in that
  case only, and the page shows "This order needs review" with the SDK buyer
  message ("The store needs to review this order before payment can continue.
  Please contact the store.") and no payment link. The order state alone does
  not switch the text, because a paid order whose amount fails the guard is in
  the same manual-review state, but an order in that state is no longer offered
  the payment link either. The decision lives in the new `PendingPage` class.
  Both strings are in all six catalogues.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order moves to the manual-review state with a note,
  instead of the payment-error state. A 404 on the read of the live invoice no
  longer cuts a new one either.
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.17] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- The module claimed PrestaShop 1.7.6 and PHP 7.4, a pair that cannot exist:
  PrestaShop 1.7.6 and 1.7.7 run only up to PHP 7.3 (BUG-187). The minimum is
  now PrestaShop 1.7.8, the first release that runs on PHP 7.4, in
  `ps_versions_compliancy`, the README, `composer.json` and the documentation;
  the README no longer claims 9.x, which the declared maximum of 8.99.99 does
  not allow. `install()` refuses PHP below 7.4, because PrestaShop 1.7.8 still
  runs on 7.1-7.3 and core checks only the PrestaShop version. The README now
  gives both causes of manual review: a paid amount that no longer matches the
  order, or an invoice replacement that was refused because the old invoice
  was paid, still payable or could not be read (BUG-188).
- A blocked invoice replacement told the buyer the payment was being confirmed
  on-chain and could offer "Continue payment" on the old invoice, which may
  already be paid or be for an amount the order no longer has (BUG-190).
  `validation.php` now adds `paymos_review=1` to the status-page link in that
  case only, and the page shows "This order needs review" with the SDK buyer
  message ("The store needs to review this order before payment can continue.
  Please contact the store.") and no payment link. The order state alone does
  not switch the text, because a paid order whose amount fails the guard is in
  the same manual-review state, but an order in that state is no longer offered
  the payment link either. The decision lives in the new `PendingPage` class.
  Both strings are in all six catalogues.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order moves to the manual-review state with a note,
  instead of the payment-error state. A 404 on the read of the live invoice no
  longer cuts a new one either.
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.16] - 2026-10-03

- fix: устранить дефекты реестра после повторного аудита

### Fixed
- The module claimed PrestaShop 1.7.6 and PHP 7.4, a pair that cannot exist:
  PrestaShop 1.7.6 and 1.7.7 run only up to PHP 7.3 (BUG-187). The minimum is
  now PrestaShop 1.7.8, the first release that runs on PHP 7.4, in
  `ps_versions_compliancy`, the README, `composer.json` and the documentation;
  the README no longer claims 9.x, which the declared maximum of 8.99.99 does
  not allow. `install()` refuses PHP below 7.4, because PrestaShop 1.7.8 still
  runs on 7.1-7.3 and core checks only the PrestaShop version. The README now
  gives both causes of manual review: a paid amount that no longer matches the
  order, or an invoice replacement that was refused because the old invoice
  was paid, still payable or could not be read (BUG-188).
- A blocked invoice replacement told the buyer the payment was being confirmed
  on-chain and could offer "Continue payment" on the old invoice, which may
  already be paid or be for an amount the order no longer has (BUG-190).
  `validation.php` now adds `paymos_review=1` to the status-page link in that
  case only, and the page shows "This order needs review" with the SDK buyer
  message ("The store needs to review this order before payment can continue.
  Please contact the store.") and no payment link. The order state alone does
  not switch the text, because a paid order whose amount fails the guard is in
  the same manual-review state, but an order in that state is no longer offered
  the payment link either. The decision lives in the new `PendingPage` class.
  Both strings are in all six catalogues.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order moves to the manual-review state with a note,
  instead of the payment-error state. A 404 on the read of the live invoice no
  longer cuts a new one either.
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.15] - 2026-09-29

- docs(prestashop): публичные страницы — PrestaShop 1.7.8–8.x; перевод ошибки о PHP 7.4
- chore: bundle Paymos PHP SDK v1.5.0

### Fixed
- The module claimed PrestaShop 1.7.6 and PHP 7.4, a pair that cannot exist:
  PrestaShop 1.7.6 and 1.7.7 run only up to PHP 7.3 (BUG-187). The minimum is
  now PrestaShop 1.7.8, the first release that runs on PHP 7.4, in
  `ps_versions_compliancy`, the README, `composer.json` and the documentation;
  the README no longer claims 9.x, which the declared maximum of 8.99.99 does
  not allow. `install()` refuses PHP below 7.4, because PrestaShop 1.7.8 still
  runs on 7.1-7.3 and core checks only the PrestaShop version. The README now
  gives both causes of manual review: a paid amount that no longer matches the
  order, or an invoice replacement that was refused because the old invoice
  was paid, still payable or could not be read (BUG-188).
- A blocked invoice replacement told the buyer the payment was being confirmed
  on-chain and could offer "Continue payment" on the old invoice, which may
  already be paid or be for an amount the order no longer has (BUG-190).
  `validation.php` now adds `paymos_review=1` to the status-page link in that
  case only, and the page shows "This order needs review" with the SDK buyer
  message ("The store needs to review this order before payment can continue.
  Please contact the store.") and no payment link. The order state alone does
  not switch the text, because a paid order whose amount fails the guard is in
  the same manual-review state, but an order in that state is no longer offered
  the payment link either. The decision lives in the new `PendingPage` class.
  Both strings are in all six catalogues.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order moves to the manual-review state with a note,
  instead of the payment-error state. A 404 on the read of the live invoice no
  longer cuts a new one either.
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.14] - 2026-09-25

- fix(prestashop): BUG-187 минимум PrestaShop 1.7.8 под PHP 7.4; BUG-188 — README о ручной проверке
- fix(prestashop): BUG-190 при заблокированной замене счёта страница статуса просит связаться с магазином и не ведёт на оплату
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- The module claimed PrestaShop 1.7.6 and PHP 7.4, a pair that cannot exist:
  PrestaShop 1.7.6 and 1.7.7 run only up to PHP 7.3 (BUG-187). The minimum is
  now PrestaShop 1.7.8, the first release that runs on PHP 7.4, in
  `ps_versions_compliancy`, the README, `composer.json` and the documentation;
  the README no longer claims 9.x, which the declared maximum of 8.99.99 does
  not allow. `install()` refuses PHP below 7.4, because PrestaShop 1.7.8 still
  runs on 7.1-7.3 and core checks only the PrestaShop version. The README now
  gives both causes of manual review: a paid amount that no longer matches the
  order, or an invoice replacement that was refused because the old invoice
  was paid, still payable or could not be read (BUG-188).
- A blocked invoice replacement told the buyer the payment was being confirmed
  on-chain and could offer "Continue payment" on the old invoice, which may
  already be paid or be for an amount the order no longer has (BUG-190).
  `validation.php` now adds `paymos_review=1` to the status-page link in that
  case only, and the page shows "This order needs review" with the SDK buyer
  message ("The store needs to review this order before payment can continue.
  Please contact the store.") and no payment link. The order state alone does
  not switch the text, because a paid order whose amount fails the guard is in
  the same manual-review state, but an order in that state is no longer offered
  the payment link either. The decision lives in the new `PendingPage` class.
  Both strings are in all six catalogues.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order moves to the manual-review state with a note,
  instead of the payment-error state. A 404 on the read of the live invoice no
  longer cuts a new one either.
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.13] - 2026-09-25

- fix(plugins): BUG-163/BUG-164 остальные плагины — замена счёта только по ответу сервера закреплена тестами, комментарии о сроке счёта исправлены
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.12] - 2026-09-23

- chore: rebuild canonical CMS package

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.11] - 2026-09-21

- chore: rebuild canonical CMS package

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.10] - 2026-09-17

- chore: bundle Paymos PHP SDK v1.4.1

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.9] - 2026-08-30

- fix(plugins): phase 4 debts — no installs in the wild, code lands now
- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.8] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- chore: rebuild canonical CMS package

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.7] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- Merge remote-tracking branch 'origin/main' into codex/payment-channels
- Merge main into codex/payment-channels
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

### Fixed
- An underpaid invoice was announced as "confirming"; it now names the amount
  still outstanding.

## [1.3.6] - 2026-08-08

- fix(plugins): make the six shipped locales actually reach the merchant

## [1.3.5] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.3.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.2] - 2026-08-07

- fix(prestashop): stop a redelivered webhook from crashing into a retry loop
- fix(prestashop): name the SSL setting when connect refuses an http shop URL
- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.3.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- feat(localization): import platform locale foundation
- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- feat: add localization coverage and gateway translations

## [1.1.3] - 2026-08-03

- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.5] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

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
