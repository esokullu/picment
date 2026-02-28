=== AI Featured Image Generator ===
Contributors:      baracksok
Tags:              ai, featured image, image generator, openai, dall-e
Requires at least: 6.0
Tested up to:      6.9
Requires PHP:      7.4
Stable tag:        1.0.1
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Auto-generate stunning DALL-E 3 AI featured images for every WordPress post. Bulk generation, per-post control, BYOK mode, and subscription plans.

== Description ==

**AI Featured Image Generator** uses OpenAI's DALL-E 3 model to automatically create beautiful, relevant featured images for your blog posts — saving you time and keeping your content visually consistent.

= How It Works =

Every time you publish a post, the plugin automatically generates a high-quality featured image based on your post's title and content. You can also run bulk generation across all your existing posts from a single admin page.

= Key Features =

* **Auto-generate on publish** — a featured image is created automatically when you publish a new post
* **Bulk generate** — generate images for all existing posts from one admin page, with a real-time progress bar
* **Per-post control** — enable or disable auto-generation per post via the sidebar metabox, and trigger generation manually with one click
* **DALL-E 3 quality** — uses OpenAI's latest and most capable image generation model
* **Configurable image settings** — choose size (landscape, square, portrait), quality (HD or standard), and style (vivid or natural)
* **Custom prompt template** — override the built-in prompt with your own using `{title}` and `{content}` placeholders
* **Images saved to Media Library** — generated images are downloaded and stored in your WordPress media library
* **Block & FSE theme compatible** — works with all modern WordPress themes
* **BYOK mode** — use your own OpenAI API key (free, rate-limited to 10 requests/min)
* **Subscription plans** — buy monthly credits (Starter, Pro, Agency) for high-volume use without managing your own API key

= Billing Modes =

**Bring Your Own Key (BYOK)** — Enter your own OpenAI API key. All API calls go directly from your server to OpenAI. You pay OpenAI directly. Rate-limited to 10 requests/minute.

**Trial** — New installs get 1 free credit to test the plugin with zero configuration.

**Starter** ($7/month) — 20 AI image credits per month. No OpenAI account needed.

**Pro** ($19/month) — 100 AI image credits per month. Best for active blogs.

**Agency** ($49/month) — 400 AI image credits per month. Ideal for managing multiple sites or high-volume content.

= Requirements =

* WordPress 6.0+ with PHP 7.4+
* A server with outbound HTTPS access
* For BYOK mode: an [OpenAI API key](https://platform.openai.com/api-keys) with access to the `dall-e-3` model

= Getting Started =

**Option A — Use a subscription plan:**

1. Install and activate the plugin
2. Go to **AI Image Gen → Billing** and subscribe to a plan
3. Publish a post — a featured image will be generated automatically

**Option B — Bring your own OpenAI key:**

1. Install and activate the plugin
2. Go to **AI Image Gen → Billing** and enter your OpenAI API key under "Bring Your Own Key"
3. Publish a post — a featured image will be generated automatically

**Option C — Try it free:**

1. Install and activate the plugin — you receive 1 free trial credit automatically
2. Go to **AI Image Gen → Generate** and generate your first image

== Installation ==

1. Upload the `wp-ai-image` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **AI Image Gen → Billing** to choose your billing mode

== Frequently Asked Questions ==

= Does this plugin work with block (FSE) themes? =

Yes. Featured image support is detected using the standard WordPress `current_theme_supports()` API, which works with all modern block and classic themes.

= What is BYOK mode? =

BYOK stands for "Bring Your Own Key." In this mode, you enter your own OpenAI API key. All image generation requests go directly from your WordPress server to OpenAI — we never see your key or your images. You pay OpenAI directly at their standard DALL-E 3 pricing.

= What is a credit? =

One credit = one AI-generated image. Credits are tied to your billing period and reset monthly when your subscription renews. Unused credits do not roll over.

= What happens when I run out of credits? =

Generation is paused until your credits reset at the start of your next billing period, or until you upgrade to a higher plan.

= Can I use a custom prompt? =

Yes — go to **AI Image Gen → Settings** and enter your own prompt template. Use `{title}` and `{content}` as placeholders for the post title and content.

= Where are images stored? =

Images are downloaded from OpenAI and saved to your WordPress Media Library. They are not hosted externally.

= Will it overwrite my existing featured images? =

By default, auto-generation is skipped if a post already has a featured image. You can enable overwriting in **AI Image Gen → Settings**.

= What happens if generation fails? =

The failure is recorded in the post's AI Status. You can retry generation at any time from the Generate page or from the post editor sidebar.

= How do I cancel my subscription? =

Click **Manage Subscription** on the **AI Image Gen → Billing** page. This opens the Stripe Customer Portal where you can cancel or modify your plan at any time.

== Screenshots ==

1. Generate Images admin page with real-time progress bar
2. Billing page with subscription plans and BYOK option
3. Settings page
4. Post editor sidebar metabox

== Changelog ==

= 1.0.1 =
* Fixed escaping and sanitization issues for WordPress.org compliance
* Replaced mt_rand() with wp_rand() for improved security
* Updated text domain to match plugin slug
* Updated "Tested up to" to WordPress 6.9
* Reduced tags to comply with the 5-tag limit

= 1.0.0 =
* Initial release with DALL-E 3 image generation, bulk generate, per-post control, BYOK mode, trial credit, and Starter/Pro/Agency subscription plans

== Upgrade Notice ==

= 1.0.1 =
Security and compliance fixes for WordPress.org submission.

= 1.0.0 =
Initial release.
