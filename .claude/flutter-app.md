You are a Senior Flutter Architect and Mobile ERP Developer.

I already have an Enterprise Multi-Tenant SaaS ERP.

Existing Backend:
- Laravel REST API
- Multi-tenant architecture
- Spatie Laravel Permission
- Role and permission based access
- Tenant-specific modules
- Tenant-specific industry configuration

Existing Web:
- Vue.js frontend
- Laravel API backend

Now build a production-ready Flutter mobile application that consumes the existing Laravel REST APIs.

IMPORTANT:
Do NOT create a separate Flutter application for every industry.
Build ONE reusable Flutter application.

The same Flutter app must support:
- Footwear Shop
- Garment Shop
- Grocery/Kirana
- Supermarket
- Mobile Shop
- Electronics
- Computer Shop
- Restaurant
- Bakery
- Dairy
- Manufacturing
- Wholesale
- Distribution
- Automobile
- Healthcare
- Agriculture
- Service Center
- Logistics
- Construction
and future industries.

The visible menu and features must dynamically depend on:
1. Tenant
2. User Role
3. Permissions
4. Enabled Modules
5. Industry
6. Subscription Plan
7. Feature Flags

==================================================
1. FLUTTER ARCHITECTURE
==================================================

Use clean, scalable architecture.

Recommended:

lib/
  core/
    config/
    constants/
    network/
    auth/
    storage/
    errors/
    utils/
    theme/
    widgets/
    permissions/
    tenant/
    notifications/

  features/
    auth/
    dashboard/
    customers/
    suppliers/
    products/
    categories/
    brands/
    sales/
    purchase/
    inventory/
    warehouse/
    expenses/
    accounts/
    reports/
    pos/
    manufacturing/
    logistics/
    service/
    notifications/
    profile/
    settings/
    ai/

  industry/
    retail/
    footwear/
    garment/
    grocery/
    restaurant/
    manufacturing/
    logistics/

  routes/
  app.dart
  main.dart

Use feature-first modular architecture.

Do not put all code inside main.dart.

==================================================
2. STATE MANAGEMENT
==================================================

Use Riverpod.

Requirements:
- Proper providers
- AsyncNotifier where appropriate
- Repository pattern
- API state handling
- Loading state
- Error state
- Empty state
- Pagination state

Avoid unnecessary rebuilds.

==================================================
3. API CLIENT
==================================================

Use Dio.

Create:

ApiClient

with:
- Base URL
- Authorization
- Tenant headers
- Request interceptor
- Response interceptor
- Error handling
- Timeout
- Retry where appropriate
- Refresh token handling
- Logging only in development

Never expose:
- Laravel secrets
- Database credentials
- Third-party secret keys
- API secret keys

in Flutter.

==================================================
4. AUTHENTICATION
==================================================

Implement:

Login
Logout
Refresh Token
Remember Session
Forgot Password
Reset Password
Change Password
Profile

Support secure token storage.

Use flutter_secure_storage.

Do NOT store sensitive authentication credentials in plain SharedPreferences.

Handle:
401
403
422
429
500
network timeout
offline

appropriately.

==================================================
5. MULTI-TENANT
==================================================

After login API may return:

tenant
user
role
permissions
modules
industry
subscription
feature_flags

Create a TenantContext.

Example:

TenantContext:
- tenantId
- companyName
- industry
- subscriptionPlan
- enabledModules
- permissions
- featureFlags
- currency
- timezone

Every API request must automatically use the correct tenant context according to the Laravel API contract.

Never allow users to manually switch tenant_id through arbitrary request parameters.

==================================================
6. DYNAMIC MENU
==================================================

Do NOT hardcode all menus as permanently visible.

Create a dynamic menu system.

Example:

Dashboard

Sales
  - POS
  - Sales Invoice
  - Sales Return

Purchase
  - Purchase Order
  - Purchase Invoice
  - Purchase Return

Inventory
  - Stock
  - Transfer
  - Adjustment

Customers
Suppliers
Expenses
Reports

Then industry modules:

Footwear:
  - Size
  - Color
  - Brand
  - Exchange
  - Warranty

Restaurant:
  - Tables
  - KOT
  - Kitchen
  - Recipes

Garment:
  - Size
  - Color
  - Fabric
  - Tailor
  - Embroidery
  - Printing

Manufacturing:
  - BOM
  - Production
  - QC
  - Machines

Logistics:
  - Shipments
  - Trips
  - POD
  - GPS

The menu must be generated from:

permissions + enabledModules + industry + featureFlags.

==================================================
7. DASHBOARD
==================================================

Create a professional ERP dashboard.

Show:

Today's Sales
Today's Purchase
Profit
Outstanding
Cash
UPI
Low Stock
Top Products
Recent Orders
Recent Customers

Use API-driven widgets.

Do not hardcode dashboard values.

Support configurable widgets.

Owner should be able to see business KPIs.

==================================================
8. PRODUCTS
==================================================

Create:

Product List
Product Detail
Create Product
Edit Product
Delete Product
Search
Filter
Sort
Pagination

Product fields should support:

SKU
Barcode
QR Code
Name
Category
Brand
Unit
Purchase Price
Selling Price
Tax
Discount
Stock
Images

Support dynamic custom fields from backend.

==================================================
9. FOOTWEAR SUPPORT
==================================================

Footwear-specific product fields:

Brand
Category
Gender
Size
Color
Material
Style
Season
SKU
Barcode

Size examples:

UK
US
EU
Kids

Support product variants:

Product
  ├── Size 6
  ├── Size 7
  ├── Size 8
  ├── Size 9
  └── Size 10

Each variant may have:

SKU
Barcode
Price
Stock
Color
Size

Do not create a separate Footwear app.

==================================================
10. SALES / POS
==================================================

Create fast POS UI.

Features:

Product Search
Barcode Scan
Cart
Quantity
Discount
Tax
Custom Percentage Discount
Customer Selection
Payment Method
Cash
UPI
Card
Split Payment

Invoice generation.

Support:
- Sales Invoice
- Sales Return
- Exchange
- Credit Note

Optimize POS for quick shop counter usage.

==================================================
11. BARCODE / QR
==================================================

Integrate camera barcode scanning.

Flow:

Scan
→ Find Product
→ Add to Cart

Also support:

Product Barcode
Stock Counting
Stock Transfer
Purchase Receiving

Use a reliable Flutter barcode scanning package.

Keep scanner integration behind an abstraction so the package can be replaced later.

==================================================
12. INVENTORY
==================================================

Screens:

Stock Overview
Stock Detail
Stock Adjustment
Stock Transfer
Stock Count
Low Stock
Dead Stock
Damaged Stock

Barcode-based stock counting.

Warehouse selection.

==================================================
13. PURCHASE
==================================================

Purchase Order
Purchase Invoice
GRN
Purchase Return
Supplier
Payment

Support barcode scanning during receiving.

==================================================
14. CUSTOMER
==================================================

Customer list
Customer detail
Create/Edit
Sales history
Outstanding
Payment history
Loyalty points
Notes
Contact

Call customer
WhatsApp customer

Use platform-safe URL launching.

==================================================
15. SUPPLIER
==================================================

Supplier list
Purchase history
Outstanding
Payment history
Contact
Notes

==================================================
16. EXPENSES
==================================================

Create expense.

Fields:

Category
Amount
Date
Payment Method
Notes
Attachment

Examples:

Rent
Electricity
Salary
Transport
Marketing
Miscellaneous

==================================================
17. REPORTS
==================================================

API-driven reports.

Sales
Purchase
Profit
Inventory
Customer
Supplier
Expense
GST
Outstanding

Filters:

Date
Branch
Warehouse
Customer
Supplier
Category
Brand

Do not load huge datasets into memory.

Use server-side pagination/filtering.

==================================================
18. OFFLINE SUPPORT
==================================================

ERP mobile app should gracefully handle poor internet.

Implement:

Network Detection
Local Cache
Offline Read
Pending Sync Queue

For critical transactions:

Create local pending transaction
→ Sync when internet returns
→ Server validates
→ Mark synced

Never blindly duplicate invoices.

Use idempotency keys for transaction APIs if backend supports them.

==================================================
19. PUSH NOTIFICATIONS
==================================================

Firebase Cloud Messaging.

Notifications:

Low Stock
New Order
Payment Received
Outstanding Reminder
Approval Required
System Notification

Handle:
Foreground
Background
Terminated state

==================================================
20. CAMERA / DOCUMENTS
==================================================

Support:

Camera
Gallery
Document Upload

Use for:

Product Image
Customer Document
Invoice Attachment
Expense Receipt
Job Card
POD
Signature

Upload using multipart APIs or presigned URLs according to backend design.

==================================================
21. LOCATION
==================================================

Use GPS only where required.

Features:

Salesman Visit
Delivery
Driver
Service Technician
Attendance

Do not continuously track location unless explicitly enabled and required by the tenant.

==================================================
22. THERMAL PRINTER
==================================================

Architecture must support:

Bluetooth
Network printer

Receipt printing.

Create PrinterService abstraction.

Do not tightly couple business logic to one printer package.

==================================================
23. WHATSAPP
==================================================

Do not put WhatsApp API secret keys inside Flutter.

Flutter:

Generate invoice/share action
→ Laravel API
→ WhatsApp provider

Support:

Invoice
Payment Reminder
Order Confirmation
Delivery Update

==================================================
24. AI ASSISTANT
==================================================

Create an AI Assistant screen.

User can ask:

"What was today's sales?"

"Which products are low stock?"

"Which footwear sizes are selling fastest?"

"Which customers have outstanding payments?"

"Suggest products to purchase."

AI requests must go through Laravel backend.

Never expose OpenAI/Anthropic/etc. secret keys in Flutter.

==================================================
25. SECURITY
==================================================

Implement:

Secure token storage
Certificate/HTTPS validation according to production environment
Permission checks
Biometric app lock optional
Auto logout
Session expiry
Device/session management
No secrets in source code
No sensitive data in logs
No production API debugging logs

IMPORTANT:

Flutter permission checks are UX/security assistance only.

Laravel remains the final authorization layer.

==================================================
26. PERFORMANCE
==================================================

Optimize for low-end Android devices.

Use:

Pagination
Lazy loading
Image caching
Debouncing
Memoization where useful
Minimal rebuilds
Background sync
Compressed images

Target:

Smooth scrolling
Fast POS
Fast barcode scanning
Low memory usage

==================================================
27. UI/UX
==================================================

Design should be:

Professional
Simple
Fast
Gujarati-friendly
English-friendly
Responsive

Support:

Light Theme
Dark Theme

Use reusable components:

AppButton
AppTextField
AppDropdown
AppSearch
AppDataTable/Card
AppEmptyState
AppErrorState
AppLoading
AppDialog
AppBottomSheet
AppDatePicker

==================================================
28. LOCALIZATION
==================================================

Prepare architecture for:

English
Gujarati
Hindi

Do not hardcode UI strings.

Use localization files.

==================================================
29. API CONTRACT
==================================================

Assume Laravel APIs follow:

GET
POST
PUT/PATCH
DELETE

Standard response:

{
  "success": true,
  "message": "Success",
  "data": {},
  "errors": []
}

Handle validation errors field-by-field.

==================================================
30. OPENAPI
==================================================

Prefer generating API models/repositories from OpenAPI documentation if available.

If API documentation is missing:

First create an API contract document listing:

Endpoint
Method
Headers
Request
Response
Authentication
Permission
Pagination
Errors

Do not invent API endpoints silently.

Use TODO markers where the backend contract is missing.

==================================================
31. TESTING
==================================================

Create:

Unit Tests
Widget Tests
Repository Tests
Provider Tests

Critical integration tests:

Login
Tenant resolution
Product search
Barcode scan
POS sale
Payment
Stock update
Offline sync
Logout

==================================================
32. DEVELOPMENT RULES
==================================================

Do NOT:

- Put everything in one file
- Hardcode tenant IDs
- Hardcode product data
- Hardcode dashboard values
- Hardcode permissions
- Store secrets in Flutter
- Bypass Laravel authorization
- Duplicate business logic unnecessarily
- Add random packages without justification

DO:

- Reusable components
- Clean architecture
- Strong typing
- Error handling
- API repositories
- Modular features
- Documentation
- Tests
- Production-ready code

==================================================
33. DEVELOPMENT PHASES
==================================================

Do not attempt to generate the entire application in one step.

Work phase by phase.

Phase 1:
Project setup
Architecture
Theme
Localization
Networking
Secure Storage

Phase 2:
Authentication
Tenant Context
Role
Permission
Dynamic Menu

Phase 3:
Dashboard

Phase 4:
Customers
Suppliers
Products

Phase 5:
Sales
POS
Barcode

Phase 6:
Purchase
Inventory
Warehouse

Phase 7:
Expenses
Reports

Phase 8:
Notifications
Firebase

Phase 9:
Offline Sync

Phase 10:
Footwear Industry Pack

Phase 11:
Other Industry Packs

Phase 12:
AI Assistant

Phase 13:
Printer
GPS
Camera
Documents

Phase 14:
Testing
Performance
Security

Phase 15:
Android Release

Phase 16:
iOS Release

==================================================
34. IMPORTANT CLAUDE CODE WORKFLOW
==================================================

Before modifying code:

1. Inspect existing project.
2. Inspect Laravel API documentation if available.
3. Inspect existing Flutter files.
4. Identify current architecture.
5. Do not overwrite working code unnecessarily.
6. Create a short implementation plan.
7. Implement one phase at a time.
8. Run analyzer.
9. Run tests.
10. Fix errors.
11. Summarize changed files.

For every feature report:

- Files created
- Files modified
- API endpoints used
- Packages added
- Database/API assumptions
- Tests added
- Remaining TODOs

Never claim a feature is complete if the backend API is unavailable.

==================================================
35. FIRST TASK
==================================================

Do NOT start building all modules.

First inspect the existing Flutter project.

Then provide:

1. Current project structure
2. Existing dependencies
3. Existing architecture
4. Existing API integration
5. Existing authentication
6. Missing architecture pieces
7. Recommended folder structure
8. Required packages
9. Implementation phases
10. Risks and API contract requirements

After analysis, wait for approval before implementing major changes.

The final application must be production-ready, scalable, multi-tenant, role-aware, industry-aware, API-first, and ready for Android + iOS.