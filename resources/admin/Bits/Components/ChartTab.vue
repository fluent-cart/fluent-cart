<script setup>
import * as Fluid from "@/Bits/Components/FluidTab/FluidTab.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  activeType: String,
  types: Object,
});
const emit = defineEmits(["change"]);

const toggleChartType = (type) => {
  emit("change", type);
};
</script>

<template>
  <div class="fct-chart-tab">
    <Fluid.Tab hasIcon small>
      <Fluid.Item
          v-for="(icon, type) in types"
          :key="type"
          :class="{ active: activeType === type }"
          @click="toggleChartType(type)"
      >
        <DynamicIcon :name="icon" />
      </Fluid.Item>
    </Fluid.Tab>
  </div>
</template>
