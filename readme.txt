=== Picment AI Featured Image Generator ===
Contributors:      baracksokullu
Tags:              ai, featured image, image generator, openai, dall-e
Requires at least: 6.0
Tested up to:      6.9
Requires PHP:      7.4
Stable tag:        1.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Auto-generate stunning DALL-E 3 AI featured images for every WordPress post. Bulk generation, per-post control, BYOK mode, and subscription plans.

== Description ==

**Picment AI Featured Image Generator** uses OpenAI's DALL-E 3 model to automatically create beautiful, relevant featured images for your blog posts — saving you time and keeping your content visually consistent.

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
2. Go to **Picment → Billing** and subscribe to a plan
3. Publish a post — a featured image will be generated automatically

**Option B — Bring your own OpenAI key:**

1. Install and activate the plugin
2. Go to **Picment → Billing** and enter your OpenAI API key under "Bring Your Own Key"
3. Publish a post — a featured image will be generated automatically

**Option C — Try it free:**

1. Install and activate the plugin — you receive 1 free trial credit automatically
2. Go to **Picment → Generate** and generate your first image

== Installation ==

1. Upload the `picment-ai-featured-image-generator` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Picment → Billing** to choose your billing mode

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

Yes — go to **Picment → Settings** and enter your own prompt template. Use `{title}` and `{content}` as placeholders for the post title and content.

= Where are images stored? =

Images are downloaded from OpenAI and saved to your WordPress Media Library. They are not hosted externally.

= Will it overwrite my existing featured images? =

By default, auto-generation is skipped if a post already has a featured image. You can enable overwriting in **Picment → Settings**.

= What happens if generation fails? =

The failure is recorded in the post's AI Status. You can retry generation at any time from the Generate page or from the post editor sidebar.

= How do I cancel my subscription? =

Click **Manage Subscription** on the **Picment → Billing** page. This opens the Stripe Customer Portal where you can cancel or modify your plan at any time.

== Screenshots ==

1. Generate Images admin page with real-time progress bar
2. Billing page with subscription plans and BYOK option
3. Settings page
4. Post editor sidebar metabox

== External Services ==

This plugin connects to the following external services:

= OpenAI (DALL-E 3) =

Used to generate featured images from your post title and content when BYOK (Bring Your Own Key) mode is active.

* **Data sent:** post title and a content excerpt derived from the post body
* **When:** each time an image is generated in BYOK mode
* **Terms of use:** https://openai.com/policies/terms-of-use
* **Privacy policy:** https://openai.com/policies/privacy-policy

= Picment Image Service =

Used to generate images via managed subscription plans (Starter, Pro, Agency), and for site registration, billing synchronisation, checkout session creation, and credit tracking. This service is operated by the plugin author.

* **Data sent:** your site URL and a unique anonymous install ID (on activation); a prompt derived from your post title and content excerpt (on image generation in trial or paid mode); plan selection (on subscription)
* **When:** on plugin activation, when subscribing to a plan, when syncing billing status, and when generating images in trial or paid mode
* **Terms of use and privacy policy:** https://picment.xyz

= Stripe =

Used to process subscription payments securely. Payment details are handled entirely on Stripe-hosted pages and are never stored by this plugin.

* **Data sent:** your site's anonymous install ID is associated with your subscription for credit tracking; payment details are entered directly on Stripe-hosted pages
* **When:** when subscribing to or managing a paid plan
* **Terms of service:** https://stripe.com/legal/ssa
* **Privacy policy:** https://stripe.com/privacy

== Changelog ==

= 1.1.0 =
* Added expanded image look styles: Photorealistic, Anime / Manga, Cinematic, Watercolor painting, and 3D render.
* Added a new Custom look option with custom prompting instructions for user-defined visual style guidance.

= 1.0.5 =
* Minor improvements and compatibility updates

= 1.0.4 =
* Updated API URLs to use picment.xyz instead of aaronswtech.com

= 1.0.3 =
* Fixed plugin version constant
* Improved output escaping throughout admin UI
* Updated plugin branding to Picment

= 1.0.2 =
* Minor stability improvements

= 1.0.1 =
* Billing system improvements

= 1.0.0 =
* Initial release with DALL-E 3 image generation, bulk generate, per-post control, BYOK mode, trial credit, and Starter/Pro/Agency subscription plans

== Upgrade Notice ==

= 1.1.0 =
Adds expanded style options and custom prompting controls.

= 1.0.5 =
Minor improvements and compatibility updates.


= 1.0.3 =
Recommended update with security and stability improvements.
