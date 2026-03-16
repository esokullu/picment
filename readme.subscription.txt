AI Featured Image Generator — Subscription & Billing System
=============================================================

Overview
--------
The plugin supports three billing modes, configurable per site from the
AI Image Gen → Billing admin page.

  trial   New installs receive 1 free credit automatically on activation.
          No configuration required.

  byok    "Bring Your Own Key." The site admin enters their own OpenAI API
          key. Requests go directly from the WordPress server to OpenAI.
          We never see the key or the generated images. Rate-limited to
          10 requests per minute.

  paid    Monthly subscription via Stripe. The plugin uses a shared OpenAI
          key managed by the plugin operator. Three plans are available:

            Starter   $7/month    20 credits/month
            Pro       $19/month  100 credits/month
            Agency    $49/month  400 credits/month

          Rate limit: 20 requests per minute per site on paid plans.

One credit = one AI-generated image. Credits reset monthly when the
subscription renews. Unused credits do not roll over.


Server-Side Configuration (wp-config.php)
------------------------------------------
These constants must be defined in wp-config.php on the plugin operator's
WordPress server (not on subscriber sites):

  // Shared OpenAI key used for trial + paid plan generations
  define( 'PICMENT_AI_IMAGE_OUR_API_KEY', 'sk-...' );

  // Stripe keys (live or test)
  define( 'PICMENT_AI_IMAGE_STRIPE_SECRET_KEY',      'sk_live_...' );
  define( 'PICMENT_AI_IMAGE_STRIPE_PUBLISHABLE_KEY', 'pk_live_...' );
  define( 'PICMENT_AI_IMAGE_STRIPE_WEBHOOK_SECRET',  'whsec_...' );

  // Stripe price IDs for each plan
  define( 'PICMENT_AI_IMAGE_PRICE_STARTER', 'price_...' );
  define( 'PICMENT_AI_IMAGE_PRICE_PRO',     'price_...' );
  define( 'PICMENT_AI_IMAGE_PRICE_AGENCY',  'price_...' );

Note: BYOK keys are stored per-site in the WordPress options table
(picment_ai_image_api_key) and are never sent to the plugin operator.


Database Options
----------------
All options are stored in wp_options with these keys:

  picment_ai_image_billing_mode           Current mode: 'trial', 'byok', or 'paid'
  picment_ai_image_trial_credits          Remaining trial credits (integer)
  picment_ai_image_install_id             UUID generated at activation (ties the
                                   site to its Stripe customer record)
  picment_ai_image_api_key                BYOK OpenAI API key (encrypted at rest
                                   via WordPress options; not transmitted)
  picment_ai_image_stripe_customer_id     Stripe customer ID (cus_...)
  picment_ai_image_stripe_subscription_id Stripe subscription ID (sub_...)
  picment_ai_image_stripe_plan            Active plan key: starter | pro | agency
  picment_ai_image_stripe_status          Stripe subscription status
  picment_ai_image_stripe_current_period_end  Unix timestamp of current period end
  picment_ai_image_credits_remaining      Credits left in current billing period
  picment_ai_image_credits_reset_at       Unix timestamp of last credit reset


Stripe Webhook
--------------
Register this endpoint in your Stripe Dashboard under
Developers → Webhooks → Add endpoint:

  https://YOUR-SITE.com/wp-json/picment-ai-image/v1/stripe-webhook

Recommended events to send:

  customer.subscription.created
  customer.subscription.updated
  customer.subscription.deleted
  invoice.paid

The webhook verifies the Stripe-Signature header using HMAC-SHA256 and
rejects events older than 5 minutes (replay-attack protection). The
PICMENT_AI_IMAGE_STRIPE_WEBHOOK_SECRET constant must match the signing secret shown in
the Stripe Dashboard for this endpoint.


Subscription Lifecycle
----------------------
1. User clicks a plan button on AI Image Gen → Billing.
2. The plugin creates a Stripe Checkout Session and redirects the user.
3. Stripe redirects the user back to the billing page on success.
4. The plugin retrieves the completed Checkout Session, extracts the
   subscription ID, and syncs the subscription (customer ID, plan,
   status, period end) into wp_options.
5. Stripe fires `customer.subscription.created` — webhook receives it
   and calls apply_subscription() to confirm state.
6. Stripe fires `invoice.paid` at the start of each billing period —
   webhook resets picment_ai_image_credits_remaining to the plan's credit
   allowance and updates the period end timestamp.
7. If the subscription is canceled but still within the current period,
   the billing mode remains 'paid' (grace period). When the period ends,
   the mode reverts to 'trial'.

Credit consumption happens server-side in Picment_AI_Image_Billing::consume_credit()
and is called only after a successful image generation. Failed generations
do not consume credits.


Lazy Credit Reset
-----------------
In addition to webhook-triggered resets, the plugin performs a lazy reset
check on every entitlement check (check_entitlement()). If the current
time has passed picment_ai_image_stripe_current_period_end and the credits have
not yet been reset for this period, they are reset automatically. This
ensures credits are refreshed even if a webhook was missed.


AJAX Actions
------------
These WordPress AJAX actions are registered by the billing class and
require the user to be logged in as an admin (manage_options capability):

  picment_ai_image_checkout      Create a Stripe Checkout Session for a plan.
                          POST data: { plan: 'starter'|'pro'|'agency' }
                          Returns:   { checkout_url: '...' }

  picment_ai_image_portal        Create a Stripe Billing Portal session for the
                          current customer.
                          Returns:   { portal_url: '...' }

  picment_ai_image_billing_sync  Re-fetch the current subscription from Stripe
                          and update local state.
                          Returns:   {} (page reloads on success)

  picment_ai_image_save_byok     Save a BYOK OpenAI API key to wp_options.
                          POST data: { api_key: 'sk-...' }
                          Returns:   { message: '...' }

All AJAX actions are protected by a WordPress nonce (picment_ai_image_billing_nonce)
which is localized into the page via wp_localize_script.


Rate Limiting
-------------
Rate limits are enforced server-side using WordPress transients.

  BYOK mode:  10 requests per 60-second window per site
  Paid plans: 20 requests per 60-second window per site

The transient key is based on the billing tier, not the post or user,
so it applies globally across all generation triggers on the site (bulk
generate, auto-publish, manual metabox button).

When the rate limit is hit, generation returns an error and the credit
is NOT consumed.


Entitlement Check Flow
----------------------
Picment_AI_Image_Billing::check_entitlement() is called before every generation.
It returns either:

  [ 'ok' => true,  'mode' => '...', 'api_key' => '...', 'credits' => N ]
  [ 'ok' => false, 'reason' => '...' ]

Possible failure reasons:

  no_credits_trial    Trial credits exhausted.
  no_byok_key         BYOK mode but no API key saved.
  rate_limit          Rate limit exceeded.
  no_credits_paid     Paid plan credits exhausted for this billing period.
  no_subscription     No active or trialing Stripe subscription.
  unknown_mode        Billing mode value is unrecognised.


Uninstall Cleanup
-----------------
All billing options are removed when the plugin is deleted via the
WordPress admin (Plugins → Delete). See uninstall.php for the full list.

Stripe subscriptions are NOT canceled on uninstall. Users must cancel
via the Stripe Customer Portal before uninstalling if they no longer
want to be billed.


Security Notes
--------------
- The shared OpenAI key (PICMENT_AI_IMAGE_OUR_API_KEY) is defined as a PHP
  constant and never exposed to the browser or to subscriber sites.
- BYOK keys are stored in wp_options on the subscriber's own server.
  They are never transmitted to the plugin operator.
- Stripe webhook payloads are authenticated via HMAC-SHA256 signature
  before any data is processed.
- All AJAX actions verify a WordPress nonce and require manage_options.
- Credit state is maintained exclusively server-side. There is no
  client-side credit counter that could be spoofed.
