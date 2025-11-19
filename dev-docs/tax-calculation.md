## Tax Calculation Process in Fluent Cart


item_id = 1
item_price = 100
quantity = 1
subtotal = 100 * 1 = 100
tax_amount = 10
shipping_charge: 10
discount_total: 50
line_total = subtotal + shipping_charge - discount_total = 100 + 10 - 50


### Tax Included ( tax_behavior = 2)
Item Price: $100 (Tax Included)
[
    item_price = 100
    quantity = 1
    subtotal = 100 * 1 = 100
    tax_amount = 0
    shipping_charge: 10
    discount_total: 50
    line_total = subtotal + shipping_charge - discount_total = 100 + 10 - 50 + 0 = 60 (don't apply tax here)
]

Apply Tax Rate: 10% after 50% discount
[
    item_price = 100
    quantity = 1
    subtotal = 100
    shipping_charge: 10
    discount_total: 50
    tax_amount = need_to_calculate_tax_included_price(subtotal - discount_total, 10) = need_to_calculate_tax_included_price(50, 10) = 4.55
    line_total = subtotal + shipping_charge - discount_total = 100 + 10 - 50 = 60 ((don't apply tax here))
    line_meta = ```{
        tax_config: [
            "inclusive": false,
            "rates": [
                {
                    "rate_id": 1,
                    "label": "AT Standard Tax",
                    "tax_amount": 3400
                }
            ]
        ]   
    }```
]


```php 
need_to_calculate_tax_included_price(item_price, tax_rate) {
    tax_amount = item_price - (item_price / (1 + (tax_rate / 100)))
    price_excluding_tax = item_price - tax_amount
    return price_excluding_tax
}
```

**Order**
{
    subtotal: SUM(items.subtotal) = 100
    shipping_total: SUM(items.shipping_charge) = 10
    shipping_tax: shipping_total * shipping_tax_rate = 10 * 10% = 1
    tax_total: SUM(items.tax_amount) = 4.55
    total_amount: subtotal + shipping_total + shipping_tax - coupon_discount_total - manual_discount_total (don't apply tax_total here)
    tax_behavior: 2
}


### Tax Excluded (tax_behavior = 1)

Item Price: $100 (Tax Excluded)
[
    item_price = 100
    quantity = 1
    subtotal = 100 * 1 = 100
    tax_amount = 0
    shipping_charge: 10
    discount_total: 50
    line_total = subtotal + shipping_charge - discount_total + tax_amount = 100 + 10 - 50 + 0 = 60 (apply tax here)
]

Apply Tax Rate: 10% after 50% discount
[
    item_price = 100
    quantity = 1
    subtotal = 100
    shipping_charge: 10
    discount_total: 50
    tax_amount = need_to_calculate_tax_excluded_price(subtotal - discount_total, 10) = need_to_calculate_tax_excluded_price(50, 10) = 5.0
    line_total = subtotal + shipping_charge - discount_total + tax_amount = 100 + 10 - 50 + 5.0 = 65.0 (apply tax here)
]

```php 
need_to_calculate_tax_excluded_price(item_price, tax_rate) {
    tax_amount = (item_price * tax_rate) / 100
    return tax_amount
}
```


**Order**
{
    subtotal: SUM(items.subtotal) = 100
    shipping_total: SUM(items.shipping_charge) = 10
    shipping_tax: shipping_total * shipping_tax_rate = 10 * 10% = 1
    tax_total: SUM(items.tax_amount) = 4.55
    total_amount: subtotal + shipping_total + shipping_tax - coupon_discount_total - manual_discount_total + tax_total
    tax_behavior: 1
}




// Checkout Page Flow:


-> Show the field if address matches with the selected tax zone config

Take the VAT Number

-> Validate VAT Number via VIES API
fct_carts => checkout_data.tax_details = {
    is_valid: 'yes/no',
    vat_number: 'VAT Number',
    country: 'IT',
    company_name: '',
}

// sales report calculations

gross = sum(total_paid)
refund = sum(total_refund)
tax = sum(shipping_tax + tax_total)
shipping = sum(shipping_total)

net = gross - refund - tax
