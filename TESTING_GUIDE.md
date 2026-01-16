# Testing Guide — WC Installment Payments

This guide explains **click by click** how to test the full plugin workflow:

1. Activation → database tables creation
2. WooCommerce checkout → plan + installments creation
3. Admin Dashboard (list + detail view)
4. Scheduler (WP-Cron) → processing pending payments
5. Retry strategy (D+3 / D+7 / D+14)
6. Webhook (HMAC) when a payment becomes final-failed

> Prerequisite: you must have a WordPress site with WooCommerce installed and active.

---

## 0) Prerequisites (check first)

### 0.1 Check WooCommerce

1. Go to **WP Admin → Plugins**
2. Make sure **WooCommerce** is **Active**

### 0.2 Have at least 1 product

1. Go to **Products → Add New**
2. Add a title (example: `Demo Product`)
3. Set a price (example: `150`)
4. Click **Publish**

---

## 1) Activate the plugin + create DB tables

### 1.1 Activate the plugin

1. Go to **WP Admin → Plugins**
2. Find **WC Installment Payments**
3. Click **Activate**

### 1.2 (Optional but recommended) Verify the tables exist

The plugin creates 2 custom tables (with your WP prefix, usually `wp_`):

- `wp_wcip_installment_plans`
- `wp_wcip_installment_payments`

#### Method A (easiest) — using a DB plugin

1. Install/activate a plugin like **WP phpMyAdmin** or **Adminer** (if you already use one)
2. Open the database
3. Search for tables starting with `wcip_`

#### Method B — using phpMyAdmin (LocalWP / hosting)

1. Open phpMyAdmin (from your local tool / hosting panel)
2. Select your WordPress database
3. Search for `wcip_installment_plans` and `wcip_installment_payments`

---

## 2) Generate data: place an order > 100€

Goal: trigger the plugin business rule:

- if order total **>= 100€** → create a 3-installment plan

### 2.1 Place an order (as a customer)

1. Open your shop on the frontend: **Shop**
2. Add the **150€** product to the cart
3. Go to **Cart**, then click **Checkout**
4. Fill in the checkout form
5. Place the order

> Tip: depending on your payment methods, you can use “Cash on delivery” / “Bank transfer” just to create an order easily.

### 2.2 Verify the plan and installments were created

#### Check via the plugin Admin Dashboard (recommended)

1. Go to **WP Admin → WooCommerce → Payment Plans** (or “Plans de paiement”)
2. You should see a new line in the list (a plan was created)

#### Check via the database (optional)

1. Open the table `wp_wcip_installment_plans`
   - you should see 1 row with `order_id`, `customer_id`, `total_amount`, etc.
2. Open the table `wp_wcip_installment_payments`
   - you should see **3 rows** (3 installments)
   - the 1st is usually `status = paid`
   - the other 2 are usually `status = pending`

---

## 3) Test the Admin UI: List + Detail (Drill-down)

### 3.1 Open the list

1. Go to **WP Admin → WooCommerce → Payment Plans**
2. You should see the paginated list

### 3.2 Open the detail view

1. In the **Plan ID** column, hover the ID
2. Click **View Details**

You should see:

- Global info (customer, order, amount, plan status)
- Installments table:
  - due date
  - amount
  - status (`paid`, `pending`, `failed`, `failed_final`)
  - `stripe_payment_intent_id` (if present)
  - `attempts` and `last_error`

---

## 4) Test the Automaton (Scheduler / WP-Cron)

The scheduler processes payments where:

- `status = pending`
- AND `due_date <= now`

### 4.1 Why it does not trigger immediately

By default, installments are scheduled 30 days apart.
So to test the scheduler immediately, you must **force a pending installment to become “due”**.

### 4.2 Force a pending installment to be due (simple method)

1. Open the DB → table `wp_wcip_installment_payments`
2. Find a row with:
   - `status = pending`
3. Edit `due_date` to a past date (example: yesterday)
   - Example: `2025-01-01 00:00:00`
4. Save

### 4.3 Trigger the Scheduler (Updated Methods)

**Method 1: Admin Button (Recommended)**
1. Go to **WP Admin → WooCommerce → Payment Plans**
2. Click the **"Trigger Scheduler Now"** button
3. Check the debug log for results

**Method 2: Direct URL (secure)**
Go to **WooCommerce → Payment Plans** and use the **Direct URL** button.
It includes a nonce so only logged-in admins can trigger it.

**Method 3: WP-Cron (Original)**
WP-Cron runs when WordPress receives traffic.
1. Open any frontend page of your site
2. Refresh it 2–3 times

> **Note**: The admin button method is most reliable for testing. WP-Cron can be inconsistent on local sites.

### 4.4 Check Debug Logs

After triggering the scheduler, check `/wp-content/debug.log` for:

```sql
WCIP Debug: Manual scheduler trigger from admin
WCIP Debug: process_due_payments() called
WCIP Debug: get_due_payments() - current time: [timestamp]
WCIP Debug: SQL query executed, found X results
WCIP Debug: Found X due payments
WCIP Debug: Processing payment ID X
```

### 4.5 Check if the scheduler processed the installment

1. Go back to the DB → `wp_wcip_installment_payments`
2. Look at the row you made "due"

Possible results:

- **Success**:
  - `status` becomes `paid`
  - `attempts` increases
  - `stripe_payment_intent_id` is filled (simulated)

- **Failure**:
  - `status` becomes `failed`
  - `attempts` increases
  - `last_error` = `insufficient_funds` (simulated)
  - **Payment gets rescheduled** to retry date (D+3)

---

## 5) Test the Retry strategy (D+3 / D+7 / D+14)

When a payment fails (`failed`), the plugin:

1. calculates the next retry date (D+3 / D+7 / D+14)
2. reschedules the payment:
   - `status` goes back to `pending`
   - `due_date` is moved to the new date

### 5.1 Verify retry after a failure

1. After a failure, open the DB → `wp_wcip_installment_payments`
2. Check that:
   - `due_date` moved (to the future)
   - `status` is back to `pending`

### 5.2 Simulate multiple failures (until `failed_final`)

Repeat this loop:

1. Set `due_date` to a past date (to trigger the scheduler)
2. Use **Method 1 or 2** from section 4.3 to trigger scheduler:
   - **Recommended**: Click "Trigger Scheduler Now" button in admin
   - **Alternative**: Visit `/trigger-scheduler.php`
3. Check the debug log and database for results

After enough failures, the payment becomes:

- `failed_final`

And the plan status becomes:

- `breach`

> **Debug Tip**: Each attempt will show in debug.log with retry dates (D+3, D+7, D+14)

---

## 6) Test the Webhook (Collections) + HMAC signature

The webhook is sent when:

- a payment becomes `failed_final`

Internal event:

- `wcip_payment_failed_final`

### 6.1 Set a test webhook URL

In: `src/Core/WebhookDispatcher.php`

- replace `webhook_url` with a test URL (example: https://webhook.site)

> If you use `webhook.site`, copy/paste your unique URL.

### 6.2 Trigger a final failure

1. Follow section 5.2 until you get `failed_final`
2. The webhook is sent automatically (fire & forget)

### 6.3 Verify reception

1. Open your endpoint page (example: `webhook.site`)
2. Check:
   - JSON body is present
   - headers:
     - `X-WCIP-Signature`
     - `X-WCIP-Event`

### 6.4 (Advanced) How to validate the signature

On the receiver server, you should:

1. read the raw JSON body
2. recompute:

`hash_hmac('sha256', body_json, secret_key)`

3. compare with the header `X-WCIP-Signature`

If it matches, the webhook is authentic.

---

## 7) Where to look if “it doesn’t work”

### 7.1 Check WordPress logs

The plugin uses `error_log()`. Depending on your setup, it can be:

- a `debug.log` file (if WP_DEBUG_LOG is enabled)
- or PHP/server logs

### 7.2 Quick checklist

- Is WooCommerce active?
- Is the order total >= 100€?
- Are the DB tables present?
- Is there a `pending` installment with `due_date <= now`?
- **Use the "Trigger Scheduler Now" button** to test scheduler manually
- Check `/wp-content/debug.log` for detailed execution logs
- In the plan detail view: do `attempts` increase? does `last_error` appear?

### 7.3 Debug Log Examples

**Working scheduler:**
```
WCIP Debug: Manual scheduler trigger from admin
WCIP Debug: process_due_payments() called
WCIP Debug: Found 1 due payments
WCIP Debug: Processing payment ID 2
```

**No due payments:**
```
WCIP Debug: get_due_payments() - current time: 2025-01-15 17:53:21
WCIP Debug: SQL query executed, found 0 results
```

---

## Expected result (“perfect test”)

You can consider everything working if:

- you place an order >= 100€ → you see a plan + 3 installments
- you force a pending installment to be due → the scheduler processes it
- on failure → retry reschedules the `due_date`
- after too many failures → `failed_final` + plan `breach`
- on `failed_final` → webhook is sent + HMAC signature is present in headers
