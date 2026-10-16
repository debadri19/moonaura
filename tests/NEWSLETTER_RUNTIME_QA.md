# Newsletter Runtime QA Guide (Phase 5)

Repeatable procedures for verifying the newsletter hardening changes on a
host that has PHP, MySQL, and (later) SMTP/Brevo available. Nothing in this
guide was executed in the originating workspace except the source-level and
static checks noted inline: PHP CLI, MySQL, and composer were absent there.

Do not paste real secrets, API tokens, or customer data into commands,
outputs, or this file. Use synthetic identities and a dedicated test secret.

---

## 1. Environment prerequisites

Verified against the actual code (not assumed from a generic extension list):

| Requirement | Used by | Notes |
| --- | --- | --- |
| PHP 8.0+ | `match` expressions, `str_contains`/`str_starts_with`/`str_ends_with` | `dashboard/*`, `includes/*`; project docs state "PHP 8+" (no 8.1-only features found) |
| `dom` + `libxml` | `newsletter_sanitize_campaign_html()` (`DOMDocument`, `libxml_use_internal_errors`) | sanitizer cannot run without it |
| `filter` | `filter_var(..., FILTER_VALIDATE_IP/EMAIL)`, `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE` | public URL/IP policy |
| `pdo_mysql` | `includes/db.php` (`mysql:` DSN, `PDO::ATTR_EMULATE_PREPARES => false`) | real server-side prepares matter for claim atomicity |
| `hash` (bundled) | `hash_hmac('sha256', ...)`, `hash_equals` | unsubscribe tokens |
| `mbstring` | `mb_substr()` | field truncation |
| `json` | campaign image upload JSON responses | admin upload |
| `curl` | `newsletter_brevo_request()` (Brevo API) | only for live eligibility/send |
| OpenSSL / TLS | PHPMailer STARTTLS/implicit TLS to Brevo SMTP | only for live send |

MySQL/MariaDB: migrations in `database/migration_newsletter_*.sql` target
MySQL 5.7+ / MariaDB 10+ (they use `information_schema` guards and
`PREPARE`/`EXECUTE`). InnoDB is required for the row-level locking the claim
logic depends on.

Distinguish these states explicitly when reporting:

- Tool exists (binary on `PATH`).
- Tool version verified (`php -v`, `mysql --version`).
- Extension available (`php -m`).
- Service running (MySQL reachable).
- Test database is isolated and confirmed non-production.

A `mysql` client existing does **not** prove a server is reachable.

---

## 2. Available checks and exact commands

Static / source-level (run anywhere PHP 8.0+ with the extensions above):

```bash
# PHP syntax for every changed newsletter file
php -l includes/newsletter-admin-functions.php
php -l includes/newsletter-campaign-functions.php
php -l includes/mailer.php
php -l dashboard/newsletter.php
php -l dashboard/newsletter-campaign-form.php
php -l dashboard/newsletter-unsubscribe.php
php -l newsletter-unsubscribe.php
php -l scripts/send-newsletter-campaigns.php
```

Behavioral (no mail, no MySQL, no Brevo, no outbound HTTP):

```bash
# Sanitizer + public-https URL policy + claim helper surface
php tests/newsletter_security_fixes.php

# Unsubscribe HMAC fail-closed + static header/save-source assertions
php tests/newsletter_hardening.php

# Unsubscribe HMAC token behavior (valid/expired/tampered/legacy/local)
php tests/newsletter_unsubscribe_tokens.php
```

Expected: each prints `PASS`/`FAIL` lines and exits `0` when all pass.
`tests/newsletter_security_fixes.php` is behavioral for
`newsletter_is_public_https_url()` and `newsletter_sanitize_campaign_html()`
via real function calls. `tests/newsletter_hardening.php` mixes a few
behavioral secret checks with `str_contains()` source assertions — label it
as static where it is static; a source substring is not behavioral proof.
`tests/newsletter_unsubscribe_tokens.php` is fully behavioral for the token
functions; it runs each configuration in a child PHP process (config values
are immutable constants) and needs `proc_open` enabled. If the parent cannot
spawn children it prints `BLOCKED` and exit code `2`, and each scenario can
be run alone, e.g. `NLQA_MODE=valid php tests/newsletter_unsubscribe_tokens.php`.

Behavioral tests that additionally require a live SMTP/Brevo (later,
authorized phase only): send test, send now, schedule, and eligibility
re-checks. Never run these against live subscribers.

---

## 3. Sanitizer QA (`newsletter_sanitize_campaign_html`)

Behavioral cases to execute through the real function (not source search).
The first block is already covered by `tests/newsletter_security_fixes.php`;
extend it as needed.

1. Safe markup survives: `<p>`, `<h1..h3>`, `<strong>/<b>`, `<em>/<i>`,
   `<u>`, `<ul>/<ol>/<li>`, `<br>`, `<span>`, `<div>`, and `{{first_name}}`.
2. Event handlers removed regardless of case/whitespace/adjacency:
   `<img src="https://ok.test/a.png" OnError=alert(1)>`,
   `<img src="https://ok.test/a.png"onload=alert(1)>`.
3. Encoded/obfuscated schemes rejected:
   `<a href="java&#115;cript:alert(1)">`, `<a href="java&#x09;script:...">`.
4. `javascript:`, `vbscript:`, `data:`, `file:`, `about:` anchors are
   unwrapped (text preserved, anchor tag gone).
5. Malformed HTML / unexpected namespaces do not leak active nodes.
6. SVG/MathML/iframe/object/embed/form/input/button/textarea/select/base are
   dropped with their subtree.
7. `style` attributes and CSS payloads (`url(...)`, `@import`) are stripped.
8. `a[href]` and `img[src]` must be public HTTPS; otherwise the anchor is
   unwrapped and the image removed. `width`/`height` must be digits only.
9. Re-render path: the stored body is sanitized again in
   `newsletter_build_campaign_html()`, so test both save-time and
   build-time output.

Storage/preview/edit/send boundaries: verify the admin editor renders the
sanitized body, and that the email HTML produced by
`newsletter_build_campaign_html()` contains no event handlers.

---

## 4. Public HTTPS URL policy (`newsletter_is_public_https_url`)

Call sites to keep consistent (save-time and final send boundary):
`newsletter_public_base_url()`, `newsletter_unsubscribe_url()`,
`newsletter_list_unsubscribe_headers()`, `newsletter_campaign_cta_html()`,
CTA save validation, and `newsletter_campaign_validate_for_send()`.

Cases to execute:

- Public HTTPS hostname accepted; plain HTTP rejected.
- `localhost`, `*.localhost`, `.internal`, `.local`, `.lan`, `.home`,
  `.corp`, `.invalid`, `.onion` rejected.
- IPv4: loopback, private (10/8, 172.16/12, 192.168/16), link-local
  (169.254/16), CGNAT (100.64/10), multicast, reserved, unspecified
  (`0.0.0.0`) rejected.
- IPv6: loopback (`::1`), unique-local (`fc00::/7`), link-local (`fe80::/10`),
  multicast (`ff00::/8`), unspecified (`::`) rejected.
- IPv4-mapped IPv6 (`::ffff:127.0.0.1`,
  `::ffff:7f00:1`, `::ffff:0:0`) rejected.
- Embedded credentials (`user:pass@host`) rejected.
- Non-443 ports rejected (`:8080`); `:443` accepted.
- Malformed authorities, unusual numeric forms (leading zeros like
  `0177.0.0.1`, bare `127.1`), and trailing-dot hostnames.
- Missing/invalid `SITE_URL` => `newsletter_public_base_url()` returns `''`;
  drafts still save, but send/test/schedule fail closed.
- No outbound requests are made while validating.

Note on scope: validated URLs are only emitted into outbound email HTML
(`href`/`src`) and `List-Unsubscribe`; the code performs **no server-side
fetch** of these URLs (uploads are local, Brevo calls go to a fixed host).
So URL policy is email-content policy, not an SSRF fetch guard. Record the
numeric-form edge cases as content-policy observations, not exploitable
network fetches.

---

## 5. Unsubscribe HMAC / request security

Set a dedicated synthetic secret for the test run only (never a production
value), e.g. `NEWSLETTER_UNSUB_SECRET` of 32+ characters. Then exercise the
real functions:

`tests/newsletter_unsubscribe_tokens.php` automates cases 1-6 and 9
behaviorally (and case 5 as legacy-key rejection). It runs four child
processes (valid secret, weak secret, missing secret, local `SITE_URL`)
because those config values are immutable constants per process. Cases 7-8
and 10 below remain integration checks (web server / mail).

1. Valid token: `newsletter_unsubscribe_token($email)` then
   `newsletter_verify_unsubscribe_token(...)` returns `true`.
2. Tampered email, `sig`, or `exp` returns `false`.
3. Expired `exp` returns `false`.
4. Token from one email does not verify for another email (HMAC covers the
   email).
5. Legacy assumptions rejected: a signature built from `SITE_URL`,
   `INVOICE_TOKEN_SECRET`, or `ADMIN_2FA_ENCRYPTION_KEY` must not verify.
6. Missing/short secret: `newsletter_unsub_secret_configured()` is `false`,
   `newsletter_unsubscribe_token()` returns `[]`, verification returns
   `false`, and the public page shows the expired/invalid state.
7. Request workflow: GET renders the confirm form only (no state change);
   POST calls `csrf_verify()` then `newsletter_admin_unsubscribe()`.
8. A missing/invalid CSRF token yields HTTP 403 and no unsubscribe.
9. An invalid/local `SITE_URL` cannot produce a usable public unsubscribe
   URL (`newsletter_public_base_url()` and `newsletter_unsubscribe_url()`
   return `''`).
10. `List-Unsubscribe` is present (https + mailto) on non-test campaigns and
    absent on test sends; one-click `List-Unsubscribe-Post` is intentionally
    not added.
11. Errors shown to the user do not include the secret or HMAC material.

**Integration only (needs a web server / live Brevo).** Cases 7, 8, and 11
and the state-changing POST path of `newsletter-unsubscribe.php` /
`dashboard/newsletter-unsubscribe.php` cannot be exercised by this CLI test:
`newsletter_admin_unsubscribe()` calls the Brevo API. Verify them in a later
authorized integration phase. Case 10 needs a configured sender and an
actual message.

Use only synthetic addresses (`qa@example.test`). Do not test against live
subscribers. Do not send email.

---

## 6. Recipient claim / concurrency QA (MySQL required)

Unit-level logic can be reasoned about statically, but atomic claims,
transaction isolation, and `rowCount()` semantics **must** be proven on a
real, isolated, non-production database. Mocks do not prove MySQL behavior.

Set up an isolated database (see §7) and apply, in order:
`migration_newsletter_admin.sql`, `migration_newsletter_campaign_sending.sql`,
`migration_newsletter_recipient_claims.sql`.

Then run two concurrent sessions against the same `(campaign_id, email)`:

1. Both execute
   `UPDATE newsletter_campaign_recipients SET status='claimed', claim_token=?, claimed_at=? WHERE campaign_id=? AND email=? AND status='pending'`.
   Exactly one reports `rowCount() = 1`; the other `0`.
2. A claim on an already-claimed row returns `0`.
3. Owner updates to `sent`/`failed` with its `claim_token`; a different
   token does not match (`rowCount() = 0`).
4. A `claimed` row older than `NEWSLETTER_RECIPIENT_CLAIM_TTL` (900s) becomes
   `unknown` via `newsletter_campaign_expire_stale_claims()`, never `pending`.
5. Crash before SMTP: the `claimed` row expires to `unknown`, not resent.
6. Crash after SMTP accepted but before the DB update: stale `claimed` ->
   `unknown`; not auto-resent; result is genuinely ambiguous.
7. Retry: `newsletter_campaign_reset_failed_recipients()` resets only
   `failed` -> `pending`; `sent`/`claimed`/`unknown` rows are untouched.
8. Campaign-level claim: two simultaneous `newsletter_campaign_claim()` calls
   from `draft`/`failed`/`cancelled` -> exactly one `rowCount() = 1`.
9. Lock expiry: hold a send past `NEWSLETTER_CAMPAIGN_LOCK_SECONDS` (600s);
   `newsletter_campaign_lock_for_resume()` may take over, but per-recipient
   claims still prevent double sends for in-flight rows.
10. Mid-run state change: there is no UI action that cancels a `sending`
    campaign, so exercise this only by direct SQL if desired. Note the final
    status `UPDATE` in `newsletter_campaign_process()` is not status-guarded.
11. DB error while recording a result: `newsletter_campaign_mark_recipient()`
    returns `false` and the row stays `claimed` until it expires to `unknown`.

Explicitly do not promise exactly-once delivery: SMTP acceptance and the
database write cannot be a single atomic operation. `unknown` is the correct
terminal state for ambiguous cases and is never auto-retried.

---

## 7. Isolated test database setup (safe procedure)

1. Confirm the instance is non-production (hostname, credentials, and a
   second human check).
2. Create a throwaway database, e.g. `moonaura_qa_<date>`, with a dedicated
   throwaway user. Do not point at the production schema.
3. Load `database/schema.sql` + `database/seed.sql` for a fresh-install
   baseline, **or** clone a sanitized schema-only dump.
4. Apply the newsletter migrations in the order in §8.
5. Run the concurrency scripts in §6.
6. Tear the database down when done (do not leave it running).

Never connect to an unknown host or restore over an existing database.

---

## 8. Migration pre-checks and order (future authorized session)

Execution status: **NOT EXECUTED** anywhere in this workspace.

Order for existing databases:

1. `database/migration_newsletter_admin.sql`
2. `database/migration_newsletter_campaign_sending.sql`
3. `database/migration_newsletter_recipient_claims.sql`

Apply commands (run only against the isolated `moonaura_qa_*` database,
never production). `$DB` is the throwaway database name:

```bash
# 13 — subscriber cache + campaign drafts
mysql "$DB" < database/migration_newsletter_admin.sql
# 14 — send/schedule columns + sends/recipients tables
mysql "$DB" < database/migration_newsletter_campaign_sending.sql
# 15 — claimed/unknown statuses + claim_token/claimed_at + claim index
mysql "$DB" < database/migration_newsletter_recipient_claims.sql
```

Expected outcome: each file exits `0`. Files 14 and 15 use
`information_schema` guards, so re-running prints `SELECT 1` for
already-present objects instead of erroring. A non-zero exit means a
pre-condition is missing (e.g. applying 15 before 14, so
`newsletter_campaign_recipients` does not exist and the `ALTER ... MODIFY
status` fails).

Pre-checks:

```bash
# Phase 3 columns/table state before applying 14
mysql -e "SHOW COLUMNS FROM newsletter_campaigns LIKE 'locked_at'"
mysql -e "SHOW TABLES LIKE 'newsletter_campaign_recipients'"

# Claim columns/status before applying 15
mysql -e "SHOW COLUMNS FROM newsletter_campaign_recipients LIKE 'claim_token'"
mysql -e "SHOW COLUMNS FROM newsletter_campaign_recipients LIKE 'claimed_at'"
```

Post-checks after 15:

```bash
# Recipient status enum must include claimed and unknown
mysql -e "SHOW COLUMNS FROM newsletter_campaign_recipients LIKE 'status'"

# Composite index for claim selection / stale-claim sweeps
mysql -e "SHOW INDEX FROM newsletter_campaign_recipients WHERE Key_name='idx_newsletter_recipients_claim'"
```

Recovery notes:

- `claimed`/`sent`/`failed`/`unknown` rows are never reset to `pending` by
  the migrations; a migration re-run only adds the enum value/columns/index.
- Re-running 15 is safe: the `MODIFY ENUM` is idempotent and the column/index
  changes are guarded by `information_schema`.
- Fresh installs already have the final schema; the guards make 15 a no-op
  except for the idempotent `MODIFY ENUM`.
- Do not make migrations retroactively alter existing recipient states.

---

## 9. Stale claims and ambiguous SMTP outcomes

- TTL: `NEWSLETTER_RECIPIENT_CLAIM_TTL` = 900s; campaign lock
  `NEWSLETTER_CAMPAIGN_LOCK_SECONDS` = 600s.
- A `claimed` row past TTL becomes `unknown` (`NEWSLETTER_RECIPIENT_UNKNOWN_ERROR`)
  and is not resent automatically.
- `unknown` rows are counted separately from `pending`/`sent`/`failed` in
  `newsletter_campaign_send_stats()`. The dashboard analytics `pending_sends`
  counts `pending` + `claimed`; `unknown` is deliberately excluded from
  accepted/failed/pending, so the sum of accepted+failed+pending may be less
  than `recipients` — this is expected, not data loss.
- Manual review is required before treating an `unknown` recipient as
  unsent. Do not add an automatic resend path.

---

## 10. Safe test-email procedure (later authorized phase only)

Do not execute now.

1. Use a controlled test mailbox you own (never a real subscriber).
2. Set non-production SMTP/Brevo credentials and a dedicated
   `NEWSLETTER_UNSUB_SECRET`.
3. Set `SITE_URL` to a public HTTPS origin (or a test origin that passes the
   policy) so the unsubscribe link builds.
4. In the admin composer, use **Send Test** (subject is prefixed `[TEST]`,
   footer states test, `List-Unsubscribe` is omitted).
5. Confirm the test contact is not a live list member and that the campaign
   was not queued for real recipients.
6. Roll back any temporary SMTP/test configuration afterwards.

---

## 11. Known limitations and deployment blockers

- PHP CLI (with `dom`/`filter`/`pdo_mysql`) and MySQL were **not** available
  in the origin workspace, so all behavioral/concurrency tests are BLOCKED
  there. Only source review and `git diff --check` succeeded.
- SMTP/Brevo sends and eligibility re-checks are NOT TESTED and must be run
  only in a later, explicitly authorized phase with a controlled recipient.
- Campaign send/test/schedule, and public unsubscribe link generation, stay
  fail-closed until `NEWSLETTER_UNSUB_SECRET` (16+ chars) and a public HTTPS
  `SITE_URL` are configured.
- Migrations 13–15 have not been executed; `newsletter_campaign_tables_ready()`
  requires the claim columns, so sending stays blocked until 15 is applied.
- No live preview was produced for this phase.
