# UI Design System, Menu Structure & Implementation Prompt
## Stock & Inventory Management System

---

## 1. Design Direction (Attractive, Distinctive UI)

**Theme name: "Warehouse Ledger"** — inspired by physical inventory registers, barcode labels, and warehouse signage, rendered as a clean modern SaaS dashboard.

### Color Palette
| Token | Hex | Use |
|---|---|---|
| `--graphite` | #1E2328 | Sidebar, headers, primary text |
| `--paper` | #F7F5F0 | Main content background (ledger-paper feel) |
| `--safety-amber` | #E8A33D | Primary accent — buttons, active menu, alerts |
| `--ink-blue` | #2C4A5E | Secondary accent — links, info states, charts |
| `--stock-green` | #4C8B6C | Success / in-stock indicators |
| `--alert-rust` | #C1502E | Low-stock / error / negative-stock indicators |

### Typography
- **Display face:** "Space Grotesk" (headings, dashboard numbers) — technical, slightly industrial character
- **Body face:** "Inter" (all UI text, tables, forms) — clean, highly legible at small sizes
- **Utility/mono face:** "IBM Plex Mono" (SKU codes, barcodes, batch numbers, invoice numbers) — gives inventory data a "system-of-record" feel and visually distinguishes codes from regular text

### Layout Concept
```
┌──────────┬─────────────────────────────────────────┐
│          │  Top bar: Search | Scan Barcode | Alerts │
│ Sidebar  ├─────────────────────────────────────────┤
│ (Master  │                                          │
│  Menu +  │   Main Content Area                      │
│  Sub-    │   (Cards / Tables / Forms)                │
│  menu,   │                                          │
│ collapse-│                                          │
│ ible)    │                                          │
└──────────┴─────────────────────────────────────────┘
```
- Sidebar: fixed, dark graphite background, collapsible to icon-only rail
- Active menu item: amber left-border + subtle amber tint background
- Content cards: paper background, 1px hairline borders (not heavy shadows) — ledger-sheet feel
- Data tables: monospace column for SKU/Batch/Serial numbers, zebra-striping in very light grey
- Status badges: pill-shaped, colored per stock-green / alert-rust / safety-amber

### Signature Element
A thin **barcode-stripe divider** (vertical black/white bars of varying width, like a real barcode) used sparingly as a section-break motif on the dashboard and report headers — ties the visual identity directly to the product domain instead of a generic gradient or icon.

---

## 2. Master Menu & Submenu Structure (Sidebar Navigation)

```
📊 Dashboard

📦 Masters
   ├─ Products
   ├─ Categories & Brands
   ├─ Units of Measure
   ├─ Warehouses / Branches
   ├─ Suppliers
   ├─ Customers
   ├─ Price Lists
   └─ Tax Rules (GST/HSN)

🛒 Purchase
   ├─ Purchase Requisition
   ├─ Purchase Orders
   ├─ Goods Receipt Note (GRN)
   ├─ Purchase Returns
   └─ Vendor Bill Matching

💰 Sales
   ├─ Quotations
   ├─ Sales Orders
   ├─ Point of Sale (POS)
   ├─ Invoices
   └─ Sales Returns

📋 Inventory
   ├─ Stock Ledger
   ├─ Stock Adjustment
   ├─ Stock Transfer
   ├─ Batch / Lot Management
   ├─ Serial Number Tracking
   ├─ Cycle Count
   └─ Reorder Automation

🏭 Warehouse Operations
   ├─ Pick – Pack – Ship
   ├─ Gate Entry (Inward/Outward)
   ├─ Return to Vendor (RTV)
   └─ Bin / Rack Management

🔖 Barcode
   ├─ Generate Barcodes/QR
   ├─ Scan (Web/Mobile)
   └─ Bulk Label Printing

📈 Reports
   ├─ Stock Reports (Current / Low / Negative / Dead / Expiry)
   ├─ Purchase Reports
   ├─ Sales Reports
   ├─ Inventory Valuation
   ├─ GST / Tax Reports
   └─ Custom Report Builder

🔌 Integrations
   ├─ Marketplaces (Amazon, Shopify, WooCommerce)
   ├─ Accounting (Tally, QuickBooks, Zoho)
   ├─ Payment Gateways
   └─ GST e-Invoice / e-Way Bill

🔔 Notifications

🕵️ Audit Logs

👤 Users & Roles
   ├─ Users
   ├─ Roles & Permissions
   └─ Activity Log

⚙️ Settings
   ├─ Company / Branch Settings
   ├─ Invoice & Numbering Rules
   ├─ Backup & Restore
   └─ System Preferences
```

**Behavior notes:**
- Master menu items with submenus expand/collapse on click (accordion style); only one master menu open at a time to keep sidebar compact
- Icon + label at full width; icon-only when sidebar is collapsed (hover reveals flyout submenu)
- "Dashboard," "Notifications," and "Audit Logs" have no submenu — direct links
- Role-based rendering: sidebar items hidden/shown based on logged-in user's permissions (e.g., Staff role sees only Inventory + Barcode + limited Reports)

---

## 3. Screens/Templates Needed

| Template Type | Examples |
|---|---|
| Dashboard | KPI cards, stock trend chart, low-stock alert widget, recent activity feed |
| List/Table view | Products list, Purchase Orders list, Stock Ledger — with filters, search, export |
| Form (create/edit) | Add Product, Create PO, Stock Adjustment |
| Detail/View page | Product detail with stock-by-warehouse breakdown, PO detail with GRN history |
| POS screen | Touch-friendly, large buttons, cart panel, barcode scan input |
| Report/Analytics page | Charts + data table + export options |
| Settings page | Tabbed sections |

---

## 4. Ready-to-Use Implementation Prompt

Copy the block below into your AI coding tool (Claude Code, v0, Lovable, Cursor, etc.) to generate the actual frontend:

```
Build a responsive web application UI for a large-scale Stock & Inventory
Management System using [React + Tailwind CSS / Vue 3 + Tailwind — choose one].

DESIGN SYSTEM:
- Theme name: "Warehouse Ledger" — an industrial-meets-clean SaaS aesthetic
- Colors: graphite #1E2328 (sidebar/headers), paper #F7F5F0 (content background),
  safety-amber #E8A33D (primary accent/buttons/active states), ink-blue #2C4A5E
  (links/secondary), stock-green #4C8B6C (success/in-stock), alert-rust #C1502E
  (low-stock/errors)
- Fonts: "Space Grotesk" for headings/dashboard numbers, "Inter" for body/UI text,
  "IBM Plex Mono" for SKU codes, batch numbers, invoice numbers, and barcodes
- Signature motif: a thin barcode-stripe divider (vertical bars of varying width)
  used as a section-break element on dashboard and report headers
- Cards use hairline 1px borders (not heavy shadows), status badges are pill-shaped

LAYOUT:
- Fixed left sidebar (collapsible to icon rail), dark graphite background
- Top bar with global search, a prominent "Scan Barcode" button, and notification bell
- Main content area with paper background

SIDEBAR MENU (master menu items expand into submenus, accordion style):
- Dashboard
- Masters: Products, Categories & Brands, Units of Measure, Warehouses/Branches,
  Suppliers, Customers, Price Lists, Tax Rules
- Purchase: Purchase Requisition, Purchase Orders, GRN, Purchase Returns,
  Vendor Bill Matching
- Sales: Quotations, Sales Orders, POS, Invoices, Sales Returns
- Inventory: Stock Ledger, Stock Adjustment, Stock Transfer, Batch/Lot Management,
  Serial Number Tracking, Cycle Count, Reorder Automation
- Warehouse Operations: Pick-Pack-Ship, Gate Entry, Return to Vendor, Bin/Rack Management
- Barcode: Generate, Scan, Bulk Label Printing
- Reports: Stock Reports, Purchase Reports, Sales Reports, Inventory Valuation,
  GST/Tax Reports, Custom Report Builder
- Integrations: Marketplaces, Accounting, Payment Gateways, GST e-Invoice
- Notifications (no submenu)
- Audit Logs (no submenu)
- Users & Roles: Users, Roles & Permissions, Activity Log
- Settings: Company/Branch Settings, Invoice & Numbering Rules, Backup & Restore,
  System Preferences

PAGES TO BUILD FIRST (MVP):
1. Dashboard — KPI cards (Total Stock Value, Low Stock Items, Pending POs,
   Today's Sales), a stock-trend line chart, a low-stock alert list, recent
   activity feed
2. Products list page — searchable/filterable table with SKU (monospace font),
   image thumbnail, category, current stock, reorder level, status badge
3. Add/Edit Product form — grouped sections (Basic Info, Pricing & Tax,
   Stock Levels, Batch/Serial settings)
4. Stock Ledger page — table of all stock movements with date, type
   (in/out/transfer/adjustment), warehouse, quantity, running balance
5. Purchase Order list + Create PO form
6. POS screen — touch-friendly, barcode scan input field, running cart,
   large checkout button

REQUIREMENTS:
- Fully responsive (mobile, tablet, desktop)
- Role-based sidebar rendering (accept a `userRole` prop/variable that
  hides menu items the role shouldn't see)
- Visible keyboard focus states, accessible color contrast
- Empty states with clear calls to action (e.g., "No products yet — Add your
  first product") in the interface's own voice, not apologetic
- Use realistic sample data (at least 15-20 sample products, a few POs, a
  few stock movements) to populate the UI for preview

Please plan the component structure first (Sidebar, TopBar, DashboardCards,
DataTable, FormLayout, POSScreen), then build page by page, starting with
Dashboard and Products list.
```

---

*Tip: If your AI tool supports iterative building, paste this prompt in stages — first "Design System + Sidebar + Dashboard," then follow up with each module — rather than asking for everything in one shot. This produces more consistent, higher-quality results.*
