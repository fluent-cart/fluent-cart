<template>
  <div class="filters-wrap">
    <div class="fct-btn-group">
      <div ref="filter_popover_wrapper">
        <el-popover :visible="filterVisible" placement="bottom-start" width="450"
                    popper-class="filter-popover fluent-cart-admin-pages">
          <div class="filter-popover-item">
            <filter-item
                v-if="filterVisible"
                @updateFilters="applyFilters"
                :table="table"
            />
          </div>
          <template #reference>
              <span>
                <el-tooltip
                    effect="dark"
                    :content="$t('Filter')"
                    placement="top"
                    popper-class="fct-tooltip"
                >
                  <IconButton tag="button"
                              @click="filterVisible = !filterVisible">
                      <el-badge :hidden="!filtersCount" :value="filtersCount" type="primary"
                                class="filter-count-badge"></el-badge>
                      <DynamicIcon name="FilterIcon"/>
                    </IconButton>
                </el-tooltip>
              </span>
          </template>
        </el-popover>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ArrowDown, Reading, Filter, Sort} from '@element-plus/icons-vue'
import FilterItem from '../../FilterItem.vue';
import {computed, getCurrentInstance, onMounted, ref} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {useFilterPopoverOutsideClickMixin} from '@/mixin/filterPopoverOutsideClickMixin';

defineOptions({
  name: 'Filters'
})

const props = defineProps({
  table: Object,
})
const selfRef = getCurrentInstance().ctx;

const emit = defineEmits(['update:columns', 'sortingUpdated'])

const filtersCount = computed(() => {
  return Object.keys(props.table.filters.applied).length;
})

const filterVisible = ref(false)
const filter_popover_wrapper = ref();
const filterPopoverOutsideClickMixin = useFilterPopoverOutsideClickMixin();

onMounted(() => {
  filterPopoverOutsideClickMixin.handle(filter_popover_wrapper.value, () => {
    filterVisible.value = false;
  })
})

const applyFilters = (filterValues) => {
  filterVisible.value = false;
  props.table.applyFilter(filterValues);
}
</script>
  
