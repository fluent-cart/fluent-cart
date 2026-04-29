<script setup>
import {ref, onMounted} from 'vue';
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
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
  getDailyMRRData()
}

const getDailyMRRData = () => {
  Rest.get('subscription-new/get-mrr-trend', {
    startDate: startDate.value,
    endDate: endDate.value,
  })
      .then((response) => {
        chartData.value = response.data;

        chartData.value.forEach((item) => {
          item.label = item.a_time_period;
          item.value = item.mrr_value * 100;
        });
      })
      .catch((error) => {
        console.error(error, "error");
      })
      .finally(() => {
      });
};

// Initialize chart on mount
onMounted(() => {
  handleDateRangeChange();
  if (props.reportFilter) {
    props.reportFilter.addListener("daily-mrr-chart", () => {
      handleDateRangeChange();
    });
  }
});
</script>

<template>
  <div class="fct-daily-signup-chart-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('New MRR Trend')"
          border_bottom
      >
      </Card.Header>
      <Card.Body>
        <LineChart :chartData="chartData" :chart-title="translate('MRR Trend')" :amountFormat="true"/>
      </Card.Body>
    </Card.Container>
  </div>
</template>
