<script setup>
import {ref, onMounted} from 'vue';
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import LineChart from "@/Bits/Components/LineChart.vue";
import DateUtil from "@/utils/support/Date";
import dayjs from "dayjs";
import ChartTypeFilter from "@/Models/Reports/ChartTypeFilterModel";
import ChartTab from "@/Bits/Components/ChartTab.vue";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: false,
  },
});

// References
const chartType = ref(
  ChartTypeFilter.getChartType("subscription", "daily_signups_chart") || "line"
);
const chartData = ref([]);
const lineChartRef = ref('');
const zoomIsActive = ref(false);
const loading = ref(true);

const getDailySignups = (params) => {
  loading.value = true;

  Rest.get('reports/daily-signups', {
    params: {
      ...params
    }
  })
  .then((response) => {    
    chartData.value = response.signups;
    
    chartData.value.forEach((item) => {
      item.label = item.trend_date;
    });
  })
  .finally(() => loading.value = false);
};

const toggleChartType = (type) => {
  ChartTypeFilter.onChange("subscription", "daily_signups_chart", type);
  
  if (chartType.value !== type) {
    chartType.value = type;
  }
};

// Initialize chart on mount
onMounted(() => {
  getDailySignups(props.reportFilter.applicableFilters.params);

  props.reportFilter.addListener("daily-signup-chart", (filters) => {
    getDailySignups(filters.params)
  });
});
</script>

<template>
  <div class="fct-daily-signup-chart-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('Daily Signups')"
          title_size="small"
          border_bottom
      >
        <template #action v-if="chartData.length && !loading">
          <div class="fct-btn-group sm gap-2 lg:gap-0">
            <ChartTab
              :activeType="chartType"
              :types="{
                line: 'LineChart',
                bar: 'BarChart',
              }"
              @change="toggleChartType"
            />

            <!-- <IconButton
              tag="button"
              size="small"
              :title="translate('Zoom Chart')"
              @click="zoomIsActive = !zoomIsActive"
              :class="zoomIsActive ? 'primary' : ''"
            >
              <DynamicIcon name="SearchAdd" />
            </IconButton> -->

            <Screenshot v-if="lineChartRef" :targetRef="lineChartRef.chartRef" />
          </div>
        </template>
      </Card.Header>

      <Card.Body>
        <LineChart 
          :chartData="chartData"
          :chart-title="translate('Signups')"
          :chartType="chartType"
          :zoomIsActive="zoomIsActive"
          ref="lineChartRef"
          :loading="loading"
        />
      </Card.Body>
    </Card.Container>
  </div>
</template>
