<?php

defined('ABSPATH') || exit;

/**
 * Builds the isolated download screen used by browser-generated PDF exports.
 * Keeping this document in one place prevents each feature from carrying its
 * own palette, spacing, markup and retry script.
 */
final class HST_Print_Document
{
    public static function download_page(array $args): string
    {
        $title = sanitize_text_field((string) ($args['title'] ?? 'دانلود فایل'));
        $message = sanitize_text_field((string) ($args['message'] ?? 'فایل در حال آماده‌سازی است.'));
        $config = is_array($args['config'] ?? null) ? $args['config'] : [];
        $payload = is_array($args['payload'] ?? null) ? $args['payload'] : [];
        $payload_key = (string) ($args['payload_key'] ?? 'hstPrintPayload');
        $method = (string) ($args['method'] ?? 'gridPdf');
        $scripts = is_array($args['scripts'] ?? null) ? $args['scripts'] : [];

        if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $payload_key)) {
            $payload_key = 'hstPrintPayload';
        }
        if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $method)) {
            $method = 'gridPdf';
        }

        $accent = sanitize_hex_color((string) ($config['accent'] ?? '')) ?: '#334155';
        $font_url = esc_url(HST_URL . 'assets/font/Vazir.woff2');
        $config_json = wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload_key_json = wp_json_encode($payload_key);
        $method_json = wp_json_encode($method);

        $script_tags = '';
        foreach ($scripts as $src) {
            $script_tags .= '<script src="' . esc_url((string) $src) . '"></script>' . "\n";
        }

        ob_start();
        ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
@font-face{font-family:"Vazirmatn";src:url("<?php echo $font_url; ?>") format("woff2");font-display:swap}
:root{--hst-download-accent:<?php echo esc_html($accent); ?>;--hst-download-bg:#f8fafc;--hst-download-surface:#fff;--hst-download-ink:#172033;--hst-download-muted:#64748b;--hst-download-faint:#94a3b8;--hst-download-border:#e2e8f0;--hst-download-shadow:0 20px 60px rgba(15,23,42,.12)}
*{box-sizing:border-box}
body{margin:0;padding-block:24px;padding-inline:24px;font-family:"Vazirmatn",Tahoma,Arial,sans-serif;background:var(--hst-download-bg);color:var(--hst-download-ink);display:grid;min-height:100vh;place-items:center;direction:rtl}
.hst-download-card{width:100%;max-width:440px;min-width:0;margin-inline:auto;background:var(--hst-download-surface);border:1px solid var(--hst-download-border);border-radius:18px;padding:24px;text-align:center;box-shadow:var(--hst-download-shadow)}
@media (max-width:520px){body{padding-inline:16px}.hst-download-card{padding:22px 18px}}
.hst-download-card h1{font-size:18px;margin:0 0 10px;color:var(--hst-download-accent)}
.hst-download-card p{margin:0 0 16px;color:var(--hst-download-muted);line-height:1.9}
.hst-download-card button{border:0;border-radius:12px;background:var(--hst-download-accent);color:var(--hst-download-surface);font:inherit;font-weight:800;padding:10px 18px;cursor:pointer}
.hst-download-card small{display:block;margin-top:12px;color:var(--hst-download-faint)}
</style>
</head>
<body>
<main class="hst-download-card">
    <h1><?php echo esc_html($title); ?></h1>
    <p><?php echo esc_html($message); ?></p>
    <button type="button" id="hst-retry-pdf">دانلود دوباره</button>
    <small>اگر دانلود شروع نشد، روی دکمه بزنید.</small>
</main>
<script>
window.hstPrintConfig=<?php echo $config_json; ?>;
window[<?php echo $payload_key_json; ?>]=<?php echo $payload_json; ?>;
</script>
<?php echo $script_tags; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<script>
(function(){
  var payloadKey=<?php echo $payload_key_json; ?>;
  var method=<?php echo $method_json; ?>;
  function run(){
    if(!window.HSTPrint||typeof window.HSTPrint[method]!=="function"){
      window.setTimeout(run,250);
      return;
    }
    window.HSTPrint[method](window[payloadKey]||{});
  }
  window.addEventListener("load",function(){window.setTimeout(run,500);});
  document.getElementById("hst-retry-pdf").addEventListener("click",run);
})();
</script>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
