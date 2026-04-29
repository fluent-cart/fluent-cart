import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";

class RefundReportModel extends Model {
  get controllerKey() {
    return "RefundReportController";
  }

  data = {
    refundData: [],
    previousMetrics: [],
    summary: {},
    previousSummary: {},
    fluctuations: {},
    chartData: [],
    weeksBetweenRefund: [],
    isBusy: {
      chartData: true,
      weeksBetweenRefund: true,
      refundData: true,
    },
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
          Object.assign(this.data, response)
        }
      })
      .catch((error) => {
        console.log(error, "error");
      })
      .finally(() => {
        if (key) this.data.isBusy[key] = false;
      });
  }

  getRefundChartData(query = {}) {
    return this.makeRequest(
      "refund-chart",
      query,
      null,
      "chartData"
    );
  }

  getWeeksBetweenRefund(query = {}) {
    return this.makeRequest(
      "weeks-between-refund",
      query,
      "data",
      "weeksBetweenRefund"
    );
  }

  getRefundSummary(query = {}) {
    return this.makeRequest(
      "refund-summary",
      query,
      "summaryData",
      "summaryData"
    );
  }

  getRefundDataByGroup(query = {}) {
    return this.makeRequest("refund-data-by-group", query, "data", "refundData");
  }

  getRefundReportFluctuations(query = {}) {
    return this.makeRequest(
      "refund-report-fluctuations",
      query,
      "data",
      "fluctuations"
    );
  }
}

export default RefundReportModel.init();
