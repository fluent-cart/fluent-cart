  export const filterOrderItemsByAdjustmentItem = (orders) => {
    /*
      For each order, check if there is an adjustment item. 
      If an adjustment item is found, replace the order's items with only the adjustment item. 
      If no adjustment item is found, keep the original items as they are.
    */
    orders.forEach(order => {
      const adjustmentItem = order.order_items.find(item => item.payment_type === 'adjustment');
      if (adjustmentItem) {
        order.order_items = [adjustmentItem]; // Directly assign the adjustment item
      }
    });
  
    return orders;
  };
  