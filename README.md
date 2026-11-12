# MyTurn WordPress plugin

The plugin is a WordPress adapter for `packages/myturn-core`. The core contains page URL rules, application use cases, and the Guzzle/DOM metadata adapter; this plugin contains WordPress routes, shortcodes, output escaping, and SEO hooks.

## Install for local development

From this directory, run `composer install`. The path repository links the sibling core package. For a standalone plugin distribution, publish `edwinlove/myturn-core` to a Composer repository and change the version constraint to a tagged release. Install the resulting `vendor/` directory with the plugin.

The plugin requires PHP 8.4 or newer. Its `edwinlove/myturn-core` dependency supplies Guzzle 7 and the DOM extension. Configure the plugin under **Settings → MyTurn**. Set the tenant subdomain only, such as `mylibrary` or `mylibrary.test`; the plugin appends the fixed `.myturn.com` suffix. The WordPress thing and search URL patterns are configurable there and must end in `{id}` and `{query}` respectively. The internal MyTurn paths remain fixed by the integration. Canonical URLs use the WordPress site URL.

## WordPress pages and routes

Catalogue pages are generated automatically under the **Catalogue URL path** set in **Settings → MyTurn** (default `/catalogue`); leave the path blank to turn catalogue and location search pages off. Configure locations in **Settings → MyTurn** using **Add location** and **Remove**. Each location has a title and a non-negative integer MyTurn code. Titles become URL slugs. In the paths below, `{catalogueUrl}` means the existing **Catalogue URL path** option:

| Title | Code | Catalogue | Search |
| --- | --- | --- | --- |
| North Branch | 7 | `/{catalogueUrl}/north-branch` | `/{catalogueUrl}/north-branch/search/{term}` |
| South | 12 | `/{catalogueUrl}/south` | `/{catalogueUrl}/south/search/{term}` |

Each catalogue and search iframe includes the matching `location` code. Location search canonical URLs retain the location path. Multiple locations require titles with unique URL slugs and unique codes. With a single location, leave its title blank to use `/{catalogueUrl}` and `/{catalogueUrl}/search/{term}` directly. Remove all locations for an unfiltered catalogue. The previous single location setting is carried forward automatically as an unnamed location.

For other MyTurn pages, such as sign-up and login, create normal WordPress pages and add the shortcode with the corresponding MyTurn path:

```text
[myturn_embed path="createUser"]
[myturn_embed path="myTurnLogin/auth"]
```

The existing thing and search routes remain available and default to `/thing/{id}` and `/thing/search/{query}`. Their public paths, and the catalogue path, can be changed in **Settings → MyTurn**; the plugin flushes rewrite rules after route or location changes. With one location, these existing routes and shortcodes use that location code; with multiple locations they are unfiltered unless a location is supplied through the route. The plugin renders those routes with the active theme header/footer and the MyTurn iframe. Enable a pretty permalink structure in **Settings → Permalinks** for these path routes; the shortcodes on ordinary WordPress pages work with any permalink setting. Rewrite rules are flushed on activation and deactivation.

On these dynamic routes the plugin fetches MyTurn Open Graph title, description, and image metadata. On thing pages it sets `og:url` to the configured WordPress canonical URL. It adapts these values to Yoast SEO and Rank Math when either is active, and emits fallback canonical/Open Graph tags otherwise. Use one SEO plugin to manage site-wide title, description, Twitter card, and sitemap settings; those settings replace the former Leogout and Presta Symfony bundles.

The Guzzle adapter treats metadata as optional: failed requests or missing Open Graph data do not prevent the iframe from rendering. Adjust the client timeout or add logging/ caching in the WordPress infrastructure layer if production traffic requires it.

## Checks

Run `php tests/locations.php` for location validation, URL routing, embed filtering, legacy settings, and rewrite refresh checks using WordPress function stubs. These checks do not require a running WordPress installation.
