# Paymos for PrestaShop — accept USDT and USDC at checkout

PrestaShop 1.7.6+ (including 8.x and 9.x) payment module for stablecoin payments. Customer pays in USDT or USDC. USDT settles on 11 chains (Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, TON, Avalanche, Solana, NEAR, Plasma). USDC settles on 10 chains (Ethereum, BSC, Polygon, Arbitrum, Optimism, Base, Avalanche, Solana, NEAR, Sui).

**Setup takes minutes**: the `paymos-prestashop.zip` you download from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) ships with your API keys pre-injected, your webhook callback URL pre-built from your store URL, and your signing secret pre-registered. No copy-paste, no separate dashboard trip after install.

[![PrestaShop 1.7.6+](https://img.shields.io/badge/PrestaShop-1.7.6%2B%20(8.x%20%2F%209.x)-981def)](https://www.prestashop-project.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue)](LICENSE)

- Full documentation: [paymos.io/docs/cms-prestashop](https://paymos.io/docs/cms-prestashop)
- Product page: [paymos.io/product/plugins/prestashop](https://paymos.io/product/plugins/prestashop)
- Get the plugin ZIP: [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms)

---

## Why setup is minutes, not hours

Other PrestaShop crypto modules ask you to create an API key in their dashboard, copy the key and signing secret into the module settings, calculate and paste the callback URL yourself, configure that URL on their side, and test the handshake manually.

The Paymos package generator does all of that **server-side at download time**:

- Sandbox + Live API credentials — baked into `paymos/paymos-config.php` inside the ZIP
- Webhook callback URL — pre-built from the store URL you typed in the dashboard
- Signing secret — pre-registered, never shown to you
- Both modes (Sandbox / Live) — pre-wired in one bundle, mode switch lives in PrestaShop admin

You upload the ZIP in **Modules → Module Manager → Upload a module**, install it, switch from Sandbox to Live when ready.

---

## Install — full walkthrough

### Step 1: Sign in to Paymos (≈30 sec)

1. Go to [paymos.io/login](https://paymos.io/login).
2. Email magic-link **or** Google — no password, no documents.
3. Onboarding wizard, 3 required steps: business name, country, integration pick.
4. Pick **CMS plugin → PrestaShop**.
5. You land on [paymos.io/dashboard/quickstart](https://paymos.io/dashboard/quickstart).

### Step 2: Generate the package (≈20 sec)

1. Open [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms).
2. Select **PrestaShop**.
3. Confirm the project that should receive PrestaShop orders is selected in your dashboard workspace.
4. Enter your public store URL — for example `https://shop.example.com`.
5. Click **Download PrestaShop package**.

The ZIP is built server-side at this moment. Paymos creates or reuses sandbox + live Payment API keys, registers a project-scoped invoice webhook endpoint, and derives your callback URL from the store URL you typed:

```
https://shop.example.com/index.php?fc=module&module=paymos&controller=callback
```

### Step 3: Install in PrestaShop (≈40 sec)

1. PrestaShop admin → **Modules → Module Manager** → **Upload a module** → drop `paymos-prestashop.zip`.
2. The module installs and appears as **Paymos** under Payment.
3. Click **Configure** on the Paymos row.

The package writes read-only sandbox and live credentials to:

```
modules/paymos/paymos-config.php
```

PrestaShop only stores presentation settings (mode, API base URL). It never asks you for an API key.

### Step 4: Activate and test (≈30 sec)

1. In the Paymos configuration, set **Mode: Sandbox** → **Save**.
2. Visit your storefront → add any product to cart → checkout.
3. Pick **Pay with stablecoins (USDT / USDC)** at payment selection.
4. On the hosted Paymos page, click **Simulate payment**.
5. Back in PrestaShop admin → Orders → status flips to **Payment accepted** within seconds.

Working? Switch to **Mode: Live**. Done.

---

## Requirements

- PrestaShop **1.7.6+**, including **8.x** and **9.x** (the module needs the `PaymentOption` API introduced in 1.7)
- PHP per your PrestaShop version: PHP 7.4 covers 1.7.6–8.x; PrestaShop 9.0 requires PHP **8.1+**
- PHP **7.4+**
- A public HTTPS store URL
- An active Paymos account with a project

No Composer install required on your store. The module ships with the Paymos PHP SDK bundled under `paymos/vendor`.

---

## Runtime flow

1. Customer reaches PrestaShop checkout, picks Paymos.
2. The `validation` controller creates the order in **Awaiting Paymos payment**, then mints a Paymos invoice via the Merchant API using the order total and currency.
3. Customer is redirected to the hosted Paymos page.
4. Customer pays in USDT or USDC on a supported chain.
5. Paymos confirms the on-chain payment using a tiered policy — small tickets clear in seconds, large tickets wait for more confirmations.
6. Paymos sends a signed callback to your store.
7. The `callback` controller verifies signature + timestamp + amount, then reverse-verifies the terminal state against the Paymos API.
8. PrestaShop moves the order to **Payment accepted** using the verified Paymos amount.

If the callback is lost in transit, the reconcile cron re-checks recent unpaid Paymos invoices.

Reference: [paymos.io/docs/payment-flow](https://paymos.io/docs/payment-flow).

---

## Configuration

The package pre-fills everything technical. PrestaShop admin only exposes:

| Setting | What it controls |
|---|---|
| Mode | `Sandbox` for tests, `Live` for production. Switch without re-uploading. |
| API base URL | Defaults to `https://api.paymos.io`. Change only if Paymos support tells you to. |

Generated values loaded from `paymos/paymos-config.php`, not editable in PrestaShop:

| Generated value | Description |
|---|---|
| API Key | Sandbox and live Payment keys |
| API Secret | HMAC signing secrets |
| Project ID | Paymos project used for PrestaShop orders |
| Webhook Secret | Sandbox and live callback verification secrets |
| Base URL | Defaults to `https://api.paymos.io` |

To rotate any of these — re-download a fresh package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) and reinstall. The previous secret stays valid through a grace window so in-flight callbacks don't fail.

API keys reference: [paymos.io/docs/api-keys](https://paymos.io/docs/api-keys).

---

## Webhooks (callback) — pre-registered, no setup

The dashboard registers your callback URL against your store URL **before the ZIP is generated**. The callback path is fixed:

```
https://shop.example.com/index.php?fc=module&module=paymos&controller=callback
```

You will not need to set this up yourself. The signing secret lives in `paymos-config.php`.

Manage and replay events at [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

The module verifies every incoming callback:

- **Signature** — header `X-Webhook-Signature`, format `t={timestamp},v1={hex}`, algorithm HMAC-SHA256, timing-safe compare, ±5 min timestamp tolerance (rejects replays).
- **Event ID deduplication** — same `event_id` cannot mark the same order paid twice (race-proof, backed by a unique-key table).
- **Reverse verification** — pulls the live invoice from the Paymos API and confirms terminal state before moving the order to paid.

Any check fails → a non-2xx response (401 for a bad signature or timestamp, 400/500 for a processing or config error) → order is **not** updated, and Paymos retries. The status is emitted with `http_response_code()` (never a raw `HTTP/1.1` status line, which breaks under HTTP/2).

Retry policy on the Paymos side: multiple attempts with exponential backoff. Failed callbacks land in the dashboard for manual replay.

Signature verification deep-dive: [paymos.io/docs/webhooks/verify](https://paymos.io/docs/webhooks/verify).

---

## Reconciliation

If a callback was missed in transit, run the reconcile cron — it re-checks recent unpaid Paymos invoices and updates the matching orders through the same verified order mapper (reverse-verify, amount guard and roll-back guard all still apply):

```
php modules/paymos/cron/reconcile.php
```

Or over HTTP with the module's secure token (shown in the module configuration under **Paymos connection**). Use the front-controller URL — PrestaShop 9 blocks direct access to module `.php` files, so a cron pointed at `cron/reconcile.php` over the web returns 403:

```
https://shop.example.com/index.php?fc=module&module=paymos&controller=reconcile&token=<secure-token>
```

You can also replay any event from [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

---

## Sandbox testing

Sandbox is fully wired the moment you install the module. No whitelist, no extra approval.

1. Confirm **Mode: Sandbox** in the configuration.
2. Place a test order.
3. On the hosted Paymos page, hit **Simulate payment**.
4. The order flips to **Payment accepted** within seconds.

Same API surface as Live. Same callback schema. Sandbox uses testnet credentials shipped in the same ZIP, Live uses mainnet.

Sandbox guide: [paymos.io/docs/testing](https://paymos.io/docs/testing).

---

## Behavior matrix

| Paymos invoice state | PrestaShop result |
|---|---|
| `invoice.paid` | Order moved to Payment accepted |
| `invoice.paid_over` | Order moved to Payment accepted (the on-chain surplus is handled in the Paymos dashboard, not as a PrestaShop credit) |
| `invoice.confirming` | Order moved to Paymos payment confirming |
| `invoice.underpaid_waiting` | Order stays Awaiting Paymos payment |
| `invoice.underpaid` | Order moved to Payment error |
| `invoice.expired` | Order moved to Cancelled |
| `invoice.cancelled` | Order moved to Cancelled |
| Paid but amount changed after invoice creation | Held in **Paymos payment — manual review**, not marked paid |

Invoice lifecycle reference: [paymos.io/docs/payment-flow](https://paymos.io/docs/payment-flow).

---

## FAQ

**Why does the package have everything pre-configured?**
Because the dashboard generates it that way. At download time, the server reads your merchant record, creates Sandbox and Live credentials if missing, derives the callback URL from the store URL you typed, registers it on the Paymos side, and writes everything into `paymos-config.php` before zipping.

**Do I ever need to paste an API key into PrestaShop?**
No. The module configuration only exposes mode and the API base URL.

**What if I change my store URL?**
Re-download the package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) after updating the URL in the dashboard, then re-upload.

**Does this work on PrestaShop 1.6?**
No. Minimum is PrestaShop 1.7.6 — the module relies on the `PaymentOption` API introduced in 1.7.

**What happens if a customer pays late?**
If the invoice expires before payment confirms, the order moves to Cancelled. The customer places a new order to try again.

**Are there chargebacks?**
No. Crypto settlement is final on confirmation.

**What if the callback never arrives?**
The reconcile cron re-checks recent unpaid Paymos invoices. You can also replay any event from the dashboard.

---

## Troubleshooting

| Symptom | What to check |
|---|---|
| `Paymos` not appearing at checkout | The store currency is not enabled for the module, or the module is disabled. Check Module Manager. |
| Configuration shows "No credentials package found" | `paymos/paymos-config.php` not present or unreadable. Re-download the package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms). |
| Order not flipping after sandbox simulate | Signature verification failed. Check PrestaShop logs (`Advanced Parameters → Logs`) for `PaymosPrestaShop` entries. |
| Callback returns 401 | Webhook secret rotated on the dashboard side but old package still installed. Re-download. |
| Live mode still behaves like sandbox | Mode switch in the admin still on Sandbox. |

Error reference: [paymos.io/docs/errors](https://paymos.io/docs/errors).

---

## Support

- Documentation: [paymos.io/docs/cms-prestashop](https://paymos.io/docs/cms-prestashop)
- Dashboard: [paymos.io/dashboard](https://paymos.io/dashboard)
- Status: [paymos.io/status](https://paymos.io/status)
- Email: [support@paymos.io](mailto:support@paymos.io)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — or browse the public release history at [paymos.io/changelog](https://paymos.io/changelog).

---

## License

MIT — see [LICENSE](LICENSE).
