# Migration Assumptions — Open Questions

These need a decision before Milestone 4 (Attributes) implementation
starts, because auto-creating ~379 EAV attributes and their option values
against a live Magento instance is expensive to undo if the wrong model
is chosen. Flagging now rather than guessing and rebuilding later.

## 1. `dtb_specification.type` — RESOLVED (was: select vs. numeric split)

**Status: closed.** Traced against actual source
(`app/Customize/Entity/Specification.php`,
`app/Customize/Entity/ProductSpecificationClass.php`) rather than left as
an assumption. `type` is `TYPE_NONE=0` / `TYPE_ITEM=1` / `TYPE_PRODUCT=2`
— it marks **which entity level (parent Item vs. child Product) a
specification applies to**, not select-vs-numeric. Separately confirmed:
`ProductSpecificationClass` (the actual value-assignment table) has no
free-value column at all — every value is a link to a
`SpecificationClass` option row. **Every specification is a `select`
attribute; no numeric/decimal attribute type is needed for this data.**
The original select-vs-numeric split proposed below is retracted. Full
detail, including a previously-unknown cascading specification-dependency
rule discovered during this trace, is in
`ECCUBE_SPECIFICATION_ARCHITECTURE.md`.

## 1a. Specification cardinality / multi-value — REOPENED AND CORRECTED

**Status: the previous "take first value" decision is RETRACTED.**

Multi-value specification assignments are **source-confirmed business
data and must not be discarded**. Full analysis of all 329 cases
(`ATTRIBUTE_MIGRATION_PLAN.md`, "SetSpecificationRule and Multi-Connector
Data Model") found:

- The two values are **different in 329 of 329 cases** — never duplicates.
- They are created deliberately by an admin rule (`SetSpecificationRule`,
  e.g. `2 => [9,9]` = "a pair of ICF flanges").
- They are stored with distinguishing `sort_no` (0 and 1) — position is
  recorded in the source.
- They represent **adapters/reducers** — components with a different
  flange size at each end (ICF 152→70, NW/KF 16→25, etc.).

**EC-CUBE Model List displaying only the first value does not prove that
additional stored values are disposable.** That `break` is a
presentation-layer limitation (one column per specification cannot render
two values); the data layer deliberately holds both, and the filter query
matches on either.

Taking the first value would erase the defining characteristic of 329
conversion products. Current recommendation: custom structured value
table + `multiselect` for the ~5 affected specifications. Pending
confirmation.

## 1b. Category filter eligibility source — RESOLVED (exact SQL traced)

**Status: closed.** `ProductRepositoryExtend::findProductIdsByOriginSpecificationClassIds()`
confirms storefront category filters are gated by
**`dtb_item_specification.selectable`**, not `dtb_specification_group`
(which is only a display-grouping label — just 2 groups exist site-wide,
confirmed too coarse to be the filter mechanism). See
`ECCUBE_FORENSIC_DATA_FLOW_ANALYSIS.md` §2. This determines Magento's
`is_filterable` flag per attribute/item combination when Attribute
migration is built — no open question remains here.

## 2. Attribute Set grouping rule — RESOLVED empirically, Round 4

**Status: closed**, with an explicit caveat stated below. EC-CUBE has
**no explicit Attribute Set equivalent** — no entity or table groups
specifications as a reusable unit. But real data across all 1,073 items
with specification links (`ECCUBE_FORENSIC_DATA_FLOW_ANALYSIS.md` §R4.1)
shows a genuine pattern: 479 distinct specification signatures overall
(too fragmented for 1:1 attribute sets), but items within the same
category consistently share a **common core** of specifications with
individual variation layered on top (e.g. category 124's 13 distinct
signatures are nearly all supersets of the same 3-specification core).

**Decision**: one Magento Attribute Set per **top-level** category
(~7-12 sets, matching the primary navigation — Feedthrough, Isolator,
Viewport, Vacuum Component, Vacuum Valve, Motion Feedthroughs, Others —
**SCREENSHOT-OBSERVED**, not independently re-verified against
`dtb_category.hierarchy` this round), each containing the union of every
specification used by any item in that category tree. Leaf-category-level
granularity (287 categories) was tested and rejected — too fine-grained,
matching the concern the original prompt raised. Products not populating
every attribute in their set is normal Magento behavior, not a design
flaw — this doesn't require every product to have a value for every
attribute in its set.

**Caveat, stated plainly**: this is `DATABASE-CONFIRMED` as a real
pattern in the data, not `SOURCE-CONFIRMED` as an explicit EC-CUBE
mechanism, because no such mechanism exists to confirm. The exact
top-level category boundary (which categories count as "top-level" for
this purpose) still needs to be verified against `dtb_category.hierarchy`
values before implementation — flagged as a small remaining task, not a
new open question.

## 3. Coupling products (`dtb_coupling_product`) target representation — inference now source-confirmed

Confirmed via `app/Customize/Controller/ProductController.php` (see
`ECCUBE_SPECIFICATION_ARCHITECTURE.md` §4): Item → specific Product,
rendered as the storefront's "Connection parts" section, filtered to
display-visible items/products only. Does **not** map to Magento's
built-in Related/Upsell/Cross-sell (conventionally same-product-type,
though not schema-enforced).

**Question**: should this become (a) a custom Magento product link type,
(b) an association similar to Grouped Product associated products but
semantically distinct ("accessory" rather than "component"), or (c) a
simple custom attribute on the parent listing linked SKUs?

**Assumption if not answered**: build as a distinct link type
(`accessory`/`connection_part`) rather than overloading an existing
Magento link type, to preserve the semantic distinction the prompt
explicitly asks for (§17: "do not blindly map all relationships to
Magento 'related'").

## 4. Product url_key generation

No source SEO/URL data exists in EC-CUBE (confirmed). Products need a
Magento url_key generated, not migrated.

**Assumption**: mirror `CategoryMapper`'s existing slugify approach
(English name → slug, numeric fallback on collision), applied per-SKU.
No open question here, stated for completeness since it's still new work.

## 5. Batching strategy for ~1.14M attribute-value writes

**Question**: is there a maximum acceptable import window (e.g. must
complete overnight), which would determine whether standard batched
`ProductRepository::save()` calls (grouped by product, still one Magento
product save per product but with ALL its attribute values set at once —
~27,900 saves instead of ~1.14M) are sufficient, or whether direct
bulk-insert into EAV value tables (bypassing `ProductRepository`, with the
associated risk/complexity the prompt is otherwise cautious about — §11:
"Avoid direct EAV table manipulation unless technically necessary and
documented") becomes justified.

**Assumption if not answered**: default to grouped-by-product batched
`ProductRepository::save()` (one save per product carrying all its
attribute values, not one save per value) — matches the prompt's
preference for using Magento APIs/services over direct EAV manipulation,
and reduces the write volume from ~1.14M to ~27,900 regardless.


## 6. Item additional information (tabs) — CORRECTED, new

**Previous claim (WRONG, retracted)**: "product detail page tabs beyond
Model List are static Twig HTML, not migratable data."

**Correct**: tab titles and HTML bodies come from
`dtb_item_additional_information` (555 populated rows, 89 distinct tab
names, 554 containing HTML, 307 with images, 485 with links). This is
real Item-level content that MUST be migrated. See
`SPECIFICATION_MAGENTO_DATA_MODEL.md` §5-6.

Key constraint: the table has `name` + `name_en` but **no `value_en`** —
tab *titles* are bilingual, tab *content* is Japanese-only HTML.
`NEEDS_REVIEW`: translation policy.

Second constraint: tab identity is **not a fixed enum**. The five tab
names given in the instructions are just the most frequent of 89 admin-
authored values. The migration must be generic over tab names.

## 7. Locale mechanism — RESOLVED (was NOT-TRACED for three rounds)

`src/Eccube/Resource/functions/locale.php`: `locale_en()` returns true
when the `ECCUBE_LOCALE` env var equals `"en"`, set via
`putenv_locale($locale)`. Entities expose `getNameWithLocale()` which
returns the `_en` column when English. This confirms the module's
existing English-first field selection is correct.

## 8. Image size variants — RESOLVED (was "external infrastructure")

`TwigFormExtension::getS3Url($path, $packageName, $size)` rewrites the
filename into `{original}_{prefix}-{w}-{h}-{unique}` using
`S3Constant::THUMBNAIL_SIZE_MAP` when S3 is available. The `_c-`/`_i-`/
`_p-` variants are generated by EC-CUBE itself, not by a CDN. Does not
change the migration plan (Magento regenerates its own variants from the
original), but the earlier "inference from absence" is now replaced by a
positive source finding.


## 9. Image ownership — CORRECTED (Round 9)

**Previous conclusion (WRONG)**: "Images belong to Product (child) only;
no Category or Item image entity exists anywhere in the codebase."

**Corrected**: ownership is expressed through five many-to-many join
tables against a shared `dtb_upload_file` (72,625 rows), not through
per-owner entity classes:

    product_upload_file    30,876   child product gallery
    dimension_upload_file  26,417   dimension/technical drawings (child)
    item_upload_file        1,093   parent item image
    category_upload_file      325   category image
    catalog_upload_file       286   catalog tab image (parent)

The earlier conclusion came from looking for `*Image` **entity classes**
and finding only `ProductImage`. That was the wrong search: the real
model uses join tables to a generic `UploadFile`.

## 10. `dtb_product_image` — CORRECTED, and it affects shipped code

**Previous assumption**: `dtb_product_image` is the product image source
(Milestone 6 was built entirely on it).

**Corrected**: that table has **zero rows** in the production database.
The shipped `ImageImporter` would import nothing. See
`SPECIFICATION_MAGENTO_DATA_MODEL.md` §A1 and the reclassification in
`MIGRATION_GAPS.md`.


## 11. Media model — full correction record (Round 10, §20)

Historical conclusions retained and explicitly superseded:

| Round | Previous conclusion | Status |
|---|---|---|
| M6 | "`dtb_product_image` is the product image source" | **CORRECTED** — 0 production rows |
| R3 | "Images belong to Product only; no Category/Item image entity exists" | **CORRECTED** — 5 owner relationships exist |
| R3 | "Image variants likely generated by external CDN" | **CORRECTED** — `S3Constant` + `getS3Url()` |
| R9 | "Catalog may have multiple files; `[0]` may lose data" | **RESOLVED** — max 1 file per item (286/286), no loss |
| R9 | "Same file may be shared by multiple owners (duplication risk)" | **RESOLVED** — 0 shared files in this dataset |

New source-confirmed facts: physical path is
`html/upload/save_image/{file_name}` with no hashed subdirectories;
`dtb_upload_file` is a **general document repository** (jpg/png/pdf/zip/
xlsx/pptx/docx/dxf/stp/step), not image-only; 13,628 orphaned files exist
(≈12,099 CAD ZIPs) belonging to a separate download subsystem —
classified, not discarded, scope `NEEDS_REVIEW`.
