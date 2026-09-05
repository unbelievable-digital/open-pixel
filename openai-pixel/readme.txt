=== OpenAI Pixel ===
Contributors: unbelievabledigital
Tags: openai, pixel, tracking, analytics, conversion tracking
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the OpenAI tracking pixel to your WordPress site in a couple of clicks. Built to grow into a full pixel manager (Meta, Google, ...).

== Description ==

OpenAI Pixel gives site owners a simple settings screen to paste in the
tracking pixel/snippet from their OpenAI business dashboard, choose where
it renders (head or footer), and enable or disable it without touching
code.

The plugin is built around a small provider architecture: OpenAI ships
first, and additional pixel providers (Meta, Google Ads, TikTok, ...) can
be registered later through the `oaip_pixel_providers` filter without
changing the plugin's core.

= Features =

* Settings > Pixel Manager screen, gated behind `manage_options`.
* Enable/disable per pixel provider.
* Choose head or footer placement per provider.
* Optional Pixel ID field, available in the snippet as `%PIXEL_ID%`.
* `oaip_pixel_providers` filter to register additional providers.
* `oaip_should_render_pixel` filter to conditionally suppress output (e.g. for logged-in admins, or behind a cookie-consent gate).

== Installation ==

1. Upload the `openai-pixel` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings > Pixel Manager, paste your OpenAI pixel snippet, and enable it.

== Frequently Asked Questions ==

= Where do I get the OpenAI pixel snippet? =

From your OpenAI business/ads dashboard. This plugin does not fabricate
or hard-code that snippet since providers change their tracking code
over time — you paste in whatever is currently issued to you.

= Can I add other pixels (Meta, Google)? =

Yes. Register additional providers via the `oaip_pixel_providers` filter,
extending the `OAIP_Provider` base class shipped with this plugin.

== Changelog ==

= 1.0.0 =
* Initial release: OpenAI provider, settings screen, extensible provider architecture.
