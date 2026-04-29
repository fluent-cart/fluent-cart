<template>
  <div class="fct-applied-filter-popover-wrap">
    <el-popover
        v-if="filterItem.value.length > 1"
        placement="bottom"
        trigger="click"
        :width="300"
    >
      <template #reference>
        <div :class="`fct-applied-filter-value-inner-label ${clearable ? 'is-clearable' : ''}`">
          <div class="truncate max-w-[300px]">
            {{ getCompactLabel(filterItem.value) }}
          </div>
          <span class="mr-1 inline-block">{{getCompactLabelCount(filterItem.value)}}</span>
          <DynamicIcon name="ChevronDown" class="fct-applied-filter-expand-icon"/>
          <DynamicIcon
              v-if="clearable"
              @click="clearAll(filterItemKey)"
              name="CircleClose"
              class="fct-applied-filter-clear-all"
          />
        </div>
      </template>

      <ul class="fct-applied-filter-popover-content">
        <li v-for="item in filterItem.value.slice(1)" :key="item.id">
          <span> {{item.label}}</span>
          <RemoveButton
              :filterKey="filterItemKey"
              :itemId="item.id"
              @remove="handleRemoveFilter"
          />
        </li>
      </ul>
    </el-popover>

    <div v-else class="fct-applied-filter-value-inner-label">
      <div class="truncate max-w-[300px]">{{ filterItem.value[0].label }}</div>

      <RemoveButton
          v-if="filterItem.value.length === 1"
          :filterKey="filterItemKey"
          :itemId="filterItem.value[0].id"
          @remove="handleRemoveFilter"
      />
    </div>
  </div>
</template>

<script setup>
import RemoveButton from "@/Bits/Components/FilterDropdown/parts/RemoveButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  filterState: {
    type: [Object]
  },
  filterItem: {
    type: Object,
    required: true
  },
  filterItemKey: {
    type: String,
    required: true
  },
  clearable: {
    type: Boolean,
    default: false
  }
});

const filter = props.filterState;

const getCompactLabel = (items) => {
  if (!Array.isArray(items) || !items.length) return '';
  const firstLabel = items[0]?.label || '';
  return `${firstLabel}`;
}
const getCompactLabelCount = (items) => {
  if (!Array.isArray(items) || !items.length) return '';
  return items.length > 1 ? ` +${items.length - 1}` : '';
}

const handleRemoveFilter = ({ filterKey, itemId }) => {
  filter.removeFilter(filterKey, itemId);
}

const clearAll = (key) => {
  filter.removeFilter(key);
}
</script>
