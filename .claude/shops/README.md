# Shop-wise ERP Menu Requirements

One file per shop: **what that trade's menu must contain**, in the routes the product
already ships plus an explicit list of what is still missing. Written the way
[`../shops-modules.md`](../shops-modules.md) asks for it — retail ERP as the base, the
trade's own modules on top.

| | |
|---|---|
| Shops | 67 |
| Base documents | [Retail](_base-retail.md) · [Factory / back-office](_base-factory.md) |
| Trade keys from | `App\Support\ShopProfiles::all()` |
| Routes validated against | `Database\Seeders\MenuSeeder::tree()` |
| Plan tiers from | `Database\Seeders\PlanSeeder` |

## How a shop menu is actually built

```
Menu catalogue   menu_items, tenant_id NULL   every screen the product has   MenuSeeder
      ↓  curated per trade
Shop template    shop_menu_items              what THIS trade opens on       ShopMenuSeeder
      ↓  cloned at provisioning
Tenant menu      menu_items, tenant_id set    what this workspace sees       ShopMenus::cloneForTenant
      ↓  filtered per request
Sidebar          MenuController               minus plan, module, permission
```

Four rules these documents are written against:

1. **A template names screens, it never defines them.** Label, icon, permission, plan
   feature and module gate are copied from the catalogue row, so a curated menu can never
   grow an entry the SPA router refuses. A route the catalogue lacks is skipped and reported.
2. **A template never widens entitlement.** The plan stays the ceiling and the gates travel
   with the screen — a curated menu cannot hand a cashier the Trial Balance.
3. **A second door is fine, a second register is not.** Several verticals reach
   `/service-jobs` and `/warranties`; `permissionGroupMap()` collects every owning module
   and passes if *any* is enabled. Mind the plan tier — see [Book Store](book_store.md).
4. **⬜ is a real answer.** Where a trade asks for something that has no screen, these files
   say so rather than naming a route that does not exist. That list is the backlog.

## The common floor

Every retail trade starts here (`ShopMenuSeeder::common()`); the industry group is inserted
at position 3. Full detail in [the retail base](_base-retail.md).

| # | Group | Screens |
|---|---|---|
| 1 | **Dashboard** | Dashboard |
| 2 | **Sales** | POS, Quotations, Sales Orders, Sales Returns |
| 3 | **Purchase** | Purchase Orders, Vendor Invoices, Purchase Indents, Stock Returns |
| 4 | **Inventory** | Current Stock, Stock Adjustments, Stock Transfers, Stock Ledger, Inventory Alerts, Reorder, Warehouses |
| 5 | **Products** | Products, Categories, Brands, Units, Price Lists |
| 6 | **Barcode & Labels** | Barcode Scan, Label Printing |
| 7 | **Customers & Suppliers** | Customers, Customer Categories, Suppliers |
| 8 | **CRM & Marketing** | Leads, Follow-ups, WhatsApp, SMS, Marketing Campaigns |
| 9 | **Offers & Loyalty** | Loyalty Points, Coupons, Gift Vouchers |
| 10 | **Accounts** | Vouchers, Day Book, Customer Ledger, Supplier Ledger, Cash Book, Bank Book, Trial Balance, Profit & Loss, Balance Sheet, Bank & Cash Accounts |
| 11 | **Expenses** | Expenses, Expense Categories |
| 12 | **GST & Tax** | GST Registrations, HSN/SAC Master, E-Invoices, E-Way Bills, GST Tools, GSTR-1 (Outward), GST Summary (GSTR-3B) |
| 13 | **Reports** | Sales, Purchases, Stock Overview, Fast / Slow Moving, Outstanding & Ageing, Payables / Pending, Profitability |
| 14 | **AI** | AI Insights, AI Assistant |
| 15 | **Administration** | Users, Roles & Permissions, Companies, Branches, Custom Fields, Workflows, Audit Logs, System Tools, Settings |

## The shops

Template column: ✅ curated default ships today · ⬜ this file is the spec, seeder section
still to write · ⛔ blocked because the trade key does not exist yet.

### Retail

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 👕 [Garment / Apparel](garment.md) | `garment` | `pos`, `garment`, `tailoring`, `gst`, `loyalty`, `devices` | Enterprise | ✅ |
| 👟 [Footwear Shop](footwear.md) | `footwear` | `pos`, `garment`, `gst`, `loyalty`, `warranty`, `devices` | Enterprise | ✅ |
| 💍 [Jewellery](jewellery.md) | `jewellery` | `pos`, `gst`, `jewellery`, `loyalty` | Business | ✅ |

### Grocery & Kirana

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🛒 [Grocery / Kirana](grocery.md) | `grocery` | `pos`, `gst`, `grocery`, `loyalty`, `devices` | Business | ✅ |
| 🏬 [Supermarket](supermarket.md) | `supermarket` | `pos`, `supermarket`, `gst`, `grocery`, `loyalty`, `devices` | Enterprise | ✅ |

### Food & Beverage

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🍽️ [Restaurant / Fine Dine](restaurant.md) | `restaurant` | `pos`, `gst`, `hrms`, `restaurant`, `loyalty` | Business | ⬜ |
| ☕ [Cafe / Coffee Shop](cafe.md) | `cafe` | `pos`, `gst`, `restaurant`, `loyalty` | Business | ⬜ |
| 🥐 [Bakery](bakery.md) | `bakery` | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` | Professional | ⬜ |
| 🍬 [Sweet Mart / Namkeen](sweet_mart.md) | `sweet_mart` | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` | Professional | ⬜ |
| 🍔 [Fast Food / QSR](fast_food.md) | `fast_food` | `pos`, `gst`, `restaurant`, `loyalty` | Business | ⬜ |
| 🥤 [Juice / Beverage Bar](juice.md) | `juice` | `pos`, `gst`, `restaurant` | Business | ⬜ |
| 🥛 [Dairy / Milk Distribution](dairy.md) | `dairy` | `pos`, `field_sales`, `logistics`, `gst`, `devices` | Business | ⬜ |
| 🍨 [Ice Cream Parlour](ice_cream.md) | `ice_cream` | `pos`, `manufacturing`, `gst`, `loyalty`, `devices` | Professional | ⬜ |

### Manufacturing

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🏭 [Manufacturing / Factory (general)](manufacturing.md) | `manufacturing` | `manufacturing`, `textile`, `tailoring`, `embroidery`, `gst`, `hrms`, `assets` | Enterprise | ⬜ |
| 🧵 [Garment / Apparel Unit](garment_manufacturing.md) | `garment_manufacturing` | `manufacturing`, `textile`, `tailoring`, `embroidery`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Enterprise | ⬜ |
| 🧶 [Textile Mill / Processing](textile_mill.md) | `textile_mill` | `manufacturing`, `textile`, `tailoring`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Enterprise | ⬜ |
| 🪡 [Embroidery Unit](embroidery_unit.md) | `embroidery_unit` | `manufacturing`, `textile`, `tailoring`, `embroidery`, `gst`, `hrms`, `assets` | Enterprise | ⬜ |
| 🖨️ [Printing Press](printing_press.md) | `printing_press` | `manufacturing`, `textile`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Professional | ⬜ |
| 🪑 [Furniture Manufacturing](furniture.md) | `furniture` | `manufacturing`, `logistics`, `gst`, `hrms`, `service`, `projects`, `assets` | Professional | ⬜ |
| 🧴 [Plastic / Moulding](plastic.md) | `plastic` | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Professional | ⬜ |
| ⚙️ [Steel / Fabrication](steel.md) | `steel` | `manufacturing`, `logistics`, `gst`, `hrms`, `projects`, `assets`, `devices` | Professional | ⬜ |
| 📦 [Packaging / Corrugation](packaging.md) | `packaging` | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Professional | ⬜ |
| 🧪 [Chemical / Paints](chemical.md) | `chemical` | `manufacturing`, `logistics`, `gst`, `hrms`, `assets`, `devices` | Professional | ⬜ |
| 🥫 [Food Manufacturing / Processing](food_manufacturing.md) | `food_manufacturing` | `manufacturing`, `logistics`, `gst`, `hrms`, `restaurant`, `assets`, `devices` | Professional | ⬜ |

### Distribution & Wholesale

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 📦 [Wholesale / Distribution](wholesale.md) | `wholesale` | `field_sales`, `distribution`, `broker`, `logistics`, `gst` | Business | ⬜ |
| 🚚 [FMCG Distributor](fmcg_distribution.md) | `fmcg_distribution` | `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices` | Business | ⬜ |
| 🏗️ [C&F Agent / Super Stockist](super_stockist.md) | `super_stockist` | `field_sales`, `distribution`, `logistics`, `gst`, `workflow`, `devices` | Business | ⬜ |
| 🏪 [Cash & Carry / Wholesale Counter](cash_carry.md) | `cash_carry` | `pos`, `distribution`, `gst`, `loyalty`, `devices` | Business | ⬜ |
| 🔩 [Hardware & Building Material](hardware_wholesale.md) | `hardware_wholesale` | `field_sales`, `distribution`, `broker`, `logistics`, `gst`, `devices` | Business | ✅ |

### Agriculture

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🌾 [Agri Centre / Agro Dealer](agriculture.md) | `agriculture` | `pos`, `field_sales`, `broker`, `logistics`, `gst`, `agriculture`, `devices` | Business | ⬜ |
| 🌱 [Seed Dealer / Nursery](seeds.md) | `seeds` | `pos`, `field_sales`, `gst`, `agriculture`, `devices` | Business | ⬜ |
| 🧪 [Fertiliser Dealer](fertilizer.md) | `fertilizer` | `pos`, `field_sales`, `logistics`, `gst`, `agriculture`, `devices` | Business | ⬜ |
| 🐛 [Pesticides / Agro Chemicals](pesticides.md) | `pesticides` | `pos`, `field_sales`, `gst`, `agriculture`, `devices` | Business | ⬜ |
| 🐄 [Dairy Farm](dairy_farm.md) | `dairy_farm` | `pos`, `logistics`, `gst`, `hrms`, `livestock`, `assets` | Business | ⬜ |
| 🐔 [Poultry Farm](poultry.md) | `poultry` | `logistics`, `gst`, `hrms`, `livestock`, `assets` | Business | ⬜ |

### Healthcare

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 💊 [Medical Store / Pharmacy](medical.md) | `medical` | `pos`, `gst`, `pharmacy`, `loyalty`, `devices` | Business | ⬜ |
| 🔬 [Diagnostic Lab / Pathology](diagnostic_lab.md) | `diagnostic_lab` | `gst`, `diagnostics`, `devices` | Business | ⬜ |
| 🩺 [Clinic / Doctor’s Chamber](clinic.md) | `clinic` | `gst`, `hrms`, `pharmacy`, `diagnostics` | Business | ⬜ |
| 🏥 [Hospital / Nursing Home](hospital.md) | `hospital` | `gst`, `hrms`, `pharmacy`, `diagnostics`, `hospital`, `projects`, `assets` | Business | ⬜ |
| 💉 [Pharma Distribution](pharma_distributor.md) | `pharma_distributor` | `field_sales`, `broker`, `logistics`, `gst`, `pharmacy`, `devices` | Business | ⬜ |
| 🩹 [Surgical & Medical Devices](surgical.md) | `surgical` | `pos`, `gst`, `service`, `loyalty`, `devices` | Business | ⬜ |

### Automobile

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🚗 [Car Service Centre / Garage](auto_service.md) | `auto_service` | `pos`, `gst`, `hrms`, `service`, `automobile`, `devices` | Business | ⬜ |
| 🏍️ [Two-Wheeler Workshop](two_wheeler.md) | `two_wheeler` | `pos`, `gst`, `service`, `automobile`, `loyalty` | Business | ⬜ |
| 🚙 [Vehicle Dealership](auto_dealer.md) | `auto_dealer` | `pos`, `field_sales`, `gst`, `hrms`, `service`, `automobile`, `loyalty` | Business | ⬜ |
| 🔧 [Spare Parts & Accessories](spare_parts.md) | `spare_parts` | `pos`, `gst`, `automobile`, `loyalty`, `devices` | Business | ⬜ |
| 🛞 [Tyre & Battery Shop](tyre_battery.md) | `tyre_battery` | `pos`, `gst`, `service`, `automobile`, `devices` | Business | ⬜ |

### Electronics & Appliances

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 📺 [Electronics Showroom](electronics_shop.md) | `electronics_shop` | `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices` | Business | ✅ |
| 🍽️ [Home Appliance & Kitchenware](home_appliance.md) | `home_appliance` | `pos`, `logistics`, `gst`, `service`, `electronics`, `loyalty`, `warranty`, `devices` | Business | ✅ |
| ⚡ [Electrical Goods Shop](electrical_shop.md) | `electrical_shop` | `pos`, `gst`, `electronics`, `warranty`, `devices` | Business | ✅ |

### Mobile & Handsets

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 📱 [Mobile Phone Shop](mobile_shop.md) | `mobile_shop` | `pos`, `gst`, `service`, `mobile`, `loyalty`, `warranty`, `devices` | Business | ✅ |

### Computer & IT

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 💻 [Computer / Laptop Shop](computer_shop.md) | `computer_shop` | `pos`, `gst`, `hrms`, `service`, `computer_it`, `loyalty`, `devices` | Business | ✅ |

### Stationery & School Supplies

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 📝 [Stationery Shop](stationery_shop.md) | `stationery_shop` | `pos`, `logistics`, `gst`, `stationery`, `loyalty`, `devices` | Business | ✅ |

### Gift & Novelty

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🎁 [Gift Shop](gift_shop.md) | `gift_shop` | `pos`, `gst`, `gift`, `loyalty`, `devices` | Business | ✅ |

### Toys & Games

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🧸 [Toy & Game Shop](toy_shop.md) | `toy_shop` | `pos`, `gst`, `gift`, `toys`, `loyalty`, `devices` | Business | ✅ |

### Books & Periodicals

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 📚 [Book Store](book_store.md) | `book_store` | `pos`, `ecommerce`, `gst`, `books`, `loyalty`, `devices` | Professional | ✅ |
| 🎓 [Academic & School Bookshop](academic_bookshop.md) | `academic_bookshop` | `pos`, `ecommerce`, `gst`, `stationery`, `books`, `loyalty`, `devices` | Professional | ✅ |

### Optical & Eyewear

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 👓 [Optical Shop](optical.md) | `optical` | `pos`, `gst`, `optical`, `loyalty`, `warranty`, `devices` | Business | ✅ |

### Beauty & Personal Care

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 💄 [Cosmetic / Beauty Store](cosmetic.md) | `cosmetic` | `pos`, `gst`, `cosmetics`, `loyalty`, `devices` | Enterprise | ✅ |

### Sports & Fitness

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🏏 [Sports & Fitness Shop](sports_shop.md) | `sports_shop` | `pos`, `garment`, `gst`, `service`, `sports`, `loyalty`, `warranty`, `devices` | Enterprise | ✅ |

### Service, Projects & Trade — hand-written presets

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| 🔧 [Service / Repair Centre](service.md) | `service` | `gst`, `hrms`, `service`, `projects` | Business | ⬜ |
| 📋 [Projects / Consulting](projects.md) | `projects` | `gst`, `hrms`, `projects` | Business | ⬜ |
| 🏗️ [Construction / Infrastructure](construction.md) | `construction` | `logistics`, `gst`, `hrms`, `projects`, `assets` | Business | ⬜ |
| 🌍 [Import / Export](trading.md) | `trading` | `broker`, `logistics`, `gst` | Business | ⬜ |
| 🧩 [Something else](custom.md) | `custom` | — | Starter | ⬜ |

### In the spec, no trade key yet

| Shop | Trade key | Industry modules | Min. plan | Template |
|---|---|---|---|---|
| ⌚ [Watch Shop](watch_shop.md) | `watch_shop` | `pos`, `gst`, `warranty`, `service`, `loyalty`, `devices` | Business | ⛔ |
| 🎨 [Paint Shop](paint_shop.md) | `paint_shop` | `pos`, `gst`, `devices`, `loyalty`, `logistics` | Business | ⛔ |
| 🚰 [Plumbing / Sanitary Shop](plumbing_shop.md) | `plumbing_shop` | `pos`, `gst`, `distribution`, `logistics`, `devices` | Business | ⛔ |

**19 of 67** shops have a curated template in `ShopMenuSeeder` today. The rest
provision with the whole catalogue cloned — workable, but not what the trade opens on.

## Adding a shop

1. Declare the trade in its industry plugin. A trade must exist in `ShopProfiles` before a
   template can attach to it — `ShopMenuSeeder::run()` skips unknown keys and says so.
2. Write `<trade_key>.md` here, naming only routes `MenuSeeder` already has. Anything else
   goes under *Not buildable into the menu yet* — that is a plugin task, not a menu task.
3. Paste the §2 code block into `ShopMenuSeeder::industrySections()`.
4. `php artisan db:seed --class=ShopMenuSeeder` — seeds only trades with no template, so it
   never stomps an operator's curated tree. Re-applying a shipped default is "Reset to
   default" on `/platform/shop-menus`.

