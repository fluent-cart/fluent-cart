# 📊 SALES REPORT DOCUMENTATION

The SALES REPORT provides a summary of core sales and customer metrics across your store. It uses data from the `fct_orders`, `fct_order_items`, `fct_order_transactions`, and `fct_customers` tables.

---

## 📌 METRICS

### 🔹 Net Revenue
Revenue after deducting discounts, taxes, and refunds.

```php
$netRevenue = DB::table('fct_orders')
    ->where($filters)
    ->sum(DB::raw('total - discount_total - tax_total - refund_total'));
```

### 🔹 Orders
Total number of orders placed.

```php
$totalOrders = DB::table('fct_orders')
    ->where($filters)
    ->count();
```

### 🔹 New Customers
Customers who placed their first order within the selected date range.

```php
$newCustomers = DB::table('fct_customers')
    ->whereBetween('first_order_date', [$filters['start_date'], $filters['end_date']])
    ->count();
```

### 🔹 Items Sold
Total quantity of items sold.

```php
$itemsSold = DB::table('fct_order_items')
    ->join('fct_orders', 'orders.id', '=', 'order_items.order_id')
    ->where($filters)
    ->sum('fct_order_items.quantity');
```

### 🔹 Refund Count
Number of refunds issued.

```php
$refundCount = DB::table('fct_order_transactions')
    ->whereIn('status', ['refunded', 'partially_refunded'])
    ->whereBetween('created_at', [$filters['start_date'], $filters['end_date']])
    ->count();
```

### 🔹 Refunded Amount
Total amount refunded.

```php
$refundedAmount = DB::table('fct_order_transactions')
    ->whereIn('status', ['refunded', 'partially_refunded'])
    ->whereBetween('created_at', [$filters['start_date'], $filters['end_date']])
    ->sum('amount');
```

### 🔹 Average Order Net
Average net revenue per order.

```php
$avgOrderNet = $totalOrders > 0 ? $netRevenue / $totalOrders : 0;
```

### 🔹 Average Order Items
Average number of items per order.

```php
$avgOrderItems = $totalOrders > 0 ? $itemsSold / $totalOrders : 0;
```

### 🔹 Average Customer Orders
Average number of orders per customer.

```php
$uniqueCustomers = DB::table('fct_orders')
    ->where($filters)
    ->distinct()
    ->count('customer_id');

$avgCustomerOrders = $uniqueCustomers > 0 ? $totalOrders / $uniqueCustomers : 0;
```

### 🔹 Average Customer LTV
Average net revenue per customer.

```php
$avgCustomerLTV = $uniqueCustomers > 0 ? $netRevenue / $uniqueCustomers : 0;
```

---

## 🧩 CARDS

### 🔸 Top Sold Products
List of products with the highest quantity sold.

### 🔸 Top Sold Variants
List of product variants with the highest quantity sold.

### 🔸 Failed Orders
Orders that were canceled.

```php
$failedOrders = DB::table('fct_orders')
    ->where('status', 'canceled')
    ->where($filters)
    ->select('id', 'customer_name', 'created_at', 'order_items_count', 'total')
    ->get();
```