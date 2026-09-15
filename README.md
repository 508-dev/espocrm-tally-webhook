# EspoCRM Tally Webhook Intake

An EspoCRM custom module that turns [Tally](https://tally.so) form submissions into
Contact records, with a browsable log of every attempt. Built for
[508.dev](https://508.dev)'s member-intake form; published here so other EspoCRM
users don't have to start from zero.

**This is a working example, not a turnkey plugin.** The field mapping is tied to
508.dev's specific Tally form and EspoCRM schema (custom fields like `cDiscordUsername`,
`cRoles`, `cSeniority`, a `skills` multi-select, a `resume` attachment field, etc.).
You will need to edit `PostWebhook.php` to match your own form and fields — see
[Adapting this to your own CRM](#adapting-this-to-your-own-crm) below.

## What it does

- Exposes `POST /api/v1/TallyWebhook/:secret` — an EspoCRM API route, secured by a
  shared secret in the URL path, that Tally's webhook can call directly.
- Maps Tally's form-response payload onto Contact fields: plain text fields, an
  auto-detected full-name split into first/last, option-based fields (Tally sends
  option UUIDs + a lookup table; this resolves them to text), and a couple of
  type-specific conversions (a range-choice mapped to an int field, a single URL
  wrapped into an array field, etc.).
- Downloads and attaches an uploaded file (e.g. a resume) to the Contact via
  EspoCRM's own Attachment entity, immediately at submission time — Tally's file
  URLs carry a time-limited access token, so this can't be deferred.
- Deduplicates by email address (returns the existing Contact instead of creating
  a duplicate).
- Logs every attempt — created, duplicate, or error, with the raw payload — to a
  new `TallyWebhookLog` entity, browsable from EspoCRM's own nav. This is the
  piece that's genuinely CRM-agnostic and reusable as-is: EspoCRM has no built-in
  way to see inbound-webhook activity, so this adds one.

## Why not EspoCRM's built-in Lead Capture?

EspoCRM already ships a similar feature (`POST /api/v1/LeadCapture/:apiKey`,
Admin-UI configurable, with its own logging) — but it only creates **Lead**
records. We wanted new sign-ups to land as **Contacts** (`type: Prospect`)
directly, to match an existing Discord-bot integration that already creates
Contacts the same way. If your CRM's intake flow goes through Leads instead,
EspoCRM's native Lead Capture may be all you need, with none of this.

## Installation

1. Copy `custom/` from this directory into your EspoCRM installation root
   (merging with any existing `custom/` directory — this only adds new paths
   under `custom/Espo/Custom/`, it doesn't touch anything else).
2. Set a shared secret in EspoCRM's config. From the EspoCRM container/server:
   ```php
   // one-off, or add to data/config.php directly
   $config = include 'data/config.php';
   $config['tallyWebhookSecret'] = bin2hex(random_bytes(24));
   file_put_contents('data/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
   ```
3. Run `php rebuild.php` (or `docker exec <espocrm-container> php rebuild.php`).
4. Point your Tally form's webhook at
   `https://<your-espocrm-domain>/api/v1/TallyWebhook/<your-secret>`.

## Adapting this to your own CRM

Open `custom/Espo/Custom/Tools/TallyWebhook/Api/PostWebhook.php` and edit:

- **`FIELD_MAP`** — keyed by Tally's stable field `key` (e.g. `question_qOGXgG`,
  visible in any real webhook payload from your form), not by label text, so
  relabeling a question in Tally later doesn't silently break the mapping. Get
  your own form's keys by triggering one real test submission and reading the
  payload Tally sends.
- **`ROLE_ID_MAP`** / **`SENIORITY_MAP`** — translate Tally's option UUIDs (for
  MULTI_SELECT / MULTIPLE_CHOICE questions) into your own CRM's picklist values.
  Anything not explicitly mapped falls back to the option's own text, lowercased
  — harmless for a multi-enum field that allows custom options, but will fail
  validation for a strict enum, so map every option explicitly for those.
- The **`resume`** attachment target field name, if your Contact (or whichever
  entity) doesn't already have an `attachmentMultiple` field by that name.
- Swap `Espo\Modules\Crm\Entities\Contact` for `Espo\Entities\Lead` (or your own
  target entity) if you want the native Lead pipeline instead.

## Security notes

- The endpoint is unauthenticated by EspoCRM's normal means (`noAuth: true` in
  `routes.json`) and does its own secret check via `hash_equals()` against
  `tallyWebhookSecret` in config — rotate that value if you suspect it's leaked.
  A bad secret is rejected with 403 and logged as a warning (not written to
  `TallyWebhookLog`, since it's not a legitimately-shaped attempt).
- No `delete` or `create` capability is required on any API key for this to
  work — the module runs as backend code inside EspoCRM, not through the
  ordinary ACL-checked API.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
