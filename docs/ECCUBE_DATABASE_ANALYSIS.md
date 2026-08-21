# EC-CUBE Database Analysis (Real Production Dump)

Source: `cosmotec_db_bkp.zip` → `cosmotec-db-bkp.sql` (472MB, 124 tables).
This supersedes the earlier trimmed 44-table dump used for V1 — that dump
was catalog-only and did not include the attribute/relationship tables
documented here.

Note on scope: per the effort available for this analysis pass, this
single document consolidates what the prompt requests as several separate
files (`ECCUBE_ARCHITECTURE.md`, `ECCUBE_ATTRIBUTE_ANALYSIS.md`,
`ECCUBE_ATTRIBUTE_SET_ANALYSIS.md`, `ECCUBE_RELATED_PRODUCTS.md`,
`ECCUBE_MAGENTO_MAPPING.md`) rather than splitting genuinely-related
findings across many thin files. Split further on request.

## Scale (from AUTO_INCREMENT high-water marks — actual max IDs, not
   necessarily live row counts, but a reliable scale indicator)

| Table | Approx. count |
|---|---|
| `dtb_category` | ~406 |
| `dtb_item` | ~4,126 |
| `dtb_product` | ~27,903 |
| `dtb_specification` | ~379 |
| `dtb_specification_class` (option values) | ~7,541 |
| `dtb_item_specification` (item↔spec links) | ~216,100 |
| `dtb_product_specification_class` (**actual attribute value assignments**) | ~1,139,910 |
| `dtb_related_product` | ~418,376 |
| `dtb_coupling_product` | ~16,731 |

The product-specification-value volume (~1.14M) is the single largest
write workload this migration will ever perform — larger than every other
entity combined. This must be batched from the start (see
`MIGRATION_GAPS.md`, Performance row).

## Attribute/specification system — actual structure

```
dtb_specification_group  (attribute GROUP, e.g. "フランジ"/Flange, "接続タイプ"/Connection type)
        │ 1:N
dtb_specification         (attribute DEFINITION — name, name_en, type, sort_no, editable)
        │ 1:N
dtb_specification_class   (attribute OPTION/VALUE definition — name, name_en, rohs flag)
        │
        ├── dtb_item_specification_class  (which options are available for a given item's use of a spec)
        └── dtb_product_specification_class  (the ACTUAL value assigned to a specific dtb_product)

dtb_item_specification    (which specifications apply to a given dtb_item, with `selectable` and `type`)
```

Confirmed real example (group id 1, "フランジ"/Flange):
`dtb_specification` rows under this group include both:
- **Flange-size specs** (`type=0`): "フランジ ICF（1個）" / "Flange ICF (1 pieces)",
  "フランジ NW（2個）" / "Flange NW (2 pieces)", etc. — these read like
  **selectable/dropdown-style specs** (flange connector type+count).
- **Dimension-letter specs** (`type=1`): "ICF", "NW/KF", "VF", "VG",
  "ISO-K", "ISO-F", "サニタリー"/"sanitary", then bare dimension labels
  "A", "B", "C", "C1", "D", "D1", "D2", "Φ D1", "Φ D2"... — these read
  like **numeric measurement fields** (a dimension letter *is* the
  attribute name; the value is presumably a number stored elsewhere,
  likely in `dtb_item_specification`/`dtb_product_specification_class`'s
  numeric context or in `dtb_product.free_area`/similar free-text field).

**This is the single most important open question for Milestone 4** (see
`MIGRATION_ASSUMPTIONS.md`): `type=0` vs `type=1` specifications likely
need **different Magento attribute treatment** — `type=0` as a `select`
EAV attribute with options sourced from `dtb_specification_class`;
`type=1` as a `text`/`decimal` attribute for numeric dimension display —
rather than treating all ~379 specifications uniformly. Auto-creating all
379 as one type without confirming this split risks producing a
technically-working but practically-unusable attribute set (hundreds of
single-use "select" attributes for what should be simple numeric fields).

## Related products vs. coupling products — different semantics, confirmed

- **`dtb_related_product`**: `product_id` → `related_product_id`, both
  referencing `dtb_product`. Simple-product-to-simple-product. Maps
  cleanly to Magento "Related Products" links.
- **`dtb_coupling_product`**: `item_id` → `product_id`. **Item**
  (Grouped Product) to **Product** (Simple Product) — cross-entity-type,
  not simple-to-simple. Matches the spec's mention of "accessories/
  connection parts": this is an Item referencing a *specific* Product as
  a compatible/connecting accessory, not a same-type relation. This does
  **not** map to Magento's built-in related/upsell/cross-sell link types
  cleanly (those are same-product-type by convention in Magento's UI,
  though the underlying `catalog_product_link` schema doesn't enforce
  it) — recommend either a custom link type or a Grouped-Product-style
  association, to be confirmed (`MIGRATION_ASSUMPTIONS.md`).

## Category — confirmed final column list (real schema)

`id`, `parent_category_id`, `creator_id`, `category_name`, `hierarchy`,
`sort_no`, `create_date`, `update_date`, `discriminator_type`,
`short_name`, `description`, `category_name_en`, `short_name_en`,
`description_en`. **No SEO/meta columns.** Matches what the module
already reads — no gap here beyond what's already implemented.

## SEO/URL — confirmed absent as native EC-CUBE data

No `meta_title`/`meta_description`/`meta_keyword`/`url_key`-equivalent
column exists on `dtb_category` or `dtb_product` anywhere in the real
schema, and no SEO-oriented package appears in `composer.lock`. Milestone
10 ("SEO & URLs") is therefore **not a migration task** in the normal
sense — there's no source SEO data to migrate. What remains genuinely
useful: **generating** Magento url_keys for products (categories already
get one via the existing slugify logic in `CategoryMapper`; products
don't yet — see `MIGRATION_GAPS.md`).

## Tags — present, low priority

`dtb_tag`, `dtb_product_tag`, `dtb_search_tag`, `dtb_item_search_tag`,
`dtb_category_search_tag`, `dtb_customer_favorite_product_tag` all exist.
These are storefront search/favorites features, not core catalog data —
recommend deferring unless specifically requested (see
`MIGRATION_GAPS.md`).

## EC-CUBE source customizations (from `cosmotec_source.zip`, inspected in
   the V1 build)

`app/Customize/Repository/{Item,ProductRepositoryExtend}.php` contain the
custom query logic already reflected in the module's Reader/Repository
layer (e.g. `ProductRepositoryExtend`'s in/out-of-stock filtering on
`p.stock_quantity` directly, which is why the confirmed inventory
reconciliation rule treats `dtb_product.stock_quantity` as authoritative).
No additional customizations were found in this pass beyond what V1
already incorporated; a full `app/Customize/` re-read specifically for
attribute/specification-related business logic (e.g. does the storefront
treat `type=1` specs differently from `type=0`, confirming the
select-vs-numeric split above) is the concrete next research step before
Milestone 4 implementation begins.
