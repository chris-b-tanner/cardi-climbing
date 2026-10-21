# Discounts & promotions — architecture spec (not yet implemented)

## Goal

Let admins run price promotions (percentage or fixed-amount off) across memberships, drop-in
credits, event tickets, stock and services, without bending the existing `Product` /
`SalesOrder` / `SalesOrderRow` / `Payment` pipeline out of shape.

This is a proposal to review, not a build plan to follow blindly — see **Open items** at the end
for the judgment calls that need an answer first.

Gift vouchers are a separate feature with their own spec — see `gift-vouchers-spec.md`.

## Why this is separate from gift vouchers

It's tempting to treat "discount code" and "gift voucher" as the same feature with two names.
They're not, and conflating them causes real bugs (mainly around VAT and revenue reporting):

- **A discount/promotion reduces the price of the goods.** It changes what's owed, and therefore
  the VAT base and how much revenue the sale recognises. A 20% early-bird ticket discount means
  the ticket genuinely cost 20% less.
- **A gift voucher is a payment method** — it changes *how* an already-priced order gets paid
  for, not what anything costs.

Discounts are resolved into `chargedPrice` when a row is created; vouchers act later, at order
completion, on whatever `order.getTotal()` already is. The two never touch the same field, so
this spec can be built and shipped without any voucher design decisions being made.

## The pricing model as it stands today (for reference)

Nothing here changes — this is the pipeline discounts sit alongside:

- `Product` holds one canonical `price` + `vatCode`. Type-specific data lives in a 1:1 extension
  (`StockProduct`, `CreditProduct`, `MembershipProduct`, `EventTicketProduct`) — no extension
  needed for `TYPE_SERVICE`.
- `SalesOrderRow` already snapshots **three** price-relevant fields per line: `listPriceAtSale`
  (what the product's price was), `chargedPrice` (what was actually charged, inc VAT — the
  doc-comment already says *"may differ from listPriceAtSale (discount, adjustment)"*), and
  `vatCodeAtSale`. **The schema already anticipated this exact feature** — a discount just needs
  a principled way to set `chargedPrice` lower than `listPriceAtSale`, plus a record of *why*.
- Two independent places build rows today: `CartService::checkout()` (public self-serve) and
  `AdminSalesController::addRow()` / `addRemainingRecurringRows()` (staff POS). Both currently do
  `chargedPrice = product.getPrice()` unconditionally. Both need to call the same pricing
  resolution — see "Where this plugs in" below.
- `SalesOrder::getTotal()` just sums `row.chargedPrice * row.qty` — no order-level discount or
  shipping field exists, and none is needed if discounts are resolved into `chargedPrice` before
  the row is ever persisted.
- There's already an ad-hoc, manual escape hatch: `AdminSalesController`'s row price-edit action
  lets an admin set any `chargedPrice` directly, but requires a free-text `note` and leaves no
  structured record of *why*. This should keep working (staff still need to comp things
  case-by-case) — the new system is additive, not a replacement for it.
- Precedent for "a running count with an audit trail" already exists: `CreditLedgerEntry`
  (drop-in credit balance) and `InventoryMovement` (stock levels). `DiscountRedemption` below
  follows the same append-only idea.

## New entities

```
Discount
  id
  code               varchar(50) NULL, unique when set   -- NULL = automatic/blanket, no code needed
  name               varchar(150)                         -- admin-facing label, e.g. "Early bird — autumn term"
  description        text NULL                            -- shown to the member at checkout
  type               varchar(20)                           -- 'percentage' | 'fixed_amount'
  value              decimal(8,2)                          -- 0–100 for percentage, £ for fixed_amount
  appliesToProductType   varchar(20) NULL                  -- one of Product::TYPE_*, or NULL = any type
  appliesToProduct       int NULL FK -> product.id         -- one specific product, or NULL = whole type
  requiresMembershipType int NULL FK -> membership_type.id -- e.g. "existing members only" gating on the BUYER, not the product
  requiresTag            int NULL FK -> tag.id             -- audience targeting, e.g. "volunteer" tag gets 10% off
  validFrom          date NULL
  validUntil         date NULL
  maxRedemptions          int NULL                         -- total cap across everyone, NULL = unlimited
  maxRedemptionsPerMember int NULL                          -- e.g. 1 for "first booking only"
  minOrderValue      decimal(8,2) NULL                     -- optional order-level threshold to unlock it
  isActive           bool default true                     -- quick kill switch, independent of the date range
  createdAt, createdBy

DiscountRedemption
  id
  discount           int FK -> discount.id
  user               int FK -> user.id                     -- the order's payer, for per-member limits
  salesOrder         int FK -> sales_order.id
  salesOrderRow       int FK -> sales_order_row.id
  amountOff          decimal(8,2)                          -- actually knocked off this row, inc VAT
  createdAt
```

`SalesOrderRow` gets two new nullable columns, sitting alongside the existing snapshot fields:

```sql
ALTER TABLE sales_order_row
  ADD COLUMN discount_id INT NULL,
  ADD COLUMN discount_amount DECIMAL(8,2) NULL,
  ADD CONSTRAINT FK_row_discount FOREIGN KEY (discount_id) REFERENCES discount(id) ON DELETE SET NULL;
```

Why both a `Discount` definition and a `DiscountRedemption` ledger, rather than just the FK on
the row: usage-limit enforcement (`maxRedemptions`, `maxRedemptionsPerMember`) needs to count
across every row and order a discount has ever touched — exactly the kind of query
`CreditLedgerEntry` already exists to answer for credit balances. The FK + amount on the row
itself is just the fast-path "what actually happened to this line" snapshot, same as
`vatCodeAtSale`.

## Scope, deliberately kept simple for v1

A discount targets **one line at a time** — a specific product, a whole product type, or (via
`requiresTag`/`requiresMembershipType`) a specific audience — never "£5 off the whole basket
regardless of what's in it". Every real scenario raised (early-bird tickets, a membership renewal
discount, a bulk credit deal, a volunteer discount) is naturally a per-line rule anyway, and this
keeps `chargedPrice`/VAT semantics exactly as crisp as they are today. Whole-basket discounts are
listed under Open items, not MVP.

Stacking is deliberately **one discount per line, best value wins** — if a line matches more
than one automatic discount, or an automatic discount and an entered code both apply, the engine
picks whichever gives the biggest `amountOff` rather than combining them. Confirm this matches
expectations; see Open items.

## Where this plugs in

A single new service — `DiscountEngine` (or similar) — is the one place pricing logic lives:

```php
// Called once per line, wherever a SalesOrderRow is about to be created —
// CartService::checkout() and AdminSalesController::addRow()/addRemainingRecurringRows() both
// call this instead of setting chargedPrice = product.getPrice() directly.
resolveForLine(Product $product, User $beneficiary, User $payer, ?string $enteredCode): ?ResolvedDiscount
// ResolvedDiscount = { discount: Discount, amountOff: string, chargedPrice: string }
```

Both call sites currently duplicate the "build a `SalesOrderRow`" block independently (a
pre-existing duplication, not something this feature introduces) — worth factoring that block
into one shared helper while wiring this in, so a future third checkout path can't forget to call
the discount engine. Not mandatory, but the natural moment to do it.

An entered code is validated against the specific line it's being applied to (product/type match,
audience match, date window, remaining redemptions) — a code that doesn't fit anything in the
cart just shows "That code doesn't apply to anything in your basket" rather than a generic error.

## Per product type

- **Membership**: discount targets the `MembershipProduct`'s `Product` row directly (e.g. "20% off
  Family membership"). `Membership.price` still snapshots what was actually paid — no change
  needed there, it already stores a price independent of the product's current list price.
- **Credit**: same mechanism against a `CreditProduct`'s `Product` — e.g. "buy the 10-credit pack
  for the price of 8" is just a fixed-amount discount on that one product.
- **Event ticket**: `EventTicketProduct` already has its own automatic segment pricing
  (`membershipType` gating a cheaper ticket product for members) — that's a *different, already-
  solved* problem (which product to show) and shouldn't be reimplemented as a discount. A
  `Discount` here targets a specific ticket `Product` for early-bird/promo pricing on top of
  whichever ticket variant was already selected.
- **Stock**: targets a specific `StockProduct`'s `Product`, or `appliesToProductType = 'stock'`
  for a storewide clearance sale.
- **Service**: same as stock — no beneficiary-specific nuance beyond what already exists.
- **Gift voucher** (only once that feature exists): **never discounted**. `DiscountEngine`
  always returns no discount for a `TYPE_GIFT_VOUCHER` line, even under a blanket
  `appliesToProductType = NULL` discount, and the admin form doesn't offer gift voucher as a
  target type/product. Otherwise a discounted £50 voucher still redeems for £50. See
  `gift-vouchers-spec.md`.

## Admin UX sketch

- `/admin/settings/discounts` — new settings page alongside Tags/Certifications/Products,
  ROLE_ADMIN only (matching those). List + create/edit form; redemption count and remaining cap
  shown per discount, linking to the (filtered) sales list for "who's used this".
- Admin POS (`admin/sales/show.html.twig`): a code field next to the existing "Add product"
  picker, applying to whichever row it matches; the existing note-required manual price override
  stays exactly as-is as the case-by-case fallback.

## Public UX sketch

- Cart page: a "Discount code" field above the total, with inline apply/remove and a running
  note of what's been applied and why (`-£8.00 — EARLYBIRD25`).

## Suggested build order

1. `Discount` + `DiscountRedemption` + the two new `SalesOrderRow` columns. `DiscountEngine`
   service. Wire into `CartService::checkout()` and the admin POS row-adding paths. Admin CRUD UI.
   Cart code-entry UI.
2. Later, optional: whole-basket discounts, stacking beyond "one per line, best wins".

## Open items / decisions needed before building

- Confirm "one discount per line, best value wins" (no stacking) is acceptable, and that
  per-line-only scope (no whole-basket £-off) covers the real scenarios in mind.
- Who can create/edit discount codes — ROLE_ADMIN only (matching Products/Tags/Certifications
  settings), or also ROLE_TEAM?
