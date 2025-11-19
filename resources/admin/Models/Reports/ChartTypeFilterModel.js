import Model from "@/utils/model/Model";
import Storage from "@/utils/Storage.js";

class ChartTypeFilterModel extends Model {
  data = {
    dashboard: [{ name: "sales_growth_chart", type: "line" }],
    order: [
      { name: "gross_sale_by_day_chart", type: "" },
      { name: "order_completion_time_chart", type: "" },
      { name: "item_count_distribution_chart", type: "" },
      { name: "gross_sale_by_hour_chart", type: "" },
      { name: "order_value_distribution_chart", type: "" },
      { name: "avg_order_gross_chart", type: "" },
      { name: "avg_order_items_chart", type: "" },
      { name: "order_chart", type: "" },
    ],
    revenue: [{ name: "revenue_chart", type: "line" }],
    refund: [{ name: "refund_chart", type: "line" }],
    subscription: [
      {name:"subscription_chart", type: "line"},
      {name:"retention_chart", type: "line"},
      {name:"daily_signups_chart", type: "line"}
    ],
    product: [{name:"product_chart", type: "bar"}]
  };

  storageKey = "chart-type";

  beforeInit() {
    this.mergeWithStorage();
  }

  mergeWithStorage() {
    const data = Storage.get(this.storageKey);
    this.data.dashboard = data.dashboard ?? this.data.dashboard;
    this.data.order = data.order ?? this.data.order;
    this.data.revenue = data.revenue ?? this.data.revenue;
    this.data.refund = data.refund ?? this.data.refund;
    this.data.subscription = data.subscription?? this.data.subscription;
  }

  listeners = {};

  addListener(key, listener) {
    this.listeners[key] = listener;
  }

  async onChange(section, name, type) {
    const sectionData = this.data[section];

    const itemIndex = sectionData.findIndex((item) => item.name === name);

    if (itemIndex !== -1) {
      this.data[section][itemIndex].type = type;
    } else {
      this.data[section].push({ name, type });
    }

    this.syncStorage();

    // Notify listeners
    for (let key in this.listeners) {
      this.listeners[key](this.applicableFilters);
    }
  }

  getChartType(section, name) {
    const sectionData = this.data[section];
    const item = sectionData.find((item) => item.name === name);
    return item ? item.type : null;
  }

  syncStorage() {
    Storage.set(this.storageKey, this.applicableFilters.chartType);
  }

  removeListener(key) {
    delete this.listeners[key];
  }

  get applicableFilters() {
    return {
      chartType: {
        dashboard: this.data.dashboard,
        order: this.data.order,
        revenue: this.data.revenue,
        refund: this.data.refund,
        subscription: this.data.subscription,
      },
    };
  }
}

export default ChartTypeFilterModel.init();
