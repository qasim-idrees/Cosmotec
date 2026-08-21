# Attribute Migration Plan

Pre-implementation plan required before any EAV creation. **Every number
below is computed from the real production dump**, not estimated. Two
corrections to earlier rounds surfaced while producing this and are
flagged explicitly in §0.

## 0. Corrections to earlier findings (flagged, not silently changed)

**0.1 — `dtb_specification.type` is 0 for ALL 360 specifications.**
DATABASE-CONFIRMED. Earlier rounds described `type` (TYPE_NONE=0 /
TYPE_ITEM=1 / TYPE_PRODUCT=2) as marking parent-vs-child scope. The
constants exist on the `Specification` entity, but **the column on
`dtb_specification` is never populated with anything but 0** in real
data. The field that actually carries ITEM/PRODUCT scope is
**`dtb_item_specification.type`** — 5,754 rows with type=1 (ITEM) and
7,099 with type=2 (PRODUCT). This is consistent with the Round 3 source
trace (which read `getItemSpecificationsTypeProduct()` — an *ItemSpecification*
accessor, not a Specification one), so the source finding was right; the
earlier prose attributing `type` to the wrong table was imprecise. **The
practical consequence is important**: scope is per (item, specification)
pair, not a global property of a specification.

**0.2 — 80 specifications are used at BOTH scopes.** DATABASE-CONFIRMED.
149 specs appear at ITEM scope, 250 at PRODUCT scope, **80 in both**
(depending on which item references them). A specification is therefore
**not** globally "a parent attribute" or "a child attribute" — the same
Magento attribute may legitimately need to carry a value on a Grouped
Product for one item and on a Simple Product for another. The Magento
design must assign such attributes to **both** the parent and child
attribute sets, which is safe and normal in Magento.

**0.3 — actual counts differ from the "~379 / ~7,541" figures used in
earlier rounds** (those were AUTO_INCREMENT ceilings, i.e. highest ever
ID including deleted rows, not live counts).


## 0.4 — MAJOR: the 18 "zero-option" specifications are NOT invalid data

**SOURCE-CONFIRMED + DATABASE-CONFIRMED.** Cross-referencing the 18
zero-option specifications against
`app/Customize/Model/SetSpecificationRule::SET_SPECIFICATION_RELATION`
shows **14 of the 18 are exactly that rule's trigger keys**:

    IDs 1-8    (Flange ICF/NW/VF/VG, "1 piece" and "2 pieces" variants)
    IDs 334-339 (ISO-K, ISO-F, type — same 1-piece/2-piece pattern)

These have zero options *by design*: they are not value holders. They
control **which other specifications apply and how many times**. The
earlier recommendation to skip zero-option specs as "unusable" would have
silently destroyed real business logic. They are now classified
`NEEDS_REVIEW`, never `SKIP_INVALID`.

The remaining 4 zero-option specs (273 "ZVF", 274 "FB", 323, 333) are not
rule keys and appear genuinely unused — classified `SKIP_INVALID`.

## 0.5 — MAJOR: this also explains the 329 multi-value cases

The rule maps "2 pieces" triggers to a **duplicated** target:

    2 => [9, 9]      // Flange ICF (2 pieces) -> specification 9 TWICE
    4 => [10, 10]    // Flange NW (2 pieces)  -> specification 10 TWICE
    6 => [11, 11]    // VF
    8 => [12, 12]    // VG

The Round 2 multi-value specifications were **9, 10, 11, 12, and 27** —
precisely these rule targets. So the 329 "anomalies" are not data errors
at all: **a product with two physical connectors legitimately records two
values of the same dimension specification, one per connector.**

**This materially changes the multi-value recommendation.** Taking only
the first value (EC-CUBE's Model List display behavior) would discard the
second connector's real dimension. The correct statement of the situation:

- EC-CUBE's **Model List rendering** shows only the first value
  (SOURCE-CONFIRMED, Round 3).
- EC-CUBE's **data** intentionally holds both (SOURCE-CONFIRMED here).

These are different things, and the earlier plan conflated them.
**Recommendation is now `NEEDS_REVIEW`, not a settled decision**: options
include (a) `select` + first value + log (matches display, loses data),
(b) `multiselect` for the ~5 affected specifications (preserves data,
diverges from EC-CUBE display), or (c) paired attributes such as
`eccube_spec_9_1` / `eccube_spec_9_2` (preserves per-connector semantics
most faithfully, most work). This needs an explicit business decision —
it is not a technical coin-flip, since it determines whether real
connector data survives migration.


## SetSpecificationRule and Multi-Connector Data Model

**This section supersedes the earlier "take first value" recommendation
entirely. That recommendation was wrong and would have destroyed data.**

### A. What SetSpecificationRule actually is — SOURCE-CONFIRMED

`app/Customize/Model/SetSpecificationRule.php`, consumed via
`SpecificationRuleInterface` by `app/Customize/Model/SpecificationStructure.php`,
called from `app/Customize/Controller/Admin/Product/ItemController.php`.

It is an **admin-side authoring helper**, not a frontend/display concern.
Three methods:

- `decideIncludableSpecifications($spec)` — when an admin selects a
  trigger, returns the specifications to ADD. For `2 => [9,9]` this
  returns specification 9 **twice**, and `SpecificationStructure::includeSpecification()`
  then calls `createItemSpecification()` once per entry, creating **two
  separate `dtb_item_specification` rows for the same specification**.
- `decideExcludableSpecifications($spec)` — the inverse (removes all
  related rows together).
- `decideSelectableSpecifications()` — hides already-configured and
  trigger specifications from the admin's pick list.

Trigger IDs are also **explicitly excluded from the normal specification
list query**: `SpecificationRepository::getList()` line 112 applies
`notIn('sp.id', array_keys(SET_SPECIFICATION_RELATION))` unless the caller
opts in. This is direct source proof that **triggers are not
customer-facing specifications** — they exist only so an admin can say
"this product has two ICF flanges" in one click instead of adding two rows
by hand.

**Answers to the specific questions asked:**
- Key = the trigger specification ID (e.g. 2 = "Flange ICF (2 pieces)").
- Array value = the specification IDs to instantiate, **with repetition
  meaning "instantiate this many times"**.
- `2 => [9,9]` = "an ICF flange pair: record specification 9 (ICF size)
  twice, once per flange."
- IDs 1-8 = ICF/NW/VF/VG in 1-piece and 2-piece variants; 334-339 =
  ISO-K, ISO-F, and "type" in the same 1/2-piece pattern.
- They have no options because they are **not value holders**.
- Used during **product/item authoring in admin**; excluded from frontend
  listing, filtering, and Model List by the `getList()` exclusion.

### B. Positional meaning — DATABASE-CONFIRMED (not inferred)

The two rows created per pair are **not identical duplicates**. In real
data, every duplicated `(item_id, specification_id)` pair carries
`sort_no = 0` and `sort_no = 1`:

    (item 4000, spec 10) -> [(row 152390, sort_no 0, type 2, selectable 1),
                             (row 152391, sort_no 1, type 2, selectable 1)]

31 such item/specification pairs exist. `sort_no` provides an explicit,
stored ordering — **position 1 and position 2 are distinguishable in the
source data**, which is exactly what a positional Magento representation
needs.

### C. The 329 cases — full analysis, no sampling

All 329 multi-value (product, specification) pairs analysed; 329 distinct
products affected.

| Spec | English | Affected products | Values per product |
|---|---|---|---|
| 9 | ICF | 136 | 2 |
| 11 | VF | 66 | 2 |
| 12 | VG | 66 | 2 |
| 10 | NW/KF | 58 | 2 |
| 27 | D | 3 | 2-3 |

**The decisive finding: in 0 of 329 cases are the two values identical.**
Real examples:

    product 25565  ICF:    152/70  |  34
    product 25251  ICF:    86      |  203
    product 22087  NW/KF:  16      |  25
    product 24073  NW/KF:  50      |  80
    product 24914  VF:     65      |  20
    product 19091  D:      50 | 38.1 | 133

These are **adapters / reducers / conversion flanges** — a component with
a different flange size at each end (e.g. NW/KF 16 on one side, 25 on the
other). This is consistent with the catalog itself, which sells
"Various Vacuum Components (Standard Conversion, Size Conversion etc)" and
"Zero-Length Conversion Flange" (**SCREENSHOT-OBSERVED** in the supplied
category navigation).

**Therefore taking only the first value would not lose a minor detail —
it would erase the defining characteristic of 329 conversion products,
making a 152→70 reducer indistinguishable from a plain 152 flange.**

### D. Why Model List shows only one value — RESOLVED as a display limitation

The `break`-on-first-match in `ProductController::detail()` is a
**presentation-layer limitation**, not evidence the second value is
disposable. Proof: the second value is (a) deliberately created by the
admin rule, (b) stored with an explicit distinguishing `sort_no`, and
(c) always different from the first. The Model List table has one column
per specification and simply cannot render two values in one cell.
Question §8 of the request is answered: **A/B (UI displays one
representative value / does not support multiple), not "the data is
junk."**

### E. Filter behaviour — DATABASE-CONFIRMED by query shape

`findProductIdsByOriginSpecificationClassIds()` matches on
`dtb_product_specification_class` rows via `IN (...)` with no positional
constraint, so a product with ICF 152 **and** ICF 34 matches a filter on
either value — it appears under **both**. Any Magento representation must
preserve that (a positional split must still allow filtering on either
position).

### F. Recommended Magento representation

**Recommend Option E (custom structured data) combined with a
filter-facing attribute**, and explicitly *not* Option A:

- **Reject Option A (`select`, first value)** — destroys the defining
  data of 329 products, per §C.
- **Reject Option C (paired attributes) as the primary mechanism** — it
  would require `eccube_spec_9_1`/`_2` for every specification that could
  ever be doubled, and the ~5 affected specifications are exactly those
  most used elsewhere; it doubles attribute count for a 0.17% case.
- **`multiselect` (Option B) for the ~5 affected specifications** gives
  correct filter behaviour (matches both values, matching §E) and no data
  loss, at the cost of losing which value is "position 1". Acceptable
  *only if* combined with:
- **A custom structured table** (`eccube_product_specification_value`
  with `product_id, specification_id, position, option_id`) preserving
  the source exactly, including `sort_no` position. This is the only
  option that loses nothing, and it fits the module's existing mapping-table
  architecture rather than requiring new patterns.

**Net recommendation**: migrate values into a custom structured table
(faithful, lossless, reusable for a future Model List renderer), AND
expose the ~5 affected specifications as Magento `multiselect` attributes
for layered navigation, with all other specifications remaining `select`.

**This remains a recommendation, not a decision** — it changes the
attribute type for 5 specifications and adds one table, so it should be
confirmed before implementation.

### G. Unresolved

Whether position 1/position 2 carries a *named* physical meaning
(e.g. "vacuum side" vs "atmosphere side") rather than just an order, is
**NOT-TRACED** — `sort_no` proves order exists, but no source evidence was
found labelling the positions. If Cosmotec can confirm the convention,
Option C (named paired attributes) becomes more attractive than
`multiselect`.

## 1-6. Verified totals (DATABASE-CONFIRMED)

| Metric | Real count |
|---|---|
| Total specifications (`dtb_specification`) | **360** |
| — with at least one option | 342 |
| — with zero options | 18 |
| — never referenced by any `dtb_item_specification` | 41 |
| Total specification options (`dtb_specification_class`) | **7,364** |
| Item↔specification assignments (`dtb_item_specification`) | **12,853** (type=ITEM 5,754 / type=PRODUCT 7,099) |
| — with `selectable=1` (filterable) | **4,700** (36.6%) |
| Parent values (`dtb_item_specification_class`) | ~5,754 |
| Child values (`dtb_product_specification_class`) | **193,473** |
| Items with specifications | 1,073 |
| Distinct TYPE_PRODUCT signatures across items | 479 |

**Note on the 1.14M figure** used in earlier rounds for
`dtb_product_specification_class`: the actual parsed row count is
**193,473**, not 1.14M (again, the earlier number was the AUTO_INCREMENT
ceiling). This materially reduces the performance risk — the write
workload is roughly 6x smaller than previously planned for. The batching
strategy in §20 still applies, but the urgency is lower.

## 7. Proposed Magento Attribute Sets (top-level categories VERIFIED)

Verified from `dtb_category`: `hierarchy=1` and `parent_category_id IS NULL`
agree exactly — **8 top-level categories**, no ambiguity.

| Magento Attribute Set | Cat ID | Descendant cats | Items | Specs (total) | ITEM / PRODUCT | Selectable | Options |
|---|---|---|---|---|---|---|---|
| Feedthrough | 1 | 77 | 277 | 116 | 52 / 95 | 42 | 4,754 |
| Vacuum Component | 4 | 182 | 696 | 193 | 82 / 144 | 72 | 6,305 |
| Isolator | 2 | 8 | 12 | 31 | 9 / 24 | 8 | 2,522 |
| Vacuum Valve | 5 | 12 | 21 | 66 | 38 / 32 | 11 | 4,170 |
| Motion Feedthrough | 383 | 3 | 2 | 23 | 5 / 18 | 7 | 1,185 |
| Others | 7 | 17 | 60 | 84 | 38 / 68 | 24 | 3,047 |
| Limited | 241 | 1 | 6 | 28 | 19 / 10 | 7 | 412 |
| Viewport | 3 | 24 | 72 | 76 | 37 / 51 | 21 | 3,865 |

**Important caveat, DATABASE-CONFIRMED**: the per-set spec counts sum to
more than 360 because **items can belong to multiple top-level category
trees** (e.g. items 190-197 appear under both Vacuum Component and
Viewport). Attribute sets will therefore share many attributes — expected
and fine in Magento (attributes are global; sets just select which ones
apply). It does mean "which set does this product get?" needs a
tie-break rule when an item spans trees: **recommend lowest `sort_no`
top-level category wins**, deterministic and stable.

## 8-9. Specification → Attribute / Option mapping

- `dtb_specification` → one Magento product EAV attribute each (**360**).
- `dtb_specification_class` → attribute options (**7,364** total).
Final classification (produced by `cosmotec:eccube:analyze:attributes`):

| Classification | Count | Meaning |
|---|---|---|
| `CREATE` | **319** | Referenced by an item and has options — create the Magento attribute. |
| `SKIP_UNUSED` | **23** | Has options but no item references it (e.g. 146 "Tightening torque"). Skipped, with reason logged. |
| `SKIP_INVALID` | **4** | No options and no references and not a rule trigger (273, 274, 323, 333). |
| `NEEDS_REVIEW` | **14** | SetSpecificationRule triggers — see §0.4. Must not be auto-created or auto-skipped. |

Every skipped specification carries a recorded reason, surfaced in the
analyze command output — nothing is dropped silently.

## 10. Filterability mapping

`is_filterable` cannot be a simple per-attribute boolean, because
`selectable` is per **(item, specification)** pair, not per specification
(4,700 of 12,853 assignments are selectable). Two options:

- **(a) Union rule** (recommended): mark a Magento attribute filterable
  if **any** item marks it selectable. Simple, safe, matches Magento's
  global-attribute model. Downside: an attribute filterable for one
  product family shows in layered navigation for others too — mitigated
  because Magento only surfaces filters with matching values in the
  current category anyway.
- **(b) Per-attribute-set control**: closer to source semantics but
  Magento does not natively scope `is_filterable` per attribute set —
  would need custom layered-navigation logic. **Not recommended** for
  the initial migration.

Recommend (a), with the per-item `selectable` data preserved in the
mapping table so (b) remains possible later without re-migrating.

## 11-12. Parent vs child attribute values

- Parent (Grouped Product) values ← `dtb_item_specification_class`
  (~5,754 rows), for assignments where `dtb_item_specification.type = 1`.
- Child (Simple Product) values ← `dtb_product_specification_class`
  (193,473 rows), for assignments where `type = 2`.
- Per §0.2, an attribute may be assigned to **both** parent and child
  attribute sets.

## 13. English/Japanese — English-first is fully achievable

**DATABASE-CONFIRMED, and better than expected**: 360/360 specifications
and 7,364/7,364 options have a non-empty English name. **Zero Japanese
fallback is required** for attribute labels or option labels. The
English-first rule can be applied unconditionally here. (Source IDs are
retained in mapping tables regardless, so fallback remains correctable.)

## 14. Multi-value behavior

Unchanged from Round 3 (SOURCE-CONFIRMED): all attributes `select`; where
a product has multiple values for one specification (329 pairs, 0.17%),
take the **first** — matching EC-CUBE's own `break`-on-first-match Model
List behavior — and **log every dropped value** to `eccube_sync_history`
so nothing vanishes silently.

## 15-19. Model List / Basic Information / filters / relationships

All data-driven from the migrated structure, per Rounds 3-4 findings. No
hardcoded column lists. Related Products (Product→Product, child-level)
and Connection Parts (Item→Product, parent-level) get **separate** Magento
link types.

## 20. Performance strategy

Group by product: collect all of a product's attribute values, then one
`ProductRepository::save()` per product. That reduces ~193,473 value
writes to **~27,900 product saves** at most. Batched, resumable,
idempotent via content-hash (same pattern already proven in the existing
`InventoryImporter`). No direct EAV table manipulation.

## 21-22. Idempotency & sync

New mapping tables (`eccube_specification_map`,
`eccube_specification_option_map`, `eccube_attribute_set_map`) following
the exact existing trio pattern (Model/ResourceModel/Collection +
repository), enabling detect-existing / no-duplicate / no-overwrite
behavior, `--dry-run`, and Sync-extends-Importer reuse.

## Attribute code strategy (§5 of the request)

**`eccube_spec_{id}`** — e.g. `eccube_spec_9`, `eccube_spec_27`.
Deterministic, stable across runs, lowercase, valid characters, unique,
and **independent of labels** (so an English label edit never orphans an
attribute). The human-readable English name goes in the frontend label,
which can change freely without breaking the mapping.


## Round 11 — EAV boundary and trigger classification

### What must NOT become EAV (§22)

| Source data | Correct Magento representation |
|---|---|
| Product/item/category/dimension/catalog images | Magento media + `eccube_media_map` |
| Dimension drawings | Media with a dedicated role — **never** hundreds of EAV attributes (§3) |
| Catalog files | Document relationship on the Grouped Product (§4) |
| `dtb_item_additional_information` HTML | Generic Grouped-Product content table (§14) |
| Connection Parts / Related Products | Magento link types |
| Source→Magento mappings | Custom mapping tables |

EAV is used **only** for genuine product/category attributes: the
specifications classified `CREATE`.

### Specification classification (§8) — four buckets, none silent

| Bucket | Count | Meaning |
|---|---|---|
| CUSTOMER-FACING SPECIFICATION | 319 | `CREATE` — becomes a Magento attribute |
| ADMIN TRIGGER / DEPENDENCY | 14 | `SetSpecificationRule` keys. **No Magento attribute.** The dependency rule itself (`1 => [9]`, `2 => [9,9]`, …) is documented for a future admin/frontend implementation and is **not deleted**. |
| UNUSED | 23 | Options exist but no item references them |
| NEEDS REVIEW | 4 | Zero options, zero references, not a trigger |

### Scope rule (§6) — reaffirmed

Scope comes from `dtb_item_specification.type` per (item, specification)
pair, never `dtb_specification.type` (0 for all 360). 80 specifications
appear at **both** scopes and must be assigned to both the Grouped and
Simple attribute sets.

### English-first (§13) — precise statement

Attributes/options: **English-complete** (360/360, 7,364/7,364) — no
Japanese fallback required. Additional-information bodies: **Japanese
only** (no `value_en` column). The migration is therefore *not*
uniformly English-complete, and this must not be claimed.
