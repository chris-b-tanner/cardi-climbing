# Purchase orders spec — not yet implemented

## Goal

Give the team a structured way to buy things for the centre: stock for resale (climbing shoes,
chalk) and things the centre uses itself (holds, volumes, setting tools, cleaning supplies, a
replacement auto-belay). Someone raises an order, an admin approves it, it goes to the supplier,
and whoever unpacks the delivery records what arrived. Stock lines update stock levels on receipt,
and every order keeps a permanent record of what was bought, from whom, for how much, and who
signed it off.

This is a proposal to review, not a build plan to follow blindly. The decisions made so far are
marked "(decided)", and **Out of scope** near the end lists what's deliberately left out.

## Approach in one paragraph

A `PurchaseOrder` from a `Supplier` holds `PurchaseOrderLine`s. A line is either a **stock line**,
linked to a `StockProduct`, or a **non-stock line**: free text plus a spending category. Deliveries
are recorded as `PurchaseOrderReceipt`s against lines, so a part delivery is just a receipt for
less than the ordered quantity. For a stock line, each receipt also writes an `InventoryMovement`
(reason `purchase`, a constant that already exists but is unused) at the price actually paid. That
puts stock in exactly the way the manual "add stock" form on the products page does today, but
with the purchase it came from attached. Notes, tasks and assignment come from the existing `Note`
system, as for safety checks, so none of that is rebuilt.

## The model as it stands today (for reference)

- `StockProduct` (1:1 with `Product`) has a `costPrice` (net, ex VAT) and a `sku`. Stock on hand
  is `getCurrentStock()`, the sum of every `InventoryMovement.quantityChange`. Stock value is
  `getAssetValue()`, the sum of each movement's quantity times **its own** `netPrice`.
- `InventoryMovement` already has `REASON_PURCHASE`, but nothing uses it. Stock only comes in
  through `AdminProductController`'s add/remove form (ROLE_ADMIN, note required), which records
  `REASON_ADJUSTMENT` at the product's current `costPrice`.
- There's no supplier concept anywhere in the codebase.
- `Note` attaches to five record types (member, attendee, event, product, order) via
  `noteableType` + `noteableId`. `assets-spec.md` adds a sixth (`asset`) the same way; this adds
  another.
- PDFs are already generated with Dompdf from Twig (`CertificationPdfGenerator`), and private
  files already go to S3 with presigned download URLs (`UserFileUploader`).

## Concepts

- **Supplier**: who we buy from. "Rockcity Holds", "Tiso", "Screwfix".
- **Purchase order (PO)**: one order to one supplier, with a human-readable number
  (`PO-0001`).
- **Line**: one thing on the order, with a quantity and a net unit cost. Either a stock line
  (adds to a `StockProduct`'s stock when received) or a non-stock line (recorded as spend only).
- **Receipt**: one delivery of some quantity of a line. A line is fully received when its
  receipts add up to the ordered quantity.

## Data model

### `Supplier`

| Field | Type | Notes |
|---|---|---|
| `name` | string(150) | |
| `contactName` | ?string(150) | |
| `email` | ?string(255) | Where the PO is emailed, if it's sent from the system (see Sending) |
| `phone` | ?string(30) | Formatted with `UkPhoneFormatter` like other phone numbers |
| `website` | ?string(255) | |
| `accountReference` | ?string(100) | Our account number with them |
| `notes` | ?text | Delivery quirks, minimum order, payment terms |
| `active` | bool | Inactive suppliers drop out of the picker but keep their history |
| `createdAt`, `createdBy` | | |

### `PurchaseOrder`

| Field | Type | Notes |
|---|---|---|
| `number` | string(20), unique | `PO-0001`, assigned when the order is first saved |
| `supplier` | ManyToOne `Supplier` | |
| `status` | string(20) | See Lifecycle |
| `supplierReference` | ?string(100) | Their order or quote number |
| `expectedAt` | ?date | Expected delivery date |
| `deliveryCharge` | decimal(8,2) | Net. Default 0. Not a line, so it doesn't get received. |
| `deliveryVatCode` | string | Same VAT codes as `Product.vatCode` |
| `createdAt`, `createdBy` | | Who raised it |
| `submittedAt` | ?datetime | |
| `approvedAt`, `approvedBy` | ?datetime, ?User | |
| `orderedAt`, `orderedBy` | ?datetime, ?User | When it was actually placed with the supplier |
| `cancelledAt`, `cancelledBy` | ?datetime, ?User | |
| `cancelReason` | ?string(255) | Required to cancel |

Totals (net, VAT, gross) are calculated from the lines plus the delivery charge, the same way
`SalesOrder::getTotal()` sums its rows. They aren't stored.

### `PurchaseOrderLine`

| Field | Type | Notes |
|---|---|---|
| `purchaseOrder` | ManyToOne | |
| `stockProduct` | ?ManyToOne `StockProduct` | Set for a stock line, null for a non-stock line |
| `description` | string(255) | Pre-filled from the product name and variant for a stock line. Free text otherwise ("Large volumes set, 5 pcs"). |
| `category` | ?string(30) | Non-stock lines only. Constant list: `holds_volumes`, `equipment`, `ppe`, `setting_tools`, `cleaning`, `maintenance`, `office`, `other`. Used for spend reports. |
| `supplierCode` | ?string(100) | The supplier's part number, if different from our SKU |
| `qty` | int | Ordered quantity, at least 1 |
| `unitCost` | decimal(8,2) | Net, ex VAT. Pre-filled from `StockProduct.costPrice` for a stock line, but editable, since prices change. |
| `vatCode` | string | Same VAT codes as `Product.vatCode` |
| `position` | int | Display order |

`qtyReceived` is the sum of the line's receipts, not a stored column.

### `PurchaseOrderReceipt` (permanent)

| Field | Type | Notes |
|---|---|---|
| `line` | ManyToOne `PurchaseOrderLine` | |
| `qty` | int | Positive. Returns to the supplier aren't receipts (see Returns to supplier). |
| `receivedAt` | datetime | Defaults to now. Can be backdated, but not set in the future. |
| `receivedBy` | User | |
| `inventoryMovement` | ?OneToOne `InventoryMovement` | The stock movement this receipt created, for stock lines |
| `note` | ?string(255) | "2 arrived damaged, chasing", "Box 2 of 3" |

There's no edit or delete route for receipts, matching the safety records and the existing
ledgers. A miscount is fixed by an ordinary stock adjustment on the product page with a note,
which already exists.

### Changes to existing entities

- `InventoryMovement` gets nothing new: the receipt points at it. The movement's `reason` is
  `purchase`, `netPrice` is the line's `unitCost`, `note` is `"{PO number} — {supplier}"`, and
  `createdBy` is whoever received it. Stock value therefore reflects the price actually paid, not
  whatever `costPrice` happened to be.
- `StockProduct.costPrice` becomes a **weighted average cost**, updated on every receipt (see
  "Average costing" below).

## Lifecycle

```
draft ──submit──▶ submitted ──approve──▶ approved ──mark ordered──▶ ordered ──receive──▶ part_received ──▶ received
  │                  │  └─reject (back to draft, with a note)
  └──────────────────┴──────────── cancel (any status before the first receipt) ────▶ cancelled
```

- **draft**: being put together. Anyone on the team can edit it.
- **submitted**: waiting for an admin. The lines are locked; the person who raised it can pull it
  back to draft.
- **approved**: an admin has signed it off. It isn't with the supplier yet.
- **ordered**: placed with the supplier, by email from the system, by phone, or on their website.
  "Mark ordered" records who and when. A supplier reference can be added here.
- **part_received / received**: set automatically from the receipts. `received` once every line
  is fully received.
- **cancelled**: only before anything has been received, with a required reason.

**Reject** sends a submitted PO back to draft. The reason is recorded as a pinned note on the PO
assigned to the person who raised it, so they get the existing note assignment email.

Once a PO is approved, its lines and prices are locked. If the supplier's invoice comes in
different (a price rise, a substitution), an admin can **edit an approved PO's line cost** with a
required note, mirroring the POS price override. This is only allowed until the first receipt on
that line, because the receipt has already put the stock in at the old cost. Adding or removing lines after approval means
cancelling and raising a new PO, so nothing gets bought that nobody signed off.

## Receiving

The "Receive delivery" form on an ordered PO lists each line with its outstanding quantity,
pre-filled to receive in full. Whoever unpacks the box adjusts the quantities to what actually
arrived, adds a note if needed, and saves. In one transaction:

1. Save a `PurchaseOrderReceipt` for each line with a quantity above zero.
2. For a stock line, create the `InventoryMovement` described above, link it to the receipt, and
   update the product's average cost (below).
3. Recalculate the PO status (`part_received` or `received`).

Receiving more than was ordered is refused. If the supplier sent extra, receive the ordered
quantity and do a stock adjustment for the rest, so the PO stays a record of what was bought.

A non-stock line's receipt records that it arrived and, if "Add to asset register" is ticked on
that line, creates the asset (or one per unit). See "From a purchase order" in
`assets-spec.md`.

### Returns to supplier (decided: out of scope)

Faulty or wrong items sent back aren't modelled. For a stock item, an admin uses the existing
"remove stock" adjustment on the products page with a note (e.g. "Returned to Tiso, 2 pairs
faulty, PO-0012"). That's ROLE_ADMIN today, so a team member who packs up a return asks an admin
to record it. The adjustment goes out at the current average cost, so stock value stays
consistent. Any refund or credit note from the supplier is handled in the accounts, and can be
uploaded to the PO alongside its invoices for reference. The PO itself is left as it was: it
records what was bought and received, not what was kept.

### Average costing (decided)

`StockProduct.costPrice` is kept as a weighted average of what the stock on hand cost. On each
stock receipt, before the new movement is added:

```
onHand   = stockProduct.getCurrentStock()
newCost  = (onHand × costPrice + qtyReceived × unitCost) / (onHand + qtyReceived)
```

rounded to the penny. If `onHand` is zero or negative (stock sold before it was booked in), the
new cost is simply `unitCost`, since there's nothing sensible to average against.

Why this works with the existing code: stock comes in at the price actually paid (the receipt's
movement uses `unitCost`), and `StockFulfilmentHandler` already takes stock out at the current
`costPrice`. With `costPrice` as the average, what goes out matches what came in, so
`getAssetValue()` stays accurate apart from penny rounding. With "latest price" it would drift
every time the price changed.

Details:

- **Only receipts change the average.** The manual add/remove stock form on the products page
  keeps using the current `costPrice`, so it leaves the average unchanged. Sales don't change it
  either.
- **Admins can still edit `costPrice` by hand** on the product form (e.g. to correct an opening
  value). The next receipt averages from whatever it was set to.
- **Delivery charges aren't added to the cost.** The PO's delivery charge is reported as spend,
  not spread across the stock lines. Simpler, and the difference is small at our volumes.
- **Penny rounding.** `costPrice` is `decimal(8,2)`, so the average rounds to the penny on each
  receipt. That's a few pence of drift in stock value over time, which isn't worth widening the
  column for.
- The product page shows the cost history: each receipt's unit cost and the average after it.

## Sending to the supplier

- **PDF**: a printable PO (our details, supplier, number, lines with supplier codes, delivery,
  totals, the approver's name) rendered with Dompdf from a Twig template, the same way as
  `CertificationPdfGenerator`. Download it from the PO page whatever the supplier's ordering
  method.
- **Email**: "Email to supplier" on an approved PO sends the PDF to `Supplier.email` with a short
  covering message, via a new `PurchaseOrderMailer` following the existing mailer pattern, and
  marks the PO `ordered`. The email is logged as a note on the PO, like other system emails.
- Many orders will be placed on a supplier's website or over the phone. "Mark ordered" without
  sending covers those.

## Supplier invoices

Kept deliberately light, because paying suppliers happens in the accounts, not here:

- Upload the supplier's invoice (PDF or photo) to the PO, stored privately in S3 and downloaded
  through presigned URLs, the same way as `UserFileUploader`. More than one is allowed, for split
  deliveries.
- Record the supplier's invoice number and date with each upload.

Paying invoices isn't tracked here for now (decided). That stays in the accounts, and it can be
added later if needed.

## Note integration

- Add `Note::TYPE_PURCHASE_ORDER = 'purchase_order'` as another noteable type pointing at
  `PurchaseOrder`. It needs the same changes in every place that lists the noteable types as
  `assets-spec.md` describes for `TYPE_ASSET` (`Note::setNoteable()`, `NoteableResolver`, the
  mailer type maps, `AdminNoteController`, `LastActivityListener`, the Actions page groups). If
  both features are built, do the two together.
- **Approval task**: on submit, a pinned note "Approval needed: {PO number}, {supplier},
  £{gross}" is created on the PO with `addedBy = null` (a system note). Approving or rejecting
  completes it. Like the safety task notes, it can't be completed by hand from the Actions page,
  only by approving or rejecting.
- **Chasing**: an ordered PO past its `expectedAt` with lines outstanding shows as overdue on the
  purchasing page. It doesn't create a note on its own, to avoid adding another scheduled job.
  Anyone can add an ordinary pinned note to chase it.

## Admin UI

- **Nav:** a "Purchasing" link in `admin/base.html.twig` after Sales and Payments, with a badge
  for submitted POs awaiting approval (admins only).
- **`/admin/purchasing`** (list), with tabs using the same `.pos-tabs` pattern as the sales and
  events lists: Awaiting approval, Open (approved, ordered, part received), Received, Cancelled,
  All. Filter by supplier and date; search by PO number, supplier reference or line description.
  Overdue deliveries are highlighted.
- **New PO:** pick a supplier, then add lines. A stock line uses a product picker (the same search
  as the POS "Add product" picker, filtered to stock products) that shows current stock next to
  each product. A non-stock line is a description, category, quantity and unit cost.
- **PO page:** status and history (raised, submitted, approved, ordered, received, each with who
  and when), lines with ordered and received quantities, totals, the action buttons for the
  current status, receipts, invoices, and the notes panel (`noteableType: 'purchase_order'`).
- **Supplier pages:** under Settings, next to Products: list, create/edit, deactivate. Each
  supplier page lists its POs.
- **Product page:** a stock product shows its open POs ("8 on order, due 4 Nov") and its purchase
  history.
- **Spend report:** total spend by category, supplier and month, net and gross, with a CSV
  export. Stock spend shows as one "Stock for resale" category.
  POs aren't tagged with the grant or fund that paid for them (decided). Reporting spend back
  to funders happens outside this system.

## Permissions

Following the rule that only admins change money on sales orders:

- **ROLE_TEAM:** create and edit draft POs, submit them, receive deliveries, upload invoices, add
  notes, view everything.
- **ROLE_ADMIN:** everything the team can do, plus approve, reject, mark ordered, email to a
  supplier, cancel, edit an approved line's cost, and manage suppliers.
- **Every PO needs an admin's approval, whatever its value** (decided). There's no threshold below
  which a PO approves itself.
- **An admin can approve their own PO** (decided). To save a pointless second step, an admin
  editing their own draft gets a **Submit and approve** button that does both at once and
  records them as submitter and approver. No approval task note is created in that case, since
  there's nobody to ask.

## Out of scope

- **Low-stock reorder levels.** No reorder level on stock products and no "Low stock" list. The
  product picker on a new PO shows current stock, which is enough to decide what to order.
- **Setting up safety checks on receipt.** Receiving something that needs safety checks (an
  auto-belay, ropes) creates the asset as usual, but doesn't set up its check schedules. An admin
  adds those on the asset page (see `safety-checks-spec.md`).
- **Returns to supplier.** See Receiving.
- **Invoice payment tracking.** See Supplier invoices.
- **Grant or fund tracking.** See the spend report under Admin UI.
- **Recording what was bought as assets.** Holds, equipment and other non-stock purchases go
  in the asset register, which has its own spec: `assets-spec.md`. This spec only adds the
  "Add to asset register" option when receiving.

## Build order

1. `Supplier` entity and its settings pages.
2. `PurchaseOrder` and `PurchaseOrderLine`, numbering, the draft editor with stock and non-stock
   lines, and the list page.
3. Lifecycle: submit, approve, reject, mark ordered, cancel, with the approval task note and
   `TYPE_PURCHASE_ORDER` across the note code.
4. Receiving: `PurchaseOrderReceipt`, the `InventoryMovement` wiring, average costing, and the
   open-PO and cost history panels on the product page.
5. PO PDF and "Email to supplier".
6. Invoice uploads.
7. Spend report and CSV export.
