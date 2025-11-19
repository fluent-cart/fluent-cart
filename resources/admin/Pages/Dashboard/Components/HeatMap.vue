<script setup>
import { ref, onMounted, nextTick, watch, computed, onUnmounted } from "vue";
import * as echarts from "echarts";
import * as Card from "@/Bits/Components/Card/Card.js";
import { ElDialog } from "element-plus";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Theme from "@/utils/Theme";
import translate from "@/utils/translator/Translator";
import Asset from "@/utils/support/Asset";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import ChartTab from "@/Bits/Components/ChartTab.vue";

const props = defineProps({
  data: {
    type: Array,
    required: true,
  },
});

const colors = Theme.colors;

const chartRef = ref(null);
const modalChartRef = ref(null);
const isModalOpen = ref(false);
const chartType = ref('world');

// New refs for zoom tracking
const currentZoomStart = ref(92); // Changed from 0 to 88 for 12% zoom (100-88=12)
const currentZoomEnd = ref(100);
const showYAxisLabels = ref(false);

let chartInstance = null;
let modalChartInstance = null;

let maxValue = 50;
const zoomInterval = 0.5;
const maxZoom = 6;
const minZoom = 1.2; // Reverted back to 1.2 for world map
const zoomLevel = ref(1.2); // Reverted back to 1.2 for world map
const mapRoam = ref();

const baseColor = colors.blue;

// Computed property to determine if labels should be shown
const shouldShowLabels = computed(() => {
  const zoomRange = currentZoomEnd.value - currentZoomStart.value;
  const itemCount = props.data ? props.data.length : 0;

  // Show labels when zoomed in (showing less than 60% of data) or when data is small
  return zoomRange < 60 || itemCount < 15;
});

const hexToRgb = (hex) => {
  hex = hex.replace(/^#/, '');
  const r = parseInt(hex.substring(0, 2), 16);
  const g = parseInt(hex.substring(2, 4), 16);
  const b = parseInt(hex.substring(4, 6), 16);
  return [r, g, b];
}

const getChartE = () => {
  if (Theme.isDark()) {
    return colors.gray['50'];
  } else {
    return colors.gray['600'];
  }
}

const baseColorRgb = hexToRgb(baseColor['500']);

const loadWorldMap = async () => {
  const url = Asset.getUrl("world.geo.json");
  const response = await fetch(url);
  const worldGeo = await response.json();
  echarts.registerMap("world", worldGeo);
};

const getColor = (value) => {
  if (!value || isNaN(value)) {
    return `rgba(${baseColorRgb.join(",")}, 0)`;
  }
  const intensity = Math.log(value + 1) / Math.log(maxValue + 1);
  return `rgba(${baseColorRgb.join(",")}, ${intensity})`;
};

const toggleChartType = (type) => {
  chartType.value = type;
  updateChart();
  if (chartInstance) {
    chartInstance.clear();
    chartInstance.setOption(getChartOption());

    // Setup dataZoom listener for bar chart
    if (type === 'bar') {
      setupBarChartListeners();
    }
  }
  if (modalChartInstance && isModalOpen.value) {
    modalChartInstance.clear();
    modalChartInstance.setOption(getChartOption());

    if (type === 'bar') {
      setupModalBarChartListeners();
    }
  }
}

// Setup dataZoom event listeners
const setupBarChartListeners = () => {
  if (!chartInstance) return;

  chartInstance.off('dataZoom'); // Remove existing listeners
  chartInstance.on('dataZoom', function(event) {
    if (event.batch && event.batch[0]) {
      currentZoomStart.value = event.batch[0].start || 0;
      currentZoomEnd.value = event.batch[0].end || 100;
    } else {
      currentZoomStart.value = event.start || 0;
      currentZoomEnd.value = event.end || 100;
    }

    // Update chart with new label visibility
    setTimeout(() => {
      updateChart();
    }, 100);
  });
};

const setupModalBarChartListeners = () => {
  if (!modalChartInstance) return;

  modalChartInstance.off('dataZoom');
  modalChartInstance.on('dataZoom', function(event) {
    if (event.batch && event.batch[0]) {
      currentZoomStart.value = event.batch[0].start || 0;
      currentZoomEnd.value = event.batch[0].end || 100;
    } else {
      currentZoomStart.value = event.start || 0;
      currentZoomEnd.value = event.end || 100;
    }

    setTimeout(() => {
      if (modalChartInstance) {
        modalChartInstance.setOption(getChartOption());
      }
    }, 100);
  });
};

const coloredData = computed(() =>
    (props.data || []).map((item) => ({
      ...item,
      value: item.value || 0,
      itemStyle: { color: getColor(item.value || 0) },
      emphasis: {
        itemStyle: { shadowBlur: 10, shadowColor: "rgba(0, 0, 0, 0.5)" },
      },
    }))
);

const getMapOption = () => ({
  backgroundColor: Theme.isDark() ? colors.dark["700"] : colors.gray["25"],
  tooltip: {
    trigger: "item",
    formatter: (params) =>
        /* translators: %1$s - country name, %2$s - line break, %3$s - number of orders */
        translate("%1$s%2$s Orders: %3$s", params.name, "<br>", params.value || 0),
    borderRadius: 8,
    backgroundColor: Theme.isDark() ? "#38485a" : "#ffffff",
    borderColor: Theme.isDark() ? "#4a5a6b" : "#c0c4ca",
    borderWidth: 1,
    textStyle: {
      color: Theme.isDark() ? "#ffffff" : "#565865",
    },
  },
  visualMap: {
    min: 0,
    max: maxValue,
    left: isModalOpen.value ? "5%" : "center",
    bottom: isModalOpen.value ? "center" : "4%",
    text: [translate("High"), translate("Low")],
    orient: isModalOpen.value ? "vertical" : "horizontal",
    calculable: true,
    inRange: {
      color: [baseColor["100"], baseColor["500"]],
    },
    textStyle: {
      color: colors.gray["800"],
    },
  },
  series: [
    {
      name: "Orders",
      type: "map",
      map: "world",
      roam: mapRoam.value,
      zoom: zoomLevel.value,
      label: {
        show: false,
        emphasis: {
          show: true,
          color: colors.gray["1000"],
          fontWeight: "bold",
          textShadowColor: colors.gray["50"],
          textShadowBlur: 3,
          textShadowOffsetX: 0,
          textShadowOffsetY: 0,
        },
      },
      emphasis: {
        itemStyle: {
          areaColor: baseColor["300"],
          shadowBlur: 10,
          shadowColor: colors.gray["400"],
        },
      },
      itemStyle: {
        borderColor: colors.gray["200"],
        borderWidth: 0.5,
      },
      data: coloredData.value,
    },
  ],
});

const yAxisLabelInterval = computed(() => {
  const dataCount = props.data.length;
  const zoomRange = currentZoomEnd.value - currentZoomStart.value;
  const visibleItems = Math.ceil((dataCount * zoomRange) / 100);
  let labelInterval = 0;
  
  if (visibleItems > 100) labelInterval = 9;
  else if (visibleItems > 50) labelInterval = 4;
  else if (visibleItems > 30) labelInterval = 2;
  else if (visibleItems > 20) labelInterval = 1;

  return labelInterval;
});

const getBarOption = () => {
  const labelsVisible = shouldShowLabels.value;

  return {
    backgroundColor: Theme.isDark() ? colors.dark["700"] : colors.gray["25"],
    // toolbox: {
    //   feature: {
    //     dataZoom: {
    //       yAxisIndex: false
    //     },
    //     saveAsImage: {
    //       pixelRatio: 2
    //     }
    //   }
    // },
    tooltip: {
      trigger: 'axis',
      axisPointer: {
        type: 'shadow'
      },
      formatter: function(params) {
        const data = params[0];
        return `${data.name}<br/>${translate('Orders')}: ${data.value || 0}`;
      },
      backgroundColor: Theme.isDark() ? "#38485a" : '#ffffff',
      textStyle: { color: Theme.isDark() ? colors.gray["25"] : colors.system["mid"] },
      borderColor: Theme.isDark() ? "#4a5a6b" : "#c0c4ca",
      borderWidth: 1,
    },
    dataZoom: [
      {
        type: 'inside',
        yAxisIndex: 0,
        start: currentZoomStart.value,
        end: currentZoomEnd.value,
      },
      {
        type: 'slider',
        yAxisIndex: 0,
        start: currentZoomStart.value,
        end: currentZoomEnd.value,
        width: 25,
        top: 10,
        right: 10,
        textStyle: {
          color: getChartE()
        },
        brushSelect: false,
        showDetail: false,
        disabled: true
      }
    ],
    grid: {
      left: labelsVisible ? "10px" : "0%", // Adjust grid based on label visibility
      right: "15%",
      top: "10%",
      bottom: "15%",
      containLabel: true,
    },
    yAxis: {
      type: "category",
      axisTick: {
        show: labelsVisible
      },
      splitLine: {
        show: false
      },
      axisLine: {
        show: labelsVisible,
        lineStyle: {
          color: getChartE()
        }
      },
      axisLabel: {
        show: labelsVisible,
        interval: yAxisLabelInterval.value,
        color: getChartE(),
        fontSize: 11
      },
      data: props.data.map((item) => item.name),
    },
    xAxis: {
      type: "value",
      axisLabel: {
        show: true,
        color: getChartE(),
        formatter: function(value) {
          return value.toLocaleString();
        }
      },
      splitLine: {
        show: true,
        lineStyle: {
          color: Theme.isDark() ? colors.gray["600"] : colors.gray["200"],
        }
      },
    },
    series: [
      {
        type: "bar",
        large: true,
        itemStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
            { offset: 0, color: baseColor["400"] },
            { offset: 1, color: baseColor["600"] }
          ]),
          borderRadius: [0, 4, 4, 0]
        },
        emphasis: {
          itemStyle: {
            color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
              { offset: 0, color: baseColor["500"] },
              { offset: 1, color: baseColor["700"] }
            ])
          }
        },
        data: props.data.map((item) => item.value),
        label: {
          show: labelsVisible,
          formatter: '{c}',
          position: 'right',
          color: getChartE(),
          fontSize: 10
        },
      },
    ],
  };
}

const getChartOption = () => (chartType.value === 'bar' ? getBarOption() : getMapOption());

const initChart = async () => {
  await loadWorldMap();
  if (chartRef.value) {
    chartInstance = echarts.init(chartRef.value);
    chartInstance.setOption(getChartOption());

    // Setup listeners based on chart type
    if (chartType.value === 'bar') {
      setupBarChartListeners();
    }

    chartInstance.on("georoam", () => {
      const option = chartInstance.getOption();
      const newZoom = option.series[0].zoom;
      if (newZoom !== zoomLevel.value) {
        if (newZoom < minZoom || newZoom > maxZoom) {
          const clamped = Math.max(minZoom, Math.min(maxZoom, newZoom));
          chartInstance.setOption(
              {
                series: [{ zoom: clamped }],
              },
              false
          );
          zoomLevel.value = clamped;
        } else {
          zoomLevel.value = newZoom;
        }
      }
    });
  }
};

const updateChart = () => {
  if (props.data && props.data.length > 0) {
    maxValue = Math.max(...props.data.map((item) => item.value || 0));
  } else {
    maxValue = 1;
  }

  if (chartInstance) {
    chartInstance.setOption(getChartOption(), { notMerge: true });
  }
};

const zoomIn = () => {
  zoomLevel.value = Math.min(zoomLevel.value + zoomInterval, maxZoom);
  mapRoam.value = true;
  chartInstance.setOption({ series: [{ zoom: zoomLevel.value, roam: mapRoam.value }] });

};

const zoomOut = () => {
  zoomLevel.value = Math.max(zoomLevel.value - zoomInterval, minZoom);
  mapRoam.value = true;
  chartInstance.setOption({ series: [{ zoom: zoomLevel.value, roam: mapRoam.value }] });
};

const resetChart = () => {
  if (chartType.value === 'bar') {
    // Reset dataZoom for bar chart to 12% zoom (88-100)
    currentZoomStart.value = 92;
    currentZoomEnd.value = 100;
    if (chartInstance) {
      chartInstance.dispatchAction({
        type: 'dataZoom',
        start: 92,
        end: 100
      });
    }
  } else {
    // Reset zoom for world map
    zoomLevel.value = minZoom;
    mapRoam.value = 'move';
    if (chartInstance) {
      chartInstance.clear();
      chartInstance.setOption(getChartOption());
    }
  }
};

const openModal = async () => {
  isModalOpen.value = true;
  await nextTick();
  if (!modalChartInstance) {
    modalChartInstance = echarts.init(modalChartRef.value);
  } else {
    modalChartInstance.clear();
  }
  modalChartInstance.setOption(getChartOption());

  if (chartType.value === 'bar') {
    setupModalBarChartListeners();
  }
};

const onThemeChanged = (event) => {
  getChartE();
  if (chartInstance) {
    chartInstance.setOption(getChartOption());
  }
};

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", onThemeChanged);
  mapRoam.value = 'move';
  nextTick(initChart);
});

onUnmounted(() => {
  window.removeEventListener("onFluentCartThemeChange", onThemeChanged);
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
  if (modalChartInstance) {
    modalChartInstance.dispose();
    modalChartInstance = null;
  }
});

watch(
    () => props.data,
    () => updateChart(),
    { deep: true }
);

// Watch for label visibility changes
watch(shouldShowLabels, () => {
  if (chartType.value === 'bar') {
    nextTick(() => {
      updateChart();
    });
  }
});
</script>

<template>
  <div class="fct-gross-sale-vs-net-revenue-wrap">
    <Card.Container id="chartContainer">
      <Card.Header :title="$t('Order Heat Map')" border_bottom>
        <template #action>
          <div class="fct-btn-group sm">
            <ChartTab
                :activeType="chartType"
                :types="{
                  world: 'Globe',
                  bar: 'BarChart'
                }"
                @change="toggleChartType"
            />

            <Screenshot :targetRef="chartRef" size="small" />
          </div>
        </template>
      </Card.Header>
      <Card.Body class="p-0 pt-0 relative">
        <!-- World Map Controls -->
        <div v-if="chartType === 'world'" class="fct-world-map-action-btns absolute top-5 right-5 flex flex-col gap-1 z-10">
          <icon-button tag="button" size="small" @click="zoomIn">
            <DynamicIcon name="Plus" />
          </icon-button>
          <icon-button tag="button" size="small" @click="zoomOut">
            <DynamicIcon name="Minus" />
          </icon-button>
          <icon-button tag="button" size="small" @click="resetChart">
            <DynamicIcon name="Reload" />
          </icon-button>
          <icon-button tag="button" size="small lg:hidden" @click="openModal">
            <DynamicIcon name="Eye" />
          </icon-button>
        </div>

        <!-- Bar Chart Controls and Info -->
        <div v-if="chartType === 'bar'" class="fct-world-map-action-btns absolute top-[10px] right-[40px] flex flex-col gap-1 z-10">
            <icon-button tag="button" size="small" @click="resetChart">
              <DynamicIcon name="Reload" />
            </icon-button>
          </div>

        <div class="fct-chart-wrap rounded-[0px] bg-transparent" ref="chartRef"></div>
      </Card.Body>
    </Card.Container>

    <el-dialog v-model="isModalOpen" :title="$t('Order Heat Map')" class="country-heat-map-dialog">
      <div class="fct-chart-wrap" ref="modalChartRef"></div>
    </el-dialog>
  </div>
</template>

<style scoped>
.fct-chart-wrap {
  width: 100%;
  height: 450px;
  border-radius: 12px;
  overflow: hidden;
  background: #ffffff;
}

.country-heat-map-dialog .fct-chart-wrap {
  height: 600px;
}

.fct-bar-chart-info {
  border: 1px solid rgba(0, 0, 0, 0.1);
}
</style>
