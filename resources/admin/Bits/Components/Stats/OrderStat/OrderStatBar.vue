<template>
  <div class="fct-order-stats-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('Order Stats Overview')"
          border_bottom
      >
        <template #action>
          <div class="fct-order-stats-actions flex items-center gap-2.5">
            <Fluid.Tab class="hide-animation-on-mobile">
              <Fluid.Item
                  :title="`${translate('Order Stats Overview of')} ${stat.label}`"
                  tabindex="0"
                  v-for="(stat, statIndex) in stat_ranges"
                  :key="statIndex"
                  @click="handleStatRangeChanged(statIndex)"
                  :class="statIndex === stat_range ? 'active' : ''"
              >
                {{ stat.label }}
              </Fluid.Item>
            </Fluid.Tab>
          </div>
          <!-- .fct-order-stats-actions -->
        </template>
      </Card.Header>

      <Card.Body>
        <div class="fct-order-stats-body">
          <OrderStatLoader v-if="loading"/>

          <div v-else class="fct-order-stats-row">
            <div
                v-for="(stat, statKey) in stats"
                :key="statKey"
                class="fct-order-stats-col cursor-pointer"
                @click="router.push({name:'reports_orders'})"
            >
              <StatCard
                  :key="statKey"
                  :stat="stat"
                  :current-range="stat_range"
                  :stat-ranges="stat_ranges"
                  :loading="loading"
                  :stat-key="statKey"
              >
                <template v-slot:icon>
                  <DynamicIcon :name="stat.icon"/>
                </template>
              </StatCard>
            </div>
            <!-- .order-state-col -->
          </div>

          <div class="flex justify-end mt-2">
            <el-button text class="underline-link-button"
                     @click="router.push({name: 'reports_orders'})">{{ translate('See Full Report') }}
            </el-button>
          </div>
        </div>
        <!-- .fct-order-stats-body -->
      </Card.Body>
    </Card.Container>
  </div>
  <!-- .fct-order-stats-wrap -->
</template>

<script setup>
import {getCurrentInstance, onMounted, ref, watch} from "vue";
import StatCard from "./Components/StatCard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import dashBoardReport from "@/Models/Reports/DashBoardReportModel";
import dayjs from "dayjs";
import translate, {translateNumber} from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";
import OrderStatLoader from "./Components/OrderStatLoader.vue";
import {useRouter} from "vue-router";

defineOptions({
  name: "OrderStatBar",
});

const isCollapsed = ref(
    Storage.get('order_stat_collapse', false)
);

const collapsibleActiveItemName = ref("");

const selfRef = getCurrentInstance().ctx;
const stats = ref(false);
const loading = ref(true);
const stat_range_popup = ref(false);
const stat_range = ref("-7 days");

const router = useRouter();

const stat_ranges = ref({
  "-0 days": {
    label: translate("Today"),
    desc: translate("Compared to yesterday"),
  },
  "-7 days": {
    label: translate("Last 7 days"),
    desc: translate("Compared to the previous 7 days"),
  },
  "-30 days": {
    label: translate("Last 30 days"),
    desc: translate("Compared to the previous 30 days"),
  },
  "this_month": {
    label: translate("This Month"),
    desc: translate("Compared to the previous Month"),
  },
  "all_time": {
    label: translate("All Time"),
    desc: translate("See All time Sales Stat"),
  },
});

const handleStatRangeChanged = async (index) => {
  // Check if the clicked item is the same as the current stat_range
  if (stat_range.value === index) {
    return;
  }

  try {
    // Update stat_range and collapsibleActiveItemName immediately
    stat_range.value = index;
    collapsibleActiveItemName.value = "collapse";

    // Toggle isCollapsed if it is currently true
    if (isCollapsed.value === true) {
      isCollapsed.value = false;
      Storage.set('order_stat_collapse', isCollapsed.value);
    }

    // Save to storage and fetchStat asynchronously
    await Promise.all([
      selfRef.Storage().set("order_quick_stat_bar_range", stat_range.value),
      fetchStat(),
    ]);
  } catch (error) {
    console.error("Error in handleStatRangeChanged:", error);
  }

};

const onCollapsibleItemChange = (item) => {
  Storage.set('order_quick_stat_bar_collapse_item', item);
};
const rangeChanged = () => {
  collapsibleActiveItemName.value = "collapse";
  selfRef.Storage().set("order_quick_stat_bar_range", stat_range.value);
  fetchStat();
  window.dispatchEvent(new CustomEvent("getSalesGrowth"));
};

const onStateRangeChanged = () => {
  Storage.set('order_quick_stat_bar_range', stat_range.value);
  fetchStat();
};

const toggleCollapse = () => {
  isCollapsed.value = !isCollapsed.value;
  Storage.set('order_stat_collapse', isCollapsed.value);
};

// const fetchStat = () => {
//   stat_range_popup.value = false
//   loading.value = true;
//   selfRef.$get('reports/quick-order-stats', {
//     day_range: stat_range.value
//   })
//       .then(response => {
//         console.log(response.stats, "_____")
//         stats.value = response.stats
//       })
//       .catch((errors) => {
//         selfRef.handleError(errors);
//       })
//       .finally(() => {
//         loading.value = false;
//       });
// }

//to do: sync with the stat_range
const fetchStat = () => {
  stat_range_popup.value = false;
  loading.value = true;

  const dateShortCuts = {
    "-0 days": [
      dayjs().startOf("day").format("YYYY-MM-DD"),
      dayjs().endOf("day").format("YYYY-MM-DD"),
    ],
    "-7 days": [
      dayjs().subtract(7, "days").startOf("day").format("YYYY-MM-DD"),
      dayjs().endOf("day").format("YYYY-MM-DD"),
    ],
    "-30 days": [
      dayjs().subtract(30, "days").startOf("day").format("YYYY-MM-DD"),
      dayjs().endOf("day").format("YYYY-MM-DD"),
    ],
    "this_month": [
      dayjs().startOf("month").format("YYYY-MM-DD"),
      dayjs().endOf("day").format("YYYY-MM-DD"),
    ],
    "all_time": [null, null],
  };

  const [startDate, endDate] = dateShortCuts[stat_range.value] || [
    dayjs().startOf("month"),
    dayjs().endOf("day"),
  ];

  const params = {
    startDate: startDate,
    endDate: endDate,
    paymentStatus: "all",
  };

  dashBoardReport
      .getDashboardStats({
        params: {
          ...params,
        },
      })
      .then(() => {
        stats.value = dashBoardReport.data.dashBoardStats;
      })
      .catch((error) => {
        console.error("Error fetching dashboard stats:", error);
      })
      .finally(() => {
        loading.value = false;
      });


};

onMounted(() => {
  stat_range.value = Storage.get('order_quick_stat_bar_range', '-0 days');
  let order_quick_stat_bar_collapse_item = Storage.get("order_quick_stat_bar_collapse_item");
  collapsibleActiveItemName.value =
      order_quick_stat_bar_collapse_item === null
          ? "collapse"
          : order_quick_stat_bar_collapse_item;
  fetchStat();
});


</script>
