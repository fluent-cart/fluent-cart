import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";

const emptyProductReport = () => ({
  summary: {},
  previousSummary: {},
  fluctuations: {},
  currentMetrics: [],
  previousMetrics: [],
});

// Module scope, not a class field: the Model proxy redirects `this.x = ...` writes into data.
let productReportRequestId = 0;
let topSoldVariantsRequestId = 0;

class ProductReportModel extends Model {
  get controllerKey() {
    return "ProductReportController";
  }

  data = {
    ...emptyProductReport(),
    isBusy: true,
    productReportBusy: true,
    chartData: [],
    topSoldProducts: [],
    error: null,
    topSoldVariants: [],
    topSoldVariantsBusy: true,
    topSoldVariantsError: null,
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
    const requestId = ++productReportRequestId;
    const isLatest = () => requestId === productReportRequestId;

    this.data.error = null;
    this.data.isBusy = true;
    this.data.productReportBusy = true;

    const request = Rest.get("reports/product-report", typeof query === "object" ? query : {});
    request
      .then((response) => {
        if (isLatest()) {
          Object.assign(this.data, response);
        }
      })
      .catch((error) => {
        if (!isLatest()) {
          return;
        }
        Object.assign(this.data, emptyProductReport());
        this.data.error = error?.data?.message || translate("Could not load the product report.");
      })
      .finally(() => {
        this.data.isBusy = false;
        if (isLatest()) {
          this.data.productReportBusy = false;
        }
      });
    return request;
  }

  getTopSoldVariants(query = {}) {
    const requestId = ++topSoldVariantsRequestId;
    const isLatest = () => requestId === topSoldVariantsRequestId;

    this.data.topSoldVariantsError = null;
    this.data.topSoldVariantsBusy = true;

    const request = Rest.get("reports/fetch-top-sold-variants", typeof query === "object" ? query : {});
    request
      .then((response) => {
        if (isLatest()) {
          this.data.topSoldVariants = response?.topSoldVariants || [];
        }
      })
      .catch((error) => {
        if (!isLatest()) {
          return;
        }
        this.data.topSoldVariants = [];
        this.data.topSoldVariantsError = error?.data?.message || translate("Could not load the top sold variants.");
      })
      .finally(() => {
        if (isLatest()) {
          this.data.topSoldVariantsBusy = false;
        }
      });
    return request;
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
