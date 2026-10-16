# MoonAura Crystals — Setup Guide

**Current version: v0.6.7.** Functional ecommerce (catalog, cart,
checkout, Razorpay/COD/Manual UPI, accounts, admin, GST invoices,
transactional email, storefront newsletter, Light/Dark/System theme)
is implemented. Phase 5H POS / walk-in is **not** implemented.
Admin newsletter campaigns (Phases 1–4) and Admin Dark Mode are implemented; live QA is pending. Open/click tracking is not configured.
See `PROJECT_STATE.md` and `PROJECT_STATUS.md`.

The live admin directory is `dashboard/` (URLs use `ADMIN_URL`, default
`SITE_URL/dashboard`). Older notes that say `admin/*.php` mean these
dashboard files.

This file explains how to set up and run the project. It was originally
written after Phase 0 (PHP/MySQL foundation) and has grown with each
phase; the payment/migration sections below cover every phase that
touches the database.

---

## 1. Project Folder Structure

```
Moonaura/
├── dashboard/                 Admin panel (not admin/)
│   ├── assets/css/admin.css   Admin styling (no storefront Dark Mode)
│   ├── includes/              Admin header / sidebar / footer
│   ├── setup.php              Run ONCE to create the first admin, then remove
│   ├── login.php / logout.php
│   ├── dashboard.php
│   ├── products.php, categories.php, customers.php, orders.php
│   ├── settings.php, invoice-designer.php, 2fa-setup.php
│   └── ...
├── account/                   Customer account pages
├── assets/css/                Storefront CSS (style.css + page CSS)
├── assets/js/                 Vanilla JS (theme.js, cart, checkout, ga4, pixel)
├── config/config.php          Env mapping (credentials from gitignored .env)
├── database/                  schema.sql, seed.sql, migrations
├── includes/                  Shared PHP (db, auth, cart, orders, tax, mail, payments)
├── docs/                      Design system and architecture docs
├── index.php, shop.php, product.php, cart.php, checkout.php
├── about.php, support.php, policy.php, concerns.php
├── newsletter-subscribe.php, newsletter-confirmed.php
├── sitemap.php, robots.txt
└── webhook-razorpay.php
```

Full module lists: `PROJECT_STATE.md`. Do not commit `.env` or credential files.

---

## 2. Database Setup

1. Create a new MySQL database (phpMyAdmin, or the command line):
   ```sql
   CREATE DATABASE moonaura;
   ```
2. Import the two SQL files, in this order:
   - `database/schema.sql` (creates all the tables)
   - `database/seed.sql` (adds starter categories + one collection)

   Using the command line:
   ```
   mysql -u root -p moonaura < database/schema.sql
   mysql -u root -p moonaura < database/seed.sql
   ```
   Or in phpMyAdmin: open the `moonaura` database → Import → choose the file → Go.

---

## 3. Configuring config.php

Open `config/config.php` and update these 4 lines to match your MySQL setup:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'moonaura');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Also update the site URL if needed:

```php
define('SITE_URL', 'http://localhost/Moonaura');
define('ADMIN_URL', 'http://localhost/Moonaura/dashboard');
```

Live split-host values: `SITE_URL=https://moonauracrystals.in` and
`ADMIN_URL=https://admin.moonauracrystals.in`. Public CSS/JS/images
must always load from `SITE_URL` via `asset_url()`. Admin-local files
(`dashboard/assets/css/admin.css`) stay on `ADMIN_URL`.

When you move the site to live hosting, come back to this file and:
- Update the 4 database lines with your host's DB credentials.
- Change `ENVIRONMENT` from `'development'` to `'production'` (this hides
  detailed error messages from visitors).

---

## 4. Creating Your First Admin Account

1. In your browser, go to: `http://localhost/Moonaura/dashboard/setup.php`
2. Fill in your name, email, and a password (minimum 8 characters).
3. Submit the form - this creates your admin account and redirects you to
   the login page.
4. **Delete (or move) `dashboard/setup.php` after this** - it locks itself
   automatically once one admin account exists, but removing the file
   entirely is safer.

After that, log in any time at: `http://localhost/Moonaura/dashboard/login.php`

---

## 5. Running the Project Locally

You need a local server with PHP 8+ and MySQL - e.g. **XAMPP**, **MAMP**,
or **Laragon**.

1. Place the `Moonaura` folder inside your server's web root
   (e.g. `htdocs/Moonaura` for XAMPP).
2. Start Apache and MySQL from your control panel.
3. Follow steps 2-4 above (database + config + first admin account).
4. Visit `http://localhost/Moonaura/index.php` in your browser.

Note: storefront pages are `.php` (not static `.html`). A PHP-enabled
server is required. The catalog, cart, checkout, and accounts are
dynamic against MySQL.

---

## 6. Payment Gateway Setup (Phase 3A - Razorpay Live + Cash on Delivery)

**Current production state:**
- **Razorpay = ACTIVE / LIVE**
- **COD = ACTIVE**
- **Cashfree = NOT IN USE**
- **PhonePe = NOT IN USE**

Razorpay is the only online payment gateway. Cashfree and PhonePe are
not used and are not available at checkout.

1. **PHP's cURL extension must be enabled** - `RazorpayGateway` uses
   raw cURL calls (no Composer/SDK). Most PHP installs (including
   XAMPP/MAMP/Laragon defaults) already have this on; check
   `php -m | grep curl` if unsure.

### Razorpay (ACTIVE / LIVE)

2. Create a [Razorpay](https://razorpay.com) account. For production,
   switch to **Live Mode**, go to **Settings > API Keys** for a live
   Key ID/Secret, and **Settings > Webhooks** (pointing to
   `webhook-razorpay.php`) for the webhook secret:
   ```
   RAZORPAY_MODE=live
   RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxx
   RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
   RAZORPAY_WEBHOOK_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
   ```
   For local/staging only, use test keys (`rzp_test_...`) and
   `RAZORPAY_MODE=test`. Razorpay's test mode has published test
   card numbers/UPI IDs - see their [test card documentation](https://razorpay.com/docs/payments/payments/test-card-details/).

### Cash on Delivery (ACTIVE)

COD is enabled (`cod_enabled = 1`) and is offered at checkout
alongside Razorpay. Toggle it from `/dashboard/settings.php`, or:

```sql
UPDATE settings SET setting_value = '1' WHERE setting_key = 'cod_enabled';
```

### Cashfree and PhonePe (NOT IN USE)

Cashfree and PhonePe are **not** available payment options. Do not
configure `CASHFREE_*` or PhonePe credentials. Do not enable
`cashfree_enabled` or `phonepe_enabled` — those integrations have
been removed.

Runtime confirmation (current codebase):
- `PaymentManager` registers **only** `razorpay`. There is no
  `CashfreeGateway` or `PhonePeGateway` class, and no
  `webhook-cashfree.php` endpoint.
- Checkout payment radios come from
  `PaymentManager::getEnabledAndConfiguredGatewayNames()` (Razorpay
  when enabled **and** configured) plus COD if `cod_enabled` is on
  (and Manual UPI QR if that separate Phase 4D flag is on). Cashfree
  and PhonePe cannot appear as selectable methods.
- Leftover `cashfree_enabled` / `phonepe_enabled` rows from older
  migrations are inert: a settings flag with no registered class is
  never offered or processed.
- Historical orders that stored `payment_method = 'cashfree'` still
  display as "Paid Online (Cashfree)" so old receipts stay readable.
  Mentions of PhonePe as a UPI *app* (e.g. Manual UPI copy) are
  informational, not a gateway.

### Multi-gateway checkout (Phase 4C)

3. Run `database/migration_phase4c_multi_gateway_checkout.sql` (or
   use a fresh `schema.sql` + `seed.sql` if setting up new) - this
   adds `razorpay_enabled` and `default_payment_gateway` to the
   `settings` table (historical Cashfree/PhonePe flags from older
   installs are inert if still present). You'll also still need
   `database/migration_phase3a_payments.sql` first if this is an
   older database that's never had the `settings` /
   `payment_transactions` tables at all - **without it, checkout will
   error with `Base table or view not found ... table 'settings'
   doesn't exist`** the moment it calls `get_setting()`.
4. **Razorpay is the online gateway** - checkout shows it when it is
   both enabled **and** configured (`isConfigured()`; missing API keys
   hide the option even if the enabled flag is on), plus Cash on
   Delivery if `cod_enabled` is on. `default_payment_gateway`
   controls which one is pre-selected - it's ignored if it doesn't
   name a currently-enabled gateway (falls back to the first enabled
   one automatically). To change any of this, update the `settings`
   table directly for now:
   ```sql
   UPDATE settings SET setting_value = '1' WHERE setting_key = 'razorpay_enabled';
   UPDATE settings SET setting_value = 'razorpay' WHERE setting_key = 'default_payment_gateway';
   UPDATE settings SET setting_value = '1' WHERE setting_key = 'cod_enabled';
   ```
   Disabled or unconfigured gateways are also rejected server-side if
   somehow submitted anyway (checkout.php validates against the
   enabled-and-configured list, not just what the form shows) - and
   the system won't let itself end
   up with zero payment methods available; if everything is
   accidentally disabled at once, Cash on Delivery is forced back on
   automatically as a safety fallback (logged, not silent).
   **An Admin Settings page now exists at `/dashboard/settings.php`**
   (linked from the sidebar) to change all of this through the UI
   instead of direct SQL - it enforces the same two rules
   server-side (can't disable every payment method, can't set an
   unselected/disabled gateway as default) rather than just hiding
   the option client-side.

### Manual UPI QR payment (Phase 4D)

5. Run `database/migration_phase4d_manual_upi_qr.sql` (or use a fresh
   `schema.sql` + `seed.sql`) - adds `manual_upi_enabled` (off by
   default), `upi_id`, `upi_account_name`, `upi_qr_image_path` to
   `settings`, and a `screenshot_path` column to
   `payment_transactions`.
6. An additional checkout option, alongside Razorpay and COD: the
   customer sees a QR code / UPI ID / account name (set via
   `/dashboard/settings.php`'s "Manual UPI Settings" section, including
   the QR image upload), pays via any UPI app, and submits the UTR
   (transaction reference number) plus an optional screenshot on
   `manual-upi-payment.php`. This does **not** mark the order paid by
   itself - it logs a `payment_transactions` row with
   `status = 'submitted'` and leaves `payment_status = 'pending'`.
7. **An admin must verify or reject it** from
   `/dashboard/order-detail.php` - a dedicated card shows the UTR number,
   submission timestamp, and a link to the uploaded screenshot (if
   any), with two buttons:
   - **Verify Payment** → `payment_status = 'paid'`,
     `order_status = 'processing'` (same end state a successful
     online-gateway payment reaches)
   - **Reject Payment** → `payment_status = 'failed'`,
     `order_status` unchanged (the order isn't cancelled, just not
     yet paid - same as a failed online-gateway attempt doesn't
     cancel the order either)
   Both buttons only appear while the order is still
   `payment_status = 'pending'` on a `manual_upi` order - once
   resolved, the card still shows the submission details but the
   actions disappear, and this deliberately never touches how
   Razorpay verifies itself (its own webhook/callback flow is
   completely unrelated to this admin action).

## 7. Email / SMTP Setup (Phase 5D - Brevo + PHPMailer)

Transactional email (currently: the **password reset** emails for both
the customer and admin forgot-password flows) is sent through
**Brevo SMTP** using **PHPMailer** (`includes/mailer.php` is the only
place that talks to SMTP; `includes/email-templates.php` holds the
branded templates). Everything is environment-driven - **no
credentials exist anywhere in the codebase**.

1. Create a free [Brevo](https://www.brevo.com) account and go to
   **Settings > SMTP & API > SMTP** to get the SMTP credentials.
2. Put the six required variables in your `.env` file:
   ```
   BREVO_SMTP_HOST=smtp-relay.brevo.com
   BREVO_SMTP_PORT=587
   BREVO_SMTP_USERNAME=<your brevo SMTP login>
   BREVO_SMTP_PASSWORD=<your brevo SMTP key>
   MAIL_FROM_ADDRESS=no-reply@yourdomain.example
   MAIL_FROM_NAME=MoonAura Crystals
   MAIL_REPLY_TO_ADDRESS=support@yourdomain.example
   ```
   `BREVO_SMTP_PORT` is 587 (STARTTLS) or 465 (implicit TLS); TLS is
   picked automatically from the port. Optional overrides:
   - `BREVO_SMTP_SECURE=auto|tls|ssl|none` - force the TLS mode if
     the auto port-detection ever needs overriding.
   - `MAIL_FROM_NAME` defaults to `SITE_NAME` if omitted.
   - `MAIL_REPLY_TO_ADDRESS` defaults to `support@moonauracrystals.in`;
     it becomes the Reply-To on every transactional email (order
     confirmations, shipping/delivery, password resets) so replies land
     in a monitored mailbox while the From stays the no-reply sender.
3. **No SMTP configured?** Email sending is disabled and the mailer
   fails closed (see `mail_is_configured()`): `send_email()` returns
   `false` and writes one `error_log` line instead of throwing, so the
   password-reset flow still works - the page just shows the same
   generic message and the email simply doesn't arrive.

## 8. Phase 5x Migrations (v0.5.0)

> This section walks the Phase 5x migrations in the order those
> features shipped (their narrative/roadmap order), and intentionally
> does not re-list Phase 4C/4D (covered separately above, in
> **§ Multi-gateway checkout (Phase 4C)** and
> **§ Manual UPI QR payment (Phase 4D)**). For the single canonical,
> live-verified order to run **all 9** required migrations end to end
> on a fresh upgrade (including where 4C/4D fall relative to these),
> use `DEPLOYMENT_CHECKLIST.md` § 1 — not the numbering below, which
> is per-section-local, not a global sequence.

Four Phase 5 roadmap items are shipped so far — **5A** (UI/UX & Order
Tracking), **5B** (Customer Forgot Password), **5F** (Inventory
Automation) and **5G** (Login Security). Three of them come from
migration files whose **filenames keep their original historical
numbering** (they are deliberately NOT renamed; "phase5" → Phase 5A,
"phase6" → Phases 5F/5G). Phase 5B shipped under its own real roadmap
name. **Note:** step 2 below (`migration_phase6_stock_login.sql`)
has a hard dependency on step 1 (`migration_phase5_order_tracking.sql`)
having already run — it adds a column positioned `AFTER tracking_url`,
which step 1 creates. Keep that relative order if you ever reorder
this list:

1. Run `database/migration_phase5_order_tracking.sql` — adds
   `orders.courier_partner` / `orders.awb_number` /
   `orders.tracking_url` (nullable shipping/tracking fields, shown to
   the customer once an order is Shipped or Delivered) and the
   `order_status_history` table (one row per real status/payment
   milestone, powering the customer order timeline).
2. Run `database/migration_phase6_stock_login.sql` — adds
   `orders.stock_deducted_at` (nullable idempotency marker for the
   inventory lifecycle; NULL for all pre-existing orders = never
   retroactively deducted) and the `login_attempts` table (brute-force
   lockout bookkeeping for admin + customer login).
3. Run `database/migration_phase5b_customer_password_reset.sql` —
   adds the `customer_password_resets` table (Phase 5B, the
   customer-side mirror of `admin_password_resets`): `customer_id` FK
   to `customers(id)` ON DELETE CASCADE, hashed 60-minute single-use
   reset tokens for `account/forgot-password.php` /
   `account/reset-password.php`.
4. Run `database/migration_phase5e_admin_2fa.sql` (Phase 5E, v0.5.2) —
   adds `two_factor_enabled` (TINYINT(1) DEFAULT 0), `two_factor_secret`
   (VARCHAR(500) NULL, stored AES-256-GCM-encrypted) and
   `two_factor_enabled_at` (DATETIME NULL) to `admin_users`. Existing
   admins all get 2FA-off, so nobody is locked out by the migration.
5. Run `database/migration_phase5f1_recovery_codes.sql` (Phase 5F.1,
   v0.5.3) — adds `admin_recovery_codes` and `admin_security_log`.
6. Run `database/migration_phase5d2_order_emails.sql` (Phase 5D Step 2,
   v0.5.4) — adds `order_email_log` (order_id, email_type, recipient,
   subject, status `sent`/`failed`, error_message, created_at) used to
   deduplicate order confirmation / shipped / delivered emails so a
   repeated callback or status form never re-sends. Fresh installs get
   the identical table from `schema.sql`.
7. Run `database/migration_phase5f_gst_tax.sql` (Phase 5F GST, v0.5.5) —
   adds `products.gst_rate` (DECIMAL(5,2) DEFAULT 0.00), order-level tax
   snapshot columns on `orders` (`taxable_value`, `cgst_amount`,
   `sgst_amount`, `igst_amount`, `tax_type`), the same four breakdown
   columns on `order_items`, and the `business_state` setting. Existing
   products keep 0.00% (no tax on historical behaviour) and existing
   orders keep their old values untouched. Mirrored in `schema.sql` +
   `seed.sql`.
8. Run `database/migration_add_concern_categories.sql` (Homepage/
   Frontend UX pass, v0.5.8) — adds the `concern_categories` and
   `product_concerns` tables (a structured product classification,
   deliberately separate from the free-text `products.purpose` field),
   seeded with the 13 approved Concern Category presets. No ordering
   dependency on any of the migrations above. Mirrored in `schema.sql`
   + `seed.sql`.
9. Run `database/migration_add_invoice_designer_settings.sql` (Phase 6,
    v0.6.0) — adds the `invoice_designer_settings` table (one JSON-config
    row) that Admin > Settings > Invoice Designer reads/writes. No
    ordering dependency on any of the migrations above. Mirrored in
    `schema.sql` + `seed.sql`.
10. Run `database/migration_add_certificate_included.sql` (v0.6.1) —
    adds `products.certificate_included` TINYINT(1) NOT NULL DEFAULT 1.
    Existing products keep Yes. No ordering dependency on any of the
    migrations above. Mirrored in `schema.sql`.

All migrations are non-destructive and idempotent (guarded `ALTER` +
`CREATE TABLE IF NOT EXISTS`), safe to re-run, and a fresh
`schema.sql` + `seed.sql` install includes the identical structure.
Existing orders keep every value they already have.

## 8a. GST & Tax (Phase 5F)

Product prices are **GST-INCLUSIVE**: the customer pays the sell price
and the GST is **derived out of it**, never added on top. With a 3% rate
a ₹100 product yields ₹97.09 taxable value + ₹2.91 GST, and the grand
total stays ₹100 (₹100 is never turned into ₹103). The tax math lives in
one server-side file, `includes/tax-functions.php`, and is snapshotted
into `orders` / `order_items` at order creation, so historical orders
never change if a product's rate is edited later.

To go live with GST:

1. Run migration 7 above (or use a fresh `schema.sql` + `seed.sql`).
2. Set the **seller's registered state** — Admin > Settings > Business /
   GST > Business State (e.g. `Karnataka`). Free-text, optional; it is
   compared (case-insensitively, trimmed) with the customer's shipping
   state at checkout:
   - Same state → **intra-state**: GST splits into CGST + SGST (half
     each, with the second half derived so `cgst + sgst == gst` exactly).
   - Different state → **inter-state**: GST becomes IGST.
   - While `business_state` is unset, orders store `tax_type = NULL` and
     split conservatively as CGST + SGST so the money always reconciles.
   - Limitation: the checkout state is free text today, so resolution is
     best-effort normalized matching; a future state dropdown (billing/
     shipping split) would make it exact.
3. Set each **product's GST rate** — Admin > Products > Add/Edit > GST
   Rate (%). Validated server-side: 0-100, up to 2 decimal places,
   negatives/non-numeric/>100/>2-decimal rejected. Existing products
   default to 0.00%.
4. Done — order creation derives and snapshots per-line + order-level
   taxable value, GST, CGST/SGST/IGST and the tax type. The invoice PDF
   renders the full GST breakdown (per-line GST Rate column + Taxable
   Value / CGST / SGST / IGST rows, Phase 5G, further customizable via
   the Invoice Designer - § 8b below); the cart and checkout summaries
   also show a real, derived "GST (Included)" line (Part 2).
5. Set your **seller business details** for a legally complete GST
   invoice — Admin > Settings > Business / GST: Business Name
   (required), Trade Name, **GSTIN** (15-char format validated,
   e.g. `29ABCDE1234F1Z5`), Business Address, Phone, Email and Website
   (`https://` added automatically). These print on every invoice's
   letterhead; until `business_gstin` is set, invoices show "GSTIN:
   Not configured" and are not legally complete GST documents.

## 8b. Invoice Designer (Phase 6)

Admin > Settings > Invoice Designer controls how `build_invoice_pdf()`
(`includes/invoice-functions.php`) lays out the invoice PDF - logo,
watermark, branding text, header title, which order/payment/address
fields show and how they're aligned, product-table column widths, tax-
summary line visibility, footer text - all without editing code.

- **Storage**: one JSON-config row in `invoice_designer_settings`
  (migration 9 above). `includes/invoice-designer-functions.php`
  provides `get_invoice_designer_settings()` /
  `save_invoice_designer_settings()` / `reset_invoice_designer_settings()`;
  a fresh/uncustomized install renders the exact same invoice layout
  as before this phase - the defaults were hand-matched to the
  pre-Phase-6 hardcoded values and verified byte-for-byte identical.
- **Logo / watermark**: upload, replace, or remove either from the
  Invoice Designer page (stored under `assets/uploads/invoice-logo/`
  and `assets/uploads/invoice-watermark/`); leaving either unset falls
  back to the site's existing logo files, exactly as before this phase.
  Watermark position (5 options), rotation, scale, and opacity are all
  configurable.
- **Live preview**: the Invoice Designer page shows a real PDF preview
  (`dashboard/invoice-designer-preview.php`) rendered from fixed sample
  order data with whatever settings are currently saved - it reloads
  automatically after every Save.
- **Layout persistence (latest v0.6.0 maintenance):** drag-and-drop section/line coordinates are stored in the existing `invoice_designer_settings` JSON layout. The `invoice_layout_json` hidden field is explicitly associated with the main `invoiceDesignerForm`, so the current layout JSON is included in the existing POST save cycle and restored on subsequent reloads. No additional table/column or migration is required.
- **Reset**: "Restore Default Invoice Layout" (with a confirmation
  prompt) resets every setting back to the values above.
- **PDF permission restriction** (view/print allowed, editing blocked) —
  researched and **not implemented**. `includes/lib/SimplePdfWriter.php`
  has no `/Encrypt` support; doing this correctly means implementing
  the PDF Standard Security Handler's own encryption algorithm, which
  was judged too risky to hand-roll safely. No customer-facing password
  was added either (that was never in scope). See `PROJECT_STATUS.md`'s
  Pending section.
- **Untouched by this phase**: GST calculation (`tax-functions.php`),
  order creation (`create_order()`), and the order snapshot tables
  (`orders`/`order_items`/`order_addresses`) - the Invoice Designer only
  controls how an already-computed, already-stored order is drawn on
  the page.

## 9. Admin Two-Factor Authentication (Phase 5E)

Admin logins support **TOTP two-factor authentication** (RFC 6238,
compatible with Google Authenticator, Microsoft Authenticator and
Authy). Each admin manages 2FA on **Admin → Security**
(`dashboard/2fa-setup.php`): they scan a QR code (or type the Base32 key),
then 2FA only turns on after they enter a valid 6-digit code. Every
admin login with 2FA enabled then requires a fresh code
(`dashboard/2fa-verify.php`).

**Required environment variable** (without it 2FA refuses to operate
and stores nothing — fail closed):

```
ADMIN_2FA_ENCRYPTION_KEY=<base64 of exactly 32 random bytes>
```

Generate one with:

```
php -r "echo base64_encode(random_bytes(32));"
```

Add it to your `.env` / server environment. Never commit the key.
Details:
- The TOTP secret is stored **encrypted** (AES-256-GCM keyed by
  `ADMIN_2FA_ENCRYPTION_KEY`); OTP codes are never stored anywhere and
  no recovery codes are written (disabling 2FA requires a valid code).
- Existing admins are 2FA-off by default; enable it from the Security
  page.
- Session hardening shipped with this phase: 30-minute idle timeout
  (`ADMIN_SESSION_IDLE_TIMEOUT`), User-Agent session binding, security
  headers (nosniff / SAMEORIGIN / Referrer-Policy), and logout is now
  POST + CSRF only.

## 10. Newsletter Unsubscribe Secret

Campaign send, test, schedule, and public unsubscribe HMAC tokens require a dedicated secret. Without it they fail closed.

```
NEWSLETTER_UNSUB_SECRET=<hex of 32 random bytes, 16+ characters>
```

Generate one with:

```
php -r "echo bin2hex(random_bytes(32));"
```

Do not reuse `INVOICE_TOKEN_SECRET`, `ADMIN_2FA_ENCRYPTION_KEY`, or derive a key from `SITE_URL`. Links signed with those previous fallbacks will no longer verify; subscribers need a new campaign email or support help. `SITE_URL` and campaign CTA URLs must be public `https` origins. Localhost, loopback, private, link-local, `.internal`, and URLs with embedded credentials are rejected. Unsubscribe URLs and `List-Unsubscribe` headers are not built from an insecure fallback. Local drafts can still be saved without a public origin; send/test/schedule stay blocked until `SITE_URL` is a public https origin.
