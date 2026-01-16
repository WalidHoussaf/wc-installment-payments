
# WC Installment Payments

WordPress/WooCommerce plugin that adds an **installment payment** engine based on:

- a **data layer** (custom SQL tables)
- an **orchestrator** (lightweight service-locator in `Plugin`)
- a **scheduler** (WP-Cron) to execute due payments
- a **retry strategy** (pure PHP, testable)
- an **admin dashboard** (WP_List_Table) with list view + detail view
- a **webhook dispatcher** with HMAC signature to notify external systems (recovery)

> Goal: demonstrate a "senior/lead" architecture: separation of concerns, robustness, traceability, extensibility.

---

## Table of Contents

- Installation
- Project structure
- Data model (tables)
- Functional flows
  - Plan creation at checkout
  - Scheduler (automation)
  - Retry (smart recovery)
  - Recovery webhook (HMAC)
- Admin Dashboard
- Configuration (current state)
- Robustness / performance notes
- Development & debug

---

## Installation

1. Copy the `wc-installment-payments` folder into:
   `wp-content/plugins/`
2. Activate the plugin in **WP Admin → Plugins**.
3. Make sure **WooCommerce** is active.

Upon activation, the plugin creates its SQL tables via `dbDelta()`.

---

## Project structure

```
wc-installment-payments/
├── wc-installment-payments.php         # Bootstrap plugin + autoloader + hooks activation
├── uninstall.php                       # Delete tables/options on uninstall
├── src/
│   ├── Plugin.php                      # Orchestrator (service locator + wiring)
│   ├── Core/
│   │   ├── Activator.php               # Table creation + version
│   │   ├── Deactivator.php             # Cleanup WP-Cron
│   │   ├── Scheduler.php               # Automation (WP-Cron + due date processing)
│   │   └── WebhookDispatcher.php       # HMAC signed webhook (recovery)
│   ├── Payments/
│   │   ├── PlanManager.php             # Data layer table plans
│   │   ├── PaymentManager.php          # Data layer table payments
│   │   ├── CheckoutHandler.php         # WooCommerce bridge (plan creation + installments)
│   │   └── RetryStrategy.php           # Pure PHP (D+3, D+7, D+14)
│   └── Admin/
│       ├── AdminMenu.php               # WooCommerce menu + list/detail routing
│       ├── PlanListTable.php           # WP_List_Table (plans list)
│       └── PlanDetailView.php          # Plan detail view (payment schedule)
└── README.md
```

---

## Data model (tables)

The plugin uses 2 tables:

### `wp_wcip_installment_plans`

- `id` (PK)
- `order_id` (WooCommerce Order ID)
- `customer_id` (WordPress User ID)
- `total_amount` (DECIMAL)
- `installments_count` (INT)
- `status` (ex: `active`, `completed`, `breach`)
- `created_at` (DATETIME)

### `wp_wcip_installment_payments`

- `id` (PK)
- `plan_id` (logical FK to `plans.id`)
- `stripe_payment_intent_id` (VARCHAR)
- `amount` (DECIMAL)
- `due_date` (DATETIME)
- `status` (ex: `pending`, `paid`, `failed`, `failed_final`)
- `attempts` (INT)
- `last_error` (TEXT)

> At this stage, the "FK" is not an SQL constraint: this is intentional to keep it simple (WordPress + dbDelta). Can be reinforced later.

---

## Functional flows

### 1) Plan creation at checkout (WooCommerce bridge)

File: `src/Payments/CheckoutHandler.php`

Hook: `woocommerce_checkout_order_processed`

Business rule (demo):

- if order total **>= 100€** ⇒ create a **3-installment** plan

What the handler does:

1. Checks eligibility (total)
2. Creates the "parent" plan via `PlanManager::create_plan()`
3. Calculates the schedule with a **penny-perfect** algorithm (cents)
4. Creates the "child" payments via `PaymentManager::create_payment()`
   - 1st installment `paid` (paid via standard WooCommerce checkout)
   - following ones `pending` with a `due_date` at D+30, D+60...
5. Adds a note to the order

#### Penny-perfect (no loss of cents)

The calculation works in cents to avoid float errors:

- `100 / 3` ⇒ `33.33 + 33.33 + 33.34`

#### Transaction (best effort)

The handler opens an SQL transaction (`START TRANSACTION`) and rollback if:

- plan creation fails
- installment creation fails

---

### 2) Scheduler (Automation)

File: `src/Core/Scheduler.php`

Purpose: process payments that have reached their due date.

- WP-Cron event: `wcip_hourly_process` (scheduled as `hourly`)
- WP action: `add_action('wcip_hourly_process', ...)`


Process:

1. `PaymentManager::get_due_payments()` retrieves `pending` payments with `due_date <= now`
2. loop payment by payment
3. for each payment:
   - retrieves the parent plan
   - calls Stripe (simulation) via `StripeService::charge_saved_card()`
   - updates DB via `PaymentManager::log_attempt()` as `paid` or `failed`
   - in case of failure: triggers `do_action('wcip_payment_failed', $payment_id, $plan_id)`


Robustness: a crashing payment doesn't block the loop (try/catch per item).

---

### 3) Retry (Smart Recovery)

Files:

- `src/Payments/RetryStrategy.php` (pure PHP)
- `src/Core/Scheduler.php` (wiring via hook)

The strategy provides:

- attempt 1 failed ⇒ retry at **D+3**
- attempt 2 failed ⇒ retry at **D+7**
- attempt 3 failed ⇒ retry at **D+14**
- then ⇒ abandon

When `wcip_payment_failed` is triggered, the scheduler executes `handle_payment_failure()`:

- reads `attempts`
- asks `RetryStrategy` for the next date
- if date: `PaymentManager::reschedule_payment()` (status= pending + due_date)
- else:
  - `PaymentManager::mark_as_failed_final()`
  - `PlanManager::update_status($plan_id, 'breach')`
  - triggers `do_action('wcip_payment_failed_final', $payment_id, $plan_id)`

---

### 4) Webhook Dispatcher (Recovery)

File: `src/Core/WebhookDispatcher.php`

The dispatcher listens to `wcip_payment_failed_final` and sends a POST JSON to an external endpoint.

#### Security: HMAC signature

- `body` (JSON) is signed: `hash_hmac('sha256', $body_json, $secret_key)`
- the signature is sent via header: `X-WCIP-Signature`

The receiver can recalculate the HMAC server-side and verify:

- payload integrity
- authenticity (shared secret)

#### HTTP sending

Uses `wp_remote_post()` with `blocking => false` (fire & forget) to not slow down WP.

---

## Admin Dashboard

### Menu

File: `src/Admin/AdminMenu.php`

- adds a submenu under **WooCommerce**: `wcip-plans`
- internal routing:
  - `?page=wcip-plans` ⇒ list
  - `?page=wcip-plans&action=view&id=XX` ⇒ detail view

### List (WP_List_Table)

File: `src/Admin/PlanListTable.php`

- native WordPress pagination
- `column_id()` includes a **row action** "View details"

### Detail (Drill-down)

File: `src/Admin/PlanDetailView.php`

- displays a plan summary
- displays the complete payment schedule: status, Stripe PI, attempts, last error

---

## Orchestration & loading (Plugin.php)

File: `src/Plugin.php`

The plugin uses a **simple service-locator** (WordPress-friendly pattern):

- lazy initialization of services after WooCommerce check
- unique registration of hooks (guard `hooks_registered`)
- admin loading **only** if `is_admin()` (guard `admin_initialized`)

---

## Configuration (current state)

The following values are placeholders (demo):

- `StripeService::$api_key`
- `WebhookDispatcher::$webhook_url`
- `WebhookDispatcher::$secret_key`

In a production version, they should come from:

- WooCommerce options
- or environment variables

---

## Deactivation / Uninstallation

### Deactivation

`src/Core/Deactivator.php`:

- cleans up WP-Cron: `wp_clear_scheduled_hook('wcip_hourly_process')`

### Uninstallation

`uninstall.php`:

- drop custom tables
- delete `wcip_plugin_version` option

---

## Development & debug

### Generate data (demo)

- place a WooCommerce order >= 100€
- the `CheckoutHandler` creates a plan + installments

### Force CRON processing

WP-Cron triggers via traffic. To test:

- load a site page (triggers WP-Cron)
- or use WP-CLI (if available):
  - `wp cron event run wcip_hourly_process`

### Logs

The plugin logs via `error_log()`:

- critical errors (orphaned plan, impossible insert)
- retry rescheduling
- webhook sending

---

## Natural roadmap (if you continue the project)

- WooCommerce Settings (API keys, eligibility rule, number of installments)
- Real Stripe SDK integration (`off_session`, `payment_method_id` default)
- Batching (limit) in `get_due_payments()`
- Calculated `progress` column (nb paid / total)
- Bulk actions in `WP_List_Table` (export, manual retry, etc.)
