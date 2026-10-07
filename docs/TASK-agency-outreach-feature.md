# Task: Agency Outreach Assistant (qualify agencies + generate outreach emails with AI)

## Goal

Add a feature to the existing system that takes a web agency's website URL, collects the useful text from its site, sends it to an AI API with a fixed prompt, and stores the AI's answer: whether the agency is worth contacting, who to contact, and a ready-to-send outreach email.

The user reviews every generated email and sends it themselves. **This feature must never send emails automatically.**

## Before you start

1. Explore the existing codebase first. Identify the language and framework, the folder structure, how config and secrets are stored, how the database is accessed (ORM or migrations), how admin pages and forms are built, and how background jobs or queues work (if any). Follow those conventions everywhere. Do not introduce a new framework or a second way of doing something the project already does.
2. If something in this task conflicts with how the project works, follow the project and note the difference in your summary.
3. The AI prompt is provided in `agency-qualifier-prompt.md` (next to this task file). Copy it into the project as a prompt file (for example `prompts/agency-qualifier/system.txt` and `prompts/agency-qualifier/user-template.txt`) and load it at runtime. Do not rewrite or shorten the prompt text, and do not paste it inline into code.

## User flow

1. The user opens an "Agency Outreach" admin page.
2. They paste one or more agency URLs (one per line) and click "Analyze".
3. For each URL the system:
   - normalizes the URL (strips `utm_*` and other tracking parameters, forces https, removes trailing slashes) and skips it if it was already analyzed (show the existing result instead),
   - collects the site's pages (see "Scraper"),
   - calls the AI (see "AI client"),
   - stores the result.
4. The list view shows every agency with: name, URL, decision badge (SEND / SEND_LOW_PRIORITY / SKIP), score, contact email, status, date analyzed. It can be sorted by score and filtered by decision and status.
5. The detail view shows: reasons, red flags, contact name and role, `to_email`, `other_channel`, `follow_up_tip`, and the email subject and body in **editable** fields.
6. Buttons on the detail view:
   - **Copy subject** and **Copy body** (copies the final text with the signature filled in)
   - **Open in Gmail** (a `https://mail.google.com/mail/?view=cm&fs=1&to=…&su=…&body=…` link) and **Open in mail app** (a `mailto:` link)
   - **Mark as sent** (records the date and calculates the follow-up date as 7 days later)
   - **Mark as replied** / **Not interested**
   - **Re-analyze** (runs the scrape and AI call again and keeps the previous result in history)
7. The list highlights agencies whose follow-up date has passed and that have no reply.

## Scraper

- Fetch the homepage, then find links on the same domain to these pages (match the link text or URL, case-insensitive):
  - about: `about`, `team`, `who-we-are`, `our-story`
  - contact: `contact`
  - careers: `career`, `jobs`, `hiring`, `join`, `work-with-us`
  - services: `services`, `what-we-do`
  Take at most one page per type. Fetch at most 6 pages per agency in total.
- For each page:
  - remove `<script>`, `<style>`, `<noscript>`, `<svg>` and HTML comments, then extract the visible text and collapse whitespace,
  - limit the text to about 6,000 characters per page so requests stay small and cheap.
- From the raw HTML of all fetched pages, extract:
  - **emails:** from `mailto:` links and from text matched by a regex. Drop image file names (`.png`, `.jpg`, `.webp`, `.svg`, `.gif`) and known junk (`sentry`, `wixpress`, `example.com`, `wordpress.org`, `john.doe`, `user@emaildomain.com`). Deduplicate.
  - **phones:** from `tel:` links. Deduplicate.
  - **platform:** WordPress (the `generator` meta tag or `wp-content` in the HTML), Wix (`wixstatic`), Squarespace, Webflow, Shopify, or `unknown`.
- Send a normal browser-like User-Agent, use a 15-second timeout per request, follow redirects, and wait about 1 second between requests to the same domain.
- If the homepage cannot be fetched, store the agency with status `fetch_failed` and the error message, and skip the AI call.
- Some sites load their content with JavaScript, so the text may be almost empty. If the total text is under about 300 characters, still call the AI, but add the warning `"Very little text found, site may need JavaScript"` to the record.

## AI client

- The provider must be configurable. Put the API key, model name and endpoint in the project's existing config or secrets system, never in code and never in the database. Mask the key on any settings page.
- Request:
  - system message = the system prompt file,
  - user message = the user template with these placeholders filled: `{URL}`, `{e.g. WordPress 6.9 / Wix / unknown}` (platform), the emails and phones lists, and the text of each page under its heading. Use `(not found)` for missing pages.
  - Ask for JSON output with the provider's JSON or structured-output mode if it has one. Allow at least 2,000 output tokens.
- Response handling:
  - Parse the JSON. If parsing fails, retry once. If it fails again, store status `ai_failed` together with the raw response.
  - Validate it: `decision` must be one of `SEND`, `SEND_LOW_PRIORITY`, `SKIP`; `score` must be an integer from 0 to 100; when the decision is not `SKIP`, `subject` and `body` must not be empty.
  - **Safety check:** if `to_email` is not one of the emails the scraper found, clear it (set it to null) and add the red flag `"AI suggested an email that is not on the site"`. The AI must never be the source of an email address.
- Retry on rate-limit and server errors (HTTP 429 and 5xx) with backoff, at most 3 attempts.
- Store the token usage from each response (input and output tokens) so the user can see the cost.
- When the user analyzes several URLs at once, process them one after another or through the project's existing job queue, so a long batch does not time out the web request. Show progress in the list (pending, analyzing, done, failed).

## Signature

- Add settings for the signature fields: email, phone and LinkedIn URL.
- When displaying, copying or opening an email, replace `{EMAIL}`, `{PHONE}` and `{LINKEDIN}` in the body with those settings. If a value is empty, remove the placeholder together with the ` · ` separator in front of it.
- Store the AI's original body unchanged. Store the user's edits separately (or as an "edited body" field), so re-rendering never overwrites what the user changed.

## Data to store (adapt to the project's database conventions)

Agency:
- id, normalized_url, domain (unique), agency_name
- status: `pending`, `analyzing`, `fetch_failed`, `ai_failed`, `analyzed`, `sent`, `replied`, `not_interested`
- created_at, analyzed_at, sent_at, follow_up_at, replied_at
- notes (free text for the user)

Analysis (one row per run, so a re-analysis keeps history):
- id, agency_id, created_at
- scraped data: fetched page URLs, extracted emails, phones, platform, warnings
- AI result: decision, score, reasons (JSON), red_flags (JSON), contact_name, contact_role, to_email, other_channel, ref_slug, subject, body, follow_up_tip
- edited_subject, edited_body (nullable)
- raw_response (for debugging), model, input_tokens, output_tokens

The agency list always shows the latest analysis.

## Out of scope

- Sending emails from the system (no SMTP, no Gmail API). The user sends manually.
- Finding agencies automatically (Google Places and similar). This feature only processes URLs the user pastes.
- Guessing or verifying email addresses with outside services.

## Acceptance criteria

- [ ] Pasting 3 URLs (one with `utm_` parameters) creates 3 agencies with normalized URLs. Pasting one of them again does not create a duplicate.
- [ ] For a WordPress agency site, the scraper finds the About and Contact pages, extracts the emails and phones shown on them, and detects WordPress.
- [ ] Junk emails (such as `john.doe@gmail.com` or `@sentry.wixpress.com` addresses) never appear in the extracted list.
- [ ] Each analyzed agency shows a decision, score, reasons and red flags. SEND and SEND_LOW_PRIORITY results also show an editable subject and body.
- [ ] A `to_email` that was not found by the scraper is never shown; it is cleared and flagged.
- [ ] The signature placeholders are replaced from settings in the copy, Gmail and mailto actions; empty fields leave no stray separators.
- [ ] Editing the body and reloading the page keeps the edit. Re-analyzing creates a new analysis without deleting the old one.
- [ ] "Mark as sent" sets the follow-up date to 7 days later, and overdue follow-ups are highlighted in the list.
- [ ] An unreachable site ends as `fetch_failed`, invalid AI JSON (after one retry) ends as `ai_failed`, and neither crashes the batch.
- [ ] The API key is read only from config or secrets and appears nowhere in code, logs or the database.
- [ ] Nothing in the feature sends an email.

## Tests

Write tests in the project's existing test framework:
- URL normalization and duplicate detection
- email, phone and platform extraction from saved sample HTML (include junk addresses and a `mailto:` link)
- HTML-to-text cleaning (scripts and styles removed)
- AI response validation (valid JSON, invalid JSON, wrong decision value, `to_email` not on the site)
- signature placeholder replacement, including empty fields

Mock the AI API in tests. Tests must not call the real API or real websites.

## When you finish

Report:
- the files you added or changed,
- the database migrations,
- the new config or secret keys and where to set them,
- how to open the feature in the app,
- any place where you followed the project's conventions instead of this task.
