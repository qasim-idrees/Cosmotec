# Specification / Magento Data Model

Definitive source-to-frontend-to-Magento mapping for the Cosmotec
EC-CUBE 4.0.0 → Magento 2.4.8-p3 migration. Every mapping below is traced
to actual source; classification tags are used throughout:
`SOURCE-CONFIRMED`, `DATABASE-CONFIRMED`, `FRONTEND-OBSERVED`,
`INFERRED`, `NEEDS_REVIEW`.

**This document supersedes two earlier incorrect classifications, marked
`CORRECTED` below.**

---

## 1. Parent/Group vs Child/Simple Product Semantics

`SOURCE-CONFIRMED`. The two entities must never be merged:

| EC-CUBE | Magento | Frontend surface |
|---|---|---|
| `dtb_item` (Item / Parent) | Grouped Product | Basic Information, Catalog + additional-info tabs, Connection Parts |
| `dtb_product` (Product / Child) | Simple Product | Model List rows, Filter by Model, Related Products |

Scope is carried by **`dtb_item_specification.type`** (1 = ITEM,
2 = PRODUCT) — **not** `dtb_specification.type`, which is 0 for all 360
rows (`DATABASE-CONFIRMED`, previously corrected in
`ATTRIBUTE_MIGRATION_PLAN.md` §0.1). Scope is a property of the
`(item_id, specification_id)` pair. **80 specifications are used at both
scopes** — the same specification definition legitimately produces a
value on a Grouped Product for one item and on a Simple Product for
another. Values must retain their originating scope and entity.

---

## 2. Basic Information Data Flow

`SOURCE-CONFIRMED` (`ProductController::detail()` lines 707-732).

```
dtb_item_specification (type = 1, ITEM scope)
   -> dtb_item_specification_class (the parent's chosen values)
   -> Specification / SpecificationClass entities
   -> ProductController::detail()
        builds $specificationArray      (specs with NO specification_group)
        builds $setSpecificationArray   (specs WITH a specification_group, keyed by group)
   -> detail.twig
   -> "Basic Information" table on the Parent page
```

Flat vs grouped is decided purely by whether the specification has a
`specification_group_id`. **Magento representation**: attributes on the
**Grouped Product**, with grouped specs rendered via Magento attribute
groups; must not be copied onto child Simple Products.

---

## 3. Model List Data Flow

`SOURCE-CONFIRMED` (`detail()` line 792; `Product/parts/product_model_list.twig`).

```
Item -> getItemSpecificationsTypeProduct()   (dtb_item_specification WHERE type = 2)
     -> defines the COLUMN SET for this item
Item -> child dtb_product rows               (defines the ROWS)
     -> per row: dtb_product_specification_class -> SpecificationClass -> cell value
     -> empty cell renders as literal '-'
```

Columns are **per-Item and dynamic** — Parent A and Parent B legitimately
have different column sets. Nothing may be hardcoded (no fixed
NW/KF, ICF, VF, VG, A, B, C, D, P.C.D list). Rows additionally carry
Model/product_code, stock/delivery, and price from `dtb_product` /
`dtb_product_class`.

**Magento representation**: a Grouped Product's associated Simple
Products, rendered by a custom Model List block that derives its columns
from the parent's stored PRODUCT-scope specification list — not from a
static template.

---

## 4. Filter by Model Data Flow

`SOURCE-CONFIRMED` (`SpecificationRepository::findSelectableForSearchProduct()`,
`ProductRepositoryExtend::findProductIdsByOriginSpecificationClassIds()`).

Filters operate against **child Simple Products**, never the parent's
Basic Information values. Eligibility gate is
**`dtb_item_specification.selectable = 1`**; nesting/labelling comes from
`dtb_specification_group`; ordering is
`ORDER BY specification.sort_no, specification_class.sort_no`.

Filtering matches with **no positional constraint** — a product holding
ICF 152 *and* ICF 34 appears under **both** filter values
(`DATABASE-CONFIRMED` by query shape). Any Magento representation must
preserve that.

---

## 5. `dtb_item_additional_information` — **CORRECTED**

> **CORRECTED.** Round 3 of this analysis classified the non-Model-List
> product tabs as "hardcoded static Twig HTML, not database-driven."
> **That was wrong.** The Twig template contains only the static *tab
> shell*; the tab **titles and full HTML content are database-driven**
> from this table. The error came from reading the template's static
> scaffolding and stopping there instead of following the loop variable.

Schema (`DATABASE-CONFIRMED`):

| Column | Type | Meaning |
|---|---|---|
| `id` | int | PK |
| `item_id` | int FK → `dtb_item` | Item scope — parent only |
| `name` | varchar(20) | Tab title, Japanese |
| `name_en` | varchar(40) | Tab title, English |
| `value` | varchar(4000) | **Tab body — raw HTML** |
| `discriminator_type` | varchar(255) | Doctrine STI marker |

**There is no `value_en` column.** `SOURCE-CONFIRMED` via
`ItemAdditionalInformation::getNameWithLocale()`, which switches only the
*title*:

```php
public function getNameWithLocale() {
    if (locale_en()) { return $this->name_en; }
    return $this->name;
}
```

Real data (`DATABASE-CONFIRMED`): 3,264 rows, of which **only 555 carry a
value** (2,707 are entirely NULL placeholder rows). 554 of 555 contain
HTML; 307 contain `<img>`; 485 contain `<a>`. 89 distinct
(name, name_en) pairs. Content is authored **Japanese HTML** with links
to `static.cosmotec-co.jp`.

Top tab names by frequency: Reference materials (119), Assembly method
(73), Crimp tool (57), Notes on fastening glass parts (32), **The
difference of the ground and floating** (30), Assembled set breakdown
(29), Wavelength List (29).

---

## 6. Product Tab Data Flow — definitive mapping

`SOURCE-CONFIRMED`, `app/template/default/Product/detail.twig` lines
197-243:

```twig
<label for="productTabItem_1">{{ 'front.product.model_list'|trans }} ({{ Products|length }}...)</label>
{% if Item.CatalogFiles[0] %}
  <label for="productTabItem_2">{{ 'front.product.detail_catalog'|trans }}</label>
{% endif %}
{% for key, ItemAdditionalinformation in Item.ItemAdditionalinformations %}
  {% if ItemAdditionalinformation.name %}
    <label for="productTabItem_{{ key+3 }}">{{ ItemAdditionalinformation.nameWithLocale }}</label>
  {% endif %}
{% endfor %}
...
{% for ItemAdditionalinformation in Item.ItemAdditionalinformations %}
  <div class="ec-tabContents">{{ ItemAdditionalinformation.value|raw }}</div>
{% endfor %}
```

| Frontend section | Source table | Source field(s) | Entity | Scope | Type | Magento strategy |
|---|---|---|---|---|---|---|
| Model List | `dtb_item_specification` (type=2) + `dtb_product` + `dtb_product_specification_class` | see §3 | Item→Product | Product | Dynamic | Grouped→Simple associations + custom Model List block |
| Catalog | `catalog_upload_file` join → `UploadFile` | file path via `s3(Item.CatalogFiles[0],'save_image')` | Item | Item | Media | Media/file attached to Grouped Product |
| Difference of ground and floating | `dtb_item_additional_information` | `name`/`name_en` + `value` | Item | Item | Dynamic HTML | Item content store (see §11) |
| Assembly method | `dtb_item_additional_information` | `name`/`name_en` + `value` | Item | Item | Dynamic HTML | Item content store |
| Pressing tools / Crimp tool | `dtb_item_additional_information` | `name`/`name_en` + `value` | Item | Item | Dynamic HTML | Item content store |
| FAQ / other named tabs | `dtb_item_additional_information` | `name`/`name_en` + `value` | Item | Item | Dynamic HTML | Item content store |

**Important**: tab identity is **not** a fixed enum — it is whatever
`name`/`name_en` the admin typed. 89 distinct names exist. The tabs named
in the original instructions (Catalog, Assembly method, Pressing tools,
FAQ, ground/floating) are simply the most common values, **not** a schema.
A migration must therefore be **generic over tab names**, not
hardcoded to five.

`NEEDS_REVIEW`: the Twig tab-content loop is not gated by
`{% if ...name %}` (unlike the title loop) and the radio inputs are fixed
at 5 slots — so items with more than 3 additional-information rows may
render inconsistently in EC-CUBE itself. Behaviour beyond 5 tabs is
`NOT-TRACED`.

---

## 7. Connection Parts

`SOURCE-CONFIRMED` (`detail()` 757-771; `detail.twig` "接続部品" block).
`dtb_coupling_product`: **Item → specific Product** (cross-level), shown
on the **Parent** page, gated on both the target Product's and its parent
Item's `DisplayStatus = DISPLAY_SHOW`. Rendered with image, short name,
product_code, model, and (for logged-in users) stock comment.

Magento: distinct link type on the **Grouped Product**. Must not merge
with Related Products.

---

## 8. Related Products

`SOURCE-CONFIRMED` (`RelatedProduct` entity; `detailAfter` action).
`dtb_related_product`: **Product → Product** (same level), shown on the
**child-specific** page. Same dual visibility gating.

Magento: standard related-product links on **Simple Products**. Separate
link type from Connection Parts.

---

## 9. Specification Data Model

Definitions: `dtb_specification` (360) → options:
`dtb_specification_class` (7,364). Assignments:
`dtb_item_specification` (12,853 — the scope/selectable/sort_no carrier).
Values: `dtb_item_specification_class` (parent, ~5,754) and
`dtb_product_specification_class` (child, 193,473).

Classification (from `analyze:attributes`): CREATE 319 · SKIP_UNUSED 23 ·
SKIP_INVALID 4 · NEEDS_REVIEW 14 (SetSpecificationRule triggers).

---

## 10. Multi-position Specifications

Unchanged and reaffirmed from the previous round: **"take first value" is
REJECTED**. All 329 multi-value pairs have **different** values (0/329
identical) and represent adapters/reducers (ICF 152→70, NW/KF 16→25).
Position is stored: duplicated `(item, spec)` pairs carry
`sort_no = 0` and `sort_no = 1`.

Required preservation: `specification_id`, `option/value`, `position`,
`source scope`, `source entity`.

---

## 11. Magento EAV Representation — proposed, `NEEDS_REVIEW`

Per §11/§18 of the instruction, not everything becomes EAV:

| Source data | Classification | Proposed Magento representation |
|---|---|---|
| `dtb_specification` + options (single-value) | SPECIFICATION ATTRIBUTE | `select` EAV attribute, code `eccube_spec_{id}` |
| Multi-position specification values | SPECIFICATION ATTRIBUTE (positional) | custom structured table preserving position + `multiselect` for layered nav (see §10) |
| SetSpecificationRule triggers (14) | TRIGGER/CONTROL DATA | **no EAV attribute**; migration metadata only |
| `dtb_item_additional_information` | ITEM ADDITIONAL INFORMATION | **not EAV** — custom Item-content table keyed to the Grouped Product, storing `name`, `name_en`, `value` HTML, sort order |
| `catalog_upload_file` / CatalogFiles | MEDIA/FILE | media attached to Grouped Product |
| `dtb_coupling_product` / `dtb_related_product` | RELATIONSHIP | distinct Magento link types |

Rationale for the additional-information table rather than EAV: content
is per-Item **variable-cardinality HTML with variable titles** (89
distinct names, up to several rows per item). Modelling it as EAV would
require either 89+ attributes or lossy concatenation; a keyed content
table preserves it exactly and supports future sync.

---

## 12. Attribute Set Strategy — `NEEDS_REVIEW`, not finalized

8 top-level categories confirmed (`DATABASE-CONFIRMED`): Feedthrough,
Vacuum Component, Isolator, Vacuum Valve, Motion Feedthrough, Others,
Limited, Viewport. Per §17, sets must account for ITEM-scope vs
PRODUCT-scope attributes, and 80 specifications appear at both scopes —
so those attributes must belong to both the Grouped and Simple product
sets. 89 items (8.4%) span multiple top-level trees; the tie-break rule
remains **unapproved and unresolved**. **No sets created.**

---

## 13. Layered Navigation

Source `selectable` is per `(item, specification)`; Magento
`is_filterable` is global. Initial design: **UNION rule** (filterable if
any item marks it selectable), with the per-item `selectable` data
**preserved in the migration mapping layer** so a more faithful
implementation remains possible later. Documented design decision, not a
source mapping.

---

## 14. Initial Import — order

Categories → Specifications (attributes) → Options → Attribute Sets →
Grouped Products → Simple Products → Group relations → Item-scope values
→ Product-scope values → Item additional information → Media/Catalog →
Connection Parts → Related Products → Images → Inventory → Reindex.

## 15. Future Synchronization

Every layer above reuses the existing Sync-extends-Importer pattern and
mapping tables carrying source IDs, scope, position, and content hashes.

## 16. English/Japanese Handling

`SOURCE-CONFIRMED` — the locale mechanism, previously `NOT-TRACED`
across three rounds, is now resolved: `src/Eccube/Resource/functions/locale.php`
defines `locale_en()`/`locale_ja()` reading the `ECCUBE_LOCALE` env var,
set by `putenv_locale($locale)`. Entities expose `getNameWithLocale()`
accessors that return the `_en` column when `locale_en()` is true.

Coverage: specifications 360/360 and options 7,364/7,364 have English
names — English-first is fully achievable for attributes/options.
**However**: `dtb_item_additional_information.value` has **no English
column at all** — tab *content* is Japanese-only HTML while tab *titles*
are bilingual. Per §13 of the instruction ("use Japanese only when the
information is important and necessary"), this content **is** necessary,
so Japanese HTML must be migrated as-is with its source row recorded.
`NEEDS_REVIEW`: whether Cosmotec wants translation as part of migration.

## 17. Data-loss Analysis

Preserved: all specification values incl. multi-position; scope; position;
`selectable`; additional-information HTML; media; both relationship types.
Deliberately not migrated: SetSpecificationRule triggers as attributes
(control data, retained as metadata); 2,707 empty additional-information
rows; 23 unused + 4 invalid specifications (logged with reasons).

## 18. Performance

193,473 child values (not 1.14M — that was an AUTO_INCREMENT ceiling).
Grouped-by-product writes reduce this to ≈27,900 product saves. Existing
batching/resume/hash-skip patterns apply unchanged.

## 19. Testing Strategy

Existing unit tests cover classification, attribute code generation,
English-first selection, scope independence. Still to add: multi-position
preservation, additional-information parsing/locale, tab-name genericity,
Connection-Parts vs Related-Products separation, attribute-set proposal.

---

## Remaining open items

1. Attribute-set tie-break for the 89 multi-tree items — unapproved.
2. Final EAV representation for multi-position values — proposed only.
3. Named meaning of position 0/1 (e.g. vacuum vs atmosphere side) — no
   source evidence found.
4. Additional-information Japanese content translation policy.
5. EC-CUBE's own behaviour when an item has more than 3
   additional-information rows (5 fixed radio slots).

---

# ADDENDUM — Media / Catalog / Image Architecture (Round 9)

This addendum resolves §10, §14, §15 and §21 of the latest instruction
and contains **a critical finding that affects already-implemented code**.

## A1. ⚠️ CRITICAL — `dtb_product_image` IS EMPTY IN PRODUCTION

`DATABASE-CONFIRMED`. The table `dtb_product_image` **exists in the
schema but contains ZERO rows** in the real production dump
(`INSERT INTO \`dtb_product_image\`` count = 0).

**The existing module's `ImageImporter` / `ImageReader` /
`ImageRepository` read exclusively from `dtb_product_image`.** As built,
`cosmotec:eccube:import:images` would therefore import **nothing** from
this production database.

This was not detectable in Milestone 6: the earlier trimmed 44-table dump
used at that time contained `dtb_product_image` with data. The full
production dump shows the site has since moved to the upload-file model
below. Marked **CORRECTED** — the Milestone 6 image pipeline needs
rework before it can run against production.

## A2. The real image model — `SOURCE-CONFIRMED` + `DATABASE-CONFIRMED`

Images are stored once in `dtb_upload_file` and attached to owners via
five many-to-many join tables:

| Join table | Owner | Real rows | Frontend usage |
|---|---|---|---|
| `product_upload_file` | `dtb_product` (child) | **30,876** | Simple Product gallery |
| `dimension_upload_file` | `dtb_product` (child) | **26,417** | Dimension/technical drawings (the CAD-style diagrams in the Model List) |
| `item_upload_file` | `dtb_item` (parent) | **1,093** | Item-level image |
| `category_upload_file` | `dtb_category` | **325** | Category images |
| `catalog_upload_file` | `dtb_item` (parent) | **286** | Catalog tab image |

`dtb_upload_file`: **72,625 rows** — `id`, `creator_id`, `file_name`,
`sort_no`, `create_date`, `discriminator_type`, `product_id`.

**This corrects two earlier conclusions:**
- `CORRECTED` — "images belong to Product only; no Category or Item image
  entity exists." Category images (`category_upload_file`) and Item
  images (`item_upload_file`) **do** exist. The earlier finding was based
  on `ProductImage` being the only *entity* class; the actual ownership
  is expressed through join tables to a shared `UploadFile`.
- `CORRECTED` — `Item::$image_file_name` being non-persisted does **not**
  mean items have no images; they have them via `item_upload_file`.

## A3. Image variant generation — `SOURCE-CONFIRMED` (was INFERRED)

`app/Customize/Common/S3Constant.php` + `TwigFormExtension::getS3Url()` +
`AwsS3FileUpload`:

```php
IMAGE_PREFIX_MAP:  CATEGORY=>'c', ITEM=>'i', CATALOG=>'cl',
                   PRODUCT=>'p',  DIMENSION=>'d'
THUMBNAIL_SIZE_MAP: 's'=>[150,150], 'm'=>[300,300], 'l'=>[1000,1000]
IMAGE_PREFIX_SIZE_MAP:
    'c'  => [s, m]          // category: 150, 300
    'i'  => [s, m]          // item:     150, 300
    'cl' => []              // catalog:  ORIGINAL ONLY, no variants
    'p'  => [s, m, l]       // product:  150, 300, 1000
    'd'  => []              // dimension: ORIGINAL ONLY, no variants
```

`getS3Url()` rewrites `{original}_{prefix}-{unique}` into
`{original}_{prefix}-{w}-{h}-{unique}`. This fully explains the observed
`_c-150-150-`, `_i-300-300-`, `_p-1000-1000-` filenames. **Variants are
generated by EC-CUBE, not a CDN** — the earlier "external infrastructure"
inference is `CORRECTED`.

The **prefix embedded in the stored `file_name` identifies the image
type**, so ownership can be verified from the filename itself as a
cross-check against the join tables.

**Magento strategy**: migrate the **original** file only (the
un-prefixed-size base name) and let Magento generate its own cache
variants — except Catalog and Dimension images, which have no EC-CUBE
variants and are used at full size.

## A4. Catalog — separate from additional information (`SOURCE-CONFIRMED`)

Per §10: Catalog is **not** a `dtb_item_additional_information` record.
`detail.twig` renders it from `Item.CatalogFiles[0]` via
`{{ s3(Item.CatalogFiles[0], 'save_image') }}` — a single full-size image
inside its own tab, shown only `{% if Item.CatalogFiles[0] %}`.

| Aspect | Value |
|---|---|
| Table | `catalog_upload_file` → `dtb_upload_file` |
| Entity | `Item::$CatalogFiles` (ManyToMany, `@OrderBy sort_no ASC`) |
| Ordering | `sort_no` ASC; frontend uses `[0]` only |
| Title | none — tab label is the translation key `front.product.detail_catalog` |
| Localization | none on the file itself; label via i18n |
| Variants | none (`'cl' => []`) |
| Magento | media/file attached to the **Grouped Product** |

## A5. Final source-to-Magento matrix (§21)

| EC-CUBE Source | Entity | Scope | Frontend Usage | Magento Representation |
|---|---|---|---|---|
| `dtb_item` | Item | Parent | Parent product page | Grouped Product |
| `dtb_product` | Product | Child | Model List rows | Simple Product |
| `dtb_item_specification` (type=1) + `dtb_item_specification_class` | ItemSpecification | Parent | Basic Information | EAV on Grouped Product |
| `dtb_item_specification` (type=2) + `dtb_product_specification_class` | ProductSpecificationClass | Child | Model List cols / Filter by Model | EAV on Simple Product |
| Multi-position values (329 pairs, `sort_no` 0/1) | ProductSpecificationClass | Child | Model List (first only) / Filter (both) | Custom positional table + multiselect — `NEEDS_REVIEW` |
| SetSpecificationRule triggers (14 specs) | Specification | Parent authoring | none (excluded from `getList()`) | Migration metadata only, **no EAV** |
| `dtb_item_additional_information` | ItemAdditionalInformation | Parent | Additional tabs | Custom Item-content table (generic name/name_en/value) |
| `catalog_upload_file` → `dtb_upload_file` | UploadFile | Parent | Catalog tab | Media on Grouped Product |
| `item_upload_file` → `dtb_upload_file` | UploadFile | Parent | Item image | Media on Grouped Product |
| `product_upload_file` → `dtb_upload_file` | UploadFile | Child | Product gallery | Simple Product media gallery |
| `dimension_upload_file` → `dtb_upload_file` | UploadFile | Child | Dimension drawings | Simple Product media (separate role) — `NEEDS_REVIEW` on Magento role |
| `category_upload_file` → `dtb_upload_file` | UploadFile | Category | Category image | Magento category image |
| `dtb_product_image` | ProductImage | Child | **none — table empty** | **Not migrated** (superseded by `product_upload_file`) |
| `dtb_coupling_product` | CouplingProduct | Item→Product | Connection Parts | Custom link type on Grouped Product |
| `dtb_related_product` | RelatedProduct | Product→Product | Related Products | Magento related links on Simple Product |

## A6. Synchronization implications

Every media relationship must be tracked by `(join table, owner id,
upload_file id)` in the mapping layer, since one `dtb_upload_file` row
can be shared by multiple owners — a naive per-owner copy would create
duplicate Magento media and break re-sync idempotency.

## A7. Data-loss risks (updated)

| Risk | Status |
|---|---|
| Multi-position specification values | Mitigated by design (positional table); representation `NEEDS_REVIEW` |
| Dimension drawings (26,417) | **Would be lost entirely** if only `product_upload_file` is migrated — must be handled explicitly |
| Item + Category images | **Would be lost** under the earlier "images are child-only" conclusion — now corrected |
| Additional-information Japanese HTML | Preserved as-is; translation `NEEDS_REVIEW` |
| Catalog images | Preserved; no variants to lose |
| `dtb_product_image` | Nothing to lose — empty |

## A8. Immediate consequence for already-built code

`MIGRATION_GAPS.md` is updated accordingly: the Milestone 6 image
pipeline is reclassified from ✅ complete to **requires rework** — it
targets an empty table. This is the most actionable finding of this
round.


---

# ADDENDUM B — Complete Media Forensic Analysis (Round 10)

Resolves §5-§16 of the Round 10 instruction. All figures below are
computed from the full production dump; all frontend behaviour is traced
to actual Twig/PHP.

## B1. Physical file path — `SOURCE-CONFIRMED` (§16)

```
app/config/eccube/packages/eccube.yaml:
    eccube_save_image_dir: '%kernel.project_dir%/html/upload/save_image'
app/config/eccube/packages/framework.yaml:
    save_image: base_path: '/html/upload/save_image'
AwsS3FileUpload::S3_IMAGE_THUMBNAIL_ROUTE_PATH = 'html/upload/save_image/'
```

`dtb_upload_file.file_name` is the **complete stored filename** (already
including EC-CUBE's generated uniqueness hash); the public URL is simply
`{base}/html/upload/save_image/{file_name}`. This matches the observed
URLs in §11 of the instruction exactly. **No hashed subdirectories.**
The importer's source path is therefore deterministic — either the local
`html/upload/save_image/` directory or the S3/`static.cosmotec-co.jp`
equivalent.

`NEEDS_REVIEW`: MIME type and file size are **not stored** in
`dtb_upload_file` — they must be derived from the physical file at import
time (the existing `ImageImporter` already does `finfo`-based detection,
which is reusable).

## B2. Variant prefix mapping — independently cross-verified (§11)

Filename-prefix counts vs. join-table counts:

| Prefix | Files with prefix | Join table | Join rows | Match |
|---|---|---|---|---|
| `c` | 320 | `category_upload_file` | 325 | ✔ (±5) |
| `i` | 1,056 | `item_upload_file` | 1,093 | ✔ (±37) |
| `p` | 30,920 | `product_upload_file` | 30,876 | ✔ (±44) |
| `d` | 26,419 | `dimension_upload_file` | 26,417 | ✔ (±2) |
| `cl` | 286 | `catalog_upload_file` | 286 | ✔ **exact** |
| (none) | 13,624 | — | — | orphans, see B5 |

The §11 hypothesis (`c`=category, `i`=item, `p`=product, `d`=dimension,
`cl`=catalog) is **confirmed from two independent sources** — the
`S3Constant::IMAGE_PREFIX_MAP` constants and the actual data
distribution. Small deltas are expected where a file was re-uploaded or a
join row edited; they do not affect the mapping.

## B3. Dimension files — `SOURCE-CONFIRMED` (§5)

| Aspect | Finding |
|---|---|
| Entity | `Product::$DimensionFiles`, ManyToMany → `UploadFile`, `@OrderBy sort_no ASC` |
| Owner | **Child Product** (not Item) |
| Frontend | `product_model_list.twig:136` — `<img id="js-dimension-file-{{ key }}" data-exist="..." src="{{ s3(Product.DimensionFiles[0],'save_image') }}" class="is-hidden">`, revealed by JS when a Model List row is selected; also `detail_after.twig:644` on the child page |
| Semantics | **Technical/dimension drawings** — the CAD-style diagrams visible beside the Model List in the supplied screenshots |
| Variants | none (`'d' => []`) — original full-size only |
| Cardinality | 26,408 products have 1 file; 9 have 2 (max 2) |
| Localization | none |

**Magento representation** (`NEEDS_REVIEW` per §5's instruction not to
guess): the natural fit is a **dedicated media-gallery role** on the
Simple Product (e.g. a custom image role `dimension_drawing`), keeping it
out of the normal gallery carousel while remaining a first-class media
asset. Alternative: a custom media relationship table. **Not decided
here** — it depends on whether the Magento frontend will reproduce the
Model-List-row-triggered drawing swap.

## B4. Catalog files — `SOURCE-CONFIRMED`, §8 question answered

**The frontend's `[0]` loses nothing: `catalog_upload_file` contains
exactly 286 rows across 286 distinct items — every item has at most ONE
catalog file (max = 1).** So migrating "all catalog files" and
"migrating `[0]`" are identical for this dataset. Migrate the whole
relationship anyway (future-proof), but no data is at risk today.

Extension mix across all upload files includes `pdf` (947), `xlsx`,
`pptx`, `docx`, `dxf`, `stp`, `step` — so the media importer **must not
assume image-only**; `dtb_upload_file` is a general document repository.

## B5. Orphans and integrity (§15)

| Check | Result |
|---|---|
| `dtb_upload_file` rows | **72,625** |
| Referenced by any join | **58,997** |
| **Orphaned upload_file rows** (no join reference) | **13,628** |
| Orphaned join rows (pointing at a missing file) | **0** — referential integrity intact |
| Files referenced by MORE THAN ONE relationship | **0** |

The 13,628 orphans correlate almost exactly with the 13,624 unprefixed
filenames, and the extension mix is dominated by **12,099 `.zip`** files
— these are the **CAD/catalog download archives** (`dtb_upload_cad_zip_file`
exists as a separate table, and the storefront has "3D CAD Download" /
"2D CAD Download" buttons per the screenshots). They are **not orphaned
images**; they belong to a separate download subsystem.

`NEEDS_REVIEW`: whether CAD ZIP downloads are in migration scope. They
are currently **not** covered by any importer. Classified, not discarded.

## B6. §18 duplication concern — does not arise

**Zero** upload files are shared across relationships in this dataset, so
the feared physical duplication cannot occur. The canonical
`eccube_upload_file_id → Magento media` mapping recommended in §18 should
**still** be built (it is correct design and protects future syncs), but
it is not remediating an existing problem.

## B7. Required media source-to-Magento matrix (§14)

| EC-CUBE Source | Owner | Purpose | Frontend Usage | Magento Target | Status |
|---|---|---|---|---|---|
| `dtb_upload_file` (72,625) | Generic | Central file repository | Various | Canonical media mapping table | **Confirmed** |
| `product_upload_file` (30,876) | Product | Product gallery | Child product gallery + thumbnails | Simple Product media gallery | **Confirmed** |
| `dimension_upload_file` (26,417) | Product | Technical drawings | Model List row drawing; child page | Simple Product media, dedicated role | **Needs verification** (Magento role undecided) |
| `item_upload_file` (1,093) | Item | Parent image | Parent page + subcategory listing (`_i-150/300`) | Grouped Product media | **Confirmed** (source); Magento role straightforward |
| `category_upload_file` (325) | Category | Category image | Category tiles (`_c-150-150`) | Magento category image | **Confirmed** |
| `catalog_upload_file` (286) | Item | Catalog tab | Catalog tab, full size, max 1/item | Media/file on Grouped Product | **Confirmed** |
| `dtb_product_image` (0 rows) | Product | — | **none** | **Not migrated** | **Confirmed empty** |
| `dtb_upload_cad_zip_file` + 12,099 orphan `.zip` | ? | CAD downloads | 2D/3D CAD Download buttons | — | **NEEDS_REVIEW** — out of current scope |

## B8. Which variant each frontend surface uses (§10)

| Surface | Observed URL pattern | Source |
|---|---|---|
| Category tile | `_c-150-150-` | `category_upload_file` |
| Parent image in subcategory listing | `_i-150-150-` | `item_upload_file` |
| Parent product page | `_i-300-300-` | `item_upload_file` |
| Child gallery main | `_p-1000-1000-` | `product_upload_file` |
| Child gallery thumbnail | `_p-150-150-` | `product_upload_file` |
| Connection-parts card | `s3(..., 's')` → `_p-150-150-` | `product_upload_file` |
| Dimension drawing | no variant (original) | `dimension_upload_file` |
| Catalog | no variant (original) | `catalog_upload_file` |

**Strategy (§10)**: migrate the **original** file per relationship and let
Magento generate its own cache variants. EC-CUBE's `_s/_m/_l` copies
should **not** be imported as separate Magento assets — they are
derivatives of one original, and Magento produces equivalents natively.
For `d` and `cl` (no variants) the stored file *is* the original.

## B9. Synchronization design (§17, §19)

The media mapping table must carry: `eccube_upload_file_id`,
`source_relation_type` (product/dimension/item/category/catalog),
`source_owner_id`, `sort_no`, `file_name`, content hash, Magento target
id/role, status. That supports detection of new/changed/deleted files,
changed relationships, and reordering — reusing the existing
Sync-extends-Importer pattern.

## B10. Milestone 6 status (§21)

**REQUIRES REWORK** — unchanged and reaffirmed. `ImageImporter`,
`ImageReader`, `ImageRepository`, `Api/Data/ImageInterface`,
`Model/DTO/Image.php`, `Model/Mapper/ImageMapper.php`, `ImageMap`
model/resource/collection, `ImageMapRepository`, `import:images`,
`sync:images`, and `ImageValidator` **all assume `dtb_product_image`** and
must be reworked against the upload-file model. Marked
`REQUIRES_REWORK`, not deleted, pending the design decisions above.


---

# ADDENDUM C — Definitive EC-CUBE → Magento Mapping (Round 12, §28.2)

| # | EC-CUBE source | Magento 2.4.8-p3 target | Mechanism | Status |
|---|---|---|---|---|
| 1 | `dtb_category` (324) | Category tree | Native category | Implemented |
| 2 | `dtb_category.category_name_en` / `description_en` | Category name/description | Native, English-first | Implemented |
| 3 | `category_upload_file` (325) | Category image | Native category image | **To build** |
| 4 | `dtb_item` | **Grouped Product** | Native | Implemented |
| 5 | `dtb_product` | **Simple Product** | Native | Implemented |
| 6 | `dtb_product.item_id` | Grouped→Simple associations | Native links | Implemented |
| 7 | `dtb_specification` (360) → 319 CREATE | Product EAV attributes `eccube_spec_{id}` | EAV `select` | **To build** |
| 8 | `dtb_specification_class` (7,364) | Attribute options | EAV options, English-first | **To build** |
| 9 | `dtb_item_specification` type=1 + `dtb_item_specification_class` | **Grouped Product** attribute values → Basic Information | EAV on parent | **To build** |
| 10 | `dtb_item_specification` type=2 + `dtb_product_specification_class` (193,473) | **Simple Product** attribute values → Model List / Filter by Model | EAV on child | **To build** |
| 11 | Multi-position values (329 pairs, `sort_no` 0/1) | Custom positional table + `multiselect` on ~5 specs | Custom + EAV | **Approval** |
| 12 | `SetSpecificationRule` triggers (14 specs) | **No EAV** — documented dependency metadata | Metadata only | Decided |
| 13 | Attribute sets ← 8 top-level categories | 8 attribute sets (dual scope aware) | Native | **Approval** |
| 14 | `dtb_item_specification.selectable` | `is_filterable` (UNION) + preserved per-item data | EAV + mapping | **Approval** |
| 15 | `dtb_product.stock_quantity` | MSI source items | Native | Implemented |
| 16 | `product_upload_file` (30,876) | Simple Product media gallery | Native media | **Rework** |
| 17 | `dimension_upload_file` (26,417) | Simple Product media, role `dimension_drawing` | Custom role | **Approval** |
| 18 | `item_upload_file` (1,093) | Grouped Product media | Native media | **To build** |
| 19 | `catalog_upload_file` (286) | Grouped Product document relation | Custom relation | **To build** |
| 20 | `dtb_item_additional_information` (555 populated) | Custom Grouped-Product content table (name/name_en/value/sort) | Custom table, **not EAV** | **To build** |
| 21 | `dtb_coupling_product` | Connection Parts — distinct link type on Grouped Product | Custom link | **To build** |
| 22 | `dtb_related_product` | Related Products — standard links on Simple Product | Native links | **To build** |
| 23 | `dtb_upload_cad_zip_file` / 12,099 ZIPs | **Out of scope** — per-customer generated artifacts | none | Decided |
| 24 | 13,628 orphaned upload files | Classified `ORPHAN / OUT OF SCOPE` | Reported | Decided |
| 25 | All of the above | `eccube_*_map` tables + `eccube_sync_history` | Custom mapping | Partly built |
| 26 | `dtb_product_image` (0 rows) | **Not migrated** | none | Decided |

Legend: *Implemented* = working and verified against production row
counts; *Rework* = exists but targets the wrong source; *To build* = not
started; *Approval* = design ready, awaiting a decision.


---

# ADDENDUM D — Admin UI Reconciliation (Round 14)

The admin screenshots gave a working hypothesis (§12/§17 of the Round 14
instruction): *the Parent Item's "Product Specifications" section acts as
an attribute whitelist that constrains its children.* I tested that
hypothesis against the full production database rather than accepting it.
**It does not hold.** Details below, including a parsing error I caught
and corrected mid-analysis.

## D0. Parsing correction (disclosed, not hidden)

My first run of the decisive test read `dtb_product` field index 1 as
`item_id`. The actual column order is
`id, creator_id, product_status_id, name, note, description_list,
description_detail, search_word, free_area, create_date, update_date,
discriminator_type, item_id, ...` — **`item_id` is index 13, not 1.**
The first result was therefore invalid. I re-ran with the verified column
index; the corrected result is reported below. (Coincidentally both runs
produced 5.69%, but only the second is trustworthy.)

## D1. DECISIVE TEST — the whitelist hypothesis FAILS

Test: for every child product with specification values, are all of those
specifications declared `TYPE_PRODUCT` by its parent Item?

| Measure | Result |
|---|---|
| Children whose values fall entirely within the parent's `TYPE_PRODUCT` declaration | **1,556** |
| Children with values outside it | **25,799** |
| **Conformance** | **5.69%** |
| Conformance measured against the parent's declaration at *any* scope | **6.35%** |

**`dtb_item_specification` is not a strict attribute whitelist.** Child
products routinely hold specification values their parent never declared.

**Root cause, DATABASE-CONFIRMED**: only **1,073 items** have any
`dtb_item_specification` row at all, while **27,590 products** exist
across roughly 4,126 items. The large majority of items declare nothing,
yet their children still carry specification values. A declaration-driven
attribute set would therefore leave most products with an empty set.

## D2. Second finding — scope is not a partition either

Among child values whose specification was *not* declared `TYPE_PRODUCT`,
**18,259 occurrences had that specification declared `TYPE_ITEM`** by the
parent instead. So a specification declared at Item scope still receives
values on child products.

This **reinforces, with new evidence, the earlier finding** that scope is
a per-(item, specification) property and that 80 specifications appear at
both scopes — it is not a clean parent/child partition, and any Magento
design that assumes one will lose data.

## D3. What this means for Attribute Sets — hypothesis rejected, prior approach stands

The Round 14 instruction asked whether the admin "Product Specifications"
section provides a stronger, source-defined Attribute Set rule than
category hierarchy. **Answer: no.** At 5.69% conformance and with 74% of
items declaring nothing, it cannot drive attribute-set membership.

The category-derived approach documented in `FINAL_DECISION_GATE.md`
therefore **remains the recommendation**, and the tie-break question for
the 89 multi-tree items **remains a genuine business decision** — it was
not resolvable from source, and I checked rather than assumed.

Practical consequence for Magento: attribute sets should be **permissive**
(the union of specifications used anywhere in a category tree), not
restrictive. Magento tolerates unused attributes in a set; it does not
tolerate a product needing an attribute its set omits.

## D4. Admin → DB → Frontend → Magento matrix (Round 14 §14)

| EC-CUBE Admin Section | Source Table | Owner | Frontend Usage | Magento Target | Status |
|---|---|---|---|---|---|
| Item Specifications | `dtb_item_specification` (type=1) + `dtb_item_specification_class` | Item | Basic Information | EAV on Grouped Product | `SOURCE-CONFIRMED` |
| Product Specifications | `dtb_item_specification` (type=2) declaration + `dtb_product_specification_class` values | declared on Item, valued on Product | Model List + Filter by Model | EAV on Simple Product | `SOURCE-CONFIRMED`; declaration is **advisory, not a whitelist** (D1) |
| Catalog Image | `catalog_upload_file` → `dtb_upload_file` | Item | Catalog tab | Media on Grouped Product | `SOURCE-CONFIRMED` (max 1/item) |
| Option Content 1..N | `dtb_item_additional_information` | Item | Product tabs | Custom content table | `SOURCE-CONFIRMED` |
| Model List | Item → child Products | Item | Child product table | Custom frontend block | `SOURCE-CONFIRMED` |
| Connecting Parts | `dtb_coupling_product` | Item → Product | Connecting Parts | Custom link type | `SOURCE-CONFIRMED` |
| CAD 2D / CAD 3D | upload relation (admin fields confirmed) | Product | Download buttons | Document/media | `NEEDS-REVIEW` — join table not yet isolated per-field |
| Dimension Images | `dimension_upload_file` | Product | Model List drawing | Media, role `dimension_drawing` | `SOURCE-CONFIRMED` |
| Related Products | `dtb_related_product` | Product → Product | Related Products | Native related links | `SOURCE-CONFIRMED` |
| Product Gallery | `product_upload_file` | Product | Gallery | Media gallery | `SOURCE-CONFIRMED` |

The admin screenshots **confirm** every previously traced ownership
finding. Notably the child-product admin page shows "Related Products"
(child-level) while the Item page shows "Connecting Parts" (parent-level)
— exactly the parent/child split traced from source in Round 4.

## D5. Impact on already-written code (Round 14 §16)

| Class | Impact | Action |
|---|---|---|
| `AttributeImporter` | **None** — creates global attributes, does not assume whitelist semantics | KEEP |
| `SpecificationRepository` | **None** — reads scope per (item, spec), matching D2 | KEEP |
| `SpecificationMapRepository`, `eccube_specification_map` | **None** — already stores both scope flags independently | KEEP |
| Attribute-set logic | **Not yet written** — D1/D3 confirm it must be permissive/union-based | Design confirmed before coding |
| Media importer | Unchanged from Round 9-11 findings | REQUIRES REWORK (already logged) |

**No already-written code needs correction from this round.** The
existing implementation happens to be compatible because it stores both
scope flags rather than partitioning on them — which D2 now validates as
the correct choice.


---

# ADDENDUM E — Decision Gate Closure (Round 15)

Two previously-open items are now **resolved from source**. One earlier
statement is **CORRECTED**.

## E1. CAD 2D / CAD 3D — RESOLVED (§10). Previously "NEEDS-REVIEW"

`app/Customize/Entity/ProductTrait.php` lines 166-190:

```php
@ORM\ManyToMany(targetEntity="Customize\Entity\UploadFile", cascade={"remove"})
@ORM\JoinTable(name="cad2d_upload_file")
@ORM\OrderBy({"sort_no"="ASC"})
private $Cad2dFiles;

@ORM\ManyToMany(targetEntity="Customize\Entity\UploadFile", cascade={"remove"})
@ORM\JoinTable(name="cad3d_upload_file")
@ORM\OrderBy({"sort_no"="ASC"})
private $Cad3dFiles;
```

**Two join tables I had not previously identified**, both owned by the
**child Product**:

| Table | Rows | Owners | Max per owner |
|---|---|---|---|
| `cad2d_upload_file` | **1,420** | 1,420 | 1 |
| `cad3d_upload_file` | **10,655** | 10,655 | 1 |

**CORRECTED**: earlier media inventories listed five upload-file join
tables. There are **seven**. `cad2d_upload_file` and `cad3d_upload_file`
were missed because I searched for tables matching `*cad*` only in the
database (which found only `dtb_upload_cad_zip_file`) and did not search
the entity annotations for join-table names. The correct total of files
referenced by join tables rises accordingly.

**These are genuine catalog assets** — real, stored, per-product source
CAD files with a strict 1:1 ownership. They are entirely distinct from
`dtb_upload_cad_zip_file` (customer-session generated, out of scope), and
§12's three-way separation is now fully evidenced:

| Concept | Source | Rows | Scope |
|---|---|---|---|
| A. Customer ZIP artifacts | `dtb_upload_cad_zip_file` | ~8,622 | **OUT OF SCOPE** |
| B. Catalog CAD 2D | `cad2d_upload_file` → `dtb_upload_file` | 1,420 | **IN SCOPE** — downloadable document |
| B. Catalog CAD 3D | `cad3d_upload_file` → `dtb_upload_file` | 10,655 | **IN SCOPE** — downloadable document |
| C. Dimension drawings | `dimension_upload_file` | 26,417 | **IN SCOPE** — displayed image |

This also explains the storefront's "2D CAD Download" / "3D CAD Download"
buttons: they act on B, while the ZIP subsystem (A) is what packages a
customer's selection on demand.

**Magento representation**: downloadable file/document attached to the
Simple Product — **not** media-gallery images (they are `.dxf`/`.stp`/
`.step`, not renderable). Distinct from dimension drawings, which are
images and belong in the gallery under a custom role.

## E2. `dtb_specification.type` — RESOLVED (§8), and it is vestigial

`app/Customize/Entity/Specification.php` declares
`TYPE_NONE=0 / TYPE_ITEM=1 / TYPE_PRODUCT=2` and exposes
`getType()`/`setType()`. However:

- **Production data: all 360 rows have `type = 0`** (`TYPE_NONE`).
- The admin form does not bind this field. `ItemController` works
  exclusively with `specification_type_item_ids` and
  `specification_type_product_ids` (lines 210, 230, 352, 372, 419, 739-740,
  946-949), and those write **`dtb_item_specification.type`**.
- No repository, controller, service, Twig template or filter query in
  `app/Customize/` reads `Specification::getType()`.

**Conclusion**: `dtb_specification.type` is a **declared-but-unused
vestigial column**. Scope is carried solely by
`dtb_item_specification.type` per (item, specification) pair. There is no
"type 0 vs type 1" behavioural distinction at the specification-definition
level to model in Magento.

**Recommended Magento attribute type is therefore driven by option
structure, not by `type`**: every specification with options →
`select`; the ~5 multi-value specifications → see E3.

## E3. Multi-value specifications — recommendation unchanged, now with §7's required table

| spec_id | Name | Values/product | Affected products | Rendering | Order significance | Proposed Magento |
|---|---|---|---|---|---|---|
| 9 | ICF | 2 | 136 | Model List shows first only; both stored | `sort_no` 0/1 ordered, **no source-defined semantic name** | `multiselect` + positional table |
| 11 | VF | 2 | 66 | same | same | same |
| 12 | VG | 2 | 66 | same | same | same |
| 10 | NW/KF | 2 | 58 | same | same | same |
| 27 | D | 2-3 | 3 | same | same | same |

Per §7's explicit instruction: **no "Position 1 / Position 2" semantic
labels are introduced**, because no source term was found. `sort_no` is
preserved as an ordinal only. Filters treat the values independently (the
filter query has no positional constraint), which `multiselect` reproduces
correctly.

## E4. Attribute-set strategy — final recommendation (§20)

Given D1 (whitelist rejected) and §5 (permissive sets required):

1. **How many sets?** 8 — one per verified top-level category.
2. **Names?** Feedthrough, Vacuum Component, Isolator, Vacuum Valve,
   Motion Feedthrough, Others, Limited, Viewport.
3. **Which specifications per set?** The **union** of every specification
   used by any item/product in that category tree.
4. **Overlaps?** Expected and harmless — Magento attributes are global;
   sets only select which apply. Measured overlap is documented in
   Round 4's analysis.
5. **The 89 ambiguous cases?** See E5.
6. **Specifications not in a set?** They remain in the global attribute
   pool, unused. No data loss — a product needing one would indicate the
   set union was computed too narrowly, which the analysis command will
   surface.
7. **New EC-CUBE specifications?** Created as global attributes on sync
   and added to the union of any set whose products use them.
8. **Family gains a specification?** Sync adds the attribute to that
   set; existing products are unaffected.

## E5. The 89 ambiguous cases — recommendation, with the caveat stated

`Item::$CategoryItems` is `@ORM\OrderBy({"sort_no" = "DESC"})`. This is a
**real source ordering**, but I must be precise about what it does and
does not prove: EC-CUBE **never selects a single category** — the product
page renders all breadcrumb paths (4 visible in the supplied screenshot).
So this ordering is *not* a source-confirmed "primary category" rule; it
is the source's own deterministic ordering, repurposed.

**Recommendation**: use `dtb_category_item.sort_no DESC`, then lowest
`category_id` as a stable secondary tie-break. Classified
**SOURCE-DERIVED TIE-BREAK**, not SOURCE-CONFIRMED. Because sets are
permissive unions, a "wrong" choice among two overlapping sets is
low-impact — the product still gets a set containing its attributes.

**This remains the one genuine business decision** (§26L): whether that
tie-break is acceptable, or whether Cosmotec has a product-family concept
not represented in the database.

## E6. Updated media matrix (supersedes A5/B7 counts)

| Source | Owner | Rows | Magento target |
|---|---|---|---|
| `product_upload_file` | Product | 30,876 | Media gallery |
| `dimension_upload_file` | Product | 26,417 | Media gallery, role `dimension_drawing` |
| `cad3d_upload_file` | Product | **10,655** | Downloadable document |
| `cad2d_upload_file` | Product | **1,420** | Downloadable document |
| `item_upload_file` | Item | 1,093 | Grouped Product media |
| `category_upload_file` | Category | 325 | Category image |
| `catalog_upload_file` | Item | 286 | Grouped Product document |
| `dtb_upload_cad_zip_file` | Customer | ~8,622 | **OUT OF SCOPE** |
| `dtb_product_image` | — | **0** | **Not migrated** |


---

# ADDENDUM F — Child Product Media, CAD & Documents (Round 16)

## F1. §21 Document Name / Reference Link — TRACED, and it is NOT what the screenshot implies

The admin screen shows four flat fields (`Document Name (1)`,
`Reference Link (1)`, `Document Name (2)`, `Reference Link (2)`). The
instruction's default recommendation was four EAV attributes. **The
source does not support that model.**

Searched: `dtb_product` columns (no `document_name*`/`reference_link*`),
then the whole schema, then `app/Customize/` for the Japanese label
資料名/参考リンク. Found:

```
app/Customize/Entity/ProductReference.php        (資料名 = document name)
app/Customize/Form/Type/Admin/ProductReferenceType.php
app/Customize/Controller/Admin/Product/ProductController.php:188,219,435
    // 資料名・資料リンクの紐づけ（最大2つ）  = "link document name/link (max 2)"
```

Backing table (`DATABASE-CONFIRMED`):

```
dtb_product_reference:
    id, product_id -> dtb_product, creator_id,
    name  varchar(8),      -- document name
    link  varchar(255),    -- reference URL
    create_date, update_date, discriminator_type
```

**25,586 real rows.**

**This is a 1:N child table, not two paired columns on the product.** The
"max 2" is an *admin form constraint* (see the controller comment), not a
schema constraint.

**Consequence — CORRECTED recommendation**: do **not** create
`document_name_1/2` + `reference_link_1/2` as four flat EAV attributes.
That would hard-code an admin-form limit into the data model and would
silently truncate any product that ever has a third reference. Use a
**relation table** (`eccube_product_reference_map`) mirroring the source
1:N shape, exposed to the frontend via extension attributes.

`cad_unavailable_check` **is** a real `dtb_product` column
(`tinyint(1) NOT NULL DEFAULT 0`) and is already carried by the module's
existing `Api/Data/ProductInterface::isCadUnavailable()`. It is the one
field of the five that genuinely belongs as a boolean EAV attribute.

## F2. §12 Exhaustive UploadFile relation inventory — two more found

Searched every `@ORM\JoinTable` in `app/Customize/Entity/`:

| Relation | Owner entity | Rows | Catalog? |
|---|---|---|---|
| `product_upload_file` | Product | 30,876 | ✔ in scope |
| `dimension_upload_file` | Product | 26,417 | ✔ in scope |
| `cad3d_upload_file` | Product | 10,655 | ✔ in scope |
| `cad2d_upload_file` | Product | 1,420 | ✔ in scope |
| `item_upload_file` | Item | 1,093 | ✔ in scope |
| `category_upload_file` | Category | 325 | ✔ in scope |
| `catalog_upload_file` | Item | 286 | ✔ in scope |
| **`contact_upload_file`** | **Contact** (customer inquiry) | **1,341** | ✘ **OUT OF SCOPE — newly found** |
| **`designated_slip_upload_file`** | **Order** | **197** | ✘ **OUT OF SCOPE — newly found** |
| `dtb_upload_cad_zip_file` | Customer/session | ~8,622 | ✘ out of scope |

**CORRECTED**: earlier inventories listed 5, then 7 catalog relations.
The complete UploadFile relation set is **9 join tables plus the ZIP
table**; 7 are catalog-owned, 2 (contact/order) are transactional data
belonging to a customer-service and an order context respectively. This
also partly explains the 13,628 "orphaned" upload files — 1,538 of them
are attached to contacts and order slips, not orphaned at all.

Note: `category_upload_file` has **no `@ORM\JoinTable` declaration in
`app/Customize/Entity/`** yet contains 325 rows — it is presumably
declared in EC-CUBE core or via a trait not covered by this grep. Its
existence and ownership are `DATABASE-CONFIRMED`; the declaration site is
`NOT-TRACED`.

## F3. Child-product data model (§1, §4, §5)

```
Magento Simple Product
    ├── EAV metadata
    │     └── cad_not_available   <- dtb_product.cad_unavailable_check  (boolean)
    ├── Relation table
    │     └── product references  <- dtb_product_reference (1:N, 25,586 rows)
    └── Media/document relations (eccube_media_map)
          ├── product    -> gallery (image/small_image/thumbnail)
          ├── dimension  -> role dimension_drawing (never a main-image role)
          ├── cad2d      -> downloadable document (never an image role)
          └── cad3d      -> downloadable document (never an image role)
```

**No binary content in EAV** (§4/§22) — files stay in the media/document
relation layer; only metadata becomes EAV.

## F4. Final media ownership matrix (§16) — reflects F2

| EC-CUBE relation | Owner | Magento target | Type | Max/owner |
|---|---|---|---|---|
| `product_upload_file` | Product | Simple Product | gallery image | 8 |
| `dimension_upload_file` | Product | Simple Product | `dimension_drawing` role | 2 (admin says 1) |
| `cad2d_upload_file` | Product | Simple Product | CAD document | 1 |
| `cad3d_upload_file` | Product | Simple Product | CAD document | 1 |
| `item_upload_file` | Item | Grouped Product | parent image | 2 |
| `catalog_upload_file` | Item | Grouped Product | catalog document | 1 |
| `category_upload_file` | Category | Magento Category | category image | 2 |
| `contact_upload_file` | Contact | — | **OUT OF SCOPE** | — |
| `designated_slip_upload_file` | Order | — | **OUT OF SCOPE** | — |
| `dtb_upload_cad_zip_file` | Customer | — | **OUT OF SCOPE** | — |
| `dtb_product_image` | — | — | **0 rows, not migrated** | — |

## F5. Media classification (§11) — relation decides ownership, extension decides handling

Extension maps to `media_class` (IMAGE / DOCUMENT / CAD / ARCHIVE /
OTHER), but **ownership and Magento role come from the relation table,
never from the extension**. A PDF arriving via `cad2d_upload_file` is a
CAD-tab document on the Simple Product; the same PDF via
`catalog_upload_file` is a Grouped Product catalog document.

## F6. Validation rules (§20)

| relation_type | Required validation |
|---|---|
| product, dimension | must be a valid image |
| cad2d, cad3d | must be a readable file; dxf/stp/step/pdf accepted — **must not be rejected for not being an image** |
| item | valid image |
| catalog | image or document |
| category | valid image |

## F7. Not implemented in this round

Per §30 no production import was run, and per the effort available this
round I did **not** write the media-pipeline rework code
(`ImageReader`/`ImageRepository`/`ImageMapper`/`ImageValidator`/
`ImageImporter` → relation-aware), the `eccube_media_map` /
`eccube_product_reference_map` schema, the `analyze:media` command, or
the §29 test suite. The design above is complete and source-verified;
the implementation is the next step, not a completed one. Claiming
otherwise would misrepresent the state.
