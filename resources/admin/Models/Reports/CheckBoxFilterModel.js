import Model from "@/utils/model/Model";
import Storage from "@/utils/Storage.js";

class CheckBoxFilterModel extends Model {
    data = {
        order: [
            "net_revenue",
            "gross_sale",
            "order_count",
            "total_item_count",
            "average_net",
            "average_gross",
            "average_order_items_count"
        ],
        revenue: [
            "Revenue",
            "Gross Sales",
            "Refund",
            "Taxes",
            "Shipping",
            "Discount"
        ],
        refund: [
            "Total Refunds",
            "Total Refunded Amount",
            "Avg. Refunded Amount",
            "Refund Rate",
            "Total Paid"
        ],
        product: [
            "average_selling_price",
            "units_sold",
            "gross_sale",
            "customer_count"
        ],
    };

    storageKey = "report-checkbox-filter";

    beforeInit() {
        this.mergeWithStorage();
    }

    mergeWithStorage() {
        const data = Storage.get(this.storageKey);
        this.data.order = data.order ?? this.data.order;
        this.data.revenue = data.revenue ?? this.data.revenue;
        this.data.refund = data.refund ?? this.data.refund;
    }

    listeners = {};

    addListener(key, listener) {
        this.listeners[key] = listener;
    }

    async onCheckBoxChange(section, value) {
        const sectionData = this.data[section];

        if (sectionData.includes(value)) {
            this.data[section] = sectionData.filter((item) => item !== value);
        } else {
            this.data[section].push(value);
        }

        this.syncStorage();

        // Notify listeners
        for (let key in this.listeners) {
            this.listeners[key](this.applicableFilters);
        }
    }

    syncStorage() {
        Storage.set(this.storageKey, this.applicableFilters.checkbox);
    }

    removeListener(key) {
        delete this.listeners[key];
    }

    get applicableFilters() {
        return {
            checkbox: {
                order: this.data.order,
                revenue: this.data.revenue,
                refund: this.data.refund,
                product: this.data.product,
            },
        };
    }
}

export default CheckBoxFilterModel.init();
  