import ReportRequestModel from "./ReportRequestModel";

class OrderReportModel extends ReportRequestModel {
  get controllerKey() {
    return "OrderReportController";
  }

  data = {
    summary: {},
    previousSummary: {},
    fluctuations: {},
    orderChartData: [],
    previousChartData: [],
    newVsReturning: [],
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

  getOrderChartData(query = {}) {
    return this.makeRequest(
      "order-chart",
      query,
      null,
      "orderChartData"
    );
  }

  getNewVsReturningCustomer(query = {}) {
    return this.makeRequest(
      "fetch-new-vs-returning-customer",
      query,
      null,
      "newVsReturning"
    );
  }

}

export default OrderReportModel.init();
