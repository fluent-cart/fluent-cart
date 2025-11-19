import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";

class RevenueReportModel extends Model {
  get controllerKey() {
    return "RevenueReportController";
  }

  data = {
    revenueReport: [],
    previousMetrics: [],
    netRevenueByGroup: [],
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

  makeRequest(endPoint, query, accessor, key) {
    if (key) this.data.isBusy[key] = true;

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
        console.log(error, "error");
      })
      .finally(() => {
        if (key) this.data.isBusy[key] = false;
      });
  }

  getReportData(query = {}) {
    return this.makeRequest(
      "revenue",
      query,
      null,
      "revenueReport"
    );
  }

  getReportByGroup(query = {}) {
    return this.makeRequest(
      "revenue-by-group",
      query,
      "data",
      "netRevenueByGroup"
    );
  }

  getChartData(query = {}) {
    return this.makeRequest(
      "revenue-chart",
      query,
      "revenueReport",
      "chartData"
    );
  }

  // getRevenueSummary(query = {}) {
  //   return this.makeRequest(
  //     "revenue-summary",
  //     query
  //   );
  // }

  getProductBarRacer(query = {}) {
    return this.makeRequest(
      "fetch-product-bar-racer",
      query,
      "productBarRacer",
      "productBarRacer"
    );
  }

}

export default RevenueReportModel.init();
