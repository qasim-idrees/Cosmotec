# Final Decision Gate

Round 12 deliverable. **No code changed, no data mutated** (§1).
Per §27, every question below was searched in source/database first;
only genuinely unanswerable ones are escalated.

---

## 1. Questions answered by source this round (§27)

Four items previously carried as "business decisions" turned out to be
answerable. They are now closed.

### 1.1 Semantic name for `sort_no = 0` / `1` (§12) — **NOT FOUND**

Searched: `app/Customize/` (all PHP), `dtb_item_specification` schema,
`ItemSpecification` entity, plus Japanese-term searches (一次側/二次側,
vacuum/atmosphere side, inlet/outlet, flange A/B, connector 1/2).

`dtb_item_specification` columns are: `id`, `specification_id`,
`item_id`, `creator_id`, `create_date`, `update_date`,
`discriminator_type`, `selectable`, `sort_no`, `type`,
`item_specification_class_id`. **There is no name, label, title or
position-name column, and no such string exists anywhere in the
customization layer.**

**Documented conclusion, exactly as §12 requires**: *`sort_no` is a
positional ordering mechanism, but no business-facing name for the
position was found.* Positions must therefore be migrated as ordinal
values (position 1, position 2), not as named sides. If Cosmotec staff
know a convention, it exists only in their heads — not in the system.

### 1.2 Primary/main category rule (§13) — **NO SOURCE-DEFINED PRIMARY CATEGORY RULE FOUND**, but a deterministic ordering exists

Searched: `primary_category`, `main_category`, `PrimaryCategory`,
`MainCategory`, `getMainCategory` across `app/Customize/` and
`src/Eccube/` — **zero matches**. `dtb_category_item` has no "is_primary"
flag; its columns are `id`, `category_id`, `item_id`, `sort_no`,
`discriminator_type`.

**However** — `Item::$CategoryItems` is declared
`@ORM\OrderBy({"sort_no" = "DESC"})`. So while EC-CUBE never *selects*
one category (the product page renders **all** breadcrumb paths — visibly
4 paths on the supplied screenshot), it does impose a **deterministic
order**.

**Recommendation**: if Magento must pick one attribute set, use
`dtb_category_item.sort_no DESC` (the source's own ordering) rather than
an invented rule such as "lowest category id" or "lowest top-level
sort_no" — which was the previously-rejected proposal. This is derived
from source, not invented. **Still requires approval** because choosing
*one* set is a Magento constraint EC-CUBE does not share.

### 1.3 CAD ZIP subsystem (§19) — **RESOLVED: OUT OF SCOPE, and not orphaned data**

`app/Customize/Controller/CadController.php` + `dtb_upload_cad_zip_file`:

```
dtb_upload_cad_zip_file:
    id, customer_id -> dtb_customer, file_key, file_name, create_date
```

The table is keyed to **`customer_id`, not to any product or item**.
`CadController::downloadProduct()` builds a ZIP **on demand** into
`eccube_temp_dir` via `ZipArchive`, named
`uniqid("{customerId}_product_")`, using formats
`%s_2D_3D.zip` / `%s_2D.zip` / `%s_3D.zip`.

**Therefore the ~12,099 ZIP files are per-customer generated download
artifacts, not catalog content.** They are transient user-session output.
This also explains why they are "orphaned" — they were never meant to be
attached to a product.

**Classification: `ORPHAN / OUT OF CURRENT MIGRATION SCOPE`**, with the
reason recorded (§19 requirement satisfied). Not discarded, not imported.
The *source* CAD files that feed the generator are a separate question —
`NEEDS_REVIEW` whether the Magento storefront must reproduce the "2D/3D
CAD Download" feature at all.

### 1.4 English source for Japanese HTML (§22) — **NOT FOUND**

Searched the full production schema for any translation table
(`translat*`, `*_locale`, `*_lang`) and for any `_en` **content** column
(`value_en`, `content_en`, `body_en`). The only `_en` columns in the
entire database are short-text name fields: `category_name_en`,
`description_en` (category), `name_en`, `short_name_en`.

**There is no English source for `dtb_item_additional_information.value`
anywhere in EC-CUBE.** This is now confirmed rather than assumed, so the
escalation to you is legitimate under §27.

---

## 2. Decision Matrix (§26)

| Area | Source-confirmed design | Remaining uncertainty | Recommendation | Approval? |
|---|---|---|---|---|
| Specifications | 360 specs / 7,364 options; scope via `dtb_item_specification.type`; 80 dual-scope | none | 319 `select` EAV attributes, code `eccube_spec_{id}` | **No** — proceed |
| Multi-position specs | 329 pairs, all values differ, `sort_no` 0/1, no positional name exists | Magento representation | Custom positional table (lossless) + `multiselect` on the ~5 affected specs for layered nav | **Yes** |
| Attribute sets | 8 top-level categories; 89 items span multiple trees; no primary-category field exists | Which set wins for the 89 | One set per top-level category; tie-break by `dtb_category_item.sort_no DESC` (source ordering) | **Yes** |
| Layered navigation | `selectable` is per (item, spec); Magento `is_filterable` is global | exact-fidelity filtering | UNION rule + preserve per-item `selectable` in mapping; custom logic only if exact behaviour is later required | **Yes** |
| Additional information | 555 populated rows, 89 names, HTML, no `value_en` | none technical | Custom content table on Grouped Product; **not** EAV | **No** — proceed |
| Catalog | 286 rows / 286 items, max 1 each, no variants | none | Document relationship on Grouped Product | **No** — proceed |
| Product media | `product_upload_file` 30,876 → Simple Product | none | Media gallery; first = main/small/thumbnail | **No** — proceed |
| Dimension drawings | `dimension_upload_file` 26,417 → Simple Product; rendered in Model List | Magento mechanism | **Option A** — custom media role `dimension_drawing` (see §3) | **Yes** |
| Category images | `category_upload_file` 325 → Category | none | Magento category image | **No** — proceed |
| CAD ZIPs | Per-customer generated artifacts, `customer_id`-keyed | Whether the *feature* is in scope | Out of migration scope as data; feature = separate project | **Yes** (feature scope only) |
| Japanese HTML | No English source exists anywhere | Business policy | Migrate as-is, preserve source, translate later as a content project | **Yes** |
| Connection Parts | Item→Product, dual visibility gate | none | Distinct link type on Grouped Product | **No** — proceed |
| Related Products | Product→Product, child page | none | Standard related links on Simple Product | **No** — proceed |

**Nine of thirteen areas need no approval.** Four genuinely do.

---

## 3. Dimension drawings — options compared (§18)

| Criterion | A: custom media role | B: dedicated document table | C: extension attribute |
|---|---|---|---|
| Frontend display | Native media URL/resize | Custom rendering required | Custom rendering required |
| Admin management | Visible in product media tab | Needs custom admin UI | Needs custom admin UI |
| Synchronization | Reuses media mapping | Separate sync path | Separate sync path |
| File replacement | Native | Manual | Manual |
| Deletion | Native cascade | Manual | Manual |
| Duplicate handling | Native | Manual | Manual |
| Performance | Native gallery load | Extra query per product | Extra load per product |
| Magento conventions | Standard (`image`, `small_image`, `thumbnail` are roles; custom roles are supported) | Non-standard for images | Reasonable for non-media data, not for images |

**Recommendation: Option A** — a custom media-gallery role
(`dimension_drawing`) on the Simple Product. These *are* images
(jpg/png), so Magento's media subsystem is the conventional home; a
custom role keeps them out of the normal gallery carousel while
inheriting resize, replacement, deletion and sync for free. Option B
would mean rebuilding all of that. **Not chosen for ease of coding** —
chosen because dimension drawings are genuinely media, and Magento's
role mechanism exists precisely for "an image with a specific purpose."

---

## 4. Implementation dependency graph (§28.3)

```
Forensic analysis  [COMPLETE]
        |
        v
Attribute design  [COMPLETE - blocked on multi-position approval]
        |
        v
Attribute sets  [DESIGNED - blocked on tie-break approval]
        |
        v
Parent/child attribute values  [depends on both above]
        |
        v
Relationships (Connection Parts, Related Products)  [ready]
        |
        v
Media architecture rework  [DESIGNED - blocked on dimension-role approval]
        |
        v
Additional information  [ready]
        |
        v
Catalog  [ready]
        |
        v
Synchronization  [pattern exists, extends to all above]
        |
        v
Integration testing -> Full import -> Incremental sync
```

Critical path: the three "Yes" approvals in §2 gate everything downstream.
Relationships, additional information and catalog could be implemented in
parallel *now* — they have no unresolved dependencies.

---

## 5. Final blocker list (§28.4)

### Technical blockers — none
Every technical question has a source-confirmed answer. The remaining
items are choices, not unknowns.

### Business decisions required (4)
1. Multi-position specification representation (custom table + multiselect).
2. Attribute-set tie-break — accept `dtb_category_item.sort_no DESC`?
3. Dimension drawings — accept Option A (custom media role)?
4. Japanese HTML — migrate as-is now, translate later?
   (Plus: is the CAD download *feature* in Magento scope at all?)

### Environment requirements (1)
5. **Filesystem access to `html/upload/save_image/`** — 58,997 referenced
   files. `https://static.cosmotec-co.jp/...` being public does **not**
   mean the Magento server can read the originals (§21). Must be
   confirmed, or a staging copy arranged, before any media import.

### Already resolved this round (4)
- Positional semantic naming → NOT FOUND, documented.
- Primary category rule → none exists; source ordering available.
- CAD ZIPs → per-customer artifacts, out of scope.
- English content source → confirmed absent.
