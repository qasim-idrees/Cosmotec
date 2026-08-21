# Cosmotec_EccubeMigration

A migration and synchronization framework for moving catalog data from an
**EC-CUBE 4.0.0** installation into **Magento 2.4.8-p3**.

Confirmed entity mapping:

| EC-CUBE | Magento |
|---|---|
| `dtb_item` | Grouped Product |
| `dtb_product` | Simple Product |
| `dtb_product.stock_quantity` | Inventory Qty |

`dtb_item` is the parent of `dtb_product` (`dtb_product.item_id -> dtb_item.id`).

## Table of contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Admin UI](#admin-ui)
- [CLI usage](#cli-usage)
- [Cron](#cron)
- [Architecture](#architecture)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)

## Installation

1. Copy (or `composer require`) this module into `app/code/Cosmotec/EccubeMigration`.
2. Enable it and run setup:
   ```bash
   php bin/magento module:enable Cosmotec_EccubeMigration
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento cache:flush
   ```
   `setup:upgrade` creates four new tables:
   `eccube_category_map`, `eccube_item_map`, `eccube_product_map`,
   `eccube_image_map`, plus the shared `eccube_sync_history` log.
3. Confirm the module can reach the EC-CUBE database (see
   [Configuration](#configuration)), then run:
   ```bash
   php bin/magento cosmotec:eccube:test-connection
   ```

## Configuration

**Stores > Configuration > Cosmotec > EC-CUBE Migration**

### General
| Field | Purpose |
|---|---|
| Enable Migration Module | Master switch. Every CLI command and cron job checks this first. |
| Dry Run by Default | If Yes, every command behaves as if `--dry-run` was passed unless explicitly overridden. |
| Enable Logging | Gates writes to the three log files below. |
| Batch Size | Default page size for reading EC-CUBE data and for MSI write batching. Default: 100. |
| EC-CUBE Image Folder Path | Absolute path to the EC-CUBE upload/save_image directory, readable from this server. Required for the Images milestone. |
| Magento Root Category ID | The Magento category that EC-CUBE top-level categories (`parent_category_id IS NULL`) attach under — normally your store's root category (e.g. `2`), **not** Magento's absolute root (`1`). Defaults to `2`. |

### Scheduled Jobs
| Field | Purpose |
|---|---|
| Enable Scheduled Full Import | Gates the `cosmotec_eccube_full_import` cron job (default: daily 02:00). |
| Enable Scheduled Sync | Gates the `cosmotec_eccube_sync` cron job (default: hourly). |

### EC-CUBE Database Connection
Host, Port, Database Name, Username, Password (encrypted at rest), Charset
(default `utf8mb4`), Connection Timeout (default 5s). This is a **dedicated
PDO connection** — the module never reuses Magento's own database
connection to read EC-CUBE.

## Admin UI

**Admin > EC-CUBE Migration** (top-level menu):

| Page | What it shows |
|---|---|
| Dashboard | Status counts (pending/imported/updated/skipped/error) per entity type, plus a recent-activity feed from `eccube_sync_history`. |
| Entity Mapping | Four separate grid pages (Categories, Items, Products, Images — linked via a small nav bar at the top of each), one grid per page. Each row shows the EC-CUBE ID, the Magento ID it resolved to, status, and any error message. |
| Synchronization History | The full append-only `eccube_sync_history` log, filterable by entity type, operation, status, and run ID. |
| Logs | Tails the last ~300 lines of each of the three log files directly in the browser. |

These pages are **read-only** — there is no "Run Import" button. All
actual import/sync execution stays CLI/cron-only by design; triggering a
long-running migration process from an HTTP request without a queue
system would be an operational risk, not a convenience worth adding.

## CLI usage

Every `import:*` and `sync:*` command supports:

```
--dry-run              Report what would happen; writes nothing to Magento.
--resume                See note below.
--batch-size=N          Override the configured batch size for this run.
```

> **About `--resume`:** every import/sync command is idempotent by
> design — already-successfully-processed records are always skipped on
> re-run, and failed records are always retried on the next run,
> regardless of whether `--resume` is passed. The flag exists for
> operational clarity in logs/output rather than changing behavior.

### Recommended first-run order
```bash
php bin/magento cosmotec:eccube:test-connection

php bin/magento cosmotec:eccube:validate:categories
php bin/magento cosmotec:eccube:validate:products
php bin/magento cosmotec:eccube:validate:inventory

php bin/magento cosmotec:eccube:import:categories
php bin/magento cosmotec:eccube:import:group-products
php bin/magento cosmotec:eccube:import:simple-products
php bin/magento cosmotec:eccube:import:product-relations
php bin/magento cosmotec:eccube:import:images
php bin/magento cosmotec:eccube:import:inventory

php bin/magento cosmotec:eccube:reindex
php bin/magento cosmotec:eccube:status
```

Order matters for the import commands: categories must exist before
products can be assigned to them; group products (the Grouped Product
"shell") must exist before group relations can link simple products to
them; simple products must exist before images or inventory can be
attached to them.

### Ongoing / incremental updates
```bash
php bin/magento cosmotec:eccube:sync:categories
php bin/magento cosmotec:eccube:sync:group-products
php bin/magento cosmotec:eccube:sync:simple-products
php bin/magento cosmotec:eccube:sync:inventory
php bin/magento cosmotec:eccube:sync:images
```
These only touch records modified since the last successful run (or,
for inventory/images, records whose content hash changed) — safe to run
frequently without re-scanning everything.

### Full command reference
| Command | Purpose |
|---|---|
| `cosmotec:eccube:test-connection` | Verify EC-CUBE DB connectivity. |
| `cosmotec:eccube:validate:categories` | Read-only category data validation. |
| `cosmotec:eccube:validate:products` | Read-only item + product data validation. |
| `cosmotec:eccube:validate:inventory` | Read-only inventory data validation. |
| `cosmotec:eccube:import:categories` | Create/update Magento categories. |
| `cosmotec:eccube:import:group-products` | Create/update Grouped Product shells from `dtb_item`. |
| `cosmotec:eccube:import:simple-products` | Create/update Simple Products from `dtb_product`. |
| `cosmotec:eccube:import:product-relations` | Link Simple Products to their parent Grouped Product ("Group Relations"). |
| `cosmotec:eccube:import:images` | Upload product images to the Magento media gallery. |
| `cosmotec:eccube:import:inventory` | Write stock quantities via MSI source items. |
| `cosmotec:eccube:sync:categories` / `sync:group-products` / `sync:simple-products` / `sync:inventory` / `sync:images` | Incremental re-sync counterparts of the above. |
| `cosmotec:eccube:status` | Table of import/sync status counts per entity type. |
| `cosmotec:eccube:reindex` | Reindex the catalog/inventory indexers this module affects. |

## Cron

Two scheduled jobs (`etc/crontab.xml`, `default` group), both gated on
**Enable Migration Module** and their own **Scheduled Jobs** toggle:

| Job | Default schedule | Runs |
|---|---|---|
| `cosmotec_eccube_full_import` | `0 2 * * *` (daily 02:00) | The full import pipeline, in Import Order: categories → group products → simple products → group relations → images (if configured) → inventory. |
| `cosmotec_eccube_sync` | `0 * * * *` (hourly) | All five sync services. |

Both jobs reuse the exact same services as their CLI counterparts — nothing
is reimplemented for the scheduled path. Changing the schedule itself
requires editing `etc/crontab.xml` (see [Known limitations](#known-limitations)
for why this isn't admin-configurable).

## Architecture

```
Reader → Validator → Mapper → Importer → (mapping table + sync history) → Logger
```

- **Reader** (`Model/Reader/`): streams EC-CUBE rows in resumable batches
  via a `Generator`. One per entity: Category, Item, Product, Inventory,
  Image.
- **Repository** (`Model/Repository/` + `Api/*RepositoryInterface.php`):
  the *only* place SQL is written. EC-CUBE-side repositories (read-only,
  via a dedicated PDO connection) are distinct from Magento-side mapping
  repositories (`*MapRepositoryInterface`, read/write, via Magento's own
  ORM).
- **Validator** (`Model/Validator/`): data-quality checks only — required
  fields, numeric/range sanity, EC-CUBE-side referential integrity.
  Deliberately never checks "has this already been imported into
  Magento" — that's a Mapper/Importer concern, kept separate so
  `validate:*` commands give an honest pre-import picture.
- **Mapper** (`Model/Mapper/`): converts an EC-CUBE DTO into a
  Magento-target DTO (`Api/Data/Magento*Interface`). Resolves
  cross-entity references (parent category, parent item, SKU) and
  computes each target DTO's `getContentHash()`, used by sync to skip
  unchanged records. Grouped/Configurable/Bundle product-type selection
  goes through a Strategy Pattern (`Model/Mapper/Strategy/`) — only
  `GroupedProductStrategy` exists today, per the confirmed mapping, but
  adding another type is one new class plus one `di.xml` line.
- **Importer** (`Model/Import/`): orchestrates the above against the real
  Magento APIs, writes the mapping table row and a `eccube_sync_history`
  entry per record, and continues past individual record failures
  (logs + marks `status=error`; automatically retried on the next run).
- **Sync** (`Model/Sync/`): for Category/Item/Product, *extends* the
  matching Importer and overrides only the scan strategy
  (`getModifiedSince()` instead of a full scan) — 100% of the
  validate/map/persist/error-handling logic is inherited, not duplicated.
  Inventory and Images have no reliable "modified since" column to query,
  so their Sync classes are thin wrappers delegating to the (already
  content-hash-incremental) Importer.
- **Logger** (`Logger/`): three channels — `var/log/eccube_import.log`,
  `eccube_sync.log`, `eccube_error.log` (error-level entries from either
  channel also land here).

### Mapping tables
| Table | Tracks |
|---|---|
| `eccube_category_map` | `dtb_category.id` ↔ Magento category |
| `eccube_item_map` | `dtb_item.id` ↔ Magento Grouped Product |
| `eccube_product_map` | `dtb_product.id` ↔ Magento Simple Product, plus Group Relations linked-state and inventory sync state |
| `eccube_image_map` | `dtb_product_image.id` ↔ Magento media gallery entry |
| `eccube_sync_history` | Append-only log of every record processed by any import/sync command, across all entity types |

## Troubleshooting

**`test-connection` fails.** Check Host/Port/Database/Username/Password
under Stores > Configuration. Remember the password field is encrypted at
rest via Magento's own encryption key — if you've rotated
`app/etc/env.php`'s crypt key without re-entering the password, re-save it.

**A category imports under the wrong parent.** Check *Magento Root
Category ID* — if left at the default (`2`) but your store actually uses a
different root category, top-level EC-CUBE categories will attach to the
wrong tree.

**`import:simple-products` succeeds but products aren't visible on their
Grouped Product page.** Run `import:product-relations` — Simple Product
creation and Group Relations linking are separate steps by design (see
Architecture above).

**Images fail with "does not exist or is not readable".** *EC-CUBE Image
Folder Path* must be the absolute path to EC-CUBE's image directory as
seen **from this server** (not from wherever EC-CUBE itself runs, if
they're different hosts) and must be readable by the PHP process user.

**An image was replaced in EC-CUBE but Magento still shows the old one.**
Re-run `import:images` or `sync:images` — replacement is detected via a
filename+filesize+mtime hash, not full binary comparison (a file replaced
with identical size and mtime but different bytes won't be detected;
extremely unlikely for normal CMS-managed uploads, but worth knowing).

**Inventory numbers look wrong for a product with class variants.** By
design (confirmed requirement), `dtb_product.stock_quantity` always wins
over `dtb_product_class.stock`, even when class rows exist. This is not a
bug.

**A record is stuck in `error` status.** Check
`var/log/eccube_error.log` for the message, and/or query the relevant
`eccube_*_map` table's `error_message` column. Fixing the underlying data
issue in EC-CUBE and re-running the same import/sync command will retry
it automatically — no manual reset is needed (see
[Known limitations](#known-limitations) regarding `reset:*` commands).

## Known limitations

The original specification listed a broader CLI surface than what's
implemented here. Everything needed to actually run and monitor a
migration is complete (`test-connection`, all `validate:*`, all
`import:*`, all `sync:*`, `status`, `reindex`). **Not implemented** in
this build:

- `analyze`, `analyze:database`, `analyze:mapping`, `analyze:products`,
  `analyze:categories` — diagnostic/reporting commands beyond what
  `status` and `validate:*` already provide.
- `cleanup` — no dedicated command to prune orphaned mapping rows.
- `reset:mapping`, `reset:sync-history`, `reset:failed-jobs` — no
  dedicated reset commands. In practice, a failed record's `status=error`
  row is automatically retried on the next `import:*`/`sync:*` run once
  the underlying data issue is fixed, so a manual reset is rarely needed;
  if you do need to force a full re-import of something, the mapping
  tables are ordinary Magento tables and can be queried/edited directly.
- **Cron schedule is not admin-configurable** — `etc/crontab.xml` has
  fixed default times (daily 02:00 / hourly); only whether each job runs
  at all is a Store Configuration setting. This was a deliberate choice:
  Magento's dynamic-cron-expression technique needs verification against
  a live instance to get exactly right, which wasn't available while
  building this module (see `BUILD_STATUS.md`, Milestone 9, for the full
  reasoning).
- **Per-record Magento writes are not batched** except for MSI inventory
  writes (`InventoryImporter`, batched in Milestone 10) and Group
  Relations links (`ProductRelationImporter`, one save per parent item).
  `CategoryImporter`/`ItemImporter`/`ProductImporter`/`ImageImporter` each
  still do one Magento API call per record — fine at typical catalog
  sizes, worth revisiting if migrating a very large catalog.
- **Tests are unit-level only** (`Test/Unit/`), covering the validators,
  mappers, DTOs, and strategy pool — the parts with real business logic to
  get wrong. No integration tests (`Test/Integration/` exists but is
  empty) since those require a live Magento + MySQL test environment this
  build process didn't have access to; the unit tests likewise haven't
  been executed against real PHPUnit (no Magento framework was available
  in this environment) and should be run for real (`vendor/bin/phpunit
  app/code/Cosmotec/EccubeMigration/Test/Unit`) before relying on them.

See `BUILD_STATUS.md` for the complete, milestone-by-milestone build log,
including every EC-CUBE-schema-specific finding (e.g. the confirmed
inventory reconciliation rule, the lack of an `update_date` column on
`dtb_item` and `dtb_product_image`) that shaped the decisions above.
