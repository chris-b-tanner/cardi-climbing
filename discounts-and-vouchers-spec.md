# Discounts, promotions & gift vouchers — architecture spec (not yet implemented)

## Goal

Let admins run price promotions (percentage or fixed-amount off) across memberships, drop-in
credits, event tickets, stock and services, plus optionally sell gift vouchers — without bending
the existing `Product` / `SalesOrder` / `SalesOrderRow` / `Payment` pipeline out of shape.

This is a proposal to review, not a build plan to follow blindly — see **Open items** at the end
for the judgment calls that need an answer first.

## Two different problems, not one

It's tempting to treat "discount code" and "gift voucher" as the same feature with two names.
They're not, and conflating them causes real bugs (mainly around VAT and revenue reporting):

- **A discount/promotion reduces the price of the goods.** It changes what's owed, and therefore
  the VAT base and how much revenue the sale recognises. A 20% early-bird ticket discount means
  the ticket genuinely cost 20% less.
- **A gift voucher is a payment method — stored value, spent later.** It doesn't change what
  anything costs; it changes *how* an already-priced order gets paid for, the same way cash or a
  drop-in credit already do. The VAT and the "real" price of the thing bought are unaffected.

Keep these as two separate subsystems that both plug into the same checkout, rather than one
"Discount" entity trying to be both. The rest of this doc treats them separately.

## The pricing model as it stands today (for reference)

Nothing here changes — this is the pipeline both new pieces sit alongside:

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
- `SalesOrderService` owns draft → complete: `completeFree()`, `completeWithCash()`,
  `startCardPayment()` (sets `Payment.amount = order.getTotal()`), `completeFromPayment()`. A
  voucher redemption hooks in here, not in the row-pricing step.
- `FulfilmentHandlerInterface` (tagged `app.fulfilment_handler`, one implementation per
  `Product::TYPE_*`) is the existing extension point for "what actually happens when a row is
  paid for". A gift voucher becomes a new product type with its own handler — no changes needed
  to the dispatch mechanism itself.
- There's already an ad-hoc, manual escape hatch: `AdminSalesController`'s row price-edit action
  lets an admin set any `chargedPrice` directly, but requires a free-text `note` and leaves no
  structured record of *why*. This should keep working (staff still need to comp things
  case-by-case) — the new system is additive, not a replacement for it.
- Precedent for "a running balance with an audit trail" already exists twice: `CreditLedgerEntry`
  (drop-in credit balance) and `InventoryMovement` (stock levels) — both append-only ledgers of
  balance changes with a `reason`, an optional link to the row/booking that caused it, a `note`,
  and `createdBy`. Gift vouchers should be a third instance of the same shape, not a new pattern.

## Part 1 — Discounts & promotions

### New entities

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

### Scope, deliberately kept simple for v1

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

### Where this plugs in

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

### Per product type

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

## Part 2 — Gift vouchers

### Sold through the existing pipeline, not a parallel one

A gift voucher is just a new `Product::TYPE_GIFT_VOUCHER`, sold via the same
`Product`/`SalesOrderRow`/`Payment`/fulfilment flow as everything else — no new purchase UI
pattern needed, it slots into the shop grid like any other product.

Fixed denominations only for v1 (e.g. separate £10 / £25 / £50 / £100 products, using
`variantName`/`variantValue` exactly like `StockProduct` size variants already do) rather than a
free-typed custom amount — that would break the "a Product has one fixed price" assumption
everything else in the codebase relies on (cart line totals, price snapshotting). Custom amounts
are an Open item, not MVP.

```
GiftVoucherProduct                       -- 1:1 extension, same pattern as CreditProduct etc.
  product            int PK/FK -> product.id
  faceValue          decimal(8,2)         -- what the voucher is worth to redeem — independent of
                                           -- whatever chargedPrice the buyer actually paid, so a
                                           -- voucher bought at a discount still gives full value

GiftVoucher
  id
  code               varchar(20) unique   -- e.g. 12-char alphanumeric, generated like MagicLinkService's tokens
  initialValue       decimal(8,2)         -- copied from faceValue at issuance
  balance            decimal(8,2)         -- decreases as redeemed
  purchasedBy        int FK -> user.id
  recipientName      varchar(150) NULL
  recipientEmail     varchar(255) NULL
  personalMessage    text NULL
  status             varchar(20)          -- active | redeemed | expired | cancelled
  issuedAt           datetime
  expiresAt          datetime NULL

GiftVoucherLedgerEntry                    -- mirrors CreditLedgerEntry exactly
  id
  giftVoucher        int FK -> gift_voucher.id
  amountChange       decimal(8,2)         -- negative on redemption
  reason             varchar(20)          -- issued | redeemed | refunded | expired | adjustment
  salesOrder         int NULL FK -> sales_order.id   -- the purchase (issued) or the spend (redeemed)
  note               varchar(255) NULL
  createdAt, createdBy
```

The voucher is **bearer, not account-bound**: whoever has the code can spend it, same as a
physical gift card — it doesn't have to be redeemed by the `recipientEmail` it was addressed to.
`GiftVoucherFulfilmentHandler::fulfil($row)` mints the `GiftVoucher` on order completion and
emails the recipient (or the buyer, if no recipient given — a self-purchase) with the code, same
mailer pattern as `WelcomeMailer`/`BookingMailer`.

### Redemption — a payment method, not a price change

At checkout (public cart and admin POS both need this), a "Have a gift voucher?" code field sits
**separately** from the discount code field — same input area is fine, but label them distinctly
so a member isn't confused about what kind of code they're holding.

On completing an order with a voucher applied:

```
amountFromVoucher = min(voucher.balance, order.getTotal())
Payment.amount     = order.getTotal() - amountFromVoucher   // only the remainder goes to Stripe/cash
```

If `amountFromVoucher` covers the order in full, skip Stripe entirely — a new
`SalesOrderService::completeWithVoucher()` mirroring `completeFree()`/`completeWithCash()`, no
`Payment` row fabricated for the voucher portion. The spend itself is recorded as a
`GiftVoucherLedgerEntry` (reason `redemption`, linked to the `SalesOrder`), not as a `Payment` —
`Payment` keeps meaning "real money moved via a real method" (cash/card/terminal), and "how much
of our revenue came in via vouchers" is answered by querying the ledger, exactly how
credit-covered free bookings already coexist with `Payment` today.

**This is also exactly where the accounting question in Open items bites** — see below.

## Admin UX sketch

- `/admin/settings/discounts` — new settings page alongside Tags/Certifications/Products,
  ROLE_ADMIN only (matching those). List + create/edit form; redemption count and remaining cap
  shown per discount, linking to the (filtered) sales list for "who's used this".
- `/admin/vouchers` — list of issued vouchers (code, balance, purchaser, recipient, status),
  search by code (for a phone-in "can you check my voucher balance" query), a manual
  balance-adjustment action (mirroring the credit ledger's admin adjustment path) with a required
  note, and a way to resend the recipient email.
- Admin POS (`admin/sales/show.html.twig`): a code field next to the existing "Add product"
  picker, applying to whichever row it matches; the existing note-required manual price override
  stays exactly as-is as the case-by-case fallback.

## Public UX sketch

- Shop page (`/shop`): gift voucher products appear as their own category tile group, same
  `.pos-tabs` pattern already used for the shop grid and the admin events/sales filters.
- Cart page: a "Discount code" field and a separate "Gift voucher code" field above the total,
  each with its own inline apply/remove and a running note of what's been applied and why
  (`-£8.00 — EARLYBIRD25` / `-£25.00 — gift voucher •••• 4F2A`).

## Suggested build order

1. `Discount` + `DiscountRedemption` + the two new `SalesOrderRow` columns. `DiscountEngine`
   service. Wire into `CartService::checkout()` and the admin POS row-adding paths. Admin CRUD UI.
   Cart code-entry UI. No vouchers yet — ship and use this first.
2. `Product::TYPE_GIFT_VOUCHER` + `GiftVoucherProduct` + `GiftVoucher` +
   `GiftVoucherLedgerEntry` + `GiftVoucherFulfilmentHandler`. Sellable in the shop, mints a code,
   sends the recipient email. No redemption yet — a voucher can be bought but not spent.
3. Redemption: `SalesOrderService::completeWithVoucher()`, the reduced-Stripe-amount wiring, the
   cart/POS redemption field, the admin voucher lookup/adjustment page.
4. Later, optional: whole-basket discounts, custom-amount vouchers, stacking beyond
   "one per line, best wins", expiry-reminder emails, refunding a cancelled paid booking back
   onto a voucher balance instead of a card refund.

## Open items / decisions needed before building

- **Revenue recognition for vouchers is an accounting question, not just an engineering one.**
  Recognising the sale as revenue when the voucher is *bought* is simpler to build, but a
  community-fund/charity context may want proper deferred-revenue treatment (recognise it when
  *redeemed* instead), which affects reporting and possibly Gift Aid position. Needs an answer
  from whoever manages the accounts before Part 2 is built — this doc doesn't pick one.
- Confirm "one discount per line, best value wins" (no stacking) is acceptable, and that
  per-line-only scope (no whole-basket £-off) covers the real scenarios in mind.
- Voucher expiry: none, or a standard term (12/24 months)? Affects the `expiresAt` default and
  whether an expiry-reminder email is worth building in phase 4.
- Fixed denominations only, or is a custom "pay what you want" voucher amount important enough to
  justify relaxing the one-fixed-price-per-product assumption for this one product type?
- Can a voucher be redeemed against a donation (a `Payment` with `isDonation()` true, which has no
  `SalesOrder`)? Proposal above assumes no — vouchers only ever pay off a real `SalesOrder` — but
  "buy a voucher, redeem it as a donation" is a conceivable ask worth ruling in or out explicitly.
- Who can create/edit discount codes and issue manual voucher adjustments — ROLE_ADMIN only
  (matching Products/Tags/Certifications settings), or also ROLE_TEAM?
- Should a cancelled/refunded booking that was paid for (even partly) with a voucher return that
  amount to the voucher's balance automatically, or does that always need an admin's manual
  adjustment with a note (safer default, more staff effort)?
