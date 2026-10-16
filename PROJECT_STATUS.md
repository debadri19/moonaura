# MoonAura Crystals — Project Status

> Handoff checkpoint: **v0.6.7** (product version unchanged by this documentation refresh).
> Workspace: `master` @ `4e037bb` plus unpublished storefront UI in the working tree.
> Status labels below distinguish **Implementation Complete**, **Visual QA Pending**, and **Production Verified**.
> Code edits are not visual QA. Local historical test counts from earlier sessions are not re-run here.

---

## Status Legend

| Label | Meaning |
|---|---|
| Implementation Complete | Present in current source |
| Visual QA Pending | Implemented, not signed off in browser across Light / Dark / System and breakpoints |
| Production Verified | Confirmed on a live production server in this workspace's records |
| Pending | Not implemented |
| Not started | Not in source |

---

## Completed (implementation)

| Area | Scope | Implementation | Visual QA | Production |
|---|---|---|---|---|
| Catalog | Dynamic home, shop, product, concerns, zodiac | Complete | Pending (recent UI) | Not verified in this workspace |
| Cart / wishlist | Session cart + wishlist, AJAX | Complete | Pending | Not verified here |
| Checkout | Guest + logged-in, Buy Now, saved addresses | Complete | Pending | Not verified here |
| Payments | Razorpay + COD + Manual UPI | Complete | Pending | Not verified here |
| Accounts | Register/login, orders, tracking, invoices, theme | Complete | Pending | Not verified here |
| Admin | Products, categories, customers, orders, settings, 2FA, Dark Mode | Complete | Pending | Not verified here |
| Admin newsletter | Audience, composer, send/schedule, history/analytics | Complete | Pending | Not verified here |
| GST / invoices | Inclusive GST snapshots, PDF, Invoice Designer | Complete | N/A | Not verified here |
| Email | Brevo SMTP: reset + order confirmation/shipped/delivered | Complete | N/A | Not verified here |
| Newsletter (storefront) | Brevo Contacts pending-list double opt-in | Complete | Pending (input/button alignment) | Not verified here |
| Theme | Storefront Light / Dark / System | Complete | Pending | Not verified here |
| Sitemap / robots | `sitemap.php`, `robots.txt` | Complete | N/A | Not verified here |
| GA4 / Meta Pixel | Env-gated gtag, Pixel, CAPI | Complete | Events Manager QA pending | Not verified here |

Historical local test-suite counts in older docs are **not repeated as current proof**. They are not re-executed in this documentation pass.

---

## Recent storefront UI (working tree)

All of the following are **Implementation Complete** and **Visual QA Pending**.

| Item | Status |
|---|---|
| Product button size refinement (`.product-btn` 40px, radius 16px) | Implementation Complete |
| Add to Cart button size/alignment (`.cart-btn` 40×40, matched height) | Implementation Complete |
| Policy / About / mobile-nav circular icon wells | Implementation Complete |
| Desktop active nav: short gold bar under item | Implementation Complete |
| Mobile active nav: left gold inset bar | Implementation Complete |
| Home Hero padding aligned with Shop Hero (`min-height: 520px`) | Implementation Complete |
| About / Policy section spacing refinements | Implementation Complete |
| Shop search toolbar equal vertical padding | Implementation Complete |
| Policy mobile shortcuts 2-column grid | Implementation Complete |
| Zodiac icons → Lucide-style stroke SVGs | Implementation Complete |
| Dark Mode scrollbar colors | Implementation Complete |
| Dark Mode text contrast — Account headings/links | Implementation Complete |
| Dark Mode text contrast — Home `.section-title` / hero accent / product-title hover | Implementation Complete |
| Global Dark Mode `.btn-outline` (`--color-outline`) | Implementation Complete |
| Dark Mode icon-well / icon glyph contrast | Implementation Complete |
| Policy Hero typography compact + eyebrow nowrap | Implementation Complete |
| About mobile Our Promise 2×3 grid | Implementation Complete |
| Support “Online Store Only • No Walk-in Store” | Implementation Complete |

Do **not** re-add the items above as pending UI work.

---

## In Progress

- Documentation refresh to match the current workspace (this pass).
- Unpublished storefront UI waiting for Visual QA.

No feature implementation is in progress in source beyond the unpublished CSS/PHP already in the working tree.

---

## Pending

### UI / Visual

| Item | Status |
|---|---|
| Newsletter input + Subscribe button size/alignment | Pending (input `54px` vs button `--btn-height` `42px`) |
| Full Site Visual QA (Light / Dark / System, desktop / tablet / mobile) | Pending |
| Admin Panel Dark Mode | Implementation Complete, Visual QA Pending |
| Admin button style consistency with storefront pills | Pending (pre-existing) |

**Removed from pending** because they are implemented in current CSS/PHP:

- Search Bar Top & Bottom Spacing
- About Us → OUR STORY bottom spacing (reduced in `about-us.css`)
- Home Hero Container Height
- Product Card Add to Cart Button Size/Alignment
- Policy Mobile Shortcuts → 2-column grid
- Zodiac Icons → Lucide-style replacement
- Global Scrollbar Color → Dark Mode
- Dark Mode Text Contrast → Account Pages
- Dark Mode Text Contrast → Home Page

### Admin / Features

| Item | Status |
|---|---|
| Admin Newsletter & Email Campaign Management System | Implementation Complete (Phases 1–4); QA Pending. Opens/clicks not tracked. |
| Phase 5H POS / Walk-in Sales | Not started (storefront now states online-only) |
| Coupons | Not implemented |
| Account-linked (DB) wishlist | Not implemented |
| Checkout state dropdown / billing vs shipping split | Not implemented |
| Invoice PDF `/Encrypt` / permission restriction | Not implemented (known `SimplePdfWriter` limitation) |

### QA / Production

| Item | Status |
|---|---|
| Full Site Visual QA | Pending |
| Meta Pixel Events Manager QA / Diagnostics | Pending |
| Later Meta Pixel phases beyond current Pixel + CAPI code | Pending / not separately verified |
| Live Admin Deployment Finalization | Pending |
| Final Production Audit | Pending |
| Real-server verification of Phases 4B–6 + recent UI | Pending in this workspace |

---

## Production / Release Blockers

These are **not claimed resolved** by this documentation pass:

1. Full-site visual QA not signed off.
2. Production env completeness (Razorpay live keys, Brevo SMTP, `ADMIN_2FA_ENCRYPTION_KEY`, `INVOICE_TOKEN_SECRET`, GA4/Pixel IDs) must be confirmed on the live host — not inferred from this workspace.
3. Newsletter campaign live sending, cron, and migration application must be confirmed on the host. Set `NEWSLETTER_UNSUB_SECRET` (16+ characters) and a public https `SITE_URL` before any send. Apply `database/migration_newsletter_recipient_claims.sql` after the Phase 3 sending migration. Previous unsubscribe links signed with `SITE_URL` / invoice / 2FA fallbacks will not verify.
4. Open/click/bounce tracking is not configured.
5. Unpublished working-tree UI is not committed.

This workspace does **not** record a production-verified go-live.

---

## QA Requirements

Before calling the storefront production-ready:

- Walk Home, Shop, Product, Cart, Checkout, Account, About, Policy, Support in Light, Dark, and System.
- Confirm gold accents, circular icon wells, rectangular product CTAs, and pill `.btn` hierarchy.
- Confirm Dark Mode heading/link/scrollbar/outline contrast without changing Light Mode.
- Confirm Policy Hero eyebrow stays one line and About mobile Promise is 2×3.
- Place a Razorpay test/live payment, a COD order, and a Manual UPI verify/reject.
- Confirm order emails send once per type.
- Confirm newsletter pending → confirm flow in Brevo (automation is outside this repo).
- Confirm GA4 and Meta Pixel only fire when IDs are configured; run Events Manager diagnostics separately.

---

## Database Migrations

Canonical order remains `DEPLOYMENT_CHECKLIST.md` §1 (15 files). Fresh install: `database/schema.sql` + `database/seed.sql`.

Newsletter Admin (manual, execution Unknown in this workspace):

1. `database/migration_newsletter_admin.sql` — Phase 1/2 tables.
2. `database/migration_newsletter_campaign_sending.sql` — Phase 3 send columns/tables.
3. `database/migration_newsletter_recipient_claims.sql` — recipient `claimed`/`unknown` statuses plus `claimed_at`/`claim_token` for exclusive worker claims. Apply after 2.

Phase 4 added **no** new SQL. `newsletter_campaign_tables_ready()` selects `claimed_at`/`claim_token`, so campaign send/test/schedule stay blocked until 3 is applied on existing databases.

---

## Environment Variables (names only)

Documented in `SETUP.md` and `DEPLOYMENT_CHECKLIST.md`. Never paste real values.

Required for a working shop: `DB_*`, `SITE_URL`.

For live features: Razorpay keys, `ADMIN_2FA_ENCRYPTION_KEY`, Brevo SMTP, `BREVO_API_KEY` (newsletter), `INVOICE_TOKEN_SECRET` (guest invoices), `GA4_*`, `META_PIXEL_ID`, `META_CAPI_ACCESS_TOKEN`.
