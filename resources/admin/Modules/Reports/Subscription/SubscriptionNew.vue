<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import {ref} from "vue";
import {formatNumber} from "@/Modules/Reports/Utils/formatNumber";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SubscriptionNewOverview from "@/Modules/Reports/Subscription/SubscriptionNewOverview.vue";
import DailySignupChart from "@/Modules/Reports/Subscription/DailySignupChart.vue";
import SubscriptionMRRTrend from "@/Modules/Reports/Subscription/SubscriptionMRRTrend.vue";
import dayjs from "dayjs";
import ChurnAnalytics from "@/Modules/Reports/Subscription/ChurnAnalytics.vue";

</script>

<template>
  <div class="fct-subscription-report-overview-page">
    <div class="page-heading-wrap flex items-center justify-between">
      <div>
        <h1 class="page-title">
          {{ translate('Subscription Report Overview') }}
        </h1>

        <span>
          {{translate('Showing data of current month only')}}
          {{ dayjs().startOf("month").format("MMM, YYYY") }}
      </span>
      </div>
    </div>


    <div class="fct-default-report-page">
      <SubscriptionNewOverview class="mb-5"/>

      <el-row>
        <el-col :lg="24">
          <DailySignupChart/>
        </el-col>

        <el-col :lg="24">
          <SubscriptionMRRTrend/>
        </el-col>

        <el-col :lg="24">
          <ChurnAnalytics />
        </el-col>
      </el-row>
    </div>

  </div>
</template>
