# Espees Payment Integration — Replication Guide

> **Current status:** Native Espees checkout is available to all unpaid SBO owners
> once the merchant gateway is configured. Normal SBOs are charged their
> server-calculated registration fee. The dedicated test account skips fee
> selection and is fixed at exactly **1 Espee**.

A complete, provider-accurate guide to how **Espees** is integrated in LW Registry,
written so the same pattern can be reproduced on another platform (any stack — the
Espees-specific parts are framework-agnostic; Laravel specifics are called out).

Espees is a **redirect (hosted-checkout) payment provider** with a **server-to-server
confirmation** step. There is no client SDK and no webhook in this integration — the flow
is: create a payment on Espees → redirect the payer to Espees' hosted page → Espees
redirects back to your `success`/`fail` URL → your server calls Espees' confirm endpoint
to verify the result before granting value.

> **Golden rule:** never trust the browser redirect alone. Value (marking a fee "paid")
> is granted **only** after your server independently confirms the transaction with Espees
> and validates the returned status, reference, and amount.

---

## 1. Espees API surface (what you integrate against)

Three HTTP endpoints, authenticated with an API key header. Defaults used here:

| Purpose | Method | URL (default) | Auth |
|---|---|---|---|
| Create a payment (product) | `POST` | `https://api.espees.org/v2/payment/product` | `x-api-key: <token>` |
| Hosted payment page (redirect the user here) | `GET` | `https://payment.espees.org/pay/{payment_ref}` | — (user's browser) |
| Confirm a payment (verify) | `POST` | `https://api.espees.org/v2/payment/confirm/` | `x-api-key: <token>` |

Credentials you obtain from Espees for your merchant:
- **API token** — sent as the `x-api-key` header on the create/confirm calls.
- **Merchant wallet** — your Espees wallet the funds settle to; sent in the create payload.

### 1a. Create payment — request

`POST {product_url}` with header `x-api-key: <token>`, JSON body:

```json
{
  "product_sku": "ESPEES-MBN-2026-0001-AB12CD34",   // YOUR unique local reference
  "narration": "SBO registration fee - Acme Ltd",
  "price": 400,                                       // integer, in Espees
  "merchant_wallet": "<your merchant wallet>",
  "success_url": "https://yourapp/…/success",         // Espees redirects here on success
  "fail_url": "https://yourapp/…/failure",            // …and here on failure
  "user_data": {                                       // free-form; echoed back, for your reconciliation
    "sbo_id": 123,
    "application_id": "MBN-2026-0001",
    "owner_id": 45,
    "local_reference": "ESPEES-MBN-2026-0001-AB12CD34"
  }
}
```

### 1b. Create payment — response

```json
{ "payment_ref": "<espees payment reference>", "...": "..." }
```

- `payment_ref` is **Espees'** reference. Persist it — you need it to build the redirect
  URL and to confirm later. If it is missing/empty, treat the setup as failed.

### 1c. Redirect the user

Send the browser to: `{payment_url}/{payment_ref}` (URL-encode the ref). The user pays on
Espees' hosted page, then Espees redirects them back to your `success_url` or `fail_url`.

### 1d. Confirm payment — request/response

`POST {confirm_url}` with header `x-api-key: <token>`, JSON body:

```json
{ "payment_ref": "<the same espees payment_ref>" }
```

Response (fields this integration relies on):

```json
{
  "transaction_status": "APPROVED",   // APPROVED | PENDING | DECLINE | NOT FOUND
  "product_sku": "ESPEES-MBN-2026-0001-AB12CD34",  // must equal YOUR local reference
  "price": 400,                                     // must equal the amount you charged
  "...": "..."
}
```

**A payment is only "approved" when ALL of these hold** (see `confirmationIsApproved()`):
1. `transaction_status` (upper-cased) `=== "APPROVED"`, **and**
2. `product_sku === your local_reference` (guards against ref mix-ups / tampering), **and**
3. `price` is numeric and equals your charged `amount` (float compare, `< 0.01` tolerance).

Non-approved confirm statuses map to internal gateway states:
`PENDING → pending`, `DECLINE`/`NOT FOUND → failed`, anything else → `verification_failed`.

---

## 2. Configuration model

Two layers: **static endpoints** in config/env, and **per-merchant credentials** that are
admin-editable at runtime (and here, scopable per country).

### 2a. Endpoints (env / config) — `config/services.php`

```php
'espees' => [
    'product_url' => env('ESPEES_PRODUCT_URL', 'https://api.espees.org/v2/payment/product'),
    'payment_url' => env('ESPEES_PAYMENT_URL', 'https://payment.espees.org/pay'),
    'confirm_url'  => env('ESPEES_CONFIRM_URL', 'https://api.espees.org/v2/payment/confirm/'),
],
```

Override per-environment via `.env` (e.g. point at Espees sandbox in staging).

### 2b. Merchant credentials (runtime, admin-managed)

Stored as key/value settings (here: the `sbo_fee_settings` table, country-scopable). Keys:

| Setting key | Meaning |
|---|---|
| `espees_enabled` | `"1"`/`"0"` — master switch for the automated checkout |
| `espees_token` | the `x-api-key` value |
| `espees_merchant_wallet` | the merchant wallet funds settle to |
| `espees_test_mode` | `"1"`/`"0"` — sandbox flag (surface in UI / branch endpoints as needed) |

A `resolveGateway(countryId)` helper reads these and computes a **`configured`** flag —
`enabled && token !== '' && wallet !== ''`. SBO checkout runs for unpaid owners when
`configured`; otherwise the app falls back to manual payment (see §5). Reference
(`SboFeeCalculator::espeesGateway`):

```php
return [
    'enabled'         => $enabled,
    'token'           => $token,
    'merchant_wallet' => $wallet,
    'product_url'     => config('services.espees.product_url'),
    'payment_url'     => config('services.espees.payment_url'),
    'confirm_url'     => config('services.espees.confirm_url'),
    'test_mode'       => $testMode,
    'configured'      => $enabled && $token !== '' && $wallet !== '',
];
```

> **Why runtime settings, not just env:** the merchant can rotate the token / switch
> wallet / enable-disable checkout from the admin UI with no deploy, and (here) different
> countries can use different merchant wallets.

---

## 3. Data model — the transaction ledger

Every checkout attempt is a row in `sbo_payment_transactions`. This is the audit trail and
the idempotency/reconciliation anchor. Reproduce this table (names adaptable):

| Column | Type | Purpose |
|---|---|---|
| `id` | pk | |
| `sbo_id` | fk → payer/entity | who is paying |
| `provider` | string (`espees`) | supports multiple providers later |
| `local_reference` | string, **unique** | **YOUR** ref; sent as `product_sku`; must round-trip |
| `provider_reference` | string, nullable, indexed | Espees' `payment_ref` |
| `amount` | unsigned int | charged amount (integer Espees) |
| `currency` | string | e.g. `Espees` |
| `gateway_status` | string, indexed | lifecycle vs. the gateway (see below) |
| `review_status` | string, indexed | manual-review state: `none`/`pending`/`approved`/`rejected` |
| `raw_payload` | json, nullable | every request/response/redirect query, appended over time |
| `rejection_reason` | text, nullable | admin reason when rejected |
| `reviewed_by` | fk → users, nullable | admin who actioned (null when auto-approved) |
| `submitted_at` | timestamp, nullable | when payment was submitted/confirmed |
| `reviewed_at` | timestamp, nullable | when reviewed/auto-approved |
| `timestamps` | | |

**`gateway_status` lifecycle:**
`initiated` → `redirected` → (`submitted` on success) — or `failed` /
`verification_failed` / `pending` on the various non-success paths.

Keep `raw_payload` append-only (merge, don't overwrite) so you retain the full forensic
trail: the create request/response, the redirect query string, and the confirm response.

---

## 4. The end-to-end flow (server logic)

Three server actions. In Laravel these are `start` / `success` / `failure` controller
methods; the logic is portable to any framework.

### 4a. START — create the payment and redirect

Route (POST, auth-gated to the owner): `sbo/applications/{sbo}/payments/espees/start`

1. **Authorize** the caller owns the entity (`created_by === user.id`).
2. **Guards:** if already paid → bounce back with a friendly message; normal users
   must have a confirmed fee; the dedicated test account uses the fixed 1-Espee
   amount; if gateway not `configured` → tell the owner to use manual payment.
3. **Create the transaction row** with a unique `local_reference` (retry-until-unique):
   `ESPEES-{application_id or SBO-id}-{RANDOM8}`. Status `initiated`.
4. **Call `createPayment()`.** On failure, mark the row `failed` (store the error in
   `raw_payload`) and redirect back with an error — **no exception leaks to the user**.
5. On success, save `provider_reference = payment_ref`, set `redirected`, store the
   request+response in `raw_payload`.
6. **Redirect** the browser to `paymentUrl(payment_ref)` (Espees hosted page).

### 4b. SUCCESS — verify server-side, then grant value

Route (GET, the `success_url`): `sbo/payments/espees/{transaction}/success`

1. **Authorize** owner; `abort(404)` if the transaction isn't an `espees` one.
2. **Idempotency:** if the transaction is already approved or the fee already paid →
   redirect with the success message and stop.
3. Re-resolve the gateway; if not `configured`, record the redirect query and bail.
4. **Call `confirmPayment()`** (server-to-server). On error → `verification_failed`,
   store the error, ask the user to retry/contact support.
5. Append the confirm response to `raw_payload`.
6. **Validate with `confirmationIsApproved()`** (status + sku + amount, §1d). If it passes:
   - Set the transaction: `gateway_status=submitted`, `review_status=approved`,
     `submitted_at`, `reviewed_at=now`, `reviewed_by=null` (auto).
   - Copy `payment_reference` and `payment_submitted_at` onto the entity.
   - **Grant value** via the shared confirmer (marks fees paid, logs, notifies — §6).
   - Redirect with success.
7. If it does **not** pass: set `gateway_status` from the status map and redirect with a
   "not approved yet — retry or use manual" message. (No value granted.)

### 4c. FAILURE — record and offer retry

Route (GET, the `fail_url`): `sbo/payments/espees/{transaction}/failure`

1. Authorize owner; `abort(404)` if not `espees`.
2. If not already approved, mark `failed`, capture any provider reference from the query
   (tries several common keys: `payment_ref`, `reference`, `tx_ref`, `trxref`, `ref`, …),
   and append the failure query to `raw_payload`.
3. Redirect back with a "not completed — try again or use manual" message.

### Route summary

```php
Route::post('…/payments/espees/start',              [Ctrl::class, 'start'])  ->name('…espees.start');
Route::get ('…/payments/espees/{transaction}/success',[Ctrl::class,'success'])->name('…espees.success');
Route::get ('…/payments/espees/{transaction}/failure',[Ctrl::class,'failure'])->name('…espees.failure');
// all behind auth + role middleware; success/fail URLs are what you pass to Espees on create
```

---

## 5. SBO checkout, test account, and manual fallback

### 5a. Dedicated Espees test account

The dedicated test-account path is protected by all of the following conditions:

- `ESPEES_TEST_ACCOUNT_ENABLED=true` must be explicitly set;
- the application environment must be `local`, `testing`, or `staging`; production requires the additional `ESPEES_TEST_ACCOUNT_ALLOW_PRODUCTION=true` opt-in;
- the signed-in user's email must match the configured test-account email; and
- the Espees merchant settings must be enabled with `espees_test_mode=1`.

The seeded test credentials are:

| Field | Value |
|---|---|
| Username / email | `espees.test@lwregistry.test` |
| Password | `EspeesTest!2026` |
| Checkout amount | `1 Espee` |

These are test-only credentials and must not be reused for a real user. To
create/reset the account and its approved test SBO in local or staging:

```dotenv
ESPEES_TEST_ACCOUNT_ENABLED=true
ESPEES_TEST_ACCOUNT_ALLOW_PRODUCTION=true
ESPEES_TEST_ACCOUNT_EMAIL=espees.test@lwregistry.test
ESPEES_TEST_ACCOUNT_PASSWORD=EspeesTest!2026
```

For production triage, leave `APP_ENV=production` and set both enablement flags
above. This is an intentional exception for the dedicated account only; it may
call the live Espees endpoint and should be disabled as soon as triage ends.

Then run:

```bash
php artisan optimize:clear
php artisan db:seed --class=Database\\Seeders\\EspeesTestAccountSeeder
```

Configure the Espees token, merchant wallet, and enabled switch from the admin
Fees page before signing in. Non-production environments also require the
test-mode switch; the explicit production flag permits the production triage
account to use the configured live provider path. The test account skips the
normal fee-selection form and is shown only the hosted 1-Espee payment option,
whose amount is independently checked against the provider response.

### 5b. Espees checkout for normal SBO accounts

Once a normal SBO owner confirms the calculated registration fee, the payment step
shows **Pay securely with Espees**. The amount sent to Espees is the persisted,
server-calculated fee; the confirmation response must match both the local reference
and that amount before the shared confirmer marks fees paid. Normal users are
authorized by SBO ownership, not by the test-account gate.

### 5c. Manual fallback for normal SBO accounts

If the gateway is unavailable or the owner needs an alternative, the payer can
transfer to the **merchant code** (e.g. `MOCDII1`) via the Espees app, enter a
**payment reference**, and **upload proof** (PDF/JPG). This sets
`payment_submitted_at` but **does not** mark paid — an admin reviews the proof and
confirms it.

---

## 6. Granting value + manual admin review

**Single choke point for "fees are now paid":** a shared confirmer (`SboPaymentConfirmer`)
so automated and manual paths behave identically. It:
1. No-ops if already paid (idempotent).
2. Sets `payment_status=paid`, `payment_confirmed_at=now`.
3. Writes an activity-log entry.
4. Notifies the owner (`FeesPaidNotification`).
5. If the entity is now fully verified, issues the final number + verified notification.

**Admin manual review** (`SboPaymentTransactionReviewer`) handles transactions that need a
human (e.g. gateway `pending`, or manual proof): `approve()` routes through the same
confirmer; `reject()` sets `review_status=rejected` with a reason and logs it. Approve/reject
are surfaced on an admin "Payment approvals" page.

---

## 7. Security & correctness checklist (do not skip)

- [ ] **Confirm server-side.** Never mark paid off the browser redirect; always call the
      confirm endpoint from your server and check `transaction_status === APPROVED`.
- [ ] **Bind the reference.** Verify `product_sku` in the confirm response equals your
      stored `local_reference`. This prevents a user from replaying someone else's ref.
- [ ] **Verify the amount.** Compare confirmed `price` to the amount you charged.
- [ ] **Idempotency.** Guard `success` so re-hitting it (refresh, double redirect) can't
      double-grant. Key off `review_status=approved` / fee already paid.
- [ ] **Authorization.** Every route checks the caller owns the transaction/entity.
- [ ] **Unique local reference** with DB-level `unique` + retry-on-collision.
- [ ] **Secrets.** API token lives in runtime settings/secret store, never in code or the
      client. It's only ever sent as the `x-api-key` header, server-to-server.
- [ ] **Timeouts + failure capture.** HTTP calls time out (15s here); on any failure, record
      the error in `raw_payload` and show a safe message — never leak exceptions.
- [ ] **Append-only audit.** Persist every request, response, and redirect query.
- [ ] **Graceful disable.** A `configured` gate + manual fallback so the app works even when
      the gateway is off/misconfigured.

---

## 8. Minimal replication recipe (any stack)

1. Get Espees merchant credentials: **API token** + **merchant wallet**.
2. Store endpoints in config; store token/wallet/enabled/test-mode as editable settings.
3. Create a **transactions** table (§3).
4. Implement a **gateway client** with two calls:
   - `createPayment(payload)` → `POST product_url` (header `x-api-key`) → returns `payment_ref`.
   - `confirmPayment(payment_ref)` → `POST confirm_url` (header `x-api-key`) → returns status/sku/price.
   - plus `paymentUrl(ref) = payment_url + '/' + urlencode(ref)`.
5. Implement **start / success / failure** handlers (§4), passing your own `success_url` /
   `fail_url` into `createPayment`.
6. Implement a **confirmer** (single place that grants value) and reuse it for both the
   auto path and admin manual approval.
7. Add a **manual fallback** (merchant code + reference + proof upload → admin confirm).
8. Work through the **security checklist** (§7).

---

## 9. Source map (this codebase)

| Concern | File |
|---|---|
| Gateway HTTP client (create/confirm/url) | `app/Support/EspeesMerchantGateway.php` |
| Advertising checkout flow | `app/Http/Controllers/AdCampaignPaymentController.php` |
| Legacy SBO transaction model | `app/Models/SboPaymentTransaction.php` |
| Legacy transactions table | `database/migrations/2026_07_05_010000_create_sbo_payment_transactions_table.php` |
| Endpoint config | `config/services.php` (`espees`) |
| Runtime gateway resolver | `app/Support/SboFeeCalculator.php` (`espeesGateway()`), advertising + SBO checkout |
| Test-account gate and fixed amount | `app/Support/EspeesTestAccount.php` |
| SBO checkout controller | `app/Http/Controllers/SboEspeesPaymentController.php` |
| Grant-value choke point | `app/Support/SboPaymentConfirmer.php` |
| Admin manual approve/reject | `app/Support/SboPaymentTransactionReviewer.php` |
| Admin approvals UI | `resources/views/livewire/admin/approvals/payments.blade.php` |
| Admin credential settings UI | `resources/views/livewire/admin/fees/index.blade.php` |
| Payer UI | `resources/views/livewire/sbo-fees.blade.php` |
| SBO checkout routes | `routes/web.php` (authenticated SBO owners) |
