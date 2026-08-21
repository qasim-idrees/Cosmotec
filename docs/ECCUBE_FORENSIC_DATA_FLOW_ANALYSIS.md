# EC-CUBE Forensic Data Flow Analysis (Round 2)

Continues `ECCUBE_SPECIFICATION_ARCHITECTURE.md`. Every finding below is
either (a) traced against actual `app/Customize/` source, or (b) computed
directly from the real production dump — never inferred from screenshots
alone. Screenshot-only inferences are labeled as such.

## 1. Specification cardinality — RESOLVED (empirically, not guessed)

**Question**: does a product ever have more than one value for the same
specification?

**Three converging sources of evidence:**

1. **Database schema**: `dtb_product_specification_class` has no unique
   constraint on `(product_id, specification_class_id)` or equivalent —
   the schema does not forbid multiples.
2. **Admin form design**: both `ProductSpecificationType.php` and
   `ItemSpecificationTypeItemType.php` use Symfony `ChoiceType` **without**
   `multiple => true` — a plain dropdown, one value per specification per
   save action. The UI is designed for single-select.
3. **Empirical data** (full scan of all 18 INSERT batches, 193,473 real
   rows — not a sample): **99.83% of (product, specification) pairs have
   exactly one value.** The remaining **0.17% (329 pairs)** have multiple
   values, concentrated in exactly 5 specifications: **ICF, NW/KF, VF, VG,
   D** — all flange-connector/dimension specs.

**Interpretation**: the intended design is single-select (`select`
attribute in Magento terms). The small number of real exceptions are
concentrated in flange-family specs and plausibly correspond to products
with two identical physical connectors (e.g. "BNC-JJ ground shield on
**both sides**" from the uploaded screenshots literally has two
connectors of the same family, each potentially needing its own recorded
size).

**Recommendation for the Attribute Mapper**: default every specification
to a Magento `select` attribute. For the 5 specifications with confirmed
multi-value data (and any others discovered at the same rate during a
full import dry-run), the Mapper must not silently drop the extra
value(s) or crash — recommend logging a warning and either (a) using
`multiselect` specifically for those 5, or (b) storing only the first
value and recording the rest as a note for manual review, with the
**decision deferred to you** since it affects visible product data for a
small but real set of products. This is not a guess dressed as certainty
— it's a real 0.17% edge case that needs an explicit decision, not a
default swallowed silently.

## 2. Category filter derivation — RESOLVED (exact SQL traced)

**Question**: where do the storefront's left-sidebar category filters
(Flange/ICF/34/70/114, Connection type, Electric current, etc., visible
in the uploaded screenshots) actually come from?

Traced to `ProductRepositoryExtend::findProductIdsByOriginSpecificationClassIds()`.
The filters are **specification-driven**, not a separate filter-definition
table. The query unions two branches:

- **Item-level branch** (`dtb_item_specification.type = 1`): filters via
  `dtb_item_specification_class` → `dtb_item_specification` → `dtb_item`
  → `dtb_product`. A match at the item level applies to **every** child
  product under that item.
- **Product-level branch** (`dtb_item_specification.type = 2`): filters
  via `dtb_specification_class` → `dtb_product_specification_class`
  directly by `product_id`.

**Critical finding**: in both branches, the query requires
`dtb_item_specification.selectable = 1`. **The `selectable` checkbox on
the admin's Item Specification form (`ItemSpecificationTypeItemType.php`)
is the actual gate for "does this specification appear as a storefront
filter" — not `dtb_specification_group`, which is only a display-grouping
label** (confirmed earlier: only 2 groups exist site-wide, "Flange" and
"Connection type" — far too coarse to be the filter-eligibility mechanism
on its own, and now confirmed it isn't).

**Migration implication**: when building Magento's filterable-attribute
flag (`is_filterable` in the Layered Navigation sense), the source of
truth is `dtb_item_specification.selectable`, evaluated per
item/specification pair — not a blanket "make everything filterable" or
"use the group."

## 3. Image ownership — CONFIRMED (matches existing module design, no change needed)

`src/Eccube/Entity/ProductImage.php`: images belong to `Product` (child/
Simple Product) only — no Category or Item image entity exists anywhere
in the codebase.

`Item::$image_file_name` exists but **has no `@ORM\Column` annotation** —
it's a transient/computed property, not a real database column (also
confirmed: no image-related column exists on `dtb_item` in the real
schema). This is a runtime "representative image" derived from a child
product, not separately stored data.

**Conclusion: the existing module's image architecture is already
correct** — `ImageImporter` attaching images only to Simple Products
(never to the Grouped Product) matches EC-CUBE's own data model exactly.
No migration gap here, no change needed.

## 4. Image resize/variant naming — not resolved this pass, noted honestly

No image-resizing service exists in `app/Customize/` — sizing (if the
uploaded screenshots' filenames imply distinct size variants) is either
handled by unmodified EC-CUBE core (`src/Eccube/`, not re-read this pass)
or by an external CDN/proxy transformation layer outside the application
entirely. **Not confirmed either way in this pass.** Practical
recommendation regardless of the answer: Magento's own media gallery
already generates its own size variants from the original upload (this is
standard Magento behavior, already relied upon by the existing
`ImageImporter`) — so this question does not block anything already
built; it would only matter if EC-CUBE pre-generates and stores
resolution-specific files that should be preserved as separate Magento
media entries instead of re-derived. Flagging as unresolved rather than
assuming either way.

## 5. English/Japanese field selection — inference only, not fully traced

The uploaded screenshots are from an `en.` subdomain
(`en_cosmotec-co_jp_...`), consistent with a locale-based storefront
split. The exact routing/locale-detection mechanism was **not traced**
this pass (would require reading `src/Eccube/` core routing plus any
`app/Customize/EventListener/` locale handling not yet searched). This
doesn't change the existing module's decision — `_en` fields are already
being imported as the primary Magento data, which is consistent with what
the English-subdomain screenshots show — but the mechanism by which
EC-CUBE itself switches between `name`/`name_en` is not yet source-confirmed,
only inferred from the screenshots' URL pattern.

## 6. Not traced this pass (honest scope statement, same caveat as before)

Model List column derivation (which columns like A/B/C/D/P.C.D. appear
per product, seen in the screenshots' "Model List" tabs), the other
product detail tabs (Catalog, "difference of ground and floating",
Assembly method, Pressing tools, FAQ), Basic Information section field
source, attribute-set grouping candidates beyond what's already discussed
in `MIGRATION_ASSUMPTIONS.md` Q2, and Related Products vs. Coupling
Products' exact frontend rendering differences. These remain open per the
prompt's own extensive list — prioritized behind the cardinality and
filter-mechanism questions above because those two directly gate whether
attribute *values* get migrated correctly, while the remaining items
mostly affect *presentation* of already-correctly-migrated data.

## Round 3 — Product/Category Presentation Data Flow

Traced primarily against `ProductController::detail()` (the single
richest source — it assembles Model List, filters, Basic Information
inputs, and Connection Parts in one place),
`SpecificationRepository::findSelectableForSearchProduct()`, and
`app/template/default/Product/detail.twig`. Every finding below is
classified: **SOURCE-CONFIRMED** (read the actual PHP/Twig),
**DATABASE-CONFIRMED** (verified against real data), **SCREENSHOT-OBSERVED**
(matches the provided screenshots but not independently traced),
**INFERRED** (reasonable conclusion, not directly proven), or
**NOT-TRACED** (genuinely unknown this round).

### R3.1 Model List — SOURCE-CONFIRMED

- **Model List = the Item's child Products** — `ProductController::detail()`
  line 792: `$TargetProducts[0]->getItem()->getItemSpecificationsTypeProduct()`.
  Confirms §5's premise exactly.
- **Column set is dynamic, not hardcoded** — columns come from whichever
  `ItemSpecification` rows exist for *this specific Item* with
  `type = ItemSpecification::TYPE_PRODUCT`. Different items legitimately
  have different columns (explains why the screenshots show different
  column sets per product family — e.g. Coaxial BNC's "NW/KF, ICF, VF,
  VG, A, B, C, D, P.C.D." vs. a different product's different column set).
- **Column label** = the specification's `getNameWithLocale()` (i.e. the
  `_en` field on the English site — confirmed, not inferred, this method
  exists on the Specification entity and is called directly).
- **Column order**: **NOT independently re-confirmed in this file**, but
  `SpecificationRepository::findSelectableForSearchProduct()` (used for
  the *filter* panel, see R3.3) orders by `specification.sort_no` — the
  same `sort_no` column almost certainly governs Model List column order
  too, since it's the only ordering field on the entity. **INFERRED**,
  not directly observed being applied to Model List columns specifically.
- **Empty value handling**: literal string `'-'` — SOURCE-CONFIRMED,
  line 799 (`$productSpecificationClassName = '-';`, only overwritten if
  a match is found).
- **Multi-value handling — the 329-pair edge case, now resolved with
  real evidence**: lines 800-805 loop the product's
  `getProductSpecificationClasses()` and `break` on the **first** match
  per specification. **EC-CUBE's own Model List rendering already
  discards every value after the first** for a product with multiple
  values on the same specification. This is decisive: Magento does not
  need to invent new behavior here — matching EC-CUBE's own actual
  behavior (take first value, `select` attribute, log/ignore the rest)
  is not a compromise, it's **reproducing the source system exactly**.
  **Recommendation: build all specifications as `select`, including the
  5 flange/dimension ones. Do not build `multiselect`.**
- **Model/SKU link + navigation**: **NOT-TRACED** this round (would need
  the Model List table's Twig fragment specifically, not yet located
  precisely within `detail.twig`'s ~250 lines skimmed).
- **Stock/price/delivery source**: **NOT-TRACED** this round for the
  Model List table specifically, though separately confirmed at the
  overall product level: `dtb_product.stock_quantity` (existing module's
  Milestone 7 finding, unchanged) and pricing via `dtb_product_class`
  price fields (visible in the uploaded screenshots' "Model List" pricing
  column, e.g. "32,100 JPY") — **SCREENSHOT-OBSERVED** for the display,
  **not independently re-traced to source** this round.
- **Only display-enabled products included**: **INFERRED** — consistent
  with the `DisplayStatus::DISPLAY_SHOW` gating seen everywhere else in
  this controller (categories, coupling products), but not specifically
  re-confirmed for the Model List product list construction in this pass.

### R3.2 Basic Information section — SOURCE-CONFIRMED (data), INFERRED (exact Twig binding)

The controller returns `specificationArray` and `setSpecificationArray`
(built at `detail()` lines 707-732): every specification for this Item
with `type = TYPE_PRODUCT` that does **not** belong to a
`specification_group` goes into `specificationArray` (flat); every one
that **does** belong to a group goes into `setSpecificationArray`, keyed
by group, with group name/name_en preserved. **SOURCE-CONFIRMED** these
two variables exist and are built this way. **INFERRED** (not
line-by-line confirmed in Twig) that these are what actually populates
the "Basic Information" table in the screenshots — no other returned
variable is a plausible candidate, but the exact Twig loop wasn't opened
to verify 1:1.

**This answers §8's core question directly**: Basic Information uses
**the same underlying specification mechanism** as Model List and
filters (`ItemSpecification` + `Specification` + `SpecificationClass`),
not a separate mechanism — the difference between "Model List column",
"Basic Information row", and "category filter" is **which consuming code
path reads the same underlying specification data**, not different
source tables.

### R3.3 Category / Model List filters ("Filter by model") — SOURCE-CONFIRMED

`SpecificationRepository::findSelectableForSearchProduct()`:

```sql
SELECT specification_group.id, specification_group.name, specification_group.name_en,
       specification.id, specification.name, specification.name_en,
       specification_class.id, specification_class.name, specification_class.name_en
FROM dtb_specification_class specification_class
INNER JOIN dtb_specification specification ON specification.id = specification_class.specification_id
LEFT JOIN dtb_specification_group specification_group ON specification_group.id = specification.specification_group_id
WHERE specification_class.id IN (<selectable ids>)
GROUP BY specification_class.id
ORDER BY specification.sort_no, specification_class.sort_no
```

- **Eligibility**: gated upstream by `selectable` (confirmed Round 2),
  feeding `$targetSelectableSpecificationClassIds` into the `WHERE ... IN`.
- **Ordering — SOURCE-CONFIRMED, resolves §7's question 8 exactly**:
  `ORDER BY specification.sort_no, specification_class.sort_no` — explicit
  custom order via a real `sort_no` column on both tables, not
  alphabetical or ID-based.
- **Nested grouping ("Flange → ICF → 34/70/114") — SOURCE-CONFIRMED,
  resolves §7's question 9**: `dtb_specification_group` **is** used here
  — as the top-level visual grouping ("Flange"), with each
  `dtb_specification` under that group ("ICF", "NW/KF", "VF", "VG")
  forming the next level, and each specification's
  `dtb_specification_class` rows ("34", "70", "114") forming the leaf
  checkboxes. **This nuances the Round 2 finding**: `specification_group`
  is not the *filter-eligibility* gate (that's still `selectable`), but
  it **is** the *visual nesting* mechanism once a specification is
  already eligible. Both things are true simultaneously.
- **Caching**: Redis-cached per `(categoryIds, productIds, onlyStockReal, itemId)`
  — an operational detail, not migration-relevant, noted for completeness.
- **AND/OR / AJAX behavior**: the detail-page route accepts both GET and
  POST (`methods={"GET", "POST"}`), and `specification_class_ids` arrives
  as a request parameter array-of-arrays (line 640-649, iterating
  `$selectedSpecificationClassList` within `$selectedSpecificationClasses`)
  — **SOURCE-CONFIRMED structure**: grouped by specification (OR within a
  group's selected classes, AND across groups, matching typical faceted
  search) — but whether the actual form submission is full-page POST or
  AJAX-partial-reload was **NOT-TRACED** (would require the frontend JS,
  not opened this round).

### ⚠️ R3.4 **CORRECTED** — see SPECIFICATION_MAGENTO_DATA_MODEL.md §5-6

**The finding below is WRONG and is retained only for traceability.**
R3.4 concluded the non-Model-List tabs were "hardcoded static Twig HTML,
not database-driven." Subsequent tracing of `detail.twig` lines 197-243
proved the opposite: the Twig contains only the static *tab shell*, while
tab **titles and full HTML bodies are database-driven** from
`dtb_item_additional_information` (`{{ ItemAdditionalinformation.nameWithLocale }}`
and `{{ ItemAdditionalinformation.value|raw }}` inside a `{% for %}` loop).
The error was reading the template's static scaffolding and stopping
there rather than following the loop variable to its entity. Also
corrected: the Catalog tab is driven by `Item.CatalogFiles` (the
`catalog_upload_file` join table), not static markup.

Likewise **R3.7 (image variant naming) is now RESOLVED**, not
"infrastructure-level/unknowable": `TwigFormExtension::getS3Url()` builds
the `_c-`/`_i-`/`_p-` sized filenames from `S3Constant::THUMBNAIL_SIZE_MAP`
via the `s3()` Twig function. And **R3.8/§L (locale mechanism) is
RESOLVED**: `src/Eccube/Resource/functions/locale.php` `locale_en()`
reads the `ECCUBE_LOCALE` env var set by `putenv_locale()`.

--- original (incorrect) text follows ---

### R3.4 Other product tabs (Catalog, ground/floating, Assembly method,

### Pressing tools, FAQ) — SOURCE-CONFIRMED (mechanism), NOT-TRACED (per-family variation)

`app/template/default/Product/detail.twig`: tabs are a **hardcoded
CSS-radio-tab pattern** (`productTabItem_1` through `_5`, fixed 5 slots)
directly in the Twig template — **not** driven by any database entity,
repository, or `app/Customize/` service. No tab-configuration table exists
anywhere in the 124-table schema, confirmed by the absence of any
plausible candidate in every table list pulled so far.

**This means tab content (Assembly method instructions, Pressing tools
info, FAQ text) is very likely static HTML authored directly in Twig**,
varying either through separate template files per product family or
conditional blocks within one template — **not migratable structured
data**. Exactly which mechanism selects which tab set per product family,
and where that per-family template content physically lives, was
**NOT-TRACED** this round (would require diffing multiple product
families' rendered tab sets against their respective template files,
which wasn't done given time constraints).

**Practical implication for Magento**: these tabs are a presentation
concern to be rebuilt as Magento CMS blocks / static content per product
type, not a data migration task — there is no EC-CUBE source data to
migrate for them beyond what's already captured (Model List, Basic
Information, connection parts already covered above).

### R3.5 Connection Parts — SOURCE-CONFIRMED, extending Round 2

Full trace of `detail()` lines 757-771 (already partially covered
Round 2, now complete):

- **Direction**: `Item::getCouplingProducts()` → each has `getProductId()`
  → resolved to a `Product` (child) via `productRepository->find()`. Item
  → Product, confirmed exact direction (not Product → Product, not
  Item → Item).
- **Visibility filter**: **both** the target Product's own
  `DisplayStatus` **and** that Product's parent Item's `DisplayStatus`
  must be `DISPLAY_SHOW` — a coupling product hidden either at its own
  level or its parent's level is excluded. **SOURCE-CONFIRMED.**
- **Ordering, duplicate handling, cross-parent reuse**: **NOT-TRACED**
  this round — the `CouplingProduct` entity's own sort/uniqueness
  behavior wasn't opened.
- **Rendering (image/SKU/price/stock in the "Connection parts" cards)**:
  **SCREENSHOT-OBSERVED** only (visible in the uploaded product-detail
  screenshot showing 11 "Connection parts" cards with images, product
  codes, models, and prices) — the Twig fragment rendering these cards
  specifically was not opened.

### R3.6 Related Products (`dtb_related_product`) — NOT-TRACED this round

Distinct from Connection Parts per Round 2's schema-level finding (real
FK structure: product_id → related_product_id, both `dtb_product`), but
**no controller/repository/Twig trace was performed this round** — only
the schema shape from Round 1/2. The uploaded screenshot's "Related
products" section (6-7 product cards, e.g. "NW16 One-touch clamp",
various cables) is **SCREENSHOT-OBSERVED** to exist and render similarly
to Connection Parts, but the actual query/controller logic populating it
was not located or read this round. **Recommendation stands unchanged
from Round 2** (keep semantically distinct from Connection Parts) but is
not yet backed by a source trace of its own retrieval logic.

### R3.7 Image variant naming (`_c-150-150-`, `_i-300-300-`, `_p-1000-1000-`,

### `_p-150-150-`) — NOT-TRACED (infrastructure-level, likely outside EC-CUBE)

- `src/Eccube/Entity/ProductImage.php` stores only a plain filename — no
  size-variant columns, no resize logic on the entity itself.
  **SOURCE-CONFIRMED.**
- `src/Eccube/Twig/Extension/EccubeExtension.php` has only a
  `getNoImageProduct()` fallback helper — no resize/thumbnail Twig
  filter or function found anywhere in EC-CUBE core's Twig extensions.
  **SOURCE-CONFIRMED (absence).**
- `app/Plugin/` (where an installed EC-CUBE plugin would add this kind of
  feature) is **empty** in the provided source tree. **SOURCE-CONFIRMED
  (absence).**
- **Conclusion**: the `_c-`/`_i-`/`_p-` naming convention is not
  generated anywhere in the EC-CUBE application code available for this
  trace. **INFERRED**: most likely generated by external infrastructure
  (a CDN or image-transformation proxy sitting in front of
  `static.cosmotec-co.jp`) entirely outside the EC-CUBE codebase — but
  this is an inference from absence, not a positive confirmation, since
  that infrastructure (if it exists) isn't part of any supplied source or
  database. **Practical implication, unaffected either way**: Magento's
  own media gallery already generates its own resized variants from the
  original upload — the existing `ImageImporter` (already built, Milestone
  6) only needs the *original* image file, which is unambiguously owned
  by the child Product. This question does not block or change anything
  already implemented.

### R3.8 English/Japanese selection mechanism — still NOT fully traced;

### narrower finding this round

`Specification::getNameWithLocale()` and `SpecificationClass::getNameWithLocale()`
are called directly in `ProductController::detail()` (lines 793, 802,
817) — confirming **SOURCE-CONFIRMED**: specification names, specification
class (option) names, and the Item's own name all go through a
`getNameWithLocale()` accessor at render time, not a hardcoded `_en`
field reference. This is a stronger and more general mechanism than
previously described — it strongly implies a **shared locale-switching
convention across every translatable entity** (`Item`, `Specification`,
`SpecificationClass`, presumably `Category`/`Product` too, consistent
with the existing module's use of their respective `_en` columns).
**The exact implementation of `getNameWithLocale()` itself (how it
decides Japanese vs. English) was not opened this round** — still
**NOT-TRACED** for the underlying locale-detection logic (subdomain vs.
session vs. request), though the *existence and consistent use* of this
pattern across entities is now source-confirmed rather than merely
inferred from the `en.` subdomain screenshots.

### R3.9 Category hierarchy labels ("Category 7Matter", "Subcategory

### 208Matter") — SCREENSHOT-OBSERVED, mechanism INFERRED

"7Matter"/"208Matter"/"9Matter" (visible in the uploaded category-page
screenshots) are almost certainly **a count suffix** ("7 items", "208
items", "9 items" — matching the visible product/subcategory counts on
each page) rather than a stored label — **INFERRED** from the pattern
(the number always matches the visible item count), not confirmed against
the category-listing controller/Twig, which was **NOT-TRACED** this
round. If correct, this confirms `dtb_category` itself needs no special
handling beyond what the existing `CategoryImporter` already does — the
"Matter" suffix is a storefront-rendering convention, not migratable
data.


## Round 4 — Final Data Model & Presentation Verification

Two genuinely new, decisive findings this round, plus one important
correction to methodology. Everything else from the requested 14
questions that couldn't be closed with real evidence in the available
time is marked `NOT-TRACED` below, not guessed.

### R4.0 Correction: `dtb_item_specification` column order

Round 3's supporting queries never used this table directly, so no prior
finding is affected, but flagging for the record: this round's initial
parse of `dtb_item_specification` assumed column order
`(id, item_id, specification_id, ...)`. The actual schema is
`(id, specification_id, item_id, creator_id, create_date, update_date,
discriminator_type, selectable, sort_no, type, item_specification_class_id)`
— `specification_id` and `item_id` were reversed. Caught before use by
re-verifying the DDL directly (the same discipline applied throughout
this analysis); the corrected parse is what R4.1 below is built on.

### R4.1 Attribute Set Strategy — DATABASE-CONFIRMED, real pattern found

Built item→category and item→specification-signature maps from the full
real dataset (12,853 `dtb_item_specification` rows, 1,073 items with
specification links). Tested the hypothesis directly against data rather
than reasoning about it further:

- **479 distinct TYPE_PRODUCT specification signatures** exist across all
  items — far too many and far too fragmented to use directly 1:1 as
  attribute sets (would violate the "no one-per-product" constraint).
- **95 of 287 categories (33%) have items that share an *identical*
  specification signature.**
- **192 of 287 categories (67%) are "mixed"** — but inspection of the
  actual signatures within mixed categories (e.g. category 124:
  `{10,24,27,60,46}`×3, `{10,24,27,60}`×2, `{10,21,24,27,46}`×3, ... )
  shows they are overwhelmingly **variations built from a shared core
  set** (`{10,24,27}` appears in nearly every signature in that category,
  with individual items adding a few extra specs on top) — not
  unrelated/random specification sets.

**Conclusion, evidence-based**: EC-CUBE does **not** have a direct,
explicit Attribute Set equivalent (confirmed — no entity or table
represents "this group of specifications belongs together as a unit").
But the data shows a genuine, real **category-level clustering pattern**:
items within a category share a common core of specifications, with
individual variation layered on top. This directly supports (with
evidence, not invention) a strategy of:

> **One Magento Attribute Set per (top-level, or a small number of
> curated) category, containing the UNION of all specifications used by
> any item in that category** — not requiring every product to populate
> every attribute in its set (Magento tolerates and expects unused/empty
> attributes in a set; this is normal, not a design flaw).

This avoids both rejected extremes (one-per-product, one-per-category
would actually be ~287 sets which is likely still too granular given
many categories are subcategories of a shared parent) — the practical
recommendation is to build attribute sets from **top-level categories**
(the ~7-12 categories visible in the primary nav: Feedthrough, Isolator,
Viewport, Vacuum Component, Vacuum Valve, Motion Feedthroughs, Others —
confirmed by the screenshots' top navigation, **SCREENSHOT-OBSERVED**,
not independently re-verified against `dtb_category.hierarchy` this
round), not the 287 leaf categories, since leaf-category-level granularity
is exactly the "too fine-grained" trap the prompt warned against.

**This is DATABASE-CONFIRMED as a real pattern**, not SOURCE-CONFIRMED as
an explicit EC-CUBE mechanism (because none exists) — the distinction
matters and is stated plainly per the classification requirement.

### R4.2 Related Products — SOURCE-CONFIRMED, resolves the outstanding gap

`app/Customize/Entity/RelatedProduct.php` + `ProductController.php` lines
901-914 (in the child-product-specific `detailAfter` action, not the
parent `detail` action):

```php
$RelatedProducts = $TargetProduct->getRelatedProducts();  // $TargetProduct is a child Product
foreach ($RelatedProducts as $RelatedProduct) {
    $RelatedProductEntity = $this->productRepository->find($RelatedProduct->getRelatedProductId());
    // gated by both the related product's own display status AND its parent item's display status
}
```

- **Relation direction, confirmed**: **Product → Product** (child-to-child),
  via `RelatedProduct.product_id` → `RelatedProduct.related_product_id`,
  both referencing `dtb_product`. Not Item→Item, not Item→Product.
- **Displayed on**: the child-product-specific detail page
  (`product_detail_after`, i.e. after a specific model/variant is
  selected) — **not** the parent Item page where Connection Parts
  appears. This is a real, structural difference between the two
  relationship types, beyond just "different source table":
  **Connection Parts is a parent-level concept; Related Products is a
  child-level concept.**
- **Visibility gating**: same dual-check pattern as Connection Parts
  (target product's own display status + its parent item's display
  status must both be `DISPLAY_SHOW`).
- **Not traced further this round**: exact ordering, duplicate-relation
  handling, and the Twig rendering of the related-products cards
  (image/price/stock display) — **NOT-TRACED**.

**Recommendation, now evidence-based**: represent Related Products as a
Magento product-link assigned **per Simple Product** (matching its
child-level scope), and Connection Parts as a distinct custom
relationship assigned **per Grouped Product** pointing at specific Simple
Products (matching its parent-level scope) — these must not share the
same Magento link type or the parent/child distinction EC-CUBE itself
maintains would be lost.

### R4.3 Representative image for category/Item listings — NOT-TRACED

`Item::setImageFileName()` exists but **is never called anywhere in
`app/Customize/`** (confirmed by an exhaustive grep across the entire
customization tree). Two possibilities, neither confirmed: (a) it's
populated by EC-CUBE core (`src/Eccube/`), not searched this round, or
(b) it's dead/unused code and the actual category-listing image comes
from a direct repository JOIN not yet located. **Honestly NOT-TRACED** —
this was flagged as important (§13 of the prompt) and deserves a real
answer, not a guess, in a follow-up pass specifically searching
`src/Eccube/Repository/` and `src/Eccube/Controller/` (EC-CUBE core, not
just Customize).

### R4.4 Remaining requested items — NOT-TRACED this round (honest scope statement)

Given the volume of this request (14 major question groups) versus
available effort, the following were **not traced with source-level
rigor** this round and should not be treated as resolved:

- Exact `getNameWithLocale()` locale-detection implementation (still only
  confirmed to exist and be called consistently — Round 3 finding,
  unchanged).
- Full per-tab content-source classification for all 6 product tabs
  (Model List and the general "tabs are hardcoded containers" finding
  stand from Round 3; a field-by-field breakdown per tab — "Tab title /
  Visibility / Content source / ..." as the prompt's template requests —
  was not produced for each of the 6 tabs individually).
- "Filter by Model" AJAX-vs-full-request mechanism and frontend JS.
- Image variant (`_c-`, `_i-`, `_p-`) generation mechanism beyond the
  Round 3 finding (still: not in `app/Customize/`, not in EC-CUBE core
  Twig extensions searched, `app/Plugin/` empty — genuinely appears to be
  external infrastructure, but this remains an inference from absence).
- Full 5-10 product-family comparison table with per-item Model
  List/filter/tab/Connection-Parts/Related-Products/image detail (the
  underlying data for the *attribute-set* part of this exercise was
  produced empirically in R4.1 across all 1,073 items, which is stronger
  evidence than a hand-picked 5-10 example table would have been — but
  the specific per-product narrative table the prompt requested was not
  separately produced).
- Model List's exact SKU-link generation and stock/price binding in the
  Twig table body (`Model` links to `product_detail_after` is a
  reasonable inference from the route name found in R4.2/Round 3, but the
  Twig fragment itself was not opened to confirm).



Two of the three highest-stakes open questions from Round 2 were closed
with real evidence, and Round 3 resolved the third:
- Attribute type: **`select`**, confirmed (not assumed).
- Filter eligibility source: **`dtb_item_specification.selectable`**,
  confirmed (not assumed) — with the Round 3 addition that
  `dtb_specification_group` still matters, as the *visual nesting*
  mechanism for filters, even though it's not the eligibility gate.
- Image architecture: **already correct**, no change needed.
- **Multi-value specification exception (329 pairs): resolved in Round 3.**
  EC-CUBE's own Model List code takes only the first matching value and
  discards the rest (`break` on first match). Matching this exactly —
  build all specifications as `select`, take the first value, log the
  rest — reproduces the source system's actual behavior rather than
  inventing new behavior. No `multiselect` needed.

## Bottom line for decision-making (updated through Round 4)

Two of the three highest-stakes open questions from Round 2 were closed
with real evidence, Round 3 resolved the third, and Round 4 resolved two
more:
- Attribute type: **`select`**, confirmed (not assumed).
- Filter eligibility source: **`dtb_item_specification.selectable`**,
  confirmed (not assumed) — with the Round 3 addition that
  `dtb_specification_group` still matters, as the *visual nesting*
  mechanism for filters, even though it's not the eligibility gate.
- Image architecture: **already correct**, no change needed.
- **Multi-value specification exception (329 pairs): resolved in Round 3.**
  EC-CUBE's own Model List code takes only the first matching value and
  discards the rest (`break` on first match). Matching this exactly —
  build all specifications as `select`, take the first value, log the
  rest — reproduces the source system's actual behavior rather than
  inventing new behavior. No `multiselect` needed.
- **Attribute Set strategy: resolved in Round 4, empirically.** No
  explicit EC-CUBE mechanism exists, but real data across all 1,073 items
  shows category-level clustering (shared specification core + per-item
  variation). Recommendation: one attribute set per top-level category
  (~7-12 sets), containing the union of that category's specifications.
- **Related Products: resolved in Round 4.** Product→Product (child-level),
  shown on the child-specific detail page — structurally distinct from
  Connection Parts (Item→Product, parent-level). Must not share a Magento
  link type.

**Genuinely still open after four rounds** (see Round 4 §R4.3-R4.4 for
detail): the representative-image-selection mechanism for category/Item
listings (would require searching EC-CUBE core, not yet done); the
precise locale-detection mechanism behind `getNameWithLocale()`; Model
List's exact SKU-link/stock/price Twig binding; per-tab content-source
classification for all 6 tabs individually; "Filter by Model" AJAX
mechanics. **None of these block starting the Attribute/Option/Value
pipeline** — every one of them affects presentation refinement or a
narrow secondary feature, not the core data model (which entities exist,
what type they are, how they're related, how cardinality works). The
data model itself is now resolved.

Round 3 additionally clarified that Model List, Basic Information, and
category filters all read from the **same underlying specification data**
(`ItemSpecification`/`Specification`/`SpecificationClass`) — they differ
in which consuming code path reads it, not in source tables. And it
identified two things that are explicitly **not** migratable data: the
product detail page's other tabs (hardcoded Twig HTML) and the image
size-variant naming convention (not generated anywhere in the EC-CUBE
application code — likely external infrastructure).


---

## Round 12 — closure of long-standing open items

| Item | Rounds open | Resolution |
|---|---|---|
| Locale mechanism behind `getNameWithLocale()` | R3-R8 | **RESOLVED (R9)** — `locale_en()` reads the `ECCUBE_LOCALE` env var. |
| Image variant naming `_c-`/`_i-`/`_p-` | R3-R9 | **RESOLVED (R9)** — `TwigFormExtension::getS3Url()` + `S3Constant::THUMBNAIL_SIZE_MAP`. Earlier "external CDN" was an inference from absence and was **CORRECTED**. |
| Product tabs source | R3-R8 | **CORRECTED (R9)** — DB-driven via `dtb_item_additional_information`, not static HTML. |
| Related Products retrieval logic | R2-R4 | **RESOLVED (R4)** — Product→Product, child page. |
| Positional meaning of `sort_no` 0/1 | R7-R12 | **NOT FOUND (R12)** — no naming exists in source; documented as ordinal-only. |
| Primary category rule | R4-R12 | **NOT FOUND (R12)** — no primary-category concept; `sort_no DESC` ordering available as a source-derived tie-break. |
| CAD ZIP / orphaned files | R10-R12 | **RESOLVED (R12)** — per-customer generated artifacts, out of scope. |

**Still genuinely not traced** (presentation-layer only, non-blocking):
Model List's exact Twig SKU-link/stock/price binding; "Filter by Model"
AJAX vs full-request mechanics; per-tab rendering when an item has more
than 3 additional-information rows (5 fixed radio slots).
