# WesternTowing WooCommerce OpenAI Merchant Feed

A WordPress plugin that generates an [OpenAI merchant feed](https://platform.openai.com/docs/guides/shopping) for WesternTowing WooCommerce products via WP-CLI.

## Features

- Generates a JSON merchant feed compatible with OpenAI's shopping product schema
- Includes published, in-stock simple products and variable product variants
- Resolves product images (falls back to parent image for variations without one)
- Applies VAT (20%) to prices, with zero-rate tax class support
- Outputs sale prices where applicable
- Reads GTIN and MPN from product meta fields (`_ampology_gtin`, `_ampology_mpn`)
- Calculates a margin-based custom label (`high_margin`, `mid_margin`, `low_margin`, `unknown`)
- Includes shipping class label in feed output
- Supports PPC exclusion via a product meta flag (`_ampology_ppc`)
- Imports PPC exclusions in bulk from a CSV file via WP-CLI
- Adds structured schema shipping details via a WooCommerce filter

---

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- [WP-CLI](https://wp-cli.org/) (for feed generation and exclusion imports)

---

## Installation

1. Copy `westerntowing-openai-merchant-feed.php` into your WordPress plugins directory:
   ```
   wp-content/plugins/westerntowing-openai-merchant-feed/
   ```
2. Activate the plugin via the WordPress admin panel or WP-CLI:
   ```bash
   wp plugin activate westerntowing-openai-merchant-feed
   ```

---

## Usage

### Generate the Feed

Run the following WP-CLI command from the root of your WordPress installation:

```bash
wp wt-generate-openai-feed
```

This writes the feed to `{ABSPATH}/wt_openai_merchant_feed.json`.

The feed is written atomically via a temporary file to prevent serving a partially written file.

---

### Import PPC Exclusions

To exclude products from the feed, create a CSV file with one SKU per row and import it:

```bash
wp wt_openai_exclusions import path/to/your/file.csv
```

This sets the `_ampology_ppc` meta key to `no` on each matched product or variation. Products with this flag set to `no` are skipped during feed generation.

---

## Product Meta Keys

| Meta Key         | Purpose                                              |
|------------------|------------------------------------------------------|
| `_ampology_ppc`  | PPC exclusion flag. Set to `no` to exclude a product |
| `_ampology_gtin` | Product GTIN (Global Trade Item Number)              |
| `_ampology_mpn`  | Product MPN (Manufacturer Part Number)               |

---

## Configuration Constants

These constants can be overridden in `wp-config.php` before the plugin loads:

| Constant                  | Default       | Description                                          |
|---------------------------|---------------|------------------------------------------------------|
| `WT_VAT_RATE`             | `0.2`         | VAT rate applied to prices (20%)                     |
| `WT_MAX_BRAND_LENGTH`     | `70`          | Maximum character length for brand name              |
| `WT_MAX_DESCRIPTION_LENGTH` | `5000`      | Maximum character length for product description     |
| `WT_BRAND_TAXONOMY`       | `pa_brand`    | WooCommerce attribute taxonomy used for brand        |
| `WT_SKU_PREFIX_LENGTH`    | `3`           | SKU prefix length (unused when MPN meta key is set)  |
| `WT_MARGIN_HIGH_THRESHOLD`| `25`          | Margin % threshold for `high_margin` label           |
| `WT_MARGIN_MID_THRESHOLD` | `15`          | Margin % threshold for `mid_margin` label            |
| `WT_MARGIN_LOW_THRESHOLD` | `0`           | Margin % threshold for `low_margin` label            |
| `WT_EXCLUSION_META_KEY`   | `_ampology_ppc` | Meta key used for PPC exclusion flag              |
| `WT_GTIN_META_KEY`        | `_ampology_gtin` | Meta key used for GTIN                           |
| `WT_MPN_META_KEY`         | `_ampology_mpn`  | Meta key used for MPN                            |

---

## Feed Format

The output JSON follows this structure:

```json
{
  "seller_name": "Your Store Name",
  "seller_url": "https://yourstore.com",
  "target_countries": ["GB"],
  "store_country": "GB",
  "products": [
    {
      "item_id": "SKU123",
      "title": "Product Title",
      "description": "Product description...",
      "url": "https://yourstore.com/product/slug",
      "brand": "Brand Name",
      "availability": "in_stock",
      "image_url": "https://yourstore.com/wp-content/uploads/image.jpg",
      "price": "119.99 GBP",
      "sale_price": "99.99 GBP",
      "gtin": "1234567890123",
      "mpn": "MPN-001",
      "custom_label_0": "high_margin",
      "shipping_label": "large-item",
      "condition": "new",
      "group_id": "PARENT-SKU",
      "variant_dict": {
        "pa_colour": "red",
        "pa_size": "large"
      }
    }
  ]
}
```

---

## Author

Anthony Shapley
