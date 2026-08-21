# Existing Module Analysis — Cosmotec_EccubeMigration

Baseline: the delivered module as of the last fix round (156 PHP files).
This document inspects what exists before any V2 work begins, per the
continuation prompt's Step 1-9 requirement.

## 1. Already implemented (do not rebuild)

- **Skeleton**: `registration.php`, `composer.json`, `etc/module.xml`,
  `etc/acl.xml`, admin menu, `etc/adminhtml/system.xml` (connection +
  general + cron config).
- **EC-CUBE connection**: dedicated PDO connection
  (`Model/Connection/EccubeConnection*`), never reuses Magento's DB
  connection. Configurable host/port/db/user/password/charset/timeout.
  **Sound, reusable as-is.**
- **Reader framework**: `Model/Reader/{Category,Item,Product,Inventory,Image}Reader.php`,
  generator-based, resumable, batched via `AbstractReader`.
- **Repository layer**: `Api/*RepositoryInterface.php` + `Model/Repository/*.php`
  for both EC-CUBE-side reads and Magento-side mapping tables. All SQL
  centralized here per the architecture rule.
- **Category pipeline**: `CategoryValidator` → `CategoryMapper` →
  `CategoryImporter`, hierarchy-ordered, idempotent, `eccube_category_map`.
- **Item (Grouped Product) pipeline**: `ItemValidator` → `ItemMapper` (via
  `Strategy/ProductTypeStrategyPool` → `GroupedProductStrategy`) →
  `ItemImporter`, `eccube_item_map`.
- **Product (Simple Product) pipeline**: `ProductValidator` →
  `ProductMapper` → `ProductImporter`, `eccube_product_map`. SKU collision
  defense against duplicate `product_code`.
- **Group Relations**: `ProductRelationImporter`, batched per-parent.
- **Images**: `ImageValidator` → `ImageMapper` → `ImageImporter`, role
  assignment (main/gallery), changed-image detection via
  filename+size+mtime hash, `eccube_image_map`.
- **Inventory**: `InventoryMapper` (confirmed reconciliation rule:
  `dtb_product.stock_quantity` always wins over `dtb_product_class.stock`)
  → `InventoryImporter`, batched MSI `SourceItemsSaveInterface` writes.
- **Synchronization**: `Model/Sync/{Category,Item,Product}Sync` extend
  their Importer counterparts, scanning `getModifiedSince()` instead of a
  full table scan. `InventorySync`/`ImageSync` delegate to their Importer
  (already hash-incremental).
- **Cron**: `Cron/FullImportCron`, `Cron/SyncCron`, `etc/crontab.xml`,
  admin enable toggles.
- **CLI**: 17 commands — test-connection, validate (categories/products/
  inventory), import (categories/group-products/simple-products/
  product-relations/images/inventory), sync (same five), status, reindex.
- **Admin UI**: Dashboard, Entity Mapping (4 separate grid pages — one
  controller per grid, the pattern that actually works), Sync History
  grid, Logs viewer. `Ui/DataProvider/*` with explicit `getData()`
  (collection→array conversion, per the last fix round).
- **Logging**: `ImportLogger`/`SyncLogger` channels →
  `var/log/eccube_{import,sync,error}.log`.
- **Tests**: `Test/Unit/` covers validators, mappers (including the
  inventory reconciliation rule), the strategy pool, and DTO hashing.

## 2. Partially implemented

- **Category custom data**: `dtb_category.description`/`description_en`
  are read and mapped (Category → Magento `description` attribute); no
  other category-level custom fields exist in the real schema to migrate
  (confirmed against the actual production dump — see
  `ECCUBE_DATABASE_ANALYSIS.md`).
- **Multi-store**: nothing hardcodes store id 0 for reads, but
  `ItemImporter`/`ProductImporter` assign only the *default* website via
  `StoreManagerInterface::getWebsite()` — fine for a single-website
  install (confirmed to be Cosmotec's actual topology), not yet
  multi-website-aware if that changes.

## 3. Incorrect / risky (fixed already, noted for the record)

- Two admin-UI structural bugs (invalid `<tabs>` wrapper in `system.xml`;
  four UI Component grids stacked on one page instead of one-per-page)
  were found and fixed in prior rounds — see `BUILD_STATUS.md`.
- `STATUS_ENABLED`/`STATUS_DISABLED` constant source was wrong twice
  (interface, then wrong concrete class) before landing on
  `Magento\Catalog\Model\Product\Attribute\Source\Status` — fixed.
- Name/description fields were reading the Japanese columns instead of
  the `_en` columns — fixed in a prior round.

## 4. Missing (confirmed against the real production DB — this is the
   actual scope of V2)

None of the following exist anywhere in the module today:

- **Attributes / specifications** (`dtb_specification`,
  `dtb_specification_group`, `dtb_specification_class`,
  `dtb_item_specification`, `dtb_item_specification_class`,
  `dtb_product_specification_class`) — no reader, repository, DTO,
  validator, mapper, importer, or mapping table.
- **Attribute sets/groups** — no strategy/manager of any kind; every
  product uses Magento's default attribute set.
- **Related products** (`dtb_related_product`) and **coupling/connection
  parts** (`dtb_coupling_product`) — not read anywhere.
- **Tags** (`dtb_tag`, `dtb_product_tag`, `dtb_search_tag`,
  `dtb_customer_favorite_product_tag`, etc.) — not read anywhere. (Low
  priority — see `MIGRATION_GAPS.md`.)
- **SEO/URL rewrites** — moot for the fields the spec lists
  (meta title/description/keyword, url_key): **confirmed absent from the
  actual EC-CUBE schema** (no such columns on `dtb_category` or
  `dtb_product`, no SEO-related plugin in `composer.lock`). Magento URL
  keys will need to be *generated* (already done for categories via
  slugify; not done for products), not migrated from a source that
  doesn't have them.
- **`analyze:*` CLI commands**, `cleanup`, `reset:*` — still absent
  (previously documented as a known gap; unchanged).
- **Performance hardening for 100k+ scale**: current importers do one
  Magento write per record (except inventory, which is batched). The real
  DB has ~27,900 products / ~4,100 items / ~1.14M product-specification
  assignments — the per-record pattern is very likely to be too slow for
  the specification-assignment volume specifically once that's built; see
  `MIGRATION_GAPS.md`.

## 5. What can be reused as-is

Connection layer, Reader framework, Repository pattern, Logger channels,
Sync-extends-Importer pattern, `SyncHistory`/mapping-table conventions,
Strategy Pattern scaffold (`ProductTypeStrategyPool` — directly relevant
since attribute-driven attribute-set selection will need an analogous
pattern), CLI command scaffolding, admin UI patterns (one controller per
grid).

## 6. What should be extended

- `Api/Data/ProductInterface` / `Model/DTO/Product.php`: needs no new
  fields (attribute *values* live in a separate table, not on
  `dtb_product` itself).
- `ProductMapper`/`ItemMapper`: need to gain attribute-value assignment as
  an additional mapping concern, analogous to how category-id resolution
  already works (best-effort, logged, non-blocking if an attribute isn't
  ready yet).
- `di.xml`: needs new repository/reader/mapper/importer/sync preferences
  and CLI command registrations, following the exact existing pattern —
  no new wiring style needed.
- `etc/db_schema.xml`: needs new mapping tables (attribute map, attribute
  option map, attribute-set map, related-product tracking) — same trio
  pattern (Model/ResourceModel/Collection) already used four times.

## 7. What should be refactored

Nothing structural. The existing architecture (Reader → Validator →
Mapper → Importer → mapping table + history, Sync-extends-Importer,
Strategy Pattern for product-type selection) is sound and the attribute
work fits it directly — an **Attribute Strategy** analogous to
`ProductTypeStrategyPool` is the natural way to keep "how does this
specification become a Magento attribute" pluggable, matching the
project's own established pattern rather than introducing a new one.

## 8. Exact recommended next steps

See `MIGRATION_GAPS.md` for the full table and `MIGRATION_ASSUMPTIONS.md`
for the open questions that should be confirmed before large,
hard-to-reverse EAV-creation code is written (auto-creating ~380
attributes against a live Magento instance is not something to get wrong
twice).
