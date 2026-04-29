// CurrencySign.js
import { defineComponent, computed, h } from "vue";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import Arr from "@/utils/support/Arr";
import { formatNumber } from "../Utils/formatNumber";

export default defineComponent({
  name: "WithCurrency",
  props: {
    value: {
      type: [Number, String],
      default: null,
    },
  },
  setup(props) {
    const currencySign = computed(() => {
      return reportFilter?.currentCurrencySign || "";
    });

    // Get the currency position from the global window variable
    const currencyPosition = computed(() => {
      return Arr.get(
        window,
        "fluentCartAdminApp.shop.currency_position",
        "before"
      );
    });

    return () => {
      // If only showing the currency sign without a value
      if (props.value === null) {
        return h("span", { innerHTML: currencySign.value });
      }

      // Format with position
      if (currencyPosition.value === "before") {
        return h("span", {
          innerHTML: `${currencySign.value}${formatNumber(props.value)}`,
        });
      } else {
        return h("span", {
          innerHTML: `${formatNumber(props.value)}${currencySign.value}`,
        });
      }
    };
  },
});
