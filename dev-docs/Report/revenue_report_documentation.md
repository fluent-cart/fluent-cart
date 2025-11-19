# 📈 Revenue Report Documentation

The Revenue Report provides comprehensive insights into store revenue, including net revenue, gross sales, refunds, taxes, shipping, and discounts. It supports visual representation through charts and detailed breakdowns through grouped tables.

---

## 📊 Revenue Report Chart

A dynamic chart that displays the following revenue metrics:

- **Net Revenue**
- **Gross Sale**
- **Refund**
- **Taxes**
- **Shipping**
- **Discount**

**Features:**

- Can be toggled between **Line Chart** and **Bar Chart**.
- The chart state (type selection and filters) is **persisted in localStorage** for consistent user experience.

---

## 📋 Net Revenue Table (Grouped by Year)

Displays annual revenue breakdown with the following columns:

- **Net Revenue**
- **Gross Sale**
- **Refund**
- **Taxes**
- **Shipping**
- **Discount**
- **Orders**

This table can be **expanded to reveal monthly data** for each year.

---

## 🗂 Net Revenue Grouped By

Allows grouping revenue metrics by:

- **Billing Country**
- **Shipping Country**
- **Payment Method**

Each group includes the following columns:

- **Net Revenue**
- **Gross Sale**
- **Refund**
- **Taxes**
- **Shipping**
- **Discount**
- **Orders**

**Example Grouping Query:**
```sql
SELECT billing_country, SUM(total - discount_total - tax_total - refund_total) AS net_revenue, COUNT(*) as orders
FROM fct_orders
GROUP BY billing_country;
```

---

This report helps identify performance trends and geographical or payment-based revenue segmentation.