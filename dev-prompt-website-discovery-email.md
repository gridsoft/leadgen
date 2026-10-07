# Task: Website Discovery, Email Pattern Verification & Lead Classification

## Context

You are working on my lead generator. It collects small local businesses in the USA and Canada (roofers, HVAC, cleaning services, auto repair, etc.) from Foursquare OS Places, Overture Maps, and Google Places. The goal is a list of **verified business emails** for cold email outreach offering website builds.

Each lead already has: business name, phone, city, region/state, country, category, and sometimes a website and/or email.

An existing step, **"Step 2 – Site Email Extractor"**, crawls a known website and extracts emails from it. Your job is to build the three modules that handle the leads where that isn't enough:

- **Module A – Website Discovery:** the lead has no known website.
- **Module B – Pattern Guess + Verification:** the lead has a domain, but no email was found on the site.
- **Module C – Classification:** decide the final outreach status for every lead.

**Before writing any code:** inspect the existing codebase. Find the lead model, the Step 2 extractor, and how background jobs run. Then give me a short implementation plan (files to add or change, schema changes, libraries you'll use) and wait for my OK.

Use the project's existing language, framework, and conventions. If Step 2 doesn't exist yet, define its interface and stub it:
`extractEmailsFromSite(domain) -> list of {email, page_url}`

---

## Overall flow per lead

```
lead
 ├─ email in dataset?        → verify → valid: email_ready / otherwise continue
 ├─ website known?           → Step 2 extractor → email found → verify
 │                                              → no email    → Module B
 └─ no website known         → Module A
                                 ├─ site accepted  → Step 2 → no email → Module B
                                 └─ nothing found  → Module C (phone_only)

Every lead ends in Module C with a final outreach_status and a status_reason.
```

Emails found by Step 2 or from the dataset also go through verification before they can become `email_ready`.

---

## Module A – Website Discovery

### A1. Phone normalization

- Use libphonenumber (PHP: `giggsey/libphonenumber-for-php`, Python: `phonenumbers`).
- Normalize the lead's phone to E.164 (`+1XXXXXXXXXX`). The matching key is the 10-digit national number.
- If the phone is missing or invalid, skip Module A (reason: `no_valid_phone`), because we can't confirm a match without it.

### A2. Search

- Create a provider-agnostic interface: `SearchProvider.search(query, limit) -> [{url, title, snippet}]`.
- Implement one concrete provider, selected via config (`SEARCH_PROVIDER`, `SEARCH_API_KEY`). I'll tell you which provider to use.
- **Query 1:** `"{name}" {city} {region}`
- **Query 2** (only if Query 1 gives no accepted site): the phone in quotes, formatted `"(XXX) XXX-XXXX"`. Phone searches often surface the business's own site directly.
- Max 2 queries per lead and top 5 results per query (both configurable).
- Cache search results by query string (TTL 30 days).

### A3. Filter candidates

- Normalize each result URL to its registrable domain using a public suffix library (Python: `tldextract`, PHP: `jeremykendall/php-domain-parser`).
- **Drop blocklisted domains**, matching the domain and all its subdomains. The blocklist lives in a config file. Defaults:
  `yelp.com, yelp.ca, facebook.com, instagram.com, linkedin.com, x.com, twitter.com, tiktok.com, youtube.com, pinterest.com, nextdoor.com, bbb.org, yellowpages.com, yellowpages.ca, superpages.com, angi.com, angieslist.com, homeadvisor.com, thumbtack.com, houzz.com, porch.com, bark.com, buildzoom.com, manta.com, mapquest.com, google.com, bing.com, apple.com, foursquare.com, tripadvisor.com, chamberofcommerce.com, bizapedia.com, opencorporates.com, 411.ca, canpages.ca, wikipedia.org, reddit.com, craigslist.org, expertise.com, birdeye.com, networx.com`, plus all `.gov` domains.
- **Keep site-builder subdomains** (e.g. `*.wixsite.com`, `*.square.site`, `*.godaddysites.com`, `*.business.site`). These ARE the business's website. For them, treat the full host as the "domain".
- Deduplicate candidates.

### A4. Validate each candidate (in order)

1. Fetch the homepage (follow redirects, max 5). Reject it if the request fails, the response isn't HTML, or it's a **parked / for-sale page**. Detect those with phrases like "domain is for sale" or "buy this domain", known parking hosts (Sedo, Bodis, Dan.com, Afternic), or a near-empty page.
2. Extract phone numbers from `tel:` links, visible text (regex for North American formats), and JSON-LD `telephone` fields. Normalize each one and compare the 10-digit national numbers.
3. If there's no match on the homepage, fetch up to 2 pages on the same host whose link text or URL contains `contact`, `about`, or `location`, and check again.
4. **Phone match → accept.** Store `website_discovered`, `discovery_source = search`, and `discovery_evidence_url` (the page where the phone matched).
5. **No phone match, but a strong name match** (see name normalization below, token similarity ≥ 0.85) AND the city appears on the page → do NOT accept. Save it as a candidate with `outreach_status = needs_review`, reason `name_match_only` (the business may have changed its number).
6. Stop at the first accepted candidate.

**Name normalization:** lowercase; strip punctuation; remove `llc, inc, co, corp, ltd, the, &, and`; collapse whitespace.

### A5. Chain / aggregator guard

If the same discovered domain is accepted for more than 3 leads (configurable), flag all of them with reason `shared_domain` and set `needs_review`. That domain is likely a franchise HQ, a lead-gen network, or a directory missing from the blocklist. Add it to a "suggested blocklist" report.

After acceptance, pass the site to **Step 2**.

---

## Module B – Pattern Guess + Verification

**Preconditions:** the lead has a domain (from the dataset or Module A), and Step 2 found no usable email.
**Skip** if the "domain" is a site-builder subdomain (`*.wixsite.com`, etc.), since no email can exist there.

1. **DNS MX lookup** on the domain. No MX record → reason `no_mx` → go to Module C.
2. **Candidates, in order (configurable):** `info@`, `contact@`, `office@`.
3. **Verify** via a provider-agnostic interface: `EmailVerifier.verify(email) -> {status, raw_response}`.
   - Normalize provider statuses to: `valid`, `invalid`, `catch_all`, `unknown`, `disposable`.
   - Implement one concrete provider, selected via config (`VERIFIER_PROVIDER`, `VERIFIER_API_KEY`). I'll tell you which one.
4. **Stop at the first `valid` result.** Store `email_source = pattern`, `email_confidence = medium`.
   - These are role addresses on purpose. Do NOT reject them because a provider flags them as "role".
5. **`catch_all`:** the domain accepts every address, so we can't confirm anything. Don't spend credits on the remaining patterns. Store `info@` with `email_verification = catch_all`, `email_confidence = low`, and `outreach_status = needs_review`. Config flag `SEND_CATCH_ALL=false` by default.
6. **`unknown`:** re-queue one retry after 24 hours, then treat it as not found.
7. **All candidates invalid:** reason `all_patterns_invalid` → Module C.

**Never do direct SMTP RCPT probing from our own server.** It gets our IPs blacklisted. Use only the verification API.
Cache verification results per address for 60 days.

---

## Module C – Classification

### Fields to add (with a DB migration)

| Field | Values / notes |
|---|---|
| `outreach_status` | `email_ready` / `phone_only` / `needs_review` / `excluded` |
| `status_reason` | `no_valid_phone`, `no_site_found`, `no_mx`, `all_patterns_invalid`, `catch_all`, `shared_domain`, `name_match_only`, `business_closed`, `casl_check`, `suppressed`, … |
| `email` | final chosen email |
| `email_source` | `dataset` / `website` / `pattern` |
| `email_verification` | `valid` / `invalid` / `catch_all` / `unknown` / `disposable` |
| `email_confidence` | `high` (dataset or website + valid), `medium` (pattern + valid), `low` (catch_all) |
| `website_discovered`, `discovery_source`, `discovery_evidence_url` | from Module A |
| `enrichment_log` | JSON list of `{step, result, reason, timestamp}` |
| `enriched_at` | timestamp |

### Rules

- **`email_ready`** only when the email's verification is `valid`.
- **`phone_only`** when no email was found after all steps and the phone is valid. These leads are automatically left out of the email campaign.
- **`excluded`** when the business is closed (`date_closed` or business status), when there's no valid phone and no email, or when the email or domain is on the suppression list.
- **Canada (`country = CA`):** never auto `email_ready`. Set `needs_review` with reason `casl_check`, because Canada's anti-spam law (CASL) is stricter than US rules. Make this a config flag.
- **Enforce this in the data layer:** the campaign export must select only `email_ready` leads that are NOT on the unsubscribe/suppression list. Put this in the export query/repository, not only in the UI.
- `phone_only` leads get their own CSV export for calling.

---

## Non-functional requirements

- **Jobs:** use the existing queue. If there isn't one, build a simple DB-backed job table plus a worker CLI.
- **Politeness and limits:** 5–10 concurrent workers; max 1 request per 2 seconds per host; 10s connect timeout, 20s total; max response size 2 MB; 2 retries with exponential backoff on network errors and 5xx (no retry on 4xx).
- **Respect robots.txt.** Use a descriptive User-Agent that includes a contact URL (from config).
- **Don't bypass CAPTCHAs, logins, or bot protection.** If blocked, mark the candidate `unreachable` and move on.
- **Idempotent:** re-running skips leads enriched in the last N days (config) unless `--force` is passed.
- **Cost control:** daily caps for search queries and verifications (config). When a cap is reached, pause jobs and log it. Store daily usage counters in the DB.
- **Config:** all thresholds, patterns, the blocklist, and caps in one config file; API keys in environment variables.
- **Logging:** every decision is written to `enrichment_log` with a reason, so I can see why each lead ended where it did.

---

## CLI commands

```
enrich --niche roofing --city "Austin" --region TX --limit 200 [--force] [--dry-run]
export --status email_ready --format csv
export --status phone_only  --format csv
report   # counts by status and reason, API usage today, suggested blocklist domains
```

---

## Tests

**Unit tests:**

- Phone normalization and matching: `(512) 555-0123`, `512.555.0123`, `512-555-0123`, `+1 512 555 0123`, `1-512-555-0123`, `tel:+15125550123`, JSON-LD `telephone`; plus mismatch cases.
- Blocklist: `m.yelp.com`, `yelp.ca`, `www.facebook.com/somepage` are blocked; site-builder subdomains are kept.
- Domain extraction with multi-part suffixes (public suffix library).
- Parked page detection using HTML fixtures.
- Name normalization and similarity.
- Verifier status mapping and catch-all handling.
- Classification rules, including CA → `needs_review`.

**Integration tests** (mocked search provider and verifier, local HTML fixtures), covering the full flow for:

1. Site found via the name query with a phone match.
2. Site found only via the phone query.
3. Name match without phone match → `needs_review`.
4. Domain without an email on the site → `info@` verified valid → `email_ready`.
5. Catch-all domain → `needs_review`.
6. Nothing found → `phone_only`.
7. Shared domain guard triggers.

---

## Acceptance criteria

- Running `enrich` on a batch gives every lead a final `outreach_status` and a `status_reason`.
- No lead reaches `email_ready` without a `valid` verification.
- No blocklisted domain is ever accepted as a business website.
- A website is only auto-accepted when the lead's phone number appears on it, and the evidence URL is stored.
- The email campaign export contains only `email_ready` leads that aren't suppressed.
- Daily API caps are enforced.
- All tests pass.

## Deliverables

Code, DB migration, config file with sensible defaults, tests, and a README section explaining setup, config, and the CLI commands.
