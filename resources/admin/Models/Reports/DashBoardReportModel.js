import Model from "@/utils/model/Model";
import TableManager from "@/utils/table/TableManager";
import Rest from "@/utils/http/Rest";
import AppConfig from "@/utils/Config/AppConfig";

class DashBoardReportModel extends Model {
    get controllerKey() {
        return "ReportingController";
    }

    beforeInit() {

    }

    data = {
        salesGrowthChart: [],
        countryHeatMap: [],
        fluctuations: [],
        dashBoardStats: [],
        recentOrders: [],
        unfulfilledOrders:[],
        recentActivities: [],
        topSoldProducts: [],
        currencySign: AppConfig.get('shop.currency_sign'),
        isBusy: {
            recentActivities: false,
            fluctuations: false,
            dashBoardStats: false,
            recentOrders: false,
            unfulfilledOrders: false,
            countryHeatMap: false,
            salesGrowthChart: true,
            topSoldProducts: true
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
                //console.log(response);
                if (!(key == null || accessor == null)) {
                    this.data[key] = response[accessor];
                }
            })
            .catch((error) => {
                //console.log(error, "error");
            })
            .finally(() => {
                this.data.isBusy[key] = false;
            });
    }

    getDashboardStats(query = {}) {
        return this.makeRequest(
            "dashboard-stats",
            query,
            "dashBoardStats",
            "dashBoardStats"
        );
    }


    getSalesGrowthChart(query = {}) {
        return this.makeRequest(
            "sales-growth-chart",
            query,
            "salesGrowthChart",
            "salesGrowthChart"
        );
    }

    getCountryHeatMap(query = {}) {
        return this.makeRequest(
            "country-heat-map",
            query,
            "countryHeatMap",
            "countryHeatMap"
        );
    }

    getRecentOrders(query = {}) {
        return this.makeRequest(
            "get-recent-orders",
            query,
            "recentOrders",
            "recentOrders"
        );
    }

    getUnfulfilledOrders(query = {}) {
        return this.makeRequest(
            "get-unfulfilled-orders",
            query,
            "unfulfilledOrders",
            "unfulfilledOrders"
        );
    }

    getRecentActivities(query = {}) {
        return this.makeRequest(
            "get-recent-activities",
            query,
            "recentActivities",
            "recentActivities"
        );
    }

    getSummary(query = {}) {
        return this.makeRequest(
            "get-dashboard-summary",
            query,
            "summaryData",
            "summaryData"
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

export default DashBoardReportModel.init();
