import ReportRequestModel from "./ReportRequestModel";
import AppConfig from "@/utils/Config/AppConfig";

class DashBoardReportModel extends ReportRequestModel {
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
        recentActivities: [],
        topSoldProducts: [],
        currencySign: AppConfig.get('shop.currency_sign'),
        isBusy: {
            recentActivities: false,
            fluctuations: false,
            dashBoardStats: false,
            recentOrders: false,
            countryHeatMap: false,
            salesGrowthChart: true,
            topSoldProducts: true
        },
    };

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

    getRecentActivities(query = {}) {
        return this.makeRequest(
            "get-recent-activities",
            query,
            "recentActivities",
            "recentActivities"
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
