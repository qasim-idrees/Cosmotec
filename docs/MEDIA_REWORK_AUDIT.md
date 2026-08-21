# Media Rework Audit & Design

Answers §16, §17 and §21 of the Round 11 instruction. No code has been
changed — this is the design that must be reviewed before Step 5 of the
implementation order begins.

## 1. Existing media class audit (§16)

Verdict key: **REUSE** (no change), **MODIFY** (extend in place),
**REPLACE** (rewrite internals, keep the class), **DEPRECATE** (retire).

| Class | Lines | Verdict | Reason |
|---|---|---|---|
| `Model/Repository/ImageRepository.php` | 58 | **REPLACE** | Only class naming `dtb_product_image` in SQL. Must query `dtb_upload_file` joined through 5 relation tables. |
| `Api/ImageRepositoryInterface.php` | 40 | **MODIFY** | `getByProductId()` / `getBatch()` / `countAll()` shapes survive, but need a relation-type dimension. |
| `Api/Data/ImageInterface.php` | 29 | **MODIFY** | Add `getUploadFileId()`, `getRelationType()`, `getOwnerId()`, `getFileExtension()`, `getMediaClass()`. Existing `getFileName()`/`getSortNo()` stay. |
| `Model/DTO/Image.php` | 50 | **MODIFY** | Mirror the interface changes. Immutable-DTO pattern unchanged. |
| `Model/Reader/ImageReader.php` | 36 | **MODIFY** | Generator/batching logic is correct and reusable; only the repository call and per-relation-type iteration change. |
| `Model/Validator/ImageValidator.php` | 59 | **REPLACE** | Currently assumes every file is an image. `dtb_upload_file` also holds pdf/zip/xlsx/pptx/docx/dxf/stp/step — must classify, not reject. |
| `Model/Mapper/ImageMapper.php` | 81 | **MODIFY** | Path resolution + content hashing are reusable. Main/gallery role logic must become relation-type-aware (a dimension drawing is never the "main image"). |
| `Model/Import/ImageImporter.php` | 320 | **REPLACE** | Hardcodes "iterate products → attach gallery images to Simple Product". Needs per-relation-type dispatch (product/dimension → Simple; item/catalog → Grouped; category → Category). Error handling, dry-run, history recording are reusable patterns. |
| `Model/Sync/ImageSync.php` | 38 | **REUSE** | Thin delegating wrapper — correct as-is once the importer is reworked. |
| `Model/ImageMap.php` + ResourceModel + Collection | 50 | **REPLACE** | Keyed on `eccube_image_id` (a `dtb_product_image` id that will never exist). Rekey per §17 below. |
| `Model/Repository/ImageMapRepository.php` | 80 | **MODIFY** | Method shapes survive; lookups change from image-id to (relation_type, owner_id, upload_file_id). |
| `Api/ImageMapRepositoryInterface.php` | 38 | **MODIFY** | Same. |
| `Console/Command/ImportImagesCommand.php` | 84 | **MODIFY** | Add `--type=product\|dimension\|item\|category\|catalog\|all`. Existing `--dry-run/--resume/--batch-size` handling is reusable. |
| `Console/Command/SyncImagesCommand.php` | 79 | **MODIFY** | Same. |
| `etc/db_schema.xml` `eccube_image_map` | — | **REPLACE** | Superseded by the schema in §2. |

**No class is DEPRECATED.** Nothing is deleted; `dtb_product_image` is
simply no longer referenced anywhere after the rework. Deliberate
decision: the table is empty, not gone — but keeping a fallback query
would violate §1 of the instruction ("do not merely add another fallback
query").

**Reusable without change**: `EccubeConnection`, `AbstractReader`
batching, `AbstractEccubeRepository` hydration helpers, `ImportContext`/
`ImportResult`, `SyncHistory`, both logger channels, and the
Sync-extends-Importer pattern.

## 2. Media mapping table design (§17)

Replaces `eccube_image_map`. Proposed `eccube_media_map`:

| Column | Type | Purpose |
|---|---|---|
| `entity_id` | int PK | — |
| `eccube_upload_file_id` | int, indexed | `dtb_upload_file.id` — the canonical source file |
| `relation_type` | varchar(24), indexed | `product` / `dimension` / `item` / `category` / `catalog` |
| `eccube_owner_id` | int, indexed | product / item / category id, per `relation_type` |
| `sort_no` | smallint | source ordering within the owner |
| `source_file_name` | varchar(255) | `dtb_upload_file.file_name` |
| `source_path` | varchar(512) | resolved `html/upload/save_image/{file_name}` |
| `media_class` | varchar(16) | `IMAGE` / `DOCUMENT` / `CAD` / `ARCHIVE` / `CATALOG` / `OTHER` (§10) |
| `magento_entity_type` | varchar(24) | `simple_product` / `grouped_product` / `category` |
| `magento_entity_id` | int, nullable | resolved Magento id |
| `magento_file_path` | varchar(512), nullable | final Magento media path |
| `magento_role` | varchar(64), nullable | `image,small_image,thumbnail` / `dimension_drawing` / `catalog` / null |
| `content_hash` | varchar(64) | filename+size+mtime, as already used by `ImageMapper` |
| `source_updated_at` | timestamp, nullable | `dtb_upload_file.create_date` (no update_date exists) |
| `last_synced_at` | timestamp, nullable | — |
| `status` | varchar(32), indexed | `pending`/`imported`/`updated`/`skipped`/`error`/`needs_review` |
| `error_message` | text, nullable | non-empty whenever status is `skipped`/`error` (§19) |
| `created_at` / `updated_at` | timestamp | — |

Unique constraint on `(relation_type, eccube_owner_id, eccube_upload_file_id)`
— the true source composite key, since the same file could in principle
be attached to several owners (currently 0 cases, but the constraint
makes that safe rather than assumed).

**§18 canonical-file support**: an index on `eccube_upload_file_id` alone
allows "has this physical file already been copied into Magento?" to be
answered before a second copy is made, even though no sharing exists in
today's data.

**§19 compliance**: `status` + mandatory `error_message` guarantees every
source row lands in exactly one classified bucket — including
`needs_review` for CAD ZIPs and orphans.

## 3. Media classification rules (§10)

| Extension | `media_class` | Magento handling |
|---|---|---|
| jpg, jpeg, png, gif, webp | `IMAGE` | media gallery |
| pdf | `DOCUMENT` | file attachment |
| dxf, stp, step | `CAD` | file attachment |
| zip | `ARCHIVE` | `needs_review` — CAD download subsystem (§11) |
| xlsx, xls, pptx, docx | `DOCUMENT` | file attachment |
| anything else | `OTHER` | `needs_review`, never silently dropped |

Only `IMAGE` files go through Magento's image validator — this is the
§10 requirement that "not every file goes through the product-image
validator."

## 4. Per-relation-type import targets (§2, §3, §4)

| Relation | Rows | Magento target | Role |
|---|---|---|---|
| `product_upload_file` | 30,876 | Simple Product | gallery; first = `image,small_image,thumbnail` |
| `dimension_upload_file` | 26,417 | Simple Product | dedicated `dimension_drawing` role, excluded from the normal gallery carousel |
| `item_upload_file` | 1,093 | Grouped Product | gallery/main |
| `category_upload_file` | 325 | Magento Category | category image |
| `catalog_upload_file` | 286 | Grouped Product | `catalog` document relationship, not EAV (§4) |

Ownership is never crossed: item media is not copied to children, product
media is not copied to the parent (§2).

## 5. Original-vs-variant policy (§9)

Migrate the **original** file only. EC-CUBE's `_s`/`_m`/`_l` copies are
derivatives generated by `getS3Url()` from one stored original; Magento
regenerates equivalents natively. For `d` (dimension) and `cl` (catalog)
the stored file *is* the original — no variants exist.

## 6. What still blocks implementation

| # | Item | Type |
|---|---|---|
| 1 | Magento role/entity for dimension drawings — custom gallery role vs. separate document table | **Business decision** |
| 2 | Is the CAD ZIP download subsystem (12,099 files) in scope? | **Business decision** |
| 3 | Multi-position specification representation (custom table + multiselect for ~5 specs) | **Approval needed** |
| 4 | Attribute-set tie-break for the 89 multi-top-level-category items | **Approval needed** |
| 5 | Japanese-only additional-information HTML — migrate as-is or translate? | **Business decision** |
| 6 | Physical file availability: the importer needs read access to `html/upload/save_image/` (or the S3/static mirror). Not yet confirmed available. | **Environment** |


## Round 12 updates

**Dimension drawings** — full option comparison (§18) is in
`FINAL_DECISION_GATE.md` §3. Recommendation: **Option A**, a custom
media-gallery role `dimension_drawing` on the Simple Product. Rationale:
they are genuinely images (jpg/png), so Magento's media subsystem gives
resize/replace/delete/sync for free; a custom role keeps them out of the
normal carousel. Option B (document table) would require rebuilding all
of that. Awaiting approval.

**CAD ZIPs** — removed from the media blocker list. `dtb_upload_cad_zip_file`
is `customer_id`-keyed and `CadController` generates archives on demand,
so these are not media assets at all. Blocker #2 in this document's §6 is
therefore **closed**; remaining blockers are #1 (dimension role), #3-#5
(specification/attribute-set/HTML decisions) and #6 (filesystem access).
