import Model from "@/utils/model/Model";
import Arr from "@/utils/support/Arr";
import Rest from "@/utils/http/Rest";

class DefaultReportModel extends Model {
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
    failedOrders: [],
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
      salesReport: true,
    },
  };

  makeRequest(endPoint, query, accessor, key) {
    if (key && key.startsWith("graphs.")) {
      const graphKey = key.split(".")[1];
      this.data.isBusy.graphs[graphKey] = true;
    } else {
      this.data.isBusy[key] = true;
    }

    let request;
    if (typeof query === "object") {
      request = Rest.get("reports/" + endPoint, query);
      //request = this.controller.withParams(query)[endPoint]();
    } else {
      request = Rest.get("reports/" + endPoint);
      //request = this.controller[endPoint]();
    }

    this.resolvePromiseResponse(request, accessor, key);
    return request;
  }

  resolvePromiseResponse(promise, accessor = null, key = null) {
    promise
      .then((response) => {
        if (!(key == null || accessor == null)) {
          Arr.set(this.data, key, Arr.get(response, accessor));
          //this.data[key] = response[accessor];
        } else {
          Object.assign(this.data, response);
        }
      })
      .catch((error) => {
        console.log(error, "error");
      })
      .finally(() => {
        if (key && key.startsWith("graphs.")) {
          const graphKey = key.split(".")[1];
          this.data.isBusy.graphs[graphKey] = false;
        } else {
          this.data.isBusy[key] = false;
        }
      });
  }

  getDefaultReport(query = {}) {
    return this.makeRequest(
      "fetch-default-report",
      query,
      "summaryData",
      "summaryData"
    );
  }

  getDefaultReportFluctuations(query = {}) {
    return this.makeRequest(
      "fetch-default-report-fluctuations",
      query,
      "fluctuations",
      "fluctuations"
    );
  }

  getDefaultReportGraphs(query = {}) {
    let key = "graphs";
    return this.makeRequest(
      "fetch-default-report-graphs",
      query,
      "graphs." + query.params.graph,
      "graphs." + query.params.graph
    );
  }

  getTopSoldProducts(query = {}) {
    return this.makeRequest(
      "fetch-top-sold-products",
      query,
      "topSoldProducts",
      "topSoldProducts"
    );
  }

  getFailedOrders(query = {}) {
    return this.makeRequest(
      "fetch-failed-orders",
      query,
      "failedOrders",
      "failedOrders"
    );
  }

  getTopSoldVariants(query = {}) {
    return this.makeRequest(
      "fetch-top-sold-variants",
      query,
      "topSoldVariants",
      "topSoldVariants"
    );
  }

  getSalesReport(query = {}) {
    this.data.isBusy.salesReport = true;

    return this.makeRequest(
      "sales-report",
      query,
      'salesReport'
    ).then(() => this.data.isBusy.salesReport = false);
  }
}

export default DefaultReportModel.init();
