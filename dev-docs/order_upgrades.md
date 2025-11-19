### Order Upgrades Paths and Data Changes

When a customer upgrades their order, the following changes occur in the database:

**Old Order:**
- config: { upgraded_to: 1234,  }

**Old Subscription (if any):**
- config: { upgraded_to_sub_id: 1234 }
- status: "cancelled"

**New Order:**
- config: { upgraded_from: 1234 }

**New Subscription (if any):**
- config: { upgraded_from_sub_id: 1234 }
