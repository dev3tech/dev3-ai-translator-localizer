# DEV3 AI Translator & Cultural Localizer for WPML, TranslatePress & Polylang

AI translation with cultural adaptation, glossary, translation memory and delta sync for WPML, TranslatePress and Polylang.

## Features

Translate posts, pages, WooCommerce products and custom post types into every language of your multilingual plugin — adapted for each market, not translated word for word.

* **Cultural adaptation per language** – formal or informal address (Sie/du, vous/tu…), metric or imperial units with pre-calculated conversions, currency handling, date and number formats, idiom adaptation, brand voice and custom instructions.
* **Block-aware segmentation** – paragraphs, headings, list items, buttons, image alt text, captions and block attributes; markup, links, shortcodes and block settings are preserved exactly.
* **Translation memory & delta sync** – when you edit a source post only the changed paragraphs are sent to the AI; everything else is reused ("X of Y segments reused").
* **Glossary & brand constraints** – do-not-translate terms and forced translations per language, enforced in the instructions and checked in every answer (automatic retry).
* **Bulk translation** – whole post types into several languages in a background queue (Action Scheduler or WP-Cron) with cost estimate, progress, pause/resume/cancel and a per-item log.
* **Review** – side-by-side editor per segment; human edits and approvals are protected from later syncs.
* **Beyond content** – title, excerpt, slug, Yoast SEO / Rank Math fields, WooCommerce attributes and variation descriptions, custom fields, taxonomy terms, site title and tagline.

Integrations:

* **Polylang** – translations are linked posts and terms (pll_* API).
* **WPML** – translations join the source's translation group through WPML's public hooks.
* **TranslatePress** – rendered strings are written into TranslatePress' dictionary using its own parser and query classes; paragraphs with links become translation blocks.

### Privacy
The text you translate (titles, content, excerpts, selected custom fields, SEO fields, term names) and your glossary and locale instructions are sent to the Google AI API using your own API key, only when a translation runs: manually, in a bulk job, or by auto-sync after you save a translated post. No visitor data is sent. The API key is encrypted in your database (AES-256-GCM) and never sent to the browser.tech. Usage logs (task, model, token counts) are stored in your database and can be turned off.

## Requirements

- WordPress 6.0 or newer (tested up to 6.8)
- PHP 7.4 or newer
- Optional: your own Google AI API key (from Google AI Studio) to switch on the DEV3 AI features. The rest of the plugin works without one.

## Installation

### From the release zip

1. Download `dev3-ai-translator-localizer.zip` from the [latest release](https://github.com/dev3tech/dev3-ai-translator-localizer/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**.

### Build from source

```bash
git clone https://github.com/dev3tech/dev3-ai-translator-localizer.git
cd dev3-ai-translator-localizer
composer install --no-dev
npm ci
npm run build
```

Then copy the `dev3-ai-translator-localizer` folder into `wp-content/plugins/` and activate it.

### Getting started

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
2. Make sure WPML, Polylang or TranslatePress is active with at least two languages.
3. Create a key at https://aistudio.google.com/app/apikey and paste it in DEV3 AI Translator → API Config ("Validate & save").
4. Open DEV3 AI Translator → Bulk Translate, pick content and languages, estimate the cost and start.

## FAQ

### Can I use Polylang and TranslatePress together?
No. They both control language URLs and cannot be active at the same time. Activate one multilingual plugin.

### Will a sync overwrite my corrections?
No. Segments you edit or approve in Review are marked human-edited/approved and are never replaced by the AI. Translations edited directly in WordPress are skipped by syncs unless you choose to overwrite them.

### How much does it cost?
Bulk Translate shows an estimate (segments, tokens, approximate cost) before you start. Unchanged content is reused from the translation memory, so updates are cheap.

### Who can use it?
Users with `edit_posts` can translate and review posts they can edit; users with `manage_options` manage settings, glossary and bulk jobs. Both can be changed with the `dev3tl_capability` and `dev3tl_manage_capability` filters.

## Documentation

The full HTML guide (installation, settings, hooks and FAQ) is in [`docs/index.html`](docs/index.html). Open it in a browser after cloning or downloading the repository.

## Changelog

### 1.0.0
* Initial release.

## Licence

Released under the GNU General Public License v2.0 or later (GPL-2.0-or-later). See [LICENSE](LICENSE).

## More from DEV3

- [DEV3 AI Translator & Cultural Localizer for WPML, TranslatePress & Polylang on the DEV3 Marketplace](https://www.dev3.tech/item/dev3-ai-translator-localizer)
- [More WordPress plugins, themes and templates from DEV3](https://www.dev3.tech/marketplace)
