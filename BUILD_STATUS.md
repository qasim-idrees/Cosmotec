# Cosmotec_EccubeMigration — Build Status

Tracks progress against the 10-milestone plan. Each milestone is completed in
full, and verified against the actual EC-CUBE schema/source supplied
(`ec-cube-db.sql`, `cosmotec_source.zip`), before the next one starts.

## Milestone 1 — Module skeleton ✅ DONE

- `registration.php`
- `composer.json`
- `etc/module.xml` (declares sequence on Catalog, CatalogInventory,
  InventoryApi, InventorySalesApi, Store, Eav, UrlRewrite, Indexer,
  MediaStorage)
- `etc/acl.xml` (admin resource tree: top-level menu resource, dashboard,
  entity mapping, sync history, logs, plus a config-section resource under
  Stores > Settings > Configuration)
- `i18n/en_US.csv`
- `LICENSE.txt`
- Full target directory structure created under `Api`, `Console/Command`,
  `Cron`, `Helper`, `Logger`, `Model/*` (Config, Connection, DTO, Import,
  Mapper, Pipeline, Reader, Repository, ResourceModel, Sync, Validator),
  `Observer`, `Plugin`, `Setup/Patch/{Data,Schema}`, `Test/{Unit,Integration}`,
  `etc/adminhtml`, `view/adminhtml/{layout,ui_component}`.

No `di.xml` yet — it is intentionally deferred until Milestone 2, when
there are real interface-to-model preferences to declare. An empty
`di.xml` would just be a placeholder, which this project does not use.

### Notes from inspecting the real EC-CUBE data

This instance is a customized EC-CUBE build, not vanilla 4.0.0:

- `dtb_item` is the parent entity; `dtb_product.item_id` references it.
  This matches the spec's `dtb_item` → Grouped Product /
  `dtb_product` → Simple Product mapping.
- `dtb_product` carries its own `stock_quantity`, `price`,
  `price_expiration_check/date/judgment`, `sale_type_id`,
  `minimum_sales_quantity`, `standard_date`, `stock_limited_only` columns —
  vanilla EC-CUBE 4.0 does not put stock/price directly on `dtb_product`.
- Stock also exists separately via `dtb_product_class.stock` /
  `stock_unlimited` and `dtb_product_stock.stock` (class-variant level).
  Milestone 7 (Inventory) will need to define precedence rules between
  `dtb_product.stock_quantity` and the class/stock tables rather than
  assuming a single source — this will be called out explicitly when we
  get there.
- `app/Customize/Repository/ItemRepository.php` and
  `ProductRepositoryExtend.php` contain custom query logic worth reviewing
  before the Reader layer (Milestone 2) is finalized.

## Milestone 2 — EC-CUBE connection, repositories, DTOs, reader framework ✅ DONE

### Admin configuration
- `etc/adminhtml/system.xml` — Stores > Configuration > Cosmotec > EC-CUBE
  Migration: Enable, Dry Run by Default, Enable Logging, Batch Size, Image
  Folder (General group); Host, Port, Database, Username, Password
  (encrypted via `Magento\Config\Model\Config\Backend\Encrypted`), Charset,
  Timeout (Connection group).

### Configuration layer
- `Api/EccubeConfigProviderInterface.php` — typed contract every other class
  depends on instead of touching ScopeConfig directly.
- `Model/Config/ModuleConfig.php` — implementation; reads default scope
  (fields are default-scope only), decrypts the password via
  `EncryptorInterface`, applies sane defaults (batch size 100, charset
  utf8mb4, port 3306, timeout 5s) when a value is unset.
- `Helper/Data.php` — thin `AbstractHelper` facade for blocks/templates;
  everything else should depend on `EccubeConfigProviderInterface` directly.

### Connection layer
- `Model/Connection/EccubeConnectionInterface.php` /
  `EccubeConnection.php` — dedicated lazy-connecting PDO wrapper
  (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, configurable timeout/
  charset). Never touches Magento's own DB connection.
- `Model/Connection/EccubeConnectionFactory.php` — builds/caches the
  connection from `EccubeConfigProviderInterface`; supports one-off
  parameter overrides without touching di.xml.
- `Model/Connection/EccubeConnectionException.php` — `LocalizedException`
  subtype for connect/query failures.
- **Deliberately no `di.xml` preference for `EccubeConnectionInterface`** —
  `EccubeConnection` takes scalar constructor args the ObjectManager can't
  supply; it's only ever built via the factory's `new`. Documented inline in
  `etc/di.xml` so nobody "fixes" this into a preference later and breaks it.

### DTOs / service contracts
- `Api/Data/{Category,Item,Product,ProductClass,Image,InventoryRecord}Interface.php`
  — column-for-column against the real dumped schema (not vanilla EC-CUBE
  4.0.0 assumptions).
- `Model/DTO/{Category,Item,Product,ProductClass,Image,InventoryRecord}.php`
  — immutable, constructor-promoted, typed-property implementations.

### Repository layer (all SQL lives only here)
- `Api/*RepositoryInterface.php` + `Model/Repository/*Repository.php` for
  Category, Item, Product, ProductClass, Image.
- `Model/Repository/AbstractEccubeRepository.php` — shared row-hydration
  helpers (nullable string/int/bool, `DateTimeImmutable`) and constructs
  the connection once via `EccubeConnectionFactory`.
- LIMIT/OFFSET are cast-to-int and interpolated directly rather than bound
  as PDO params — binding them as strings can break on some MariaDB builds
  once `ATTR_EMULATE_PREPARES` is off. They are always internally-cast
  integers, never raw user input, so this is safe.
- `ItemRepository::getModifiedSince()` joins to `dtb_product` and uses
  `MAX(update_date)` per `item_id`, since `dtb_item` itself has no
  `update_date` column.

### Reader framework
- `Model/Reader/ReaderInterface.php` / `AbstractReader.php` — generator-based
  batch streaming (`read(offset, batchSize): Generator`), resumable from any
  offset, logs progress ("read N / total") to `ImportLogger`.
- `CategoryReader`, `ItemReader`, `ProductReader`, `ImageReader` — thin
  subclasses delegating to their repository's `getBatch()`/`countAll()`.
- `InventoryReader` — pages `dtb_product` and attaches each product's
  `dtb_product_class` rows (if any) into an `InventoryRecord` DTO. Carries
  both possible stock sources without picking a winner; Milestone 7 decides
  precedence.

### Logging
- `Logger/Handler/{Import,Sync,Error}Handler.php` → `var/log/eccube_import.log`,
  `eccube_sync.log`, `eccube_error.log`.
- `Logger/{Import,Sync}Logger.php` — named Monolog channels, each also wired
  to `ErrorHandler` (in `etc/di.xml`) so error-level entries land in both
  their own log and the shared error log.
- Readers depend on the concrete `ImportLogger` type, not the generic
  `Psr\Log\LoggerInterface` — overriding that globally would repoint
  Magento's own default logger, which this module must not do.

### CLI
- `Console/Command/TestConnectionCommand.php` —
  `php bin/magento cosmotec:eccube:test-connection`. Checks the Enable flag
  first, then pings the EC-CUBE connection and reports success/failure.
- Registered in `etc/di.xml` via `Magento\Framework\Console\CommandListInterface`.

### Verification note
No PHP CLI is available in this sandbox to run `php -l` / phpcs, so every
file was additionally checked with an automated brace/parenthesis balance
pass (44/44 files clean) and manually re-reviewed. Full static analysis
(phpcs PSR-12, `php -l`, `bin/magento setup:di:compile`) should still be run
in your actual Magento environment before deploying.

## Milestone 3 — Categories ✅ DONE

### Schema (new, shared infrastructure)
- `etc/db_schema.xml` + `etc/db_schema_whitelist.json` — two new tables:
  - `eccube_category_map` (per-category mapping/status/hash)
  - `eccube_sync_history` — **generic, append-only, used by every future
    entity type**, not category-specific. Introduced now because Category
    is the first entity with a real import pipeline; Milestones 4-9 reuse
    it as-is rather than creating their own history tables.
- `etc/adminhtml/system.xml` — added **Magento Root Category ID** (General
  group). Necessary addition not in the original spec's settings list:
  EC-CUBE top-level categories (`parent_category_id IS NULL`) need an
  explicit Magento parent to attach to (normally the store's root category,
  e.g. id 2 — not Magento's absolute root, id 1). Defaults to 2 if unset.

### Magento-side persistence (Model/ResourceModel/Collection trio)
- `Model/CategoryMap.php` + `Model/ResourceModel/CategoryMap.php` +
  `Model/ResourceModel/CategoryMap/Collection.php`
- `Model/SyncHistory.php` + `Model/ResourceModel/SyncHistory.php` +
  `Model/ResourceModel/SyncHistory/Collection.php`
- `Api/CategoryMapRepositoryInterface.php` /
  `Model/Repository/CategoryMapRepository.php`
- `Api/SyncHistoryRepositoryInterface.php` /
  `Model/Repository/SyncHistoryRepository.php`

### Validator layer
- `Model/Validator/ValidatorInterface.php`, `ValidationResult.php` — generic,
  reused by every future entity validator.
- `Model/Validator/CategoryValidator.php` — checks required `category_name`
  and EC-CUBE-side referential integrity (parent id actually exists in
  `dtb_category`). **Deliberately does not** check "has the parent been
  imported into Magento yet" — that's an import-time ordering concern, not
  a data-validity one; conflating them would make a pre-import
  `validate:categories` run falsely report almost every non-root category
  as invalid. That check lives in the Mapper instead (see below).

### Mapper layer
- `Api/Data/MagentoCategoryInterface.php` / `Model/DTO/MagentoCategory.php`
  — the Magento-target shape, self-computing a `content_hash` (sha256 over
  every mapped field) for Milestone 8's change detection.
- `Model/Mapper/MapperInterface.php` — generic, reused by every future mapper.
- `Model/Mapper/CategoryMapper.php` — resolves the Magento parent id either
  to the configured root category (top-level EC-CUBE categories) or to the
  already-imported Magento id of the EC-CUBE parent; throws
  `UnresolvedParentException` if the parent hasn't been imported yet. Also
  generates a URL key (transliterated/slugified name, falling back to
  `category-{id}` for non-Latin names with no English translation).

### Importer
- `Model/Import/{ImportContext,ImportResult,ImporterInterface}.php` —
  generic, reused by every future importer.
- `Model/Import/CategoryImporter.php` — Reader → Validator → Mapper →
  `Magento\Catalog\Api\CategoryRepositoryInterface` → mapping table + sync
  history. Continues past per-record failures (catches everything,
  records `status=error` + message, moves on) — failed rows are
  automatically retried on the next run since resume is implemented as
  always-on idempotent skip-by-status rather than an offset checkpoint
  (documented in the class docblock). Records duration/memory per row into
  `eccube_sync_history`.
- **Correctness fix applied to `CategoryRepository::getBatch()`**: now
  orders by `hierarchy ASC, id ASC` instead of just `id ASC`, so parent
  categories always stream before their children — required for the
  importer's parent-before-child dependency to hold across batch
  boundaries, not just within one page.

### CLI
- `cosmotec:eccube:validate:categories` — reports valid/invalid counts and
  up to 50 error messages; read-only.
- `cosmotec:eccube:import:categories --dry-run --resume --batch-size=N` —
  dry-run reports intended create/update actions and writes nothing (no
  Magento save, no mapping row, no sync history row).
- Both registered in `etc/di.xml`.

### Verification note
Same as Milestone 2: no PHP CLI in this sandbox, so every file (68 total
now) was checked with the brace/parenthesis balance script (0 mismatches)
plus manual review. Run `bin/magento setup:upgrade`,
`bin/magento setup:di:compile`, and phpcs (PSR-12,
`magento/magento-coding-standard`) in your real environment before
deploying.

## Milestone 4 — Group products (Item → Magento Grouped Product) ✅ DONE

### Schema
- `eccube_item_map` added to `etc/db_schema.xml` / `db_schema_whitelist.json`
  — same trio pattern as `eccube_category_map` (entity/status/hash/
  error/timestamps), plus a `sku` column since the SKU is synthesized
  (see below) rather than copied from source.

### Magento-side persistence
- `Model/ItemMap.php` + `Model/ResourceModel/ItemMap.php` +
  `Model/ResourceModel/ItemMap/Collection.php`
- `Api/ItemMapRepositoryInterface.php` / `Model/Repository/ItemMapRepository.php`
  — mirrors `CategoryMapRepository` exactly.

### Validator
- `Model/Validator/ItemValidator.php` — required `name`, and
  `display_status_id` must be one of the two confirmed values (1=show,
  2=hide) when present.

### Strategy Pattern (the spec's explicit requirement for this milestone)
- `Model/Mapper/Strategy/ProductTypeStrategyInterface.php` — one
  implementation per Magento parent-product type.
- `Model/Mapper/Strategy/GroupedProductStrategy.php` — the confirmed
  mapping today (`type_id = 'grouped'`). SKU is synthesized as
  `ECCUBE-ITEM-{id}` since `dtb_item` has no product-code-like column of
  its own (only `dtb_product` does, and those rows don't exist yet at this
  milestone).
- `Model/Mapper/Strategy/ProductTypeStrategyPool.php` — resolves a
  `type_id` to its strategy; populated entirely from `etc/di.xml`. Adding
  Configurable or Bundle later means writing one new strategy class and
  adding one `<item>` line to the pool's di.xml argument — `ItemMapper`,
  `ItemImporter` and the CLI command need zero changes.

### Mapper
- `Api/Data/MagentoParentProductInterface.php` / `Model/DTO/MagentoParentProduct.php`
  — type-agnostic target shape (sku, name, type_id, enabled, visibility,
  attribute_set_id, category_ids), self-hashing for future sync.
- `Model/Mapper/ItemMapper.php` — resolves the default catalog_product
  attribute set via `Magento\Eav\Model\Config`, resolves the item's
  categories through `ItemRepository::getCategoryIdsByItemId()` +
  `CategoryMapRepository` (best-effort: an unmapped/not-yet-imported
  category is skipped with a log line, never blocks the item), then
  delegates to `ProductTypeStrategyPool::get()`.

### Importer
- `Model/Import/ItemImporter.php` — Reader → Validator → Mapper →
  `Magento\Catalog\Api\ProductRepositoryInterface` (+
  `CategoryLinkManagementInterface` for category assignment) → `ItemMap` +
  `eccube_sync_history`. Same error-continues/idempotent-resume pattern as
  `CategoryImporter`. Sets website id from `StoreManagerInterface`'s
  default website (no new admin setting needed for that).
- **Does not link any child simple products yet.** Per the spec's own
  Import Order (step 4 Group Products, step 5 Simple Products, step 6
  Group Relations — in that order), the children don't exist until
  Milestone 5. The actual grouped-product association will be the final
  step of the Milestone 5 importer, once both sides of the relationship
  exist.

### CLI
- `cosmotec:eccube:validate:products` — validates items now; the spec
  reuses the same command name for products, so Milestone 5 extends this
  same class with `ProductValidator` rather than adding a second command.
- `cosmotec:eccube:import:group-products --dry-run --resume --batch-size=N`

### Verification note
83 PHP files total, 0 brace/paren mismatches. `di.xml`, `db_schema.xml`,
`system.xml`, `acl.xml`, `module.xml` all confirmed well-formed XML;
`db_schema_whitelist.json` confirmed valid JSON. Real `php -l` / phpcs /
`setup:di:compile` still needed in your actual Magento environment.

## Milestone 5 — Simple products (dtb_product → Magento Simple Product) + Group Relations ✅ DONE

### Dependencies
- `etc/module.xml` — added `Magento_GroupedProduct` to the sequence
  (needed for the `associated` product-link type used in Group Relations).

### Schema
- `eccube_product_map` added to `etc/db_schema.xml` / whitelist — same
  trio pattern as the other maps, plus `eccube_item_id` (denormalized from
  `dtb_product.item_id` so `ProductRelationImporter` doesn't need a second
  EC-CUBE round trip) and `relation_linked` (0/1, tracks Group Relations
  progress independently of import status).

### Magento-side persistence
- `Model/ProductMap.php` + ResourceModel + Collection.
- `Api/ProductMapRepositoryInterface.php` / `Model/Repository/ProductMapRepository.php`
  — adds `getUnlinkedByItemId()` (drives relation linking) and `getBySku()`
  (drives duplicate-SKU defense in the Mapper) beyond the usual CRUD.

### Validator
- `Model/Validator/ProductValidator.php` — name required; `product_code`
  may legitimately be empty (some EC-CUBE installs only SKU at the class
  level) but not whitespace-only; price must be numeric and non-negative
  if present; `stock_quantity` non-negative if present; `item_id` (if set)
  must reference a real `dtb_item` row.

### Mapper
- `Api/Data/MagentoSimpleProductInterface.php` / `Model/DTO/MagentoSimpleProduct.php`.
- `Model/Config/DefaultAttributeSetProvider.php` — extracted from
  `ItemMapper` (Milestone 4) into a shared helper now that two mappers need
  the same default-attribute-set lookup; `ItemMapper` was refactored to use
  it too.
- `Model/Mapper/ProductMapper.php` — SKU is `product_code` when present,
  else synthesized `ECCUBE-PRODUCT-{id}`; either way checked against
  `ProductMapRepository::getBySku()` and disambiguated with a `-{id}`
  suffix if a *different* EC-CUBE product already claims it (the spec's
  "Duplicate products" validation concern, handled here rather than in the
  Validator since it requires cross-referencing already-imported state, not
  just the single record being checked). Visibility is
  `NOT_VISIBLE_INDIVIDUALLY` when the product has a parent item (normal for
  grouped children), `VISIBILITY_BOTH` for standalone products. Status
  enabled only when `product_status_id = 1` (2=hide and 3=abolished both
  map to disabled).

### Importer
- `Model/Import/ProductImporter.php` — Reader → Validator → Mapper →
  `Magento\Catalog\Api\ProductRepositoryInterface` → `ProductMap` + sync
  history. Sets baseline legacy stock data (`qty`/`is_in_stock`/
  `manage_stock`) from `dtb_product.stock_quantity` so products are
  immediately salable; **Milestone 7 will reconcile this properly against
  `dtb_product_class` via MSI source items** rather than this being the
  final word on stock.
- `Model/Import/ProductRelationImporter.php` — the spec's Import Order
  step 6, "Group Relations", built as its **own class and CLI command**
  (matching the spec's separate `import:product-relations` entry) rather
  than folded into `ProductImporter`. Batches one Magento product-link save
  per parent item (loads existing links once, appends all pending children,
  saves once) instead of one save per child. Idempotent: checks existing
  linked SKUs before appending, and tracks completion via
  `eccube_product_map.relation_linked` so re-running only touches what's
  still pending.

### CLI
- `ValidateProductsCommand` extended (not duplicated) with `ProductValidator`
  — now validates both items and products in one run, as promised in the
  Milestone 4 notes.
- `cosmotec:eccube:import:simple-products --dry-run --resume --batch-size=N`
- `cosmotec:eccube:import:product-relations --dry-run --resume --batch-size=N`

### Verification note
97 PHP files, 0 brace/paren mismatches. All XML config (`di.xml`,
`db_schema.xml`, `system.xml`, `acl.xml`, `module.xml`) and
`db_schema_whitelist.json` confirmed well-formed. Real `php -l` / phpcs /
`setup:di:compile` still required in your actual Magento environment.

## Milestone 6 — Images ✅ DONE

### Schema
- `eccube_image_map` added to `etc/db_schema.xml` / whitelist. Notably
  includes `gallery_value_id` (the Magento media gallery entry id, needed
  to remove/replace a changed image) and an explicit `is_main` flag rather
  than inferring "first image = main" from row order, which would have
  been ambiguous across resumed/partial runs.

### Magento-side persistence
- `Model/ImageMap.php` + ResourceModel + Collection.
- `Api/ImageMapRepositoryInterface.php` / `Model/Repository/ImageMapRepository.php`
  — adds `hasMainImage()`, which `ImageMapper` uses to decide role
  assignment.

### Validator
- `Model/Validator/ImageValidator.php` — required `file_name`, image
  folder must be configured, and **the file must actually exist and be
  readable on disk** at `{image_folder}/{file_name}` — a real filesystem
  check, not just a data-shape check, since a missing source file is the
  most common real-world failure mode for this kind of migration.

### Mapper
- `Api/Data/MagentoImageInterface.php` / `Model/DTO/MagentoImage.php`.
- `Model/Mapper/ImageMapper.php` — resolves the absolute path, determines
  main-vs-gallery role via `ImageMapRepository::hasMainImage()`, and
  computes a **fast** content hash from filename+filesize+mtime rather than
  hashing full binary content on every run (documented trade-off: a file
  replaced with identical size and mtime but different bytes wouldn't be
  detected as changed — acceptable for the CMS-managed-upload case this
  targets, called out explicitly rather than silently assumed).

### Importer
- `Model/Import/ImageImporter.php` — **iterates via `ProductReader` +
  `ImageRepository::getByProductId()`, not the global `ImageReader` built
  in Milestone 2.** This was a deliberate choice: role assignment
  (main/gallery) needs to reason about "all of this product's images
  together," which the per-product grouping gives for free and the global
  pager doesn't. `ImageReader` itself is left in place unused by this
  importer — it's still valid Reader-layer infrastructure per the spec and
  may serve future tooling (e.g. a global image audit command).
  - Images belonging to a not-yet-imported product are counted as
    **skipped**, not errored — a later run naturally picks them up.
  - Changed-image detection: if the computed hash matches the stored one
    and a gallery entry already exists, the image is skipped entirely (no
    re-upload). If the hash changed, the stale gallery entry is removed via
    `ProductAttributeMediaGalleryManagementInterface::remove()` and a fresh
    one created — Magento's media gallery API has no in-place binary
    replace.
  - Roles: the image chosen as "main" gets `['image','small_image','thumbnail']`;
    all others are gallery-only (`[]`).
  - MIME type detection via `finfo`, with an extension-based fallback if
    `finfo` is unavailable or inconclusive.

### CLI
- `cosmotec:eccube:import:images --dry-run --resume --batch-size=N` — also
  hard-fails early with a clear message if the Image Folder Path isn't
  configured, rather than letting every single image error out
  individually.

### Verification note
108 PHP files, 0 brace/paren mismatches. All XML config and the whitelist
JSON confirmed well-formed. Real `php -l` / phpcs / `setup:di:compile`
still required in your actual Magento environment — this milestone in
particular (file I/O, base64 encoding, Magento's media gallery API) is
worth a real smoke test against actual EC-CUBE image files before trusting
it on a full catalog.

## Milestone 7 — Inventory ✅ DONE

### Reconciliation rule (confirmed by user)
`dtb_product.stock_quantity` is **always** the source of truth for
quantity, even when `dtb_product_class` rows exist for the same product.
`InventoryMapper` enforces this directly rather than the importer having to
know the rule.

### Dependencies
- `etc/module.xml` — added `Magento_InventoryCatalogApi` (for
  `DefaultSourceProviderInterface::getCode()`, the standard way to get the
  default MSI source code rather than hardcoding the literal `'default'`).

### Schema
- **No new table.** Inventory isn't a new mapped entity — it's an
  attribute of a product that's already tracked in `eccube_product_map`.
  Added `inventory_content_hash` and `inventory_synced_at` columns to that
  existing table instead of creating `eccube_inventory_map`.

### Validator
- `Model/Validator/InventoryValidator.php` — non-negative
  `stock_quantity`, and any `dtb_product_class.stock` values present must
  be numeric (checked for data-quality visibility even though this
  milestone's mapper doesn't use them for quantity).

### Mapper
- `Api/Data/MagentoInventoryInterface.php` / `Model/DTO/MagentoInventory.php`.
- `Model/Mapper/InventoryMapper.php` — resolves the SKU via
  `ProductMapRepository` (throws `UnresolvedParentException` if the product
  hasn't been imported yet, same pattern as `CategoryMapper`). `qty` =
  `max(0, stock_quantity ?? 0)`. `stockManaged` = `dtb_product.stock_limited_only`;
  when false, the product is always in-stock regardless of qty (EC-CUBE's
  "unlimited stock" case); when true, in-stock is `qty > 0`.

### Importer
- `Model/Import/InventoryImporter.php` — Reader (the `InventoryReader`
  built in Milestone 2, now finally consumed) → Validator → Mapper →
  `Magento\InventoryApi\Api\SourceItemsSaveInterface` against the default
  MSI source. **Replaces** the legacy `setStockData()` baseline
  `ProductImporter` set in Milestone 5 with a proper MSI write — that
  baseline was always documented as provisional, not final.
  - Products not yet imported are skipped, not errored (same pattern as
    every other cross-entity dependency in this codebase).
  - Change detection via `inventory_content_hash` on `eccube_product_map`:
    unchanged records are skipped entirely, no redundant MSI writes.

### CLI
- `cosmotec:eccube:validate:inventory`
- `cosmotec:eccube:import:inventory --dry-run --resume --batch-size=N`

### Verification note
115 PHP files, 0 brace/paren mismatches. All XML config and whitelist JSON
confirmed well-formed. Real `php -l` / phpcs / `setup:di:compile` still
required in your actual Magento environment — MSI writes in particular
should be smoke-tested against a real source/stock configuration before
trusting this on a full catalog.

## Milestone 8 — Synchronization ✅ DONE

### Design: Sync extends Importer, doesn't duplicate it
For Category/Item/Product, the *only* real difference between import and
sync is which records get scanned — import does a full table scan (needed
so parent-before-child ordering works when nothing exists in Magento yet);
sync only needs records modified since the last successful run. Every
other concern (validate, map, create-or-update, error handling, mapping
table writes, sync history) is identical. So:

- `CategoryImporter`, `ItemImporter`, `ProductImporter` had a few
  constructor-promoted properties and their `importOne()` method bumped
  from `private` to `protected` (nothing else changed) so a subclass could
  reuse them.
- `Model/Sync/CategorySync.php` / `ItemSync.php` / `ProductSync.php` each
  **extend** their Importer counterpart. Their constructors take
  everything the parent needs (forwarded via `parent::__construct()`) plus
  the raw EC-CUBE repository, and override `import()` to loop over
  `getModifiedSince()` pages — calling the *inherited* `importOne()` for
  each record. Zero duplicated business logic.
- The "since" watermark is `MAX(last_synced_at)` from the relevant mapping
  table (`Api\{Category,Item,Product}MapRepositoryInterface::getMaxLastSyncedAt()`,
  added this milestone) rather than a new state table — every successful
  import/sync already stamps `last_synced_at`, so this was free.

### Inventory and Images: honestly delegating, not faking a distinction
`dtb_product_class` has no `update_date`, and `dtb_product_image` has no
`update_date` either — there's no reliable "what changed" query to build
for either one. Both `InventoryImporter` and `ImageImporter` already skip
every unchanged record cheaply via content-hash comparison before doing
any write, which means a full scan through either one **is** already an
incremental sync in every way that matters. Rather than inventing a fake
distinction, `Model/Sync/InventorySync.php` and `Model/Sync/ImageSync.php`
are thin wrappers that just delegate to their Importer — documented
explicitly in each class's docblock rather than left unexplained.

### CLI
All five spec-listed sync commands, each following the same
`--dry-run`/`--resume`/`--batch-size` shape as the import commands (though
`--resume` is a no-op here by design — sync is inherently incremental,
there's no full-scan state to resume):
- `cosmotec:eccube:sync:categories`
- `cosmotec:eccube:sync:group-products`
- `cosmotec:eccube:sync:simple-products`
- `cosmotec:eccube:sync:inventory`
- `cosmotec:eccube:sync:images`

### Verification note
125 PHP files, 0 brace/paren mismatches. All XML config and whitelist JSON
confirmed well-formed. Subclass constructor parameter order was manually
cross-checked against each parent Importer's actual constructor (not
assumed) before writing the Sync classes. Real `php -l` / phpcs /
`setup:di:compile` still required in your actual Magento environment.

## Milestone 9 — Cron ✅ DONE

### Design decision: literal schedules + admin enable toggles, not dynamic cron_expr
Magento does support fully admin-configurable cron expressions via a
`<config_path>`-redirected system.xml field, but that technique is fiddly
to get exactly right without a live Magento instance to verify the config
path resolution against — and this sandbox has none. Rather than ship
something plausible-but-unverified, `etc/crontab.xml` has fixed default
schedules (full import: daily at 02:00; sync: hourly), and each Cron class
checks a straightforward admin **enable/disable** toggle at runtime. This
is fully correct and is itself a common real-world pattern; changing the
actual times requires a code change to `crontab.xml`, which is called out
explicitly here rather than silently glossed over.

### Config
- New **Scheduled Jobs** group in `etc/adminhtml/system.xml`: *Enable
  Scheduled Full Import*, *Enable Scheduled Sync*.
- `EccubeConfigProviderInterface` / `ModuleConfig` extended with
  `isScheduledImportEnabled()` / `isScheduledSyncEnabled()`.

### Cron jobs
- `etc/crontab.xml` — `cosmotec_eccube_full_import` (daily 02:00) and
  `cosmotec_eccube_sync` (hourly), both in the `default` cron group.
- `Cron/FullImportCron.php` — runs `CategoryImporter` → `ItemImporter` →
  `ProductImporter` → `ProductRelationImporter` → `ImageImporter` →
  `InventoryImporter` in the spec's Import Order, reusing the **exact same
  services** the CLI commands use (not reimplemented). Every importer is
  already idempotent, so a full pipeline pass on every scheduled run is
  safe and simply picks up whatever's new. Each stage is wrapped so an
  infrastructure-level failure (e.g. EC-CUBE DB unreachable) in one stage
  logs and lets the remaining stages still run rather than aborting the
  whole job. Images stage is skipped with a log line (not an error) if the
  Image Folder Path isn't configured.
- `Cron/SyncCron.php` — same pattern, running the five Milestone 8 Sync
  services. Logs to `SyncLogger` (the sync channel) rather than
  `ImportLogger`, matching the distinction already established back in
  Milestone 2.
- Both gate on `isEnabled()` (module-wide) and their own scheduled-job
  toggle before doing anything, and both always run with `dryRun=false,
  resume=true` — dry-run has no place in an unattended scheduled job.

### Verification note
127 PHP files, 0 brace/paren mismatches. All XML config (now including
`crontab.xml`) and whitelist JSON confirmed well-formed. Real
`php -l` / phpcs / `setup:di:compile`, and specifically a real cron:run
smoke test, still required in your actual Magento environment — cron
timing/locking behavior is one of the harder things to verify without a
live instance.

## Milestone 10 — Optimization, tests, documentation ✅ DONE

### Optimization
`Model/Import/InventoryImporter.php` was refactored to batch its MSI
writes: instead of one `SourceItemsSaveInterface::execute()` call per
product (one DB round-trip per record), changed records are buffered and
flushed in chunks of `$context->getBatchSize()`, so a whole batch's worth
of source items go in a single `execute()` call. Trade-off documented in
the class docblock rather than hidden: if a flush call itself throws, every
record in that flush is marked as an error (Magento's
`SourceItemsSaveInterface` doesn't report which specific item in a batch
failed) — a small loss of per-record failure granularity in that rare case,
traded for a large reduction in round-trips on every normal run.

The other flagged candidates (`CategoryImporter`/`ItemImporter`/
`ProductImporter`/`ImageImporter` doing one Magento write per record) were
deliberately **not** batched: Magento's `CategoryRepositoryInterface`/
`ProductRepositoryInterface`/media gallery API don't offer a true bulk-save
primitive the way `SourceItemsSaveInterface` does for inventory, so
"batching" them would mean either wrapping saves in a DB transaction
(doesn't reduce API-level overhead, Magento's save() already does
significant work beyond a single INSERT) or reimplementing large chunks of
Magento's own product-save pipeline directly — not a reasonable trade for
a spec that didn't ask for it. Documented here rather than left unexplained.

### Tests
`Test/Unit/` — real PHPUnit test classes (not stubs) covering the
highest-value, most business-logic-heavy pieces:

- `Model/Validator/ValidationResultTest.php`
- `Model/Import/ImportResultTest.php`
- `Model/Validator/CategoryValidatorTest.php` — includes an explicit
  regression test that the validator does NOT reject an unimported (but
  source-valid) parent, guarding the Milestone 3 bug fix.
- `Model/Validator/ProductValidatorTest.php`
- `Model/Mapper/InventoryMapperTest.php` — the most important test in the
  suite: proves `dtb_product.stock_quantity` wins over
  `dtb_product_class.stock` even when class rows are present, directly
  verifying the confirmed reconciliation rule.
- `Model/Mapper/Strategy/ProductTypeStrategyPoolTest.php` — proves the
  Strategy Pattern actually works (pool resolves by type_id, unregistered
  type throws).
- `Model/DTO/MagentoCategoryTest.php` — proves the content hash changes
  when mapped fields change (what Sync's change-detection depends on) and
  stays stable when they don't.
- `Model/Mapper/CategoryMapperTest.php` — root-category default,
  already-imported-parent resolution, unresolved-parent exception, blank
  name fallback.

**Honest caveat**: this sandbox has no PHP CLI, Magento framework, or
network access, so none of these tests have actually been executed — they
were written correctly against the real class signatures (verified by
reading each class's actual code before writing its test, not assumed) and
using the correct Magento AbstractModel mocking technique
(`addMethods()` for magic `__call`-based getters), but running them for
real (`vendor/bin/phpunit app/code/Cosmotec/EccubeMigration/Test/Unit`) in
your actual environment is still required before trusting them.
`Test/Integration/` remains empty — integration tests need a live
Magento + MySQL environment this build process never had.

### Documentation
`README.md` — installation, full configuration reference, CLI usage (with
the recommended first-run order and command reference table), cron,
architecture (mirroring the actual Reader→Validator→Mapper→Importer→Sync
pipeline as built, not the abstract pipeline from the spec), and
troubleshooting. Includes an explicit **Known Limitations** section
listing every spec-requested CLI command that was *not* built (`analyze:*`,
`cleanup`, `reset:*`) rather than letting their absence go undocumented —
these are diagnostic/administrative utilities layered on top of an already-
complete core pipeline (test-connection, validate, import, sync, status,
reindex), not core functionality.

### Two more CLI commands added this milestone
While closing out documentation, added the two cheapest-and-highest-value
commands from the original spec's list that hadn't been built yet, since
the infrastructure for both already existed:
- `cosmotec:eccube:status` — table of status counts across all four
  mapping tables, using the `countByStatus()` methods every repository
  already had.
- `cosmotec:eccube:reindex` — Import Order step 11; reindexes the
  catalog/inventory indexers this module's writes affect.

### Final verification
137 PHP files total (up from 127 at the end of Milestone 9), 0
brace/parenthesis mismatches. All XML config (`di.xml`, `db_schema.xml`,
`system.xml`, `acl.xml`, `module.xml`, `crontab.xml`) and
`db_schema_whitelist.json` confirmed well-formed. 17 CLI commands
registered and confirmed in `etc/di.xml`. As throughout this build: no
PHP CLI was available in this sandbox to run `php -l`, phpcs, or
`bin/magento setup:di:compile` — these, along with an actual
`cron:run` and a real migration dry-run against a staging Magento
instance, are the recommended next steps before using this in production.

---

## Post-build addition — Admin UI for the Milestone 1 ACL resources

The four ACL resources declared back in Milestone 1
(`Cosmotec_EccubeMigration::dashboard`, `::mapping`, `::sync_history`,
`::logs`) had no actual admin pages behind them until this addition — they
were resource *declarations* only. This closes that gap with real,
functional pages, reusing existing backend infrastructure rather than
adding new business logic:

- **Routing**: `etc/adminhtml/routes.xml` (frontName `cosmotec_eccube`),
  `etc/adminhtml/menu.xml` (top-level "EC-CUBE Migration" with the four
  children, matching the ACL tree exactly).
- **Dashboard** (`Controller/Adminhtml/Dashboard/Index.php`,
  `Block/Adminhtml/Dashboard.php`, `view/adminhtml/templates/dashboard.phtml`):
  status-count table per entity type (Categories/Items/Products/Images ×
  pending/imported/updated/skipped/error, via the `countByStatus()` methods
  every map repository already had) plus a recent-activity feed from
  `eccube_sync_history` (added `SyncHistoryRepositoryInterface::getRecent()`
  for this — the one genuinely new read method needed).
- **Entity Mapping** (`Controller/Adminhtml/Mapping/Index.php` +
  `view/adminhtml/layout/cosmotec_eccube_mapping_index.xml`): four full
  UI Component grids stacked on one page (Categories, Items, Products,
  Images), each backed by a `di.xml` virtualType
  (`Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider`
  wired to the existing `*Map\Collection` classes from Milestones 3-6 — no
  new PHP data-access code needed, just XML wiring). Deliberately **not**
  built as a single tabbed page: Magento's tabs UI Component pattern is
  fiddlier to get exactly right without a live instance to verify against,
  and four stacked full-width grids are simpler, more robust, and still
  satisfy "Entity Mapping" as one menu item.
- **Synchronization History** (`Controller/Adminhtml/Synchistory/Index.php`
  + one UI Component grid): filterable by entity type, operation, status,
  run ID, with duration/memory columns from the sync history schema.
- **Logs** (`Controller/Adminhtml/Logs/Index.php`,
  `Block/Adminhtml/Logs.php`, `view/adminhtml/templates/logs.phtml`): tails
  the last ~300 lines of each of the three log files
  (`eccube_import.log`, `eccube_sync.log`, `eccube_error.log`), reading
  only the last ~512KB of each file rather than loading potentially large
  log files fully into memory.
- **New option-source classes** (`Ui/Component/`): `MapStatusOptions`,
  `EntityTypeOptions`, `OperationOptions`, `HistoryStatusOptions` — filter
  dropdowns for the grids above.
- **Deliberately not built**: no web-triggered "Run Import" button. All
  actual import/sync execution remains CLI/cron-only, matching the
  module's original design — triggering a potentially long-running,
  memory-intensive migration process from an HTTP request without a queue
  system would be a real operational risk, not a reasonable scope addition
  for an admin convenience page.

### Verification
147 PHP files (up from 137), 17 XML files, all balance-checked / parsed
clean. Every class referenced by `class="..."` attributes in the new
UI Component XML (the four option-source classes) was cross-checked
against the actual files on disk to exist with matching namespaces —
this class of error (a typo'd FQN in a UI Component options="" attribute)
is easy to make and doesn't show up as an XML validation failure, only as
a runtime `ObjectManager` error, so it was checked explicitly rather than
assumed correct from writing the XML carefully. As with the rest of this
build: no live Magento instance was available to actually load these pages
and confirm they render — `setup:upgrade`, `setup:di:compile` (if in
production mode), and loading each of the four pages in a real admin
should be the next step.

All 10 milestones are done. The module implements the full spec'd
pipeline — Categories → Group Products → Simple Products → Group
Relations → Images → Inventory → Synchronization → Cron — plus
optimization, unit tests, and documentation. See `README.md` for usage and
the "Known Limitations" section for exactly what's out of scope, and see
this file's Milestone 1-9 sections above for the complete decision log,
including every EC-CUBE-schema-specific finding that shaped the build.

---

## Post-delivery fixes (real user-reported bugs, both now resolved)

1. **Invalid `<tabs>` wrapper in `system.xml`** — Magento's actual
   `system_file.xsd` schema puts `<tab>` directly as a child of
   `<system>`, sibling to `<section>`; there is no `<tabs>` wrapper
   element. The original file wrapped it (`<system><tabs><tab>...`),
   which is invalid structure and was the genuine root cause of an
   "Undefined array key 'id'" cascade when Magento walked the merged
   config tree on Stores > Configuration's landing page. Fixed by
   removing the wrapper. (An earlier diagnosis attempt misidentified a
   transcript-rendering glitch as the bug and cleared the file
   incorrectly — the user's own fix, based on their working deployment,
   was correct and is what's reflected here.)
2. **"Not registered handle X_listing" on the four Entity Mapping grids**
   — stacking four separate `<uiComponent>` listings on one shared page
   layout doesn't reliably give each one its own resolvable AJAX handle
   for sort/filter/paging. Fixed by giving each grid its own dedicated
   controller action and page (`Mapping/Index` → Categories,
   `Mapping/Items`, `Mapping/Products`, `Mapping/Images`), matching the
   pattern Synchronization History already used successfully (one grid,
   one controller, one naturally-registered handle — the standard,
   unambiguous Magento pattern). A small `Nav` block/template links the
   four pages together so "Entity Mapping" still reads as one coherent
   section. The original per-listing-name backup handle files from the
   first fix attempt were left in place as harmless extra safety nets.
3. **Grids not rendering data reliably** — the five `di.xml` virtualType
   data providers (generic `Magento\Framework\View\Element\UiComponent\
   DataProvider\DataProvider` bound to a collection) did not reliably
   serialize our `AbstractModel`-based collection items into the grid.
   Replaced with five real classes in `Ui/DataProvider/` (`CategoryMapDataProvider`,
   `ItemMapDataProvider`, `ProductMapDataProvider`, `ImageMapDataProvider`,
   `SyncHistoryDataProvider`), each extending
   `Magento\Ui\DataProvider\AbstractDataProvider` — the base class Magento
   core itself uses for custom grids — with an explicit `getData()`
   override returning `['totalRecords' => $this->collection->getSize(),
   'items' => $this->collection->getItems()]`. The five virtualTypes in
   `di.xml` were commented out (not deleted) rather than removed, so the
   two approaches remain easy to compare. No changes were needed to the
   `view/adminhtml/ui_component/*.xml` files themselves — the class FQNs
   stayed identical, only what those FQNs resolve to changed (virtualType
   → real class).
4. **`getData()` refined and `updateUrl` added.** All five
   `Ui/DataProvider/*.php::getData()` methods now explicitly `load()` the
   collection if not already loaded, and convert each collection item to
   a plain associative array via `$item->getData()` before returning
   `items` — a flat array of arrays rather than an array of model
   objects, which is what the grid's JS layer expects. All five
   `view/adminhtml/ui_component/*listing.xml` files now declare
   `<dataSource><settings><updateUrl path="mui/index/render"/></settings>...`,
   making the AJAX endpoint used for sort/filter/paging explicit rather
   than relying on the framework's default resolution.
5. **Controller `Page`/`PageFactory` namespace switched** from
   `Magento\Backend\Model\View\Result\{Page,PageFactory}` to
   `Magento\Framework\View\Result\{Page,PageFactory}` across all seven
   admin controllers (`Dashboard/Index`, `Mapping/Index`, `Mapping/Items`,
   `Mapping/Products`, `Mapping/Images`, `Synchistory/Index`,
   `Logs/Index`). Only the `use` statements changed — method bodies,
   return type hints (`: Page`), and constructor signatures were
   untouched, since both namespaces expose classes usable the same way
   here.
6. **`STATUS_ENABLED`/`STATUS_DISABLED` undefined-constant error.** Both
   `ItemImporter` and `ProductImporter` referenced these constants via
   `Magento\Catalog\Api\Data\ProductInterface` — but that interface
   doesn't declare them; only the concrete `Magento\Catalog\Model\Product`
   class does. Fixed by importing `Magento\Catalog\Model\Product` (aliased
   `MagentoProduct`) in place of the interface alias in both files, and
   renaming the 3 affected usages per file (`newProduct(): MagentoProduct`
   return type, and the two `STATUS_ENABLED`/`STATUS_DISABLED` references)
   accordingly.
7. **Import/sync English data, not Japanese, for names and descriptions.**
   EC-CUBE's schema carries both a Japanese field and an `_en` English
   counterpart for category/item/product names (and category
   descriptions); the Mappers were reading the Japanese ones. Switched:
   - `CategoryMapper`: `getCategoryName()` → `getCategoryNameEn()`,
     `getDescription()` → `getDescriptionEn()`.
   - `GroupedProductStrategy` (Item → Grouped Product name):
     `getName()` → `getNameEn()`.
   - `ProductMapper` (Simple Product name): `getName()` → `getNameEn()`.
   - `CategoryValidator`/`ItemValidator`/`ProductValidator`: the
     required-name check now validates the `_en` field too, since that's
     what's actually imported — validating the Japanese field's presence
     while importing the English one would let records with a blank
     English name silently fall through to a synthesized fallback name
     without ever being flagged.
   - Corresponding unit test mocks (`CategoryMapperTest`,
     `CategoryValidatorTest`, `ProductValidatorTest`) updated to mock the
     `_en` methods so they still reflect what the code actually checks.

   **Not changed, and why**: `dtb_product` has no English equivalent for
   `description_list`/`description_detail`/`search_word`/`free_area` in
   the confirmed schema (only `name`/`short_name` have `_en` variants for
   products) — and in any case `ProductMapper` never mapped any
   description-type field to the Magento product to begin with (only
   name, status, visibility, price, and stock), so there is no Japanese
   product description being imported today for this to affect.
   `getShortName()`/`getShortNameEn()` are exposed on every source
   interface but not currently read anywhere in the Mapper layer — nothing
   to switch there either.
8. **`STATUS_ENABLED`/`STATUS_DISABLED` source class switched.** Both
   `ItemImporter` and `ProductImporter` now reference these two constants
   via `Magento\Catalog\Model\Product\Attribute\Source\Status` (aliased
   `ProductStatus`) instead of `Magento\Catalog\Model\Product`. The
   `Magento\Catalog\Model\Product` import (aliased `MagentoProduct`) is
   still needed and kept as-is in both files — it's the return type for
   each file's `newProduct()` method, a separate concern from the status
   constants. Confirmed via a module-wide grep that these were the only
   two places `STATUS_ENABLED`/`STATUS_DISABLED` appeared anywhere in the
   codebase.


---

## Media writer + Product References (Round 18)

**Implemented**
- `MediaImporter` — relation-aware writer for all 7 relations. Gallery
  attach for product/item; category image attach; dimension/CAD/catalog
  stored in Magento media and tracked via `eccube_media_map` (no binary
  in EAV). Files copied once per physical source file.
- `MediaSync` — reuses MediaImporter for CREATE/UPDATE/SKIP, adds
  obsolete detection; never removes a physical file still referenced by
  another active relation.
- `ProductReferenceImporter` — true 1:N, every source reference mapped
  independently, obsolete handling per product.
- Map layer: `MediaMap` / `ProductReferenceMap` model+resource+collection
  +repository, canonical identity `(relation_type, owner_id, upload_file_id)`.
- `ProductReferenceRepository` (EC-CUBE read, ordered by source id).
- Commands: `import:images` (`--type/--dry-run/--batch-size/--limit/--source-id`),
  `sync:images` (now MediaSync), `import:product-references`.
- Hashing: SHA-256 content hash for files <=1MB, metadata hash above.
- composer.json now allows PHP 8.4.

**Legacy (orphaned, marked @deprecated)**
`ImageImporter`, `ImageSync`, `ImageRepository`, `ImageReader`,
`ImageMapper`, `ImageValidator`, `eccube_image_map`. No registered
command reaches them; verified by dependency grep.

**Not yet done**
- CAD unavailable attribute import (§7/§9).
- Real-data tests against a live Magento (not possible in this env).
- Attributes/options/sets and product values (next phase).

---

## Round 20 — media storage correctness fix

### CRITICAL BUG FOUND AND FIXED (item 1)

The previous MediaImporter wrote image files to
`pub/media/cosmotec/eccube/{relation}/{file}` and then created gallery
entries via `$entry->setFile($thatPath)`.

**This does not work.** Magento resolves media-gallery `file` values
relative to `pub/media/catalog/product/`, so it would have looked for
`pub/media/catalog/product/cosmotec/eccube/product/x.jpg` — a path that
never exists. Consequences that would have followed: broken admin
gallery, non-functional `image`/`small_image`/`thumbnail` roles, dead
frontend URLs, and a resize cache that could never be generated.

**Fix**: images now go through
`ProductAttributeMediaGalleryManagementInterface::create()` with a
base64 `ImageContentInterface` payload. Magento copies the binary into
`pub/media/catalog/product/<x>/<y>/` itself and assigns the canonical
path, which is then read back and stored in `eccube_media_map`. This is
the same mechanism the Magento admin and REST API use, so admin gallery,
roles, frontend URLs, resize cache, `cache:flush` and `indexer:reindex`
all behave natively.

Idempotency: before creating an entry the importer lists the product's
existing gallery and returns early on a filename/label match, so
re-running never duplicates an entry.

**Same bug class fixed for category images**: Magento resolves the
category `image` attribute relative to `pub/media/catalog/category/`, so
category images are now written there and the attribute stores the bare
filename.

**Documents are unaffected and intentionally different**: CAD 2D/3D ZIPs
and catalog files are not catalog images, so they remain in the
module-owned `cosmotec/eccube/{relation}/` media directory and are
resolved only through `eccube_media_map`. They are not unzipped, not
placed in EAV, and not rejected for failing image validation.

### Item 3 — ProductReference ordering verified

Re-checked against the recorded production schema: `dtb_product_reference`
has columns `id, product_id, creator_id, name, link, create_date,
update_date, discriminator_type` — **no ordering column exists**. Source
id order is therefore a documented deterministic fallback, now stated
explicitly in both `ProductReferenceRepository` and
`ProductReferenceImporter`. No two-reference UI limit is applied at the
data layer.

### Testing performed vs not performed

Performed: PHP brace/strict-types validation (208 files, 0 issues), XML
validation, JSON schema/whitelist validation, dependency-graph
verification.

**Not performed — no Magento runtime available in this environment**:
`php -l`, `bin/magento setup:upgrade`, `setup:di:compile`, unit tests,
integration tests, and the 13-point real-data gallery verification from
item 1. Those must be run in the real environment; the fix above is a
correctness fix based on how Magento's catalog media subsystem resolves
paths, not a verified-at-runtime claim.

---

## Round 22 — primary image selection fix + cad_unavailable

### Item 2: CRITICAL bug fixed

Previous logic:

    $isFirst = $file->getSortNo() === 0
        && $this->mediaGalleryManagement->getList($sku) === [];

Two defects: (a) it depended on the Magento gallery being empty, so a
dimension/CAD/item file imported first permanently prevented the real
product image from ever receiving image/small_image/thumbnail; (b) it
assumed sort_no = 0 identifies the first image without verifying source
ordering.

Fixed with `MediaRepository::getPrimaryUploadFileId(relation, ownerId)`:

    SELECT uf.id FROM {join_table} r
    INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
    WHERE r.{owner_column} = :owner_id
    ORDER BY uf.sort_no ASC, uf.id ASC LIMIT 1

Scoped to ONE relation, so dimension/cad2d/cad3d/item can never influence
which product_upload_file row wins. Independent of import order and of
current Magento gallery state, so re-runs are deterministic. Result is
cached per (relation, owner). `sort_no ASC, id ASC` uses real source
ordering with a deterministic documented tie-break.

Also fixed: a missing `MediaRepositoryInterface` injection that would
have been a runtime DI failure.

### Item 1: dimension images

Kept in Magento-native media storage (so Magento owns the file, resizing
and cleanup) but the gallery entry is now created with
`setDisabled(true)`, so dimension drawings cannot appear in the storefront
gallery carousel beside real product photos. They remain retrievable via
`eccube_media_map` (relation_type = dimension, role = dimension_drawing).
Roles image/small_image/thumbnail are structurally impossible for them.

Regression test added: `Test/Unit/Model/Media/PrimaryImageSelectionTest.php`.

### cad_unavailable implemented

`dtb_product.cad_unavailable_check` now flows source DTO -> ProductMapper
-> MagentoSimpleProduct DTO (and its content hash, so changes resync) ->
ProductImporter, written via `setCustomAttribute('cad_unavailable', ...)`.

### Testing status

Performed: brace/strict-types validation (209 files, 0 issues), XML and
JSON validation.

NOT performed - no Magento runtime in this environment: php -l,
setup:di:compile, unit/integration tests, and the real-data gallery
verification requested in items 1 and 2. Those require the live
environment.

---

## Round 23 — real-data gallery verification against the live environment (media, CONFIRMED PASSING)

A live Magento 2.4.8-p3 + real EC-CUBE DB environment became available this
round. This is the first round with an actual runtime, so every finding
below is from direct execution, not static review.

### The documented "last known blocker" is stale - already fixed

`Call to undefined method MediaImporter::isPrimaryImage()` (the blocker
this file previously tracked) does **not** reproduce. The method exists
and works (`MediaImporter.php:539`, per Round 22). Confirmed two ways:
static review, and `var/log/eccube_import.log`, which shows the exact
undefined-method error occurring three times on 2026-08-20, then
disappearing for good once the Round 22 fix landed.

### What real execution against upload_file_id=1124 (product 2382, SKU
10319 - the exact target this file names) surfaced instead

An execute run had already happened (outside this session) after the
Round 22 fix and left product 2382 in a polluted state: a store-view-scoped
(`store_id=1`, the real "Default Store View") stale override on
`image`/`small_image`/`thumbnail`, five duplicate physical copies of the
same source file (Magento's filename-collision suffixing, `_1`.. `_4`),
and two extra disabled gallery entries with no label - traced to
`DiagnoseGalleryCommand`'s own escalating-stage smoke tests against the
same live product, not to `MediaImporter`. Also observed: two consecutive
execute re-runs logged `updated=1` instead of the expected `skipped=1`.

Initial hypothesis (based on reading `Magento\Catalog\Model\ProductRepository::save()`,
which falls back to `StoreManager::getStore()->getId()` whenever a saved
product's `store_id` isn't explicitly set, and a standalone reproduction
showing that fallback resolves to store_id=1 in this CLI context) was that
`MediaImporter::attachGalleryImageViaApi()`/`resolveStoredFile()` never
setting an explicit store id was a **live code defect** that would corrupt
`image`/`small_image`/`thumbnail` at store-view scope across the whole
catalog. Per explicit instruction, no code was changed on this theory alone
- it was required to be proven or disproven by an actual clean-slate
execute run first.

### Clean-slate verification - result: PASSES, no code defect found

Product 2382's test-polluted state (Magento gallery/attribute rows and
`eccube_media_map` row scoped exactly to `relation_type=product,
eccube_owner_id=1, eccube_upload_file_id=1124`, and the 5 duplicate
physical files + their resize-cache derivatives - nothing else touched)
was reset to a genuine baseline. `cosmotec:eccube:import:images
--type=product --upload-file-id=1124` was then run: dry-run (confirmed no
`[EXECUTE]`), then `--execute` three times back-to-back with nothing else
touching state in between.

Result: **`imported=1` -> `skipped=1` -> `skipped=1`**, exactly as required.
A one-line diagnostic (`existing_hash`/`computed_hash`/`existing_status` ->
`SKIP`/`PROCEED`, purely additive, added this round to `importOne()`)
confirmed the skip decision was correct on both re-runs: hash matched,
status was `imported`. The earlier `updated=1` results were **not**
reproduced under controlled conditions - most likely caused by manual
state changes during the live debugging session that first surfaced the
`isPrimaryImage()` bug, not a defect in the skip-check logic itself.

The store-scope theory **also did not reproduce**: after the clean
3-run sequence, `image`/`small_image`/`thumbnail` have **only** a
store_id=0 row; store_id=1 correctly falls back to it (verified via
`ProductRepositoryInterface::getById($id, false, $storeId, true)` at both
scopes, not just raw SQL). Exactly one gallery entry exists, with the
correct `[image,small_image,thumbnail]` types and no duplicate physical
files. Since the standalone reproduction from two rounds ago doesn't hold
up against the actual integration path Magento exercises when saving an
*existing* product (the realistic case - the product is always created by
`ProductImporter` before `MediaImporter` ever touches it), **no store-id
code change was applied.** Changing already-correct behavior on a
theoretical concern that real execution disproved would have been an
unjustified architecture change.

Storefront rendering was verified with a real HTTP fetch of the product
page (`HTTP 200`, Fotorama gallery JSON correctly references the single
clean image) and the resized cache URL it points to (`HTTP 200`). Admin
was verified via the same `ProductRepositoryInterface` calls Admin itself
uses (not a screenshot - no browser available in this environment).

### Net result

Media import is now proven end-to-end for the exact case this file has
been tracking since Round 17: dry-run safety, execute, idempotent re-run,
correct role assignment, correct store-scope inheritance, no orphaned
files, real storefront rendering. **Media is no longer the blocker.** Per
the project's own stated priority order, the next milestone is
Attributes/Specifications - not started in this session.

---

## Round 24 — Attributes/Specifications: investigation + architecture prep (NOT EXECUTED)

New branch: `feature/specifications-attributes`, off a committed baseline
(`ea0fd46`). Everything below is code/schema **structure only** - no
`setup:upgrade`, no `--execute`, no attribute/option/set/value ever
written to Magento. 266 PHP files total (up from 215 at the start of this
round), 0 `php -l` failures.

### Investigation findings (live-verified against both databases, not
just docs)

- `analyze:attributes`/`analyze:attribute-sets` re-run live: every
  documented figure confirmed exactly (360 specs, 7,364 options, CREATE
  319/NEEDS_REVIEW 14/SKIP_UNUSED 23/SKIP_INVALID 4, 8 top-level
  categories, 89 multi-tree items).
- **Real documentation contradiction traced to resolution**:
  `ECCUBE_FORENSIC_DATA_FLOW_ANALYSIS.md` (Round 3/4) says "take first
  value, no multiselect" for the 5 multi-value specs; `ATTRIBUTE_MIGRATION_PLAN.md`
  §0.5, `MIGRATION_ASSUMPTIONS.md` §1a, and `SPECIFICATION_MAGENTO_DATA_MODEL.md`
  §10 all later retract that and recommend multiselect + a custom
  positional table. The FORENSIC doc was simply never updated with a
  correction note (unlike its sibling docs) - it is stale. Standing
  design used throughout this round's code is multiselect + positional
  table, per explicit instruction.
- **New critical finding, not previously documented anywhere**: a
  pre-existing, manually-built `ct_*` attribute family (17 attributes:
  `ct_icf`, `ct_nwkf`, `ct_vf`, `ct_vg`, `ct_a`/`ct_b`/`ct_c`/`ct_d`/`ct_pcd`,
  `ct_model`, `ct_2d_cad`/`ct_3d_cad`, `ct_tab_catalog`/`ct_tab_ground_floatin`,
  `ct_stock_status`/`ct_when_out_of_stock`) already exists on a "Coaxial"
  attribute set (id 9, 84 live products, real populated data - `ct_icf`
  alone has 74 non-null values). This conceptually overlaps 4 of the 5
  multi-value specifications and the dimension-letter/CAD/tab
  specifications. **Explicitly not touched, not renamed, not auto-mapped
  this round** - reconciliation is a pending business decision.
- **Schema correction caught before writing code**: `dtb_item_specification_class`
  has no `item_id`/`specification_id` column (only `id`,
  `specification_class_id`, metadata) - the real link is entirely through
  `dtb_item_specification.item_specification_class_id`.
  `dtb_product_specification_class` has no `sort_no` and no
  `specification_id` column either (only `id`, `specification_class_id`,
  `product_id`) - `specification_id` requires a join through
  `dtb_specification_class`, and there is no source ordering column for
  the multi-value case, so row `id` (insertion order) is the deterministic
  fallback, matching the existing `eccube_product_reference_map` pattern.
  This corrects an implicit assumption in `ATTRIBUTE_MIGRATION_PLAN.md`
  §B that conflated this table with `dtb_item_specification`'s own
  `sort_no`.

### Three pending decisions - kept pending, not resolved by code

1. Reconciliation with the pre-existing `ct_*` attribute family - open.
2. Multi-value specs (9 ICF, 10 NW/KF, 11 VF, 12 VG, 27 D): multiselect +
   custom positional table - implemented in code as the standing design,
   flagged pending in `Model/Specification/MultiValueSpecificationRegistry.php`.
3. Attribute-set tie-break for the 89 multi-category items: `dtb_category_item.sort_no DESC`
   then lowest `category_id` - implemented in `Model/Mapper/AttributeSetResolver.php`,
   flagged pending, **not yet wired into `ItemMapper`/`ProductMapper`**
   (those are proven, already-verified pipeline and were deliberately left
   untouched this round).

### What was built (architecture only)

- **Multi-value registry**: `Model/Specification/MultiValueSpecificationRegistry.php`
  - single source of truth for the pending decision, referenced by
  `AttributeImporter` (now creates `multiselect`/`varchar` instead of
  `select`/`int` for the 5 flagged specs - the only change to
  already-existing, previously-verified code this round) and both new
  value importers.
- **Specification values**: `Api/Data/{Item,Product}SpecificationValueInterface.php`
  + `Model/DTO/*` + `Api/*RepositoryInterface.php` + `Model/Repository/*`
  (read-only, schema corrected per above) + `Model/Import/ItemAttributeValueImporter.php`
  (Grouped Product, batched per item) + `Model/Import/ProductAttributeValueImporter.php`
  (Simple Product, batched per product via `getProductIdsWithValues()` -
  confirmed live this round: **27,355 distinct products** carry at least
  one specification value, far more than the 1,073 items with formal
  declarations, consistent with the "declaration is advisory" finding in
  `SPECIFICATION_MAGENTO_DATA_MODEL.md` ADDENDUM D). Idempotency via new
  `specification_value_hash`/`specification_values_synced_at` columns
  added to the existing `eccube_item_map`/`eccube_product_map` tables -
  same pattern as `InventoryImporter`'s `inventory_content_hash`, not a
  new table.
- **Multi-value positional store** (pending design): `eccube_product_specification_value`
  table + `Model/ProductSpecificationValueMap.php` trio + repository -
  lossless per-value storage keyed on the exact source row id.
- **Attribute Sets**: `Model/Mapper/AttributeSetResolver.php` (tie-break,
  pending) + `Model/Import/AttributeSetImporter.php` (creates/updates the
  8 sets via real `Magento\Eav\Api\AttributeSetManagementInterface`/`AttributeManagementInterface`,
  idempotent, permissive-union per `SPECIFICATION_MAGENTO_DATA_MODEL.md`
  ADDENDUM D) + `eccube_attribute_set_map` table.
- **Related Products**: `Model/Import/RelatedProductImporter.php` - native
  Magento `related` product links (matches `ProductRelationImporter`'s
  existing pattern), batched per source product, `eccube_related_product_map`.
- **Connection Parts**: `Model/Import/ConnectionPartImporter.php` +
  `Model/Product/CouplingProductProvider.php` + `Plugin/AddConnectionPartsToProduct.php`
  - mirrors the existing `ProductReferenceImporter`/`ProductReferenceProvider`/`AddProductReferencesToProduct`
  extension-attribute pattern exactly, since Connection Parts is
  structurally the same shape (a genuine relation table, not a Magento
  link type) and must not share Related Products' mechanism.
  `eccube_coupling_product_map` table.
- 5 new CLI commands (`import:attribute-sets`, `import:item-attribute-values`,
  `import:product-attribute-values` (with `--limit`, added this round -
  see below), `import:related-products`, `import:connection-parts`), all
  dry-run-by-default / `--execute`-required, matching `import:attributes`'s
  established safety convention.

### Verification actually performed (real, not claimed)

- Full module `php -l` sweep: 266 files, 0 errors.
- Every new class instantiated through Magento's **real DI container**
  (`$objectManager->create()`), not just linted - caught a stale config
  cache (fixed with `cache:flush config`, not a code defect).
- Every new CLI command run in its default dry-run mode against the real
  EC-CUBE DB. Two real, fixed findings from this:
  1. `import:attribute-sets`/`import:related-products`/`import:connection-parts`
     correctly report per-row errors for the new mapping tables not
     existing yet (expected - `setup:upgrade` was deliberately not run).
  2. **Real bug found and fixed**: `RelatedProductImporter`'s error
     handler was missing the `!$context->isDryRun()` guard around its
     map-save that every other importer has, and its fallback error-path
     map-save had no defense against a *second* failure of the same root
     cause - together these let one bad row crash the entire dry run with
     an uncaught exception instead of being recorded and continued past.
     Fixed (dry-run guard restored, error-path save wrapped
     defensively) and re-verified clean.
  3. `import:product-attribute-values` had no `--limit` option (unlike
     every other importer with an unbounded source table) - a full dry
     run legitimately walks all 27,355 products-with-values, taking
     several minutes. Added `--limit`, verified fast (<1s at `--limit=10`).
- Confirmed the pre-existing `analyze:attributes`/`analyze:attribute-sets`
  commands still produce identical output after the `SpecificationRepository`
  additions (purely additive, nothing removed/changed).

### Not done this round (explicitly, per instruction)

`setup:upgrade` (new tables don't physically exist yet - every dry run's
"error" count against them is expected), `--execute` of anything, any
change to `ItemMapper`/`ProductMapper` to consume `AttributeSetResolver`,
any change to the `ct_*` attributes, Item Additional Information, Catalog
document relation (already covered by the media pipeline, see Round 23),
CAD/document attribute (`cad_unavailable_check` already exists per
Round 22).

---

## Round 25 — Sync layer for Attributes/Specifications (NOT EXECUTED)

Closes the "required mapping/history/**sync**" gap from Round 24: every
new Round 24 component now has a Sync counterpart, matching the existing
Sync-extends/delegates-Importer pattern exactly. 278 PHP files total
(up from 266), 0 `php -l` failures.

### Sync classes added

`Model/Sync/{Attribute,AttributeSet,ItemAttributeValue,ProductAttributeValue}Sync.php`
are thin delegating wrappers (same reasoning as the existing
`InventorySync`/`ImageSync`: the underlying Importer is already
hash-incremental, so a full scan through it already behaves as a sync -
no fake "modified since" query invented). `Model/Sync/{RelatedProduct,ConnectionPart}Sync.php`
additionally page through their map table's distinct owner ids and call
new `markObsoleteForProduct()` / `markObsoleteForItem()` importer methods
for relations removed at source (same pattern as `MediaSync`) -
`RelatedProductImporter` gained `markObsoleteForProduct()` this round
(mirroring the already-existing `ProductReferenceImporter::markObsoleteForProduct()`
and `ConnectionPartImporter::markObsoleteForItem()`), plus
`getDistinctProductIds()`/`getDistinctItemIds()` on the two map
repositories to page owners without materialising the whole table.

**Known limitation, stated explicitly rather than silently omitted**:
these Sync classes (and the Round 24 Importers) do not scrub an
individual attribute *value* that was removed at source while the
item/product itself still exists - the hash-based skip correctly detects
the change and re-writes, but only ever sets values present in the
current resolved set, never explicitly clears one that's gone missing.
This mirrors a pre-existing, equally-unsolved gap in
`ProductReferenceImporter`'s own obsolete handling (which marks the
*mapping* obsolete but doesn't scrub already-written Magento data
either). Not fixed this round - flagged for revisit if source deletions
of individual specification values (not whole items/products) turn out
to occur in practice.

### CLI commands added (6)

`sync:attributes`, `sync:attribute-sets`, `sync:item-attribute-values`,
`sync:product-attribute-values`, `sync:related-products`,
`sync:connection-parts`. **Deliberately use the newer `--execute`-required
safety convention** (matching `import:attributes`/`import:images`), not
the older `sync:categories`/`sync:group-products`/`sync:simple-products`/`sync:inventory`/`sync:images`
commands' writes-by-default-unless-`--dry-run` pattern - a documented,
deliberate departure for this round's new commands per explicit
instruction to keep every migration command dry-run by default.

### Verification performed

- Full lint sweep: 278 files, 0 errors.
- All 12 new classes (6 Sync + 6 commands) instantiated through Magento's
  real DI container after a `cache:flush config`.
- All 6 sync commands registered (`bin/magento list`) and run in default
  dry-run mode against live data:
  - `sync:attributes`: 319 imported / 41 skipped / 0 errors (identical
    projection to `import:attributes`, confirming true delegation).
  - `sync:attribute-sets`: 8/8 expected "table does not exist" errors,
    each individually caught and logged - command completes cleanly, not
    a crash.
  - `sync:item-attribute-values`: 1092 skipped / 0 errors (identical to
    `import:item-attribute-values`).
  - `sync:related-products`: 61,617 total, 24,205 expected "table does
    not exist" errors, each individually caught and logged.
  - `sync:connection-parts`: 862 total, 707 expected "table does not
    exist" errors, each individually caught and logged.
  - `sync:product-attribute-values`: verified via direct code-path
    equivalence (the Sync class is a literal one-line delegation to the
    already-fully-verified `ProductAttributeValueImporter::import()`) -
    not re-run to completion a second time, since a partial run at
    `--batch-size=20` showed identical behaviour to the already-completed
    `--batch-size=100` run before being stopped as redundant.
- Re-confirmed zero Magento mutation after this round's testing:
  `eccube_spec_*` attributes still 0, `catalog_product` attribute sets
  still 2 (`Default`, `Coaxial`), `ct_*` family still 17 attributes
  untouched, none of the 4 new tables physically exist (`setup:upgrade`
  still deliberately not run).

### Not done this round

`setup:upgrade`, `--execute` of anything, `ItemMapper`/`ProductMapper`
wiring for `AttributeSetResolver`, `ct_*` reconciliation, the
individual-value-removal limitation noted above.

---

## Round 26 — `setup:upgrade` applied (schema only, verified clean)

Git checkpoint before this round: `0a89eaa` ("Implement specification
attributes migration"). A fresh Magento DB backup was taken by the user
before any database-changing command ran.

### First attempt - FAILED, real bug found and fixed

The first `setup:upgrade` run aborted partway through:
`SQLSTATE[42000]: Syntax error ... near 'Parts") to Magento...'` while
creating `eccube_coupling_product_map`. Root cause: that table's
`db_schema.xml` comment contained XML-escaped embedded double quotes
(`&quot;Connection Parts&quot;`), which decode correctly to a literal
`"` - but Magento's declarative-schema-to-SQL generator does not escape
embedded double quotes when building a `COMMENT "..."` clause, so the
literal quote broke out of the generated SQL string. Grepped the entire
schema file: this was the only `&quot;` occurrence anywhere - an
isolated bug, not systemic.

**Resulting partial state after the failure** (verified precisely, not
assumed): `eccube_attribute_set_map` and `eccube_product_specification_value`
had been created; `eccube_coupling_product_map` and
`eccube_related_product_map` had not (the second was never reached - the
failure aborted the run before it). All 4 new columns on
`eccube_item_map`/`eccube_product_map` had already been added
successfully (columns are applied before new tables in Magento's
declarative-schema apply order). Zero rows anywhere, zero impact on any
existing Magento or EC-CUBE data - confirmed by direct query, not
inferred from the error message.

**Fix**: one line changed in `etc/db_schema.xml` - removed the embedded
quotes from the `eccube_coupling_product_map` comment
(`&quot;Connection Parts&quot;` -> `Connection Parts`). Nothing else in
the file touched. `git diff --check` clean; diff reviewed and approved
before re-running.

### Second attempt - SUCCESS

`setup:upgrade` completed with no errors across all modules (1539-line
log, zero `error`/`exception`/`fail` matches). `setup:db:status` now
reports "All modules are up to date."

### Full verification performed (live queries, not assumed)

| Check | Result |
|---|---|
| `eccube_attribute_set_map` | EXISTS, 0 rows |
| `eccube_product_specification_value` | EXISTS, 0 rows |
| `eccube_coupling_product_map` | EXISTS, 0 rows |
| `eccube_related_product_map` | EXISTS, 0 rows |
| `eccube_item_map`/`eccube_product_map` new columns (4 total) | all EXIST |
| `catalog_product_entity` count | 19,158 (unchanged) |
| `catalog_category_entity` count | 328 (unchanged) |
| `customer_entity` / `sales_order` | unaffected |
| `catalog_product` attribute sets | still 2 (`Default`, `Coaxial`) |
| `ct_*` attributes | still 17, untouched |
| `eccube_spec_*` attributes | still 0 - nothing executed |
| EC-CUBE connection (`cosmotect_production`) | healthy, architecturally never touched by `setup:upgrade` |

Full module lint/validation re-run after the fix: 278 PHP files / 0
`php -l` errors, `di.xml`/`db_schema.xml`/`db_schema_whitelist.json` all
valid, module still enabled.

### Status

Schema migration for Attributes/Specifications is **complete and
verified**. No attribute, option, attribute set, or product value has
been created yet - `import:attributes --execute` (and every subsequent
`--execute` step) is still pending your approval. The 3 pending business
decisions (`ct_*` reconciliation, multiselect + positional storage for
specs 9/10/11/12/27, `sort_no DESC` + lowest-category_id tie-break) and
the 2 known limitations (`AttributeSetResolver` not wired into
`ItemMapper`/`ProductMapper`; individual-value removal not scrubbed)
remain exactly as documented in Round 24/25 - unchanged by this round.

---

## Round 27 — dry-run/execute bug fix + first real controlled execute (3 attributes)

### Bug found: `--execute` was silently non-functional on 12 commands

While attempting the first controlled `import:attributes --execute` test
(specs 119/125/141), the command printed "DRY RUN" and wrote nothing
despite `--execute` being passed. Root cause: the module's admin setting
**Stores > Configuration > Cosmotec > EC-CUBE Migration > "Dry Run by
Default"** was Yes, and every `--execute`-required command OR'd that
setting into its dry-run decision with equal weight to `--execute`:
`$dryRun = !$execute || $dryRunFlag || $config->isDryRunByDefault();` -
so the admin setting could silently defeat `--execute` with no error or
warning. Confirmed live-reproduced, not theoretical.

Exhaustively identified every affected file by grep (not assumed):
exactly the 12 `--execute`-having commands (`import`/`sync:attributes`,
`attribute-sets`, `item-attribute-values`, `product-attribute-values`,
`related-products`, `connection-parts`). 10 other, older commands
(`import:categories`, `sync:inventory`, etc.) also reference
`isDryRunByDefault()` but have no `--execute` option at all - a
different, correct, pre-existing design, confirmed untouched.
`--dry-run=0` (referenced in the admin field's help text as the intended
override) was confirmed **not actually implemented anywhere in the
module** - every `--dry-run` option in every command is a boolean flag
(`InputOption::VALUE_NONE`), not a value-accepting one.

**Fix (Option B - proper fix, not a config workaround)**: new
`Console\ExecuteModeResolver`, matching the already-working
`import:images` command's logic exactly (`$dryRunFlag || !$executeFlag`)
- the admin default is no longer consulted by this 12-command family at
all, since dry-run is already their unconditional hard default with or
without it. The admin setting itself, `system.xml`, and
`ModuleConfig::isDryRunByDefault()` were **not modified** - they still
correctly govern the older 10-command family. Applied identically to all
12 files (verified via grep - zero remaining references to
`isDryRunByDefault()` in any of the 12; `executeModeResolver` present in
all 12). Required clearing a stale `generated/code/Cosmotec` interceptor
cache after the constructor signatures changed (local build artifact,
not a data operation, not tracked in git).

Verified: 279 files / 0 lint errors; all 13 new/changed classes
instantiate through Magento's real DI container; behaviour matrix
confirmed against live data - no flags -> DRY RUN, `--execute` -> EXECUTE,
`--execute --dry-run` together -> DRY RUN (dry-run wins, matching
`import:images`'s documented precedence).

### First real controlled execute - 3 attributes (accepted as the
### controlled test, not rolled back)

Verifying step (b) of the behaviour matrix (`--execute` must report
EXECUTE) was run against the pre-approved, `ct_*`-non-overlapping,
non-multi-value filter `--specification-id=119,125,141` - which, now
that the fix makes `--execute` genuinely functional, actually created
the 3 attributes for real, one step earlier in the sequence than
originally planned. Reported immediately and transparently. Decision:
**accepted as the controlled test** (exact same pre-vetted spec set;
nothing unapproved was touched) - **not rolled back**.

Full verification performed against live Magento (not assumed):

| Check | Result |
|---|---|
| 3 attributes exist | `eccube_spec_119`="handle" (id 163), `eccube_spec_125`="Clamping bolt" (id 164), `eccube_spec_141`="Recommended plate thickness" (id 165) |
| frontend_input / backend_type | `select` / `int` for all 3 (correct - none of these 3 are in the pending multiselect set) |
| source_model | `Magento\Eav\Model\Entity\Attribute\Source\Table` (correct) |
| scope (`is_global`) | 1 (Global) for all 3 - correct |
| is_required | 0 for all 3 - correct |
| is_filterable / is_filterable_in_search | 0 for all 3 - correct, matches source `selectable_count=0` for these specific specs (UNION rule) |
| Option counts | 119: 2, 125: 3, 141: 2 - exact match to `dtb_specification_class` source counts |
| Option ordering | Verified via the **authoritative `eav_attribute_option.sort_order` column** (not array-iteration order, which is not reliable) - exact match to source `sort_no`/id-tiebreak ordering for all 7 options across all 3 attributes |
| No missing/duplicate options | Confirmed - 7 option-map rows total (2+3+2), each with a unique source `specification_class_id` |
| `eccube_specification_map` | Exactly 3 rows, correct spec IDs/attribute IDs/scope flags/status=imported, no duplicates |
| `eccube_specification_option_map` | Exactly 7 rows, correct source-to-Magento option id mapping, correct `sort_no`, status=imported |
| No duplicate Magento attributes | Confirmed via `GROUP BY attribute_code HAVING COUNT(*) > 1` - none |
| No stray `eccube_spec_*` attributes | Confirmed exactly 3 exist, nothing else |
| `ct_*` attributes | Still exactly 17, same ids/codes/labels/frontend_input/backend_type/is_required/is_user_defined as the pre-test snapshot - byte-identical |
| `catalog_product` attribute sets | Still exactly 2 (`Default`, `Coaxial`) |
| EC-CUBE source (`dtb_specification`/`dtb_specification_class`) | Unchanged - `analyze:attributes` re-run shows identical 360/7,364/319/14/23/4, matching every prior measurement this session |

### Idempotency - partial finding, honestly reported

Re-ran the same dry-run (`import:attributes --specification-id=119,125,141`,
no `--execute`) expecting a skip/update report. **It still printed
"Created: 3"** - confirmed via direct query this did **not** write
anything (all 3 counts unchanged) - but this is because
`AttributeImporter`'s dry-run branch (`importOne()`) unconditionally logs
"would create/verify" and increments the "imported" counter without
first checking whether the attribute already exists; only the real
`persist()` path (used in `--execute` mode) does the actual
adopt-existing-or-create-new check (`findExistingAttribute()`,
`getOptionByClassId()`). This is a **pre-existing reporting-only gap in
already-existing code** (not introduced by this round's fix, not a
duplication risk - verified no data was written) - the dry-run counter
is cosmetically misleading, not incorrect in effect. True idempotency
(does a second real `--execute` produce `Updated: 3, Created: 0` with no
duplicate rows) has not yet been empirically proven with a real run and
is flagged as a remaining item, not silently claimed.

### Not done this round (explicitly)

The remaining 316 specifications, attribute sets, item/product attribute
values, related products, connection parts. `ct_*` not modified. No
unrelated code changes. Nothing committed or pushed.

---

## Round 28 — dry-run idempotency reporting fix (final)

Closed the one remaining non-critical issue from Round 27: `AttributeImporter`'s
dry-run branch reported "Created" for every specification regardless of
whether the Magento attribute already existed, because the existing-attribute
check only happened inside `persist()` - after the dry-run branch had
already returned. No data was ever at risk (confirmed in Round 27 - the
dry-run wrote nothing either way), but the reported counts were
inaccurate, which matters for trusting dry-run output ahead of the full
319-attribute run.

**Fix**: `findExistingAttribute()` (already-existing method, unchanged)
is now also called once inside the dry-run branch of `importOne()`,
before the `isDryRun()` check short-circuits - a read-only Magento API
call, safe during dry-run. Reports `Updated` when the attribute code
already exists (mirroring exactly what a real `--execute` would do -
`persist()`'s own `$isUpdate` branch never truly "skips" an existing
attribute, it re-verifies/updates it) and `Created` only for a
genuinely new attribute code. Scoped deliberately narrowly: `persist()`
and the real execute/write path were **not** touched - a minor,
harmless duplicate `findExistingAttribute()` call now happens on the
execute path (once in the new dry-run check that mirrors it, once inside
`persist()`), left as-is rather than refactored, since the instruction
was to fix dry-run reporting only, not to touch execute behavior without
a proven need.

### Verification (live, this round)

- Lint: 279 files / 0 errors. DI: `AttributeImporter` instantiates
  cleanly after `cache:flush config`. `setup:db:status`: up to date.
  Module enabled.
- Re-ran `import:attributes --specification-id=119,125,141` (dry-run,
  no `--execute`): now correctly reports **`Created: 0, Updated: 3`**
  (previously incorrectly reported `Created: 3`). Log confirms per-spec
  detail: `"already exists - would verify/update"` for all three.
- Confirmed zero data written by this dry-run: `eccube_spec_*` still 3,
  `eccube_specification_map` still 3 rows, `eccube_specification_option_map`
  still 7 rows - all unchanged.
- Sanity-checked the other branch didn't break: dry-ran a genuinely new,
  never-imported specification (110, "Fittings") - correctly reported
  `Created: 1`, and confirmed nothing was actually written
  (`eccube_spec_110` does not exist; total `eccube_spec_*` count still 3).
- `ct_*`: still exactly 17, untouched. `analyze:attributes` re-run:
  EC-CUBE source figures identical (360/7,364/319/14/23/4) - unchanged.
- Per explicit instruction, `--execute` was **not** run again for
  specs 119/125/141 - the Round 27 real execution result stands as the
  controlled-test record.

### Status

The 3-specification controlled test (Round 27) plus this dry-run
reporting fix (Round 28) are both complete and verified. Dry-run output
can now be trusted ahead of the full 319-attribute run. No unrelated
files changed. Nothing committed or pushed - awaiting the Git checkpoint
before the full import.

---

## Round 29 — full 319-attribute/7,173-option import EXECUTED and verified

Git checkpoint for Rounds 27-28 was committed by the user directly
(`89c9f99 "Implement specification attributes migration - 2"`) before
this round began.

### Full dry-run, then full execute

`import:attributes` (no filter): dry-run reported
`Created: 316, Updated: 3, Skipped: 41, Errors: 0` (the 3 already
matching the Round 27 controlled test) - confirmed zero writes, then
executed for real. **Result: `Created: 316, Updated: 3, Skipped: 41,
Errors: 0` - identical to the dry-run projection.** Runtime: 5m30s for
360 specifications / 7,173 real options.

### Full verification (live, this round)

| Check | Result |
|---|---|
| `eccube_spec_*` attribute count | 319 (matches CREATE classification exactly) |
| Duplicate attribute codes | none |
| `eccube_specification_map` | 360 rows total: 316 imported + 3 updated + 41 skipped - matches exactly, no duplicates |
| `eccube_specification_option_map` | 7,209 rows, no duplicate source rows (`eccube_specification_class_id` unique per row) |
| Real Magento options for `eccube_spec_*` | 7,173 (all real, zero placeholder/empty-label rows - `AttributeImporter` never requests one) |
| Orphaned option-map rows (pointing at a non-existent Magento option) | **zero** - every map row resolves to a real option |
| `ct_*` | still exactly 17, untouched |
| EC-CUBE source | unchanged - `analyze:attributes` re-run identical (360/7,364/319/14/23/4) |
| Errors recorded anywhere in `eccube_specification_map` | zero |
| Idempotency | re-ran the same full dry-run: `Created: 0, Updated: 319, Skipped: 41` - exact, correct idempotent result |

### New finding this round - real, non-blocking, flagged for the
### PRODUCT-scope values phase

7,209 option-map rows resolve to only 7,173 distinct Magento options -
36 groups (up to 7 source rows each) share a single Magento option.
Investigated to ground truth rather than assumed a bug: this is a
**genuine EC-CUBE source limitation**, not a migration defect.
`dtb_specification_class.name_en` is `varchar(30)` at the source schema
level; multiple distinct option rows within the same specification
(different `sort_no`, different actual real-world values) are truncated
to an *identical* 30-byte English string, so `AttributeImporter`'s
existing label-based duplicate-option guard (`addOption()`'s
`in_array($label, $existingLabels)` check, working exactly as designed)
correctly treats them as "the same option" and reuses one Magento
option id for all of them.

Confirmed concretely (spec 62, source ids 5892-5895): English
`name_en` for all four is identically truncated to `"Large caliber:120
___Small cal"` (30 bytes, hits the column limit exactly), while the
**Japanese `name` column is not truncated and contains the real,
distinguishing values**: `大口径:120＿小口径:60`, `...70`, `...85`,
`...100` (small-diameter 60/70/85/100mm - a real, meaningful
difference). Per the project's own English-first-with-Japanese-fallback
rule, this is a case where English is *present* but *lossy*, not a case
already covered by "fallback when English is unavailable."

**Why this doesn't block the current phase**: every source
`specification_class_id` is still individually and correctly recorded
in `eccube_specification_option_map` (zero data loss at the mapping
layer, zero orphans, zero corruption) - the merge only affects the
Magento option *label/identity* for ~36 groups out of 7,364. **Why it
matters for what's next**: once PRODUCT-scope values are imported,
products that should show visibly different values (e.g. small-diameter
60mm vs 70mm) will both resolve to the same merged, ambiguous option
label. Flagged explicitly to revisit before/during the PRODUCT-scope
values phase - not fixed here, since doing so would mean guessing a
disambiguation scheme (e.g. falling back to the Japanese label, or
appending the source id) without a confirmed design decision, which
would violate the project's "never invent translations" rule.

### Status

Attributes + Options: **complete, executed, and fully verified** against
real production-scale data.

---

## Round 30 — Attribute Sets EXECUTED and verified

Dry-run (`Created: 8, Updated: 0, Skipped: 0, Errors: 0`) confirmed zero
writes, then executed (`Created: 8, Updated: 0, Skipped: 0, Errors: 0` -
identical, 3.3s).

### Full verification (live)

| Check | Result |
|---|---|
| Attribute sets created | Feedthrough(10), Vacuum Component(11), Isolator(12), Vacuum Valve(13), Motion Feedthrough(14), Others(15), Limited(16), Viewport(17) - 8/8, correct names |
| Attribute count per set | Matches `eccube_attribute_set_map.specification_count` exactly for all 8 (e.g. Vacuum Component: 193 `eccube_spec_*` attributes assigned, matching the analyze-time projection exactly) |
| `eccube_attribute_set_map` | 8 rows, correct top-level category ids (1,2,3,4,5,7,241,383), correct Magento set ids, `status=imported`, no errors |
| Duplicate set names within `catalog_product` | none (an initial unscoped check flagged a false positive across *other* entity types' own "Default" sets - re-checked scoped correctly, confirmed clean) |
| `Default` (id 4) / `Coaxial` (id 9) | untouched - 59 / 76 attributes respectively, Coaxial's 76 matching the count originally discovered when `ct_*` was first found, confirming no drift |
| `ct_*` | still exactly 17 |
| EC-CUBE source | unchanged - `analyze:attribute-sets` re-run identical to every prior measurement |
| Idempotency | re-ran dry-run: `Created: 0, Updated: 0, Skipped: 8` - content-hash skip working correctly |

### Status

Attribute Sets: **complete, executed, and fully verified.**

---

## Round 31 — area-code fix + CRITICAL false-success finding (attribute-set assignment gap)

### Area-code bug found and fixed

`import:item-attribute-values --execute` failed all 878 writable items with
`"Area code is not set"` - the identical root cause/fix class as the
media milestone's `MediaImporter`/`ImportImagesCommand` issue, now found
in the newer importer family. Confirmed precisely which of the 12
commands actually call `magentoProductRepository->save()` (only these
need the guard): `ImportItemAttributeValuesCommand`,
`ImportProductAttributeValuesCommand`, `ImportRelatedProductsCommand`,
and their 3 `sync:*` counterparts - 6 files. `ImportConnectionPartsCommand`
verified to never touch product save (writes only to a plain map table)
so deliberately not touched; `ImportAttributesCommand`/`ImportAttributeSetsCommand`
already empirically proven to work without it. Fix: same
try/`getAreaCode()`/catch/`setAreaCode('adminhtml')` guard already
proven in `ImportImagesCommand`, added to exactly these 6. Re-ran
`import:item-attribute-values --execute`: no more area-code errors,
"Written: 878, Errors: 0" (see below for why this number was still
misleading).

### CRITICAL finding: attribute-set assignment gap causes 100% silent data loss

Despite `Written: 878, Errors: 0`, direct verification found **zero**
rows in `catalog_product_entity_int`/`catalog_product_entity_varchar`
for any `eccube_spec_*` attribute anywhere in the catalog. Root cause
confirmed with certainty: every Grouped Product (1,092) and every Simple
Product (17,982) is on `attribute_set_id=4` ("Default"), and **zero**
`eccube_spec_*` attributes are assigned to that set (they only belong to
the 8 new category-derived sets created in Round 30). Magento's product
save silently drops `setData()` values for attributes outside the
product's current attribute set - no exception, no warning - which is
exactly why the importer reported clean success while persisting
nothing. This is the direct, now-empirically-proven consequence of the
previously-documented (Round 24/25) "`AttributeSetResolver` not wired
into `ItemMapper`/`ProductMapper`" limitation - testing has now
demonstrated it causes complete silent data loss, crossing the
project's own stated bar for escalation.

Secondary consequence: `eccube_item_map.specification_value_hash` was
set (non-null) for all 878 items despite no real data existing,
which would make the importer's own idempotency check wrongly skip
them on a retry. Not yet reset - addressed in the next round per the
user's explicit reset procedure (identify exactly which rows, reset
only those, verify zero EAV values and null hashes before retrying).

### Deployment context clarified by the user - changes the fix strategy

This Magento instance is **staging only**; the real deployment target is
an *empty* Magento catalog populated entirely by this migration module.
The existing `Default`/`Coaxial` attribute-set assignment on current
staging products is therefore test data, not a preservation target -
explicit permission granted to reassign/manipulate staging product
attribute sets aggressively while developing and verifying the real fix
(build the actual `AttributeSetImporter`-driven product-assignment step,
verified via a controlled experiment first). `ct_*` attributes
themselves remain off-limits regardless.

### Status

Area-code fix: verified working. Attribute-set assignment gap: root
cause confirmed, fix strategy set by the user, implementation next.

## Round 32 — AttributeSetResolver investigation report (live EC-CUBE data)

Per user direction: this Magento instance is staging/local-dev only; the real
deployment target is an empty Magento catalog, so the *existing* Magento
Default/Coaxial assignment is test data, not the migration source of truth.
EC-CUBE category data is the source of truth for attribute-set assignment.
All numbers below were produced by running `AttributeSetResolver` and
`SpecificationRepository` directly against the live EC-CUBE database (script:
scratchpad `resolver_report.php` / `unresolved_investigate.php`), not from
prior documentation.

### 1. The 8 target migration attribute sets (source top-level category → Magento set)

| eccube category_id | sort_no | Set name | magento_attribute_set_id |
|---|---|---|---|
| 1 | 1 | Feedthrough | 10 |
| 4 | 7 | Vacuum Component | 11 |
| 2 | 633 | Isolator | 12 |
| 5 | 697 | Vacuum Valve | 13 |
| 383 | 880 | Motion Feedthrough | 14 |
| 7 | 901 | Others | 15 |
| 241 | 902 | Limited | 16 |
| 3 | 907 | Viewport | 17 |

All 8 sets already exist in `eccube_attribute_set_map` from the Round-30
`AttributeSetImporter` execution; no gaps.

### 2/3/4. Items / products / specs mapped per set, ITEM vs PRODUCT usage

| Set | items | products | specs total | item-scope specs | product-scope specs |
|---|---|---|---|---|---|
| Feedthrough | 277 | 5,395 | 116 | 52 | 95 |
| Vacuum Component | 696 | 21,604 | 193 | 82 | 144 |
| Isolator | 12 | 180 | 31 | 9 | 24 |
| Vacuum Valve | 21 | 111 | 66 | 38 | 32 |
| Motion Feedthrough | 2 | 41 | 23 | 5 | 18 |
| Others | 60 | 240 | 84 | 38 | 68 |
| Limited | 6 | 105 | 28 | 19 | 10 |
| Viewport | 72 | 437 | 76 | 37 | 51 |

Item/product counts are per top-level tree (an item can appear in more than
one tree — see multi-category section below), and spec counts overlap
between sets because many specifications are reused across families.

### 5/6. The 89 multi-category items — exact resolution

89 items belong to more than one of the 8 top-level trees simultaneously.
Running the real `AttributeSetResolver::resolveTopLevelCategoryId()` (sort_no
DESC over `dtb_category_item`, lowest `category_id` as tiebreak — the
standing approved convention) against all 89 resolves every one of them
(0 unresolved in this subset):

- Viewport: 56 items
- Vacuum Component: 20 items
- Vacuum Valve: 11 items
- Feedthrough: 2 items

**Important finding on tie quality**: of the 89, **39 (44%)** have a genuine
`sort_no` tie (both `0`) between the winning categories in the two competing
top-level trees — e.g. item 4089 ties `{Feedthrough: 0, Others: 0}`, item 206
ties `{Viewport: 0, Vacuum Component: 0}`. For these 39, `sort_no` carries no
real signal and the resolution is determined entirely by the
lowest-category_id tiebreak convention, not by any EC-CUBE-authored ordering.
This is not a new architectural conflict — it's the expected consequence of
the tie-break rule the user already approved as the standing default — but it
is documented here because it means ~44% of multi-category resolutions are
convention-based rather than data-driven, which should be visible if these
assignments are ever manually reviewed.

### 7. Unresolved items (no chain to any of the 8 top-level trees)

Across **all** 1,073 distinct items that carry at least one specification
value (not just the 89 multi-category ones):

- Resolved: 1,037 (Vacuum Component 628, Feedthrough 265, Viewport 60,
  Others 52, Vacuum Valve 14, Isolator 12, Limited 4, Motion Feedthrough 2)
- **Unresolved: 36**

Root cause confirmed directly (not assumed): all 36 unresolved items have
**zero** rows in `dtb_category_item` — they carry no category assignment at
all in EC-CUBE. Sampled item names include a `【×】` prefix (EC-CUBE's own
discontinued/excluded marker, e.g. item 162 "【×】接続部品 真空用両側プラグ付マルチモードファイバー
UV / VIS") for most of them, consistent with these being deliberately
uncategorized/retired source records rather than a resolver defect. These 36
items still have specification data and must not be silently dropped from
the migration — they need an explicit fallback bucket (candidate: the
"Others" set, or a dedicated "Uncategorized" set) rather than being excluded;
this is a design decision to confirm before the ITEM importer runs against
them, not a blocking architectural conflict.

### 8. Products whose resolved target set differs from current Magento set

Checked all 1,092 `eccube_item_map` rows against their current
`catalog_product_entity.attribute_set_id`:

- **1,056 of 1,092 (96.7%)** have a resolved EC-CUBE-derived target set that
  differs from their current Magento attribute-set assignment.
- This is expected and confirms the known state: **100% of the current
  catalog sits on `attribute_set_id=4` (Default)**, which is disposable
  staging test data, not a migration signal. Per the user's explicit
  standing instruction, this is not a stop condition — it is exactly the
  situation the real product attribute-set assignment importer (Step 7,
  not yet implemented) exists to correct.

### Conclusion / no-stop determination

Nothing found in this report meets the user's stop criteria ("the
EC-CUBE-derived target assignment itself is ambiguous, incorrect, or could
cause data loss"). The two open items (39 convention-resolved ties, 36
uncategorized items) are documented for visibility but do not block
progress — proceeding directly to the controlled Magento reassignment
experiment (Step 4) as instructed.

## Round 33 — Controlled Magento reassignment experiment (real save/reload/verify cycle)

To avoid any risk to the only real reference data for the `ct_*` family,
neither experiment touched products 1192 (Grouped, Coaxial set) or 1197
(Simple, Coaxial set, 13 real `ct_*` EAV rows) directly. Instead each was
**cloned** via `ProductRepositoryInterface` (new SKU/entity, all data copied,
new unique `url_key`), the clone was put through the full experiment, then
deleted. Confirmed after cleanup: 1192 and 1197 are byte-for-byte unchanged
(`attribute_set_id=9` on both, 13 `ct_*` rows still on 1197, zero leftover
clone rows in `catalog_product_entity`).

### Experiment A — Simple product with real `ct_*` data, reassigned Coaxial(9) → Feedthrough(10)

1. Cloned 1197 → new entity_id 20350, 13 `ct_*` EAV rows copied over intact.
2. `setAttributeSetId(10)`, `save()` via `ProductRepositoryInterface`.
3. **Result: all 13 `ct_*` EAV rows were physically DELETED**, not just
   hidden — `catalog_product_entity_int`/`_varchar` went from 13 rows to 0
   for this entity immediately after the save. This is Magento's
   `EntityManager` doing exactly what its EAV save operation is documented
   to do: on save, it reconciles attribute values against the *current*
   attribute set's attribute list and removes rows for attributes no longer
   in that set. It is **not a soft-hide** — the data is gone unless captured
   beforehand.
4. Confirmed via reload: `ProductRepositoryInterface::getById()` after the
   reassignment returns `null` for `ct_a` etc., consistent with the DB state.
   Setting `eccube_spec_119` on this now-Feedthrough-set clone and saving
   also returned `null` on reload — because `eccube_spec_119` was not yet in
   the Feedthrough set's attribute group either at time of test (single
   3-spec controlled test only assigned it to specific sets during Round 24
   testing, and Feedthrough's own attribute assignment differs) — this is
   the *same* silent-drop behavior already identified as the Round-31 root
   cause, reproduced here on demand as expected.

**Implication for the real assignment importer (Step 7)**: reassigning
`attribute_set_id` on a live product is a destructive operation for any
existing EAV value outside the new set's attribute list. Since the
migration's actual EC-CUBE-mapped products currently sit on `attribute_set_id=4`
(Default, disposable test data — confirmed empty of `eccube_spec_*` and
free of any `ct_*` values), this is safe to do at scale for those 1,092+
17,982 mapped products. It must **never** be run against `ct_*`-bearing
products (the 84 Coaxial-set products, none of which are EC-CUBE-mapped —
confirmed in Round 33 pre-check) or any product outside the migration's own
`eccube_item_map`/`eccube_product_map` scope. The real importer must
restrict its candidate set to mapped products only — this is now a hard
requirement, not just a convention.

### Experiment B — Grouped product, reassigned Coaxial(9) → Vacuum Component(11), then value write

1. Cloned 1192 → new entity_id 20351.
2. Reassigned to Vacuum Component (11), saved successfully.
3. Set `eccube_spec_119` (an int/select-backed attribute) to the string
   `'EXPERIMENT-VALUE-B-GROUPED'`, saved, reloaded via
   `ProductRepositoryInterface`.
4. **Result: value persisted as `'0'`**, both via API reload and direct EAV
   query (`catalog_product_entity_int`). Root cause: `eccube_spec_119` is a
   `select` (int-backed) attribute; a non-numeric string set on an int
   attribute is silently coerced to `0` by Magento's EAV int handling
   instead of raising an error. This confirms the *positive* case — when an
   attribute **is** present in the product's attribute set, `setData()` +
   `save()` really does persist to the correct EAV table and is readable
   back through `ProductRepositoryInterface` — but it also surfaces a
   **second silent-failure mode** distinct from the Round-31 bug: passing an
   invalid/non-option value for a select attribute does not throw, it writes
   a wrong value (`0`, which is not a valid option ID either). The
   verify-before-hash fix (Step 5) must check not just "was something
   written" but "does the persisted value match the intended value/option
   ID," or this failure mode will produce a second generation of false
   successes.

### Unblocked

No genuine architectural ambiguity or unavoidable data-loss risk was found.
Both findings above are implementation requirements for Steps 5 and 7, not
stop conditions. Proceeding to Step 5 (verify-before-hash fix in
`ItemAttributeValueImporter`/`ProductAttributeValueImporter`).

## Round 34 — verify-before-hash fix (ItemAttributeValueImporter / ProductAttributeValueImporter)

`persist()` (Item) and `persistEav()` (Product) now save, then reload the
product with `ProductRepositoryInterface::getById($id, false, 0, true)`
(forced cache bypass), and compare every intended value against what was
actually read back. Any mismatch is returned to the caller instead of being
silently accepted. `importOne()` in both classes now only sets
`specification_value_hash` / calls `persistPositional()` when the returned
mismatch list is empty; on a non-empty list it increments `errors`, logs the
full expected-vs-actual detail, records `SyncHistory::STATUS_ERROR` with
that detail as the message, and returns without ever reporting success.
`ItemAttributeValueSync`/`ProductAttributeValueSync` needed no changes -
both simply delegate to the importer classes, so the fix covers sync too.

### Deliberate test (both paths, on real data, not a synthetic script)

Used item 1 / Magento product 1290 (one of the original 878 false-success
rows) directly, with its `specification_value_hash` reset to `NULL` for this
single identified row only (not the bulk Step-6 reset, which comes next).

**Negative path** (product still on `attribute_set_id=4`, the situation all
878 rows are actually in): ran
`bin/magento cosmotec:eccube:import:item-attribute-values --execute --batch-size=1`.
Result: `Written: 0, Updated: 0, Errors: 1` (previously this reported
`Written: 1, Errors: 0` under the old code). `eccube_sync_history` recorded
`status=error` with all 17 attribute codes and their `expected` vs.
`actual: null` values. `eccube_item_map.specification_value_hash` confirmed
still `NULL` after the run - no false success recorded.

**Positive path**: reassigned product 1290 to Feedthrough (`attribute_set_id=10`,
confirmed to carry all 17 of item 1's resolved attribute codes), re-ran the
same command. Result: `Written: 1, Errors: 0`. Verified independently of the
command's own report: direct query against `catalog_product_entity_int`
shows all 17 `eccube_spec_*` rows present with the correct values;
`ProductRepositoryInterface::getById(1290, false, 0, true)` reads
`eccube_spec_68 = '238'` correctly; `eccube_item_map.specification_value_hash`
is set and `specification_values_synced_at` is populated.

Product 1290 was reverted to `attribute_set_id=4` and item 1's hash reset
back to `NULL` afterward, so this test item re-enters the pool of 878 rows
needing the Step 6 reset + Step 7 real importer like every other one -
nothing was left in a special-cased state.

### Next

Step 6: precisely identify and reset the remaining false-success
`eccube_item_map` rows (878 total, one already reset as part of this test).
