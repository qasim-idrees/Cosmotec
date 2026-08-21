# EC-CUBE Specification & Relationship Architecture — SOURCE-CONFIRMED

Traced directly against `app/Customize/` source (`cosmotec_source.zip`),
not screenshots or schema alone, per the continuation prompt's explicit
requirement not to guess when source code can answer. This **corrects**
an earlier wrong assumption in `MIGRATION_ASSUMPTIONS.md` — flagged
clearly rather than silently changed.

## 1. `dtb_specification.type` — CORRECTED FINDING

**Earlier assumption (wrong):** `type` distinguishes select-style specs
from numeric/dimensional specs.

**Actual meaning, confirmed in `app/Customize/Entity/Specification.php`:**

```php
const TYPE_NONE    = 0;  // 区分：指定なし        (unspecified)
const TYPE_ITEM     = 1;  // 区分：アイテム仕様     (Item-level specification)
const TYPE_PRODUCT  = 2;  // 区分：製品仕様         (Product-level specification)
```

`type` marks **which entity level a specification applies to — Item
(parent/Grouped Product) or Product (child/Simple Product)** — not
whether its value is a dropdown or a number. This directly answers the
prompt's Milestone/§9 concern ("Product Attributes are not only Parent
Attributes"): the source data itself already encodes parent-vs-child
applicability via this field. The migration must route `TYPE_ITEM`
specifications to the Magento Grouped Product and `TYPE_PRODUCT`
specifications to the Magento Simple Product, using this field directly
— not a heuristic.

## 2. Are any specifications actually numeric/free-text? NO — confirmed.

`app/Customize/Entity/ProductSpecificationClass.php` (the table that
holds the actual ~1.14M value assignments) has **no free-value column at
all**. Its only relevant columns are foreign keys:

```php
@ORM\ManyToOne(targetEntity="Customize\Entity\SpecificationClass")
@ORM\JoinColumn(name="specification_class_id", referencedColumnName="id")

@ORM\ManyToOne(targetEntity="Eccube\Entity\Product")
@ORM\JoinColumn(name="product_id", referencedColumnName="id")
```

Every specification value — including the ones that look numeric on the
storefront (dimension letters "A", "B", "D1", "Φ D2") — is a link to a
pre-defined `SpecificationClass` **option** row, not a stored number.

**Conclusion, now confirmed rather than assumed: every `dtb_specification`
should become a Magento `select` attribute**, with options sourced from
`dtb_specification_class`. There is no numeric/decimal attribute type
needed for this data. This overturns the type=0/type=1 select-vs-numeric
split proposed in the original `MIGRATION_ASSUMPTIONS.md` — that
assumption is now retracted.

## 3. A previously-unknown business rule: cascading specification

## dependency (`SetSpecificationRule`)

`app/Customize/Model/SetSpecificationRule.php` contains a hardcoded rule
table:

```php
const SET_SPECIFICATION_RELATION = [
    1 => [9],       // フランジICF1個 (Flange ICF x1) → unlocks dimension spec #9
    2 => [9, 9],    // フランジICF2個 (Flange ICF x2) → unlocks dimension spec #9 (twice, for 2 connectors)
    3 => [10],      // フランジNW1個 → unlocks spec #10
    ...
];
```

Selecting a base connector-type specification (e.g. "Flange ICF (1
piece)") **unlocks which other specifications become selectable/relevant
for that item** (e.g. the dimension spec that applies to ICF flanges
specifically). This is a **storefront UX/dependency concern**, not a data
storage concern — the underlying value assignment is still a plain
`dtb_product_specification_class` link regardless. It does **not** block
core attribute+value migration, but it does mean: if the Magento
storefront should reproduce this cascading "choosing X reveals Y" UX,
that's a distinct follow-on piece of work (candidate: Magento's
configurable-product / Swatches dependency mechanisms, or a custom
frontend enhancement) — not part of the base data migration. Documented
here so it isn't silently lost, per §39 (No Data Loss) of the prompt.

## 4. Coupling Products ("Connection Parts") — confirmed, matches original inference

`app/Customize/Controller/ProductController.php`, product detail action:

```php
// 接続部品の製品情報の取得   ("Get product information for connection parts")
$displayCouplingProducts = [];
foreach ($TargetItem->getCouplingProducts() as $CouplingProduct) {
    $CouplingProductId = $CouplingProduct->getProductId();
    $Product = $this->productRepository->find($CouplingProductId);
    $CouplingItem = $Product->getItem();
    // shown only if both the item and the specific product are display=show
    if ($Product->getDisplayStatus()->getId() == DisplayStatus::DISPLAY_SHOW
        && $CouplingItem->getDisplayStatus()->getId() == DisplayStatus::DISPLAY_SHOW) {
        $displayCouplingProducts[] = $Product;
    }
}
```

Confirms: `dtb_coupling_product` is genuinely **Item → specific Product**
(cross-family, not same-type), rendered as the "Connection parts" section
on the product detail page, filtered to only show currently-visible
items/products. This matches (does not correct) the original inference in
`MIGRATION_GAPS.md`/`MIGRATION_ASSUMPTIONS.md` — recommendation to build
it as a distinct link type rather than overloading Magento's Related
Products stands, now with source confirmation rather than inference.

## 5. Still not traced (honest scope statement)

The continuation prompt (§41-44) asks for a full trace of ~18 additional
behaviors: category filter derivation, Model List column derivation,
Model List "Filter by model" behavior, attribute-set grouping rule,
Basic Information field source, the other product tabs (Catalog,
"difference of ground and floating", Assembly method, Pressing tools,
FAQ), and image-ownership rules per context. **These were not traced in
this pass** — the two answered above were prioritized because they were
explicitly flagged as blocking (§6, "critical — do not guess") and
because auto-creating ~379 attributes on the wrong model is the most
expensive mistake to reverse. The remaining items affect *presentation*
(which attributes show as filters/columns/tabs) rather than *data
correctness* (what gets stored), so they can follow the core
attribute/option/value migration rather than block it — but they are
real, open, and listed here rather than silently skipped. See
`MILESTONE_STATUS.md` for how this affects sequencing.

## 6. Immediate revision to `MIGRATION_ASSUMPTIONS.md` Question 1

Question 1 in that document ("does `type` distinguish select from
numeric?") is **answered and closed**: no split is needed. All ~379
specifications are `select` attributes. Questions 2 (attribute-set
grouping rule) and 5 (batching strategy) remain open. Question 3
(coupling products representation) is now source-confirmed rather than
inferred — the recommendation (distinct link type) stands unchanged.


---

## Round 11 addendum — corrections index

This document's original conclusions remain valid for specification
scope and the `ProductSpecificationClass` option-only finding. Two later
conclusions elsewhere were **CORRECTED** and are recorded here so this
file is not read in isolation:

1. **"Take the first value for multi-value specifications"** — CORRECTED.
   All 329 cases hold different values (reducers/adapters with a
   different flange size at each end) and carry positional `sort_no`
   0/1. See `MIGRATION_ASSUMPTIONS.md` §1a and
   `ATTRIBUTE_MIGRATION_PLAN.md`.
2. **"Product tabs are static Twig HTML"** — CORRECTED. Tab shell is
   template-driven; tab names and HTML bodies come from
   `dtb_item_additional_information`. See
   `SPECIFICATION_MAGENTO_DATA_MODEL.md` §5-6.

Also confirmed in later rounds: the `SetSpecificationRule` cascading
dependency first noted here is an **admin authoring rule**, excluded from
customer-facing specification lists by
`SpecificationRepository::getList()`.
