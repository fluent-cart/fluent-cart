import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";

class OrderReportModel extends Model {
  get controllerKey() {
    return "OrderReportController";
  }

  data = {
    summary: {},
    previousSummary: {},
    fluctuations: {},
    orderChartData: [],
    newVsReturning: [],
    orderByGroup: [],
    itemCountDistribution: [],
    grossSaleByDay: [],
    averageOrderGross: [],
    grossSaleByHour: [],
    orderCompletionTime: [],
    averageOrderItems: [],
    orderValueDistribution: [],
    orderByDayAndHour: [],
    dashBoardStats: [],
    isBusy: {
      summary: false,
      fluctuations: false,
      orderChartData: true,
      newVsReturning: true,
      orderByGroup: false,
      itemCountDistribution: false,
      grossSaleByDay: false,
      averageOrderGross: false,
      grossSaleByHour: false,
      orderCompletionTime: false,
      averageOrderItems: false,
      orderValueDistribution: false,
      orderByDayAndHour: false,
      dashBoardStats: false,
    },
  };

  makeRequest(endPoint, query, accessor, key) {
    this.data.isBusy[key] = true;

    if (typeof query !== "object") {
      query = {};
    }
    const request = Rest.get(`reports/${endPoint}`, query);
    this.resolvePromiseResponse(request, accessor, key);
    return request;
  }

  resolvePromiseResponse(promise, accessor = null, key = null) {
    promise
      .then((response) => {
        if (!(key == null || accessor == null)) {
          this.data[key] = response[accessor];
        } else {
          Object.assign(this.data, response);
        }
      })
      .catch((error) => {

      })
      .finally(() => {
        this.data.isBusy[key] = false;
      });
  }

  getOrderChartData(query = {}) {
    this.data.isBusy.orderChartData = true;

    return this.makeRequest(
      "order-chart",
      query
    ).then(() => this.data.isBusy.orderChartData = false)
  }

  getNewVsReturningCustomer(query = {}) {
    this.data.isBusy.newVsReturning = true;

    return this.makeRequest(
      "fetch-new-vs-returning-customer",
      query
    ).then(() => this.data.isBusy.newVsReturning = false);
  }

  getOrderByGroup(query = {}) {
    return this.makeRequest(
      "fetch-order-by-group",
      query,
      "data",
      "orderByGroup"
    );
  }

  getItemCountDistribution(query = {}) {
    return this.makeRequest(
      "item-count-distribution",
      query,
      "data",
      "itemCountDistribution"
    );
  }

  getOrderCompletionTime(query = {}) {
    return this.makeRequest(
      "order-completion-time",
      query,
      "data",
      "orderCompletionTime"
    );
  }

  getOrderValueDistribution(query = {}) {
    return this.makeRequest(
      "order-value-distribution",
      query,
      "data",
      "orderValueDistribution"
    );
  }

  getReportByDayAndHour(query = {}) {
    this.data.isBusy.orderByDayAndHour = true;
    return this.makeRequest(
      "fetch-report-by-day-and-hour",
      query
    ).then(() => this.data.isBusy.orderByDayAndHour = false);
  }

  getDashboardStats(query = {}) {
    return this.makeRequest(
      "dashboard-stats",
      query,
      "dashBoardStats",
      "dashBoardStats"
    );
  }
}

export default OrderReportModel.init();
