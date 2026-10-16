# MoonAura Crystals — Deployment Checklist

> v0.6.7. Applies to the current handoff state: Phases 1-5F.1 (incl.
> admin TOTP 2FA + recovery codes), Phase 5D Step 2 (order transactional
> emails), **Phase 5F GST & Tax** (product-wise GST rates, GST-inclusive
> pricing, order tax snapshots), **Phase 5G — COMPLETE** (GST-compliant
> invoice rendering + invoice branding/settings), the **v0.5.8
> Homepage/Frontend UX pass**, the **v0.5.9 Concern UI Cleanup**, **Parts
> 1-3** (UI & Responsive Fixes, Checkout/Buy Now/Saved Address/GST
> Display, Order Confirmation & My Account UX), and **Phase 6 — the
> Advanced Invoice Designer System** (admin-configurable invoice layout;
> see `CHANGELOG.md` § v0.6.0). Follow the ordering below to avoid the
> `two_factor_enabled` / missing-column and encryption-key issues
> resolved earlier in this project. The migration order below is the
> canonical, live-verified order (finalized in v0.5.7, extended with
> migration 10 in v0.5.8 and migration 11 in v0.6.0).

---

## 1. Required Migrations

Run in this order against the target database (all non-destructive and
idempotent; safe to re-run; a fresh install can instead load
`database/schema.sql` + `database/seed.sql` which include everything):

1. `database/migration_phase4c_multi_gateway_checkout.sql` — multi-gateway flags
2. `database/migration_phase4d_manual_upi_qr.sql` — manual UPI QR payment
3. `database/migration_phase5_order_tracking.sql` — tracking fields + order_status_history
4. `database/migration_phase6_stock_login.sql` — stock_deducted_at + login_attempts
5. `database/migration_phase5b_customer_password_reset.sql` — customer_password_resets
6. `database/migration_phase5e_admin_2fa.sql` — admin 2FA columns (**required before enabling 2FA**)
7. `database/migration_phase5f1_recovery_codes.sql` — admin_recovery_codes + admin_security_log
8. `database/migration_phase5d2_order_emails.sql` — `order_email_log` (Phase 5D Step 2 dedup table)
9. `database/migration_phase5f_gst_tax.sql` — `products.gst_rate`,
   order/order-item tax snapshot columns (`taxable_value`,
   `cgst/sgst/igst_amount`, `orders.tax_type`) and the `business_state`
   setting (Phase 5F GST)
10. `database/migration_add_concern_categories.sql` — `concern_categories`
    + `product_concerns` tables, seeded with the 13 approved Concern
    Category presets (Homepage/Frontend UX pass, v0.5.8). No ordering
    dependency on any other migration in this list.
11. `database/migration_add_invoice_designer_settings.sql` —
     `invoice_designer_settings` single-row JSON config table for the
     Invoice Designer admin panel (Phase 6). No ordering dependency on
     any other migration in this list.
12. `database/migration_add_certificate_included.sql` —
      `products.certificate_included` TINYINT(1) NOT NULL DEFAULT 1
      (Certificate Included Yes/No). No ordering dependency on any
      other migration in this list.
13. `database/migration_newsletter_admin.sql` — Admin newsletter
      subscriber cache + campaign drafts (Phase 1/2). Apply after
      existing-database upgrades that predate these tables.
14. `database/migration_newsletter_campaign_sending.sql` — campaign
      send/schedule columns plus `newsletter_campaign_sends` and
      `newsletter_campaign_recipients` (Phase 3). Apply after 13.
      Repeat-safe via `information_schema` column/index guards.
      Preflight: `SHOW COLUMNS FROM newsletter_campaigns LIKE 'locked_at'`
      and `SHOW TABLES LIKE 'newsletter_campaign_recipients'`.
      Execution in this workspace: **No — not executed**.
15. `database/migration_newsletter_recipient_claims.sql` — recipient
      `claimed`/`unknown` statuses plus `claimed_at`/`claim_token`
      (campaign worker exclusive claim). Apply after 14.
      Repeat-safe via `information_schema` column/index guards.
      Preflight: `SHOW COLUMNS FROM newsletter_campaign_recipients LIKE 'claim_token'`.
      Execution in this workspace: **No — not executed**.

Phase 4 campaign analytics added **no** new SQL. Fresh installs get
these tables from `database/schema.sql`. Execution of 13–15 in this
workspace: Unknown (not run here).

> **Warning:** migration 6 must be applied before 2FA is enabled, or the
> admin Security page fails with a missing `two_factor_enabled` error.

> **Hard ordering dependency:** migration 3 must run before migration 4
> — migration 4 adds `orders.stock_deducted_at` positioned
> `AFTER tracking_url`, a column migration 3 creates. Every other pair
> in this list is order-independent (each only adds its own
> table/columns or writes to the generic `settings` table), so 1-2 and
> 5-10 can move freely as long as 3 stays before 4. This order is the
> canonical one (`PROJECT_STATUS.md` and `SETUP.md` are kept in sync
> with it) and has been live-verified: fresh install (`schema.sql` +
> `seed.sql`) and an existing-database upgrade through this exact
> sequence produce byte-identical table structures (columns, indexes,
> foreign keys, engine, charset), all 10 migrations re-run cleanly a
> second time with no duplicate columns/tables/rows, and pre-existing
> orders/products/customers/admin data survive untouched. See the
> CHANGELOG "Migration order documentation audit" (v0.5.7) and
> "Homepage & Product Discovery UX" (v0.5.8) entries for the full
> verification writeup.

## Latest v0.6.7 Note

- No database migration is required for v0.6.7.
- Set `GA4_MEASUREMENT_ID`, `GA4_PROPERTY_ID`, and `GA4_CREDENTIALS_PATH` in `.env`. Do not commit `.env` or the service-account JSON.
- Admin Visitors card uses GA4 Data API Current = realtime `activeUsers` and Total = calendar-month `totalUsers` when the credentials file is readable.

### Invoice Designer Persistence Smoke Test

- [ ] Open **Admin > Settings > Invoice Designer** on staging.
- [ ] Move at least one whole section and one individual line.
- [ ] Click **Save Invoice Layout**.
- [ ] Reload the page and confirm both positions remain where they were saved.
- [ ] Download a real invoice and confirm the saved layout is still used by the PDF renderer.

## 2. Required .env Variables

| Variable | Required | Notes |
|---|---|---|
| `DB_HOST` | yes | Database host |
| `DB_NAME` | yes | Database name |
| `DB_USER` | yes | Database user |
| `DB_PASS` | yes | Database password |
| `SITE_URL` | yes | Absolute storefront URL (emails, links, public assets) |
| `ADMIN_URL` | no | Admin host; defaults to `SITE_URL/dashboard`. Live: `https://admin.moonauracrystals.in` |
| `ADMIN_2FA_ENCRYPTION_KEY` | yes (for 2FA) | `php -r "echo base64_encode(random_bytes(32));"` — must be exactly 32 bytes when base64-decoded. 2FA **fails closed** without it |
| `BREVO_SMTP_HOST` | yes (for email) | e.g. `smtp-relay.brevo.com` |
| `BREVO_SMTP_PORT` | yes (for email) | 587 (STARTTLS) or 465 (implicit TLS) |
| `BREVO_SMTP_USERNAME` | yes (for email) | Brevo SMTP login |
| `BREVO_SMTP_PASSWORD` | yes (for email) | Brevo SMTP key |
| `BREVO_SMTP_SECURE` | no | `auto` (default) / `tls` / `ssl` / `none` |
| `MAIL_FROM_ADDRESS` | yes (for email) | e.g. `no-reply@yourdomain.example` |
| `MAIL_FROM_NAME` | no | defaults to `SITE_NAME` |
| `MAIL_REPLY_TO_ADDRESS` | no | Reply-To for order/reset emails; defaults to `support@moonauracrystals.in`. Also used as the `List-Unsubscribe` mailto address when valid |
| `NEWSLETTER_UNSUB_SECRET` | yes (for newsletter campaigns) | Dedicated HMAC secret, 16+ characters. `php -r "echo bin2hex(random_bytes(32));"`. Campaign send/test/schedule fail closed without it. Do not reuse invoice/2FA secrets |
| Invoice settings | yes | Per `SETUP.md` (invoice number format, GST, store details) |
| Payment gateway keys | yes | Razorpay credentials + webhook setup (see `SETUP.md`). COD is active. Cashfree/PhonePe are not in use. |

## 3. Pre-Launch Checklist

- [ ] Migrations 1-12 applied; verify with `SHOW TABLES LIKE 'admin_%'`
  (expect `admin_users`, `admin_password_resets`,
  `admin_recovery_codes`, `admin_security_log`),
  `SHOW COLUMNS FROM admin_users` (expect `two_factor_enabled`,
  `two_factor_secret`, `two_factor_enabled_at`) and
  `SHOW TABLES LIKE 'order_email_log'`
- [ ] Migration 9 applied: `SHOW COLUMNS FROM products` (expect
  `gst_rate`), `SHOW COLUMNS FROM orders` (expect `taxable_value`,
  `cgst_amount`, `sgst_amount`, `igst_amount`, `tax_type`) and
  `SELECT * FROM settings WHERE setting_key = 'business_state'`
- [ ] Migration 10 applied: `SHOW TABLES LIKE 'concern_categories'` and
  `SHOW TABLES LIKE 'product_concerns'`; `SELECT COUNT(*) FROM
  concern_categories` should return 13
- [ ] Migration 11 applied: `SHOW TABLES LIKE 'invoice_designer_settings'`;
  `SELECT COUNT(*) FROM invoice_designer_settings` should return 1
- [ ] Migration 12 applied: `SHOW COLUMNS FROM products LIKE 'certificate_included'`
- [ ] `SITE_URL` is the public storefront host; public assets load from
  `{SITE_URL}/assets/...` even on admin/account pages. `ADMIN_URL` is
  the admin host. Admin-local CSS (`dashboard/assets/css/admin.css`) stays
  on the admin host.
- [ ] Set the seller's registered state via **Admin > Settings >
  Business/GST** (`business_state`) so orders resolve intra-state
  (CGST+SGST) vs inter-state (IGST); while unset orders store
  `tax_type = NULL` and split GST conservatively as CGST+SGST
- [ ] Set per-product GST rates via **Admin > Products > Add/Edit** (GST
  Rate %, 0-100, 2 decimals). Prices are **GST-INCLUSIVE**: the GST is
  derived out of the sell price, never added on top
- [ ] Place a COD test order with a GST product; confirm the order's
  `gst_amount`/`taxable_value`/`cgst`+`sgst` (or `igst`) snapshot and
  that the grand total still equals the subtotal the customer paid
- [ ] Set the seller's invoice business details via **Admin > Settings >
  Business/GST**: Business Name (required), Trade Name, **GSTIN**
  (15-char format validated, e.g. `29ABCDE1234F1Z5`), Address, Phone,
  Email, Website. These print on every invoice letterhead (Phase 5G);
  until `business_gstin` is set, invoices show "GSTIN: Not configured"
- [ ] Download a test invoice from **Admin > Orders > View Invoice >
  Download** (`dashboard/invoice.php?mode=download`) and open it in a PDF
  reader; confirm the per-line GST Rate column, Taxable Value and
  CGST/SGST/IGST rows match the order's stored snapshot
- [ ] `.env` contains a valid `ADMIN_2FA_ENCRYPTION_KEY` (32-byte base64) — **not** the dev value used locally
- [ ] `.env` contains `NEWSLETTER_UNSUB_SECRET` (16+ characters, dedicated HMAC secret). Campaign send/test/schedule fail closed without it. Existing unsubscribe links signed with the old SITE_URL / invoice / 2FA fallbacks will not verify.
- [ ] Brevo SMTP credentials configured; send a test password-reset email to confirm delivery
- [ ] Confirm `MAIL_REPLY_TO_ADDRESS` (default `support@moonauracrystals.in`) resolves to a monitored inbox — it is the Reply-To on all order emails
- [ ] Place a COD test order and a gateway test order; confirm the confirmation email arrives exactly once per order
- [ ] Flip a test order to `shipped` then `delivered` in the admin; confirm one shipped and one delivered email
- [ ] Razorpay in live mode (production) with the correct webhook URL (signed webhooks drive confirmation emails). COD remains available.
- [ ] `SITE_URL` matches the production storefront domain (emails, absolute links, public assets)
- [ ] `ADMIN_URL` matches the production admin host if split from the storefront
- [ ] Confirm the admin Security page (`/dashboard/2fa-setup.php`) loads with no error after enabling 2FA
- [ ] Enable 2FA for at least the primary admin, generate recovery codes, download and store them offline
- [ ] Verify an admin login with 2FA: wrong code rejected, correct code works, recovery code works
- [ ] PHP version + extensions: PHP 8.x, PDO MySQL, OpenSSL (AES-256-GCM), mbstring
- [ ] File permissions: config/ and includes/ not writable by the web user; uploads dir as needed
- [ ] Production PHP settings: error display off, `session.cookie_httponly`, HTTPS enforced
- [ ] Run `php -l` sweep over the codebase
- [ ] Back up the database before first production run

## 4. Post-Launch Checklist

- [ ] Monitor `admin_security_log` for `recovery_code_used` /
  `recovery_code_reuse_attempt` / `recovery_codes_regenerated` events
- [ ] Confirm password-reset emails arrive (spam test with the store inbox)
- [ ] Monitor `order_email_log` for `failed` rows; an SMTP outage never
  blocks orders, but `failed` rows should be retried (the next
  status/payment event or a resubmit re-sends exactly once)
- [ ] Spot-check admin login from an incognito browser (idle timeout + 2FA)
- [ ] Verify lockout: 5 bad passwords/OTPs → 15-minute lock for admin and customer
- [ ] Keep `ADMIN_2FA_ENCRYPTION_KEY` stable and backed up (rotation locks out 2FA secrets)
- [ ] Rotate/regenerate recovery codes if a set is ever suspected of compromise
- [ ] Monitor PHP error logs for SMTP failures (`error_log` diagnostics from `includes/mailer.php`)
- [ ] Schedule the remaining roadmap phases (5H)
