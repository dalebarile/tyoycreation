# Tyoy Creation QES — Independent Weakness Analysis

**Scope:** Live codebase at `qes/` (verified against source, not documentation)
**Date:** 2026-10-15
**Method:** Manual source review of security-critical paths (auth, crypto, DB router, chatbot, booking, notification, caching)

> This report deliberately re-verifies the claims made in the existing `docs/PROJECT_AUDIT.md`.
> Several "🟢 Production Ready / 9.5-10" claims are **not true of the actual code**. Those discrepancies are called out explicitly.

---

## 1. Severity Summary

| # | Weakness | Severity | Location |
|---|---|---|---|
| W1 | TLS certificate verification **disabled** on outbound Gemini API calls | 🔴 Critical | `chatbot.php:805-806` |
| W2 | Live production secrets stored in a **plaintext, non-PHP `.env`** file (service key, API keys, SMTP app password, OAuth secret) | 🔴 Critical | `qes/.env` |
| W3 | Audit documentation materially **misrepresents** the encryption (claims AES-256-GCM; code is AES-256-CBC+HMAC) | 🟠 High | `docs/PROJECT_AUDIT.md` vs `crypto_helper.php` |
| W4 | `qes_decrypt()` silently returns `''` on auth failure → risk of **silent data loss / blank PII** | 🟠 High | `crypto_helper.php:94-101` |
| W5 | Serialized cache files stored in a **web-accessible** `cache/` dir with no `.htaccess` deny | 🟠 High | `cache/`, `cache_helper.php` |
| W6 | Insecure cache deserialization (`unserialize`) of on-disk payloads | 🟡 Medium | `cache_helper.php:37` |
| W7 | `error_log()` writes verbose paths/emails/DB errors to a webroot log | 🟡 Medium | throughout (`db.php`, `notification_helper.php`) |
| W8 | Helper functions duplicated verbatim (gibberish detector, rate-limit logic) → drift already visible | 🟡 Medium | `chatbot.php` vs `scratch/*`, `db.php` |
| W9 | Internal tooling with `shell_exec` + hardcoded SEED admin identity left in tree | 🟡 Medium | `scratch/audit_isolated.php` |
| W10 | Monolithic files (booking.php 5,244 lines; index.php 2,433; db.php 2,391) | 🟢 Low | multiple |
| W11 | No real integration test coverage; `tests/Integration/PlaceholderTest.php` is a stub | 🟢 Low | `tests/` |

---

## 2. Detailed Findings

### 🔴 W1 — TLS verification disabled on the AI concierge (Critical)

`chatbot.php` lines 805-806:

```php
CURLOPT_SSL_VERIFYPEER => false,
CURLOPT_SSL_VERIFYHOST => 0,
```

The code even computes a CA bundle path on the next lines (808-811), but it is **pointless** because verification was just turned off two lines earlier. This makes every Gemini call vulnerable to man-in-the-middle interception, and the API key is sent in the URL query string (`?key=...`).

The existing audit (row 2, "Resolved") and README both claim strict SSL validation is enabled. **They are wrong.**

**Fix:** set `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, and set `CURLOPT_CAINFO` to the real bundle. Also move the API key from the URL into an `x-goog-api-key` header.

---

### 🔴 W2 — Live secrets in a plaintext `.env` file (Critical)

`qes/.env` (2.0 KB) contains **real, working credentials**:

- `ENV_SUPABASE_SERVICE_KEY` (service-role JWT — full DB bypass of RLS)
- `ENV_SUPABASE_ANON_KEY`, `ENV_SUPABASE_TOKEN` (`sbp_...` personal access token)
- `ENV_GEMINI_API_KEY`
- `ENV_EMAIL_PASSWORD` (Gmail app password, `azdd myfa ymyf juaf`)
- `ENV_GOOGLE_CLIENT_SECRET` (`GOCSPX-...`)
- `ENV_TURNSTILE_SECRET_KEY`
- `ENV_ENCRYPTION_KEY` (master PII key)

Mitigating facts confirmed: the file **is** git-ignored, and `.htaccess` `FilesMatch ^(\.env.*)` **does** deny web access. But the substance is still fragile:

- A non-PHP `.env` is a second, redundant copy of `.env.php`. Two sources of truth = one will drift and get committed.
- Values are also duplicated into `.env.example.php` and the DB `settings` table (Turnstile fallbacks), widening the blast radius.

**Fix:** delete `qes/.env` entirely (the app loads `.env.php`); rotate every secret that ever sat in it; keep one secrets file, git-ignored, ideally outside webroot.

---

### 🟠 W3 — Documentation misrepresents the crypto (High)

| Claim (audit/README) | Reality (`crypto_helper.php`) |
|---|---|
| "AES-256-**GCM** with authenticated tags and random 12-byte IV" | AES-256-**CBC** + separate HMAC-SHA256, 16-byte IV (`$method = 'aes-256-cbc'`, IV = 16 bytes) |

Encrypt-then-MAC with HMAC-SHA256 is a **legitimate, secure** construction — so this is not an exploitable bug by itself. The problem is governance: an audit asserting a specific cipher that the code does not implement destroys trust in the whole document, and would mislead any regulator, client security review, or future developer.

**Fix:** correct the docs to say "AES-256-CBC with encrypt-then-MAC (HMAC-SHA256)", or migrate to real `openssl_encrypt(..., 'aes-256-gcm')`.

---

### 🟠 W4 — Silent decryption failure → masked data loss (High)

```php
if (!hash_equals($hmac, $expectedHmac)) {
    return '';   // <-- failure indistinguishable from "no value"
}
```

`qes_decrypt()` returns an **empty string** when the HMAC does not verify. Callers cannot distinguish "this field was never set" from "this field is corrupt or was tampered with." Combined with the fact that `qes_encrypt()` *returns the plaintext unchanged* if `openssl_encrypt()` fails (line 51-53), the system can persist mixed plaintext/ciphertext without any signal. A rotated `ENV_ENCRYPTION_KEY` would silently blank every client name, email, and phone in the admin UI — with no error surfaced.

**Fix:** throw/return a distinguishable error (or log loudly), and add a key-version prefix so rotation is detectable.

---

### 🟠 W5 — Web-accessible serialized cache (High)

`cache_helper.php` writes `serialize([...])` payloads to `qes/cache/*.cache`. Verified: that directory has **no `.htaccess`** and `.htaccess` at the app root does **not** deny `/cache`. Sample on-disk content:

```
a:2:{s:3:"exp";i:1791565812;s:3:"val";a:1:{i:0;a:1:{s:5:"total";i:3;}}}
```

Any user can fetch `http://host/qes/cache/<md5>.cache`. Today these hold settings maps and counts, but the `settings` map can include Turnstile keys and business data, and future cache entries could hold decrypted rows.

**Fix:** add `cache/.htaccess` with `Require all denied`, or move the cache dir outside the webroot; prefix cache files with `<?php exit; ?>`.

---

### 🟡 W6 — Unsafe `unserialize()` of disk cache (Medium)

`unserialize()` on file contents (no `allowed_classes` restriction) is a classic PHP object-injection vector if an attacker can ever write to the cache dir. Low likelihood here, but trivially hardened.

**Fix:** `unserialize($content, ['allowed_classes' => false])`, or switch to `json_encode`/`json_decode`.

---

### 🟡 W7 — Verbose logging to a webroot file (Medium)

`error_log()` calls throughout `db.php` and `notification_helper.php` record **recipient emails**, file paths, and raw DB error strings. If `log_errors` routes to a file under the webroot (default XAMPP behaviour), this is an information-disclosure channel.

**Fix:** log to a path outside the webroot, and avoid logging PII.

---

### 🟡 W8 — Duplicated logic already diverging (Medium)

The gibberish detector exists in **three** places: `chatbot.php`, `scratch/test_gibberish.php`, and a near-copy elsewhere. Comparing them shows the keyboard-smash token list has already **drifted** (`chatbot.php` version adds `askd|ksdj`, the scratch copy does not). Duplicated security/business predicates silently diverge over time.

**Fix:** extract to one shared `src/` module and require it everywhere.

---

### 🟡 W9 — Internal tooling left in tree (Medium)

`scratch/audit_isolated.php` uses `shell_exec()` to run PHP, and hardcodes a **seed admin identity** (`$_SESSION['username'] = 'creationtyoy'; $_SESSION['id'] = 8; $_SESSION['role'] = 'main_admin';`). Even though `scratch/` is git-ignored and not routed, leaving a script that grants itself `main_admin` in the deployed folder is an unnecessary hazard.

**Fix:** delete `scratch/` from the deployment, or move it outside the webroot.

---

### 🟢 W10 / W11 — Maintainability & testing

- `booking.php` is 5,244 lines; `index.php` 2,433; `db.php` 2,391. Single-responsibility is not enforced; changes carry high regression risk.
- Tests: only unit tests plus `tests/Integration/PlaceholderTest.php` (a stub). There is **no** integration coverage of the real booking → verify → notify → calendar flow. The audit's "Verified against current live codebase" claim is not backed by an automated gate.

---

## 3. What Is Genuinely Strong (verified, not just claimed)

To be fair and accurate, these areas really are well-built:

- **Admin login** (`qes_process_admin_login`): per-IP **and** per-account rate limiting, lockout checked *before* `password_verify`, uniform error message to prevent user enumeration, role allow-list enforced.
- **SQL**: consistently uses `prepare()` + `bind_param()`; `check_booking_conflict()` **fails closed** on DB error.
- **CSRF**: 32-byte `random_bytes` token, `hash_equals` comparison, applied to state-changing POSTs.
- **Output escaping**: `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` applied consistently; the chat widget's `formatChatMessage()` escapes `& < >` *before* applying markdown, so AI/CMS content cannot inject HTML.
- **Booking integrity**: collision-checked `EV-YYYY-XXXX` reference generation, catering-pax validation, lead-time enforcement, OTP-gated commit (booking is only written after email verification).
- **Notification queue**: enqueue-then-dispatch with retry count, duplicate suppression, and credential redaction in error strings.

---

## 4. Recommended Fix Order

1. **W1** — re-enable TLS verification + move API key to header (one-line, highest impact).
2. **W2** — delete `qes/.env`, rotate all leaked credentials (Supabase service key, Gmail app password, Gemini key, Google OAuth secret, Turnstile secret, encryption key).
3. **W5** — deny web access to `cache/`.
4. **W4** — make decryption failures loud + add key versioning.
5. **W3** — correct the audit/README crypto claims.
6. **W6, W7, W8, W9** — hardening & cleanup.
7. **W10, W11** — refactor monoliths, add a real integration test for the booking lifecycle.

---

*Independent source-level review. Findings are reproducible from the cited file paths and line numbers.*
