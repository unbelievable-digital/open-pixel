# OpenAI Pixel

WordPress plugin: paste your OpenAI tracking pixel into a settings screen, pick head/footer placement, done. Built as a small pixel-provider framework so Meta, Google Ads, TikTok, etc. can be added later without rewriting anything.

## Install

Copy `openai-pixel/` into `wp-content/plugins/`, activate, then go to **Settings > Pixel Manager**.

## Architecture

- `includes/class-oaip-provider.php` — abstract base every pixel provider extends (id, label, settings, sanitize, render).
- `includes/providers/class-oaip-provider-openai.php` — first provider. Site owner pastes the snippet OpenAI issues them; `%PIXEL_ID%` in that snippet gets replaced with the Pixel ID field.
- `includes/class-oaip-core.php` — registers providers (via the `oaip_pixel_providers` filter) and prints enabled pixels on `wp_head` / `wp_footer`.
- `includes/class-oaip-admin.php` — Settings > Pixel Manager screen, one section per registered provider.

## Adding a new provider (Meta, Google, ...)

```php
class My_Meta_Provider extends OAIP_Provider {
    public function get_id() { return 'meta'; }
    public function get_label() { return 'Meta Pixel'; }
}

add_filter( 'oaip_pixel_providers', function ( $providers ) {
    $providers[] = new My_Meta_Provider();
    return $providers;
} );
```

Drop that in a small companion plugin (or extend `includes/providers/`) and the new pixel gets its own section on the settings screen automatically.

## Filters

- `oaip_pixel_providers` — array of `OAIP_Provider` instances to register.
- `oaip_should_render_pixel( bool $should_render, string $provider_id, array $settings )` — return `false` to suppress a pixel on a given request (consent gating, admin exclusion, etc.).
