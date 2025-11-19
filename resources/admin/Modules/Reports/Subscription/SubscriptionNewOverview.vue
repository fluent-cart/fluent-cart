<script setup>
import { onMounted, ref, computed } from "vue";
import Rest from "@/utils/http/Rest";
import { formatNumber } from "../Utils/formatNumber";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import WithCurrency from "../Utils/WithCurrency.js";

const subscriptionOverview = ref([]);
const loading = ref(false);

const fetchOverview = () => {
  // todo: fetch subscription overview data
  loading.value = true;

  Rest.get("reports/get-subscription-overview")
    .then((response) => {
      subscriptionOverview.value = response.overviews;
    })
    .catch((error) => {
      console.error(error, "error");
    })
    .finally(() => {
      loading.value = false;
    });
};

const currencySign = computed(() => {
  return reportFilter?.currentCurrencySign || "";
});

onMounted(() => {
  fetchOverview();
});
</script>

<template>
  <el-row :gutter="16" class="gap-y-4">
    <template v-if="loading">
      <el-col :lg="8" :md="12" v-for="index in 6" :key="index">
        <div class="fct-report-card fct-report-summary-card h-[100px]">
          <div class="fct-report-card-body flex h-full items-center">
            <div class="fct-report-sold-product w-full">
              <div class="fct-report-result">
                <div class="fct-report-result-content">
                  <div class="title flex items-center gap-1">
                    <el-skeleton animated :rows="1" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </el-col>
    </template>
    <template v-if="!loading && Object.values(subscriptionOverview).length">
      <el-col
        :lg="8"
        :md="12"
        v-for="(overview, index) in Object.values(subscriptionOverview)"
        :key="index"
      >
        <div class="fct-report-card fct-report-summary-card h-[100px]">
          <div class="fct-report-card-body flex h-full items-center">
            <div class="fct-report-sold-product w-full">
              <div class="fct-report-result">
                <div class="fct-report-result-content">
                  <div class="title flex items-center gap-1">
                    {{ overview.title }}
                  </div>
                  <div class="value">
                    <span v-if="overview.isCurrency">
                      <WithCurrency :value="overview.value" />
                    </span>
                    <span v-else>{{ formatNumber(overview.value) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </el-col>
    </template>
  </el-row>
</template>

<style scoped></style>
