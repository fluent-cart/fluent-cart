### Order Data Attributes

`fct_orders`

```JSON
{
  "id": 1234567890,
  "status": "processing",
  // add possible statuses here
  "parent_id": null,
  "invoice_no": "INV-2023-05-15-001",
  "fulfillment_type": "physical",
  // add possible fulfillment types here
  "type": "checkout",
  // add possible types here
  "mode": "live",
  // or "test"
  "shipping_status": "shipped",
  // add possible shipping statuses here
  "customer_id": 9876543210,
  "payment_method": "credit_card",
  "payment_status": "paid",
  // add possible payment statuses here
  "payment_method_title": "Visa Credit Card",
  "currency": "USD",
  "subtotal": 99.99,
  "discount_tax": 0.50,
  "manual_discount_total": 10.00,
  "coupon_discount_total": 5.00,
  "shipping_tax": 0.75,
  "shipping_total": 7.50,
  "tax_total": 8.25,
  "total_amount": 100.99,
  "total_paid": 100.99,
  "total_refund": 0.00,
  "rate": 1.000000000,
  "note": "Customer requested gift wrapping for item #2",
  "ip_address": "192.168.1.100",
  "completed_at": "2023-05-15T14:30:00Z",
  "refunded_at": null,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "created_at": "2023-05-15 10:00:00",
  "updated_at": "2023-05-15 14:30:00"
}
```
# Order Flow Documentation

## Introduction
This document outlines the entire order processing flow in our system, detailing the decision points, validations, and actions required for successful order processing.

---

## Flow Steps

### **1. Start**
The process begins by checking customer availability.

### **2. Check Customer Availability**
- **Yes:** Set the user address from the customer to order data.
- **No:** Continue to next.

### **3. Validate Shipping Information**
- **Yes:** Mutate order data by setting billing address, shipping address, etc.
- **No:** Return an error.

### **4. Validate Payment Method**
- **Yes:** Retrieve customer information, checkout items, and UTM data.
- **No:** Return an error.

### **5. Validate Products**
- **Yes:** Manage customers and customer addresses.
- **No:** Return an error.

### **6. Manage Customers**
- Create a new customer, update, or use existing customer information.

### **7. Final Order Data Preparation**
- Handle subscription and installment requirements if applicable.

### **8. Store Order**
- Save the order in the system.

### **9. Set Order Status**
- Set `order_status = on-hold` and `payment_status = pending`.

### **10. Attach Draft Payment**
- Attach draft payment to the order.

### **11. Set Transaction Status**
- Set `transaction_status = pending`.

### **12. Attach Customer Address**
- Add the customer address to the order.

### **13. Apply Coupons and Discounts**
- Attach coupon and discount application if available.

### **14. Dispatch Order Created Event**
- Notify relevant systems of order creation.

---

## Error Handling
- If any validation step fails, the process ends with an error.

---

### Order Item Data Attributes

```JSON
[
  {
    "id": 1,
    "order_id": 1234567890,
    "post_id": 5001,
    "fulfillment_type": "physical",
    // add possible fulfillment types here
    "payment_type": "onetime",
    // add possible payment types here
    "post_title": "Premium Wireless Headphones",
    "title": "Premium Wireless Headphones - Black",
    // is this full title or the variation title?
    "object_id": 5001,
    "cart_index": 0,
    "quantity": 1,
    "price": 79.990000000,
    "unit_price": 99.990000000,
    "cost": 50.000000000,
    "total": 79.990000000,
    "tax_amount": 6.990000000,
    "discount_total": 20.000000000,
    "line_total": 86.980000000,
    "rate": 1.000000000,
    "other_info": {
      // what type of data will be stored here?
    },
    "line_meta": "SKU: WH-BLK-001",
    "referrer": "homepage_banner",
    "created_at": "2023-05-15T10:05:00Z",
    "updated_at": "2023-05-15T10:05:00Z"
  },
  {
    "id": 2,
    "order_id": 1234567890,
    "post_id": 5002,
    "fulfillment_type": "physical",
    "payment_type": "onetime",
    "post_title": "Smart Fitness Tracker",
    "title": "Smart Fitness Tracker - Silver",
    "object_id": 5002,
    "cart_index": 1,
    "quantity": 1,
    "price": 49.990000000,
    "unit_price": 49.990000000,
    "cost": 30.000000000,
    "total": 49.990000000,
    "tax_amount": 4.370000000,
    "discount_total": 0.000000000,
    "line_total": 54.360000000,
    "rate": 1.000000000,
    "other_info": {
    },
    "line_meta": "SKU: FT-SLV-002",
    "referrer": "product_recommendation",
    "created_at": "2023-05-15T10:06:00Z",
    "updated_at": "2023-05-15T10:06:00Z"
  }
]
```

### Order Transactions

```JSON
{
  "id": 9876543210,
  "order_id": 1234567890,
  "order_type": "purchase", // possible order type?
  "transaction_type": "charge", // possible transaction types? charge | authorise | dispute | refund
  "subscription_id": null,
  "card_last_4": 4242,
  "card_brand": "visa",
  "vendor_charge_id": "ch_1N3X4Y2eZvKYlo2C9X5Y6Z7W",
  "payment_method": "credit_card",
  "payment_mode": "online",
  "payment_method_type": "card", //applepay | googlepay
  "status": "completed", // possible statuses?
  "total": 141.340000000,
  "rate": 1.00000,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "meta": {}, // What type of data it will store?
  "created_at": "2023-05-15T10:10:00Z",
  "updated_at": "2023-05-15T10:10:00Z"
}
```

[x] Please add all the associate data and their conditions in this doc.
