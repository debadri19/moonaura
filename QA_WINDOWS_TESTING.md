# Windows Laragon QA - Safe Setup Order

This package is a test transfer copy of the MoonAura Crystals source.
Run the steps below in order. Do not skip ahead to database or email
testing. Do not overwrite the existing `C:\laragon\www\Moonaura`.

## 1. Extract into a separate directory

Extract this ZIP into a NEW folder, e.g. `C:\laragon\www\Moonaura_QA`.
Keep the existing `C:\laragon\www\Moonaura` folder untouched.

## 2. Verify PHP and extensions through Laragon

- Confirm the Laragon PHP version is PHP 8.0 or newer (`php -v`).
- Confirm these extensions are enabled (`php -m`):
  `dom`, `libxml`, `filter`, `pdo_mysql`, `hash`, `mbstring`, `json`,
  and `curl` / `openssl` for later email work.
- The sanitizer requires `dom` + `libxml`; the claim logic requires
  `pdo_mysql` with server-side prepares.
- The token test needs `proc_open` enabled to run its child processes
  (each configuration uses immutable constants).

## 3. Run the dependency-free checks first

From the extracted folder (Laragon terminal):

```bash
# PHP syntax check of the newsletter files
php -l includes/newsletter-admin-functions.php
php -l includes/newsletter-campaign-functions.php
php -l includes/mailer.php
php -l dashboard/newsletter.php
php -l dashboard/newsletter-campaign-form.php
php -l dashboard/newsletter-unsubscribe.php
php -l newsletter-unsubscribe.php
php -l scripts/send-newsletter-campaigns.php
```

These tests do NOT touch a database, send email, call Brevo, or make
outbound network requests:

```bash
php tests/newsletter_security_fixes.php
php tests/newsletter_hardening.php
php tests/newsletter_unsubscribe_tokens.php
```

Expected: each prints PASS/FAIL lines and exits 0 when all pass.
`tests/newsletter_unsubscribe_tokens.php` runs four child processes
(valid / weak / missing secret, and a local `SITE_URL`). If it prints
`BLOCKED` with exit code 2, `proc_open` is unavailable; in that case run
each scenario alone, e.g. `NLQA_MODE=valid php tests/newsletter_unsubscribe_tokens.php`.

## 4. Create an isolated, disposable QA database

- BEFORE any database test, create a SEPARATE throwaway database, e.g.
  `moonaura_qa`, with its own dedicated test user.
- Do NOT point the app at production or at the user's existing database.
- Provide DB settings through your own local `.env` (see `SETUP.md`
  environment-variable names). Do not guess or invent credentials.

## 5. Review migrations before execution

- Read `DEPLOYMENT_CHECKLIST.md` section 1 (newsletter migrations 13-15).
- Inspect each SQL file before running it:
  `database/migration_newsletter_admin.sql`,
  `database/migration_newsletter_campaign_sending.sql`,
  `database/migration_newsletter_recipient_claims.sql`.
- Apply them IN ORDER (13 -> 14 -> 15), and only to the confirmed QA
  database.

## 6. Concurrency testing with synthetic data only

- Use synthetic recipients (e.g. `qa@example.test`).
- Use a test harness that cannot deliver email (no SMTP configured, or
  the mailer left unconfigured) so no real message can be sent.
- Follow `tests/NEWSLETTER_RUNTIME_QA.md` section 6 for the two-session
  claim test and the stale-claim / retry cases.

## 7. Keep real delivery disabled

- Do NOT configure real SMTP/Brevo credentials or send any campaign yet.
- SMTP/Brevo delivery testing stays disabled until it is separately
  authorized, using a controlled test mailbox you own.

## Notes

- Do not alter the user's local PHP or MySQL configuration.
- No migrations were executed in the export environment, no email was
  sent, and no Brevo call was made.
- This package is not a runtime-verified production release.
