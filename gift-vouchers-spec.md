# Gift vouchers — architecture spec (not yet implemented)

## Goal

Let people buy gift vouchers (stored value, spent later) and redeem them against anything sold
through the existing `Product` / `SalesOrder` / `SalesOrderRow` / `Payment` pipeline, without
bending that pipeline out of shape.

This is a proposal to review, not a build plan to follow blindly — see **Open items** at the end
for the judgment calls that need an answer first.

Discounts/promotions are a separate feature with their own spec — see `discounts-spec.md`.
Neither depends on the other; they can be built in either order.

## Why this is separate from discounts

- **A discount reduces the price of the goods** — it changes what's owed, the VAT base and the
  revenue the sale recognises.
- **A gift voucher is a payment method — stored value, spent later.** It doesn't change what
  anything costs; it changes *how* an already-priced order gets paid for, the same way cash or a
  drop-in credit already do. The VAT and the "real" price of the thing bought are unaffected.

Vouchers act at order completion on whatever `order.getTotal()` already is, and never touch
`SalesOrderRow.chargedPrice`. The only point of contact with discounts: **discounts never apply
to voucher products** (decided) — see "Sold through the existing pipeline" below.

## The pipeline as it stands today (for reference)

Nothing here changes — this is the pipeline vouchers sit alongside:

- `Product` holds one canonical `price` + `vatCode`. Type-specific data lives in a 1:1 extension
  (`StockProduct`, `CreditProduct`, `MembershipProduct`, `EventTicketProduct`). A gift voucher
  adds one more extension of the same shape.
- `SalesOrderService` owns draft → complete: `completeFree()`, `completeWithCash()`,
  `startCardPayment()` (sets `Payment.amount = order.getTotal()`), `completeFromPayment()`. A
  voucher redemption hooks in here, not in the row-pricing step.
- `FulfilmentHandlerInterface` (tagged `app.fulfilment_handler`, one implementation per
  `Product::TYPE_*`) is the existing extension point for "what actually happens when a row is
  paid for". A gift voucher becomes a new product type with its own handler — no changes needed
  to the dispatch mechanism itself.
- Precedent for "a running balance with an audit trail" already exists twice: `CreditLedgerEntry`
  (drop-in credit balance) and `InventoryMovement` (stock levels) — both append-only ledgers of
  balance changes with a `reason`, an optional link to the row/booking that caused it, a `note`,
  and `createdBy`. Gift vouchers should be a third instance of the same shape, not a new pattern.

## Sold through the existing pipeline, not a parallel one

A gift voucher is just a new `Product::TYPE_GIFT_VOUCHER`, sold via the same
`Product`/`SalesOrderRow`/`Payment`/fulfilment flow as everything else — no new purchase UI
pattern needed, it slots into the shop grid like any other product.

**Fixed denominations only** (decided): separate £10 / £25 / £50 / £100 products (or whatever
set the admin creates), using `variantName`/`variantValue` exactly like `StockProduct` size
variants already do. No free-typed custom amount, which keeps the "a Product has one fixed price"
assumption everything else relies on (cart line totals, price snapshotting).

**Discounts never apply to voucher products** (decided). `faceValue` is what the voucher
redeems for, so an automatic discount on a voucher would hand out money by accident (a storewide
20% off turns a £50 voucher into £40 for £50 of spending). If the discounts feature exists, the
`DiscountEngine` must skip `TYPE_GIFT_VOUCHER` lines, including under blanket "any type"
discounts — see `discounts-spec.md`.

**Giving a voucher away is a £0 sale** (decided), the same way anything else is given away. In
the POS, staff add the voucher product to an order and use the existing row price override
(`AdminSalesController::updateRowPrice()`, which already requires a note) to set the sold price,
down to £0, and complete it with "Free". The voucher still issues at full `faceValue`, because
`initialValue` comes from the product's `faceValue`, never from the row's `chargedPrice`.
Prizes, raffles and goodwill gestures all work this way, with no separate "prize voucher" route.
- The override note is the record of why it was given (e.g. "Bouldering comp 1st prize").
- The voucher row's sold price must stay between £0 and `faceValue`. The override should refuse a
  higher price for a voucher row, since selling a £25 voucher for £30 is almost certainly a typo.
- Only admins can do this. The price override is ROLE_ADMIN for every product, not just
  vouchers (already in the code). Team members can sell at the standard price but not change it,
  and members can't: the cart has no price editing.
- The order's member is the "buyer" (`purchasedBy`). For a prize, staff put the order under the
  winner (or themselves) and fill in the recipient details as usual.

```
GiftVoucherProduct                       -- 1:1 extension, same pattern as CreditProduct etc.
  product            int PK/FK -> product.id
  faceValue          decimal(8,2)         -- what the voucher is worth to redeem — independent of
                                           -- the chargedPrice on the row (which staff may lower, e.g. to £0)

GiftVoucher
  id
  code               varchar(20) unique   -- e.g. 12-char alphanumeric, generated like MagicLinkService's tokens
  initialValue       decimal(8,2)         -- copied from faceValue at issuance, whatever the row's chargedPrice was
  balance            decimal(8,2)         -- decreases as redeemed
  purchasedBy        int FK -> user.id
  recipientName      varchar(150) NULL
  recipientEmail     varchar(255) NULL
  personalMessage    text NULL
  status             varchar(20)          -- active | redeemed | expired | cancelled
  issuedAt           datetime
  expiresAt          datetime NULL        -- set to issuedAt + 12 months at issuance; admin-editable per voucher

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

### Expiry — decided: 12 months by default

At issuance `expiresAt = issuedAt + 12 months`, and the email to the recipient states the expiry
date. "By default" means an admin can change `expiresAt` on an individual voucher from
`/admin/vouchers` (e.g. to extend one as a goodwill gesture), with a required note. The column
stays nullable, so "never expires" is still possible for a one-off. Nothing else changes the
term; there's no per-product setting in v1.

The app has no scheduled jobs today (no console commands, and nothing in the Procfile but
`release`/`web`), so expiry is applied **lazily** rather than by a cron job. Redemption checks
`expiresAt` directly, and the first time an expired voucher is looked up (at redemption or in
`/admin/vouchers`) it's moved to `expired` with a ledger entry (reason `expired`,
`amountChange = -balance`) that zeroes the balance, keeping the ledger sum equal to `balance`.
The admin list derives "expired" from `expiresAt`, so it's accurate even for vouchers nobody has
looked up yet. Revenue was already recognised at purchase, so expiry has no revenue effect.

**No expiry-reminder emails** (decided): the expiry date in the original voucher email is the
only notice. That also means no scheduler is needed for this feature.

## Redemption — a payment method, not a price change

Vouchers are redeemable in **both** checkouts (decided): by an admin in the POS
(`admin/sales/show.html.twig`) and by a member in the shopping cart. Both go through the same
service methods; only the UI differs. If the discounts feature exists too, keep the voucher and
discount code fields clearly labelled as distinct, so nobody is confused about which kind of code
they're holding.

### Applying a voucher to an order

`SalesOrder` gets two nullable columns recording the voucher applied to it:

```sql
ALTER TABLE sales_order
  ADD COLUMN gift_voucher_id INT NULL,
  ADD COLUMN gift_voucher_amount DECIMAL(8,2) NULL,   -- how much of the total the voucher covers
  ADD CONSTRAINT FK_order_gift_voucher FOREIGN KEY (gift_voucher_id) REFERENCES gift_voucher(id);
```

plus `SalesOrder::getAmountDue()` = `getTotal() - giftVoucherAmount`. **Every place that
currently charges `getTotal()` charges `getAmountDue()` instead**: `completeWithManualPayment()`
(cash and BACS), `startCardPayment()` (the S700 terminal), and `CartController::checkout()`'s
call to `StripePaymentService::startOnlinePayment()`. `getTotal()` itself is unchanged and still
means the price of the goods.

One voucher per order in v1. A `GiftVoucherRedemptionService` (or methods on `SalesOrderService`)
owns the rules:

- `applyVoucher(SalesOrder $order, string $code)`: the voucher must exist, be `active`, have a
  balance, and not be past `expiresAt`. Sets
  `giftVoucherAmount = min(voucher.balance, order.getTotal())`. A bad code gets a specific message
  ("That voucher has expired" / "That voucher has no balance left" / "We don't recognise that
  code"), not a generic error.
- `removeVoucher(SalesOrder $order)`: clears both columns.
- The amount is recalculated whenever the order's rows change, so a voucher can never cover more
  than the current total.
- **Applying doesn't spend anything.** The balance is only debited (the `redeemed` ledger entry)
  inside `markComplete()`, so an abandoned draft or a failed card payment never eats into a
  voucher. Balance and expiry are re-checked when payment starts and again at completion.
- **Stripe minimum:** Stripe won't take a card payment under £0.30. If the voucher would leave
  between 1p and 29p to pay by card, the voucher covers less so that £0.30 is left to pay and the
  rest stays on the voucher. (Cash and BACS in the POS have no minimum, so this only applies to
  card.)
- If `getAmountDue()` is zero, the order completes via a new
  `SalesOrderService::completeWithVoucher()` mirroring `completeFree()`: no `Payment`, no Stripe.

Accepted edge case: the same bearer code could be applied to two drafts at once (say, a member
in the cart while staff use it in the POS). Whichever completes first spends the balance. If the
second has already taken a card payment for its remainder, it still completes; the ledger debits
only what's left on the voucher, and the shortfall is written to the order as a `Note` for an
admin to follow up. That's rare enough at this scale not to justify holding balances on drafts.

### In the POS (admin)

- A "Gift voucher" code field on a draft order, next to the existing Cash / Card / BACS / Free
  completion buttons. Applying it shows `-£25.00 — gift voucher •••• 4F2A (£15.00 left after this)`
  and the amount due.
- The existing completion buttons then take the **remainder**. If the voucher covers it all, they
  give way to a single "Complete — paid by voucher" button.
- Staff can look a code up first (balance, expiry, status) without applying it, for the "how much
  is left on this?" question at the desk.

### In the shopping cart (member)

- A "Gift voucher code" field on the cart page above the total, with inline apply/remove. The
  cart lives in the session before checkout, so the entered code is stored in the session with
  it, and the displayed total shows the voucher and the amount due.
- On checkout, `CartService::checkout()` creates the draft order as now and applies the voucher
  to it (re-validating it). If `getAmountDue()` is zero it calls `completeWithVoucher()` and the
  response tells the page the order is done, so it skips the Stripe card form. Otherwise Stripe is
  started for `getAmountDue()` only.
- **Rate-limit code attempts**, because codes are bearer: anyone who guesses one can spend it. The
  12-character alphanumeric codes are hard to guess, but the cart field is a guessing endpoint
  for any logged-in member. Limit failed attempts per user (e.g. 10 an hour) using
  `symfony/rate-limiter`, which the project doesn't have yet. The POS doesn't need this.

### Recording the spend

The spend is recorded as a `GiftVoucherLedgerEntry` (reason `redeemed`, linked to the
`SalesOrder`), not as a `Payment`. `Payment` keeps meaning "real money moved via a real method"
(cash, card, terminal, BACS). Any `Payment` for the remainder is an ordinary one for
`getAmountDue()`. "How much was paid for with vouchers" is answered by querying the ledger,
exactly how credit-covered free bookings already coexist with `Payment` today.

**Vouchers only ever pay off a `SalesOrder` — never a donation** (decided). A donation is a
`Payment` with `isDonation()` true and no `SalesOrder`, and the donation flow gets no voucher
field. It's also consistent with the revenue decision below: no money arrives when a voucher
is spent (it arrived at purchase, if at all), so redeeming one as a "donation" would
record a donation nobody made.

### Revenue recognition — decided: at purchase

The sale is recognised as revenue when the voucher is **bought** (the voucher's
`SalesOrderRow.chargedPrice`), not deferred until it's redeemed. No deferred-revenue/liability
tracking is built.

The consequence: when a voucher is later spent, the voucher-covered portion of that order is
**not** new revenue — it was already counted at purchase. So any sales revenue figure must be
"real money in" (non-donation `Payment` rows, which already include the voucher purchases
themselves), never a straight sum of every order's `getTotal()`, or voucher-funded sales get
counted twice. No revenue report exists yet, so nothing needs changing today; this is the rule for whoever builds one. The
`GiftVoucherLedgerEntry` redemption rows are then a usage metric ("£X of vouchers spent this
month"), not a revenue source. Outstanding voucher balances (sum of `balance` on active vouchers)
remain queryable if the accounts ever need the liability figure.

Vouchers sold below face value fit the same rule with no special-casing. Revenue is whatever
was actually paid for the voucher (£0 for a giveaway), and nothing more comes in when it's spent.
"How much have we given away in vouchers" is the voucher rows where `chargedPrice` is below
`faceValue`, and their override notes say why.

VAT is a separate question from revenue recognition and isn't settled by this decision. Under UK
rules a general-purpose voucher is normally a *multi-purpose voucher*: no VAT on the voucher sale,
VAT charged on the goods when it's redeemed. That fits this design as-is — the redeemed rows keep
their own `vatCodeAtSale` — provided the voucher products get an outside-scope/zero `vatCode`.
Worth confirming with whoever does the accounts — including how goods paid for with a voucher
that was given away (or sold below face value) should be treated for VAT, since no money changes hands at either end.

## Admin UX sketch

- `/admin/vouchers` — list of issued vouchers (code, face value, price paid, balance, purchaser,
  recipient, status),
  search by code (for a phone-in "can you check my voucher balance" query), a manual
  balance-adjustment action (mirroring the credit ledger's admin adjustment path) with a required
  note, ROLE_ADMIN only like the POS price override, and a way to resend the recipient email.
- Admin POS (`admin/sales/show.html.twig`): voucher code field and balance lookup. See "In the
  POS (admin)" above.

## Public UX sketch

- Shop page (`/shop`): gift voucher products appear as their own category tile group, same
  `.pos-tabs` pattern already used for the shop grid and the admin events/sales filters.
- Cart page: a "Gift voucher code" field above the total. See "In the shopping cart (member)"
  above.

## Suggested build order

1. `Product::TYPE_GIFT_VOUCHER` + `GiftVoucherProduct` + `GiftVoucher` +
   `GiftVoucherLedgerEntry` + `GiftVoucherFulfilmentHandler`. Sellable in the shop and POS
   (including at a reduced or £0 price via the existing override, capped at face value), mints a
   code, sends the recipient email. No redemption yet — a voucher can be bought or given but not
   spent.
2. Redemption in both checkouts: the two `SalesOrder` columns and `getAmountDue()`, switching
   every payment path from `getTotal()` to `getAmountDue()`, `completeWithVoucher()`, the POS
   voucher field and lookup, the cart voucher field with rate limiting, and the admin voucher
   list/adjustment page.
3. Later, optional: refunding a cancelled paid booking back onto a voucher balance instead of a
   card refund.

## Open items / decisions needed before building

- Should a cancelled/refunded booking that was paid for (even partly) with a voucher return that
  amount to the voucher's balance automatically, or does that always need an admin's manual
  adjustment with a note (safer default, more staff effort)?
