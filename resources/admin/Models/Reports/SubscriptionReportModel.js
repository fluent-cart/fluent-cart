import Model from "@/utils/model/Model";
import TableManager from "@/utils/table/TableManager";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";

class SubscriptionReportModel extends Model {
    get controllerKey() {
        return "SubscriptionReportController";
    }

    data = {
        overviews: [],
        fluctuations: [],
        chartData: [],
        productList: [],
        customerList: [],
        revenueChurnRateNet: {
            title: translate("Revenue Churn Rate"),
            isCurrency: false,
            value: 0,
        },
        netNewMrr: {
            title: translate("Net New MRR"),
            isCurrency: true,
            value: 0,
        },
        isBusy: {
            overviews: false,
            fluctuations: false,
            chartData: true,
            productList: false,
            customerList: false,
            revenueChurnRateNet: true,
            netNewMrr: true,
        },

        currentMetrics: [],
        previousMetrics: []
    };

    get revenueChurnRateNet() {
        return this.data.revenueChurnRateNet
    }

    get netNewMrr() {
        return this.data.netNewMrr
    }

    makeRequest(endPoint, query, accessor, key) {
        if (typeof query !== "object") {
            query = {};
        }
        this.data.isBusy[key] = true;
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
                this.data.isBusy[key] = false;
            });
    }

    getSubscriptionReportFluctuations(query = {}) {
        return this.makeRequest(
            "getSubscriptionReportFluctuations",
            query,
            "fluctuations",
            "fluctuations"
        );
    }

    getSubscriptionReportFluctuations(query = {}) {
        return this.makeRequest("get-subscription-fluctuations", query, "fluctuations", "fluctuations");
    }

    getRetentionChart(query = {}) {
        return this.makeRequest("retention-chart", query, "chartData", "chartData");
    }

    listSubscriptionsByProduct(query = {}) {
        return this.makeRequest(
            "list-subscription-by-product",
            query,
            "productList",
            "productList"
        );
    }

    listSubscriptionsByCustomer(query = {}) {
        return this.makeRequest(
            "list-subscription-by-customer",
            query,
            "customerList",
            "customerList"
        );
    }

    getSubscriptionOverview(query = {}) {

        return this.makeRequest(
            "get-subscription-overview",
            query,
            "overviews",
            "overviews"
        ).then((response) => {
            this.getRevenueChurnRateNet(query);
            this.getNetNewMrr(query);
        }).finally(() => {

        });

    }

    getRevenueChurnRateNet(query = {}) {
        this.data.isBusy.revenueChurnRateNet = true;
        Rest.get(`reports/get-subscription-overview/revenue-churn-rate-net`, query).then((response) => {
            this.data.revenueChurnRateNet = response.data;
        }).finally(() => {
            this.data.isBusy.revenueChurnRateNet = false;
        });
    }

    getNetNewMrr(query = {}) {
        this.data.isBusy.netNewMrr = true;
        Rest.get(`reports/get-subscription-overview/net-new-mrr`, query).then((response) => {
            this.data.netNewMrr = response.data;
        }).finally(() => {
            this.data.isBusy.netNewMrr = false;
        });
    }
}

export default SubscriptionReportModel.init();
