import { formatNumber } from "@/Bits/productService";

export const recalculatePayout = ($orderObj, $hasCoupon, discountTotal = 0) => {
  let subTotal = 0;
  let couponDiscountTotal = 0;

  // Remove the custom discount amount if coupon is applied.
  if($hasCoupon) {
    $orderObj.manual_discount_total = 0;
  }

  if ($orderObj.order_items) {
    $orderObj.order_items.forEach((item, idx) => {
      let updateStock = item.updateStock || 0;
      if (item.quantity < 1) {
        item.quantity = 1;
      } else if (updateStock != 0 && parseInt(item.quantity) > updateStock) {
        item.quantity = updateStock;
      }
      item.total = item.unit_price * item.quantity;
      let originalTotal = item.unit_price * item.quantity;
      item.subtotal = originalTotal - item.cost;

      if ($orderObj.manual_discount_total > 0) {
        item.discount_total = item.discount_total;
      }
      else if ($hasCoupon === true) {
        couponDiscountTotal += item.discount_total;
      }
      else {
        if (!$orderObj.manual_discount_total === 0) {
          $orderObj.manual_discount_total -= item.discount_total;
        } else {
          $orderObj.manual_discount_total = 0;
        }
        item.discount_total = 0;
      }

      item.line_total = originalTotal - item.discount_total + item.tax_amount
      subTotal += originalTotal;

      if (
        item.payment_type == "subscription" &&
        item.other_info.manage_setup_fee == "yes"
      ) {
        let signupFee = parseInt(item.other_info.signup_fee);
        if (item.other_info.setup_fee_per_item == "yes") {
          signupFee = signupFee * item.quantity;
        }
        subTotal += signupFee;
      }
    });
  }


  /**
   * Does those weired brackets needed? - yeah....
   *
   * @type {number}
   */
  $orderObj.subtotal = subTotal;
  $orderObj.coupon_discount_total = couponDiscountTotal;

  if (
    $orderObj.subtotal <= 0 ||
    $orderObj.subtotal <= $orderObj.manual_discount_total
  ) {
      $orderObj.manual_discount_total =
      $orderObj.coupon_discount_total =
      $orderObj.shipping_tax =
      $orderObj.shipping_total =
      $orderObj.tax_total =
        0;
  }
  calculateOrderTotal($orderObj);

  return $orderObj;
};

export const adjustTotalBasedOnDiscountChange = ($orderObj, $discountObj) => {
  if ($discountObj.value < 0) {
    return $orderObj;
  }

  let dAmount = $discountObj.value ? $discountObj.value * 100 : 0;
  $discountObj.label = "";

  if ($discountObj.type === "percentage") {
    dAmount = ($orderObj.subtotal * $discountObj.value) / 100;
  }

  discountLabel($discountObj);

  dAmount = parseInt(dAmount);

  if (dAmount >= 0 && dAmount <= parseInt($orderObj.subtotal)) {
    $orderObj.manual_discount_total = dAmount;
  }

  let totalAmount =
      $orderObj.subtotal * 1 +
      $orderObj.tax_total * 1 +
      $orderObj.shipping_total * 1 -
      $orderObj.manual_discount_total -
      $orderObj.coupon_discount_total;

  if (totalAmount >= 0) {
    $orderObj.total_amount = totalAmount;
  }

  return $orderObj;
};

/**
 *
 * @param $orderObj
 * @param $shippingObj
 * @returns {*}
 */
export const adjustShippingTotal = ($orderObj, $shippingObj) => {
  $orderObj.shipping_total = 0;
  $orderObj.shipping_tax = 0;

  if ($shippingObj.type === "custom") {
    $orderObj.shipping_total = $shippingObj.custom_price * 100;
  }

  return calculateOrderTotal($orderObj);
};

export const calculateOrderTotal = ($orderObj) => {
  $orderObj.total_amount =
    $orderObj.subtotal * 1 +
    $orderObj.tax_total * 1 +
    $orderObj.shipping_tax * 1 +
    $orderObj.shipping_total * 1 -
    $orderObj.manual_discount_total -
    $orderObj.coupon_discount_total;
  return $orderObj;
};

export const discountLabel = ($discountObj) => {
  if ($discountObj.type === "percentage") {
    $discountObj.label = "(-" + $discountObj.value + "%)";
  }

  return $discountObj;
};
