<template>
  <div class="fct-advanced-filters">
    <table v-if="items.length" class="w-full">
      <tbody>
      <FilterItem
          :table="table"
          v-for="(item, itemKey) in items"
          :key="itemKey"
          :filterLabels="filterLabels"
          :item="item"
          @removeItem="removeItem(itemKey)"
      />
      </tbody>
    </table>

    <div class="fct-advanced-filter-intro">
      <el-popover
          placement="right"
          width="450"
          popper-class="fct-advanced-filter-popover"
          trigger="click"
          ref="popoverRef"
      >
        <div class="fct-cascader-panel-wrap">
          <CascaderPanel
              :options="filterOptions"
              v-model="newItem"
              @change="selectFilterOption"
          />

          <el-cascader-panel
              style="width: 100%"
              :options="filterOptions"
              v-model="newItem"
              @change="selectFilterOption"
          />
        </div>

        <template #reference>
          <el-button class="el-button--x-small">
            <DynamicIcon name="Plus"/>
            {{ $t('Add') }}
          </el-button>
        </template>
      </el-popover>

      <span class="px-2">{{ $t('Add new properties') }}</span>

      <IconButton
          v-if="items.length === 0"
          @click="()=>{
            table.removeAdvanceFilterGroup(filterIndex)
          }"
          class="float-end"
          size="x-small"
          bg="info"
          tag="button"
          :disabled="filtersLength <= 1"
      >
        <DynamicIcon name="Delete"/>
      </IconButton>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import FilterItem from "@/Bits/Components/TableNew/Components/AdvancedFilter/_FilterItem.vue";
import CascaderPanel from "./_CascaderPanel.vue";

// Props
const props = defineProps({
  table: {
    required: true,
  },
  items: {
    type: Array,
    default: () => []
  },
  filtersLength: {
    type: Number,
  },
  filterOptions: {
    type: Array,
    default: () => []
  },
  filterIndex:{
    type: Number,
    required: true
  },
});

// Refs
const popoverRef = ref(null);
const newItem = ref([]);
const isPopoverVisible = ref(false);

// Computed
const filterLabels = computed(() => {
  const options = {};
  props.filterOptions.forEach(option => {
    if (option.children) {
      option.children.forEach(item => {
        options[`${option.value}-${item.value}`] = {
          provider: option.value,
          ...item
        };
      });
    }
  });
  return options;
});

// Methods
const selectFilterOption = (value) => {
  if (newItem.value.length === 2) {
    const parent = props.filterOptions.find((item) => item.value === value[0]);
    const child = parent?.children.find((item) => item.value === value[1]);

    if (child) {
      props.items.push({
        source: [...newItem.value],
        filter_type: child.filter_type,
        operator: '',
        value: '',
        column: child.column,
        relation: child.relation,
      });

      // Manually force popover to close
      isPopoverVisible.value = false;
      nextTick(() => {
        if (popoverRef.value) {
          popoverRef.value.hide(); // Force close popover
        }
      });

      newItem.value = [];
    }
  }
};

const removeItem = (index) => {
  nextTick(() => {
    props.items.splice(index, 1);
    props.table.applyAdvancedFilter(true);
  });
};
</script>
