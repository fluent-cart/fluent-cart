<script setup>
import {computed, nextTick, onMounted, ref, watch} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";
import LineChart from "@/Bits/Components/LineChart.vue";
import DateUtil from "@/utils/support/Date";
import dayjs from "dayjs";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: false,
  },
});

const startDate = ref(null);
const endDate = ref(null);
// References
const chartData = ref([]);

const handleDateRangeChange = () => {

  if (props.reportFilter) {
    const appliedFilters = props.reportFilter.applicableFilters.params;
    startDate.value = appliedFilters.startDate;
    endDate.value = appliedFilters.endDate;
  } else {
    startDate.value = DateUtil.withTimezone(dayjs().startOf("month").format("YYYY-MM-DD 00:00:00"));
    endDate.value = DateUtil.withTimezone(dayjs().endOf("month").format("YYYY-MM-DD 00:00:00"));
  }
  fetchChurnRates()
}


const fetchChurnRates = () => {
  Rest.get('subscription-new/get-churn-rates', {
    startDate: startDate.value,
    endDate: endDate.value,
  })
      .then((response) => {
        chartData.value = response.data;

        chartData.value.forEach((item) => {
          item.label = item.trend_date;
        });

      })
      .catch((error) => {
        console.error(error, "error");
      })
      .finally(() => {
      });
}

// Watch for data or chart type changes
watch([() => chartData.value, Theme.isDark()], () => {
}, {deep: true});

// Initialize chart on mount
onMounted(() => {
  handleDateRangeChange();
  if (props.reportFilter) {
    props.reportFilter.addListener("churn-rates-chart", () => {
      handleDateRangeChange();
    });
  }
});
</script>

<template>
  <Card.Container>
    <Card.Header
        :title="translate('Churn Rates Over Time')"
    >
    </Card.Header>
    <Card.Body>
      <LineChart :chartData="chartData" :chart-title="translate('Rate')" :formatValuePercentage="true"/>
    </Card.Body>
  </Card.Container>
</template>

<style scoped>

</style>
