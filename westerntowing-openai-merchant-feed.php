<?php
/**
 * Plugin Name: WesternTowing WooCommerce OpenAI Merchant Feed Generator
 * Description: Generates an OpenAI Merchant Feed for WesternTowing WooCommerce products via WP-CLI. Includes simple products and in-stock variation variants with images, sale prices, exclusions import, and schema shipping details.
 * Version: 1.0.0
 * Author: Anthony Shapley
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WT_VAT_RATE')) {
    define('WT_VAT_RATE', 0.2);
}

if (!defined('WT_MAX_BRAND_LENGTH')) {
    define('WT_MAX_BRAND_LENGTH', 70);
}

if (!defined('WT_MAX_DESCRIPTION_LENGTH')) {
    define('WT_MAX_DESCRIPTION_LENGTH', 5000);
}

if (!defined('WT_BRAND_TAXONOMY')) {
    define('WT_BRAND_TAXONOMY', 'pa_brand');
}

if (!defined('WT_SKU_PREFIX_LENGTH')) {
    define('WT_SKU_PREFIX_LENGTH', 3);
}

if (!defined('WT_MARGIN_HIGH_THRESHOLD')) {
    define('WT_MARGIN_HIGH_THRESHOLD', 25);
}

if (!defined('WT_MARGIN_MID_THRESHOLD')) {
    define('WT_MARGIN_MID_THRESHOLD', 15);
}

if (!defined('WT_MARGIN_LOW_THRESHOLD')) {
    define('WT_MARGIN_LOW_THRESHOLD', 0);
}

if (!defined('WT_EXCLUSION_META_KEY')) {
    define('WT_EXCLUSION_META_KEY', '_wt_ppc');
}

if (!defined('WT_GTIN_META_KEY')) {
    define('WT_GTIN_META_KEY', '_wt_gtin');
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wt-generate-openai-feed', 'wt_generate_openai_feed_command');
}

/**
 * Replace known named entities with numeric entities and escape output.
 */
function wt_clean_xml_content($content) {
    $replacements = array(
        '&nbsp;'   => '&#160;',
        '&ordm;'   => '&#186;',
        '&lsquo;'  => '&#8216;',
        '&rsquo;'  => '&#8217;',
        '&ldquo;'  => '&#8220;',
        '&rdquo;'  => '&#8221;',
        '&Prime;'  => '&#8243;',
        '&deg;'    => '&#176;',
        '&sup3;'   => '&#179;',
        '&sup2;'   => '&#178;',
        '&reg;'    => '&#174;',
        '&trade;'  => '&#8482;',
        '&pound;'  => '&#163;',
        '&mdash;'  => '&#8212;',
        '&ndash;'  => '&#8211;',
        '&hellip;' => '&#8230;',
        '&euro;'   => '&#8364;',
        '&copy;'   => '&#169;',
        '&laquo;'  => '&#171;',
        '&raquo;'  => '&#187;',
    );

    foreach ($replacements as $entity => $char) {
        $content = str_replace($entity, $char, $content);
    }

    return esc_html($content);
}

/**
 * Return the exclusion meta key.
 */
function wt_get_exclusion_meta_key() {
    return WT_EXCLUSION_META_KEY;
}

/**
 * Determine custom margin label.
 */
function wt_get_custom_label_based_on_margin($product) {
    $cost           = (float) get_post_meta($product->get_id(), '_wc_cog_cost', true);
    $regular_price  = (float) $product->get_regular_price();

    if ($cost <= 0 || $regular_price <= 0) {
        return 'unknown';
    }

    $margin_percentage = (($regular_price - $cost) / $regular_price) * 100;

    if ($margin_percentage >= WT_MARGIN_HIGH_THRESHOLD) {
        return 'high_margin';
    }

    if ($margin_percentage >= WT_MARGIN_MID_THRESHOLD) {
        return 'mid_margin';
    }

    if ($margin_percentage >= WT_MARGIN_LOW_THRESHOLD) {
        return 'low_margin';
    }

    return 'loss_making';
}

/**
 * WP-CLI command: wt-generate-openai-feed
 *
 * Generates a JSON merchant feed for OpenAI containing all published, in-stock
 * simple products and variation variants that have an image.
 */
function wt_generate_openai_feed_command($args, $assoc_args) {
    $filename      = ABSPATH . 'wt_openai_merchant_feed.json';
    $temp_filename = $filename . '.tmp';

    $seller_name = get_bloginfo('name');
    $seller_url  = esc_url(site_url());

    $paged          = 1;
    $products_array = array();

    while (true) {
        $query_args = array(
            'post_type'      => 'product',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'post_status'    => 'publish',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $products = new WP_Query($query_args);
        if (!$products->have_posts()) {
            break;
        }

        while ($products->have_posts()) {
            $products->the_post();
            $product = wc_get_product(get_the_ID());

            if (!$product) {
                continue;
            }

            if (!$product->is_type('simple') && !$product->is_type('variable')) {
                continue;
            }

            $products_to_process = $product->is_type('simple') ? array($product) : $product->get_children();

            foreach ($products_to_process as $child_or_simple) {
                $current_product = $product->is_type('simple') ? $product : wc_get_product($child_or_simple);
                if (!$current_product) {
                    continue;
                }

                // Resolve the image: use the variant's own image, falling back to the parent.
                $image_id = $current_product->get_image_id();
                if (empty($image_id) && $current_product->is_type('variation')) {
                    $image_id = $product->get_image_id();
                }

                // Skip products/variants that have no image.
                if (empty($image_id)) {
                    continue;
                }

                // Skip if PPC exclusion flag is explicitly set to 'no'.
                $exclude_flag = (string) get_post_meta($current_product->get_id(), WT_EXCLUSION_META_KEY, true);
                if ('no' === strtolower($exclude_flag)) {
                    continue;
                }

                // Only include products that are in stock.
                if ($current_product->get_stock_status() !== 'instock') {
                    continue;
                }

                $regular_price = (float) $current_product->get_regular_price();
                if ($regular_price <= 0) {
                    continue;
                }

                $tax_class = $current_product->get_tax_class();
                if ($tax_class === 'zero-rate') {
                    $regular_price_with_vat = $regular_price;
                } else {
                    $regular_price_with_vat = $regular_price * (1 + WT_VAT_RATE);
                }
                $regular_price_with_vat = round($regular_price_with_vat, 2);

                $sale_price_with_vat = null;
                if ($current_product->is_on_sale()) {
                    $sale_price = (float) $current_product->get_sale_price();
                    if ($tax_class === 'zero-rate') {
                        $sale_price_with_vat = $sale_price;
                    } else {
                        $sale_price_with_vat = $sale_price * (1 + WT_VAT_RATE);
                    }
                    $sale_price_with_vat = round($sale_price_with_vat, 2);
                }

                $description = $current_product->get_description();
                if (empty($description) && $current_product->is_type('variation')) {
                    $description = $product->get_description();
                }

                $description = wt_clean_xml_content((string) $description);
                $description = wp_strip_all_tags($description);
                if (strlen($description) > WT_MAX_DESCRIPTION_LENGTH) {
                    $description = substr($description, 0, WT_MAX_DESCRIPTION_LENGTH);
                }

                $brands     = wp_get_post_terms($current_product->get_id(), WT_BRAND_TAXONOMY);
                $brand_name = '';
                if (!is_wp_error($brands) && !empty($brands)) {
                    $brand_name = $brands[0]->name;
                }

                $image_url = wp_get_attachment_url($image_id);

                $product_item = array(
                    'item_id'      => $current_product->get_sku() ?: (string) $current_product->get_id(),
                    'title'        => wp_strip_all_tags($current_product->get_name()),
                    'description'  => $description,
                    'url'          => esc_url($current_product->get_permalink()),
                    'brand'        => substr((string) $brand_name, 0, WT_MAX_BRAND_LENGTH),
                    'availability' => 'in_stock',
                    'image_url'    => esc_url((string) $image_url),
                    'price'        => number_format($regular_price_with_vat, 2, '.', '') . ' GBP',
                );

                if ($sale_price_with_vat !== null && $sale_price_with_vat > 0 && $sale_price_with_vat < $regular_price_with_vat) {
                    $product_item['sale_price'] = number_format($sale_price_with_vat, 2, '.', '') . ' GBP';
                }

                $gtin = (string) get_post_meta($current_product->get_id(), WT_GTIN_META_KEY, true);
                if (!empty($gtin)) {
                    $product_item['gtin'] = $gtin;
                }

                $sku = (string) $current_product->get_sku();
                $mpn = (strlen($sku) > WT_SKU_PREFIX_LENGTH) ? substr($sku, WT_SKU_PREFIX_LENGTH) : '';
                if (!empty($mpn)) {
                    $product_item['mpn'] = $mpn;
                }

                $custom_label = wt_get_custom_label_based_on_margin($current_product);
                if (!empty($custom_label) && $custom_label !== 'unknown') {
                    $product_item['custom_label_0'] = $custom_label;
                }

                $shipping_class = $current_product->get_shipping_class();
                if (!empty($shipping_class)) {
                    $product_item['shipping_label'] = $shipping_class;
                }

                $product_item['condition'] = 'new';

                if ($product->is_type('variable')) {
                    $product_item['group_id'] = $product->get_sku() ?: (string) $product->get_id();

                    $attributes = $current_product->get_attributes();
                    if (!empty($attributes)) {
                        $variant_dict = array();
                        foreach ($attributes as $attr_name => $attr_value) {
                            if (!empty($attr_value)) {
                                $variant_dict[(string) $attr_name] = (string) $attr_value;
                            }
                        }

                        if (!empty($variant_dict)) {
                            $product_item['variant_dict'] = $variant_dict;
                        }
                    }
                }

                $products_array[] = $product_item;
            }
        }

        wp_reset_postdata();
        $paged++;
    }

    $output = array(
        'seller_name'      => $seller_name,
        'seller_url'       => $seller_url,
        'target_countries' => array('GB'),
        'store_country'    => 'GB',
        'products'         => $products_array,
    );

    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        WP_CLI::error('json_encode failed: ' . json_last_error_msg());
        return;
    }

    $handle = fopen($temp_filename, 'w');
    if ($handle === false) {
        WP_CLI::error('Unable to open temp file for writing: ' . $temp_filename);
        return;
    }

    $bytes_written = fwrite($handle, $json);
    if ($bytes_written === false || $bytes_written !== strlen($json)) {
        fclose($handle);
        if (file_exists($temp_filename)) {
            unlink($temp_filename);
        }
        WP_CLI::error('Unable to write feed data to temp file: ' . $temp_filename);
        return;
    }
    fclose($handle);

    if (!rename($temp_filename, $filename)) {
        WP_CLI::error('Unable to move temp file into place: ' . $filename);
        return;
    }

    WP_CLI::success('OpenAI Merchant Feed generated successfully at ' . $filename . ' with ' . count($products_array) . ' items');
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Command to import PPC exclusions from a CSV file, including product variations.
     */
    class WT_OpenAI_Exclusions_Command {
        /**
         * Imports PPC exclusions and updates product meta.
         *
         * ## OPTIONS
         *
         * <file>
         * : The path to the CSV file containing the SKUs.
         *
         * ## EXAMPLES
         *
         *     wp wt_openai_exclusions import path/to/your/file.csv
         *
         * @when after_wp_load
         */
        public function import($args, $assoc_args) {
            [$file] = $args;

            $real = realpath($file);
            if ($real === false || !file_exists($real)) {
                WP_CLI::error('File not found: ' . $file);
                return;
            }
            $file = $real;

            $handle = fopen($file, 'r');
            if (!$handle) {
                WP_CLI::error('Unable to open file: ' . $file);
                return;
            }

            $updated   = 0;
            $not_found = 0;

            while (($data = fgetcsv($handle)) !== false) {
                if (!isset($data[0])) {
                    continue;
                }

                $sku = trim($data[0]);
                if ($sku === '') {
                    continue;
                }

                $product_id = wc_get_product_id_by_sku($sku);

                if ($product_id) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $meta_key = wt_get_exclusion_meta_key();
                        update_post_meta($product_id, $meta_key, 'no');
                    }
                    $updated++;
                    WP_CLI::line('Updated SKU: ' . $sku . ' with PPC exclusion.');
                } else {
                    $variation_args = array(
                        'status' => 'publish',
                        'type'   => 'variation',
                        'sku'    => $sku,
                        'limit'  => 1,
                    );

                    $variations = wc_get_products($variation_args);
                    if (!empty($variations)) {
                        $variation = $variations[0];
                        $meta_key  = wt_get_exclusion_meta_key();
                        update_post_meta($variation->get_id(), $meta_key, 'no');
                        $updated++;
                        WP_CLI::line('Updated Variation SKU: ' . $sku . ' with PPC exclusion.');
                    } else {
                        $not_found++;
                        WP_CLI::line('SKU not found: ' . $sku);
                    }
                }
            }

            fclose($handle);

            WP_CLI::success($updated . ' SKUs updated. ' . $not_found . ' SKUs not found.');
        }
    }

    WP_CLI::add_command('wt_openai_exclusions', 'WT_OpenAI_Exclusions_Command');
}

add_filter('woocommerce_structured_data_product', 'wt_add_shipping_info_to_schema', 10, 2);

/**
 * Adds shipping destination and shipping label to schema output.
 */
function wt_add_shipping_info_to_schema($markup, $product) {
    $shipping_class_id   = $product->get_shipping_class_id();
    $shipping_class_slug = '';

    if ($shipping_class_id) {
        $shipping_class_term = get_term($shipping_class_id, 'product_shipping_class');
        if (!is_wp_error($shipping_class_term) && $shipping_class_term) {
            $shipping_class_slug = $shipping_class_term->slug;
        }
    }

    if (!empty($shipping_class_slug)) {
        $markup['shippingDetails'] = array(
            '@type'               => 'OfferShippingDetails',
            'shippingDestination' => array(
                '@type'          => 'DefinedRegion',
                'addressCountry' => 'GB',
            ),
            'shippingLabel'       => $shipping_class_slug,
        );
    }

    return $markup;
}
