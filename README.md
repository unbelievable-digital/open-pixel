# OpenAI Pixel for WordPress

ChatGPT Ads **Measurement Pixel** + **Conversions API** for WordPress and WooCommerce, implemented straight from the official docs (https://developers.openai.com/ads/measurement-pixel). Enter a Pixel ID, done.

Built as a small pixel-manager framework: OpenAI ships first, Meta / Google Ads / TikTok are single classes on the same event bus later.

## What it tracks

| Where | OpenAI event | Data |
|---|---|---|
| Every front-end page | `page_viewed` | page id + title as a `page` content |
| Single product page | `contents_viewed` | product id, name, price (minor units), currency |
| Add to cart (classic, AJAX, blocks/Store API) | `items_added` | product, quantity, line amount, `event_id` |
| Checkout page | `checkout_started` | cart items + total, `event_id = checkout_{cart_hash}` |
| Order-received page | `order_created` | order items + total, `event_id = order_{id}` (fires once per order) |
| Payment complete (server) | `order_created` via Conversions API | same `order_{id}` → OpenAI deduplicates against the browser event |
| New user / customer | `registration_completed` | `event_id = reg_{user_id}` |

Advanced matching: on the thank-you page and for logged-in users, email / phone / first & last name / external id are normalized and SHA-256 hashed on the server exactly as the docs specify, and passed to `oaiq("init", { user })`. Geo fields go as plain text, as required.

## Install

Copy `openai-pixel/` into `wp-content/plugins/`, activate, open **Settings > Pixel Manager**, paste your Pixel ID (from Ads Manager > Conversions), enable.

For the Conversions API, also paste the API key from the same tab and use **Send test event** — it calls the endpoint with `validate_only: true`, so nothing is recorded.

## Settings

- **Pixel ID** — comma-separate several IDs; every event goes to all of them (`oaiq("init")` per ID).
- **Debug mode** — `debug: true` on init; SDK logs to the console.
- **Do not track administrators** — skips the pixel for users with `manage_options`.
- **Consent** — "Require consent first" emits `oaiq("consent", false)` before `init`. Grant it from your banner with `window.oaip.grantConsent()`; the WP Consent API (`marketing` category) is detected automatically.
- **Send hashed customer data** — advanced matching on/off.
- **No-JavaScript fallback** — `<noscript>` image tag for `page_viewed`.
- **Track WooCommerce events** — on/off for the whole WooCommerce integration.
- **Conversions API** — enable + API key. Orders are queued through Action Scheduler (ships with WooCommerce) with retries and logged under WooCommerce > Status > Logs, source `openai-pixel`.

## Content Security Policy

If your site enforces a CSP, add:

```
script-src  https://bzrcdn.openai.com
connect-src https://bzr.openai.com https://bzrcdn.openai.com
img-src     https://bzr.openai.com
```

Use the `oaip_script_nonce` filter to put your nonce on the inline snippets.

## Architecture

```
WordPress / WooCommerce hooks
        │
        ▼
OAIP_Event_Bus   normalized events: page_view, view_item, add_to_cart, begin_checkout,
        │        purchase, sign_up, generate_lead, schedule, subscribe, start_trial, custom
        │
        ├──► OAIP_Provider_OpenAI   oaiq("measure", …)  +  OAIP_OpenAI_CAPI (server)
        ├──► (later) Meta           fbq("track", …)     +  Meta CAPI
        └──► (later) Google         gtag("event", …)
```

- `includes/class-oaip-event-bus.php` — normalized event model, persistence for events raised on AJAX / redirect requests (WooCommerce session or user transient), draining into the footer or into WooCommerce AJAX fragments.
- `includes/class-oaip-provider.php` — abstract provider: declarative settings fields, `render_head` / `render_footer`, `to_browser_payload`, `handle_server_event`.
- `includes/providers/class-oaip-provider-openai.php` — the official loader, init, consent, `measure` mapping, `contents[]` building, `<noscript>` image tag, Conversions API event mapping.
- `includes/providers/class-oaip-openai-capi.php` — `POST https://bzr.openai.com/v1/events?pid=…`, async delivery + retries, logging.
- `includes/integrations/class-oaip-integration-woocommerce.php` — the WooCommerce hooks, written once against the bus.
- `includes/class-oaip-money.php` / `class-oaip-hash.php` — ISO 4217 minor-unit conversion and the documented normalization + SHA-256 rules.
- `assets/js/oaip.js` — tiny runtime that forwards payloads to `window.oaiq`, handles WooCommerce fragments and consent.

## Sending your own events

```php
// Browser event on the current page:
oaip()->get_bus()->track( array(
    'name'     => 'generate_lead',
    'event_id' => 'lead_' . $entry_id,
) );

// Persist for the next page (e.g. inside a form handler that redirects):
oaip()->get_bus()->track( array( 'name' => 'schedule', 'value' => 50, 'currency' => 'EUR' ), true );

// Server-side only (Conversions API):
oaip()->get_bus()->track( array(
    'name'    => 'subscribe',
    'plan_id' => 'pro_monthly',
    'value'   => 20, 'currency' => 'USD',
    'channel' => 'server',
    'user'    => array( 'email' => $email ),
    'context' => array( 'source_url' => home_url( '/pricing' ), 'ip_address' => $ip, 'user_agent' => $ua ),
) );
```

Unknown names become `custom` events with `custom_event_name` set to the name.

## Adding a provider

```php
class My_Meta_Provider extends OAIP_Provider {
    public function get_id()    { return 'meta'; }
    public function get_label() { return 'Meta Pixel'; }
    public function get_fields() { /* declarative fields, see OAIP_Provider */ }
    public function render_head( OAIP_Event_Bus $bus ) { /* fbq loader */ }
    public function to_browser_payload( array $event ) {
        return array( 'provider' => 'meta', 'event_id' => $event['event_id'], 'args' => array( 'track', 'Purchase', array( /* … */ ) ) );
    }
}
add_filter( 'oaip_pixel_providers', function ( $providers ) {
    $providers[] = new My_Meta_Provider();
    return $providers;
} );
```

Then register a JS handler: `window.oaip.register('meta', function (p) { fbq.apply(null, p.args); });`.

## Hooks

- `oaip_pixel_providers` — register providers.
- `oaip_track_event( $event )` — modify or drop (return `null`) any event before providers see it.
- `oaip_track_page_view` — return `false` to skip automatic `page_viewed`.
- `oaip_consent_granted( bool, $provider_id )` — tell the plugin consent is already granted (e.g. from a cookie) so it does not emit `consent(false)`.
- `oaip_script_nonce` — CSP nonce for inline scripts.
- `oaip_currency_exponent( int, $currency )` — override minor-unit digits.
- `oaip_wc_product_item( $item, $product, $quantity )` — adjust WooCommerce item data.
- `oaip_openai_capi_delivered( $event, $response )` — after a successful Conversions API delivery.

## Roadmap

See [docs/PLAN.md](docs/PLAN.md).
