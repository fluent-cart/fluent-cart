import Model from "@/utils/model/Model";
import Rest from "@/utils/http/Rest";

class CartReportModel extends Model {

  data = {
    abandonedItems: {},
    summaryData: {},
    fluctuations: {},
    graphs: {},
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

  getAbandonedCartItems(query = {}) {
    return this.makeRequest(
      "cart-report",
      query,
      "abandonedItems",
      "abandonedItems"
    );
  }
}

export default CartReportModel.init();
