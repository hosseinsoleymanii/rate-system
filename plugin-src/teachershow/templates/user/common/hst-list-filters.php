<?php
/**
 * Reusable client-side list filters for HST tables.
 */
defined('ABSPATH') || exit;

if (!function_exists('hst_filter_options_from_items')) {
    function hst_filter_options_from_items($items, $property, $separator = ',') {
        $options = [];

        foreach ((array) $items as $item) {
            $value = is_array($item) ? ($item[$property] ?? '') : ($item->{$property} ?? '');

            $parts = $property === 'classes'
                ? (preg_split('/\s*(?:،|,)\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                : array_filter(array_map('trim', explode($separator, (string) $value)));

            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $options[$part] = $part;
                }
            }
        }

        if ($property === 'classes' && class_exists('HST_Classes')) {
            return HST_Classes::sort_options($options);
        }

        ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }
}

if (!function_exists('hst_render_list_filters')) {
    function hst_render_list_filters(array $args) {
        $target_id          = isset($args['target_id']) ? sanitize_html_class($args['target_id']) : '';
        $search_placeholder = $args['search_placeholder'] ?? 'جست‌وجو...';
        $filters            = $args['filters'] ?? [];

        if (!$target_id) {
            return;
        }
        ?>
        <div class="hst-filters hst-list-filters" data-hst-filter-target="<?php echo esc_attr($target_id); ?>" dir="rtl">
            <div class="hst-filters__field">
                <label for="<?php echo esc_attr($target_id); ?>-search">جست‌وجو</label>
                <input
                    id="<?php echo esc_attr($target_id); ?>-search"
                    type="search"
                    class="hst-search"
                    placeholder="<?php echo esc_attr($search_placeholder); ?>"
                    autocomplete="off"
                    data-hst-filter="search"
                >
            </div>

            <?php foreach ($filters as $filter) : ?>
                <?php
                $key     = isset($filter['key']) ? sanitize_key($filter['key']) : '';
                $label   = $filter['label'] ?? '';
                $options = $filter['options'] ?? [];

                if (!$key || empty($options)) {
                    continue;
                }
                ?>
                <div class="hst-filters__field">
                    <label for="<?php echo esc_attr($target_id . '-' . $key); ?>"><?php echo esc_html($label); ?></label>
                    <select id="<?php echo esc_attr($target_id . '-' . $key); ?>" data-hst-filter="<?php echo esc_attr($key); ?>">
                        <option value="">همه</option>
                        <?php foreach ($options as $value => $option_label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="hst-filters__actions">
                <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-filter-reset" data-hst-filter-reset>پاک کردن فیلترها</button>
                <span class="hst-filters__count hst-filter-count" data-hst-filter-count aria-live="polite"></span>
            </div>
        </div>
        <?php
    }
}
