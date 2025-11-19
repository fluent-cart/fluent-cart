import Model from "@/utils/model/Model";
import TableManager from "@/utils/table/TableManager";
import Rest from "@/utils/http/Rest";

class LicenseReportModel extends Model {
  get controllerKey() {
    return "LicenseReportController";
  }

  beforeInit() {

  }

  data = {
    summaryData: [],
    lineChartData: [],
    pieChartData: [],
    isBusy: false,
  };

  makeRequest(endPoint, query, accessor, key) {
    this.data.isBusy = true;

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
        }
      })
      .catch((error) => {
        console.log(error, "error");
      })
      .finally(() => {
        this.data.isBusy = false;
      });
  }

  getLicenseLineChart(query = {}) {
    return this.makeRequest(
      "license-chart",
      query,
      "lineChartData",
      "lineChartData"
    );
  }

  getLicensePieChart(query = {}) {
    return this.makeRequest(
      "license-pie-chart",
      query,
      "pieChartData",
      "pieChartData"
    );
  }

  getSummary(query = {}) {
    return this.makeRequest(
      "license-summary",
      query,
      "summaryData",
      "summaryData"
    );
  }
}

export default LicenseReportModel.init();
