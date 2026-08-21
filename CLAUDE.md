# CLAUDE.md — Cosmotec EC-CUBE → Magento Migration

## Project
This is the existing Cosmotec EC-CUBE 4.0.0 → Magento 2.4.8-p3 migration project.

Magento module:
`app/code/Cosmotec/EccubeMigration/`

**Continue and extend the existing module. Do not create a replacement migration module or discard the existing architecture.**

## First read
Before changing code, read:
- `BUILD_STATUS.md`
- `README.md`
- relevant `docs/*.md`
- `docs/EXISTING_MODULE_ANALYSIS.md`
- `docs/ECCUBE_DATABASE_ANALYSIS.md`
- `docs/MIGRATION_ASSUMPTIONS.md`
- `docs/MIGRATION_GAPS.md`
- `docs/MILESTONE_STATUS.md` if present
- the complete current `app/code/Cosmotec/EccubeMigration/`

Treat these as the existing project baseline. If documentation and code disagree, inspect the code and report the discrepancy.

## Architecture
Preserve the established flow:

Reader → Repository → DTO → Validator → Mapper → Importer → Mapping/History → Sync

Extend existing components rather than creating parallel implementations.

## Confirmed product mapping
- EC-CUBE `dtb_item` → Magento Grouped Product
- EC-CUBE `dtb_product` → Magento Simple Product

Do not flatten parent and child data.

## EC-CUBE source and database
The current environment indicates the EC-CUBE source is associated with:
`/var/www/html/magento/m2/eccube/`

Confirmed EC-CUBE source image directory:
`/var/www/html/magento/m2/eccube/html/upload/save_image/`

Do not invent a different path. Verify the actual environment/configuration if it differs.

The EC-CUBE database is the separate source database configured for the migration module. Do not assume it is a SQL file inside the Magento module. Use the existing configured EC-CUBE DB connection. Treat source data as read-only unless explicitly instructed otherwise.

Magento project:
the project containing `app/code/Cosmotec/EccubeMigration/`.

Magento logs:
`var/log/`

## Vendor
Magento `vendor/` and Magento core may be inspected to verify framework behavior, but **never modify files under `vendor/`**.

## Current media architecture
Media relations include:
- `product`
- `dimension`
- `cad2d`
- `cad3d`
- `item`
- `catalog`
- `category`

Canonical media identity:
`(relation_type, owner_id, upload_file_id)`

Media mapping:
`eccube_media_map`

Product reference mapping:
`eccube_product_reference_map`

Legacy image chains must not be reintroduced into the production media path.

## Critical media rules
### Product gallery
Use Magento's native product media-gallery representation. Product gallery files must resolve under:
`pub/media/catalog/product/`

Do not store custom filesystem paths as Magento gallery `file` values.

### Category images
Use Magento's native category media representation under:
`pub/media/catalog/category/`

### CAD
CAD files are ZIP files:
- keep ZIP format
- do not unzip
- do not reject as non-images
- do not store CAD binaries in product EAV
- track CAD/document files through the module media mapping

### Dimensions
Dimension images must not become storefront primary product images or `image/small_image/thumbnail`.

## Media source path
EC-CUBE DB stores filenames; the source directory is `html/upload/save_image`. The Magento configuration must resolve this to an absolute filesystem path.

## Media CLI safety
Media import is dry-run by default.

- `--dry-run` = no writes
- `--execute` = real writes
- if both are supplied, dry-run wins
- never assume omission of `--dry-run` means execute

Before a real test, verify output explicitly says `[EXECUTE]`.

## Media filtering
Use:
- `--owner-id`
- `--upload-file-id`

They are mutually exclusive. `--source-id` is deprecated.

Filtering must happen in SQL/repositories, not by scanning all relations in PHP.

## Media performance
The source has approximately 71,072 media relations. Use pagination, batching, SQL filtering, owner-level paging, mapping tables, and hashes. Never load the complete dataset into memory.

## Media errors
Distinguish:
- `SOURCE_FILE_NOT_FOUND`
- `SOURCE_FILE_NOT_READABLE`
- `STATUS_NEEDS_REVIEW`
- actual runtime `ERROR`

Missing source files must not be fabricated, renamed, or silently ignored.

## Last known media blocker
The last known runtime failure was:

`Call to undefined method Cosmotec\EccubeMigration\Model\Import\MediaImporter::isPrimaryImage()`

at `MediaImporter.php:455`

Target:
`bin/magento cosmotec:eccube:import:images --type=product --upload-file-id=1124 --execute`

The dry run identified:
`10298_p-5d4d225b13686-1.jpg`

for Magento product:
- ID: `2382`
- SKU: `10319`

The product itself passed a controlled save test. A gallery isolation test also demonstrated that Magento's canonical gallery representation can save entries.

**Before moving to attributes/specifications, inspect and resolve/verify this media runtime issue.**

## Media idempotency
A second unchanged import must not duplicate the gallery entry. Verify both Magento gallery state and `eccube_media_map`, not only CLI counters.

## Product references
EC-CUBE product references are true 1:N. Do not flatten them. Preserve ownership, source ID, link/name, synchronization and obsolete handling. The previous source analysis found no explicit ordering column; use deterministic ID fallback if ordering is required.

## Inventory
Authoritative source field:
`dtb_product.stock_quantity`

Use Magento MSI. Do not silently substitute another stock field.

## Specifications / attributes
Do not guess the Magento EAV design.

Important source tables:
- `dtb_specification`
- `dtb_specification_group`
- `dtb_specification_class`
- `dtb_item_specification`
- `dtb_item_specification_class`
- `dtb_product_specification_class`

The meaning of `dtb_specification.type = 0/1` must be verified from actual EC-CUBE source/database usage before finalizing the design.

## Attribute options and sets
Verify:
- English/Japanese labels
- source IDs
- specification ownership
- ordering
- usage
- numeric/free-form/selectable semantics
- product-family applicability

Do not mass-create Magento attributes/options/sets without a read-only analysis/dry-run.

## Performance
Previous analysis reported approximately:
- 27,903 products
- 4,126 parent/items
- 1.14M product specification assignments
- 418K related-product relationships

Use batching, pagination, streaming/bulk operations, mapping tables, hashes and resumable processing.

## Related products
`dtb_related_product` is a genuine product-to-product relationship. Preserve direction, identity, idempotency, sync and obsolete relationships. Map appropriately to Magento Related Products.

## Connection/coupling products
`dtb_coupling_product` is semantically different from Related Products. Treat it as a separate relationship such as `connection_part`/accessory. Do not misuse Magento Related Products for it.

## Categories
Preserve EC-CUBE category hierarchy and trace actual controller/repository/service/Twig/database behavior. Do not implement category behavior from screenshots alone.

## Model List
The parent/group product Model List represents child/simple products and may display different specification columns by product family. Trace actual EC-CUBE source behavior. Do not hard-code one universal column list.

## Filters and attributes
Keep separate:
- Magento attribute
- filterable attribute
- Model List column
- Basic Information field
- internal specification
- parent-level specification
- child-level specification

A specification being an attribute does not automatically make it a frontend filter.

## Images
EC-CUBE uses contextual image variants such as:
- `*_c-150-150-*`
- `*_i-150-150-*`
- `*_i-300-300-*`
- `*_p-150-150-*`
- `*_p-1000-1000-*`

Do not copy unnecessary generated variants if Magento can generate appropriate sizes natively. Preserve source ownership and semantic purpose.

## Tabs/content
Previous analysis found database-driven tabs and important `dtb_item_additional_information` content:
- 89 distinct tab names
- 3,264 rows
- 555 populated
- 554 populated rows containing HTML
- 307 containing `<img>`
- 485 containing `<a>`
- `name_en` exists for titles, but examined tab content is Japanese-only

Do not automatically translate. Trace actual rendering before selecting Magento storage.

## Language
English preferred; Japanese fallback when required and English is unavailable. Never invent translations.

## URL keys
Previous analysis found no native EC-CUBE equivalents for Magento URL/SEO fields such as `url_key`, `meta_title`, `meta_description`, `meta_keyword`. Generate deterministic Magento URL keys and handle collisions safely.

## Source-code investigation
When source code can answer a question, inspect it instead of guessing. Relevant EC-CUBE areas include:
- `app/Customize/`
- `app/Doctrine/`
- `src/`
- `app/template/`
- `app/Plugin/`

Search for:
`dtb_specification`, `dtb_specification_group`, `dtb_specification_class`, `dtb_item_specification`, `dtb_item_specification_class`, `dtb_product_specification_class`, `dtb_related_product`, `dtb_coupling_product`

and:
`Model List`, `Filter by model`, `Connection parts`, `Related products`, `Basic Information`, `Catalog`, `Assembly method`, `Pressing tools`, `Frequently Asked Questions`, `The difference of the ground and floating`.

## Error handling
A bad source record must not stop the complete migration. Validate, import in try/catch, log source IDs/context, record mapping status, continue, and make failures retryable. Never swallow useful exception information.

## No data loss
Preserve category hierarchy, parent/group products, child/simple products, specifications, options, parent/child attributes, inventory, images, related products, connection parts, relevant content, English/Japanese fallback and meaningful ordering.

If Magento has no direct equivalent, design an appropriate Magento representation instead of silently dropping data.

## Runtime testing
Use the actual Magento environment for runtime verification:
- `php -l`
- `bin/magento setup:di:compile`
- targeted dry-run
- targeted execute
- database verification
- Magento Admin verification
- idempotency/re-run verification

Do not claim a test passed unless it was actually executed.

## Safety
Do not:
- modify `vendor/`
- create a replacement migration module
- delete EC-CUBE source data
- run destructive SQL without explicit approval
- modify EC-CUBE source unnecessarily
- hide exceptions
- make major architectural changes without explaining why

## Documentation
After significant work update `BUILD_STATUS.md` with:
- completed work
- files changed
- tests actually run
- exact results
- known issues
- current blocker
- next task

Update relevant `docs/*.md` when architecture/source findings change.

## Current priority
At the beginning of a new session:
1. Read `BUILD_STATUS.md`, `README.md` and relevant `docs/*.md`.
2. Inspect the current media implementation.
3. Verify the last known `isPrimaryImage()` issue against the actual code.
4. Run targeted runtime checks as appropriate.
5. Fix and verify media end-to-end.
6. Do not move to attributes/specifications until media execute mode and Magento Admin gallery are proven.
7. Only then proceed to specifications → attributes → options → attribute sets → product values → related products → connection/coupling products → remaining content → synchronization.
