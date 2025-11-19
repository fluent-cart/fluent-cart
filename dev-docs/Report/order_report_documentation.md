# 📊 ORDER REPORT DOCUMENTATION

The Order Report provides detailed insights into revenue, orders, item quantities, customer behavior, time-based order performance, and distribution charts. It uses data from the orders, order_items, refunds, and customers tables.

## 📌 METRICS

- **Net Revenue**: Revenue after deducting discount, tax, and refund amounts.  
  ```php
  $netRevenue = DB::table('fct_orders')->where($filters)->sum(DB::raw('total - discount_total - tax_total - refund_total'));
  ```

- **Gross Sale**: Total amount before any deductions.  
  ```php
  $grossSale = DB::table('fct_orders')->where($filters)->sum('total');
  ```

- **Orders**: Total number of orders.  
  ```php
  $totalOrders = DB::table('fct_orders')->where($filters)->count();
  ```

- **Items**: Total number of items sold.  
  ```php
  $itemsSold = DB::table('fct_order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where($filters)->sum('order_items.quantity');
  ```

- **Average Order Net**: Average net revenue per order.  
  ```php
  $avgOrderNet = $totalOrders > 0 ? $netRevenue / $totalOrders : 0;
  ```

- **Average Order Gross**: Average gross sale per order.  
  ```php
  $avgOrderGross = $totalOrders > 0 ? $grossSale / $totalOrders : 0;
  ```

- **Average Order Items**: Average number of items per order.  
  ```php
  $avgOrderItems = $totalOrders > 0 ? $itemsSold / $totalOrders : 0;
  ```

- **Average Monthly Net**: Average net revenue grouped by month.  
  ```php
  $monthlyNet = DB::table('fct_orders')->where($filters)->groupByMonth('created_at')->sum(DB::raw('total - discount_total - tax_total - refund_total'));
  $avgMonthlyNet = count($monthlyNet) > 0 ? array_sum($monthlyNet) / count($monthlyNet) : 0;
  ```

- **Average Monthly Gross**: Average gross sale grouped by month.  
  ```php
  $monthlyGross = DB::table('fct_orders')->where($filters)->groupByMonth('created_at')->sum('total');
  $avgMonthlyGross = count($monthlyGross) > 0 ? array_sum($monthlyGross) / count($monthlyGross) : 0;
  ```

- **Average Monthly Orders**: Average number of orders per month.  
  ```php
  $monthlyOrders = DB::table('fct_orders')->where($filters)->groupByMonth('created_at')->count();
  $avgMonthlyOrders = count($monthlyOrders) > 0 ? array_sum($monthlyOrders) / count($monthlyOrders) : 0;
  ```

- **Average Monthly Items**: Average number of items sold per month.  
  ```php
  $monthlyItems = DB::table('fct_order_items')->join('fct_orders', 'orders.id', '=', 'fct_order_items.order_id')->where($filters)->groupByMonth('fct_orders.created_at')->sum('fct_order_items.quantity');
  $avgMonthlyItems = count($monthlyItems) > 0 ? array_sum($monthlyItems) / count($monthlyItems) : 0;
  ```

- **Refunds**: Total refund amount.  
  ```php
  $refunds = DB::table('fct_order_transactions')->whereIn('status', ['refunded', 'partially_refunded'])->whereBetween('created_at', [$filters['start_date'],$filters['end_date']])->sum(total);
  ```

- **Discount**: Total discount amount.  
  ```php
  $discount = DB::table('fct_orders')->where($filters)->selectRaw('SUM(manual_discount_total + coupon_discount_total) as total_discount');
  ```

- **Shipping**: Total shipping amount.  
  ```php
  $shipping = DB::table('orders')->where($filters)->sum('shipping_total');
  ```

- **Tax**: Total tax amount.  
  ```php
  $tax = DB::table('orders')->where($filters)->sum('tax_total');
  ```

## 📋 TABLES

### 🔸 New vs Returning Customers

Grouped into two categories (new & returning), each with:

- Customer Count
- Order Count
- Net Sales
- Average Net Sales
- Gross Sales
- Average Gross Sales

**Logic:** Use `customer_id` to determine if the order is their first. Group orders by customer type and summarize based on counts and totals.

### 🔸 Orders Grouped By

Group orders by shipping country, billing country, and payment method.

```php
DB::table('fct_orders')->where($filters)->groupBy('shipping_country')->selectRaw('shipping_country, COUNT(*) as order_count')->get();
```

### 🔸 Orders Grouped By Day and Hour (Heatmap)

Generate a heatmap with 7 rows (days of week) and 24 columns (hours). Each cell shows order count. Higher numbers = darker background color.

Example data:
```json
{ "day": "Monday", "hour": "14", "orders": 12 }
```

## 📈 CHARTS

### 🔸 Spend By Day (Gross Sale by Day of Week)

Group gross sales by day of the week.

```php
$spendByDay = DB::table('orders')->where($filters)->selectRaw('DAYOFWEEK(created_at) as day, SUM(total) as gross')->groupBy('day')->get();
```

### 🔸 Item Count Distribution

- X-axis: Number of items in an order
- Y-axis: Number of orders having those item counts

Example output:
- 2 items → 20 orders
- 4 items → 30 orders
- 5 items → 40 orders

### 🔸 Spend By Hour

Gross sales grouped by order hour (0-23).

```php
$spendByHour = DB::table('orders')->where($filters)->selectRaw('HOUR(created_at) as hour, SUM(total) as gross')->groupBy('hour')->get();
```

### 🔸 Time Between Order Created and Completed

Shows how many hours it takes to complete orders.

```php
$diffHours = DB::table('orders')->where($filters)->selectRaw('TIMESTAMPDIFF(HOUR, created_at, completed_at) as hour, COUNT(*) as orders')->groupBy('hour')->get();
```

Example:
- Hour: 0 → Orders: 1
- Hour: 14 → Orders: 1
- Hour: 26 → Orders: 1

### 🔸 Average Order Gross Chart

Average gross per order grouped by day, month, or year — depending on data density.

**Logic:**
- If days ≤ 30 → group by day
- If days ≤ 900 → group by month
- Else → group by year

### 🔸 Order Value Distribution

Groups orders by net value range.

Example:
- 0–100 → 28 orders
- 100–200 → 28 orders
- 200–300 → 10 orders
- 300–400 → 2 orders
- 400–500 → 0 orders

### 🔸 Average Order Items

Show average item count per order over time, grouped by day/month/year.

Example:
```json
{
  "2024-05-01": 3.2,
  "2024-05-02": 2.8
}
```