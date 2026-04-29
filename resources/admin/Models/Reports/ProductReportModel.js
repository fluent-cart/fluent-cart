import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";

class ProductReportModel extends Model {
  get controllerKey() {
    return "ProductReportController";
  }

  data = {
    summary: {},
    previousSummary: {},
    fluctuations: {},
    isBusy: true,
    currentMetrics: [],
    previousMetrics: [],
    chartData: [],
    topSoldProducts: [],
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
        } else {
          Object.assign(this.data, response);
        }
      })
      .catch((error) => {
        console.log(error, "error");
      })
      .finally(() => {
        this.data.isBusy = false;
      });
  }

  getReportData(query = {}) {
    return this.makeRequest(
      "net-revenue",
      query,
      "data",
      "revenueReport"
    );
  }

  getReportByGroup(query = {}) {
    return this.makeRequest(
      "net-revenue-by-group",
      query,
      "data",
      "netRevenueByGroup"
    );
  }

  getProductReportData(query = {}) {
    return this.makeRequest(
      "product-report",
      query
    );
  }

  getSummary(query = {}) {
    return this.makeRequest(
      "product-report-summary",
      query
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
}

export default ProductReportModel.init();
