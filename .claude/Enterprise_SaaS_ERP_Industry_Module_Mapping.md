# Enterprise SaaS ERP - Industry-wise Module Mapping

See sections for Retail, Food & Beverage, Manufacturing, Distribution & Wholesale, Automobile, Mobile, Healthcare, Agriculture, Service Businesses, Logistics, Construction.

## Core ERP

**Every tenant gets this list, on every plan.** It is not a preset and not a
selection: an industry adds its own modules on top, and nothing below can be
unticked at signup, priced out by a package or revoked by the platform operator.

Half of it was never hideable to begin with — Dashboard, the Masters (Customers,
Suppliers, Products, Warehouse), Reports, Users, Roles & Permissions and
Notifications carry no module tag, so no gate applies. The switchable half is
`App\Support\Modules::CORE` = `sales, crm, purchase, inventory, accounts, ai`,
enforced in `Plan::allowedModules()`, `EnabledModules::forTenant()` and
`TenantAccessController`. Trade presets and industry plugins therefore declare
**only** what their trade adds.

- Dashboard
- CRM
- Customers
- Suppliers
- Products
- Purchase
- Sales
- Inventory
- Warehouse
- Accounts
- Expenses
- Reports
- Users
- Roles & Permissions
- Notifications
- AI Assistant

## Retail
Garment: Style Master, Fabric, Size Matrix, Colour Matrix, Barcode, QR, Label Printing, Offers, Loyalty, Alteration, Trial Room, Gift Voucher.
Footwear: Size Matrix, Colour, Brand, Barcode, Exchange, Return, Warranty, Label Printing.
Grocery / Kirana: POS, Barcode, Batch, Expiry, Weight Scale, Offers, Combo Products, Loyalty, Daily Cash, Supplier Credit. (Now its own vertical — `app/Modules/Industry/Grocery`.)
Optical: Eye Power, Lens Details, Frame Details, Prescription Upload, Appointment,
Spectacle Order, Delivery, Warranty. (Now its own vertical — `app/Modules/Industry/Optical`.
The eye test, the lens specification and the spectacle order stayed in core's
`optical` module; the plugin adds the diary in front of the test, the scan of the
paper beside it, the frame's own facts, the collection desk the pair leaves
through, and the warranty cards handing it over issues into core's register.)
Jewellery: Gold Rate, Purity, Diamond & Stone Details, Making Charges, Hallmark,
Old Gold Exchange, Purchase Order, Barcode, RFID.

Four notes on where the garment work lives, because half of it is not where a
reader would first look:

- **Alteration** is a `TailorAlteration` with a customer and an invoice on it —
  the same document the workroom raises — not a second register. The Garment menu
  puts a second door on the existing Alterations screen.
- **Gift Voucher** is a Loyalty & Offers feature in *core*, not in the Retail
  plugin: a gift shop and a supermarket sell the same instrument, and it is spent
  through `PosController`, which cannot depend on a vertical that may not be
  installed. It settles as a tender (`payments.mode = 'voucher'`) beside redeemed
  points and old gold, never as a discount.
- **Size/Colour Matrix** writes ordinary `product_variants` rows. It is a way of
  *entering* variants, not a second variant system, so POS scanning, stock,
  barcode labels and channel listings keep working untouched.
- **Trial Room** reserves stock rather than moving it, so a garment the customer
  keeps is billed once, through the ordinary till.

## Gift & Novelty
Gift Shop: Product Variants, Gift Wrap, Combo Pack (hampers), Occasion Reminder,
Loyalty, Barcode, Gift Voucher, Label Printing.

Its own vertical (`app/Modules/Industry/Gift`, module `gift`) rather than a retail
trade, because nothing a gift shop needs is about the object being sold — the
goods are ordinary stock with ordinary variants — and everything is about what
happens around it:

- **Gift Wrap** is a desk with a queue, not a flag on an invoice: a promised time,
  material off the shelf, and a rule that the receiver must not see the price
  (`gift_wraps.hide_prices`, default true). The *charge* is billed through the
  ordinary sales spine as an `is_service` product named on the wrapping option, so
  wrapping needs no money path of its own; the parcel stores the charge that
  applied on the day. Marking a parcel wrapped depletes its material once —
  `material_posted_at` is the guard — and cancelling afterwards does **not** put
  the paper back, because it was really cut.
- **Combo Pack** is a hamper: a physical article assembled out of other stock, so
  the stock moves when a batch is **built** (`gift_pack_builds` → contents out,
  hampers in, cost stored), not when one is sold. Deliberately unlike the Food &
  Beverage combo, which expands at sale time because a meal deal cannot sit on a
  shelf. A build refuses to go negative and names every short component; a
  reversal is a posted correction, not an edit. Also unlike the Grocery module's
  combo pack, which is a multipack billed as one scan.
- **Occasion Reminder** is a per-*person* register with lead time
  (`gift_occasions`), because the dates a gift shop sells against belong to wives,
  mothers and bosses rather than to the customer, and a reminder on the morning of
  arrives after the money was spent elsewhere. Core's
  `crm:notify-customer-occasions` (same-day, the customer's own date) stays as it
  is for every other trade; this vertical adds `gift:reminders` at 07:30, which
  notifies the counter and texts the customer where the occasion allows it.
- **Gift Voucher** is core's, under Loyalty & Offers — see the Retail note above.
  The Gift Shop menu puts a second door on it.
- **Product Variants / Barcode** are ordinary `product_variants` and the `devices`
  module's label printing. Nothing here duplicates them.

Priced into **Starter** (like Grocery, and for the same reason: the archetypal
buyer is a one-counter shop), so there is no marketplace pack — every plan already
carries the module. The Toys & Games vertical reuses `gift` for its own wrap desk
and combo packs rather than shipping rivals.

## Beauty & Personal Care
Cosmetic Store: Batch, Expiry, Shade/Colour, Barcode, Offers, Membership.

Four of the six are somebody else's already, so the plugin
(`app/Modules/Industry/Cosmetics`) ships only the two that belong nowhere:

- **Shade** is a master (`cosmetic_shades`) and not a colour word, because a
  shade is chosen by matching it to a face: family, finish, undertone and depth
  are what the customer is picking between, and depth is what a foundation card
  sorts on. The shade card generates ordinary `product_variants` from it — a way
  of *entering* variants, exactly like the garment matrix — with
  `cosmetic_shade_id` on the variant so "which four of the forty are we out of"
  is a join rather than a scan through JSON. It sits on the product too, for the
  shops that key one SKU per shade; `CosmeticsPlugin::shadeFor()` is the only
  place that precedence is resolved.
- **Period after opening** is the second clock, and core has no idea it exists.
  A mascara is finished six months after the seal breaks whatever the carton
  says, and the units that get opened in this trade are the testers on the
  counter. `cosmetic_open_units` holds them: written off stock as `sample` the
  moment they are opened (never `damage` — testers are the cost of trading, not
  breakage), with `opened_on` and `expires_on` snapshotted so correcting a
  product's PAO later cannot move the death date of a tester already in use.
- **Batch / Expiry** are core `batches` with core FEFO picking. What this
  vertical adds is the *refusal*: selling cosmetics past their date is an offence
  under the Drugs and Cosmetics Rules rather than a markdown decision, so
  `ShelfLifeService` gates the till through `CosmeticCounterService` at the same
  point the pharmacy's prescription check sits. Short-dated stock only ever
  warns — it is legal to sell and the customer decides. The policy is three
  tenant settings (`cosmetics_block_expired_sale`,
  `cosmetics_min_sale_shelf_life_days`, `cosmetics_near_expiry_days`), the same
  shape Distribution's credit enforcement uses, because a chain with a compliance
  officer and a counter clearing its shelf at 40% off need different answers.
- **Offers / Membership / Barcode** are core Loyalty & Offers and
  `inventory`/`devices`. A club card is a club card whether a café or a beauty
  counter sells it, so the preset ticks `loyalty` and the menu puts a second door
  on the existing Memberships screen.

Registered as its own vertical rather than a Retail trade: Retail is organised
around how the *article* is identified, and this trade is organised around how
long the article stays fit to sell. Perfumery, salon retail and ayurvedic are the
obvious siblings, and none of them belongs under a garment showroom.

## Books & Periodicals
Book Store: ISBN, Author, Publisher, Language, Category, Barcode, Online Orders.
Academic & School Bookshop: the same, plus the Stationery vertical's class
booklists and institution supply orders.

The vertical exists because a bookshop is the one retail trade that does not name
its own stock — the article arrives with a worldwide identity, a price printed on
the cover by somebody else, and people on the front that customers ask for by
name. Four notes on where the work lives:

- **ISBN is also the barcode.** An ISBN-13 beginning 978/979 is a valid EAN-13,
  which is what is printed on the back cover. `TitleObserver` check-sums the
  number (both forms carry a check digit — one wrong digit is a different book
  that also exists) and copies it onto `products.barcode`, so a title is scannable
  at the core till without this module touching the POS. It only adopts a barcode
  that is empty or still holds core's SKU default; a shop that typed its own keeps
  it, and the Titles screen flags the drift.
- **Author is a join table with a role**, not a column. A translated novel has an
  author and a translator, an anthology an editor, a picture book one person
  twice — and "what else do you have by her?" is what the counter is asked all day.
- **Publisher is not a brand.** It sets the printed price, sells at a trade
  discount off it (the shop's whole margin), and often takes unsold copies back:
  sale-or-return terms are what decide whether slow stock is a deadline or a loss.
  Core `brands` stays free for the stationery the same shop sells.
- **Category, Barcode and Online Orders are core** — `categories`, `inventory` +
  `devices`, and the `ecommerce` channel inbox respectively. Nothing here
  duplicates them, and unlike Toys → Gift the menu puts no second door on the
  last: a second row makes its group's module an owner of that row's permission,
  which is safe only between modules in one plan tier. `books` is Starter and
  `ecommerce` is Professional, so the inbox stays under Ecommerce where it is
  gated.

Priced into **Starter** (the module is catalogue hygiene, and that problem is
worst at the smallest shops), while `ecommerce` stays Professional — so a Starter
book shop gets the ISBN register and buys up for the online-order inbox.

## Food & Beverage
Restaurant: POS, Table, KOT, Menu, Recipe, QR Menu, Parcel, Delivery, Waiter, KDS.
Cafe: Membership, Happy Hours, Combo.
Bakery: Recipe, Batch, Expiry, Cake Orders.
Sweet Mart: Weight Scale, Batch, Expiry.
Fast Food: KOT, Token, Combo.
Juice: Recipe, Inventory.
Dairy: Milk Collection, Route, Subscription.
Ice Cream: Freezer Stock, Batch, Flavor.

## Manufacturing
Common: BOM, Production, QC, Machine, Labour, Maintenance, Scrap, Costing.
Garment, Textile, Embroidery, Printing Press, Furniture, Plastic, Steel, Packaging, Chemical, Food Manufacturing specific modules.

## Distribution & Wholesale
Multi Warehouse, Salesman, Route, Credit Limit, Outstanding, Transport, Import & Export.

## Automobile
VIN, Booking, Job Card, Service, Spare Parts, Warranty.

## Mobile
Mobile Shop: IMEI Management, Serial Number, Warranty, Insurance, Exchange, Repair Job Card, Accessories, Barcode, QR Code, EMI Tracking, Buyback.

Four notes on where the work lives, because most of this list is not a new table:

- **Warranty** is core's `product_warranties` — the same card a footwear counter
  issues — with a `handset_id` column added so a card can name an IMEI rather than
  a typed-in serial. There is deliberately no second warranty register: a shop must
  never be given two answers about who pays.
- **Insurance** *is* a table of its own, and the reason is `claim_limit`. A warranty
  can be claimed against repeatedly for unrelated faults; a screen policy is
  normally finished after one claim, and a register that did not know the difference
  would promise a second replacement nobody is going to fund. It also carries a
  premium, an excess and named perils, none of which a warranty card has.
- **Exchange** and **Buyback** are one register. The old handset is graded, taken in
  as an open credit the customer holds, and settled against the new bill as a
  *tender* (`payments.mode = 'buyback'`) beside old gold and gift vouchers — never
  as a discount, so the new phone still bills at its shelf price.
- **Repair Job Card** is a `ServiceJob` with `handset_id` and `insurance_policy_id`
  on it. A claim is not a table: it is a repair booked against a policy, counted on
  the policy by an observer so it also counts when the job is opened from the
  ordinary Service screen.

**EMI** turns on one column. A `finance_company` file was settled with the shop at
disbursal, so collecting an instalment only ticks the schedule; an `in_house` file
is the shop's own receivable, so collecting writes a real payment against the sale
invoice. Accessories, Barcode, QR Code and Serial Number are the ordinary
catalogue, inventory and label-printing modules — a cable is not a special kind of
product.

## Healthcare
Batch, Expiry, Prescription, Diagnostic Lab, Hospital Store.

## Agriculture
Seeds, Fertilizer, Pesticides, Dairy Farm, Poultry.

## Service Businesses
Job Card, Technician, AMC, Complaint, Projects, Event Booking.

## Logistics
Shipment, Vehicle, GPS, POD, Warehouse, Freight, Customs, CHA.

## Construction
BOQ, Material, Labour, Project Billing, Tiles, Sanitary.

## Integrations
Barcode, QR, Thermal Printer, Label Printer, Weighing Scale, Biometric, WhatsApp, Payment Gateway, Tally, Busy, Zoho Books, Shiprocket, Delhivery, Google Maps, Firebase.
