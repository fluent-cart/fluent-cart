<template>
  <div class="fct-report-card">
    <div class="fct-report-card-header">
      <h3 class="title">Product Sales Volatility</h3>
      <div class="fct-btn-group sm">
        <el-select
          v-model="displayCount"
          class="el-select--x-small !w-[120px] mr-2"
        >
          <el-option label="Top 5" :value="5" />
          <el-option label="Top 10" :value="10" />
          <el-option label="Top 15" :value="15" />
        </el-select>
        <el-select v-model="sortBy" class="el-select--x-small !w-[150px] mr-2">
          <el-option label="Most Volatile" value="volatility" />
          <el-option label="Highest Volume" value="volume" />
        </el-select>
        <Screenshot :targetRef="chartContainerId" />
      </div>
    </div>
    
    <div v-if="hasData" class="fct-report-card-body">
      <!-- Direct container with guaranteed ID -->
      <div :id="chartContainerId" class="volatility-chart-container"></div>
      
      <div class="text-center text-xs text-system-mid dark:text-gray-300 mt-2">
        <span class="inline-block mx-2">
          <span class="inline-block w-3 h-3 bg-blue-400 rounded-sm align-middle mr-1"></span>
          Range (Min-Max)
        </span>
        <span class="inline-block mx-2">
          <span class="inline-block w-3 h-3 bg-green-500 rounded-full align-middle mr-1"></span>
          Low Volatility
        </span>
        <span class="inline-block mx-2">
          <span class="inline-block w-3 h-3 bg-yellow-500 rounded-full align-middle mr-1"></span>
          Medium Volatility
        </span>
        <span class="inline-block mx-2">
          <span class="inline-block w-3 h-3 bg-red-500 rounded-full align-middle mr-1"></span>
          High Volatility
        </span>
        <span class="inline-block mx-2">
          <span class="inline-block w-3 h-3 bg-pink-500 rounded-sm align-middle mr-1"></span>
          Latest Sales
        </span>
      </div>
    </div>
    
    <div v-else class="flex flex-col items-center justify-center py-8 text-center">
      <svg
        class="w-10 h-10 text-gray-300 mb-3"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
        xmlns="http://www.w3.org/2000/svg"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
        ></path>
      </svg>
      <p class="text-gray-500 text-sm">
        Not enough data to calculate volatility. Products need sales data from multiple time periods.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import * as echarts from "echarts";
import Screenshot from "@/Bits/Components/Screenshot.vue";
import Theme from "@/utils/Theme";

// Props definition
const props = defineProps({
  volatilityData: {
    type: Array,
    required: true,
    default: () => []
  }
});

// Generate a unique ID for this chart instance
const chartContainerId = `volatility-chart-${Date.now()}-${Math.floor(Math.random() * 10000)}`;
const displayCount = ref(10);
const sortBy = ref("volatility");

// Chart instance and state tracking
let chartInstance = null;
let initializationAttempts = 0;
const MAX_ATTEMPTS = 10;
let initTimeouts = [];

// Check if we have valid data
const hasData = computed(() => {
  return Array.isArray(props.volatilityData) && props.volatilityData.length > 0;
});

// Sort and limit the data based on selected options
const sortedProducts = computed(() => {
  if (!hasData.value) return [];
  
  let sorted;
  if (sortBy.value === "volatility") {
    sorted = [...props.volatilityData].sort((a, b) => b.volatility - a.volatility);
  } else {
    sorted = [...props.volatilityData].sort((a, b) => b.totalSales - a.totalSales);
  }
  
  return sorted.slice(0, displayCount.value);
});

// Format product name for display
const formatProductName = (name) => {
  if (!name) return "";
  return name.length > 25 ? name.substring(0, 22) + "..." : name;
};

// Colors based on current theme
const chartColors = computed(() => ({
  textColor: Theme.isDark() ? "#E5E7EB" : "#334155",
  subTextColor: Theme.isDark() ? "#9CA3AF" : "#64748B",
  axisLineColor: Theme.isDark() ? "#4B5563" : "#E2E8F0",
  splitLineColor: Theme.isDark() ? "#374151" : "#E2E8F0",
  tooltipBgColor: Theme.isDark() ? "#1F2937" : "#FFFFFF",
  tooltipBorderColor: Theme.isDark() ? "#374151" : "#E2E8F0",
}));

// Get the chart container from the DOM with reliable checks
const getChartContainer = () => {
  const container = document.getElementById(chartContainerId);
  if (!container) return null;
  
  // Ensure container has dimensions
  const rect = container.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) {
    // Force dimensions and try again
    container.style.width = '100%';
    container.style.height = '400px';
    container.style.display = 'block';
    
    // Get updated dimensions
    const newRect = container.getBoundingClientRect();
    if (newRect.width <= 0 || newRect.height <= 0) {
      return null; // Still no dimensions
    }
  }
  
  return container;
};

// Initialize the chart with retry mechanism
const initChart = () => {
  // Check attempt limit
  if (initializationAttempts >= MAX_ATTEMPTS) {
    console.warn(`[Volatility Chart] Gave up after ${MAX_ATTEMPTS} initialization attempts`);
    return;
  }
  
  initializationAttempts++;
  
  try {
    // Get container
    const container = getChartContainer();
    if (!container) {
      scheduleRetry();
      return;
    }
    
    // Dispose any existing instance
    if (chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }
    
    // Force dimensions one more time before initialization
    container.style.width = '100%';
    container.style.height = '400px';
    
    // Initialize chart
    chartInstance = echarts.init(container);
    
    // Update with data
    updateChart();
    
    // Set up resize listener
    window.addEventListener('resize', handleResize);
    
    console.log(`[Volatility Chart] Successfully initialized on attempt ${initializationAttempts}`);
  } catch (error) {
    console.error("[Volatility Chart] Initialization error:", error);
    
    // Clean up and retry
    if (chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }
    
    scheduleRetry();
  }
};

// Schedule a retry with exponential backoff
const scheduleRetry = () => {
  const delay = Math.min(200 * Math.pow(1.5, initializationAttempts), 2000);
  
  const timeoutId = setTimeout(() => {
    initChart();
  }, delay);
  
  initTimeouts.push(timeoutId);
};

// Clear all pending initialization timeouts
const clearInitTimeouts = () => {
  initTimeouts.forEach(id => clearTimeout(id));
  initTimeouts = [];
};

// Update the chart with current data
const updateChart = () => {
  if (!chartInstance || !sortedProducts.value.length) return;
  
  try {
    // Get products sorted for the chart (reverse for bottom-to-top display)
    const products = [...sortedProducts.value].reverse();

    // Calculate the maximum value for better axis scaling
    const maxValue = Math.max(...products.map((p) => p.max)) * 1.1;

    // Define volatility color function
    const getVolatilityColor = (volatility) => {
      if (volatility < 20) return "#22c55e"; // Green - Low
      if (volatility < 40) return "#f59e0b"; // Yellow - Medium
      return "#ef4444"; // Red - High
    };

    // Chart options
    const option = {
      grid: {
        left: "3%",
        right: "7%",
        bottom: "5%",
        top: "5%",
        containLabel: true,
      },
      tooltip: {
        trigger: "axis",
        axisPointer: {
          type: "shadow",
        },
        formatter: function (params) {
          const product = products[params[0].dataIndex];
          return `<div style="font-weight:bold;margin-bottom:5px;font-size:14px">${
            product.name
          }</div>
            <div style="margin-bottom:10px;font-size:12px;color:${
              chartColors.value.subTextColor
            }">Sales Summary</div>
            <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:${getVolatilityColor(
              product.volatility
            )}"></span> <span style="padding-left:5px">Volatility:</span> <span style="float:right;font-weight:bold">${
            product.volatility
          }%</span></div>
            <div><span style="display:inline-block;width:10px;height:10px;border-radius:0;background-color:${
              Theme.colors.report.royal_blue
            }"></span> <span style="padding-left:5px">Min Sales:</span> <span style="float:right;font-weight:bold">${
            product.min
          } units</span></div>
            <div><span style="display:inline-block;width:10px;height:10px;border-radius:0;background-color:${
              Theme.colors.report.royal_blue
            }"></span> <span style="padding-left:5px">Max Sales:</span> <span style="float:right;font-weight:bold">${
            product.max
          } units</span></div>
            <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:${getVolatilityColor(
              product.volatility
            )}"></span> <span style="padding-left:5px">Average:</span> <span style="float:right;font-weight:bold">${
            product.avg
          } units</span></div>
            <div><span style="display:inline-block;width:10px;height:10px;border-radius:0;background-color:${
              Theme.colors.report.hot_pink
            }"></span> <span style="padding-left:5px">Latest:</span> <span style="float:right;font-weight:bold">${
            product.latest
          } units</span></div>
            <div><span style="padding-left:15px">Total:</span> <span style="float:right;font-weight:bold">${
              product.totalSales
            } units</span></div>
            <div><span style="padding-left:15px">Data Points:</span> <span style="float:right;font-weight:bold">${
              product.dataPoints
            } months</span></div>`;
        },
        backgroundColor: chartColors.value.tooltipBgColor,
        borderColor: chartColors.value.tooltipBorderColor,
        textStyle: {
          color: chartColors.value.textColor,
        },
        padding: 10,
      },
      xAxis: {
        type: "value",
        name: "Units Sold",
        nameLocation: "middle",
        nameGap: 30,
        nameTextStyle: {
          color: chartColors.value.textColor,
        },
        max: maxValue,
        axisLabel: {
          color: chartColors.value.textColor,
        },
        axisLine: {
          lineStyle: {
            color: chartColors.value.axisLineColor,
          },
        },
        splitLine: {
          lineStyle: {
            color: chartColors.value.splitLineColor,
          },
        },
      },
      yAxis: {
        type: "category",
        data: products.map((product) => formatProductName(product.name)),
        axisLabel: {
          color: chartColors.value.textColor,
        },
        axisLine: {
          lineStyle: {
            color: chartColors.value.axisLineColor,
          },
        },
      },
      series: [
        // Range bars
        {
          name: "Sales Range",
          type: "custom",
          renderItem: function (params, api) {
            const min = api.value(0);
            const max = api.value(1);
            
            // Calculate coordinates
            const yValue = api.value(2);
            const start = api.coord([min, yValue]);
            const end = api.coord([max, yValue]);
            
            // Bar height
            const height = api.size([0, 1])[1] * 0.4;
            
            // Create shapes
            return {
              type: "rect",
              shape: {
                x: start[0],
                y: start[1] - height / 2,
                width: end[0] - start[0],
                height: height,
              },
              style: {
                fill: Theme.colors.report.royal_blue + "60",
                stroke: Theme.colors.report.royal_blue,
              },
            };
          },
          encode: {
            x: [0, 1],
            y: 2,
          },
          data: products.map((product, index) => [
            product.min,
            product.max,
            index,
          ]),
        },

        // Average point (with volatility indication)
        {
          name: "Average & Volatility",
          type: "scatter",
          symbolSize: function (data) {
            // Scale based on volatility (min 15, max 30)
            return Math.min(Math.max(data[3] * 0.5, 15), 30);
          },
          data: products.map((product, index) => [
            product.avg,
            index,
            product.name,
            product.volatility,
          ]),
          itemStyle: {
            color: function (params) {
              const product = products[params.dataIndex];
              return getVolatilityColor(product.volatility);
            },
          },
        },

        // Latest sales point
        {
          name: "Latest Sales",
          type: "scatter",
          symbol: "rect",
          symbolSize: 12,
          data: products.map((product, index) => [
            product.latest,
            index,
            product.name,
          ]),
          itemStyle: {
            color: Theme.colors.report.hot_pink,
          },
        },

        // Volatility text labels
        {
          name: "Volatility Label",
          type: "custom",
          renderItem: function (params, api) {
            const product = products[params.dataIndex];
            const yValue = api.value(1);
            const volatility = product.volatility;

            // Get position at the right side of the chart
            const point = api.coord([maxValue * 0.98, yValue]);

            return {
              type: "text",
              style: {
                text: volatility + "%",
                fill: getVolatilityColor(volatility),
                stroke: Theme.isDark() ? "#000" : "#fff",
                lineWidth: 0.5,
                fontWeight: "bold",
                fontSize: 12,
                textAlign: "right",
              },
              position: point,
            };
          },
          data: products.map((product, index) => [
            maxValue,
            index,
            product.volatility,
          ]),
        },
      ],
    };

    // Apply the options to the chart
    chartInstance.setOption(option);
    
    // Force a resize after rendering
    setTimeout(() => {
      if (chartInstance) {
        chartInstance.resize();
      }
    }, 200);
  } catch (error) {
    console.error("[Volatility Chart] Error updating chart:", error);
  }
};

// Handle window resize
const handleResize = () => {
  if (chartInstance) {
    try {
      chartInstance.resize();
    } catch (error) {
      console.error("[Volatility Chart] Error during resize:", error);
    }
  }
};

// Set up a multi-try initialization strategy with increasing delays
const setupMultiTryInitialization = () => {
  // Clear any existing timeouts
  clearInitTimeouts();
  
  // Reset attempt counter
  initializationAttempts = 0;
  
  // Initial immediate attempt
  initChart();
  
  // Then scheduled attempts with increasing delays
  const delays = [300, 600, 1000, 1500, 2000, 3000];
  delays.forEach(delay => {
    const timeoutId = setTimeout(() => {
      // Only try again if not yet successful
      if (!chartInstance) {
        initChart();
      }
    }, delay);
    
    initTimeouts.push(timeoutId);
  });
};

// Watch for changes that should trigger chart updates
watch([displayCount, sortBy], () => {
  // Only update if chart exists
  if (chartInstance) {
    updateChart();
  }
});

// Watch for data changes
watch(() => props.volatilityData, () => {
  // Completely reinitialize on data change
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
  
  // Start initialization process
  setupMultiTryInitialization();
}, { deep: true });

// Watch for theme changes
watch(
  () => Theme.isDark(),
  () => {
    // Reinitialize on theme change
    if (chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }
    
    setupMultiTryInitialization();
  }
);

// Component lifecycle hooks
onMounted(() => {
  // Wait for DOM to be fully rendered then initialize
  window.addEventListener('load', () => {
    setupMultiTryInitialization();
  });
  
  // Also try when tab becomes visible
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !chartInstance) {
      setupMultiTryInitialization();
    }
  });
  
  // Also try immediately
  setupMultiTryInitialization();
});

onUnmounted(() => {
  // Clean up all resources
  clearInitTimeouts();
  
  window.removeEventListener('resize', handleResize);
  
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
});
</script>

<style scoped>
.volatility-chart-container {
  width: 100% !important;
  height: 400px !important;
  min-height: 400px !important;
  display: block !important;
  position: relative !important;
  background-color: transparent;
  margin: 0 auto;
}
</style>
