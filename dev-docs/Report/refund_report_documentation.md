
# 📄 Refund Report Documentation

The **Refund Report** provides a comprehensive view of all refund-related activities. It includes graphical and tabular representations to analyze the total refunds, refund amounts, and refund behavior over time and by grouping parameters.

---

## 📊 REFUND REPORT CHART

Displays a chart with the following metrics over time:

- **Total Refunds**: Number of refund transactions.
- **Total Refunded Amount**: The total monetary value refunded or partially refunded.
- **Average Refunded Amount**: Average amount per refund or partially refunded transaction.
- **Refund Rate**: (Total Refunded Amount / Total Order Amount). (Only for the refunded orders)

> This chart supports both **line** and **bar** visualizations, and the preferred chart type is saved in **LocalStorage**.

---

## ⏳ WEEKS BETWEEN ORDER CREATED AND REFUNDED

This chart groups orders based on how many **weeks** passed between when the order was **created** and when it was **refunded**.

- **X-axis**: Week ranges (e.g., 0–1 week, 1–2 weeks, 2–3 weeks, etc.)
- **Y-axis**: Number of refunded orders

> Useful for identifying delay patterns in refund processing.

Example Output:
```
Week Range     | Refunded Orders
-------------- | ----------------
0–1 weeks      | 10
1–2 weeks      | 25
2–3 weeks      | 18
3–4 weeks      | 5
```

---

## 📋 REFUND GROUP BY TABLE

This table shows grouped data based on the selected dimension. The available group options are:

- **Billing Country**
- **Shipping Country**
- **Payment Method**

### Columns:

| Group By           | Total Refunds | Total Refunded Amount | Avg Refunded Amount |
|--------------------|---------------|------------------------|----------------------|
| Billing Country A  | 18            | $540                   | $30.00               |
| Billing Country B  | 25            | $650                   | $26.00               |

| Group By           | Total Refunds | Total Refunded Amount | Avg Refunded Amount |
|--------------------|---------------|------------------------|----------------------|
| Stripe             | 18            | $540                   | $30.00               |
| PayPal             | 40            | $1,200                 | $30.00               |

> Data is grouped using SQL `GROUP BY` clauses on the selected field.

Example Query:
```sql
SELECT billing_country, COUNT(*) as total_refunds, SUM(amount) as total_refunded_amount, AVG(amount) as avg_refunded_amount
FROM fct_order_transactions
WHERE status IN ('refunded', 'partially_refunded')
GROUP BY billing_country;
```
