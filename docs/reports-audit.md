# Reports Endpoint Audit

**Date:** 2026-04-06
**Branch:** `development`
**Scope:** All 13 report services, 11 report controllers, routes, and policies

---

## Critical Issues

### 1. SQL Injection — Currency Filter in DashBoardReportService
- **File:** `app/Services/Report/DashBoardReportService.php:106`
- **Status:** [ ] Open
- **Description:** Currency value is directly interpolated into raw SQL without escaping.
  ```php
  AND currency = '{$this->filters['currency']}'
  ```
  The `$this->filters['currency']` comes from user input via `ReportHelper::processRequest()`.
- **Fix:** Replace string interpolation with a `?` placeholder and add currency to the `$bindings` array.

---

### 2. Broken Prepared Statement in LicenseReportService
- **File:** `app/Services/Report/LicenseReportService.php:73-81`
- **Status:** [ ] Open
- **Description:** Uses `%s` placeholders in `$wpdb->prepare()` for SQL fragments (column expressions, table names, GROUP BY clauses). `prepare()` quotes these as string literals, which breaks the query.
  ```php
  $wpdb->prepare(
      "SELECT %s, COUNT(*) AS license_count FROM %s WHERE created_at BETWEEN %s AND %s GROUP BY %s ORDER BY %s",
      $dateFormat,   // e.g. "DATE(created_at) AS date" — gets quoted!
      "{$wpdb->prefix}fct_licenses",  // table name — gets quoted!
      ...
  );
  ```
- **Fix:** Inline the SQL fragments directly into the query string (they're derived from a controlled `switch` statement). Only use `%s` for the actual date values.

---

### 3. Mixed Escaping in OverviewReportController
- **File:** `app/Http/Controllers/Reports/OverviewReportController.php:199`
- **Status:** [ ] Open
- **Description:** Double-escaping with `esc_sql()` inside `$wpdb->prepare()`, then the prepared string is concatenated into a separate raw query that uses `App::db()->select()` with `?` placeholders. Two different escaping mechanisms are mixed.
  ```php
  $currency_filter = $wpdb->prepare(' AND o.currency = %s', esc_sql($currency));
  // Then concatenated into raw query passed to App::db()->select()
  ```
- **Fix:** Use a single escaping strategy. Either handle currency as a `?` binding in the `App::db()->select()` call, or use `$wpdb->prepare()` for the entire query — not both.

---

## Medium Issues

### 4. Dead Code + N+1 Query in DashBoardReportService
- **File:** `app/Services/Report/DashBoardReportService.php:72-79`
- **Status:** [ ] Open
- **Description:** A `foreach` loop iterates all orders and counts `order_items` (triggering N+1 if not eager-loaded), but the result is immediately overwritten on the next line.
  ```php
  foreach ($this->data as $order) {
      $totalOrderItems += count($order->order_items); // N+1 risk
  }
  $this->totalOrderItems = $totalOrderItems;
  $this->totalOrderItems = $this->data->where('payment_status', 'paid')->sum('order_items_count'); // overwrites!
  ```
- **Fix:** Delete lines 72-78. The `sum('order_items_count')` on line 79 is the intended logic.

---

### 5. Double Binding Substitution in DashBoardReportService
- **File:** `app/Services/Report/DashBoardReportService.php:116-121`
- **Status:** [ ] Open
- **Description:** Bindings are manually substituted into the query string via `preg_replace`, then the same bindings array is passed again to `App::db()->select()`. This double-applies values.
  ```php
  foreach ($bindings as $binding) {
      $binding = is_numeric($binding) ? $binding : "'$binding'";
      $query = preg_replace('/\?/', $binding, $query, 1);
  }
  $currentStats = App::db()->select($query, $bindings)[0]; // bindings passed again
  ```
- **Fix:** Remove the manual `preg_replace` loop entirely. Let `App::db()->select()` handle binding substitution. Also fix issue #1 (currency) so the full query uses proper `?` placeholders.

---

### 6. All Carts Loaded Into Memory
- **File:** `app/Services/Report/CartReportService.php:42`
- **Status:** [ ] Open
- **Description:** `prepareReportData()` loads all matching carts into a collection and iterates in PHP to classify idle vs abandoned and compute totals. For stores with thousands of carts, this causes high memory usage.
- **Fix:** Replace the PHP iteration with SQL aggregation using `SUM(CASE WHEN ...)` and `COUNT(CASE WHEN ...)` with `TIMESTAMPDIFF()` for the time-based classification.

---

### 7. No Limit on Unfulfilled Orders Query
- **File:** `app/Services/Report/DashBoardReportService.php` (getUnfulfilledOrders)
- **Status:** [ ] Open
- **Description:** Returns all unfulfilled orders with no `LIMIT`. High-volume stores could return thousands of rows to the dashboard.
- **Fix:** Add `->limit(20)` or paginate the response.

---

### 8. Race Condition in Snapshot Job Tracking
- **File:** `app/Http/Controllers/Reports/RetentionSnapshotController.php:36`
- **Status:** [ ] Open
- **Description:** Uses `time()` (1-second granularity) as the job tracking ID. Two requests in the same second overwrite each other's status in `wp_options`.
  ```php
  $trackingId = time();
  ```
- **Fix:** Use `wp_generate_uuid4()` or `uniqid('', true)` for unique IDs.

---

### 9. Unsafe Array Access on Cart Data
- **File:** `app/Services/Report/CartReportService.php:93-94`
- **Status:** [ ] Open
- **Description:** Accesses `$item['title']` and `$item['price']` from deserialized `cart_data` without null checks or type casting. Missing keys will throw PHP notices.
  ```php
  $productName = $item['title'];  // no null check
  $unitPrice = $item['price'];    // no type cast
  ```
- **Fix:** Use `$item['title'] ?? 'Unknown'` and `(int)($item['price'] ?? 0)`.

---

## Low Issues

### 10. groupKey Whitelist Missing Values
- **File:** `app/Services/Report/ReportHelper.php:163-166`
- **Status:** [ ] Open
- **Description:** The `sanitizeParams()` whitelist for `groupKey` is:
  ```php
  ['billing_country', 'shipping_country', 'payment_method', 'payment_status', 'default', 'monthly', 'yearly']
  ```
  But `RefundReportService::getRefundDataGroupedBy()` checks for `payment_method_type` and `payment_method_title` which are not in this list. They get sanitized to `'payment_method'` before reaching the service, causing wrong grouping for refund reports.
- **Fix:** Add `payment_method_type` and `payment_method_title` to the whitelist array.

---

### 11. No Caching on Report Queries
- **Files:** All 13 services in `app/Services/Report/`
- **Status:** [ ] Open
- **Description:** Every report page load runs complex aggregation queries (including CTEs, window functions, subqueries) directly against the database with no caching layer. This impacts dashboard load times on stores with large order volumes.
- **Recommendation:** Add short-lived WordPress transients (5-15 min TTL) for expensive queries like `getCountryWiseStatsImproved()` and `getDashBoardStats()`. Cache keys should include date range + currency + filters.

---

---

## Top Sold Products/Variants Report Issues

### 12. Inflated total_amount in Top Sold Products and Variants
- **File:** `app/Services/Report/DefaultReportService.php:18,61`
- **Status:** [x] Fixed
- **Description:** Both `fetchTopSoldProducts()` and `fetchTopSoldVariants()` sum `o.total_amount` (the full order total) grouped by product/variant. When an order contains multiple products, each product row gets credited with the **entire order total**, not just its line item amount. This inflates revenue figures significantly.
  ```php
  // Line 18 (fetchTopSoldProducts)
  SUM(o.total_amount) / 100 AS total_amount

  // Line 61 (fetchTopSoldVariants)
  SUM(o.total_amount) / 100 AS total_amount
  ```
  For example, if an order worth $100 has 3 products, each product shows $100 instead of its actual line item value.
- **Fix:** Use the item-level `oi.line_total` column (stored in cents in `fct_order_items`) instead of the order-level `o.total_amount`:
  ```php
  SUM(oi.line_total) / 100 AS total_amount
  ```

---

### 13. GROUP BY Mismatch in fetchTopSoldProducts
- **File:** `app/Services/Report/DefaultReportService.php:22`
- **Status:** [x] Fixed — Removed posts join entirely. Names fetched from fct_order_items via latest row per post_id.
- **Description:** The query groups by `oi.post_id, oi.post_title` but selects `p.post_title` (from the `posts` table). While `oi.post_title` and `p.post_title` usually match, grouping by one and selecting the other is incorrect — if a product is renamed, `p.post_title` reflects the current name but `oi.post_title` reflects the name at order time.
  ```php
  ->selectRaw('... p.post_title AS product_name ...')
  ->groupBy('oi.post_id', 'oi.post_title')  // groups by oi.post_title
  ```
- **Fix:** Group by `oi.post_id, p.post_title` to match the selected column. This also ensures the report shows the current product name consistently.

---

### 14. Missing GROUP BY Columns in fetchTopSoldVariants
- **File:** `app/Services/Report/DefaultReportService.php:65`
- **Status:** [x] Fixed — Removed posts join. Variant meta fetched from fct_order_items via latest row per object_id.
- **Description:** The query groups by `oi.object_id, oi.title` but also selects `oi.post_id` and `p.post_title` which are not in the GROUP BY clause. In MySQL strict mode (`ONLY_FULL_GROUP_BY`), this query will fail with an error.
  ```php
  ->selectRaw('... oi.post_id AS product_id, p.post_title AS product_name ...')
  ->groupBy('oi.object_id', 'oi.title')  // missing oi.post_id and p.post_title
  ```
- **Fix:** Add the missing columns to GROUP BY:
  ```php
  ->groupBy('oi.object_id', 'oi.title', 'oi.post_id', 'p.post_title')
  ```

---

## Progress Tracker

| # | Severity | Issue | Status |
|---|----------|-------|--------|
| 1 | CRITICAL | SQL injection — currency in DashBoardReportService | [x] Fixed |
| 2 | CRITICAL | Broken prepare() in LicenseReportService | [x] Fixed |
| 3 | CRITICAL | Mixed escaping in OverviewReportController | [x] Fixed |
| 4 | MEDIUM | Dead code + N+1 in DashBoardReportService | [x] Fixed |
| 5 | MEDIUM | Double binding substitution in DashBoardReportService | [x] Fixed |
| 6 | MEDIUM | All carts loaded into memory | [x] Removed (dead code) |
| 7 | MEDIUM | No limit on unfulfilled orders | [x] Removed (dead code) |
| 8 | MEDIUM | Race condition in snapshot job ID | -- Ignored |
| 9 | MEDIUM | Unsafe array access on cart_data | [x] Removed (dead code) |
| 10 | LOW | groupKey whitelist missing values | [x] Fixed |
| 11 | LOW | No caching on report queries | -- Skipped (admin-only) |
| 12 | HIGH | Inflated total_amount in top sold products/variants | [x] Fixed |
| 13 | MEDIUM | GROUP BY mismatch in fetchTopSoldProducts | [x] Fixed |
| 14 | MEDIUM | Missing GROUP BY columns in fetchTopSoldVariants | [x] Fixed |
| 15 | HIGH | Row multiplication + timeout in revenueByGroup | [x] Fixed |
| 16 | MEDIUM | `payment_method_type` not on fct_orders (review catch) | [x] Fixed |
| 17 | MEDIUM | Phantom products/variants with post_id/object_id = 0 (review catch) | [x] Fixed |
| 18 | MEDIUM | getProductNames/getVariantMeta unbounded rows (review catch) | [x] Fixed |
| 19 | LOW | Duplicate WHERE EXISTS in revenueByGroup (review catch) | [x] Fixed |
| 20 | CRITICAL | `collect()` fatal error in fetchTopSoldVariants (PR feedback) | [x] Fixed |
| 21 | MEDIUM | Standardize payment_method slug across all reports (PR feedback) | [x] Fixed |
| 22 | MEDIUM | Remove redundant MAX(payment_method_title) column (PR feedback) | [x] Fixed |
| 23 | MEDIUM | Correlated subquery → pre-aggregated JOIN in revenueByGroup (PR feedback) | [x] Fixed |
| 24 | MEDIUM | MAX() is lexicographic, not latest — use subquery for names (Codex catch) | [x] Fixed |
| 25 | MEDIUM | Independent MAX(title)/MAX(post_id) mismatch in getVariantMeta (Codex catch) | [x] Fixed |
