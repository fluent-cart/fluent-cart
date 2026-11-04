import ReportRequestModel from "./ReportRequestModel";

class RevenueReportModel extends ReportRequestModel {
  get controllerKey() {
    return "RevenueReportController";
  }

  data = {
    revenueReport: [],
    previousMetrics: [],
    netRevenueByGroup: [],
    byOrderType: [],
    summary: {},
    previousSummary: {},
    fluctuations: {},
    isBusy: {
      revenueReport: true,
      netRevenueByGroup: false,
      revenueSummary: false,
      fluctuations: false,
      chartData: false,
      productBarRacer: false,
    },
    chartData: [],
    productBarRacer:[],
    appliedGroupKey: 'default'
  };

  getReportData(query = {}) {
    return this.makeRequest(
      "revenue",
      query,
      null,
      "revenueReport"
    );
  }

  // getRevenueSummary(query = {}) {
  //   return this.makeRequest(
  //     "revenue-summary",
  //     query
  //   );
  // }

}

export default RevenueReportModel.init();
