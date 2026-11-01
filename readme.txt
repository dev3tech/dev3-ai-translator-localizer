=== DEV3 AI Translator & Cultural Localizer for WPML, TranslatePress & Polylang ===
Contributors: dev3tech
Tags: translation, multilingual, polylang, wpml, translatepress
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI translation with cultural adaptation, glossary, translation memory and delta sync for WPML, TranslatePress and Polylang.

== Description ==

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

The translation engine is Google Gemini (default model gemini-2.5-pro, configurable).

= Privacy =

The text you translate (titles, content, excerpts, selected custom fields, SEO fields, term names) and your glossary and locale instructions are sent to the Google Gemini API using your own API key, only when a translation runs: manually, in a bulk job, or by auto-sync after you save a translated post. No visitor data is sent. The API key is encrypted in your database (AES-256-GCM) and never sent to the browser.tech. Usage logs (task, model, token counts) are stored in your database and can be turned off.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
2. Make sure WPML, Polylang or TranslatePress is active with at least two languages.
3. Create a key at https://aistudio.google.com/app/apikey and paste it in DEV3 AI Translator → API Config ("Validate & save").
4. Open DEV3 AI Translator → Bulk Translate, pick content and languages, estimate the cost and start.

You can also define the key in wp-config.php: `define( 'DEV3TL_GEMINI_API_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Can I use Polylang and TranslatePress together? =
No. They both control language URLs and cannot be active at the same time. Activate one multilingual plugin.

= Will a sync overwrite my corrections? =
No. Segments you edit or approve in Review are marked human-edited/approved and are never replaced by the AI. Translations edited directly in WordPress are skipped by syncs unless you choose to overwrite them.

= How much does it cost? =
Bulk Translate shows an estimate (segments, tokens, approximate cost) before you start. Unchanged content is reused from the translation memory, so updates are cheap.

= Who can use it? =
Users with `edit_posts` can translate and review posts they can edit; users with `manage_options` manage settings, glossary and bulk jobs. Both can be changed with the `dev3tl_capability` and `dev3tl_manage_capability` filters.

== Changelog ==

= 1.0.0 =
* Initial release.
