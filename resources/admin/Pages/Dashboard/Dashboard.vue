<template>
  <div class="fluent_dashboard fluent-cart-admin-pages fct-layout-width">
    <!-- <div class="my-6">
      <OrderStatBar />
    </div> -->


    <Alert
        class="mb-5"
        v-if="showPageNotification"
        type="warning"
        icon="InformationFill"
    >

        <span v-html="
        /* translators: %s is the link to set up pages */
        translate('Set up your pages first to get started %s',
          '<a href=&quot;' + getSetupPageUrl() + '&quot; target=&quot;_blank&quot;>' + translate('Set up your pages') + '</a>'
          )"
        >
        </span>

    </Alert>

    <el-row :gutter="20">
      <el-col :lg="16">
        <CurrentUserInfo/>

        <UserCan permission="dashboard_stats/view">
          <div class="flex items-center justify-start gap-1 mb-2 text-xs text-system-mid dark:text-system-light">
            <DynamicIcon name="InformationFill" class="w-4 h-4 text-system-light" />

            {{
              /* translators: %s - number of days */
              translate('Last %s Days', translateNumber(30))
            }}
          </div>
          <StatsWidget/>
        </UserCan>

        <UserCan permission="reports/view">
          <SalesGrowthChart
              :chartData="dashBoardReport.data.salesGrowthChart"
              :loading="dashBoardReport.isBusy.salesGrowthChart"
          />
        </UserCan>

        <el-row :gutter="24">
          <UserCan permission="reports/view">
            <el-col :lg="12">
              <RecentOrders/>
            </el-col>
          </UserCan>

          <UserCan permission="reports/view">
            <el-col :lg="12" class="mt-6 xl:mt-0">
              <TopSoldProducts
                  :data="dashBoardReport.topSoldProducts"
                  :loading="dashBoardReport.isBusy.topSoldProducts"
              />
            </el-col>
          </UserCan>


          <UserCan permission="reports/view">
            <el-col class="mt-6">
              <HeatMap :data="dashBoardReport.data.countryHeatMap"/>
            </el-col>
          </UserCan>


        </el-row>

      </el-col>
      <el-col :lg="8">
        <div class="fc_dashboard_sidebar">
          <SideBar/>
        </div>
      </el-col>
    </el-row>
    <!--       <RecentActivity/> -->

    <el-dialog
        class="fct-congrats-dialog"
        v-model="isCongratsPopoverOpen"
        :append-to-body="true"
        :close-on-click-modal="false"
        modal-class="fct-congrats-modal"
        width="800">
      <div class="fct-congrats-dialog-content">
        <div class="fct-congrats-dialog-content-inner">
          <div class="icon">🎉</div>
          <h3>{{ translate('Congratulations!') }}</h3>
          <p>{{ translate('You successfully finished your onboarding & your store is ready.') }}</p>
          <el-button type="primary" @click="isCongratsPopoverOpen = false">{{ translate("Let's go") }}</el-button>
        </div>
      </div>
      <div
          class="fct-confetti-wrap pointer-events-none fixed left-0 top-0 w-full h-full flex justify-center items-center">
        <ConfettiExplosion :particleCount="100" :force="1"/>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import {ref, getCurrentInstance, onMounted} from "vue";
import OrderStatBar from "@/Bits/Components/Stats/OrderStat/OrderStatBar.vue";
import dashBoardReport from "@/Models/Reports/DashBoardReportModel";
import SalesGrowthChart from "./Components/SalesGrowthChart.vue";
import HeatMap from "./Components/HeatMap.vue";
import RecentOrders from "./Components/RecentOrders.vue";
import SideBar from "./Components/SideBar.vue";
import CurrentUserInfo from "@/Pages/Dashboard/Components/CurrentUserInfo.vue";
import dayjs from "dayjs";
import StatsWidget from "@/Pages/Dashboard/Components/StatsWidget.vue";
import Storage from "@/utils/Storage";
import Url from "@/utils/support/Url";
import ConfettiExplosion from "vue-confetti-explosion";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import TopSoldProducts from "@/Modules/Reports/Default/Components/TopSoldProducts.vue";
import {provide} from "vue"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Alert from "@/Bits/Components/Alert.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

defineOptions({
  name: "Dashboard",
});

const isCongratsPopoverOpen = ref(false);
const dummyProductInfo = ref([]);

const showPageNotification = ref(false);

const togglePageNotification = (state) => {
  showPageNotification.value = state;
}

provide("dashboardTogglePageNotification", togglePageNotification)

const getSalesGrowth = () => {
  const [startDate, endDate] = [
    dayjs().subtract(1, 'month').format("YYYY-MM-DD 00:00:00"),
    dayjs().format("YYYY-MM-DD 23:59:59"),
  ];

  const params = {
    startDate: startDate,
    endDate: endDate,
    paymentStatus: "all",
  };

  dashBoardReport.getSalesGrowthChart({
    params: {
      ...params,
      groupKey: "daily",
    },
  });
};

const getRecentOrders = () => {
  dashBoardReport.getRecentOrders({
    params: null,
  });
};

const getCountryHeatMap = () => {
  const params = {
    paymentStatus: "all",
  };
  dashBoardReport.getCountryHeatMap({
    params: {
      ...params,
      groupKey: "daily",
    },
  });
};

// const getUnfulfilledOrders = () => {
//   dashBoardReport.getUnfulfilledOrders({
//     params: null,
//   });
// };

const getTopSoldProducts = () => {
  const [startDate, endDate] = [
    dayjs().subtract(1, 'month').format("YYYY-MM-DD 00:00:00"),
    dayjs().format("YYYY-MM-DD 23:59:59"),
  ];

  const params = {
    startDate: startDate,
    endDate: endDate,
  };

  dashBoardReport.getTopSoldProducts({
    params: {
      ...params,
    },
  });
};

const getCongratsPopover = () => {
  const params = Url.getVueParams();
  const isFirstOnboarding = Storage.get('onboarding_congratulations', 'no');

  if (isFirstOnboarding !== 'yes' && AppConfig.get('total_products_count') == 0) {
    Storage.set('onboarding_congratulations', 'yes');
    isCongratsPopoverOpen.value = true;
  }
};

const getSetupPageUrl = () => {
  return AppConfig.get('admin_url') + 'settings/store-settings/pages_setup';
}

onMounted(() => {
  window.addEventListener("getSalesGrowth", getSalesGrowth);
  dummyProductInfo.value = AppConfig.get('dummy_product_info');
  getSalesGrowth();
  getCountryHeatMap();
  getRecentOrders();
  // getUnfulfilledOrders();
  getCongratsPopover();
  getTopSoldProducts();
});
</script>
