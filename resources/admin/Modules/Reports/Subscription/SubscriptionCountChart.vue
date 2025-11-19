<script setup>
import {onMounted, ref} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
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
  Rest.get('subscription-new/get-subscriptions-count-trend', {
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

// Initialize chart on mount
onMounted(() => {
  handleDateRangeChange();
  if (props.reportFilter) {
    props.reportFilter.addListener("sub-count-chart", () => {
      handleDateRangeChange();
    });
  }
});
</script>

<template>
  <Card.Container>
    <Card.Header
        :title="translate('Subscriptions Count Trend')"
    >
    </Card.Header>
    <Card.Body>
      <LineChart :chartData="chartData" :chart-title="translate('Count')"/>
    </Card.Body>
  </Card.Container>
</template>

<style scoped>

</style>
