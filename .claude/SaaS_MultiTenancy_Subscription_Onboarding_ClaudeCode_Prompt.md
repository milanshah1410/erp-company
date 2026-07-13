# Claude Code Prompt: Multi-Tenant SaaS Layer
## Subscription Model + Registration/Onboarding + Subdomain Provisioning + Separate Database per Tenant

---

## 1. Architecture Decision (Context Before the Prompt)

**Multi-tenancy model chosen: Database-per-Tenant (fully isolated)**

| Aspect | Approach |
|---|---|
| Tenant identification | Subdomain-based (`companyname.yourapp.com`) |
| Data isolation | Separate physical database per tenant (not shared-schema) — best for large scale, security, and per-tenant backup/scaling |
| Central database | Stores tenants, users (super-admin level), subscription plans, billing, domain mapping |
| Tenant database | Stores all business data from the Inventory SRS (products, stock, purchase, sales, etc.) — one DB created automatically per tenant at signup |
| Recommended package (Laravel) | `stancl/tenancy` — mature, handles subdomain routing, DB creation/switching, and tenant-aware queues/cache out of the box |

**Why separate DB per tenant instead of shared DB with tenant_id column:**
- True data isolation (no risk of cross-tenant data leaks in queries)
- Easier to scale — heavy tenants can be moved to their own DB server later
- Easier per-tenant backup/restore and GDPR-style data deletion
- Trade-off: slightly more complex migrations (must run on every tenant DB) — acceptable for a large-scale system

---

## 2. Central Database Schema (Landlord DB)

| Table | Purpose |
|---|---|
| `tenants` | id, company_name, status (trial/active/suspended/cancelled), created_at |
| `domains` | tenant_id, domain (subdomain), is_primary |
| `plans` | id, name (Starter/Growth/Enterprise), price, billing_cycle, feature_limits (JSON: max_users, max_warehouses, max_products, api_access) |
| `subscriptions` | tenant_id, plan_id, status (trialing/active/past_due/cancelled), trial_ends_at, current_period_end |
| `subscription_invoices` | subscription_id, amount, status, payment_gateway_ref |
| `central_users` | super-admin / platform-level users (not tenant users) |
| `onboarding_progress` | tenant_id, step_completed (JSON), completed_at |

---

## 3. Subscription Plan Structure (example)

| Plan | Price (monthly) | Limits |
|---|---|---|
| **Starter** | ₹999 | 1 warehouse, 3 users, 500 products, email support |
| **Growth** | ₹2,999 | 5 warehouses, 15 users, 10,000 products, barcode + reports |
| **Enterprise** | Custom | Unlimited, API access, integrations, dedicated support |

- 14-day free trial on signup, no card required for trial
- Plan enforcement via middleware checking `feature_limits` against current usage before allowing create actions (e.g., block adding a 4th warehouse on Starter plan)
- Upgrade/downgrade with prorated billing
- Payment gateway: Razorpay (India) or Stripe (global) subscription/recurring billing API

---

## 4. Registration & Onboarding Flow

```
Step 1: Sign Up
   - Company name, admin name, email, password, phone
   - Choose subdomain (auto-suggested from company name, live availability check)
   - Email verification (OTP or magic link)

Step 2: Auto-Provisioning (background job, show progress screen)
   - Create tenant record in central DB
   - Create subdomain DNS/domain mapping entry
   - Create tenant database (run all migrations)
   - Seed default data: default roles (Admin/Manager/Staff), default units (pcs/kg/ltr),
     default tax rates, sample warehouse ("Main Warehouse")
   - Create tenant admin user (linked to the signup email)
   - Start 14-day trial subscription (status = trialing)

Step 3: Onboarding Wizard (inside the new tenant subdomain)
   - Welcome screen
   - Add company logo/details
   - Add first warehouse (pre-filled with "Main Warehouse", editable)
   - Add first 3-5 products (guided, with option to "Skip and import later" / CSV import)
   - Invite team members (optional, can skip)
   - Redirect to Dashboard with a "Getting Started" checklist widget

Step 4: Post-Onboarding
   - Trial countdown banner shown until day 14
   - Upgrade prompt / plan selection before trial ends
   - Grace period + downgrade-to-read-only if trial ends without payment
```

---

## 5. Subdomain Provisioning Requirements

- Reserved subdomain list (block: www, api, admin, app, mail, ftp, etc.)
- Subdomain format validation: lowercase, alphanumeric + hyphen, 3-30 chars
- Wildcard DNS (`*.yourapp.com`) pointed to the app server; app resolves tenant from subdomain via middleware
- SSL: wildcard certificate (`*.yourapp.com`) via Let's Encrypt or Cloudflare
- Optional future feature: custom domain mapping (tenant can point their own domain via CNAME)

---

## 6. Ready-to-Use Claude Code Prompt

Paste the block below into Claude Code to implement this layer on top of the existing Laravel-based Inventory system:

```
I'm building a multi-tenant SaaS Stock & Inventory Management System on Laravel.
Implement the multi-tenancy, subscription, and onboarding layer as described below.

TECH STACK:
- Laravel 12
- Package: stancl/tenancy (v3) for multi-tenancy with subdomain identification
  and one database per tenant
- MySQL/PostgreSQL
- Queue: Redis + Laravel Horizon (for background tenant provisioning jobs)
- Payment: Razorpay Subscriptions API (recurring billing)

TASK 1 — Install & configure stancl/tenancy:
- Install stancl/tenancy package, publish config
- Set up the central (landlord) database connection and a dynamic tenant
  database connection
- Configure subdomain identification middleware so requests to
  `{tenant}.yourapp.com` resolve to the correct tenant and switch the DB
  connection automatically
- Add reserved subdomain list (www, api, admin, app, mail, ftp, staging,
  billing, support) that cannot be registered

TASK 2 — Central (landlord) database schema:
Create migrations for these tables in the CENTRAL database:
- tenants (id, company_name, status: trial/active/suspended/cancelled, created_at)
- domains (tenant_id, domain, is_primary)
- plans (id, name, price, billing_cycle, feature_limits as JSON)
- subscriptions (tenant_id, plan_id, status, trial_ends_at, current_period_end)
- subscription_invoices (subscription_id, amount, status, payment_gateway_ref)
- central_users (platform/super-admin users, separate from tenant users)
- onboarding_progress (tenant_id, step_completed JSON, completed_at)

Seed the `plans` table with three plans: Starter (₹999/mo, 1 warehouse,
3 users, 500 products), Growth (₹2,999/mo, 5 warehouses, 15 users,
10,000 products), Enterprise (custom pricing, unlimited, flag-based features).

TASK 3 — Registration flow:
Build a registration endpoint/page that collects: company name, admin name,
email, password, phone, and a subdomain field with live-availability AJAX
check (validate format: lowercase alphanumeric + hyphen, 3-30 chars, not in
reserved list, not already taken). Send email verification (OTP or signed
link) before activating the account.

TASK 4 — Tenant auto-provisioning (queued job):
On successful signup + email verification, dispatch a queued job that:
1. Creates the tenant record and domain record in the central DB
2. Creates a new tenant database and runs all tenant migrations
   (products, categories, warehouses, stock_ledgers, purchase_orders,
   sales_orders, users, roles, permissions, etc. — use the existing
   Inventory system's tenant-side migrations)
3. Seeds default data into the new tenant DB: default roles (Admin,
   Warehouse Manager, Purchase Manager, Sales Manager, Staff, Auditor),
   default units of measure, a default "Main Warehouse", default tax rates
4. Creates the tenant's first Admin user (linked to signup email/password)
5. Creates a subscription record with status=trialing and trial_ends_at =
   now + 14 days
6. Sends a "Your account is ready" email with a link to their subdomain
Show a progress/status screen to the user while this job runs (poll a
status endpoint or use websockets/Pusher for live updates).

TASK 5 — Onboarding wizard (runs inside the new tenant subdomain):
Build a multi-step wizard shown on first login:
1. Welcome + company logo upload
2. Confirm/edit first warehouse details
3. Add first products (manual entry for up to 5, OR CSV import option,
   OR "Skip for now")
4. Invite team members by email (optional, skippable)
5. Finish → redirect to Dashboard with a persistent "Getting Started"
   checklist widget that tracks completion of each onboarding step
   (read/write onboarding_progress in the central DB)

TASK 6 — Subscription & plan enforcement middleware:
Create middleware that runs on tenant requests to check the tenant's
current plan `feature_limits` against current usage (count of warehouses,
users, products) before allowing create actions. Return a clear
upgrade-prompt response (not a generic 403) when a limit is hit, e.g.
"You've reached the 1-warehouse limit on the Starter plan — upgrade to add
more warehouses," with a link to the billing/upgrade page.

TASK 7 — Billing integration:
Integrate Razorpay Subscriptions API:
- Plan selection / upgrade / downgrade page
- Webhook handler for payment success, failure, and subscription
  cancellation events, updating the `subscriptions` and
  `subscription_invoices` tables accordingly
- Trial-ending reminder emails (3 days before, 1 day before trial ends)
- Grace period handling: if trial ends with no active subscription, set
  tenant status to "suspended" and show a read-only/upgrade-required
  screen instead of the dashboard

TASK 8 — Wildcard SSL & DNS notes (document only, not code):
Add a README section documenting that wildcard DNS (*.yourapp.com) must
point to the app server, and a wildcard SSL certificate (Let's Encrypt or
Cloudflare) must be configured — this is an infra/DevOps step outside the
Laravel app itself.

Please implement task by task, starting with Task 1 and Task 2 (tenancy
package setup + central schema), confirm it works with a test tenant
created via Artisan command, then proceed to the registration flow and
provisioning job.
```

---

*Tip: Feed this prompt to Claude Code one task at a time (Task 1 → confirm → Task 2 → confirm...) rather than all at once — multi-tenancy code is easy to get subtly wrong (wrong DB connection active at the wrong time), so verifying each task before moving on will save debugging time later.*
