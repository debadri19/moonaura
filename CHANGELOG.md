# MoonAura Crystals — Changelog

Entries are grouped by project phase, most recent first. Each entry
lists what changed at that milestone, not a running diff - see git
history (if this project is under version control on your end) for
line-level detail.

**Versioning convention:** releases are tagged `v0.x.x` (current product
version: **v0.6.7**). This documentation refresh does not bump the
version. Historical Phase 5A–5H labels and migration filenames in
older entries below are unchanged.

Admin PHP paths in older entries may say `admin/`; the live path is
`dashboard/`.

---

## Current Status

**Workspace (unreleased on top of v0.6.7):** storefront UI refinements
are in the working tree (CSS + `index.php` + `support.php`). They are
**implementation complete, visual QA pending.** See the unreleased
entry below.

**Latest tagged product checkpoint remains v0.6.7:** GA4 env mapping,
dynamic sitemap, storefront newsletter (Brevo Contacts), Razorpay as
the only registered online gateway, Invoice Designer, GST snapshots.

**Pending (verified against current source):**
- Newsletter input + Subscribe button size/alignment
- Admin button style consistency / admin UI design audit
- Phase 5H POS / walk-in sales (not started; storefront now states online-only)
- Newsletter campaign QA (browser, Brevo events, live DB, live sends)
- Full Site Visual QA; Meta Pixel Events Manager QA; live deployment finalization; production audit

**No longer pending (implemented in current CSS/PHP):** product CTA
sizing, Home Hero padding, shop toolbar padding, Policy 2-column
mobile shortcuts, zodiac stroke SVGs, Dark Mode scrollbar, Account and
Home Dark Mode heading/link contrast, Policy Hero type, About mobile
Promise 2×3 grid, Support online-only note.

---

## [Unreleased] — Admin newsletter Phases 1–4 + storefront UI refinements

**Scope:** Admin newsletter audience, composer, sending, and campaign
history/analytics on the existing Brevo Contacts + SMTP stack. No
product version bump. Visual QA and live campaign testing pending.

### Newsletter Phase 4 — Campaign history & analytics

- Campaign analytics overview on `dashboard/newsletter.php`: totals for
  campaigns, draft/scheduled/sent/failed, unique recipients targeted,
  accepted SMTP sends, and failed recipient sends.
- Date range filter uses `newsletter_campaigns.created_at`.
- Campaign history search/status/sort/pagination. Send log is paginated.
- Composer shows accepted/failed counts, grouped failure reasons (no
  recipient email list), and per-campaign send log.
- Opens, clicks, deliveries, and bounces display as **Tracking not
  configured**. No Brevo event webhook or engagement table exists.
- Test emails are counted separately and excluded from recipient totals.
- **No new SQL migration.** Reuses Phase 1–3 tables.

### Newsletter remaining-gap hardening (unreleased)

- Public unsubscribe HMAC uses dedicated `NEWSLETTER_UNSUB_SECRET` only.
  Missing/short secrets fail closed. Invoice/2FA/`SITE_URL` fallbacks removed.
  Previously signed links will not verify after this change.
- Campaign send/test/schedule require a public `https` `SITE_URL` so
  unsubscribe URLs are never built from localhost.
- Campaign HTML includes `List-Unsubscribe` (https + mailto). One-click
  `List-Unsubscribe-Post` is not added because the public endpoint still
  requires an interactive CSRF confirmation.
- Sync: contacts on pending/confirmed lists still use live Brevo status
  (including `emailBlacklisted`). Cached rows missing from both lists are
  stored as unsubscribed. Send-time eligibility re-checks Brevo and skips
  the recipient when the API fails.
- Saving a failed/cancelled campaign no longer resets status to draft.
- Phase 3 SQL uses `information_schema` guards. **No — not executed**.
- `.gitignore` conflict markers removed.
- Campaign HTML sanitizer uses DOMDocument allowlists; event handlers,
  `style`, and non-https image/link URLs are dropped.
- CTA and `SITE_URL` require public https (no localhost, loopback,
  private, link-local, `.internal`, or embedded credentials).
- Workers claim each recipient atomically (`claimed` + `claim_token`)
  before SMTP. Stale claims become `unknown` and are not auto-retried.
  Migration: `database/migration_newsletter_recipient_claims.sql`.
  **No — not executed**.

### Newsletter Phases 1–3 (already in working tree)

- Phase 1: subscriber cache, search/filter/CSV, add (DOI), unsubscribe.
- Phase 2: local campaign drafts and composer.
- Phase 3: Send Test / Send Now / Schedule, signed public unsubscribe,
  `scripts/send-newsletter-campaigns.php`.

**SQL (manual, not executed here):**
- `database/migration_newsletter_admin.sql`
- `database/migration_newsletter_campaign_sending.sql`
- `database/migration_newsletter_recipient_claims.sql`
- Fresh installs: `database/schema.sql` already includes these tables.

---

## [Unreleased] — Storefront UI refinements + documentation refresh

**Scope:** unpublished working-tree UI on `master` after merge `4e037bb`
(GitHub `debadri19/moonaura-v6` `main`). No product version bump. No
schema change. Visual QA pending.

- Compact rectangular product CTAs: `.product-btn` / `.cart-btn` at 40px height, 16px radius (not pills).
- Circular icon wells on Policy, About Promise, and mobile nav icons.
- Desktop active nav: short gold bar under the item. Mobile: left gold inset bar unchanged in role.
- Home Hero padding aligned with Shop Hero; shop toolbar vertical padding equalized.
- Policy mobile shortcuts: 2-column grid. Policy Hero heading reduced; `MOONAURA POLICIES` eyebrow `white-space: nowrap`.
- Homepage zodiac glyphs: Lucide-style stroke SVGs in `HOMEPAGE_ZODIAC_SIGNS`.
- Dark Mode: scrollbar thumbs, `--color-heading` / `--color-link`, `.btn-outline` via `--color-outline`, raised icon wells.
- About mobile: Our Promise `2 × 3` grid; section spacing tightened at mobile breakpoints only.
- Support Address card: “Online Store Only • No Walk-in Store” under the existing address.
- Documentation rewritten to match current source (`README.md`, `PROJECT_STATE.md`, `PROJECT_STATUS.md`, `SETUP.md`, `docs/*`, recovery/AI instruction files).

**No database / migration changes.**

---

## Historical Current Status (v0.6.7 and earlier)

**Resolved issues (this checkpoint):**
- **Phase 5G - GST-compliant invoice system & invoice branding shipped** -
  the Phase 5F GST snapshot is now rendered as a proper tax invoice:
  per-line GST Rate column, Taxable Value + CGST/SGST/IGST breakdown
  (never recalculated, rate labels only on single-rate orders), PDF
  /Info metadata (deterministic, so byte-identical regeneration
  holds), "A Brand by DS Lifestyle" letterhead tagline + optional
  business_website line, a Business / GST section on
  `admin/settings.php` (GSTIN-format + URL validation), and an admin
  invoice `?mode=download` attachment flow. Full detail in the v0.5.6
  entry below. Owner-password / read-only PDF encryption is NOT
  faked - documented as a limitation with alternatives.
- **Phase 5F - GST & Tax architecture shipped** - product-wise GST
  rates with GST-INCLUSIVE pricing: `products.gst_rate`, a server-side
  tax layer (`includes/tax-functions.php`), order + order-item tax
  snapshots (`taxable_value`, `cgst/sgst/igst_amount`, `tax_type`) taken
  at order creation so historical orders never change, intra-state
  (CGST+SGST) vs inter-state (IGST) resolution from the shipping state
  vs the new `business_state` setting, and admin management of product
  GST rates plus the seller's business state. GST is **derived out of**
  the inclusive price, never added on top. Full detail in the v0.5.5
  entry below.
- **`checkout.php`/`create_order()` tax gap** - GST was hardcoded to 0
  at order creation. Now every order derives + snapshots per-line and
  order-level GST from each product's own (DB-authoritative) rate with
  the correct reverse inclusive-tax formula; the checkout UI and every
  payment total are unchanged because `grand_total` was always
  GST-inclusive-by-construction.

**Latest v0.6.7 update:**
- **GA4 env configuration** - gitignored `.env` now sets Measurement ID, Property ID, and credentials path. Service-account JSON stays outside the web root and is gitignored. Existing config/env mapping is reused. Credentials are not hardcoded. Admin Visitors card still uses realtime `activeUsers` and calendar-month `totalUsers` when the credentials file is readable.
- **Dynamic XML sitemap** - `sitemap.php` builds `/sitemap.xml` from the live catalog (active products, active categories, active concern pages, plus public static storefront URLs). Draft/inactive/private URLs are omitted. `robots.txt` advertises the sitemap. No schema change.

**Pending tasks:**
- Admin button style consistency (align admin buttons with the customer
  "Shop Now" pill style)
- Admin UI design audit
- Phase 5H - invoice email attachments / marketing emails (per roadmap)
- Checkout summary GST line items / tax breakdown (the invoice PDF now
  renders the full tax breakdown - Phase 5G v0.5.6 below - but the
  checkout summary itself still shows GST-inclusive totals only)
- Production readiness review (real server verification of all phases)

---

## [v0.6.6] — GA4 server-side environment configuration

**Scope:** complete remaining GA4 Reporting API environment configuration. Existing analytics helpers, Admin Visitors card, storefront gtag, navigation, payment, newsletter, and application logic are unchanged.

- Set `GA4_MEASUREMENT_ID`, `GA4_PROPERTY_ID`, and `GA4_CREDENTIALS_PATH` in gitignored `.env`.
- Reused the existing `config.php` `env()` mapping. Credentials are not hardcoded and the service-account JSON contents are not exposed.
- Service-account JSON remains outside the web root. `moonaura-ga4.json` is excluded from version control.
- Reporting API verification confirmed Measurement ID and Property ID load. Credentials file was not readable in this environment (`GA4 credentials file is not readable`), so visitor values were not faked.

**No database / migration changes.**

---

## [v0.6.5] — Navigation/header cleanup + Account nav chevron state sync

**Scope:** storefront navigation restructure and Account Dashboard / shared Account nav pill-dropdown chevron state fix. Existing routes, pages, search, cart, wishlist, account menu, mobile drawer mechanics, authentication, payment, newsletter, and GA4 are unchanged.

- Desktop and mobile main nav order is Home, Shop, Policy, Help, About Us.
- Header Support renamed to Help with the same button styling, placement, theme, and responsive behavior. Help dropdown: Policies (`policy.php`) and Support (`support.php`).
- Policy remains a dropdown and reuses existing policy page anchors (Order, Cancellation, Shipping, Replacement, Return & Refund, Refund, Privacy, Terms & Conditions, Disclaimer).
- Mobile drawer logo section padding reduced (logo size and alignment unchanged). Mobile nav order matches desktop.
- Subtle gold divider/separator accents in desktop dropdowns and the mobile drawer, using the existing MoonAura gold (`--gold`).
- Account nav chevron rotation is bound only to the native `<details open>` state. Hover and focus no longer rotate the chevron. Click-open, click-close, outside click, and Escape stay in sync.

**No database / migration changes.**

---

## [v0.6.4] — Homepage newsletter subscription (Brevo pending-list double opt-in)

**Scope:** wire the existing homepage newsletter form to the Brevo Contacts API. Signup places the address on pending list 7. Confirmation mail and the move to confirmed list 6 are handled by a Brevo Automation. Transactional SMTP mail (`includes/mailer.php`, order emails, password-reset flows) is unchanged. No local subscriber table.

- Added a dedicated POST endpoint (`newsletter-subscribe.php`) with CSRF, server-side email validation, and generic visitor-facing success/error messages.
- Added Brevo Contacts API subscription (`includes/newsletter-functions.php`) using env-driven `BREVO_API_KEY`, pending list id 7, and confirmed list id 6. Does not call `/contacts/doubleOptinConfirmation` and does not use a DOI template id.
- New contacts and unconfirmed existing contacts are placed on pending list 7. Contacts already on confirmed list 6 are not re-added to pending and see the already-subscribed message.
- Wired the existing homepage `.newsletter-form` (POST, `name="email"`) and added consent text under the form.
- Added a public confirmation landing page (`newsletter-confirmed.php`) using the existing storefront header, footer, and newsletter styles. The page does not change Brevo contacts or local data.
- Added environment/config mappings in `config/config.php`. The API key is never hardcoded.
- Already-subscribed detection is confirmed-list membership. A create-time `duplicate_parameter` is re-checked against list membership rather than shown as already subscribed. Other HTTP 400s are treated as errors and logged with status, `code`, `message`, and list id only.

**No database / migration changes.**

---

## [v0.6.1] — Public asset URLs + Certificate Included (on frozen Razorpay/Shop card base)

**Scope:** only the asset-URL architecture and the product-level Certificate Included flag. Frozen v0.6.1 Razorpay mapping guard, configured-gateway checkout, Shop card actions, Invoice Designer, GST, email, Cashfree, PhonePe, Buy Now, and cart architecture are unchanged.

**1. Canonical public asset URLs.** `SITE_URL` remains the public storefront host. New `ADMIN_URL` (env, default `SITE_URL/admin`) is the admin host. `asset_url()` in `includes/functions.php` always prefixes `SITE_URL` and strips a leading `../` or `/` so admin/account pages still load storefront assets from the public host. `admin_url()` builds admin page links (used by the admin password-reset email). Public `href`/`src` generation in admin, account, and storefront now uses `asset_url()`; admin-local `href="assets/css/admin.css"` and `src="assets/js/qrcode.js"` stay relative to the admin host. PHP filesystem paths (`__DIR__ . '/../assets/...'`) and DB-stored relative paths are not rewritten.

**2. Certificate Included.** Idempotent `database/migration_add_certificate_included.sql` adds `products.certificate_included TINYINT(1) NOT NULL DEFAULT 1` (also in `schema.sql`). Admin Add/Edit Product has a Yes/No select. `product.php` omits the "Certificate of Authenticity Included" badge when the value is 0; GST invoice badge is unchanged. Missing column / default 1 preserves previous always-shown behaviour.

**Files created:** `database/migration_add_certificate_included.sql`, `tests/asset_url.php`, `tests/certificate_included.php`.

---

## [v0.6.1] — Razorpay mapping guard, configured-gateway checkout, Shop card actions

**Scope:** two verified Razorpay/checkout defects plus the Shop product-card action markup. Invoice Designer, GST, email, Cashfree/PhonePe removal, Customer Account, Admin Customers, asset URLs, and Certificate Included are untouched.

**1. Order <-> transaction validation.** `PaymentManager::verifyPayment(string $gatewayOrderId, array $callbackData, int $expectedOrderId)` now requires the local order id already ownership-checked by `payment-verify.php`. A `gateway_order_id` that resolves to a different `transaction.order_id` returns false immediately, logs the mismatch, and does not verify the signature or write payment/order/stock/cart/email state.

**2. Hide unconfigured online gateways.** Checkout no longer offers a gateway based only on `{name}_enabled`. `PaymentManager::getEnabledAndConfiguredGatewayNames()` / `isGatewayConfigured()` reuse each gateway class's existing `isConfigured()`. Admin Settings shows a non-blocking "{Gateway} is enabled but API credentials are incomplete." warning next to Razorpay/Cashfree; saving settings is unchanged.

**3. Shop product-card actions.** `shop.php` only: replace the non-functional Add to Cart icon + Buy Now button with the existing View Product + `fa-cart-plus` Add to Cart pattern. Wishlist and other listing pages are unchanged.

**No database / migration changes.**

---

## [v0.6.0] — Phase 6: Advanced Invoice Designer System

**Scope:** a full admin-configurable invoice layout system - logo,
watermark, branding, header, order/payment/address info, product
table, tax summary, and footer are now controlled from Admin >
Settings > Invoice Designer, with zero code edits required for future
layout changes. GST calculation, order snapshot architecture, payment
gateway logic, and order creation are all completely untouched - this
phase only controls how an already-computed, already-stored order is
laid out on the PDF page.

**1. New settings storage.** `invoice_designer_settings` - a single-row
JSON config table, deliberately separate from the generic `settings`
table (whose `setting_value` column is VARCHAR(255), far too small for
this many structured fields). `includes/invoice-designer-functions.php`
provides `get_invoice_designer_settings()` / `save_invoice_designer_settings()`
/ `reset_invoice_designer_settings()`, with a recursive merge over a
PHP-side defaults array so a partial or empty stored config (or a
future field added later) never produces a missing key. Every default
value was hand-matched to what `build_invoice_pdf()` hardcoded before
this phase.

**2. `build_invoice_pdf()` refactored** (`includes/invoice-functions.php`)
to read from these settings for every section: logo (width/alignment/
custom upload), watermark (opacity/rotation/scale/position/custom
upload), branding (optional company-name override + tagline), header
(title/font size/alignment), order info (visibility + alignment for
order number/date, invoice number/date), payment info (visibility for
method/status/transaction ID/payment date - the latter two newly read
from the existing `payment_transactions` table, never a new column or
invented data), billing/shipping addresses (visibility + alignment
independently), product table (configurable column-width boundaries,
font size, item-name alignment), tax summary (per-row visibility +
alignment for Subtotal/Discount/Shipping/CGST/SGST/IGST/Grand Total,
plus a new optional combined "GST (Included)" line, off by default),
and footer (Thank You text / Footer text / Terms notes / Contact
information, each optional, plus alignment).

**Regression discipline:** a baseline PDF was generated from the
*pre-refactor* code for 4 real orders before any change was made.
After the full refactor, the same 4 orders were regenerated with
Invoice Designer settings left at their defaults and diffed against
the baseline - **byte-for-byte identical** in all 4 cases (no
metadata normalization was even needed). An uncustomized install
therefore renders an unchanged invoice.

**3. `SimplePdfWriter` extended, never modified.** Two new methods
added purely additively - `drawImageRotated()` (a full affine
rotation transform, for the watermark's rotation control) and
`textCentered()` (for center-alignment options) - every existing
method (`drawImage()`, `text()`, `textRightAligned()`, etc.) and every
existing caller is completely untouched.

**4. One real bug caught and fixed during testing:** the first version
of the product-table column-boundary clamping logic could, under
malformed/extreme input (e.g. all three boundaries set far past 100),
cascade past the page's right margin - a test harness built
specifically to probe worst-case input caught this before it shipped;
the ceilings were tightened so the worst possible input now always
resolves to a safe, on-page, strictly-increasing column layout.

**5. Admin UI** - `admin/invoice-designer.php` (reachable from the
sidebar and via a callout on `admin/settings.php`), covering every
section above, with logo/watermark upload-replace-remove (reusing the
existing `save_uploaded_image()` helper - the same one Manual UPI's QR
code upload already uses), a live A4 preview
(`admin/invoice-designer-preview.php`, an iframe rendering a real PDF
from fixed sample order data with whatever settings are currently
saved - updates on every Save via a normal POST-redirect-GET plus a
`Cache-Control: no-store` header on the preview response), and a
"Restore Default Invoice Layout" reset action with a confirmation
dialog.

**6. PDF protection - researched, not implemented.** `SimplePdfWriter`
has no `/Encrypt` dictionary support at all; implementing PDF
permission restriction (view/print allowed, editing blocked, no user
password) correctly requires hand-implementing the PDF Standard
Security Handler's own encryption algorithm (RC4/AES key derivation
from owner/user password hashes, encrypting every string and stream in
the document) - a genuine cryptographic subsystem, not a small
addition, and a subtly wrong implementation could silently produce
corrupted or falsely-"protected" PDFs. Per this phase's explicit
instruction not to hand-roll unsafe encryption, this was **not
implemented** - documented here and in `PROJECT_STATUS.md` as a known
limitation rather than worked around.

**Verification:** full project-wide PHP lint clean. Live-tested via
PHP's built-in server against a seeded MariaDB database: the full
settings form save/reload cycle (every field verified round-tripping
correctly through a real POST), Reset to Defaults (verified restoring
exact default values), logo upload (verified the uploaded file is
used and rendered) and removal (verified the file is deleted from disk
and the site's default logo is used again), and the live preview
endpoint (verified reflecting saved customizations). A dedicated edge-
case pass (19 assertions) covered all 5 watermark positions combined
with rotation, all 3 logo alignments, extreme malformed column-
boundary input, all 3 alignment options on tax-summary rows, and every
combination of billing/shipping visibility - zero crashes, zero
invalid output. Invoice generation re-verified for a real order under
each of COD, Cashfree, Razorpay, and Manual UPI, plus one historical
(pre-GST-era, `payment_method` NULL) order - all produce valid,
substantial PDFs containing that order's own real data.

**Regression:** email system (`order-emails.php`, `mailer.php`,
`email-templates.php`), order creation (`create_order()`), GST
calculation (`tax-functions.php`), and payment gateway internals
(`PaymentManager.php`, `CashfreeGateway.php`, `RazorpayGateway.php`)
all explicitly diffed against the pre-Phase-6 baseline and confirmed
byte-for-byte untouched.

**Files created:** `database/migration_add_invoice_designer_settings.sql`,
`includes/invoice-designer-functions.php`, `admin/invoice-designer.php`,
`admin/invoice-designer-preview.php`.

**Files modified:** `includes/invoice-functions.php`,
`includes/lib/SimplePdfWriter.php`, `admin/settings.php`,
`admin/includes/admin-sidebar.php`, `admin/assets/css/admin.css`,
`database/schema.sql`, `database/seed.sql`.

**Known limitations:** PDF permission restriction is not implemented
(see point 6 above - documented, not worked around). The live preview
requires a full page/iframe reload after Save rather than updating
without any server round-trip, which is the correct tradeoff for a
real PDF-rendering system rather than a JS mockup.

---

## [v0.5.9] — Concern UI Cleanup: Semantic Concern Icons + Legacy Homepage Section Removal

**Scope:** two targeted fixes on top of v0.5.8's Concern Category
system - no new functionality, no redesign, no database changes. No
payment, GST/tax, checkout, order, security, or invoice code was
touched.

**1. Concern icon mapping.** `/concerns.php` previously rendered the
same generic `fa-gem` icon on every one of the 13 seeded Concern
Category cards - there was no per-concern icon logic at all, unlike
the 4 homepage "Shop By Concern" teaser cards, which were individually
hand-coded with their own icons and had no shared, reusable mapping
either page could draw from.

Added `get_concern_icon_class(string $slug): string` to
`includes/concern-functions.php` as the single source of truth (used
by both `concerns.php` and the individual `concern.php` listing page,
which also gained a small icon badge next to its heading for visual
consistency - a light addition, not a redesign). Every icon class was
checked against a real Font Awesome 6 free-tier `solid.css` before use
(the icon set is append-only across 6.x minor releases, so anything
confirmed present in an earlier 6.x is present in this project's
6.7.2 CDN build). One near-miss caught in the process: `fa-sparkles`
is Pro-only and would have rendered a blank icon for "Spiritual
Growth" - used the confirmed-free `fa-wand-magic-sparkles` instead.

| Concern | Icon |
|---|---|
| Love & Relationships | `fa-heart` |
| Career Success | `fa-briefcase` |
| Education & Focus | `fa-graduation-cap` |
| Wealth & Prosperity | `fa-coins` |
| Protection | `fa-shield-halved` |
| Confidence & Courage | `fa-bolt` |
| Peace & Healing | `fa-heart-pulse` |
| Spiritual Growth | `fa-wand-magic-sparkles` |
| Evil Eye Protection | `fa-eye` |
| Money & Prosperity | `fa-sack-dollar` |
| Peace & Calm | `fa-moon` |
| Positive Energy | `fa-sun` |
| Study & Focus | `fa-book` |
| *(fallback - unseeded/future slugs)* | `fa-gem` |

The 4 homepage "Shop By Concern" teaser icons in `index.php` were
deliberately left untouched - verified byte-for-byte unchanged
(`fa-heart`, `fa-briefcase`, `fa-graduation-cap`, `fa-layer-group`).
New CSS: `.concern-icon-inline` in `assets/css/home.css` (8 lines, for
the concern.php heading badge) - inherits the existing `.concern-icon`
responsive rules for free, no new breakpoints needed.

**2. Legacy homepage section removed.** `index.php` was still
unconditionally rendering an old `<section class="career-collection"
id="career-success">` block ("Curated Collection" / "Career Success
Collection" / "No products in this collection yet.", backed by
`get_collection_products('career-success')`) - a pre-Concern-Category
leftover that was never removed when Shop By Concern and the dedicated
`/concern.php?slug=career-success` page shipped in v0.5.8, so the old
and new "Career Success" UI briefly coexisted. Removed the 153-line
section and its now-orphaned data-fetch call from `index.php`.
`get_collection_products()` itself (in `includes/product-functions.php`)
and the `collections`/`collection_products` tables were **not**
touched - only this one now-dead call site.

One thing deliberately left alone: `assets/css/home.css`'s
`.career-collection` CSS class is *also* reused by `product.php`'s
unrelated "Related Products" section (a shared, if confusingly-named,
section-wrapper style) - removing that CSS would have broken a
different, still-active page, so it stays.

**Verification (live-tested against a seeded MariaDB test database):**
- All 13 seeded concerns confirmed showing their correct, distinct
  icon on `/concerns.php` (zero generic-fallback among them); the
  individual concern page's new icon badge confirmed correct
  (`fa-briefcase` on Career Success).
- Homepage: confirmed zero occurrences of "Curated Collection",
  "Career Success Collection", or "No products in this collection
  yet."; Featured Products, Best Selling Products, Shop By Concern,
  and Shop By Zodiac Sign all confirmed still present and rendering.
- The dedicated `/concern.php?slug=career-success` page confirmed
  still fully functional (correct products, correct icon) after the
  homepage section's removal.
- Admin Product Form: re-verified the existing Concern Category
  assignment flow from v0.5.8 with no regressions - an existing
  product's saved concern assignments still load and pre-check
  correctly on the edit form.
- Full project-wide PHP lint: clean. All 14 CSS files: brace-balanced.
- Diffed the full working tree against the v0.5.8 baseline: confirmed
  the *only* changed files are `includes/concern-functions.php`,
  `concerns.php`, `concern.php`, `assets/css/home.css`, and
  `index.php` - no unrelated changes, and the entire `database/`
  directory is byte-for-byte identical to v0.5.8 (zero schema
  changes). Payment, checkout, GST/tax, order, security (2FA/recovery
  codes), and invoice files explicitly diffed and confirmed untouched.

**Files modified:** `includes/concern-functions.php`, `concerns.php`,
`concern.php`, `assets/css/home.css`, `index.php`.

**Not changed:** database/schema, `admin/product-form.php` (its v0.5.8
Concern Category logic needed no changes - verified via regression
test above), any payment/GST/checkout/order/security/invoice code.

---

## [v0.5.8] — Homepage & Product Discovery UX + Concern Category Architecture + Global Favicon Audit

**Scope:** mobile nav cleanup, About Us wording, four new homepage
product-discovery sections, a new structured "Concern Category"
product classification (separate from the existing free-text
`products.purpose` field), admin support for assigning it, and a
project-wide favicon audit. No payment, GST/tax, invoice, order,
auth, 2FA, recovery-code, or email code was touched.

**1. Mobile navigation - duplicate entries removed.** The hamburger
drawer's `.mobile-account-links` block (Wishlist / Cart / My Account)
was removed from `includes/header.php` - those were already reachable
from the always-visible `.header-actions` icons in the mobile header
(confirmed via `assets/css/header.css`: `.header-actions` has no
`display:none` at any breakpoint, so the drawer copy was a true
duplicate, not a fallback for a hidden desktop-only element as an old
code comment had claimed). The drawer now shows only Home / Shop /
About Us / Support (+ its existing submenu), per spec. The now-dead
`.mobile-account-links` CSS was removed from `header.css` too. Desktop
nav, drawer open/close behavior, and all links/functionality are
unchanged.

**2. About Us - "OUR PROMISE" wording.** Two exact string replacements
in `about.php`, scoped only to those two promise cards: "Secure
Protective Packaging" -> "Secure Packaging"; "Friendly Customer
Support" -> "Dedicated Customer Care". Icons, card structure, spacing,
and the rest of the page are untouched.

**3. Concern Category - new structured classification (separate from
Purpose).** `products.purpose` remains exactly what it was
(free-text product-benefit copy, still shown on the product detail
page via `format_purpose_bullets()` - untouched). A new, SEPARATE
system was added for "Shop By Concern" / `/concerns` pages:

- New tables `concern_categories` (the 13 approved presets - fixed
  vocabulary, not admin-creatable) and `product_concerns` (many-to-
  many pivot), modeled directly on the existing
  `collections`/`collection_products` pair. Added to `schema.sql`
  (fresh installs) and `seed.sql` (the 13 presets), plus a standalone
  idempotent `database/migration_add_concern_categories.sql` for
  existing databases.
- New `includes/concern-functions.php`: `get_active_concern_categories()`,
  `get_concern_categories_with_counts()`, `get_concern_category_by_slug()`,
  `get_products_by_concern()`, `get_product_concern_ids()`,
  `save_product_concerns()`.
- New pages `concerns.php` (full listing, all 13 with product counts,
  empty state if none) and `concern.php?slug=...` (individual concern
  product listing - reuses the exact shop.php/wishlist.php product-card
  markup: wishlist toggle, Add to Cart, pricing; polished empty state
  if a concern has no products yet, never a blank page). No clean-URL
  rewriting exists anywhere in this project (no `.htaccess`/router), so
  these follow the project's actual existing convention
  (`product.php?slug=...`) rather than inventing `/concerns/<slug>`
  routes the server has no way to serve.
- Admin: `admin/product-form.php` gained a "Concern Category" checkbox
  grid (Classification section, right after Crystal Origin) - multiple
  selection, loads existing assignments in edit mode, redisplays the
  admin's actual submitted selection on a validation error (same
  pattern as every other field on this form), and saves via
  `save_product_concerns()` after both the create and update paths
  (full replace-the-set sync, not an append). New `.admin-checkbox-grid`
  CSS in `admin/assets/css/admin.css`.
- shop.php's existing "concern" dropdown filter (a *different*, older
  feature that loosely LIKE-matches `products.purpose` against ~5 fixed
  values) was deliberately left untouched - it is not the same system
  and this work does not repurpose or merge into it.

**4. Homepage - four new product-discovery sections**, inserted in
`index.php` between the existing hero and the existing "Career Success
Collection" section (hero and that section both untouched):

- **Featured Products** - reuses the existing, already
  admin-toggleable `products.featured` flag (no new curation
  mechanism needed).
- **Best Selling Products** - new `get_best_selling_products()` in
  `includes/product-functions.php`, ranked from REAL sales data
  (`order_items` summed per product across orders where
  `order_status != 'cancelled'` and `payment_status IN ('pending',
  'paid')` - i.e. real orders, including legitimate COD-pending ones,
  excluding cancelled/failed/refunded). Falls back to the existing
  `products.is_bestseller` admin flag only to pad out remaining slots
  when real sales data doesn't fill the section (a brand-new store has
  no order history) - never overrides real rankings, never fabricates
  a number or a label. Returns an empty array (honest empty state) if
  neither source has anything.
- **Shop By Concern** - 4 cards (Love & Relationships, Career Success,
  Education & Focus, View All Concerns) linking to `concern.php?slug=...`
  / `concerns.php`.
- **Shop By Zodiac Sign** - 12 sign cards, reusing the *existing*
  `products.zodiac` / `shop.php?zodiac=...` filter as-is (no second
  zodiac system, no new page). Rendered with Unicode zodiac glyphs
  (♈♉♊...) rather than a Font Awesome icon font: this project loads
  Font Awesome 6.7.2 via CDN, and FA's zodiac glyphs were only added in
  7.2 - using `fa-aries` etc. here would have rendered broken/missing
  icons.
- All four sections reuse the existing `.product-card`/`.product-grid`
  architecture as-is - `assets/css/home.css` already had full,
  previously-unused CSS support for the richer wishlist-enabled card
  variant (a prior comment in that file even flagged it as added for
  reuse), so no new product-card CSS was needed. New CSS added:
  section wrappers (`.home-products`, `.home-products-alt`,
  `.shop-by-concern`, `.shop-by-zodiac`) and the two new card types
  (`.concern-card`, `.zodiac-card`), with responsive rules at the
  project's standard 992/768/576 breakpoints.

**5. Global favicon audit.** No new favicon artwork was created - the
existing `assets/images/icons/favicon/favicon.webp` and
`apple-touch-icon.webp` were simply wired up consistently. This project
has no shared `<head>` partial (every page has its own full
`<!DOCTYPE html>...<head>` block), so favicon presence had drifted:
`index.php`, `about.php`, `wishlist.php`, and `policy.php` already had
it; every other rendered page did not, including `shop.php`,
`product.php` (both of its two head blocks - the 404 branch and the
main branch), `cart.php`, `checkout.php`, `support.php`,
`manual-upi-payment.php`, `order-success.php`, `payment-failure.php`,
`payment.php`, all 11 rendered `account/*.php` pages, and all 15
rendered `admin/*.php` pages. All were fixed, using the correct
relative path per directory depth (`assets/...` at the project root,
`../assets/...` from `account/` and `admin/`, since `assets/images/
icons/favicon/` only exists once, at the project root). Verified live
via HTTP that the icon actually resolves from every depth. Pure
redirect/API/PDF-output endpoints (e.g. `cart-add.php`,
`wishlist-toggle.php`, `guest-invoice.php`, `admin/invoice.php`,
`payment-verify.php`, `admin/logout.php`, etc.) have no `<head>` at
all and are correctly out of scope - a favicon `<link>` has nowhere to
go in a file that never renders an HTML document.

**Verification:** PHP-linted every changed/new PHP file (all pass).
Live-rendered via PHP's built-in server against a seeded MariaDB test
database: homepage (all 4 new sections present and correct, including
zero-data and fallback-data cases for Best Selling), `concerns.php`,
`concern.php` (populated / empty / invalid-slug cases), and the full
admin product-form Concern Category flow end-to-end - create with 2
concerns assigned (verified in the database), edit form correctly
pre-checks exactly those 2, change to a different single concern and
save (verified old assignments removed, new one persisted, via direct
database query), and a validation-error submission (missing required
Product Name) correctly redisplays the form with the admin's concern
checkbox selection preserved and creates nothing in the database.
Favicon resolution verified live via HTTP from root, `account/`, and
`admin/` paths.

**Files created:** `database/migration_add_concern_categories.sql`,
`includes/concern-functions.php`, `concerns.php`, `concern.php`.

**Files modified:** `database/schema.sql`, `database/seed.sql`,
`includes/header.php`, `includes/product-functions.php`,
`assets/css/header.css`, `assets/css/home.css`, `about.php`,
`index.php`, `admin/product-form.php`,
`admin/assets/css/admin.css`, plus the favicon `<link>` additions in
`shop.php`, `product.php`, `cart.php`, `checkout.php`, `support.php`,
`manual-upi-payment.php`, `order-success.php`, `payment-failure.php`,
`payment.php`, and all 11 rendered `account/*.php` + 15 rendered
`admin/*.php` pages listed above.

**Known limitation:** `shop.php`'s pre-existing "concern" dropdown
filter (LIKE-matching `purpose` against ~5 generic values) and the new
13-preset Concern Category system are intentionally two different
things living side by side, per this checkpoint's brief - a future
pass could fold the old filter into the new taxonomy, but that wasn't
in scope here and doing it silently would have violated "do not
repurpose Purpose."

---

## [v0.5.7] — Database Migration / Ordering Audit

**Summary — all five audit objectives passed:**
- ✅ **Fresh-install verification passed** — `schema.sql` + `seed.sql`
  into an empty database: zero errors.
- ✅ **Existing-database upgrade verification passed** — all 9 required
  migrations applied to a baseline seeded with real sample data: zero
  errors.
- ✅ **Idempotency verification passed** — all 9 migrations re-applied a
  second time: zero errors, zero duplicates.
- ✅ **Data preservation verified** — pre-existing admin/product/
  customer/order rows byte-identical before and after.
- ✅ **Canonical migration order finalized** — `PROJECT_STATUS.md`,
  `DEPLOYMENT_CHECKLIST.md`, and `SETUP.md` now agree on a single order
  (see `DEPLOYMENT_CHECKLIST.md` § 1); the one real hard dependency
  (order-tracking before stock-login) is documented in all three.

**Scope:** requested audit of database architecture / migration
ordering (fresh install + existing-database upgrade), following
earlier project incidents (`collection_products`, `admin_password_resets`
FK-ordering issues, both already resolved in Phase 2D - see that
entry's "Post-phase incident" note). No code or schema changes were
made; this was root-cause analysis + doc corrections only, verified
against a live MariaDB instance in an isolated sandbox (not this
project's dev DB).

**Root cause found:** `PROJECT_STATUS.md`'s 9-item "Database
Migrations Required" list was out of sync with
`DEPLOYMENT_CHECKLIST.md`'s "§1 Required Migrations" list -
`migration_phase4c_multi_gateway_checkout.sql` and
`migration_phase4d_manual_upi_qr.sql` were positioned differently
(after order-tracking/stock-login/password-reset in one doc, before
them in the other). This was a **documentation inconsistency only**,
not a functional bug: live testing (below) proved both orders produce
byte-identical schemas, because 4C/4D only write to the generic
`settings` table and touch no column any other required migration
depends on.

**Real (but already-satisfied) hard dependency identified:**
`migration_phase6_stock_login.sql` adds `orders.stock_deducted_at`
positioned `AFTER tracking_url` - a column only
`migration_phase5_order_tracking.sql` creates. All three docs already
ordered these two correctly relative to each other; this was
previously undocumented as a *hard* constraint (vs. the other 7
migrations, which are order-independent), so it was called out
explicitly in all three docs to prevent a future accidental reorder.

**Live verification performed** (MariaDB 10.11, isolated sandbox
instance, not the project's dev DB):
- **Fresh install:** `schema.sql` + `seed.sql` into an empty database
  - zero errors, 21 tables, all 16 foreign keys formed correctly, all
    seed rows inserted correctly.
- **Existing-database upgrade, two orders tested:** a baseline DB
  seeded with sample admin/product/customer/order/order-item/
  order-address rows, reverted to a pre-migration-9 state, then the 9
  required migrations applied (a) in `DEPLOYMENT_CHECKLIST.md`'s
  documented order and (b) in `PROJECT_STATUS.md`'s previous
  (differently-ordered) sequence, in two independent copies of the
  database - zero errors in either order.
- **Structural equivalence:** `SHOW CREATE TABLE` (columns, indexes,
  foreign keys, engine, charset, collation) for every table diffed
  across fresh-install and both upgrade orders - identical except for
  cosmetic `AUTO_INCREMENT` counter differences from the sample data.
  The two upgrade orders produced a byte-for-byte identical schema
  diff against each other (zero differences at all).
- **Idempotency:** all 9 migrations re-applied a second time to the
  already-upgraded database - zero errors, zero duplicate
  columns/tables, zero duplicate `settings` rows (verified via
  `information_schema` + `GROUP BY ... HAVING COUNT(*) > 1`).
- **Data preservation:** all sample rows (admin user, product,
  customer, order, order item, order address) verified byte-identical
  before and after the upgrade and after the idempotency re-run; new
  nullable columns (`stock_deducted_at`, `tax_type`, etc.) correctly
  landed as `NULL` on the pre-existing order, never retroactively
  populated.

**Files modified (documentation only):** `PROJECT_STATUS.md` (migration
list reordered to match the canonical/verified order, dependency note
added), `DEPLOYMENT_CHECKLIST.md` (hard-dependency + verification note
added to the existing, already-correct order), `SETUP.md`
(cross-reference note added directing readers to
`DEPLOYMENT_CHECKLIST.md` for the canonical global order, since its
own §8 numbering is phase-narrative-local and never claimed to be a
full 9-item sequence).

**Not changed:** `schema.sql`, `seed.sql`, and all `database/migration_*.sql`
files - live verification found no schema defect, so Task 5's "keep
changes minimal / do not invent missing schema requirements" applied:
there was nothing to fix at the SQL level.

**Remaining risk (unchanged by this audit, flagged for awareness):**
the `AFTER <column>`-anchored `ALTER TABLE` pattern used across several
migrations (tracking → stock_login being the one real cross-file
case) is inherently order-fragile if a future migration is inserted
between two that chain this way; no current instance is broken, but a
future migration author should grep for `AFTER` before reordering
anything in the required list.

**Accompanying v0.5.7 handoff cleanup** (documentation + packaging
only, no code changes):
- `PROJECT_STATUS.md` — Phase 5G (GST-compliant invoice system, v0.5.6)
  marked **COMPLETE** in the Completed table, disambiguated from the
  unrelated earlier "Phase 5G (Login Security)" work; the invoice-PDF
  GST-rendering line item removed from Pending (it's done - see the
  v0.5.6 entry below); the still-open checkout-summary GST breakdown
  kept as its own Pending item; commit-status notes updated to include
  Phase 5G and this audit.
- `test-pdf.php` (a dev-only diagnostic script hardcoding a local
  Windows temp path, not referenced anywhere in the application)
  excluded from the `MoonAura-v0.5.7-final.zip` handoff package. It is
  not deleted from the working tree.
- Fresh handoff package built: `MoonAura-v0.5.7-final.zip`.

---

## [v0.5.6] — Phase 5G: GST-Compliant Invoice System & Invoice Branding

**Phase 5G — production-ready GST invoice rendering + invoice branding
layer, on top of the Phase 5F GST snapshot:**

- **Items table** (`build_invoice_pdf()` in `includes/invoice-functions.php`)
  gained a **GST Rate column** showing each line's snapshotted
  `order_items.gst_rate` ("18%", "5.5%", "0%" - trailing zeros
  trimmed), so mixed-rate orders show the right rate next to every
  item. Column layout re-balanced ('# / Item / Qty / Unit Price / GST
  Rate / Line Total'); the header row is still redrawn on page breaks.
- **Totals block** replaced the single "GST (x%)" line with a proper
  GST invoice breakdown read from the stored Phase 5F snapshot, never
  recalculated at render time: **Taxable Value** + **CGST / SGST /
  IGST** rows (only non-zero components drawn). A rate suffix
  ("CGST @9%") appears only when the whole order is a single rate
  (`orders.gst_rate > 0`); mixed-rate orders show amounts without a
  label since their per-line rates are already in the table. Pre-5F
  historical orders (no tax snapshot) render with no tax rows and stay
  exactly as accurate as before.
- **PDF metadata** - `SimplePdfWriter::setMetadata()` emits a standard
  PDF `/Info` dictionary (Title / Author / Subject / Keywords / Creator
  / Producer / CreationDate / ModDate) referenced from the trailer.
  Dates come from the stored `invoice_generated_at`, so the Phase 3B
  byte-identical-on-every-download guarantee is preserved (verified).
  A metadata-free document emits no `/Info` object at all - output
  stays byte-identical to the pre-metadata format.
- **Branding** - "A Brand by DS Lifestyle" tagline under the business
  name in the letterhead (matching the footer's brand credit), plus an
  optional `business_website` "Website:" line on the seller contact
  block when configured.
- **Admin Business / GST settings** (`admin/settings.php`) - all
  `business_*` invoice fields now editable in the UI: `business_name`
  (required), `business_trade_name`, `business_gstin` (15-char GSTIN
  format validated, uppercased on save), `business_address`,
  `business_phone`, `business_email` (format-validated),
  `business_website` (`https://` auto-added, URL-validated), alongside
  the existing Phase 5F `business_state`. All length-capped; stored
  via `set_setting()`, read by `get_invoice_business_details()`.
  Empty GSTIN still prints "Not configured" (empty string and unset
  behave identically).
- **Admin invoice download** - `admin/invoice.php` accepts
  `?mode=download` → `Content-Disposition: attachment` (inline remains
  the default); `admin/order-detail.php` shows a "Download" link next
  to "View Invoice" for existing invoices.
- **Not faked** - PDF owner-password / read-only encryption is not
  supported by the hand-rolled writer and is documented as a known
  limitation with concrete alternatives (qpdf/Ghostscript
  post-processing, or a maintained library such as TCPDF/FPDF/dompdf),
  rather than emitting a bogus `/Encrypt` dict.

**Verification:** `/tmp/opencode/t_phase5g_invoice.php` (42 checks) -
GST breakdown across intra/inter/mixed/zero-rated orders, per-line
rates, determinism (byte-identical regeneration), numbering format/
uniqueness/idempotency, metadata + trailer, metadata-free backward
compatibility, settings-driven letterhead; every generated PDF opens
in pypdf. HTTP: settings POST validation (valid GSTIN saved +
uppercased, invalid GSTIN rejected, empty name rejected), admin
invoice `?mode=download` headers, guest-invoice token flow, public
pages. All prior harnesses re-run green (5F GST 60/60, 5D2 email
22/22, 5E/5F.1 24/24).

---

## [v0.5.5] — Phase 5F: GST & Tax Architecture

**Phase 5F — production-ready GST & Tax with product-wise rates and
GST-INCLUSIVE pricing (roadmap-naming note above):**

- **Pricing model** - product prices are **GST-inclusive**: the customer
  pays the sell price and the GST is **derived** out of it
  (`taxable_value = P / (1 + rate/100)`; `gst = P - taxable_value`), so
  `grand_total = subtotal - discount + shipping_charge` stays exact and
  no double-charge is possible. A ₹100 price @ 3% derives ₹2.91 GST and
  ₹97.09 taxable value - ₹100 is never turned into ₹103.
- **`includes/tax-functions.php`** (new) - the single server-side tax
  authority: `normalize_gst_rate()` (0-100, ≤2 decimals, negatives /
  non-numeric / >100 / >2-decimal rejected - used by BOTH the admin form
  and the calc layer), `resolve_tax_type()` (intra/inter/unknown from
  shipping state vs `business_state`), `split_gst_amount()` (CGST/SGST
  half-split with the second half derived so `cgst + sgst == gst` exactly
  - no paise drift; IGST for inter-state), `compute_line_tax()`,
  `aggregate_line_tax()`, `order_level_gst_rate()` and
  `tax_type_label()`. All money rounds to 2 decimals via `round()`.
- **`create_order()`** (`includes/order-functions.php`) - now resolves
  the tax type, re-reads each product's **authoritative** `gst_rate`
  from the `products` table (one indexed query - a caller-supplied rate
  can never be smuggled in), derives per-line + order-level tax, and
  snapshots it into `orders` and `order_items` **at creation time**.
  Existing order totals, payments, stock and emails are untouched.
- **Database** - `database/migration_phase5f_gst_tax.sql`
  (information_schema-guarded, idempotent, MySQL < 8.0.29 / MariaDB
  compatible): `products.gst_rate DECIMAL(5,2) DEFAULT 0.00` (existing
  products keep 0.00 = no tax on historical behaviour); `orders` gains
  `taxable_value`, `cgst_amount`, `sgst_amount`, `igst_amount`
  (DEFAULT 0.00) and `tax_type` (VARCHAR(10) NULL); `order_items` gains
  the same four breakdown columns; the `business_state` setting is
  inserted idempotently. Mirrored in `schema.sql` + `seed.sql`.
- **Admin UI** - `admin/product-form.php` (add/edit) gained a "GST
  Rate (%)" field with server-side validation (same `normalize_gst_rate`
  guard) and a hint that prices are GST-inclusive; `admin/products.php`
  gained a GST % column; `admin/settings.php` gained a "Business /
  GST → Business State" field (`business_state`, free-text, ≤100 chars,
  optional) used to resolve intra/inter-state.
- **Tax type resolution** - `order_addresses.state` (existing shipping
  state) is compared (normalized, case-insensitive) with the seller's
  `business_state` setting: match → intra (CGST+SGST), differ → inter
  (IGST). When `business_state` is unconfigured, `tax_type` is stored
  NULL and the GST splits conservatively as CGST+SGST so money always
  reconciles - documented, never guessed.
- **Verification** - `60/60` CLI checks green covering: rate validation
  edge cases, tax-type resolution (intra/inter/unknown, case variants),
  CGST/SGST/IGST split exactness (incl. odd paise), the inclusive
  formula (₹118@18%→100/18, ₹97@3%→94.17/2.83, 0% no-tax, ₹0 line),
  single-rate, mixed-rate (18/5/0%) and 0%-rate carts, inter-state,
  business_state-unset, discount+shipping consistency, snapshot
  stability (order/item values frozen after a product's rate changes),
  tamper-proofing (DB-authoritative rates, bad form rates rejected),
  stock-deduction regression, and invoice-PDF generation on a GST order.
  Regressions: Phase 5D Step 2 emails 22/22, Phase 5E + 5F.1 24/24,
  full `php -l` sweep clean, public pages 200.
- **Known limitations** - shipping state is free text at checkout
  (normalized best-effort), so tax-type resolution can mismatch on
  typos/missing state until a state dropdown exists (future billing vs
  shipping address work); the invoice PDF and checkout summary still
  show the pre-5F totals (GST breakdown rendering is deferred, invoice
  enhancement is roadmap Phase 5G); order-level `gst_rate` is 0.00 for
  mixed-rate orders by design (per-line `order_items.gst_rate` is
  authoritative).

---

## [v0.5.3] — Phase 5F.1: Admin 2FA Recovery Codes

**Phase 5F.1 — Additive one-time recovery codes for the admin TOTP 2FA
system (Phase 5E architecture untouched):**
- New `includes/recovery-codes.php` - 10 one-time codes per batch, each
  12 chars from a 32-char alphabet (no 0/1/O/I) via `random_bytes()`
  (~60 bits of entropy each). Only SHA-256 hashes are stored
  (`admin_recovery_codes.code_hash`); the plaintext lives only in the
  admin's session for the one-time display/download panel and is never
  emailed. Consumption is atomic (`UPDATE ... WHERE consumed_at IS
  NULL` + affected-rows check), so a code can be used exactly once even
  under concurrent requests. Codes belong to batches; the most recently
  generated batch is active, so **regenerating automatically
  invalidates all previous codes** without deleting rows.
- **Recovery login flow** - `admin/2fa-verify.php` gained a "Use a
  recovery code instead" option (checkbox + JS label toggle). A valid
  unused code signs the admin in and is marked consumed immediately;
  reuse attempts and invalid codes are rejected with generic messages
  and are subject to the same protections as TOTP entries: the shared
  `login_attempts` lockout (5 fails -> 15 min) and the session attempt
  cap (`ADMIN_2FA_MAX_ATTEMPTS`) that forces a fresh password login.
- **Admin UI** - `admin/2fa-setup.php` gained a "Recovery Codes" card
  (visible once 2FA is enabled): one-time panel right after
  Generate/Regenerate (codes shown once, with Download + "I've saved
  these codes"), a "Generate/Regenerate Recovery Codes" button, and a
  remaining-unused-codes count. New `admin/recovery-download.php`
  serves the codes as a `.txt` attachment (POST + CSRF only, session
  plaintext cleared after download, no-store response headers).
- **Security logging** - new `admin_security_log` table records
  generation, regeneration, download, use, reuse attempts and invalid
  attempts (with IP, admin id and detail; never code values).
- **Database** - `database/migration_phase5f1_recovery_codes.sql`
  creates `admin_recovery_codes` + `admin_security_log`
  (`CREATE TABLE IF NOT EXISTS`, idempotent; mirrored in `schema.sql`).
  No existing TOTP tables/columns were modified.
- **Verification** - `43/43` HTTP checks green: generation (10 hashed
  rows, plaintext not stored, panel shows once), TXT download (10 codes,
  single-fetch), successful recovery login, reuse prevention (atomic
  single-consume), regeneration invalidation (old-batch codes rejected),
  CSRF (403 on generate/download without token), rate limiting (5 wrong
  codes -> shared lockout message), baseline restore. Regressions: Phase
  5E 44/44, Phase 5D 30/30, Phase 5C 62/62, lockout 18/18.
- **Known limitations** - recovery codes only operate while 2FA is
  enabled (by design); the one-time plaintext panel persists in the
  admin's session until downloaded or dismissed (so the Download button
  keeps working across a reload); no OTP is required to
  generate/regenerate recovery codes (an authenticated admin session
  can do so - matches spec; could be hardened with re-auth later);
  stored hashes are unsalted SHA-256 (acceptable given ~60-bit code
  entropy; an HMAC pepper could be added later); old batch rows are
  retained (not deleted) for auditability.

---

## [v0.5.4] — Phase 5D Step 2: Order Transactional Emails

**Phase 5D Step 2 — Order confirmation, shipped and delivered emails,
built on the Step 1 mailer (PHPMailer + Brevo SMTP) and branded email
layout:**

- New `includes/order-emails.php` - single orchestration point for the
  three order emails. `send_order_email_if_due()` is the core: skips if
  already sent, validates the recipient, renders via the template
  functions, sends through `send_email()`, and logs every attempt. All
  paths are fail-closed and never throw, so **an SMTP outage can never
  break order creation, payment or status updates**.
- **Deduplication** - new `order_email_log` table
  (`migration_phase5d2_order_emails.sql`, mirrored into `schema.sql`)
  records one row per (order, email_type). Only `status='sent'`
  suppresses a retry; a `failed` row (network outage, etc.) does NOT
  block a later retry, so recovery sends exactly once with no
  duplicates on success. The check-then-insert is intentionally
  non-transactional to keep the order hot path fast.
- **Confirmation email trigger points** (all idempotent via dedup):
  - `update_order_payment_status()` in `includes/payment-functions.php`
    fires it when `payment_status` transitions to `paid` - covers the
    Cashfree client-callback + server-to-server verify path, Razorpay,
    Manual UPI admin verification and the admin "mark paid" button.
  - Payment webhooks through `PaymentManager::handleWebhook()` for both
    gateways land in the same `update_order_payment_status()` path.
  - COD: `checkout.php` sends it after the order is created and stock
    is deducted.
  - `admin/order-create.php` sends it after admin-created orders.
  - Gating: `is_order_confirmed_for_email()` - only orders that are
    not cancelled AND (COD OR `payment_status='paid'`).
- **Shipped / delivered emails** - `update_order_status()` in
  `includes/order-functions.php` fires them only on a REAL status
  transition (processing -> shipped, shipped -> delivered). Re-submitting
  the same status, or reverting and re-applying, never re-sends. The
  event timestamp is read back from `order_status_history` so the email
  shows the actual shipping/delivery time. Shipped emails include
  courier partner, AWB number and tracking URL when present, and work
  without them.
- **Templates** - `includes/email-templates.php` gained
  `order_confirmation_email()`, `order_shipped_email()`,
  `order_delivered_email()` plus shared `order_address_html/text` and
  `order_summary_html/text` helpers. The layout shells
  (`email_layout_html`/`email_layout_text`) gained optional
  footer-note/caption params - backwards compatible with the Step 1
  password-reset emails. All customer fields are HTML-escaped (a
  product name containing `<script>alert(1)</script>` is rendered as
  inert escaped text in the HTML part; the plain-text part shows the
  literal string, which is safe in text clients).
- **Mailer** - `send_email()` in `includes/mailer.php` gained an
  optional `$replyToAddress` param and a by-ref `$failureReason` out
  param. Reply-To defaults to the new `MAIL_REPLY_TO_ADDRESS` constant
  (`config/config.php`, default `support@moonauracrystals.in`, env
  override `MAIL_REPLY_TO_ADDRESS`), falling back to the From address.
  From stays `noreply@moonauracrystals.in` / `MAIL_FROM_ADDRESS`.
  Failure reasons are now captured into the `order_email_log` row
  instead of only the PHP error log.
- **Verification** - 22/22 CLI checks against a local SMTP sink:
  COD / Cashfree / Razorpay / Manual UPI confirmation, repeat-submit
  dedup, shipped + delivered emails, shipped without courier details,
  missing/invalid recipient (skipped, no crash), XSS escaping,
  signed Cashfree + Razorpay webhooks (valid signature sends exactly
  once, re-fire is idempotent, bad signature rejected with no email).
  SMTP-failure drill: sink down -> order flow unaffected, `failed`
  logged with the SMTP reason; sink restored -> retry sends exactly
  once. Regression: Phase 5D Step 1 forgot-password (admin + customer)
  end-to-end, Phase 5E TOTP 24/24, Phase 5F.1 recovery codes, all PHP
  files lint-clean.

---

## [v0.5.2] — Phase 5E: Admin Security Hardening + TOTP Two-Factor Auth

**Phase 5E — Admin session hardening + RFC 6238 TOTP 2FA for admin logins:**
- New `includes/two-factor.php` - RFC 6238 TOTP (SHA1 / 6 digits / 30s
  window, ±1 step tolerance, verified against the RFC 6238 test vector),
  a 160-bit Base32 secret generator (`generate_totp_secret()`),
  `totp_code()` / `verify_totp()`, an `otpauth://` URI builder, and
  AES-256-GCM storage helpers. The secret is **never stored in plain
  text** - `two_fa_encrypt_secret()` stores `base64(iv || tag ||
  ciphertext)` keyed by `ADMIN_2FA_ENCRYPTION_KEY` (32-byte base64, env
  only, never hardcoded). OTP values are never stored; no recovery
  codes are written anywhere. Every public entry point **fails closed**:
  a missing/wrong key or undecryptable blob denies access rather than
  guessing.
- `admin/login.php` is now 2FA-aware: valid password + 2FA-on admin →
  parked as `$_SESSION['admin_2fa_pending']` (no `admin_id` yet) and
  sent to the new OTP step. Only a verified code grants the session.
- New `admin/2fa-verify.php` - step 2 of login: fail-closed guards
  (no pending → login, already logged in → dashboard, inactive /
  2FA-off / unreadable secret → pending cleared + login), POST-only
  OTP check with CSRF, `complete_admin_login()` (session regeneration,
  anti-fixation) on success. Every wrong code counts into the existing
  per-email lockout (`login_attempts`, 5 fails → 15 min) **and** a
  session attempt counter (max `ADMIN_2FA_MAX_ATTEMPTS`) that forces a
  fresh password login instead of grinding OTPs.
- New `admin/2fa-setup.php` - Security Settings: QR code (client-side
  vendored `admin/assets/js/qrcode.js`, MIT) + manual Base32 key,
  "verify the first OTP before enabling" (2FA only flips to on after a
  real code), "Generate a New Secret" rotation, and disable - which
  also requires a valid current OTP (no silent bypass). Shows a
  fail-closed warning and refuses to store anything when
  `ADMIN_2FA_ENCRYPTION_KEY` is missing.
- `includes/auth.php` hardened: `send_security_headers()` (nosniff,
  SAMEORIGIN, Referrer-Policy), idle-timeout enforcement
  (`ADMIN_SESSION_IDLE_TIMEOUT`, 30 min), User-Agent binding (session
  destroyed on UA change), `attempt_admin_login()` no longer touches
  the session, and the single `complete_admin_login()` grants
  `admin_id` and regenerates the session id. Pending 2FA sessions are
  sent straight to the OTP step by `require_admin_login()`.
- Logout moved to **POST + CSRF** (`admin/logout.php`, logout button in
  the top bar is now a small form) - GET logout no longer destroys the
  session. Sidebar gained a "Security" link to the 2FA setup page.
- Database: `database/migration_phase5e_admin_2fa.sql` adds
  `two_factor_enabled` / `two_factor_secret` / `two_factor_enabled_at`
  to `admin_users` (guarded, idempotent; mirrored in `schema.sql`).
  No existing admin is locked out - 2FA defaults off.
- Verified locally: 44/44 HTTP flow checks (setup → QR → enable-on-
  first-OTP, login-with-2FA, wrong-code + lockout + attempt-cap,
  bypass/tamper/CSRF, disable-with-OTP, baseline restore) + 11/11
  fail-closed checks (no-key and tampered-secret scenarios). All prior
  suites still green (Phase 5C 62/62, Phase 5D 30/30, lockout 18/18).

---

## [v0.5.1] — Phase 5D: Email Notifications (Step 1)

**Phase 5D Step 1 — Email infrastructure + Forgot-Password emails:**
- New `includes/mailer.php` - the single place that sends email,
  wrapping vendored **PHPMailer 7.1.1** (`includes/lib/PHPMailer/`)
  against **Brevo SMTP**. Everything is env-driven from `config.php`
  (`BREVO_SMTP_HOST/PORT/USERNAME/PASSWORD`, `MAIL_FROM_ADDRESS/NAME`,
  optional `BREVO_SMTP_SECURE`) - zero credentials in code. Features:
  central `send_email()`, HTML + plain-text `multipart/alternative`
  (plain auto-derived from HTML via `html_to_text()` when not passed),
  TLS (implicit `ssl` on 465 / `tls` on 587 auto-detected, SMTPAutoTLS
  upgrade, or forced via `BREVO_SMTP_SECURE`), graceful failure
  (returns `false`, never throws - fatal TypeError on a null recipient
  is now prevented too), and one-line `error_log()` diagnostics on
  every failure. Fails closed: no SMTP config → no network attempt.
- New `includes/email-templates.php` - branded, mobile-safe,
  inline-CSS-only (no external assets) email shell for MoonAura
  Crystals ("Guided by the Moon, Inspired by Nature") with a single
  CTA button and 60-minute expiry notice, plus
  `customer_password_reset_email()` and `admin_password_reset_email()`
  (each returns subject/html/text).
- `account/forgot-password.php` + `admin/forgot-password.php`: the
  dev "link printed on the page" placeholders are gone - the reset
  link is now actually emailed. The no-enumeration behaviour is
  unchanged (same generic message whether or not the address exists
  and whether or not SMTP succeeded); SMTP failures log and the flow
  keeps working. Customer page reuses `create_customer_reset_token()`;
  admin keeps its inline token generation. Both now SELECT the email
  column needed to send (previously only id+name).
- `config/config.php`: new env-driven email/SMTP block.
- `SETUP.md`: new "Email / SMTP Setup (Phase 5D)" section.
- Out of scope for this step (per roadmap): order / shipping /
  delivery / marketing emails and WhatsApp integration.

## [v0.5.0] — Phase 5B: Customer Forgot Password

**Phase 5B — Customer Forgot Password (customer-side password reset):**
- New `customer_password_resets` table - the customer-side mirror of
  `admin_password_resets` (Phase 2D): `customer_id` FK to
  `customers(id)` ON DELETE CASCADE, `token_hash` (SHA-256 only, never
  the raw token), `expires_at` (60 minutes), `used_at` (single-use),
  `created_at`. Indexed token lookup. Delivered as
  `database/migration_phase5b_customer_password_reset.sql` (idempotent,
  `CREATE TABLE IF NOT EXISTS`, non-destructive) + same structure in
  `database/schema.sql` for fresh installs
- New `account/forgot-password.php`: CSRF-protected email form, only
  `status='active'` customers get a token, identical no-enumeration
  message whether the email exists or not, and the reset link is
  displayed on-page as a dev placeholder (real email delivery is Phase
  5E, exactly like the admin flow). Reuses the Phase 5G per-email
  lockout (`login_attempt_status()`/`login_attempt_failed()`) to
  throttle repeated requests and refuses to generate links for a
  currently-locked email
- New `account/reset-password.php`: validates the token against the
  hashed value (exists + unused + unexpired - one generic message for
  all failure modes), CSRF-protected new-password form reusing the
  existing ≥8-char + confirmation rules, `password_hash(PASSWORD_DEFAULT)`
  update, marks the token `used_at` (one-time use), clears any login
  lockout for that account, then redirects to `account/login.php` with
  the success flash message "Password updated successfully. Please
  login." (no auto-login)
- New token helpers in `includes/customer-functions.php`:
  `create_customer_reset_token()`, `get_valid_customer_reset_token()`,
  `consume_customer_reset_token()` - the only DB touch-points, so a
  future Phase 5E email step can send the token without touching this
  logic
- `account/login.php`: added "Forgot your password? Reset it here" link
  in the existing auth footer (same spacing/alignment, no redesign)
- UI/UX: both new pages reuse the existing `account-auth-page` /
  `account-auth-box` / `account-alert` / `.btn.btn-primary` /
  `account-auth-footer` storefront components from `assets/css/account.css`
  (identical to Login/Register/Change Password) - no Bootstrap, no
  Tailwind, no new CSS
- **Verified live** (22 automated HTTP checks): existing-email link
  generation, non-existing-email generic message + no token row,
  invalid/expired/already-used token rejection, password mismatch
  (token not consumed, hash unchanged), successful reset →
  redirect to login + exact flash message, login with new password
  works, login with old password fails, CSRF 403s on both forms,
  forgot-password blocked while the email is login-locked, and stored
  `token_hash` == SHA-256(raw token)
- Out of scope (unchanged): email notifications/SMTP (Phase 5E), magic
  links, 2FA, and the admin password reset flow

---

## [v0.4.8] — Phase 5A: UI/UX & Order Tracking + Phase 5F: Inventory Automation + Phase 5G: Login Security

**Context:** this release delivers three of the Phase 5 roadmap items.
Earlier internal labels "Phase 5 (Order Tracking)" and "Phase 6
(Search/Stock/Login)" are normalized here to the 5A/5F/5G scheme;
migration filenames keep their historical names (see versioning note
above).

**Phase 5A — UI/UX & Order Tracking:**
- Product search entry point fixed: the header search icon previously
  linked to a nonexistent `search.html` - it now routes to
  `shop.php?q=` (`includes/header.php`)
- `get_shop_products()` (`includes/product-functions.php`) supports a
  `q` filter with escaped-LIKE matching on product name, category
  name, short/full description, purpose/concern and zodiac
- `shop.php`: search form with persistent value, search-aware empty
  state, pagination drops empty `q`; `.shop-search-box` /
  `.shop-empty-state` CSS in `assets/css/shop.css`
- Order tracking & timeline: `order_status_history` table (one row per
  real milestone), `log_order_status_event()` / `update_order_status()`
  / `update_order_payment_status()` timeline logging
- Admin order detail (`admin/order-detail.php`): order-status,
  payment-status (COD/Manual UPI only), and shipping/tracking
  (courier/AWB/tracking URL) update forms; two-column order +
  customer/shipping cards
- Customer order detail (`account/order-detail.php`): real timeline
  rendering plus Shipping & Tracking display once an order is shipped
  or delivered

**Phase 5F — Inventory Automation:**
- New `includes/stock-functions.php`: `apply_order_stock_deduction()`
  (transaction + `SELECT ... FOR UPDATE` + guarded per-item
  `UPDATE ... AND stock_quantity >= ?`), `restore_order_stock()`,
  `validate_cart_stock()`, `refresh_product_stock_status()` (auto
  `out_of_stock`/`in_stock` flip, never touches admin `low_stock`)
- Deduction points: COD at placement (`checkout.php`), Manual UPI only
  on admin verify (`admin/order-detail.php`), gateways only after
  payment confirmation (`PaymentManager` verify + paid webhook,
  wrapped in idempotent `reserveStockForOrder()` - failures logged,
  never a 500)
- `orders.stock_deducted_at` marker makes deduction/restore
  idempotent (double-callback and payment-retry safe); restore on
  admin cancel; no negative stock; pre-existing orders never
  retroactively deducted
- `database/migration_phase6_stock_login.sql` adds
  `orders.stock_deducted_at` (filename kept per versioning note)

**Phase 5G — Login Security:**
- New `includes/login-security.php`: `login_attempt_status()` /
  `login_attempt_failed()` / `login_attempt_succeeded()` /
  `login_lock_message()`, `login_attempts` table, 5 failed attempts /
  15-minute lock for both admin and customer login, no-enumeration
  messages, lazy expiry, success clears the record
- Wired into `admin/login.php` and `account/login.php`
- `database/migration_phase6_stock_login.sql` adds the
  `login_attempts` table (filename kept per versioning note)

**Verification:** 60 automated checks green (26 unit + 16 HTTP stock
+ 18 lockout), plus search HTTP checks, fresh-install schema load,
and `php -l` sweep. See `PROJECT_STATE.md` §4.

---

## [Unreleased] — Phase 4C: Multi-Gateway Payment Checkout, Admin Settings + Phase 4D: Manual UPI QR Payment, Manual UPI Verification Workflow

**Context:** an initial implementation of the checkout-facing half of
both Phase 4C (multi-gateway checkout) and Phase 4D (Manual UPI QR
payment) was already present in the codebase when this work began -
built directly, outside the usual session-by-session process this
changelog otherwise reflects, with neither this file nor
`PROJECT_STATE.md` updated to describe it. The first thing done was an
audit against the approved specification (documented in full in
`PROJECT_STATE.md` §4/§5) rather than assuming it was correct or
building further on top of it unverified. That audit found the
checkout-facing half of both features correctly built and already
satisfying the approved requirements, but two genuinely critical
pieces referenced by name in the existing code/docs were missing
entirely: `admin/settings.php` and the Manual UPI Verify/Reject
Payment admin workflow. This entry covers building those two pieces,
plus documenting everything else that was already there but
undocumented.

**Phase 4C - Multi-Gateway Payment Checkout (audited, found already
built):**
- Replaced the single `active_payment_gateway` setting with one
  `*_enabled` flag per gateway (`cashfree_enabled`, `razorpay_enabled`,
  `phonepe_enabled`) plus `default_payment_gateway`, which only
  controls which enabled option checkout pre-selects, never which
  options are shown. Any combination of gateways can be enabled at
  once.
- `PaymentManager::createPayment()` now resolves the gateway from the
  order's own stored `payment_method` (set at checkout, when the
  customer actually chose it) instead of a global "currently active"
  setting - `verifyPayment()`/`handleWebhook()` already worked this
  way per-transaction, so this closes the one remaining gap between
  "one active gateway" and "the customer's own choice, remembered."
- `checkout.php` shows one labeled radio option per enabled gateway
  (e.g. "Cashfree", "Razorpay") plus Cash on Delivery if enabled,
  correctly rejects a disabled gateway server-side if somehow
  submitted anyway, and forces Cash on Delivery back on as a logged
  safety fallback if every payment method is ever left disabled at
  once (the actual UI-level prevention of that state is the new
  `admin/settings.php` validation below - this fallback is the
  defense-in-depth backstop, not the primary mechanism).
- `database/migration_phase4c_multi_gateway_checkout.sql` (idempotent,
  `INSERT IGNORE`) seeds the new settings from whatever
  `active_payment_gateway` a given database already has, so existing
  checkout behavior is preserved exactly on upgrade. `schema.sql`/
  `seed.sql` also updated for fresh installs.
- **PhonePe investigated, deliberately not implemented blindly:**
  their current API (PG API v2) requires `client_id`/`client_secret`/
  `client_version` issued only via a manual support-ticket onboarding
  process with PhonePe's integration team (no self-serve sandbox keys
  like Razorpay/Cashfree), uses an OAuth token exchange neither
  existing gateway needs, and its webhook verification is a third,
  different mechanism (a dashboard-configured username/password pair,
  `SHA256(username:password)` compared against the `Authorization`
  header) - documented in full in `PaymentManager.php`'s own comments
  and `PROJECT_STATE.md` §5. `phonepe_enabled` exists as a real,
  inert setting; `getEnabledGatewayNames()` only ever returns a
  gateway that's both enabled AND has a registered class, so flipping
  it on safely does nothing until a real `PhonePeGateway` class is
  written and registered.

**Phase 4C - Admin Settings page (built this session, was missing):**
- New `admin/settings.php`, linked from the sidebar (was a disabled
  "coming in a future phase" placeholder). Payment Methods section
  (checkbox per gateway - Cashfree, Razorpay, a disabled/inert
  PhonePe row, Manual UPI QR, Cash on Delivery), a Default Payment
  Gateway dropdown, and a Manual UPI Settings section (UPI ID, Account
  Name, QR image upload/replace).
- Both required validation rules enforced server-side, not just
  hidden client-side: submitting a form that would leave every
  payment method disabled is rejected with an error, and
  `default_payment_gateway` can only be saved as one of the gateways
  being saved as enabled in that same submission. A small inline
  script keeps the dropdown's disabled options in sync with the
  checkboxes as an admin toggles them before submitting, but that's a
  UX nicety on top of the real, server-side enforcement.
- `PaymentManager.php` gained one new public, read-only method,
  `getRegisteredGatewayNames()`, so this page has a single source of
  truth for which gateways have an actual class instead of
  hardcoding the list itself. Does not touch `createPayment()`,
  `verifyPayment()`, `handleWebhook()`, or either gateway class.
- `admin/assets/css/admin.css` gained `.admin-btn-danger` (reusing the
  existing `#b3261e` danger red already used by
  `.admin-icon-btn-danger` and the invoice payment-status badges - not
  a new color) for the Reject Payment button below, and
  `.admin-current-qr` (a small image-preview style) for the QR
  section.

**Phase 4D - Manual UPI QR Payment (audited, found already built):**
- A fifth checkout option: customer sees an admin-configured QR code/
  UPI ID/account name, pays via any UPI app, and submits the UTR
  (transaction reference) plus an optional screenshot on
  `manual-upi-payment.php`. Logs a `payment_transactions` row
  (`status = 'submitted'`) without marking the order paid -
  `payment_status` stays `'pending'` until an admin acts on it.
  Authorization matches `payment.php`'s existing model
  (`customer_or_session_owns_order()`); file upload validated via the
  new `includes/upload-functions.php` (server-side MIME sniffing via
  `mime_content_type()`, 5MB cap, fully random server-generated
  filename - the client's original filename is never used).
- `database/migration_phase4d_manual_upi_qr.sql` adds
  `manual_upi_enabled` (off by default), `upi_id`, `upi_account_name`,
  `upi_qr_image_path` to `settings`, and a `screenshot_path` column to
  `payment_transactions`. `schema.sql`/`seed.sql` updated for fresh
  installs.

**Phase 4D - Manual UPI verification workflow (built this session,
was missing - the feature was not usable in production without it):**
- `admin/order-detail.php` gained a "Manual UPI Payment" card, shown
  for any `manual_upi` order with a logged transaction: UTR number,
  submission timestamp, and a link to the uploaded screenshot (if
  any).
- **Verify Payment** button → `payment_status = 'paid'`,
  `order_status = 'processing'` (the same end state a successful
  online-gateway payment reaches).
- **Reject Payment** button → `payment_status = 'failed'`,
  `order_status` left unchanged (the order isn't cancelled, just not
  yet paid - matching how a failed online-gateway attempt doesn't
  cancel the order either).
- Both buttons only appear while the order is still
  `payment_status = 'pending'` - CSRF-protected, confirm-dialog
  gated, and write through the *existing*
  `update_order_payment_status()`/`update_payment_transaction()`
  functions (the same ones `PaymentManager` itself calls) rather than
  new hand-written SQL. Confirmed this is the only order/payment
  status update mechanism added anywhere - every other payment
  method's status still only ever changes via `PaymentManager` or
  `checkout.php`'s direct COD call, both completely unchanged this
  session.

**Documentation:**
- `SETUP.md`: corrected the stale "Admin Settings page... planned but
  not part of this phase" line now that it exists, and added a full
  Phase 4D section (QR payment flow + the verify/reject workflow).
- `PROJECT_STATE.md`: added proper Phase 4C and 4D rows to the
  Completed Phases table, a full Runtime Verification Status entry
  documenting exactly what changed and what's still unverified against
  a real server, and corrected two now-stale Known Limitations entries
  that both said "no Admin Settings UI exists" (one in the payment
  section, one in the invoice-business-details section) - the second
  one specifically clarified: `admin/settings.php` covers payment
  settings only, `business_gstin`/`business_trade_name`/etc. remain
  SQL-only.

**Verified this session:** `RazorpayGateway.php`, `CashfreeGateway.php`,
`payment.php`, `payment-verify.php`, `webhook-razorpay.php`, and
`webhook-cashfree.php` are all byte-for-byte unchanged (confirmed via
diff) - nothing about how either existing gateway processes, verifies,
or receives a webhook for a payment was touched.

---

## [Unreleased] — Phase 4B: Full customer-facing UI/UX audit series (10 batches) — closed

A 9-batch, page-by-page audit and fix pass covering every
customer-facing page. Every change in this entry is CSS/HTML markup
only - no schema changes, no new queries, no business-logic changes.
Two claims in an earlier Phase 4B entry below turned out to be
incomplete when the code was actually re-checked this session -
corrected here and flagged explicitly rather than silently fixed (see
"About Us / Home overflow" and "Button-system audit" below).

**Batch 1 — Footer, Cart/Wishlist Empty States, Orders Page, Account
Typography:**
- **Footer white-strip, root cause found:** `index.php`'s
  `#backToTop` button had no CSS or JS anywhere in the project - a
  bare, unstyled `<button>` sitting in normal document flow directly
  below the footer, which was the visible "white strip." Given a real
  fixed-position implementation (`style.css`: hidden by default,
  shown via a `.show` class) and scroll-show/click-to-top behavior
  (`main.js`, guarded with `if (!backToTop) return;` since only
  `index.php` has this element).
- **Cart/Wishlist Empty States:** both "Continue Shopping" buttons had
  `class="btn-primary"` without the shared `.btn` base class (same
  root-cause pattern as the account-pages button work below), so they
  rendered unstyled. Consolidated the two pages' near-duplicate
  empty-state markup into one shared `.empty-state` component in
  `style.css`. `payment-failure.php` also uses the old `.cart-empty`
  class from `cart.css` - kept that rule in place (now unused by
  `cart.php`) rather than delete it, since that would have broken an
  out-of-scope page.
- **Orders Page:** removed the separate "View Details" column, made
  the Order # itself the clickable link (new `.account-order-id-link`
  class in `account.css`).
- **Account Typography:** every account page's `<h1>` reads "My
  Account" except `order-detail.php`, which had `<h1>Order
  MOAOD...</h1>`. Standardized to match; moved the order number into
  the existing "Placed <date>" meta line instead of dropping it.

**Batch 2 — Shop Page rework + Product Cards:**
- **Shop Hero:** replaced a separate, broken `.about-hero`/`.hero-tag`
  implementation (which wasn't even linked to a stylesheet defining
  it) with the exact `.hero`/`.hero-container`/`.hero-content`/
  `.hero-image` component `index.php` already used (`home.css`). CTA
  buttons removed per spec; same background image reused.
- **Shop Filters/Toolbar/Pagination:** had zero CSS anywhere. Added a
  new page-scoped `assets/css/shop.css` (same pattern as
  `cart.css`/`checkout.css`), styled using the existing form-input
  pattern from `account.css` and the existing pill-nav pattern from
  `.account-nav a`.
- **"Why Choose MoonAura" / "Need Help Choosing" on Shop:** replaced
  `shop.php`'s own separate `.shop-features`/`.shop-cta`
  implementations with a reuse of About Us's existing
  `.about-why*`/`.need-help`/`.help-box` components (later renamed -
  see the Batch 3 consolidation below). A second (outline) button was
  needed in the Need Help box that About's version never needed -
  added as a `.shop-need-help`-scoped rule so About Us itself was
  never touched.
- **Product Cards:** `.product-category`, the card title, `.product-price`,
  and `.current-price` (the `shop.php`/`wishlist.php` card variant)
  had no CSS anywhere - added to `home.css`, giving every card a fixed
  2-line clamp for both title and benefits text (benefits paragraph
  now always renders, even empty, so the space is always reserved).
  **Regression caught in the same batch:** the first version of the
  new `.product-content h3` selector had no class qualifier, so it
  also matched (and, due to higher specificity, silently overrode)
  `index.php`/`product.php`'s `<h3 class="product-title">` in the same
  `.product-content` wrapper - rescoped to
  `.product-content h3:not(.product-title)` before shipping.

**Batch 3 — About Us / Home overflow fix + "Why Choose" consolidation:**
- **About Us grid overflow - the earlier fix (see the entry below)
  was incomplete.** The desktop 4-column rule did have the
  `minmax(0,1fr)` fix, but the `@media (max-width:992px)` 2-column
  breakpoint still had the original bare `repeat(2,1fr)` bug. A
  second, separate bug compounded it: `white-space:nowrap` on the
  card title caused "Certificate Included" to overflow its own card
  at real mobile widths regardless of the grid fix. Confirmed via a
  flexbox simulation at the real computed column width (showed the
  overflow, then showed it resolved) since `wkhtmltoimage` here can't
  render CSS Grid. Fixed both bugs on About; found and fixed the
  identical pair on Home's `.why-grid`/`.why-title`.
- **Home/About/Shop "Why Choose" consolidation:** the two
  implementations (`home.css`'s `.why-*`, `about-us.css`'s
  `.about-why-*`) were byte-for-byte identical in every CSS property
  but one real difference - Home's markup was missing the gold
  divider element between title/description that About's had.
  Evidence this was unintentional, not a design choice: `home.css`
  contained an orphaned `.about-why-card:hover .about-why-divider`
  rule (About's own class names, sitting unused in the wrong file)
  plus a fully-built, never-referenced `.why-divider` rule with
  identical values to About's. Added the missing divider to
  `index.php`, moved the shared CSS into `style.css` under a neutral
  `.why-choose*` name, deleted both old duplicate blocks, updated
  `about.php`/`shop.php` to match. **Gap caught in a follow-up
  verification pass**: the initial edit deleted the old CSS from
  `about-us.css` correctly but missed deleting the matching block from
  `home.css` - completed before this was considered done.

**Batch 4 — Authentication + Shared Forms audit:**
- `login.php`/`register.php` reviewed - already correct, no changes
  needed.
- Found and fixed one instance of the missing-`.btn`-base-class bug on
  `checkout.php`'s "Place Order" button.
- Confirmed (again) no customer-facing Forgot/Reset Password flow
  exists - only Admin has one. Not fixed (new pages/routes/logic,
  outside a UI audit's scope) - flagged as a product decision.

**Batch 5 — Customer-Facing Button-System audit:**
- The Batch 4 finding turned out to be much larger than the 8-file
  count an earlier Phase 4B entry (below) had recorded for the
  account pages. Verified and fixed 13 more identical
  missing-`.btn`-base-class instances: `cart.php` (Update/Proceed to
  Checkout/Continue Shopping), `payment.php` (Back to Checkout/Pay
  Now), `payment-failure.php` (Retry Payment/Back to Cart),
  `order-success.php` (Continue Shopping/Download Invoice/Print
  Invoice), `product.php` (Back to Shop/Add to Cart/Buy Now).
  Confirmed every page-scoped CSS override that assumes `.btn` is
  present (e.g. `.cart-summary .btn-primary`) was already correctly
  authored and simply waiting for the class to be added - these were
  genuine bugs, not missing designs.

**Batch 6 — Tables Audit:**
- `.account-table` (used by `orders.php` and `dashboard.php`) had no
  responsive handling anywhere - added `.account-table-wrap`
  (`overflow-x:auto`) plus a `min-width` on the table so it scrolls
  instead of illegibly compressing on narrow screens.
- `dashboard.php`'s Recent Orders table still had the stale "View
  Details" column pattern Batch 1 had already removed from
  `orders.php` - applied the same fix.
- **Cart mobile layout (≤800px), two real bugs found:** the
  remove-button's `grid-area: remove` targeted the `<button>`, but the
  actual CSS Grid child is its unclassed wrapping `<form>` -
  `grid-area` has no effect on non-grid-item elements (unambiguous
  per the CSS Grid spec). Fixed by adding `class="cart-item-remove-form"`
  to the form itself and retargeting the CSS. Separately, the
  "Update" button overflowed past the card edge because the inherited
  `.btn` `min-width:180px` was never overridden for this compact
  context - fixed with `min-width:0`, scoped inside the existing
  800px media query only so desktop sizing is untouched.

**Batch 7 — Notifications Audit:**
- Reviewed all three alert families (`.account-alert*`,
  `.checkout-alert*`, `.cart-alert*`) across every page using them.
  Confirmed byte-for-byte identical in every property (colors,
  padding, radius, spacing, the `<ul><li>` error-list pattern, and a
  consistent total absence of icons everywhere). **No genuine
  inconsistency found - no files changed.**

**Batch 8 — Loading States Audit:**
- Searched the full customer-facing codebase, including the payment
  gateway JS, for any spinner/skeleton/disabled-button-state
  implementation. **None exists anywhere, consistently - no files
  changed.** Flagged as a product decision (particularly the Pay Now
  button having no double-click protection), not a bug.

**Batch 9 — Documentation synchronization:**
- `PROJECT_STATE.md` and `CHANGELOG.md` had gone stale relative to
  this entire audit series (verified: byte-identical to the pre-audit
  baseline until this entry). Brought both current - see
  `PROJECT_STATE.md` §1/§2/§4/§5/§6 for the full breakdown, including
  the two corrections noted above.

**Batch 10 — Final Design System Audit (incl. Product Page Audit):**
- **Product Page Audit:** reviewed the gallery, breadcrumb, info
  block, specs, description, Related Products section, and the
  404/out-of-stock empty state. Structurally sound - Add to Cart/Buy
  Now already correctly fixed in Batch 5, Related Products correctly
  reuses the `index.php` card variant. One design-inconsistency
  finding (not fixed): `.product-not-found` is a third, visually
  distinct "empty state" pattern (no icon/card background, unlike
  Cart/Wishlist's shared `.empty-state`) - not broken, just
  stylistically different from the shared component; changing it
  would mean redesigning an existing, currently-working page.
- **Accessibility bug, found and fixed:** `.newsletter-form input`
  (Home page) had `outline:none` with no `:focus` replacement
  anywhere in the codebase - confirmed via a full programmatic sweep
  of every `outline:none` rule site-wide that this was the only such
  instance (a real WCAG focus-visibility gap, not a design choice -
  every other outline-suppressed input on the site pairs it with a
  `border-color` swap; this one has `border:none` too, so a
  `box-shadow` ring was used instead, on the same
  `rgba(91,46,145,...)` primary-color convention already used
  elsewhere in this codebase).
- **Icon-syntax inconsistency, found and fixed:** the `#backToTop`
  button (built this session, in Batch 1) used legacy
  `class="fas fa-chevron-up"` instead of the `fa-solid` prefix used
  everywhere else on the site (162 other instances, confirmed via a
  full sweep). Fixed for authoring consistency.
- Reviewed and explicitly left unchanged: the wide box-shadow value
  spread across cards/buttons (intentional per-component art
  direction - purple-tinted for brand cards, gold-tinted for CTAs,
  neutral for plain cards - not an inconsistency); two hardcoded hex
  colors that exactly duplicate `var(--primary)`/`var(--gold)` (zero
  visual difference, maintainability note only); the `Courier New`
  monospace on the payment reference value (intentional, appropriate
  for an alphanumeric code); the "hover-lift only on truly clickable
  whole-cards" pattern (`.product-card`/`.why-choose-card`/
  `.testimonial-card` have it, `.account-card`/`.cart-item`/
  `.account-address-card` correctly don't, since the latter are
  containers for separately-interactive children).

**Findings reported, intentionally not fixed** (each needs a design
decision or is architecture-level, both explicitly out of this
audit's scope): button width behaves differently across three
otherwise-identical contexts (auth box/account card/checkout form);
form-input and alert CSS is triplicated near-identically across three
stylesheets; `dashboard.php` now shows 4 order-table columns vs.
`orders.php`'s 5; no Warning/Information notification variant exists
anywhere (only Success/Error); the two different product-card markups
noted in an earlier entry below still coexist; `.product-not-found`
is a third empty-state visual pattern; two hardcoded hex colors
duplicate existing CSS variables. Full detail in `PROJECT_STATE.md`
§5.

**Phase 4B is now code-complete and closed**, pending your
real-server review of everything shipped across all 10 batches.

---

## [Unreleased] — Phase 4B: Customer Account + Authentication button-system rollout, roadmap numbering reconciled

Continuation of Phase 4B (UI/UX Refinement), scoped to the 5 pages
explicitly named for the Customer Account part: Dashboard, Orders,
Saved Addresses, Profile, Change Password - plus Authentication
(`login.php`/`register.php`), fixed in this same pass once the
identical bug was confirmed there too.

**Roadmap numbering reconciled:** the agreed project roadmap labels
the Wishlist feature "Phase 4A" and this UI/UX refinement work "Phase
4B" - this project's own docs had been calling Wishlist "Phase 3E"
(a numbering that predates the agreed roadmap). Every "Phase 3E"
reference in this file and `PROJECT_STATE.md` is now "Phase 4A", and
the "3F+" pending-scope placeholder is now "4C+", so the two no
longer disagree. No implementation changed as part of this -
labels only.

**Root cause found - most buttons were missing the shared `.btn`
base class.** The button system's actual shape/sizing/hover-lift
(pill radius, 56px height, padding, transition) all live on `.btn`;
`.btn-primary`/`.btn-outline` alone only set color and were never
designed to work standalone. Across `orders.php`, `profile.php`,
`change-password.php`, `address-form.php`, `order-detail.php`
(invoice buttons), `addresses.php` (Add Address), `login.php`, and
`register.php`, the color class was present but `.btn` wasn't - so
these were rendering as essentially unstyled, undersized elements
rather than the intended pill buttons. Fixed by adding the missing
`.btn` class everywhere it was missing; zero color/variant changes.

**Found and removed a hack instead of a fix:** `addresses.php`'s Add
Address button had `style="display:inline-block;"` inline - a patch
for the missing-`.btn` symptom rather than the actual cause. Removed
the inline style, added the real `.btn` class - also restores the
icon-nudge hover effect (`.btn:hover i`) that couldn't work without
it.

**Edit / Delete / View Details standardized on `.btn-text`:**
`addresses.php`'s Edit/Delete and `orders.php`/`dashboard.php`'s
"View Details" (plus dashboard's "View all N orders") were each
styled by bespoke, duplicate CSS (`.account-address-actions a,
button` / `.account-table a`) doing color/reset work that
`.btn-text` (added last session) already does. Replaced with
`class="btn btn-text"` and removed both now-redundant CSS rules from
`account.css`. Visual note: these were previously hand-set to 13px;
`.btn-text` inherits the button system's 16px base size instead -
intentional (matching the rest of the button family's type scale is
the point of "apply the common system"), flagged here so it's not a
silent surprise. Delete kept the same color as Edit (no red/danger
treatment applied) - deliberately conservative, since the original
CSS didn't distinguish them either and adding that distinction would
be a design decision beyond "replace page-specific styling with the
shared system."

**Login.php/register.php's error/success messages already used the
shared `.account-alert`/`.account-alert-error`/`.account-alert-success`
classes** - checked, nothing to fix there.

**Confirmed there is no customer-facing Forgot Password / Reset
Password flow** in this project - only an admin one exists. Nothing
to standardize there; noting it so it isn't assumed missing-but-
unfound in a future session.

**Not touched:** `.account-auth-box .btn-primary` and `.account-card
.btn-primary` in `account.css` - these already correctly assume
`.btn` is present and only add page-specific layout (width, margin,
alignment), which is exactly what "page-specific context on top of
the shared system" should look like. Left alone.

---

## [Unreleased] — Phase 4B: About Us grid overflow fixed, missing button variants added, footer issue re-investigated

**Correction (see the Phase 4B entry above, added later): "Phase 4B"
does now apply here** - at the time this entry was originally
written, `PROJECT_STATE.md`/`CHANGELOG.md` had no record of it under
any name, since the project's own history had labeled the prior
phase (Wishlist) "3E" rather than "4A". The numbering mismatch was
confirmed to be a prompt-labeling artifact, not a real discrepancy -
the project's own "3E"/"3F+" labels have since been reconciled to
"4A"/"4C+" to match the agreed roadmap, so this work correctly sits
under Phase 4B after all. Kept the original note below for an
accurate record of what was actually known at the time.

**No "Phase 4B" exists in this project's history** - the session that
produced this entry opened with a request framed as continuing a
previously-approved "Phase 4B" investigation/plan, but
`PROJECT_STATE.md`/`CHANGELOG.md` (this project's own source of
truth) have no record of it anywhere; the most recent actual prior
work is Phase 4A below. Worked from the codebase as it actually
stands rather than from an unverifiable prior plan.

**Fixed - About Us "Why Choose MoonAura" card overflow:** real root
cause found. `.about-why-grid` (`assets/css/about-us.css`) used
`grid-template-columns: repeat(4,1fr)` - bare `1fr` tracks have an
implicit `min-width: auto` (the content's min-content size), so at
viewports just above the 992px breakpoint the four padded cards
refused to shrink below their own content width and pushed past the
container. Matches the reported "overflow before the mobile
breakpoint" exactly. Fixed by changing to
`repeat(4,minmax(0,1fr))` - cards now shrink to fit (with normal
internal text wrap) instead of overflowing; behavior is unchanged
everywhere the grid already had enough room. `.about-why-card` and
the 768px/576px breakpoints untouched. Not visually verified by
render - `wkhtmltoimage` here is v0.12.6 (QtWebKit, pre-dates CSS
Grid support) and rendered the grid as stacked blocks regardless of
the CSS; confidence is from CSS spec reasoning, not a render. Worth a
real-browser check.

**Added - missing global button variants:** `.btn-secondary`,
`.btn-text`, `.btn-danger` added to `assets/css/style.css`, built on
the existing shared `.btn` base the same way `.btn-primary`/
`.btn-outline` already are. No new colors invented -
`.btn-secondary` uses the existing `--gold` variable, `.btn-danger`
reuses `#b3261e` (already the project's danger red in
`admin.css`'s `.admin-icon-btn-danger` and the invoice's
payment-status-failed/refunded badges). `.btn-text` overrides the
shared base's `min-width`/`height`/`padding` (needed - a text-style
button can't use the same 56px pill sizing as the filled variants)
but keeps the same color tokens/transition timing. Nothing existing
renamed or restructured.

**Investigated further, still unresolved - footer white strip:** two
more specific hypotheses checked and ruled out this session: (1)
`index.php`'s home-page-only `<main id="page-content">` wrapper has
zero associated CSS anywhere and nothing in the project's CSS selects
on DOM nesting depth, so it can't be silently breaking a
cascade-dependent rule; (2) no `100vw`/`50vw` usage anywhere in the
CSS (the classic scrollbar-width full-bleed gap bug). Still
genuinely unresolved - needs a screenshot or the exact page/browser/
viewport width to pin down.

**Not attempted - Shop page rework, full responsive/design-system
audit:** requested in the same message, but no approved spec for
either exists anywhere in this project's own records. Flagged for a
separate, scoped session rather than guessed at.

---

## [Unreleased] — Phase 4A UI regression pass: product-card action row restored, footer issue investigated

**Root cause of the regression - my own mistake, not a pre-existing
bug:** the previous Phase 4A session found `.btn-cart`/`.btn-buy`/
`.product-buttons` had zero CSS anywhere in the project, which was
true - but it then **misdiagnosed the fix**. Instead of recognizing
that the project already had a complete, working, responsive design
for this exact thing - `.product-actions`/`.cart-btn`/`.product-btn`
(an icon-only cart button paired with a solid "Buy Now" button,
already correctly used on `index.php` and `product.php`'s Related
Products section, full `@media` breakpoints included) - it invented a
new text-button style (`.btn-cart`/`.btn-buy`) from scratch. That
replaced the site's real, established icon-button convention with a
different-looking one on `shop.php` and the new `wishlist.php`,
without ever touching the actual "Add to Cart" markup itself (which
had always said "Add to Cart" as text, unstyled and easy to overlook,
until CSS made it suddenly prominent).

**Fixed - reverted to the project's real design:**
- `assets/css/home.css` - removed the invented `.product-buttons`/
  `.btn-cart`/`.btn-buy` block entirely (1.6KB, clean removal, verified
  byte-exact)
- `shop.php` / `wishlist.php` - action row markup changed from
  `.product-buttons` back to `.product-actions`; Add to Cart changed
  from a text button back to an icon-only `.cart-btn`
  (`<i class="fa-solid fa-cart-shopping">`, no text); Buy Now/"View"
  changed from `.btn-buy` to `.product-btn`
- The wishlist heart button itself (`.wishlist-btn`/`.wishlist-form`,
  positioned in the card's top-right corner) was correct from the
  first Phase 4A session and is untouched by either the mistake or
  this fix

**Verified this time by actually rendering the CSS**, not just
reading it - this sandbox has no PHP, but does have `wkhtmltoimage`
(no network needed). Rendered an isolated test card with the real,
corrected CSS: confirmed the icon cart button and "Buy Now" render at
matching height and sit vertically aligned (they were already designed
as a matched pair), and confirmed the wishlist heart still renders
correctly in both saved/unsaved states. Balance-checked every touched
file again post-revert.

**Footer white-strip issue - investigated, not resolved:** checked
`html`/`body` base styles, `.footer`'s box model, pseudo-elements,
`100vh`/`100vw` usage project-wide, and `header.php`'s tag balance -
found no CSS/HTML root cause. Neither `footer.php` nor `footer.css`
was touched by any Phase 4A work. Reconstructed an approximate real
page (actual header/footer markup, PHP tags stripped) and rendered it
with the real CSS at desktop and mobile widths - no white strip
appeared in either render. Given `wkhtmltoimage` is a different,
older rendering engine than a real browser, and the page
reconstruction is approximate, this doesn't rule the issue out - it
just means it couldn't be found or fixed with what's available here.
Needs a screenshot or specific repro details (page/browser/viewport)
to actually pin down - see `PROJECT_STATE.md` §4 for the full
investigation notes. Deliberately not "fixed" with a guessed change,
per the explicit instruction not to mask an unconfirmed issue.

---

## [Unreleased] — Phase 4A: Wishlist feature

**Design-language review first, as instructed:** reviewed
`about.php`/`policy.php`/`support.php` and their CSS
(`about-us.css`/`policy.css`/`support.css`) for page structure,
spacing, container width, typography, cards, buttons, colors, icons.
Concluded the Wishlist page is functionally closer to `cart.php` (a
personal "your saved things" utility page) than to a marketing page
like About Us, so its page-level structure (h1 + container, empty
state shape) deliberately copies `cart.php`'s values rather than the
About page's section/hero patterns - while the individual saved
items reuse `shop.php`'s exact product-card markup and
`assets/css/home.css` styling, so a saved product looks pixel-identical
to how it looks in the shop.

**Architecture decision - session-based, not account-linked:** the
wishlist lives in `$_SESSION['wishlist']`, deliberately mirroring
`includes/cart-functions.php`'s existing pattern exactly (same shape,
same "look up live product details for display" approach, same "no
login required" behavior) rather than introducing a new DB-backed,
customer-account architecture for a feature the project's own cart
already solves the identical problem for. See `PROJECT_STATE.md` §5
for the tradeoff this implies (doesn't survive across devices/cleared
cookies) and why a future account-linked version is a separate,
bigger feature, not attempted here.

**Discovery: the wishlist heart icon already existed, doing nothing.**
`shop.php`'s product-card markup already had
`<button class="wishlist-btn"><i class="fa-regular fa-heart"></i></button>`
- no CSS anywhere in the project, no form, no backend. This session
wired it up for real rather than building something new alongside it.

**New files:**
- `includes/wishlist-functions.php` - `wishlist_get()`, `wishlist_add()`,
  `wishlist_remove()`, `wishlist_toggle()`, `is_in_wishlist()`,
  `wishlist_count()`, `get_wishlist_items_with_details()` - function-
  for-function mirroring `cart-functions.php`'s own shape
- `wishlist-toggle.php` - POST endpoint (mirrors `cart-add.php`
  exactly, including its redirect-safety regex copied verbatim, not
  reimplemented)
- `wishlist.php` - the Wishlist page
- `assets/css/wishlist.css` - page-level structure only (`.wishlist-page`/
  `.wishlist-empty`/etc.) - values copied directly from `cart.css`'s
  equivalent rules, not re-derived

**Edited:**
- `shop.php` - the existing dead `.wishlist-btn` button is now a real
  form posting to `wishlist-toggle.php`, filled/outline heart icon
  reflecting actual saved state
- `product.php` - added a wishlist heart next to the existing Add to
  Cart/Buy Now buttons (this page had no wishlist placeholder at all
  before)
- `includes/header.php` - added a Wishlist icon (before Cart, same
  `.header-icon` treatment) with a count badge, reusing the existing
  `.cart-count-badge` class rather than adding a new one purely
  presentational duplicate
- `assets/css/home.css` - added `.wishlist-btn` styling (positioned to
  mirror `.product-badge`'s opposite corner, colors reuse
  `var(--primary)`/`var(--gold)` - no new colors introduced)

**Also fixed, found while reusing the product-card component this
session depends on:** `.product-buttons`/`.btn-cart`/`.btn-buy` had
**zero CSS anywhere in the project** (confirmed by `grep` across every
`.css` file, not assumed) - every product-card's action buttons were
rendering unstyled on every page that had them, including the ones
this session needed to reuse for the Wishlist page to look "premium."
Added minimal CSS reusing only already-established tokens
(`var(--primary)`/`var(--primary-dark)`, the same border-radius scale
and hover lift/shadow values already used by adjacent, apparently
orphaned `.product-btn`/`.cart-btn` rules from an earlier design
iteration - left those untouched, unrelated cleanup, not part of this
fix). This was not part of the Wishlist ask itself but directly
affects it, so it's called out explicitly rather than silently bundled
in.

**Deliberately out of scope, explained rather than silently skipped**
(see `PROJECT_STATE.md` §5 for the full reasoning on each):
- The heart icon was NOT added to `index.php`'s featured-products
  section or `product.php`'s "Related Products" section - both use an
  older, simpler product-card markup that never had a wishlist
  placeholder, and retrofitting one there is a bigger, separate
  decision than wiring up what already existed
- "Buy Now" remains a dead button everywhere (pre-existing site-wide,
  not a wishlist regression) - `wishlist.php`'s equivalent action is a
  working "View" link instead, since "Buy Now" on a saved-for-later
  item doesn't make sense as a direct-purchase action anyway
- No account-linked/DB-backed wishlist, no wishlist sharing, no "move
  all to cart" bulk action - none of these were asked for

**Verification:** static/parity-check only, same sandbox limitation as
every prior session (no PHP/MySQL runtime here) - every function call
cross-referenced against its definition, the `.wishlist-btn`/
`.btn-cart`/`.btn-buy` CSS gap confirmed by actually grepping (not
assumed), every touched file balance-checked. See `PROJECT_STATE.md`
§4 for the full Phase 4A verification block and the real-server
checklist.

---

## [Unreleased] — Phase 3D: Customer & Admin Experience review + polish

**Scope:** reviewed every Customer Account page (Dashboard, Orders,
Order Details, Saved Addresses, Profile, and the Order Success page)
and the Admin Panel (the newly-enabled Orders module + dashboard),
per the given priority order. Fixed real, identified gaps only - no
refactor of unrelated code, no admin redesign, no order editing/
status-change/refund/shipping UI added anywhere.

**Review findings - what was already complete (no changes made):**
`account/orders.php`, `account/addresses.php`, `account/profile.php`,
and `order-success.php` were all already fully functional, correctly
styled, and had no placeholder content - reviewed and left alone.

**Review findings - what was incomplete, and why:**
- `account/order-detail.php` silently dropped the `discount` line
  from the customer-facing total breakdown even though the column
  exists on `orders` and is used elsewhere (checkout, `order-success.php`,
  `admin/order-detail.php`) - simply never added when this page was
  first built. Also showed no Payment Method and no per-item unit
  price, and had no link back to the order list.
- `account/dashboard.php` was three static count cards and nothing
  else - not broken, just underbuilt for what a dashboard usually
  offers.
- `admin/dashboard.php` had a genuinely stale placeholder: its
  Products and Orders cards still read "Coming in the next/future
  phase" despite both modules being fully live (Products since Phase
  1A, Orders since Phase 3C) - nobody had revisited this page after
  either shipped.
- `admin/orders.php` had a search box but no way to filter by status,
  and no indication of how many orders were showing.
- `admin/order-detail.php` displayed `invoice_number` as plain text
  but gave admin no way to actually view or generate the invoice PDF -
  only the customer-facing, login-gated `account/invoice.php` existed.

**Changes made:**

1. **`account/order-detail.php`** - added a conditional Discount row
   (only shown when `> 0`, matching the pattern already used in
   `admin/order-detail.php`), a Payment Method row, unit price shown
   alongside quantity on each item line, and a "Back to Orders" link
   with the order date. No new queries - all fields were already
   being fetched.

2. **`account/dashboard.php`** - added a Recent Orders preview (last
   3, with a "View all N orders" link past that), reusing
   `orders.php`'s exact `.account-table`/`.account-badge` markup
   rather than introducing new styling.

3. **`admin/dashboard.php`** - Products and Orders placeholder cards
   now show live counts (`SELECT COUNT(*)` against `products`/`orders`)
   and link to their real pages, wrapped in `<a>` tags the same way
   `account/dashboard.php` already wraps its own summary cards - no
   new CSS. Customers card intentionally left as a placeholder, since
   that module doesn't exist yet. Updated the page's stale welcome
   copy and doc comment to match.

4. **`admin/orders.php`** - added an order-status filter dropdown
   (values allow-listed against the 5 real `order_status` enum values
   before reaching the query), combined with the existing search via
   `AND`, plus a result-count line. One new small CSS rule
   (`.admin-search-form select`) matching the existing
   `.admin-search-form input` styling.

5. **`admin/invoice.php`** (new) - a thin, read-only wrapper letting
   admin view/generate a customer's invoice PDF. Calls the exact same
   `get_or_create_invoice_number()`/`build_invoice_pdf()` that
   `account/invoice.php` and `guest-invoice.php` already use - no
   invoice logic duplicated. Linked from `admin/order-detail.php`'s
   existing Invoice row ("View Invoice" once generated, "Generate &
   View" before that). Added flash-message display
   (`.admin-alert`/`.admin-alert-error`, an existing pattern already
   used by `admin/categories.php`) to `admin/order-detail.php` for
   this endpoint's error path.

6. **Also fixed while cross-referencing this work**:
   `account/invoice.php`'s doc comment still said "guest-order invoice
   download is not wired up yet," which predated `guest-invoice.php`
   and was corrected to point at it (see the "Correction" entry
   below for the full guest-invoice-download trace this refers to).

**Verification:** static/parity-check only, same sandbox limitation as
every prior session (no PHP/MySQL runtime here) - every new/changed
field was cross-referenced against `database/schema.sql`, every
function call cross-referenced against its actual definition, every
touched file balance-checked. See `PROJECT_STATE.md` §4 for the full
Phase 3D verification block and the real-server checklist.

---

## [Unreleased] — Correction: guest invoice download IS fully implemented

A prior changelog/PROJECT_STATE.md entry (below) claimed guest invoice
download wasn't wired up because `account/invoice.php` never calls
`verify_guest_invoice_token()`. **That claim was wrong** - it only
checked `account/invoice.php` and missed that the guest flow lives in
a deliberately separate file, `guest-invoice.php` (project root), not
a branch inside `account/invoice.php`. No code was changed this
session - this is a documentation correction after re-tracing the
complete flow end-to-end on request:

`order-success.php` → `generate_guest_invoice_token($order['order_number'])`
(HMAC-SHA256 of `orderNumber|expiresAt`, 7-day default TTL, fails
closed/returns `null` if `INVOICE_TOKEN_SECRET` is unconfigured) →
Download/Print links built to
`guest-invoice.php?order=...&exp=...&sig=...&mode=...` (only rendered
when the token isn't null) → `guest-invoice.php` →
`verify_guest_invoice_token()` (recomputes the HMAC, checks expiry,
`hash_equals()` comparison; 403s with a clear message if invalid/
expired) → on success, calls the exact same `get_or_create_invoice_number()`
+ `build_invoice_pdf()` that `account/invoice.php` already uses -
invoice numbering/PDF rendering logic is not duplicated anywhere.
`account/invoice.php` is untouched and intentionally stays
login-only; it was never meant to be the guest entry point.

**Real, separate finding from this re-trace**: the feature is
currently **inactive on this project's `.env`** - `INVOICE_TOKEN_SECRET`
is present as a key but its value is empty, so both token functions
correctly fail closed (same "missing secret = unavailable, not
insecure" pattern as `CASHFREE_SECRET_KEY`) and the guest
Download/Print buttons simply don't render on `order-success.php`
right now. This is a configuration gap, not a code gap - `.env`
already has a comment with the exact command to generate a real value.

**Also stale, not corrected this session** (out of scope - this was a
documentation-only pass): `account/invoice.php`'s own doc comment
still says "guest-order invoice download is not wired up yet," which
predates `guest-invoice.php` and should be updated in a future code
session.

---

## [Unreleased] — Phase 3C (partial): Invoice header UI polish + Admin Orders module

**Scope note:** two specific, unrelated changes this session - a
visual-only invoice tweak and a new admin read-only Orders view.
Nothing else touched (no order editing/status changes, no refactor,
no unrelated cleanup).

**Documentation drift found at the start of this session:** this
project's `PROJECT_STATE.md`/`CHANGELOG.md` were out of date relative
to the actual code - `includes/lib/SimplePdfWriter.php`/
`includes/invoice-functions.php` already contained page-numbering
("Page X of Y") and guest-invoice-download token functions that
neither doc mentioned. Confirmed the page-numbering placeholder
(`{{TOTAL_PAGES}}`) does get correctly replaced in
`SimplePdfWriter::output()`. Confirmed the guest-token *verification*
function exists but **is not yet called anywhere** -
`account/invoice.php` still only has the logged-in path, so guest
invoice download is not actually usable yet despite the token
functions being present. Not fixed this session (out of scope for
both tasks given) - see `PROJECT_STATE.md` for the handoff note.

> **⚠ Correction (see the entry above, top of this file):** the
> "is not yet called anywhere" claim above is wrong - it only checked
> `account/invoice.php` and missed `guest-invoice.php`, a separate
> file where `verify_guest_invoice_token()` is in fact called. Guest
> invoice download was already fully wired at the time this entry was
> written; it just isn't active on this `.env` until
> `INVOICE_TOKEN_SECRET` has a real value.

**1. Invoice item-table header - MoonAura purple theme:**
- Added `SimplePdfWriter::filledRoundedRect()` - a new, additive
  method (rounded-rectangle fill via a hand-built cubic-Bezier path,
  since PDF has no native rounded-rect operator). Nothing existing in
  that class was changed.
- `build_invoice_pdf()`'s `$drawTableHeader` closure now fills the
  header row with brand purple (`#5B2E91`, the same `$purple` array
  already used elsewhere on the invoice) at a 4pt corner radius, and
  draws the column labels (`#`, `Item`, `Qty`, `Unit Price`,
  `Line Total`) in white instead of dark text. Column positions, row
  height, spacing, and font size are all unchanged - only the header
  row's own fill/text color and corner treatment changed. Data rows
  below it are untouched (still plain white background, dark text).
  Removed the now-unused light-lavender `$rowShade` variable that only
  the old header styling referenced.
- White-on-`#5B2E91` is a very high-contrast pairing, so it stays
  clearly readable both on-screen and printed (including a plain
  black-and-white print, where the purple still renders as a
  noticeably darker filled band behind light text).
- Verified via a Python line-for-line port of the exact same drawing
  calls, rendered with `poppler` and pixel-sampled: confirmed the
  exact brand purple fill (`RGB 91,46,145`) with white text pixels on
  top, and anti-aliased curved edges (not a plain rectangle). Re-ran
  the existing realistic multi-page parity test (12 items, long
  names, forced page break) through the change - pagination/wrapping/
  layout all still correct, only the header row's colors changed.

**2. Admin Orders module enabled:**
- Root cause: `admin/orders.php` didn't exist - the sidebar's Orders
  link was a hardcoded `<span class="admin-nav-disabled">`, not a
  feature flag or config check. There was no other blocker.
- New `admin/orders.php` - order list (order #, customer, date,
  total, payment status/method, order status), search by order
  number/customer name/email/phone. Follows `admin/products.php`'s
  existing list-page pattern exactly (same auth check, same
  search-form/table-card markup, no pagination - matching
  `products.php`, which also has none).
- New `admin/order-detail.php` - single-order view: order/payment/
  invoice info, customer + shipping address, itemized products table,
  totals breakdown. Looked up by `orders.id` (this page is already
  behind `require_admin_login()`, unlike the customer-facing guest
  invoice flow where exposing that id was explicitly disallowed).
- **Deliberately read-only** - no status editing, refunds, or
  shipping actions were added. That's the separate, not-yet-scoped
  "Order Timeline"/"Shipping Integration" work already listed in
  `PROJECT_STATE.md` §3; this session only made the existing order
  data visible, per the task's "implement only the missing pieces
  required to make it functional" scope.
- Sidebar link enabled (`orders.php`, highlights when active); its
  stale doc comment (still said "Orders... placeholders for later
  phases") updated to match.
- `admin/assets/css/admin.css`: extended the existing badge color
  groups with order/payment status keywords
  (paid/pending/failed/refunded/processing/shipped/delivered/cancelled),
  reusing the same green/red/amber visual language already established
  for product status badges rather than inventing new colors; added
  one new small `.admin-detail-row`/`.admin-table-subtext` class pair
  for the label/value rows used on the new detail page.
- Cross-checked every DB column referenced against `database/
  schema.sql` (orders/order_items/order_addresses/payment_transactions)
  and every function call (`require_admin_login()`, `current_admin()`,
  `format_price()`, etc.) against their actual definitions - all
  match. `admin/dashboard.php`'s placeholder "Orders - coming in a
  future phase" card was left untouched - it's stale for Products too
  (which has been live since Phase 1A), so fixing just the Orders
  wording there would be an inconsistent partial fix; noted rather
  than acted on, since dashboard copy wasn't part of what was asked.

**Verification:** static/parity-prototype only, same sandbox
limitation as every prior session (no PHP/MySQL runtime here) - see
`PROJECT_STATE.md` §4 for the specific real-server checklist,
including admin login + click-through and exercising every conditional
branch on the order-detail page (no address, COD vs. online payment,
invoice generated vs. not).

---

## [Unreleased] — Phase 3B: Invoice System (GST invoice, on-demand PDF)

Scope delivered exactly as requested: Invoice System, the on-demand
generation strategy, and the Customer Account UI - nothing else (no
Order Timeline, Shipping Integration, Email System, Admin
improvements, or UI redesign). Nothing touched: Payment Flow, Cashfree
integration, `PaymentManager`, gateway classes, `checkout.php`, or the
database schema (`invoice_number`/`invoice_generated_at` already
existed on `orders` since Phase 2A - no migration needed).

**New files:**
- `includes/lib/SimplePdfWriter.php` - dependency-free PDF 1.4 writer
- `includes/invoice-functions.php` - invoice number/date logic + PDF assembly
- `account/invoice.php` - download/print endpoint

**Edited:** `account/order-detail.php` (Invoice section added)

**Why on-demand generation instead of storing PDF files - the actual
architectural decision, and why:**

The PDF is rebuilt from `orders`/`order_items`/`order_addresses` on
every single request; no file is ever written to disk, and there is
no `invoices` table.
- The order snapshot is already the correct, immutable source of
  truth - `order_items` already freezes product name/price/GST exactly
  as purchased (a Phase 2A/2C decision, not new here), specifically so
  it stays correct even if the live product catalog later changes. A
  stored PDF would just be a second, less-correctable copy of that
  same information: if a rendering bug is ever found and fixed,
  every *stored* PDF ever generated would still show the bug, forever.
  Regenerating from the snapshot means every invoice - past or future
  - always reflects the current, correct renderer.
- No file storage, cleanup, orphan-file, or disk-space/permissions
  concern on deploy.
- A one-page PDF from ~10 database rows is cheap to build - well under
  what the page's own network round-trip already costs - so there's no
  real performance case for caching it as a file. (The one place
  generation isn't free is optional logo decoding - see the
  limitations note below.)
- `invoice_number`/`invoice_generated_at` are still stored, on
  `orders` directly, specifically because THAT is the one thing that
  must never change after first generation - and it's two small
  columns, not a file.

**Invoice number generation - lazy, transaction-safe:**
`get_or_create_invoice_number()` locks the order's own row
(`SELECT ... FOR UPDATE`) before deciding whether to generate - same
technique `generate_daily_sequence_number()` already used on
`daily_sequences`, applied here to `orders` instead. Without this
lock, two near-simultaneous requests for the same not-yet-invoiced
order (a doubled click, or Download and Print opened in two tabs at
once) could each read `invoice_number` as `NULL`, each generate a
*different* number, and race to write - the loser's number would be
silently discarded but still have consumed a slot in the day's
sequence. The lock makes the second request simply wait, then reuse
the number the first one just wrote.

**Customer Account UI (`account/order-detail.php`):** an Invoice
section was added. If `invoice_number` is already set: shows the
invoice number/date plus Download Invoice and Print Invoice buttons
(`.btn-primary`/`.btn-outline`, reused as-is - no new CSS, no UI
redesign). If not yet generated: shows a Download Invoice button
together with the specified note ("Your invoice will be generated
automatically when you download it for the first time.") - the button
itself is what triggers generation on click, exactly as required; a
literal reading that showed *only* the note with no way to click
"download... for the first time" would be a dead end, so the button is
present in both states, the note is what differs. Print Invoice opens
the PDF inline (`Content-Disposition: inline`) so the browser's own
PDF viewer handles printing; Download uses `attachment`. Same
ownership check as `order-detail.php` already uses
(`WHERE order_number = ? AND user_id = ?`) - scoped to logged-in
customers only this phase, matching the task's "Inside Order Details"
scope; guest-order invoice download is not wired up yet.

**Verification against six specific requirements (this session):**

Given no PHP interpreter exists in this authoring sandbox, each of the
following was verified by first building an exact line-for-line Python
port of the same algorithm, then transcribing to PHP - not by running
the actual PHP file. See `PROJECT_STATE.md` §4 for the full
methodology and the real-server checklist still needed.

1. **Identical every time it's downloaded** - confirmed: the same
   generation run twice produced byte-for-byte identical output
   (`cmp` showed no diff). Every value that reaches a drawing call
   comes from the stored order snapshot or from settings - nothing
   reads the current time, a random ID, or anything else that changes
   between requests. (Business *settings* changing between two
   downloads - e.g. an admin correcting the phone number - will
   legitimately change future output; documented in
   `includes/invoice-functions.php` and `PROJECT_STATE.md` as
   intentional, not a determinism bug: unlike product/price data, the
   seller's own current contact details are correctly "live," not
   part of what the customer purchased.)
2. **Long customer names/addresses/product names wrap correctly** -
   added `SimplePdfWriter::wrapText()`/`wrappedText()`
   (greedy word-wrap) and used them for the Billed To/Ship To blocks
   and each item's product name; item row height is now computed per
   row from how many lines the name actually wrapped to. Verified
   with a deliberately long name, a long-domain email, and a
   four-line shipping address - none overran their column in the
   rendered output.
3. **Multi-page invoices repeat the header automatically** - added
   `SimplePdfWriter::setPageHeaderCallback()`, fired automatically by
   `newPage()` (triggered by `ensureSpace()`); `build_invoice_pdf()`
   registers its letterhead-drawing closure once and it now redraws
   on every continuation page with no per-call bookkeeping required.
   Verified with 12 items (long names) forcing a second page - the
   redrawn letterhead measured pixel-identical (~0.6/255 mean
   difference, i.e. antialiasing noise only) to page 1's.
4. **Supports future branding (e.g. a logo) without an architecture
   change** - `SimplePdfWriter::registerImageJpeg()`/`drawImage()` are
   real, exercised primitives (JPEG embedding, with an optional
   grayscale JPEG as `/SMask` for transparency), not stubs.
   `register_invoice_logo()` demonstrates the intended usage today,
   defensively: it attempts to load this project's actual header logo
   via PHP's GD extension. **This surfaced a genuinely useful
   discovery**: every image asset in this project, despite `.png`/
   `.jpg`-looking filenames, is actually WebP by file signature
   (verified project-wide, not just the one file used here) - so a
   real logo won't render unless the server's GD build includes WebP
   read support, or a true JPEG/PNG export is dropped in instead. This
   is now documented as the most likely reason `register_invoice_logo()`
   returns null on a given host. Verified via the parity prototype: a
   JPEG color image plus a JPEG alpha mask embed and render correctly
   (composited transparency, correct brand-purple pixels sampled from
   the output, no black box or missing image).
5. **All business info comes from configurable settings, not
   hardcoded values** - `get_invoice_business_details()`
   (`includes/invoice-functions.php`) reads `business_name`,
   `business_trade_name`, `business_gstin`, `business_address`,
   `business_phone`, `business_email` via `get_setting()`. Four of the
   six default to real values already published on this site's own
   Support page if unset (not invented placeholders);
   `business_trade_name` and `business_gstin` have no real value
   anywhere in the codebase, so those two intentionally have no
   default and print as absent/"Not configured" until an admin sets
   them - **no GSTIN was fabricated**. There is no Settings admin UI
   yet (out of scope this phase), so today setting any of these is a
   direct `INSERT INTO settings ...` statement - see
   `PROJECT_STATE.md` §5.
6. **Documented limitations of the custom PDF engine vs. a standard
   library** - written up in full in `PROJECT_STATE.md` §5
   ("Invoice PDF engine... known limitations"): estimated (not
   metrics-accurate) text width, no hyphenation/justification, no
   embedded/non-Latin fonts, JPEG-only image embedding at the PDF
   level (with the GD/WebP dependency above), shared single
   `/Resources` dictionary, no PDF metadata (deliberate - see
   determinism above), no page numbers, no PDF/A/encryption/signing,
   and the per-pixel cost of alpha-mask extraction for a transparent
   logo.

---

## [Unreleased] — Phase 3A: Cashfree added as the active gateway (Razorpay kept, inactive)

**Runtime status:** confirmed fully working end-to-end on a real
server, per your report (this session) - see `PROJECT_STATE.md` §4.

**New gateway:** `CashfreeGateway` (`includes/payments/`), fully
implementing `PaymentGatewayInterface`, with the same
`isConfigured()`/`PaymentConfigurationException` safety pattern as
Razorpay. Registered in `PaymentManager` alongside Razorpay - both are
always available; `active_payment_gateway` in the `settings` table
now defaults to `'cashfree'`. Razorpay's code
(`RazorpayGateway.php`, `webhook-razorpay.php`) is untouched
functionally and stays fully working - switching back is a single
`UPDATE settings ...` statement, no code change, no redeploy.

**Three things work meaningfully differently for Cashfree than
Razorpay, verified against Cashfree's own API documentation before
implementing (not assumed to mirror Razorpay's pattern):**
1. **Mode is a different base URL** (`sandbox.cashfree.com` vs.
   `api.cashfree.com`), not just a different key prefix -
   `CASHFREE_MODE` picks which. Still "switchable via configuration
   only", just not implicit the way Razorpay's key is.
2. **No signed client-side callback exists.** Cashfree's checkout
   widget doesn't hand back a cryptographic proof to verify locally.
   `CashfreeGateway::verifyPayment()` instead makes its own
   authenticated API call back to Cashfree to confirm the order's
   real status - Cashfree's own recommended pattern, arguably more
   robust than trusting a client-supplied signature.
3. **Webhook signing needs two headers**, not one: HMAC-SHA256 of
   `<timestamp><raw body>` (Razorpay: just the raw body), base64
   (Razorpay: hex), using the **same client secret as API auth**
   (confirmed via Cashfree's docs - unlike Razorpay, there is no
   separate webhook secret for Cashfree).

**Architecture change required to support #3 - `PaymentGatewayInterface::handleWebhook()`
widened from `(string $rawPayload, string $signatureHeader)` to
`(string $rawPayload, array $headers)`.** This is additive, not a
narrowing: each gateway's webhook endpoint file builds whatever
headers *that* gateway's signing scheme needs (Razorpay:
`['signature' => ...]`; Cashfree: `['signature' => ..., 'timestamp' => ...]`),
and each gateway class reads only the keys it understands.
`PaymentManager` and `checkout.php` never inspect these keys - the
change is fully contained within `includes/payments/`. Also formalized
`isConfigured(): bool` as part of the required interface contract
(both gateways already implemented it as a convention; now it's
enforced).

**Bug caught and fixed during implementation:** `checkout.php`'s
payment method radio button had `value="razorpay"` hardcoded. Left
as-is, this would have stored the wrong `payment_method` on every
order the moment Cashfree became the active gateway (the order would
say "razorpay" while `PaymentManager` actually processed it via
Cashfree) - a real functional bug, not cosmetic. Fixed by reading
`PaymentManager::getActiveGatewayName()` instead of hardcoding a
gateway name; the "Pay Online" label is now generic (doesn't name a
specific processor).

**`payment.php`/`payment-verify.php`/`payment.js` made fully
gateway-agnostic:** the JSON config embedded in `payment.php` now
carries a `gateway` field plus both gateways' specific fields (unused
ones are simply `null`); the correct external SDK script
(`checkout.razorpay.com` vs `sdk.cashfree.com`) loads conditionally
based on which gateway is actually active; `payment.js` dispatches to
`openRazorpayCheckout()` or `openCashfreeCheckout()` based on
`config.gateway`, converging on the same generic
`submitPaymentVerification()`; `payment-verify.php` forwards all POST
fields through generically instead of reading Razorpay-specific field
names, letting each gateway class pick out what it needs.

**Database changes:**
- `settings` seed default changed: `active_payment_gateway` is now
  `'cashfree'` (was `'razorpay'`)
- Migration: `database/migration_phase3a_switch_to_cashfree.sql` (for
  databases that already had Phase 3A's tables with the old default)

**Files created:** `includes/payments/CashfreeGateway.php`,
`webhook-cashfree.php`,
`database/migration_phase3a_switch_to_cashfree.sql`

**Files modified:** `includes/payments/PaymentGatewayInterface.php`
(widened `handleWebhook()`, formalized `isConfigured()`),
`includes/payments/PaymentManager.php` (registered Cashfree, default
gateway fallback), `includes/payments/RazorpayGateway.php`
(`handleWebhook()` signature only - internal logic unchanged),
`webhook-razorpay.php` (builds a headers array now), `payment.php`,
`payment-verify.php`, `assets/js/payment.js`, `checkout.php` (the
hardcoded-gateway-name bug fix above), `includes/order-functions.php`
(docblock only), `config/config.php` (Cashfree credentials via
`env()`), `database/seed.sql`, `.env.example`, `SETUP.md`

**Verification:** Static only, same sandbox limitation as every prior
phase - no PHP/MySQL/network available here, and Cashfree's API
obviously cannot be called from this authoring environment. The
webhook payload field names in `CashfreeGateway::handleWebhook()`
were implemented from Cashfree's published documentation, not tested
against a live payload - **confirm these against a real webhook
delivery (Cashfree Dashboard > Developers > Webhooks > logs) before
relying on this in production.**

---

## [Unreleased] — Phase 3A Hotfix: graceful handling of unconfigured Razorpay credentials

**Problem:** a fresh development environment with no Razorpay account
yet (`RAZORPAY_KEY_ID`/`RAZORPAY_KEY_SECRET` empty in `.env`) hit the
generic `payment.php` error "Could not start the payment process.
Please try again." with no indication that credentials were simply
missing - indistinguishable from a real outage.

**Fix:**
- New `PaymentConfigurationException` (`includes/payments/`) - a
  distinct exception type from the generic `RuntimeException` a real
  API/network failure throws.
- `RazorpayGateway::__construct()` now checks
  `RAZORPAY_KEY_ID`/`RAZORPAY_KEY_SECRET` are both non-empty and
  throws `PaymentConfigurationException` immediately if not - before
  any cURL call is made. `RazorpayGateway::isConfigured(): bool` is
  the reusable check.
- `payment.php` catches `PaymentConfigurationException` separately
  and shows its message directly: "Razorpay Test API credentials are
  not configured. Please configure your Razorpay Test Keys or use
  Cash on Delivery." (safe to display - never contains a key/secret
  value). Real gateway/network errors still fall through to the
  existing generic message, with the actual exception still
  `error_log()`'d either way.
- Cash on Delivery is entirely unaffected either way - `checkout.php`
  never touches `PaymentManager`/`RazorpayGateway` for a `cod` order,
  so COD works normally whether or not Razorpay is configured.

---

## [Unreleased] — Phase 3A Hotfix: `settings` table missing at checkout

**Bug:** `Proceed to Checkout` crashed with `SQLSTATE[42S02]: Base
table or view not found: 1146 Table 'moonaura.settings' doesn't
exist` instead of loading the payment page.

**Root cause:** `database/schema.sql` and `database/seed.sql` already
create and seed the `settings` table correctly for any *fresh*
install (verified - table 12 in `schema.sql`, seeded with
`active_payment_gateway`/`cod_enabled` in `seed.sql`). The table only
goes missing on a database that was created *before* Phase 3A and
never had `database/migration_phase3a_payments.sql` applied (see
`SETUP.md` §6, step 5) - that migration is what retroactively adds
`settings`/`payment_transactions` to an existing install. On top of
that, `get_setting()` had no error handling: a missing table raised
an uncaught `PDOException`, turning a recoverable situation into a
hard fatal instead of falling back to the safe defaults it already
accepts as a second argument.

**Fix:**
- `get_setting()` (`includes/settings-functions.php`) now catches
  `PDOException` and returns the caller's `$default` (logging the
  underlying error with `error_log()` for diagnosis) instead of
  letting the exception propagate. Both existing callers already pass
  sensible defaults (`cod_enabled` → `'1'`, `active_payment_gateway`
  → `'razorpay'`), so checkout keeps working (COD available, Razorpay
  assumed active) even if the settings store is temporarily
  unreachable or not yet migrated.
- No `schema.sql`/`seed.sql` changes were needed - both already
  satisfy "fresh install works with zero manual SQL." If you're
  hitting this on an *existing* database, run
  `database/migration_phase3a_payments.sql` once to add the missing
  table (see `SETUP.md` §6, step 5).

---

## [Unreleased] — Phase 3A: Razorpay + Cash on Delivery Payment Integration

**New features:**
- `PaymentManager` abstraction (`includes/payments/`) - a
  `PaymentGatewayInterface`, `RazorpayGateway` (raw cURL, no
  Composer), and `PaymentManager` facade. Adding a future gateway
  means one new class + one line in `PaymentManager`'s registry -
  `checkout.php` never changes
- Active gateway is read from a new generic `settings` key-value
  table (`get_setting()`/`set_setting()`), not a hardcoded constant -
  ready for a future Admin Settings UI with zero code changes
- Checkout now offers a payment method choice: Razorpay (online) or
  Cash on Delivery (toggleable via the `cod_enabled` setting)
- Full Razorpay flow: order creation → `payment.php` (Checkout.js
  widget) → `payment-verify.php` (client-callback signature
  verification, fast path) + `webhook-razorpay.php` (server-to-server,
  authoritative, idempotent) → `order-success.php`
- Failed/cancelled payments → `payment-failure.php` → Retry (creates
  a fresh gateway order against the *same* internal order, no new
  `order_number`)
- `update_order_payment_status()` (`includes/payment-functions.php`)
  is the single function that ever writes `orders.payment_status`/
  `order_status` - both the Razorpay paths and COD call it directly,
  so COD needed zero architectural changes beyond calling an
  already-designed function
- `payment_transactions` table - one-to-many with `orders` (a retried
  payment adds a new row rather than overwriting), doubling as a full
  transaction log
- `order-success.php` now gated on payment actually being settled
  (`payment_status = 'paid'` or `payment_method = 'cod'`) - a
  Razorpay order still sitting `'pending'` redirects to `payment.php`
  instead of showing a false confirmation

**Behavioral change from Phase 2C:** the cart is no longer cleared at
order creation for online payments - only once payment is confirmed.
A failed/abandoned Razorpay payment leaves both the order and the
cart intact for a retry. (Cash on Delivery still clears the cart
immediately, as it did before, since there's no payment step to wait
for.)

**Security:**
- Payment signature verification: `hash_hmac('sha256', "order_id|payment_id", key_secret)`,
  compared with `hash_equals()`
- Webhook signature verification: `hash_hmac('sha256', $rawPayload, webhook_secret)`
  against the raw request body - the webhook endpoint intentionally
  does not use this project's session-based CSRF check (Razorpay's
  servers have no session/cookie); its authenticity is the signature
  alone
- Found and fixed during implementation: the Razorpay config JSON
  embedded in `payment.php` includes customer-supplied name/email/
  phone inside a `<script>` tag - without `JSON_HEX_TAG` a crafted
  customer name containing `</script>` could have broken out of the
  tag (stored XSS). Fixed with
  `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`; the same
  fix applied to `product.php`'s JSON-LD for consistency (lower risk
  there - admin-controlled data only)
- `payment.php`/`payment-verify.php`/`payment-retry.php`/
  `payment-failure.php` authorize access via
  `customer_or_session_owns_order()` - the same browser session that
  created the order, OR a logged-in customer who owns it (lets a
  registered customer return later to finish/retry payment even after
  the session-only flag has aged out)

**Database changes:**
- New tables: `settings`, `payment_transactions`
- `create_order()` signature changed: now accepts `$paymentMethod`
  and returns `['id' => int, 'order_number' => string]` instead of
  just the order number string (its only caller, `checkout.php`, was
  updated to match)
- Migration: `database/migration_phase3a_payments.sql`

**Files created:** `includes/settings-functions.php`,
`includes/payment-functions.php`,
`includes/payments/PaymentGatewayInterface.php`,
`includes/payments/PaymentManager.php`,
`includes/payments/RazorpayGateway.php`, `payment.php`,
`payment-verify.php`, `payment-retry.php`, `payment-failure.php`,
`webhook-razorpay.php`, `assets/js/payment.js`,
`database/migration_phase3a_payments.sql`

**Files modified:** `checkout.php` (payment method selection,
post-order-creation branching), `order-success.php` (payment-settled
gate), `includes/order-functions.php` (`create_order()` signature,
new `get_order_by_number()`), `product.php` (JSON-LD escaping
hardening), `config/config.php` (Razorpay credentials via `env()`),
`database/schema.sql`, `database/seed.sql`, `assets/css/checkout.css`,
`.env.example`, `SETUP.md`

**Verification:** Static only - no PHP/MySQL/network available in the
authoring sandbox, and Razorpay's API obviously cannot be called from
here. Needs, at minimum before considering this phase done: one
successful test-mode Razorpay payment end-to-end, one COD order
end-to-end, one failed/cancelled payment → retry → success, and
webhook delivery confirmed (requires a tunnel for local testing).

---

## [Unreleased] — Checkout confirmed working; debug line removed

Both schema-drift repair migrations below are now confirmed applied
on the working database, and a full checkout run completes
successfully end-to-end. The temporary `ENVIRONMENT === 'development'`
debug block in `checkout.php` (added to surface the real exception
while investigating) has been removed - the customer-facing catch
block now only logs server-side via `error_log()` and shows the
generic "Something went wrong" message, as originally intended.

**Files modified:** `checkout.php`

---

## [Unreleased] — Repair migration: `order_addresses.landmark` column missing (schema drift, found during checkout runtime verification)

**Bug found:**
- With the `daily_sequences` fix applied (see the entry below) and
  confirmed on a real server, checkout progressed further and then
  failed with `SQLSTATE[42S22]: Column not found: 1054 Unknown column
  'landmark' in 'field list'`.
- Root cause, found by tracing every `order_addresses` reference
  project-wide: `order_addresses` was originally created by
  `migration_add_orders.sql` (Phase 2A) **without** a `landmark`
  column. That column was added later, in Phase 2C, by
  `migration_add_order_number_sequences_and_landmark.sql`'s
  `ALTER TABLE order_addresses ADD COLUMN landmark ...` - a plain
  `ALTER TABLE ADD COLUMN` with **no idempotency guard**. `schema.sql`
  has included `landmark` on `order_addresses` ever since, and
  `create_order()` (`includes/order-functions.php`) has been writing
  to it ever since - it's the only INSERT/UPDATE anywhere in the
  codebase that touches `order_addresses` (the other two references,
  `account/order-detail.php` and `order-success.php`, are both
  `SELECT *`, unaffected by the missing column). A database whose
  `order_addresses` came from `migration_add_orders.sql` but never
  had that later `ALTER TABLE` applied to it is left without
  `landmark` - same class of schema-drift gap as the `daily_sequences`
  issue below, just a different migration that was skipped.
- Checked every other `ALTER TABLE` in every migration file for a
  similar risk before concluding this was the only remaining gap on
  the order-creation path: `migration_add_alt_text.sql`
  (`product_images.alt_text`) is unrelated to checkout/orders;
  `migration_add_phase2d_accounts.sql`'s `orders` →
  `fk_orders_customer` constraint is already covered by
  `migration_add_phase2d_accounts_repair.sql`. Diffed
  `order_addresses`'s full column list between
  `migration_add_orders.sql` and `schema.sql`: `landmark` is the only
  column that differs.
- No code in `checkout.php` or `includes/order-functions.php` was
  changed - the INSERT statement was already correct against
  `schema.sql`; this is purely a data-definition gap on the specific
  database being tested against.

**Database changes:**
- New file `database/migration_add_order_addresses_landmark_repair.sql`
  - idempotent repair migration, same pattern as the other two repair
  migrations in this project: conditional `ALTER TABLE ... ADD COLUMN`
  (only runs if `order_addresses` exists and `landmark` isn't already
  on it, via the same `PREPARE`/`EXECUTE` technique as
  `migration_add_phase2d_accounts_repair.sql`'s FK step), with
  before/after `INFORMATION_SCHEMA` verification output. Never drops
  or alters anything that already exists.

**Files created:** `database/migration_add_order_addresses_landmark_repair.sql`

**Verification:** Applied and confirmed on a real server - checkout
now completes successfully end-to-end (order + order_items +
order_addresses, including `landmark`). The temporary DEBUG line
added to `checkout.php` during investigation has been removed.

---

## [Unreleased] — Repair migration: missing `daily_sequences` table blocking checkout

**Runtime status:** Applied and confirmed working on a real server -
checkout now progresses past order-number generation. (See the entry
above for the next issue this surfaced.)

**Bug investigated:**
- Checkout was failing for every order attempt with the generic
  "Something went wrong while placing your order" message (this is
  what the temporary DEBUG line added in `checkout.php` was there to
  diagnose - see the `ENVIRONMENT === 'development'` block).
- Root cause (identified via static trace of `create_order()` →
  `generate_order_number()` → `generate_daily_sequence_number()` in
  `includes/order-functions.php`): those functions require the
  `daily_sequences` table, which `schema.sql` has included since the
  "Phase 3 Pre-Implementation Fixes" order-numbering rewrite. A
  database that was provisioned before that change, and never had
  `migration_phase3_daily_sequences.sql` applied to it, is missing
  that table entirely, so every `INSERT INTO daily_sequences ...`
  fails with `SQLSTATE[42S02]` - caught by `create_order()`'s
  try/catch, which rolls back and rethrows, surfacing to the customer
  as the generic checkout failure.
- No other defect was found in the checkout → order-creation path
  during this review: `orders`/`order_items`/`order_addresses` column
  lists, placeholder counts, and bound-value counts in
  `includes/order-functions.php` were all checked one-by-one against
  `database/schema.sql` and match exactly; `includes/db.php` throws on
  DB errors (`PDO::ERRMODE_EXCEPTION`) so `checkout.php`'s try/catch
  around `create_order()` correctly catches them.

**Database changes:**
- New file `database/migration_phase3_daily_sequences_repair.sql` -
  idempotent repair migration, same pattern as
  `migration_add_phase2d_accounts_repair.sql`: `CREATE TABLE IF NOT
  EXISTS daily_sequences` (never touches the table if it already
  exists, never alters/drops anything else), with before/after
  `INFORMATION_SCHEMA` verification output. Unlike
  `migration_phase3_daily_sequences.sql`, it does not assume or drop
  the legacy `order_number_sequences` table - it only reports that
  table's presence for visibility and is scoped strictly to repairing
  `daily_sequences`.

**Files created:** `database/migration_phase3_daily_sequences_repair.sql`

**Verification:** Static only. This authoring sandbox has no PHP,
MySQL/MariaDB, or outbound network access available (confirmed:
`apt-get install php mariadb-server` and `pip install` both fail with
403/no route - consistent with the runtime-verification gap already
noted project-wide in `PROJECT_STATE.md` §4), so the migration could
not actually be applied against a live database, and no real checkout
attempt (placing a test order, confirming `generate_order_number()`
returns a value, confirming the order row was saved) could be run
end-to-end in this session. **The temporary DEBUG line in
`checkout.php` has deliberately NOT been removed** - the task
required removing it only after genuine runtime verification, and
that verification did not happen here. See `PROJECT_STATE.md` §4/§6
for the exact steps needed to close this out on a real server.

---

## [Unreleased] — Bugfix: Shop page MySQL 8 incompatibility

**Bug fixed:**
- `includes/product-functions.php` - `get_shop_categories()` used
  `SELECT DISTINCT ... ORDER BY c.display_order` where
  `display_order` wasn't in the SELECT list. MySQL 8 rejects this
  combination (`SQLSTATE[HY000] error 3065`). Fixed by replacing
  `SELECT DISTINCT` with `GROUP BY c.id` (grouping by the table's
  primary key is MySQL's own documented `ONLY_FULL_GROUP_BY`
  exception) - same result set, same ordering, no `sql_mode` changes,
  no workaround.

**Files modified:** `includes/product-functions.php`

**Verification:** Static only (no MySQL/PHP runtime available in the
authoring session) - needs confirmation on a real server.

---

## Phase 3 — Pre-Implementation Fixes (before Phase 3A)

**Security hardening:**
- Session cookies now set `httponly`, `samesite=Lax`, and
  auto-detected `secure` (previously unset)
- `destroy_session()` added (`includes/functions.php`) - full session
  wipe + cookie expiry + fresh session, replacing a partial
  unset-one-key logout in both `admin_logout()` and `customer_logout()`
- Guest order linking hardened: `normalize_mobile_number()` and
  `normalize_email()` added and applied at every phone/email
  storage/comparison point, so differently-formatted entries of the
  same real number/address now actually match for linking purposes
- `create_order()` now `error_log()`s the real exception before
  rollback+rethrow (previously silent server-side on failure)

**Order/Invoice numbering rewritten:**
- Old: `MOA-<year>-<6-digit>`, yearly reset, single `order_number_sequences` table
- New: Order `MOAOD<YYYYMMDD><4-digit>`, Invoice `MOAINV<YYYYMMDD><4-digit>`,
  both reset **daily**, tracked independently in a new `daily_sequences` table
- `generate_invoice_number()` added and ready, but not called anywhere
  yet - invoice generation itself remains a future phase

**Dead code removed:**
- Commented-out `<script src="assets/js/shop.js">` in `shop.php`
  (file never existed)
- Stale example comments in `schema.sql` referencing the old order/
  invoice number format

**Regression found and fixed:**
- `product.php`, `cart.php`, `checkout.php`, `order-success.php` were
  all missing `assets/js/main.js` - the header's mobile menu button
  did not work on any of these four pages

**Database changes:**
- New table `daily_sequences` (replaces `order_number_sequences`)
- Migration: `database/migration_phase3_daily_sequences.sql`

**Files modified:** `config/config.php`, `includes/functions.php`,
`includes/auth.php`, `includes/customer-auth.php`,
`includes/customer-functions.php`, `includes/order-functions.php`,
`admin/setup.php`, `account/register.php`, `account/profile.php`,
`account/address-form.php`, `shop.php`, `product.php`, `cart.php`,
`checkout.php`, `order-success.php`, `database/schema.sql`

**Files created:** `database/migration_phase3_daily_sequences.sql`

---

## Phase 2D — Customer Accounts + Admin Forgot Password

**New features:**
- Customer registration, login, logout, password hashing (bcrypt)
- Guest order → account linking by email/mobile
  (`link_guest_orders_to_customer()`)
- My Account: dashboard, order history, order detail (strictly
  scoped to the logged-in customer), saved addresses (full CRUD),
  profile update, change password
- `checkout.php`/`create_order()` link a logged-in customer's order
  immediately at creation time instead of waiting for next login
- Admin Forgot Password: one-time hashed reset token, 60-minute
  expiry, single-use, reset password page. Email delivery itself is
  out of scope project-wide, so the link is displayed on-screen as an
  explicit placeholder

**Bug found and fixed during this phase:**
- `includes/header.php`/`footer.php` used relative links/image paths
  that only worked from root-level pages - broke the moment they were
  included from the new one-level-deep `/account/` folder. Added
  `site_url()` helper and converted every internal link/image
  reference in header/footer to use it
- 7 of 11 new `/account/` pages were missing `main.js` (mobile menu
  non-functional) - fixed

**Database changes:**
- New tables: `customers`, `customer_addresses`, `admin_password_resets`
- `orders.user_id` finally got its real FK → `customers(id)`
  (`ON DELETE SET NULL`), as planned back in Phase 2A

**Files created:** `includes/customer-auth.php`,
`includes/customer-functions.php`, `account/register.php`,
`account/login.php`, `account/logout.php`, `account/dashboard.php`,
`account/orders.php`, `account/order-detail.php`,
`account/addresses.php`, `account/address-form.php`,
`account/address-delete.php`, `account/profile.php`,
`account/change-password.php`, `account/includes/account-nav.php`,
`assets/css/account.css`, `admin/forgot-password.php`,
`admin/reset-password.php`, `database/migration_add_phase2d_accounts.sql`

**Files modified:** `database/schema.sql`,
`includes/order-functions.php`, `checkout.php`, `admin/login.php`,
`includes/header.php`, `includes/footer.php`, `includes/functions.php`

**Post-phase incident:** the Phase 2D migration was not fully applied
on the working database (`customers` table missing at runtime,
`SQLSTATE[42S02]`). Root cause was an incomplete migration
application, not a code defect. Resolved via
`database/migration_add_phase2d_accounts_repair.sql` (idempotent -
checks `INFORMATION_SCHEMA` before creating/altering anything) and
`database/verify_phase2d_state.sql` (read-only diagnostic). Both
files are kept in the project for reference. Post-repair, runtime
verification confirmed: registration, auto-login, login, logout,
dashboard, duplicate email/phone validation, password hashing.

---

## Phase 2C — Checkout

**New features:**
- `checkout.php` (GET shows form + order summary, POST validates and
  creates the order) - guest checkout only
- Server-side validation: required fields, email format, mobile
  format (India-specific, isolated for future swap), PIN code format
- Every product re-validated live (exists + active) via
  `get_cart_items_with_details()`; prices always read fresh from the
  database, never trusted from session or browser
- Transaction-safe order creation: `orders` → `order_items`
  (full product snapshot) → `order_addresses`, using
  `SELECT ... FOR UPDATE` row-locked order number generation
- `order-success.php` gated by session match
  (`$_SESSION['last_order_number']`) so an order confirmation can't
  be viewed by guessing/incrementing the order number in the URL

**Database changes:**
- New tables: `order_number_sequences` (superseded in Phase 3),
  `order_addresses.landmark` column added
- Migration: `database/migration_add_order_number_sequences_and_landmark.sql`

**Files created:** `checkout.php`, `order-success.php`,
`assets/css/checkout.css`

**Files modified:** `includes/order-functions.php` (rewritten),
`database/schema.sql`, `cart.php`, `assets/css/cart.css`

---

## Phase 2B — Session Cart

**New features:**
- Session-only cart (`$_SESSION['cart']`), no database table
- Add/update/remove, header cart-count badge, cart page with live
  pricing and unavailable-item handling
- Quantity clamped server-side to 1-99 in both `cart_add()` and
  `cart_update_quantity()`
- Product validated as existing + active before ever being added
  (`product_is_available_for_cart()`)

**Files created:** `includes/cart-functions.php`, `cart-add.php`,
`cart-update.php`, `cart-remove.php`, `cart.php`, `assets/css/cart.css`

**Files modified:** `includes/header.php`, `assets/css/header.css`,
`index.php`, `shop.php`, `product.php`

---

## Phase 2A — Commerce Foundation

**Database changes:**
- New tables: `orders`, `order_items`, `order_addresses`
- Guest checkout supported by design (`orders.user_id` nullable, no
  FK yet - added later in Phase 2D once `customers` existed)
- Order items store a full product snapshot so future product edits
  never alter historical orders

**Files created:** `includes/order-functions.php` (initial version -
`generate_order_number()`, count-based at this point)

---

## Phase 1C — Dynamic Product Detail Page

**New features:**
- `product.php` (by slug), image gallery with vanilla-JS thumbnail
  swap, breadcrumb, related products (same category, 4 max), dynamic
  SEO meta tags + JSON-LD Product schema, proper 404 for invalid slugs

**Files created:** `product.php`, `assets/css/product.css`,
`assets/js/product.js`

**Files modified:** `includes/product-functions.php`

---

## Phase 1B — Dynamic Homepage + Shop

**New features:**
- Homepage's Career Success Collection and the Shop product grid both
  converted from hardcoded HTML to live database queries
- Category filter, price filter, sort, pagination on the Shop page

**Files created:** `includes/product-functions.php`

**Files modified:** `index.php`, `shop.php`, `database/schema.sql`
(product_images.alt_text)

---

## Phase 1A — Admin Products & Categories CRUD

**New features:**
- Full Categories CRUD, full Products CRUD, image upload/management
  (JPG/PNG/WEBP validation, gallery, set-primary, delete)

**Database changes:** new table `product_images`

**Files created:** `admin/categories.php`, `admin/category-form.php`,
`admin/category-delete.php`, `admin/products.php`,
`admin/product-form.php`, `admin/product-delete.php`,
`admin/product-image-delete.php`, `admin/product-image-set-primary.php`,
`admin/includes/image-upload-handler.php`

**Files modified:** `database/schema.sql`, `includes/functions.php`,
`admin/assets/css/admin.css`, `admin/includes/admin-sidebar.php`

---

## Phase 0 — PHP/MySQL Foundation

**New features:**
- Config (`.env`-ready), PDO connection, general helpers, CSRF
  helpers, admin session auth
- `header.html`/`footer.html` converted to PHP includes; all pages
  renamed `.html` → `.php`
- Admin login/dashboard shell (foundation only)

**Database changes:** initial schema - `categories`, `products`,
`collections`, `collection_products`, `admin_users`

**Files created:** `config/config.php`, `includes/db.php`,
`includes/functions.php`, `includes/auth.php`, `includes/header.php`,
`includes/footer.php`, `database/schema.sql`, `database/seed.sql`,
`admin/*` (setup/login/logout/dashboard + includes), `.gitignore`,
`.env.example`, `SETUP.md`

**Files archived (not deleted):** original static `.html` pages and
`components/header.html`/`footer.html` → `archive/legacy/`
