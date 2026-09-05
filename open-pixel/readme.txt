=== Open Pixel ===
Contributors: unbelievabledigital
Tags: openai, chatgpt ads, pixel, conversion tracking, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conversion pixel manager for WordPress and WooCommerce. OpenAI (ChatGPT Ads) Measurement Pixel and Conversions API today, more providers on the same event bus.

== Description ==

Open Pixel installs the official ChatGPT Ads Measurement Pixel on every
page and sends the standard conversion events for you:

* `page_viewed` on every page.
* `contents_viewed` on WooCommerce product pages.
* `items_added` on add to cart (classic, AJAX and block/Store API carts).
* `checkout_started` on the checkout page.
* `order_created` on the order-received page, and again from the server
  through the Conversions API with the same event ID so OpenAI deduplicates it.
* `registration_completed` when a user or customer registers.

Amounts are sent as integers in the currency's ISO 4217 minor unit, customer
identifiers are normalized and SHA-256 hashed on the server before they reach
the browser, and a `<noscript>` image tag covers visitors without JavaScript.

= Consent =

Choose "Require consent first" to initialize the pixel with consent set to
`false`. Grant consent from your cookie banner with `window.openPixel.grantConsent()`;
the WP Consent API "marketing" category is detected automatically.

= Conversions API =

Enable it and paste the API key from the Conversions tab of Ads Manager.
Orders are delivered asynchronously with retries and logged under
WooCommerce > Status > Logs (source: open-pixel). A "Send test event"
button validates your credentials without recording anything.

= Extensible =

The plugin is a small pixel manager: integrations emit normalized events on
a bus and each provider maps them to its own API. Additional providers
(Meta, Google Ads, TikTok, ...) can be registered with the
`openpixel_pixel_providers` filter.

== Installation ==

1. Upload the `open-pixel` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings > Pixel Manager, enter your Pixel ID and enable the pixel.

== Frequently Asked Questions ==

= Where do I find my Pixel ID and Conversions API key? =

In ChatGPT Ads Manager, Conversions tab.

= My site uses a Content Security Policy. =

Allow `script-src https://bzrcdn.openai.com`, `connect-src https://bzr.openai.com https://bzrcdn.openai.com`
and `img-src https://bzr.openai.com`. Use the `openpixel_script_nonce` filter to add your nonce to the inline snippets.

= Does it work with WooCommerce HPOS? =

Yes. The plugin only uses the WooCommerce CRUD order API and declares HPOS compatibility.

== Changelog ==

= 1.1.0 =
* Native Measurement Pixel loader, page_viewed, consent mode, debug mode, noscript image tag.
* WooCommerce events: contents_viewed, items_added, checkout_started, order_created, registration_completed.
* Advanced matching with server-side normalization and SHA-256 hashing.
* Conversions API: server-side order_created with deduplication, async delivery, retries, logging, validate-only test.
* Provider-agnostic event bus for future Meta/Google providers.

= 1.0.0 =
* Initial release.
