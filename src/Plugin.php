<?php

declare(strict_types=1);

namespace EdwinLove\MyTurn\WordPress;

use GuzzleHttp\Client;
use EdwinLove\MyTurn\Core\Application\UseCase\BuildSearchPage;
use EdwinLove\MyTurn\Core\Application\UseCase\BuildThingPage;
use EdwinLove\MyTurn\Core\Domain\Embed\EmbedUrl;
use EdwinLove\MyTurn\Core\Domain\Page\EmbeddedPage;
use EdwinLove\MyTurn\Core\Infrastructure\Http\GuzzleMyTurnPageClient;

final class Plugin
{
    private const SETTINGS_OPTION = 'myturn_settings';
    private const REWRITE_FLUSH_OPTION = 'myturn_rewrite_flush_pending';
    private const MYTURN_DOMAIN = 'myturn.com';
    private const DEFAULT_SETTINGS = [
        'tenant_subdomain' => '',
        'thing_route' => 'thing/{id}',
        'search_route' => 'thing/search/{query}',
        'location' => '',
    ];

    private ?EmbeddedPage $currentPage = null;

    public static function activate(): void
    {
        $plugin = new self();
        add_option(self::SETTINGS_OPTION, self::DEFAULT_SETTINGS);
        $plugin->addRewriteRules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function register(): void
    {
        add_action('init', [$this, 'addRewriteRules']);
        add_action('init', [$this, 'flushChangedRewriteRules'], 99);
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('update_option_' . self::SETTINGS_OPTION, [$this, 'settingsUpdated'], 10, 2);
        add_action('add_option_' . self::SETTINGS_OPTION, [$this, 'settingsAdded'], 10, 2);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_filter('template_include', [$this, 'selectTemplate']);
        add_filter('pre_get_document_title', [$this, 'documentTitle']);
        add_filter('wpseo_opengraph_title', [$this, 'filterOgTitle']);
        add_filter('wpseo_opengraph_desc', [$this, 'filterOgDescription']);
        add_filter('wpseo_opengraph_image', [$this, 'filterOgImage']);
        add_filter('wpseo_opengraph_url', [$this, 'filterOgUrl']);
        add_filter('rank_math/opengraph/facebook/title', [$this, 'filterOgTitle']);
        add_filter('rank_math/opengraph/facebook/description', [$this, 'filterOgDescription']);
        add_filter('rank_math/opengraph/facebook/image', [$this, 'filterOgImage']);
        add_filter('rank_math/opengraph/url', [$this, 'filterOgUrl']);
        add_filter('rank_math/frontend/canonical', [$this, 'filterCanonical']);
        add_action('wp_head', [$this, 'renderMetadata'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueueEmbedScript']);
        add_shortcode('myturn_embed', [$this, 'renderEmbedShortcode']);
    }

    public function addRewriteRules(): void
    {
        $settings = $this->settings();
        $thingPrefix = substr($settings['thing_route'], 0, -strlen('/{id}'));
        $searchPrefix = substr($settings['search_route'], 0, -strlen('/{query}'));

        add_rewrite_rule('^' . preg_quote($searchPrefix, '#') . '/([^/]+)/?$', 'index.php?myturn_search=$matches[1]', 'top');
        add_rewrite_rule('^' . preg_quote($thingPrefix, '#') . '/([0-9]+)/?$', 'index.php?myturn_thing=$matches[1]', 'top');
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'myturn_thing';
        $vars[] = 'myturn_search';

        return $vars;
    }

    public function addSettingsPage(): void
    {
        add_options_page(
            __('MyTurn settings', 'myturn'),
            __('MyTurn', 'myturn'),
            'manage_options',
            'myturn',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('myturn', self::SETTINGS_OPTION, [
            'type' => 'array',
            'default' => self::DEFAULT_SETTINGS,
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);

        add_settings_section(
            'myturn_routes',
            __('MyTurn connection and WordPress URLs', 'myturn'),
            static function (): void {
                echo '<p>' . esc_html__('Set the MyTurn tenant and public WordPress URL patterns. The MyTurn URL paths and .myturn.com domain are fixed by this integration.', 'myturn') . '</p>';
            },
            'myturn'
        );

        foreach ([
            'tenant_subdomain' => __('MyTurn tenant subdomain', 'myturn'),
            'thing_route' => __('Thing URL pattern', 'myturn'),
            'search_route' => __('Search URL pattern', 'myturn'),
            'location' => __('MyTurn location (optional)', 'myturn'),
        ] as $key => $label) {
            add_settings_field(
                $key,
                $label,
                [$this, 'renderSettingsField'],
                'myturn',
                'myturn_routes',
                ['key' => $key]
            );
        }
    }

    public function renderSettingsPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('MyTurn settings', 'myturn'); ?></h1>
            <?php settings_errors(self::SETTINGS_OPTION); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('myturn');
                do_settings_sections('myturn');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function renderSettingsField(array $arguments): void
    {
        $key = $arguments['key'];
        $value = $this->settings()[$key];
        printf(
            $key === 'location'
                ? '<input class="small-text" type="number" min="0" step="1" id="%1$s" name="%2$s[%1$s]" value="%3$s" />'
                : '<input class="regular-text" type="text" id="%1$s" name="%2$s[%1$s]" value="%3$s" />',
            esc_attr($key),
            esc_attr(self::SETTINGS_OPTION),
            esc_attr((string) $value)
        );

        $help = match ($key) {
            'tenant_subdomain' => sprintf(__('Builds https://%s.%s/. Use labels only, for example mylibrary or mylibrary.test.', 'myturn'), '{value}', self::MYTURN_DOMAIN),
            'thing_route' => __('WordPress path ending in {id}, for example thing/{id}.', 'myturn'),
            'search_route' => __('WordPress path ending in {query}, for example thing/search/{query}.', 'myturn'),
            'location' => __('Whole number. When set, ?location={value} is added to the catalogue, search and thing embeds. Leave blank for no location.', 'myturn'),
        };
        printf('<p class="description">%s</p>', esc_html($help));
    }

    public function sanitizeSettings($input): array
    {
        if (!is_array($input)) {
            add_settings_error(self::SETTINGS_OPTION, 'invalid_settings', __('MyTurn settings were not saved.', 'myturn'));

            return $this->settings();
        }

        $settings = $this->settings();
        $tenant = strtolower(trim(sanitize_text_field((string) ($input['tenant_subdomain'] ?? ''))));
        $thingRoute = $this->sanitizeRoute((string) ($input['thing_route'] ?? ''), '{id}');
        $searchRoute = $this->sanitizeRoute((string) ($input['search_route'] ?? ''), '{query}');
        $location = trim(sanitize_text_field((string) ($input['location'] ?? '')));
        $validLocation = $location === '' || ctype_digit($location);
        $validTenant = preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/D', $tenant) === 1;
        $routesConflict = $thingRoute !== null
            && $searchRoute !== null
            && substr($thingRoute, 0, -strlen('/{id}')) === substr($searchRoute, 0, -strlen('/{query}'));

        if (!$validTenant || $thingRoute === null || $searchRoute === null || $routesConflict || !$validLocation) {
            add_settings_error(
                self::SETTINGS_OPTION,
                'invalid_settings',
                __('Check the tenant subdomain, route patterns and location. Thing must end in {id}; search must end in {query}; location must be a whole number or blank.', 'myturn')
            );

            return $settings;
        }

        return [
            'tenant_subdomain' => $tenant,
            'thing_route' => $thingRoute,
            'search_route' => $searchRoute,
            'location' => $location === '' ? '' : (string) (int) $location,
        ];
    }

    public function settingsUpdated($oldSettings, $newSettings): void
    {
        if (($oldSettings['thing_route'] ?? null) !== ($newSettings['thing_route'] ?? null)
            || ($oldSettings['search_route'] ?? null) !== ($newSettings['search_route'] ?? null)) {
            update_option(self::REWRITE_FLUSH_OPTION, 1, false);
        }
    }

    public function settingsAdded(): void
    {
        update_option(self::REWRITE_FLUSH_OPTION, 1, false);
    }

    public function flushChangedRewriteRules(): void
    {
        if (get_option(self::REWRITE_FLUSH_OPTION)) {
            delete_option(self::REWRITE_FLUSH_OPTION);
            flush_rewrite_rules(false);
        }
    }

    public function selectTemplate(string $template): string
    {
        if (!$this->isMyTurnRoute() || !$this->hasTenant()) {
            return $template;
        }

        $this->currentPage = $this->buildCurrentPage();
        $GLOBALS['myturn_plugin'] = $this;
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) {
            $wp_query->is_404 = false;
        }
        status_header(200);

        return __DIR__ . '/../templates/embed.php';
    }

    public function documentTitle(string $title): string
    {
        $metadata = $this->currentPage?->metadata;

        return $metadata?->title ?: $title;
    }

    public function filterOgTitle(string $value): string
    {
        return $this->currentPage?->metadata?->title ?: $value;
    }

    public function filterOgDescription(string $value): string
    {
        return $this->currentPage?->metadata?->description ?: $value;
    }

    public function filterOgImage(string $value): string
    {
        return $this->currentPage?->metadata?->image ?: $value;
    }

    public function filterOgUrl(string $value): string
    {
        if (!$this->isThingPage() || $this->currentPage === null) {
            return $value;
        }

        return $this->currentPage->canonicalUrl;
    }

    public function filterCanonical(string $value): string
    {
        return $this->currentPage?->canonicalUrl ?: $value;
    }

    public function renderMetadata(): void
    {
        $page = $this->currentPage;
        if ($page === null || $this->hasSeoPlugin()) {
            return;
        }

        $metadata = $page->metadata;
        printf("<link rel=\"canonical\" href=\"%s\" />\n", esc_url($page->canonicalUrl));
        if ($this->isThingPage()) {
            printf("<meta property=\"og:url\" content=\"%s\" />\n", esc_url($page->canonicalUrl));
        }
        if ($metadata?->title) {
            printf("<meta property=\"og:title\" content=\"%s\" />\n", esc_attr($metadata->title));
        }
        if ($metadata?->description) {
            printf("<meta property=\"og:description\" content=\"%s\" />\n", esc_attr($metadata->description));
        }
        if ($metadata?->image) {
            printf("<meta property=\"og:image\" content=\"%s\" />\n", esc_url($metadata->image));
        }
    }

    public function renderEmbedShortcode(array $attributes): string
    {
        if (!$this->hasTenant()) {
            return '';
        }

        $attributes = shortcode_atts(['path' => 'inventory/browse/'], $attributes, 'myturn_embed');
        $path = sanitize_text_field((string) $attributes['path']);
        $url = $this->withLocation($this->embedUrl($path));
        $this->enqueueEmbedScript();

        return sprintf(
            '<iframe class="myturn-embed" src="%s" title="%s" height="2000" loading="lazy"></iframe>',
            esc_url($url),
            esc_attr__('MyTurn catalogue', 'myturn')
        );
    }

    public function currentEmbedUrl(): string
    {
        return $this->currentPage?->embedUrl ?? '';
    }

    public function enqueueEmbedScript(): void
    {
        if (!$this->hasTenant()) {
            return;
        }

        if ($this->isMyTurnRoute() || (is_singular() && has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'myturn_embed'))) {
            $baseUrl = $this->myTurnBaseUrl();
            wp_enqueue_script(
                'myturn-embed',
                rtrim($baseUrl, '/') . '/library/assets/ui/myturn-embed/myturn-embed.js',
                [],
                null,
                true
            );
        }
    }

    private function hasTenant(): bool
    {
        return $this->settings()['tenant_subdomain'] !== '';
    }

    private function isMyTurnRoute(): bool
    {
        return (bool) get_query_var('myturn_thing') || get_query_var('myturn_search') !== '';
    }

    private function isThingPage(): bool
    {
        return (bool) get_query_var('myturn_thing');
    }

    private function buildCurrentPage(): EmbeddedPage
    {
        $client = new GuzzleMyTurnPageClient(new Client(['timeout' => 8.0, 'connect_timeout' => 3.0]));
        $myTurnBaseUrl = $this->myTurnBaseUrl();
        $siteBaseUrl = home_url('/');
        $settings = $this->settings();

        if (get_query_var('myturn_search') !== '') {
            $searchQuery = (string) get_query_var('myturn_search');
            $canonicalPath = str_replace('{query}', rawurlencode($searchQuery), $settings['search_route']);

            $page = (new BuildSearchPage($client, $myTurnBaseUrl, $siteBaseUrl))
                ->execute($searchQuery, user_trailingslashit($canonicalPath));

            return new EmbeddedPage($this->withLocation($page->embedUrl), $page->canonicalUrl, $page->metadata);
        }

        $thingId = (int) get_query_var('myturn_thing');
        $canonicalPath = str_replace('{id}', (string) $thingId, $settings['thing_route']);

        $page = (new BuildThingPage($client, $myTurnBaseUrl, $siteBaseUrl))
            ->execute($thingId, user_trailingslashit($canonicalPath));

        return new EmbeddedPage($this->withLocation($page->embedUrl), $page->canonicalUrl, $page->metadata);
    }

    private function embedUrl(string $path): string
    {
        return EmbedUrl::fromPath($this->myTurnBaseUrl(), $path);
    }

    private function withLocation(string $url): string
    {
        $location = (string) $this->settings()['location'];

        return $location === '' ? $url : add_query_arg('location', $location, $url);
    }

    private function myTurnBaseUrl(): string
    {
        return 'https://' . $this->settings()['tenant_subdomain'] . '.' . self::MYTURN_DOMAIN . '/';
    }

    private function settings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, get_option(self::SETTINGS_OPTION, []));
    }

    private function sanitizeRoute(string $route, string $placeholder): ?string
    {
        $route = strtolower(trim(sanitize_text_field($route), '/'));
        $suffix = '/' . $placeholder;
        if (!str_ends_with($route, $suffix)) {
            return null;
        }

        $prefix = substr($route, 0, -strlen($suffix));
        if ($prefix === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/D', $prefix) !== 1) {
            return null;
        }

        return $prefix . $suffix;
    }

    private function hasSeoPlugin(): bool
    {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION');
    }
}
