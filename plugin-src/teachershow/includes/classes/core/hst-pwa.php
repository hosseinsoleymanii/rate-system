<?php

defined('ABSPATH') || exit;

/**
 * Progressive Web App (PWA) support for the plugin's front-end pages.
 *
 * Adds a web app manifest + a minimal service worker so browsers offer an
 * "Install app" option in the address bar while the user is on any TeacherShow
 * page. The manifest and service worker are served dynamically via a query var
 * (no physical files in the site root needed); the service worker is served
 * with `Service-Worker-Allowed: /` so its scope can cover the whole site even
 * though the request URL carries a query string.
 *
 * Enabling/disabling is controlled from the plugin settings
 * (option `hst-pwa-enabled`, default on).
 */
class HST_PWA
{
    const QV = 'hst_pwa';
    const ENABLED_OPTION = 'hst-pwa-enabled';

    public function __construct()
    {
        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'maybe_serve'], 0);
        add_action('wp_head', [$this, 'print_head_tags'], 1);
        add_action('wp_footer', [$this, 'print_register_script'], 99);
    }

    private function enabled(): bool
    {
        return get_option(self::ENABLED_OPTION, '1') === '1';
    }

    public function add_rewrite(): void
    {
        // Pretty endpoints so the service worker scope is the site root.
        add_rewrite_rule('^hst-pwa-manifest\.json$', 'index.php?' . self::QV . '=manifest', 'top');
        add_rewrite_rule('^hst-pwa-sw\.js$', 'index.php?' . self::QV . '=sw', 'top');

        // Self-heal: if our rule isn't actually registered yet (e.g. the plugin
        // was updated without a deactivate/activate cycle), flush once so the
        // /hst-pwa-manifest.json and /hst-pwa-sw.js URLs start resolving.
        if (get_option('hst-pwa-rewrite-v') !== '1') {
            $rules = get_option('rewrite_rules');
            if (!is_array($rules) || !isset($rules['^hst-pwa-manifest\.json$'])) {
                flush_rewrite_rules(false);
            }
            update_option('hst-pwa-rewrite-v', '1');
        }
    }

    public function register_query_var(array $vars): array
    {
        $vars[] = self::QV;
        return $vars;
    }

    public static function manifest_url(): string
    {
        // Query-var URL always works regardless of permalink settings or
        // whether rewrite rules have been flushed.
        return add_query_arg(self::QV, 'manifest', home_url('/'));
    }

    public static function sw_url(): string
    {
        return add_query_arg(self::QV, 'sw', home_url('/'));
    }

    public function maybe_serve(): void
    {
        $what = get_query_var(self::QV);
        if (!$what) {
            return;
        }

        if ($what === 'manifest') {
            $this->serve_manifest();
        } elseif ($what === 'sw') {
            $this->serve_service_worker();
        }
    }

    private function school_name(): string
    {
        if (class_exists('HST_Settings')) {
            $name = (string) HST_Settings::option('hst-home-school-name', '');
            if ($name !== '') {
                return $name;
            }
        }
        return get_bloginfo('name');
    }

    private function theme_color(): string
    {
        return apply_filters('hst_pwa_theme_color', class_exists('HST_Settings') ? HST_Settings::fixed_accent_color() : '#334155');
    }

    private function icon_data_uri(int $size): string
    {
        // Inline SVG icon (graduation cap) rendered as a data URI so no binary
        // asset files are required. Browsers accept SVG icons in manifests.
        $color = $this->theme_color();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 192 192">'
            . '<rect width="192" height="192" rx="40" fill="' . $color . '"/>'
            . '<g fill="none" stroke="#fff" stroke-width="10" stroke-linejoin="round" stroke-linecap="round">'
            . '<path d="M96 56 156 80 96 104 36 80 96 56Z"/>'
            . '<path d="M60 92v28c0 10 72 10 72 0V92"/>'
            . '<path d="M156 80v34"/>'
            . '</g></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * The site's global logo image URL if one is configured, else ''.
     */
    private function logo_url(): string
    {
        if (!class_exists('HST_Settings')) {
            return '';
        }
        $logo_id = (int) HST_Settings::option('hst-home-logo-id', 0);
        if (!$logo_id) {
            return '';
        }
        $src = wp_get_attachment_image_url($logo_id, 'full');
        return $src ? $src : '';
    }

    /**
     * Build the manifest icon list: use the uploaded global logo when present,
     * otherwise fall back to the inline default SVG icon.
     */
    private function manifest_icons(): array
    {
        $logo = $this->logo_url();
        if ($logo !== '') {
            $type = 'image/png';
            $ext = strtolower((string) pathinfo(parse_url($logo, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $type = 'image/jpeg';
            } elseif ($ext === 'svg') {
                $type = 'image/svg+xml';
            } elseif ($ext === 'webp') {
                $type = 'image/webp';
            }
            return [
                ['src' => $logo, 'sizes' => '192x192', 'type' => $type, 'purpose' => 'any'],
                ['src' => $logo, 'sizes' => '512x512', 'type' => $type, 'purpose' => 'any'],
            ];
        }

        return [
            ['src' => $this->icon_data_uri(192), 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
            ['src' => $this->icon_data_uri(512), 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
        ];
    }

    /** Best icon URL for <link rel="apple-touch-icon">. */
    private function apple_icon(): string
    {
        $logo = $this->logo_url();
        return $logo !== '' ? $logo : $this->icon_data_uri(192);
    }

    private function serve_manifest(): void
    {
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');

        $name = $this->school_name();
        $manifest = [
            'name'             => $name . ' — سامانهٔ مدرسه',
            'short_name'       => $name !== '' ? mb_substr($name, 0, 18) : 'تیچرشو',
            'description'      => 'سامانهٔ مدیریت مدرسه',
            'start_url'        => home_url('/dashboard'),
            'scope'            => home_url('/'),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'dir'              => 'rtl',
            'lang'             => 'fa',
            'background_color' => '#f4f5f7',
            'theme_color'      => $this->theme_color(),
            'icons'            => $this->manifest_icons(),
        ];

        echo wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function serve_service_worker(): void
    {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        // Allow the SW to control the whole site, not just its own path.
        header('Service-Worker-Allowed: /');

        // A deliberately minimal, network-first service worker. It exists so
        // browsers treat the site as installable; it does not aggressively
        // cache dynamic school data (which must always be fresh).
        ?>
const HST_CACHE = 'hst-pwa-<?php echo esc_js(defined('HST_VERSION') ? HST_VERSION : '1'); ?>';

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== HST_CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  // Only handle same-origin navigations/assets; let the browser handle the
  // rest (cross-origin, etc.) untouched.
  var url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) return;

  // Dynamic school pages must never be served from an old cache.
  // Navigations are network-only with a simple offline response.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return new Response(
          '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>آفلاین</title><style>body{margin:0;padding:40px;font-family:sans-serif;text-align:center}</style></head><body>اتصال اینترنت برقرار نیست.</body></html>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      })
    );
    return;
  }

  // Static same-origin assets stay network-first and may use cache only as
  // an offline fallback.
  event.respondWith(
    fetch(req).then(function (res) {
      if (res && res.status === 200 && res.type === 'basic') {
        var copy = res.clone();
        caches.open(HST_CACHE).then(function (cache) { cache.put(req, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (cached) {
        return cached || new Response('', { status: 503 });
      });
    })
  );
});
        <?php
        exit;
    }

    public function print_head_tags(): void
    {
        if (!$this->enabled() || !$this->is_plugin_page()) {
            return;
        }
        $color = esc_attr($this->theme_color());
        echo "\n<!-- TeacherShow PWA -->\n";
        echo '<link rel="manifest" href="' . esc_url(self::manifest_url()) . '">' . "\n";
        echo '<meta name="theme-color" content="' . $color . '">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . esc_attr($this->apple_icon()) . '">' . "\n";
    }

    public function print_register_script(): void
    {
        if (!$this->enabled() || !$this->is_plugin_page()) {
            return;
        }
        $sw = esc_url(self::sw_url());
        ?>
<script>
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?php echo $sw; ?>', { scope: '/' }).catch(function (e) {
        console.warn('HST PWA service worker registration failed:', e);
      });
    });
  }
})();
</script>
        <?php
    }

    /** Reuse the script manager's detection of plugin pages. */
    private function is_plugin_page(): bool
    {
        if (!is_singular()) {
            return false;
        }
        global $post;
        if (!($post instanceof WP_Post)) {
            return false;
        }
        // Any of our shortcodes present → it's a plugin page.
        $shortcodes = [
            'hst_dashboard', 'hst_profile', 'hst_students', 'hst_teachers',
            'hst_classes', 'hst_lessons', 'hst_terms', 'hst_schedules',
            'hst_my_schedule', 'hst_enter_scores', 'hst_gradebook', 'hst_scores',
            'hst_periods', 'hst_score_audit', 'hst_notifications',
            'hst_assignments', 'hst_attendance', 'hst_exams', 'hst_tuition',
            'hst_tuition_payments', 'hst_my_teachers', 'hst_discipline',
            'hst_term_transfer', 'hst_backup', 'hst_report_cards',
            'hst_import_users', 'hst_plugin_settings', 'hst_smart_analysis',
        ];
        foreach ($shortcodes as $sc) {
            if (has_shortcode((string) $post->post_content, $sc)) {
                return true;
            }
        }
        return false;
    }
}
