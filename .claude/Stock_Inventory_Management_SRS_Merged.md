# Software Requirements Specification (SRS)
## Stock & Inventory Management System (Large-Scale Web Application)
### Merged & Consolidated Requirements Document

**Version:** 2.0 (Merged)
**Document Type:** Functional + Non-Functional Requirements Specification

# Technology Stack

## Frontend

Framework: Vue 3 (^3.5)
Language: TypeScript (~5.9)
Build Tool: Vite 7
Router: Vue Router 4
State Management: Pinia 3
HTTP Client: Axios 1
Alert Library: SweetAlert2
Validation: VeeValidate + Yup
UI Framework: Vuetify 3 or PrimeVue
Charts: ApexCharts
Icons: Heroicons
Date Library: Day.js
File Upload: Dropzone
Rich Text Editor: Quill Editor

Node Version:
^20.19 or >=22.12

---

## Backend

Framework: Laravel 12

Authentication:
Laravel Sanctum

Authorization:
Role Based Access Control (RBAC)

Permission Package:
spatie/laravel-permission

Database:
MySQL 8

Cache:
Redis

Queue:
Redis Queue

Storage:
Local + AWS S3

PDF:
barryvdh/laravel-dompdf

Excel:
maatwebsite/excel

Notifications:
Mail + Database + Firebase Push Notification

API:
REST API

Logging:
Laravel Log + Activity Log

Testing:
PHPUnit

---

## 1. Project Overview

A large-scale, multi-warehouse, multi-user Stock & Inventory Management System (SIMS) designed to track goods from procurement to sale/consumption, across multiple locations, in real time, with full audit trail, barcode-based operations, and marketplace integrations.

**Target Users:** Retail chains, distributors, manufacturers, warehouses, wholesalers, e-commerce sellers with multiple branches/godowns.

---

## 2. Goals & Objectives

- Real-time visibility of stock across all warehouses/branches
- Reduce stock-out, overstock, and dead-stock situations
- Automate purchase, sales, and stock transfer workflows
- Provide accurate reporting/analytics for decision-making
- Support barcode/QR-based scanning for speed and accuracy
- Ensure data integrity, security, and role-based access
- Scale to handle lakhs of SKUs, thousands of transactions/day, and multiple concurrent users
- Integrate seamlessly with marketplaces (Amazon, Shopify, WooCommerce) and ERP/accounting systems

---

## 3. User Roles & Permissions

| Role | Permissions |
|---|---|
| **Super Admin** | Full system access, manage companies/branches, global settings |
| **Admin/Owner** | Manage own company data, users, reports, settings |
| **Warehouse Manager** | Manage stock, transfers, approvals for assigned warehouse |
| **Purchase Manager** | Create/manage purchase orders, vendor management |
| **Sales Manager / Executive** | Create sales orders/invoices, view stock availability |
| **Staff / Picker** | Scan-based stock in/out, picking, packing |
| **Accountant** | View financial reports, GST/tax reports, payments |
| **Auditor (read-only)** | View-only access to logs, stock history, reports |

Role-Based Access Control (RBAC) with granular permissions per module (view/add/edit/delete/approve).

---

## 4. Core Modules & Functional Requirements

### 4.1 Master Data Management

#### 4.1.1 Product Management
- Product name, unique SKU, Barcode/QR code
- Category (parent + child hierarchy) & category attributes
- Brand, Unit of measure (pcs/kg/ltr) with multi-unit conversion support
- Product images
- Cost price, Selling price, Tax % (HSN code)
- Minimum stock level, Maximum stock level, Reorder level
- Expiry date support, Batch number, Serial number tracking
- Product variants (size/color/etc.)

#### 4.1.2 Category Management
- Parent/child category hierarchy
- Category-specific attributes

#### 4.1.3 Warehouse Management
- Main warehouse, Branch warehouse, Transit warehouse, Damaged-goods warehouse
- Sub-location tracking: racks, bins, shelves (put-away logic)

#### 4.1.4 Supplier/Vendor Management
- Supplier profile, GST details, Credit limit, Payment terms, Purchase history

#### 4.1.5 Customer Management
- Customer details, Credit limits, Outstanding balance, Purchase history

#### 4.1.6 Price Lists
- Multiple price levels (MRP, wholesale, retail, discount-based)

---

### 4.2 Inventory Core

#### Stock Inward
- Purchase entry, Opening stock, Returns inward, Transfer inward

#### Stock Outward
- Sales, Damage, Transfer, Sample issue, Consumption

#### Stock Ledger
- Tracks every stock movement (immutable, transaction-level)

#### Stock Adjustment
- For physical mismatch, damage, theft, correction — with reason codes and approval workflow

#### Stock Transfer
- Warehouse A → Warehouse B, with in-transit tracking

#### Costing Methods
- FIFO / LIFO / Weighted Average (configurable per company)

#### Cycle Counting
- Physical stock verification with variance reporting

---

### 4.3 Purchase Module
- Purchase requisition
- Purchase Order (PO) with multi-level approval workflow
- Goods Receipt Note (GRN) — supports partial receipt
- Purchase Return / Debit note
- 3-way matching (PO vs GRN vs Vendor Invoice)
- Landed cost calculation (freight, duty, other charges added to product cost)

### 4.4 Sales Module
- Sales Quotation → Sales Order → Invoice workflow
- Point of Sale (POS) for retail counters
- Sales Return / Credit note
- Multiple payment modes (cash, card, UPI, credit)
- Backorder handling when stock is insufficient

### 4.5 Advanced Inventory Features
- Batch/Lot management with expiry tracking
- Serial number tracking for high-value/serialized items
- Expiry management with proactive alerts
- Dead-stock / slow-moving stock identification
- Reorder automation (auto-generate purchase requisition on low stock)

### 4.6 Warehouse Operations
- Bin/rack-level location tracking
- Pick-pack-ship workflow for order fulfillment
- Inward/outward gate entry logging
- Return-to-vendor (RTV) process

### 4.7 Barcode System
- Barcode/QR code generation
- Barcode scanning (mobile app / handheld scanner support)
- Bulk label printing

### 4.8 Reports Module
- Current stock, Low stock, Negative stock, Dead stock, Expiry stock
- Stock movement report (item-wise, warehouse-wise, date-range)
- Purchase reports, Sales reports
- Inventory valuation report
- Fast-moving / slow-moving analysis
- Vendor performance report
- Profitability report (per product/category)
- GST/Tax reports (input/output, HSN summary)
- Custom report builder with export (Excel, PDF, CSV)
- Dashboard with KPIs (total stock value, top SKUs, low-stock alerts, pending POs)

### 4.9 Notifications & Alerts
- Low stock / reorder alert
- Expiry alert
- Payment due / Purchase due
- Transfer received confirmation
- PO approval pending
- Stock discrepancy alert after cycle count
- Delivery channels: Email, SMS, in-app, push notification

### 4.10 Audit Logs
- Tracks all user activity — who, when, what changed (immutable log)

### 4.11 API Integrations
- Marketplaces: Amazon, Shopify, WooCommerce
- ERP systems
- Accounting software (Tally, QuickBooks, Zoho Books)
- Payment gateways (Razorpay, Stripe, PayU)
- SMS/Email gateway (Twilio, MSG91, SendGrid)
- GST e-invoicing & e-way bill API (India)
- Logistics/courier API integration

### 4.12 System Administration
- Multi-company / multi-branch support (single login, switch context)
- Data backup & restore
- Role & permission management UI
- System settings (invoice numbering, financial year, tax rules, currency)

---

## 5. Non-Functional Requirements

| Category | Requirement |
|---|---|
| **Scalability** | Support 100,000+ SKUs, 1000+ concurrent users, multi-region deployment |
| **Performance** | Page load < 2 sec; search/filter response < 1 sec even with large datasets |
| **Availability** | 99.9% uptime; horizontal scaling with load balancer |
| **Security** | HTTPS/TLS, encrypted data at rest, OWASP Top-10 compliance, 2FA for admin accounts |
| **Data Integrity** | ACID-compliant transactions for stock movements; no negative stock without explicit override |
| **Auditability** | Immutable audit trail for all inventory transactions |
| **Backup/DR** | Automated daily backups, disaster recovery plan (RPO/RTO defined) |
| **Localization** | Multi-language (English, Hindi, Gujarati), multi-currency support |
| **Accessibility** | Responsive UI for desktop, tablet, mobile |
| **Offline Support** | Warehouse scanning app should support offline mode with sync-on-connect |
| **Realtime Updates** | Live stock updates across users/screens via WebSockets |

---

## 6. Large-Scale Architecture

### Frontend Options
- Vue.js for web
- Filament (Laravel admin panel) for internal admin/back-office
- Flutter for mobile scanning app

### Database
- MySQL (relational, for transactional integrity)
- Redis (caching + queue backend)

### Search
- Elasticsearch (fast product search across large catalogs)

### Realtime
- WebSockets / Pusher (live stock updates, notifications)

### Storage
- Amazon S3 (product images, report files)

### Barcode
- ZXing (barcode generation/scanning library)

### CDN / Edge
- Cloudflare (CDN, DDoS protection, edge caching)

### Message Queue
- RabbitMQ / Kafka / Laravel Queues (async: notifications, report generation, marketplace sync)

### Hosting/Infra
- AWS / Azure / GCP, Docker + Kubernetes, auto-scaling groups

### Monitoring
- Grafana + Prometheus, Sentry (error tracking), Laravel Horizon (if Laravel)

---

## 7. Major Database Tables (Consolidated)

| Table | Purpose |
|---|---|
| users | System users |
| roles | User roles |
| permissions | Role-based permissions |
| products | Product master |
| product_variants | Size/color/variant data |
| categories | Category hierarchy |
| brands | Brand master |
| warehouses | Warehouse/branch master |
| warehouse_stocks | Current stock per warehouse per SKU |
| stock_ledgers | Every stock movement transaction |
| stock_transfers | Inter-warehouse transfer records |
| suppliers | Vendor master |
| customers | Customer master |
| purchase_orders | PO header |
| purchase_items | PO line items |
| sales_orders | Sales order header |
| sales_items | Sales order line items |
| invoices | Invoice records |
| returns | Sales/purchase return records |
| adjustments | Stock adjustment records |
| batches | Batch/lot tracking |
| serial_numbers | Serialized item tracking |
| notifications | Alert/notification log |
| audit_logs | User activity audit trail |
| price_lists | Multi-tier pricing |
| tax_rules | GST/tax slab configuration |

---

## 8. Suggested Technology Stack (Final Recommendation)

| Layer | Primary Choice | Alternative |
|---|---|---|
| **Backend** | Laravel 12 (PHP) | Node.js (NestJS) / Django |
| **Frontend (Web)** | Vue.js or React.js | Next.js |
| **Admin Panel** | Filament (Laravel) | Custom React admin |
| **Mobile (Scanning App)** | React Native / Flutter | — |
| **Database** | PostgreSQL / MySQL | — |
| **Cache/Queue** | Redis + Laravel Horizon | Redis + Bull (Node) |
| **Search** | Elasticsearch | Meilisearch |
| **Realtime** | Pusher / WebSockets | Socket.io |
| **Barcode** | ZXing | QuaggaJS |
| **Storage** | Amazon S3 | Azure Blob |
| **CDN/Security** | Cloudflare | AWS CloudFront |
| **Monitoring** | Sentry + Laravel Horizon | Grafana + Prometheus |

---

## 9. Development Roadmap (Phased)

1. **Phase 1 (MVP):** Master data, Purchase, Sales, basic stock ledger, single warehouse
2. **Phase 2:** Multi-warehouse, stock transfer, batch/serial tracking, barcode scanning
3. **Phase 3:** Reporting/analytics dashboard, alerts/notifications, reorder automation
4. **Phase 4:** Marketplace & accounting integrations (Amazon, Shopify, WooCommerce, Tally), GST e-invoicing
5. **Phase 5:** Mobile scanning app, offline sync, realtime updates, advanced analytics/forecasting

---

## 10. Assumptions

- Primarily targeted for Indian market (GST compliance included); adaptable to other regions.
- Web app is the primary interface; mobile app for warehouse staff is a supporting component.
- Multi-tenant architecture assumed (one deployment serving multiple companies); adjustable to single-tenant per client if required.

---

*This is a consolidated draft merging two requirement sources. Final scope should be refined based on business type (retail/manufacturing/distribution/e-commerce), expected transaction volume, and specific compliance/integration needs.*
