# Paymos for PrestaShop

Official Paymos payment module for PrestaShop. It offers stablecoin payment at
checkout, redirects the customer to the hosted Paymos checkout, and brings the
order to **Payment accepted** once the transfer confirms — from a signed callback,
never from the customer's browser returning.

Installing it creates three order states of its own, so a crypto payment does not
have to borrow a state that means something else in your back office. The cart's
currency is what the invoice is denominated in, and the rate is fixed the moment
the customer pays.

## Requirements

- PrestaShop 1.7.6 or later, which covers 8.x and 9.x;
- PHP 7.4 or later with the `curl`, `hash`, `json` and `openssl` extensions;
- an https storefront — `PS_SSL_ENABLED` on, and the shop URL served over TLS;
- a Paymos account, with the project that should collect this shop's orders selected in the dashboard.

The Paymos PHP SDK is vendored in the package, so there is no Composer step on the
shop. The module carries its own translations for English, Russian, German,
Spanish, Turkish and Simplified Chinese.

## Install and connect

1. Download the latest package from [GitHub Releases](https://github.com/paymos-labs/prestashop/releases/latest).
2. Upload and enable it in **Module Manager**.
3. Open the intended project in the Paymos dashboard; that current project is used automatically.
4. Open **Module Manager → Paymos → Configure** and click **Connect Paymos**.
5. Approve the displayed shop URL and current project in Paymos.

The package is the same for everyone and ships without a single credential — no API
key, no secret, no project id, no webhook secret, no token, no device code. Until
step 5 is approved the module is installed but inert.

One approval covers both environments. Per environment Paymos uses your one active
Payment key, or creates it when there is none, and registers an Invoice webhook
against this shop's callback URL — reusing a webhook already there only if its URL,
category and project all match, and refusing to overwrite a conflicting one at the
same address. The device token is short-lived and discarded; Merchant API traffic
is HMAC-signed.

The configuration page then holds one setting — **Mode**, Sandbox or Live — beside
a read-only panel showing the masked key, the bound project, the callback URL and
the reconcile URL. Two things live outside it: the module's currency restrictions,
under the payment preferences PrestaShop applies to every gateway, and the shop URL,
which the connection is bound to.

## Secret storage

Credentials and temporary device state are stored in PrestaShop Configuration as an
AES-256-GCM envelope keyed from `_COOKIE_KEY_`. Saved secrets are not rendered back
into the administration page and cannot be entered by hand. Reconnecting is the only
way to change them, and the only step needed after a key or webhook secret is rotated.

## Order states

Three states are added on install, none of them marked paid or logable, and none
emailing the customer:

- **Awaiting Paymos payment** — the order exists, the invoice is open, nothing has arrived;
- **Paymos payment confirming** — the transfer is on chain and gathering confirmations;
- **Paymos payment — manual review** — money arrived, but a figure stopped adding up.

Terminal outcomes go to PrestaShop's own states, so the rest of your back office
behaves normally:

| What arrives | Order state |
|---|---|
| `invoice.confirming` | Paymos payment confirming |
| `invoice.underpaid_waiting`, `invoice.awaiting_payment` | Awaiting Paymos payment |
| `invoice.paid`, `invoice.paid_over` | Payment accepted |
| `invoice.underpaid` | Payment error |
| `invoice.expired`, `invoice.cancelled` | Canceled |

A paid order is never walked backwards: a late `cancelled`, `expired` or
`confirming` is written to the order's private notes and dropped. And when a paid
callback's amount no longer matches the order, the order goes to **manual review**
instead of Payment accepted, with a note showing every figure that was compared.
The transaction hash and its explorer link are added as a note when the payment
settles on chain.

Uninstalling the module leaves the three states in place. PrestaShop refuses to
delete a state that historical orders reference, and orphaning your past orders
would be the worse outcome.

## What the customer goes through

Paymos appears as a payment option with its own call to action. Choosing it hands
the cart to the module's `validation` controller, which creates the order in
**Awaiting Paymos payment** and redirects to the hosted checkout; the paid amount
is written later from the verified callback, never from the cart at that point.

A double-submitted or refreshed checkout does not mint a second order and a second
invoice — the existing order for that cart is found and the customer is sent to its
status page instead.

If the invoice cannot be created at all, the order is moved to Payment error and the
customer reads a page that says so plainly instead of hitting a blank redirect. The
same controller has a neutral form for the ordinary case: a customer who closed the
payment page too early sees that the payment is still being confirmed, plus a
**Continue payment** link back to their open invoice. It is read-only either way —
that page never transitions an order.

## Test in Sandbox

1. With **Mode** on Sandbox, place an order through the front office.
2. Open that invoice in the Paymos dashboard and trigger the outcome you want to rehearse. Nothing confirms itself on a timer in Sandbox.
3. Watch the order's status history and its notes in the back office.
4. Switch **Mode** to Live. The single connect provisioned both environments, so nothing is set up twice.

No chain sits behind a Sandbox payment, so no transaction hash or explorer link is
recorded there.

## Callbacks and reconciliation

The callback controller reads the raw body and `X-Webhook-Signature`
(`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, timing-safe, and tolerant of both
secrets mid-rotation), and answers with a real HTTP status code. Event ids are
stored in the module's own table, so a redelivery is acknowledged instead of being
applied to the order twice. Before any terminal state is written, the invoice is
pulled back from the Merchant API and re-checked against the stored snapshot.

```text
https://your-store.example/index.php?fc=module&module=paymos&controller=callback
```

Reconciliation is the safety net for a delivery that never arrived. It takes up to
50 unresolved invoices from the last 24 hours and runs each one through the same
mapper, with reverse verification, the amount guard and the roll-back guard all
still in force. There are two ways to trigger it:

- over HTTP, at the tokenised URL shown on the configuration page — this is the
  form to give a scheduler, because PrestaShop 9 denies direct access to `.php`
  files under `modules/` and a cron pointed at the script itself would get a 403;
- from the shell, `php modules/paymos/cron/reconcile.php`, which needs no token.

## Troubleshooting

**Connect refuses an http shop URL.** Enable `PS_SSL_ENABLED` and make sure the
shop is genuinely served over TLS; the module names that setting in the error
rather than failing silently.

**The order sits in Awaiting Paymos payment.** Fetch the callback URL above from
outside your network — it has to answer without HTTP auth and without a redirect,
since redirects are not followed. Then read the PrestaShop log for
`PaymosPrestaShop` entries. If the shop was unreachable for a while, run the
reconcile URL once and the order will catch up.

**An order is in manual review.** The order total changed between the invoice being
created and the payment landing. The note lists the snapshot, the current total and
the amount in the event; settle it by hand.

**Paymos is not offered at checkout.** It hides itself when the cart's currency is
not among the module's permitted currencies, and when the active environment is not
fully connected. Check the currency restrictions first, then the connection panel.

**Refunding a customer.** A confirmed on-chain payment has no reversal. Send a
withdrawal from your Paymos balance to the address you agreed with the customer, and
record the credit slip in PrestaShop separately.

- [Documentation](https://paymos.io/docs/cms-prestashop)
- [Source](https://github.com/paymos-labs/prestashop)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
