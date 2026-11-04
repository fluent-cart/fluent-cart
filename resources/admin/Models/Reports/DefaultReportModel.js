import ReportRequestModel from "./ReportRequestModel";

class DefaultReportModel extends ReportRequestModel {
  get controllerKey() {
    return "DefaultReportController";
  }

  data = {
    summaryData: {
      net_revenue: 0,
      order_count: 0,
      subscription_renewal_count: 0,
      new_customers: 0,
      total_item_count: 0,
      total_refunded_amount: 0,
      total_refunded: 0,
      average_order_net: 0,
      average_order_items: 0,
      average_customer_orders: 0,
      average_customer_ltv: 0,
    },
    previousSummary: {},
    fluctuations: {
      net_revenue: 0,
      order_count: 0,
      new_customers: 0,
      total_item_count: 0,
      total_refunded_amount: 0,
      total_refunded: 0,
      subscrition_renewal: 0,
    },
    graphs: {
      netRevenueGraph: {},
      orderGraph: {},
      itemsSoldGraph: {},
      refundCountGraph: {},
      refundsGraph: {},
      newCustomerGraph: {},
      subscriptionRenewalGraph: {},
    },
    topSoldProducts: [],
    topSoldVariants: [],
    isBusy: {
      graphs: {
        netRevenueGraph: false,
        orderGraph: false,
        itemsSoldGraph: false,
        refundCountGraph: false,
        refundsGraph: false,
        newCustomerGraph: false,
        subscriptionRenewalGraph: false,
      },
      summaryData: false,
      topSoldProductsAndFailedOrders: false,
      topSoldVariants: true,
      topSoldProducts: true,
      fluctuations: false,
    },
  };

  getTopSoldProducts(query = {}) {
    return this.makeRequest(
      "fetch-top-sold-products",
      query,
      "topSoldProducts",
      "topSoldProducts"
    );
  }

}

export default DefaultReportModel.init();
