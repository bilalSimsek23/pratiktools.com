# Public website and CMS (F7.5)

`https://pratiktools.com/` is the PratikTools company website. The SaaS panel is
unchanged under `/app`; the API under `/api`.

## Architecture

| Part | Where | Notes |
|---|---|---|
| Public pages | `routes/site.php`, `App\Http\Controllers\Site` | No session, no cookie, no tenant context; `Cache-Control: public, max-age=300` |
| Content | `site_categories`, `site_contents`, `site_content_relations`, `site_media`, `site_settings`, `site_events_daily` | Platform-level marketing content, **no tenant_id, no personal data** |
| Management | `/app/website/*` (`WebsiteController`) | Only when the panel tenant is the site owner (setting `owner`; `EnsureSiteOwner`, else 404) |
| Inquiries | `POST /iletisim/talep` → F6 `LeadCapture` + `lead_inquiries` | Tenant data of the owner tenant (FORCE RLS, PII list) |

A public visitor never gets a tenant context, so every tenant table stays
invisible (FORCE RLS returns nothing). Website code never reads tenant tables
except the inquiry write inside the owner tenant's context.

## Routes

`/`, `/hizmetler`, `/hizmetler/{slug}`, `/cozumler`, `/cozumler/{slug}`,
`/projeler`, `/projeler/{slug}`, `/blog`, `/blog/{slug}` (`?kategori=` filter,
noindex), `/hakkimizda`, `/iletisim`, `/iletisim/tesekkurler` (noindex),
`/sitemap.xml`, `/robots.txt`, `/media/{id}/{width}.{webp|jpg}`, `POST /t`
(event beacon), `POST /iletisim/talep`.

## Content model

Types: `service`, `solution` (ad landing page), `project` (case study),
`article`, `page` (`home`, `hakkimizda`, `iletisim`). Status `draft →
published → archived`; only published content is public, in the sitemap and
indexable. Slugs are unique per type and fixed once published.

Fields: title, slug, excerpt, Markdown body, SEO title/description, social
image, category, featured, order, related content, plus type fields
(`ContentType::fields()`): hero, problem, technical approach, benefits,
features, integrated systems, use cases, FAQ, CTA (services/solutions;
solutions add examples and form type); client display name, real client name,
industry, problem, solution, technologies, integrations, measurable outcome
(projects); author (articles); home sections (pages).

Projects are anonymous by default. The real client name appears only when
"Müşteri adı yayınlansın" is set **and** a client approval (how it was
obtained) is recorded by a publisher; changing the name drops the approval and
publishing refuses without it.

## Permissions (only in the owner tenant)

| | owner | admin | marketer | approver | viewer |
|---|---|---|---|---|---|
| site.view | x | x | x | x | |
| site.edit (drafts, categories, images) | x | x | x | | |
| site.publish (publish/archive, client approval) | x | x | | x | |
| site.leads (inquiries, personal data) | x | x | x | | |
| site.settings (organization, analytics, crawlers) | x | x | | | |

Publishing, status changes, client approval and settings need a recent
password confirmation; every change is audited.

## Security

- Bodies are Markdown rendered with raw HTML stripped; `javascript:`/`data:`
  links removed; images only from `/media/`; outbound links `noopener nofollow`.
  Every other field is plain text, escaped on output. No Blade/PHP/script in content.
- JSON-LD is encoded with `<`, `>`, `&`, quotes escaped.
- Uploads: JPEG/PNG/WebP detected from the bytes (no SVG/GIF), ≤ 5 MB, pixel
  limit, decoded and re-encoded with GD (metadata and appended payloads
  dropped), served from our origin with `immutable` caching.
- Analytics: only validated ids (GA4 `G-…`, Google Ads `AW-…` + label, Meta
  Pixel digits). `site.js` loads providers after the visitor accepts the
  consent banner; the CSP is widened per page only for those providers' hosts.
  No pasted scripts anywhere.
- Public pages send `Referrer-Policy: strict-origin-when-cross-origin` (so form
  posts carry an Origin); everything else keeps `no-referrer`.
- `/app`, `/api`, admin host and technical endpoints send `X-Robots-Tag: noindex, nofollow`.

## Inquiry form

Fields: name, e-mail, phone (optional), company (optional), interest
(published services/solutions), message, optional marketing opt-in, form type
(Teklif Al / Görüşme Talep Et / Projenizi Anlatın / Demo Talep Et).

Protection: same-origin (Origin; Referer only if no Origin; `null` refused),
16 KB payload limit, 5/min and 20/h per IP (plus a route throttle), honeypot,
signed render timestamp (≥ 3 s, ≤ 24 h), the F6 bot verifier, field limits.
Accepted, duplicate and bot submissions get the same answer (no enumeration).

Storage: F6 lead capture in the owner tenant (lead customer, consent
recipient, metrics, idempotency by a per-page-load submission id; marketing
consent only if ticked — never İYS ONAY) plus `lead_inquiries` with the
message and attribution.

Attribution (first touch kept 90 days in the browser, last touch per visit):
utm source/medium/campaign/content/term, landing path (no query string),
referrer host, presence of gclid/fbclid/msclkid (never the value), and a class:
`google_ads`, `meta_ads`, `ai_search`, `campaign`, `organic`, `referral`, `direct`.

## SEO and AI crawlers

Per page: unique title, meta description, canonical (no query), robots,
Open Graph, Twitter card, visible breadcrumbs, internal links (categories,
related and referencing content, footer).

JSON-LD: Organization + WebSite (home), Service (services/solutions),
BlogPosting (articles), BreadcrumbList, FAQPage only when the FAQ is shown.

`robots.txt` (dynamic; the static file was removed):

- `User-agent: *` and `User-agent: OAI-SearchBot`: allow `/`, disallow `/app`,
  `/api`, `/u/`, `/health`, `/webhooks`, `/public/v1`, form/beacon endpoints.
- `User-agent: GPTBot` (model training): **disallowed by default**; Site
  Ayarları can allow it. It is a separate decision from search visibility.
- `Sitemap:` line.

Everything important is in the server-rendered HTML; no auth or CAPTCHA for
crawlers.

## Measurement

First-party counters without cookies or personal data (`site_events_daily`):
page_view, service/solution/project/article_view, lead_form_open, lead_submit
(server side), contact_click. The same events go to GA4 / Google Ads (lead
conversion label) / Meta Pixel when configured and consented. Meta Conversions
API needs a secret (vault) and is not implemented yet.

## Settings (source of truth)

All changeable, non-secret site settings are rows in `site_settings`, edited
in **/app › Web Sitesi › Formlar ve Site Ayarları** (`site.settings`, recent
password confirmation, audited):

| Setting | Group | Notes |
|---|---|---|
| Owner tenant | `owner` | Changing it needs an explicit confirmation; the previous owner loses access |
| Contact e-mail, phone, address, profile links, KVKK URL | `organization` | Validated; shown on the site and in schema |
| GA4, Google Ads (+ lead label), Meta Pixel ids | `analytics` | Ids only, pattern-validated |
| GPTBot policy | `crawlers` | allow / disallow |
| Image disk | `media` | One of the configured disks; production accepts only a persistent (object storage) disk |
| Page cache seconds | `delivery` | 0–3600 |
| Website lead form | `lead_form` | Written by `site:setup` |

Environment variables (`SITE_OWNER_TENANT`, `SITE_LEAD_FORM`,
`SITE_MEDIA_DISK`, `SITE_CACHE_SECONDS`) are read only while the
corresponding setting has never been saved (first boot); normally they stay
unset. Secrets are never site settings: storage credentials stay in Laravel
Cloud secrets, API secrets (e.g. Meta Conversions API) in the vault. User
passwords are stored only as hashes.

## Operator setup

1. Create the PratikTools tenant and its owner:
   `php artisan tenant:create "PratikTools" pratiktools --owner-email=<email> --owner-name="…"`
   (password at the hidden prompt, never in the command text).
2. `php artisan site:setup --tenant=pratiktools` — records the tenant as the site
   owner and creates the website lead form (origin = APP_URL). Idempotent;
   refuses if another tenant already owns the site.
3. Attach an object storage bucket (credentials as Cloud secrets) and select
   its disk in Site Ayarları before uploading images.
4. In Site Ayarları: contact details, KVKK URL, analytics ids, GPTBot policy.
5. Review the starting content; fill in and publish case studies.

## References (Referanslar)

`site_references` ("we worked with") is separate from projects/case studies
("what we did"); `site_reference_contents` links a reference to any number of
projects, services and solutions. Everything is managed in
**/app › Web Sitesi › Referanslar**: add, rename, describe, capabilities,
website URL, logos (light/dark), linked case studies, homepage on/off and
order, draft/publish/archive, delete (drafts/archived). No code, deploy or
migration is ever needed for a change.

Public rules (`/referanslar`, `/referanslar/{slug}`, homepage "Birlikte
çalıştığımız markalar" + "Seçili çalışmalar"):

- shown only when **published + active + client-name approval recorded**;
  publishing refuses without that approval;
- the logo only with a separate **logo-use approval**; otherwise the name is
  shown as text (an operator decision per reference, never assumed);
- renaming drops the name approval; a new light logo drops the logo approval;
- a detail page exists only when there is a description or a published linked
  case study; otherwise the reference stays in the list;
- the homepage shows `homepage_featured` references in `featured_order`.

Logos use the same hardened image pipeline (JPEG/PNG/WebP, re-encoded,
alt text required; SVG not accepted) and need a persistent object storage
disk in production.

| Permission | owner | admin | marketer | approver |
|---|---|---|---|---|
| site.references.view | x | x | x | x |
| site.references.edit (texts, relations, order, homepage) | x | x | x | |
| site.references.manage_logo | x | x | x | |
| site.references.publish (publish, archive, delete) | x | x | | x |
| site.references.approve_client (name/logo approvals) | x | x | | |

Audited: created, changed, published, archived, deleted, homepage/order
changed, name approval, logo approval, logo changed.

Starting references (IGS, Dost TV, DKM, N-Extent, MotionCurate, PazarRadar)
are installed once as drafts, featured in that order, with **no approvals**;
they appear only after an operator records the approvals and publishes.

## Starting content

Installed once by a migration (`InitialContent`, only missing slugs):
15 service categories, 9 article categories, published pages (home, about,
contact), 9 service pages and 4 ad landing pages, and 10 case-study **drafts**
without client data. The text describes capabilities and approach only — no
clients, references, success rates or revenue claims.
