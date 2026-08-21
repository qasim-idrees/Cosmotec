# Migration Gaps

| Area | Existing Status | Required | Gap | Action |
|---|---|---|---|---|
| Connection | Dedicated PDO connection, configurable, working | Same | None | Reuse as-is |
| Categories | Full pipeline + sync, hierarchy-ordered | Same | None | Reuse as-is |
| Category Attributes | description/description_en mapped | Custom category data if present | None — confirmed no other custom fields exist in real schema | No action needed |
| Attributes | Not implemented | Discover/create/reuse Magento EAV attributes from `dtb_specification` | Full gap | Build: Reader, Repository, DTO, Validator, Mapper (with select-vs-numeric strategy per `type`), Importer, `eccube_specification_map` table |
| Attribute Options | Not implemented | Create/reuse Magento attribute options from `dtb_specification_class` | Full gap | Build alongside Attributes; `eccube_specification_option_map` table |
| Attribute Sets | Not implemented; all products use default set | Discover attribute-set candidates, create/reuse, assign per product | Full gap, strategy now resolved (Round 4: one set per top-level category, empirically justified across all 1,073 items) | Build an `AttributeSetStrategy` (mirrors existing `ProductTypeStrategyPool` pattern) |
| Group Products | Complete | Complete | None | Reuse as-is |
| Simple Products | Complete | Complete | None | Reuse as-is |
| Product Attributes (values) | Not implemented | Write `dtb_product_specification_class` values onto Magento products | Full gap, **highest volume** (~1.14M rows) | Build `ProductAttributeValueImporter`, batched writes (see Performance row) |
| Related Products | Not implemented | `dtb_related_product` → Magento related products | Full gap, semantics now source-confirmed (Round 4: Product→Product, child-level, shown on child-specific detail page — structurally distinct from Connection Parts) | Build `RelatedProductImporter` assigned per Simple Product, not shared with Connection Parts' link type |
| Coupling/Connection Products | Not implemented | `dtb_coupling_product` → some Magento representation | Full gap, semantics now source-confirmed (Round 3: Item→Product, dual-visibility-gated) | Build as a distinct link type per `MIGRATION_ASSUMPTIONS.md` §3 |
| Tags | Not implemented | Optional | Full gap, low priority | Defer unless requested |
| Inventory | Complete, batched MSI writes | Complete | None | Reuse as-is |
| Images | Complete, changed-image detection | Complete | None | Reuse as-is |
| SEO/URLs | Categories get a generated url_key; products don't | Migrate SEO data | **Not a migration gap — no source SEO data exists.** Remaining real gap: generate url_key for products | Add url_key generation to `ProductMapper`, mirroring `CategoryMapper`'s existing slugify logic |
| Synchronization | Complete for existing 5 entity types (Sync-extends-Importer pattern) | Extend to new entity types | Extends automatically once Attributes/Attribute Sets/Related Products are built, following the proven pattern | No new pattern needed |
| Performance (100k+ scale) | Inventory batched; everything else one-write-per-record | Handle ~1.14M attribute-value writes without one-call-per-value | Gap specifically for the new attribute-value write path | New importer must batch from day one (e.g. `catalog_product_attribute_update` style bulk writes, or grouped-by-product batched saves) — do not build it the same way as the existing per-record importers |
| CLI — analyze:* | Not implemented | `analyze`, `analyze:database`, `analyze:mapping`, `analyze:products`, `analyze:categories`, `analyze:attributes`, `analyze:attribute-sets`, `analyze:related-products` | Full gap | Build alongside their respective entity types; `analyze:attributes`/`analyze:attribute-sets` should produce the preview table the spec requires (§13) before any auto-create runs |
| CLI — validate:attributes, validate:attribute-sets, validate:relationships | Not implemented | New validate commands | Full gap | Build alongside entity types, matching existing `validate:*` pattern |
| CLI — import/sync:attributes, import/sync:attribute-sets, import/sync:product-relations (attribute sense), import/sync:related-products | Not implemented | New commands | Full gap | Build alongside entity types |
| CLI — cleanup, reset:* | Not implemented (previously documented gap, unchanged) | Optional administrative commands | Gap, low priority | Defer unless requested |
| CLI — migrate (single orchestrator) | Not implemented | One command running the full ordered pipeline | Gap | Straightforward once all stages exist — orchestrate in the documented dependency order (§25 of the prompt) |
| Ownership tracking | Mapping tables track migration-created records via status/hash, but nothing distinguishes "migration-owned" from "manually edited after import" | Don't overwrite manually-managed Magento data | Partial gap | Existing content-hash skip-if-unchanged mechanism already prevents most unnecessary overwrites; explicit manual-edit detection (e.g. comparing Magento's own updated_at against last sync) not yet built — low priority unless a concrete incident motivates it |

## Priority order recommendation

Given the real scale data, the practical build order is: **Attributes →
Attribute Options → Attribute Sets → Product Attribute Values (batched)
→ Related Products → Coupling Products → Product url_key generation**,
matching the dependency order in the prompt (§25) and the module's own
Import Order convention already established (parent structures before
values that reference them).


## Additions from the product-page data-model pass

| Area | Existing Status | Required | Gap | Action |
|---|---|---|---|---|
| Item additional information (product tabs) | Not implemented; **previously misclassified as static HTML** | Migrate `dtb_item_additional_information` (555 populated rows, 89 tab names, HTML bodies with images/links) | Full gap | Build Reader/Repository/DTO/Importer + custom Item-content table keyed to the Grouped Product. NOT EAV — variable-cardinality HTML with admin-authored titles. |
| Catalog tab media | Not implemented | `catalog_upload_file` → `UploadFile` per Item | Full gap | Attach as media/file to the Grouped Product |
| Model List rendering | Data available post-import; no renderer | Dynamic per-parent column set | Frontend gap | Custom Magento block deriving columns from the parent's PRODUCT-scope specification list |
| Multi-position specification values | Not implemented | Preserve spec/value/position/scope/entity | Full gap | Custom structured table + multiselect for layered nav (§10-11 of the data model doc) |
| Locale handling | English-first already implemented and now source-verified | — | None | No change needed |


## ⚠️ CRITICAL reclassification — image pipeline (Round 9)

| Area | Previous Status | Corrected Status | Action |
|---|---|---|---|
| Images (Milestone 6) | ✅ Complete | **⚠️ REQUIRES REWORK** — reads `dtb_product_image`, which has **0 rows** in production | Retarget to `product_upload_file` → `dtb_upload_file` (30,876 rows) |
| Dimension images | Not identified | **Full gap** — 26,417 rows in `dimension_upload_file`, technical drawings shown in Model List | New importer; Magento media role `NEEDS_REVIEW` |
| Item (parent) images | Believed non-existent | **Full gap** — 1,093 rows in `item_upload_file` | Attach to Grouped Product |
| Category images | Believed non-existent | **Full gap** — 325 rows in `category_upload_file` | Magento category image |
| Catalog images | Not identified | **Full gap** — 286 rows in `catalog_upload_file` | Media on Grouped Product |
| Image variants | Believed CDN-generated | EC-CUBE-generated via `S3Constant` | Migrate originals; let Magento regenerate |

**Why this was missed earlier**: the trimmed 44-table dump used during
Milestone 6 contained `dtb_product_image` rows; the full production dump
shows the site now uses the `dtb_upload_file` + join-table model. This is
a genuine data-source change, not an analysis oversight in the original
milestone — but it does mean the shipped image importer will import zero
images against the real database.


## Media architecture — REQUIRES_REWORK inventory (Round 10, §12)

Every class below assumes `dtb_product_image` (0 production rows) and is
formally marked **REQUIRES_REWORK**:

| File | Assumption | Action |
|---|---|---|
| `Model/Reader/ImageReader.php` | pages `dtb_product_image` | Retarget to `product_upload_file` → `dtb_upload_file` |
| `Model/Repository/ImageRepository.php` | all SQL on `dtb_product_image` | Rewrite for the join-table model |
| `Api/ImageRepositoryInterface.php` | per-product image lookup | Extend with relation type |
| `Api/Data/ImageInterface.php`, `Model/DTO/Image.php` | `product_id`+`file_name`+`sort_no` | Add `upload_file_id`, `relation_type`, `owner_id` |
| `Model/Mapper/ImageMapper.php` | product-scope only | Must handle 5 relation types |
| `Model/Validator/ImageValidator.php` | image files only | `dtb_upload_file` also holds pdf/zip/xlsx/dxf/stp |
| `Model/Import/ImageImporter.php` | Simple Product gallery only | Split per relation type + roles |
| `Model/Sync/ImageSync.php` | delegates to above | Follows importer rework |
| `Model/ImageMap.php` + ResourceModel + Collection + `ImageMapRepository` | keyed on `eccube_image_id` | Rekey on `upload_file_id` + relation type + owner |
| `etc/db_schema.xml` `eccube_image_map` | single-relation assumption | New/extended schema |
| `Console/Command/ImportImagesCommand.php`, `SyncImagesCommand.php` | single image concept | Likely split or gain a `--type` option |

**Not yet covered by any importer at all**: dimension files (26,417),
item images (1,093), category images (325), catalog files (286), CAD ZIPs
(12,099 — scope `NEEDS_REVIEW`).

## §13 review of other importers against the full production dump

| Importer | Source table | Production rows | Verdict |
|---|---|---|---|
| Category | `dtb_category` | 324 | ✔ populated |
| Item (Grouped) | `dtb_item` | populated (1,073 with specs) | ✔ |
| Product (Simple) | `dtb_product` | ~27,590 | ✔ |
| Inventory | `dtb_product.stock_quantity` | populated | ✔ |
| Group relations | `dtb_product.item_id` | populated | ✔ |
| **Images** | `dtb_product_image` | **0** | ✘ **REQUIRES_REWORK** |

Images were the only importer built on an empty table. The others are
confirmed against real row counts.


## Round 12 resolutions

| Item | Previous status | Resolution |
|---|---|---|
| CAD ZIP files (12,099) | `NEEDS_REVIEW` — scope undecided | **RESOLVED — OUT OF SCOPE.** `dtb_upload_cad_zip_file` is keyed to `customer_id`, and `CadController::downloadProduct()` generates ZIPs on demand into `eccube_temp_dir`. These are per-customer session artifacts, never catalog data. Whether the *download feature* is rebuilt in Magento is a separate project. |
| Orphaned upload files (13,628) | `NEEDS_REVIEW` | **RESOLVED** — the same artifacts. Classified `ORPHAN / OUT OF CURRENT MIGRATION SCOPE` with reason, per §19. |
| Positional semantic naming | Open question | **NOT FOUND** — `dtb_item_specification` has no name/label/position column and no such term exists in `app/Customize/`. Positions migrate as ordinals. |
| Primary category rule | "Business decision" | **NO SOURCE-DEFINED RULE FOUND**, but `Item::$CategoryItems` is `@ORM\OrderBy(sort_no DESC)` — a deterministic source ordering usable as the tie-break. |
| English source for Japanese HTML | Assumed absent | **CONFIRMED ABSENT** — no translation table, no `value_en`/`content_en`/`body_en` column anywhere in the 124-table schema. |


## Round 15 — CAD 2D/3D discovered (CORRECTED media inventory)

| Item | Previous | Corrected |
|---|---|---|
| Upload-file join tables | 5 identified | **7** — `cad2d_upload_file` (1,420) and `cad3d_upload_file` (10,655) were missed |
| CAD 2D/3D source | "NEEDS-REVIEW, join table not isolated" | **RESOLVED** — `ProductTrait::$Cad2dFiles` / `$Cad3dFiles`, ManyToMany to `UploadFile`, owned by child Product, max 1 each |
| CAD in scope? | Ambiguous | **Catalog CAD 2D/3D are IN SCOPE** (real per-product assets); only `dtb_upload_cad_zip_file` (customer-generated) is out of scope |
| `dtb_specification.type` | "0 for all rows, meaning unclear" | **RESOLVED — vestigial**. Declared on the entity, never read by any repository/controller/template; admin binds `specification_type_item_ids`/`_product_ids` which write `dtb_item_specification.type` |

**New gap**: CAD 2D/3D document import (12,075 files total) is not covered
by any importer and was not previously in any plan.


## Round 16 — corrected media inventory + document model

| Item | Previous | Corrected |
|---|---|---|
| UploadFile relation tables | 7 catalog relations | **9 join tables total** — `contact_upload_file` (1,341, Contact) and `designated_slip_upload_file` (197, Order) newly found, both **out of scope** |
| Document Name / Reference Link | Proposed as 4 flat EAV attributes | **CORRECTED** — source is `dtb_product_reference`, a **1:N table with 25,586 rows**. "Max 2" is an admin-form limit, not schema. Must be a relation table, not flat attributes. |
| `cad_not_available` | Assumed needs new source trace | **CONFIRMED** — `dtb_product.cad_unavailable_check`, already in `Api/Data/ProductInterface::isCadUnavailable()` |
| "13,628 orphaned files" | Assumed all CAD ZIP artifacts | Partially corrected — 1,538 belong to contact/order relations, not orphans |

**New gaps**: CAD 2D/3D document import (12,075), product-reference
metadata import (25,586 rows), and the relation-aware media pipeline
rework — none implemented yet.
