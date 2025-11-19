<script setup>
import {onMounted, ref} from "vue";
import dashBoardReport from "@/Models/Reports/DashBoardReportModel.js";
import {useRouter} from "vue-router";
import Notify from "@/utils/Notify";
import Permission from "@/utils/permission/Permission";
import Arr from "@/utils/support/Arr";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import translate, {translateNumber} from "../../../utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Str from "@/utils/support/Str";

const router = useRouter()

const props = defineProps({
  activities: {
    type: Array,
    default: () => []
  },
  showManageActivity: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(["reload"]);
const showModal = ref(false);
const activity = ref({});

const selectedGroupKeys = ref([
  {label: translate("All"), value: "all"},
  {label: translate("Today"), value: "today"},
  {label: translate("Yesterday"), value: "yesterday"},
  {label: translate("This Week"), value: "this_week"},
]);

const selectedGroupKey = ref('all');

const getFilteredTitle = () => {
  switch (selectedGroupKey.value) {
    case "all":
      return translate("all");
    case "today":
      return translate("today");
    case "yesterday":
      return translate("yesterday");
    case "this_week":
      return translate("this week");
    default:
      return selectedGroupKey.value;
  }
}

const getData = () => {
  getFilteredTitle();
  dashBoardReport.getRecentActivities({
    groupKey: selectedGroupKey.value
  });
};

const deleteModal = (selectedActivity) => {
  showModal.value = true;
  activity.value = selectedActivity;
};

const deleteActivity = () => {
  showModal.value = false;
  Rest.delete(`activity/${activity.value.id}`).then((res) => {
    Notify.success(res.data?.message);
    emit("reload");
  });
};

const permissionMap = {
  'Order': 'orders/view',
  'Payment': 'orders/view',
  'Product': 'products/view',
  'Customer': 'customers/view',
  'Coupon': 'coupons/view',
};

const activityRouteHandler = (activity) => {

  const moduleRoutes = {
    'Order': {name: 'view_order', param: 'order_id', permission: 'orders/view'},
    'Payment': {name: 'view_order', param: 'order_id', permission: 'orders/view'},
    'Product': {name: 'view_product', param: 'product_id', permission: 'products/view'},
    'Customer': {name: 'view_customer', param: 'customer_id', permission: 'customers/view'},
    'Coupon': {name: 'view_coupon', param: 'coupon_id', permission: 'coupons/view'},
  };


  const route = moduleRoutes[activity.module_name];
  if (route && activity.module_id && checkPermission(activity)) {
    router.push({
      name: route.name,
      params: {[route.param]: activity.module_id},
    });
  } else {
    console.warn('Invalid activity or module not supported:', activity);
  }
}

const checkPermission = (activity) => {
  if (!activity.module_name) {
    return true
  }

  const permission = Arr.get(permissionMap, Str.capitalize(activity.module_name));
  return permission ? Permission.has(permission) : false;
}

onMounted(() => {
  getData();
});
</script>

<template>
  <div class="fct-dashboard-recent-activities-wrap">
    <div class="fct-dashboard-recent-activities__header">
      <h4 class="fct-dashboard-recent-activities__header_title">
        {{ translate('Recent Activities') }}
      </h4>
      <p class="fct-dashboard-recent-activities__header_desc">

        <template v-if="selectedGroupKey === 'all'">
          {{
            /* translators: %s - number of activities */
            translate('%s new activities', translateNumber(dashBoardReport.data.recentActivities.length) )
          }}
        </template>
        <template v-else>
          {{
            /* translators: %1$s - number of activities, %2$s - filtered title */
            translate('%1$s new activities %2$s', translateNumber(dashBoardReport.data.recentActivities.length), getFilteredTitle())
          }}
        </template>
      </p>
      <el-radio-group class="fct-dashboard-recent-activities__header_filter" v-model="selectedGroupKey" @change="getData"
                      size="large">
        <el-radio-button v-for="(filter, index) in selectedGroupKeys" :key="index" :label="filter.label"
                         :value="filter.value"/>
      </el-radio-group>
    </div>

    <div v-if="dashBoardReport.data.recentActivities.length" class="fct-dashboard-recent-activities__body">

      <template v-for="(activity, index) in dashBoardReport.data.recentActivities" :key="index">
        <div v-if="checkPermission(activity)"
             class="fct-dashboard-recent-activities__item has-icon">
        <span class="icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M12.8499 9.92029L13.5711 10.3529C13.6156 10.3795 13.6524 10.4172 13.678 10.4623C13.7036 10.5075 13.717 10.5584 13.717 10.6103C13.717 10.6621 13.7036 10.7131 13.678 10.7582C13.6524 10.8033 13.6156 10.841 13.5711 10.8677L8.30911 14.0249C8.21579 14.081 8.10897 14.1106 8.00011 14.1106C7.89124 14.1106 7.78443 14.081 7.69111 14.0249L2.42911 10.8677C2.38462 10.841 2.34779 10.8033 2.32222 10.7582C2.29664 10.7131 2.2832 10.6621 2.2832 10.6103C2.2832 10.5584 2.29664 10.5075 2.32222 10.4623C2.34779 10.4172 2.38462 10.3795 2.42911 10.3529L3.15031 9.92029L8.00011 12.8303L12.8499 9.92029ZM12.8499 7.10029L13.5711 7.53289C13.6156 7.55952 13.6524 7.59723 13.678 7.64235C13.7036 7.68746 13.717 7.73843 13.717 7.79029C13.717 7.84214 13.7036 7.89311 13.678 7.93822C13.6524 7.98334 13.6156 8.02105 13.5711 8.04769L8.00011 11.3903L2.42911 8.04769C2.38462 8.02105 2.34779 7.98334 2.32222 7.93822C2.29664 7.89311 2.2832 7.84214 2.2832 7.79029C2.2832 7.73843 2.29664 7.68746 2.32222 7.64235C2.34779 7.59723 2.38462 7.55952 2.42911 7.53289L3.15031 7.10029L8.00011 10.0103L12.8499 7.10029ZM8.30851 1.58569L13.5711 4.74289C13.6156 4.76952 13.6524 4.80724 13.678 4.85235C13.7036 4.89746 13.717 4.94843 13.717 5.00029C13.717 5.05214 13.7036 5.10311 13.678 5.14822C13.6524 5.19334 13.6156 5.23105 13.5711 5.25769L8.00011 8.60029L2.42911 5.25769C2.38462 5.23105 2.34779 5.19334 2.32222 5.14822C2.29664 5.10311 2.2832 5.05214 2.2832 5.00029C2.2832 4.94843 2.29664 4.89746 2.32222 4.85235C2.34779 4.80724 2.38462 4.76952 2.42911 4.74289L7.69111 1.58569C7.78443 1.52962 7.89124 1.5 8.00011 1.5C8.10897 1.5 8.21579 1.52962 8.30911 1.58569H8.30851ZM8.00011 2.79949L4.33231 5.00029L8.00011 7.20109L11.6679 5.00029L8.00011 2.79949Z"
                fill="#335CFF"/>
            </svg>
        </span>
          <div class="content">
            <h3 class="title">
                <span v-if="activity.module_name !== ''" @click="activityRouteHandler(activity)">
                  {{ activity.title || '--' }}
                </span>
              <template v-else>
                {{ activity.title }}
              </template>
              <span class="date">
                <ConvertedTime :date-time="activity.created_at"/>
              </span>
            </h3>
            <p v-html="activity.content" class="description">
            </p>
              <span class="created_by">
                {{
                  /* translators: %s - user name */
                  translate('By %s', activity.created_by)
                }}
              </span>
            <!--          <span class="content_bottom">-->
            <!--            Stock: +150 units added-->
            <!--          </span>-->
          </div>
        </div>
      </template>

    </div>
    <div v-else class="fct-dashboard-recent-activities__empty">
      <div class="fct-dashboard-recent-activities__empty_text">
        {{ translate("No activity found.") }}
      </div>
    </div>
  </div>
</template>
