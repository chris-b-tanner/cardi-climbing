# Asset register spec — built

## Goal

Keep a register of the things the centre owns and uses but doesn't sell: holds and volumes,
auto-belays, mats, hire harnesses and shoes, setting tools, drills, furniture, IT. For each, record
what it is, where it is, what it cost, what it would cost to replace, and what happened to it in
the end. The main uses:

- **Insurance:** a current list with replacement values, by category and location, to agree cover
  and to back up a claim.
- **Knowing what we've got:** "how many large volumes do we have and where are they?", "when did
  we buy the drills and from whom?".
- **A history:** what was bought, donated, moved, broken and thrown away, and by whom.

Sellable stock stays in `StockProduct` and `InventoryMovement` and is not part of this register.

This is a proposal to review, not a build plan to follow blindly. The decisions made so far are
marked "(decided)", and **Out of scope** near the end lists what's deliberately left out.

## Approach in one paragraph

One new entity, `Asset`, records either a single item (an auto-belay with a serial number) or a
batch of identical things counted together (a set of 20 crimps). Assets can be added by hand,
which covers everything the centre already owns and anything donated, or created when a purchase
order is received (see `purchase-orders-spec.md`). An asset's history is kept as notes, using the
existing `Note` system, so there's no separate event log to build. Values are net of VAT and only
admins can change them, following the same rule as sales and purchase prices.

## Concepts

- **Asset**: one register entry. Either an **individual item** (`quantity` = 1, usually with a
  serial or asset tag) or a **batch** (`quantity` > 1, counted together, e.g. "Rockcity Jugs set,
  blue, 20 pcs").
- **Acquisition**: how it arrived: purchased (through a PO, or before the system existed), donated,
  or other.
- **Disposal**: how it left the register: worn out, broken, lost, sold, given away. Disposed assets
  keep their history but drop out of the current register and the insurance totals.

## Data model

### `Asset`

| Field | Type | Notes |
|---|---|---|
| `name` | string(150) | "Auto-belay 3", "Large volumes, set A" |
| `category` | string(30) | Constant list: `holds_volumes`, `wall_equipment` (auto-belays, mats, belay devices), `hire_kit` (harnesses, shoes, helmets for hire), `setting_tools`, `tools_equipment` (drills, ladders, cleaning machines), `furniture_fittings`, `it_electronics`, `building`, `fire_safety`, `electrical`, `first_aid`, `other`. The last four before `other` are mainly for things that are safety checked but not bought kit, like the building or the fire alarm system. |
| `description` | ?text | |
| `quantity` | int | 1 for an individual item. A batch's count, reduced on partial disposal. |
| `identifier` | ?string(100) | Serial number or our own asset tag |
| `location` | ?string(100) | "Main wall", "Setting store", "Reception" |
| `status` | string(20) | `in_use` / `in_storage` / `out_of_service` / `disposed` |
| `acquisitionType` | string(20) | `purchased` / `donated` / `other` |
| `acquiredAt` | ?date | Null if not known (older kit) |
| `supplier` | ?ManyToOne `User` | A contact with `isSupplier` set (see `purchase-orders-spec.md`) |
| `purchaseOrderLine` | ?ManyToOne `PurchaseOrderLine` | Set when created from a PO receipt |
| `purchaseCost` | ?decimal(10,2) | Net total actually paid for this entry (not per unit). Null for donated or unknown. |
| `replacementValue` | ?decimal(10,2) | Net total to replace it today, for insurance. Defaults to `purchaseCost`. |
| `replacementValueUpdatedAt` | ?date | So old valuations stand out |
| `warrantyUntil` | ?date | |
| `disposedAt` | ?date | |
| `disposalReason` | ?string(20) | `worn_out` / `broken` / `lost_stolen` / `sold` / `given_away` / `other` |
| `disposalNote` | ?string(255) | Required on disposal |
| `createdAt`, `createdBy` | | |

Values are totals for the entry, not per unit, so a batch of 20 holds bought for £180 has
`purchaseCost` = 180.00. The UI shows the per-unit figure alongside when `quantity` > 1.

### `AssetFile`

| Field | Type | Notes |
|---|---|---|
| `asset` | ManyToOne | |
| `key` | string | S3 key. Stored privately, downloaded through presigned URLs, the same way as `UserFileUploader` |
| `filename`, `contentType`, `size` | | |
| `kind` | string(20) | `photo` / `receipt` / `manual` / `warranty` / `other` |
| `uploadedAt`, `uploadedBy` | | |

Photos matter for insurance claims and for telling sets of holds apart.

## History through notes

- Add `Note::TYPE_ASSET = 'asset'` as a sixth noteable type pointing at `Asset`. Every place that
  lists the noteable types needs the new case:
  - `Note::setNoteable()`
  - `NoteableResolver` (label `"{asset name} — {location}"`, URL of the asset page)
  - The `TYPE_SINGULAR` and `TYPE_ENTITY_NAME` maps in `NoteAssignmentMailer` and
    `NoteMentionMailer`
  - `AdminNoteController::TYPES`, `findNoteable()` and `redirectForNoteable()`
  - `LastActivityListener` (no person behind an asset note, so return null)
  - The type groups on the Actions page

  `purchase-orders-spec.md` adds `TYPE_PURCHASE_ORDER` the same way; if both are built, do the
  note changes together. Safety check tasks also go on the asset as `TYPE_ASSET` notes, so the
  safety spec needs no type of its own.
- The system adds a note (with `addedBy = null`, like other system notes) whenever something
  significant changes, so the notes panel doubles as the asset's history:
  - Created ("Added from PO-0012 receipt" / "Added by hand" / "Donated by …")
  - Status or location changed ("Moved from Main wall to Setting store")
  - Values changed (admin only, with the old and new value)
  - Partly or fully disposed (reason, quantity and note)
- Anyone on the team can add ordinary notes and tasks ("3 holds cracked, check the rest of the
  set").

## Adding assets

### By hand

A "New asset" form for everything already owned before the system, donations, and anything bought
outside a PO. All fields above, plus optional photos.

For a first stocktake there'll be a lot of these. The form has a **Save and add another** button
that keeps the category, location and acquisition type filled in. There's no spreadsheet import.

### From a purchase order

On the PO "Receive delivery" form, each **non-stock** line gets an **Add to asset register**
checkbox:

- Ticked by default for the PO categories that are usually assets (`holds_volumes`, `equipment`,
  `ppe`, `setting_tools`), unticked for consumables (`cleaning`, `maintenance`, `office`, `other`).
  Whoever receives it can change it either way.
- When ticked, saving the receipt creates one `Asset` for the quantity received, with the line's
  description as the name, a category mapped from the PO category (`holds_volumes` →
  `holds_volumes`, `equipment` → `wall_equipment`, `ppe` → `hire_kit`, `setting_tools` →
  `setting_tools`; anything else → `other`, editable afterwards), the supplier, the PO line,
  `acquiredAt` = the receipt date, `purchaseCost` = quantity × the line's net unit cost, and status
  `in_use`.
- A **Track individually** option on the same line creates one asset per unit instead (two
  auto-belays → two assets of quantity 1), so each can have its own serial number. The receipt
  then links to the asset pages to fill in the serials.
- A part delivery creates assets only for what arrived; the rest are added when it does.

Stock lines never create assets: those are for resale and are tracked as stock.

## Safety checks (decided: one register)

There's no separate safety register. Any asset can be set up for safety checks by adding one or
more check schedules to it, and the safety page lists the assets that have them. The schedules,
check records, task notes, failed-check handling and the dashboard are all in
`safety-checks-spec.md`. From the asset register's side:

- The asset page shows a **Safety checks** section when the asset has schedules, and admins can
  add the first schedule from any asset page.
- The register gets a "Safety checked" filter and a badge on assets with schedules.
- Things that need checking but aren't bought kit (the building, the fire alarm system, the
  electrics) are entered as assets with no cost, using the `building`, `fire_safety`,
  `electrical` and `first_aid` categories.
- `out_of_service` means the same thing in both specs. A failed check sets it automatically. For
  a safety-checked asset, changing the status back to `in_use` by hand is admin-only, so a failed
  check can't be cleared without either a passing check or an admin.
- Disposing of an asset deactivates its schedules and closes any open check tasks. Its inspection
  history is kept.

## Disposal

- **Full disposal**: sets `status` to `disposed`, with the date, reason and a required note. The
  asset stays in the register's history but leaves the current list and the insurance totals.
- **Partial disposal of a batch** ("4 of these 20 holds are broken"): reduces `quantity` and scales
  `purchaseCost` and `replacementValue` down in proportion, then records a system note with the
  quantity, reason and note. When the quantity reaches zero, the asset is disposed.
- If something's sold, the money is taken through the POS as an ordinary sale (a service product,
  or a price override by an admin) and the disposal note gives the order number. The asset
  register doesn't record income.

## Admin UI

- **Nav:** an "Assets" link in `admin/base.html.twig` near Purchasing.
- **`/admin/assets`** (register): the current assets, filterable by category, location and status,
  searchable by name, identifier and description. Totals of quantity, purchase cost and
  replacement value for whatever's filtered. A toggle to include disposed assets.
- **Asset page:** details and status, photos and files, the PO and supplier it came from (linked),
  quick actions for **Move** (location), **Change status** and **Dispose**, and the notes panel
  (`noteableType: 'asset'`).
- **Insurance report:** current assets grouped by category and by location, with replacement
  values and the date each was last valued. Print view and CSV export.
- **PO page:** lines show the assets created from them.
- **Supplier contact page:** lists assets bought from that supplier.

## Permissions

Following the rule that only admins change money:

- **ROLE_TEAM:** view everything, add assets by hand without values, tick "Add to asset register"
  when receiving a PO (the values come from the approved PO), move assets, change status
  (including taking something out of service, but not returning a safety-checked asset to use),
  upload photos and files, add notes, and record safety checks.
- **ROLE_ADMIN:** everything the team can do, plus set and change `purchaseCost` and
  `replacementValue`, dispose of assets (fully or partly), edit any field, and add or edit safety
  check schedules.

## Out of scope

- **Depreciation.** No depreciation schedules or book values. That's for the accounts.
- **Spreadsheet import.** The first stocktake is entered by hand, using "Save and add another".
- **Revaluation reminders.** The insurance report shows when each value was last updated, and
  nothing more.
- **A minimum value for registering.** Whoever adds an asset, or receives a PO, decides whether
  it's worth registering.
- **Lending or hire tracking.** Hire kit is recorded as assets, but who has a harness right now
  isn't tracked here.

## Build order

All four steps are built (October 2026). Step 4 came with purchase orders. The asset page links
back to the supplier and PO; the PO page doesn't yet list the assets each line created.


1. `Asset` and `AssetFile`, the register list, the asset page and the hand-entry form, with
   `TYPE_ASSET` across the note code.
2. Move, status change and disposal (including partial), with the system history notes.
3. The insurance report with print view and CSV export.
4. Creating assets from PO receipts. Needs step 4 of `purchase-orders-spec.md`.

Safety checks build on step 1 and can follow straight after it. See the build order in
`safety-checks-spec.md`.
