<template>
  <div class="filters-wrap">
    <div class="fct-btn-group">
      <div ref="sorting_popover_wrapper">
        <fct-popover :visible="sortingVisible" placement="bottom-start" width="240" popper-class="filter-popover">
          <div class="filter-popover-item">
            <h3 class="filter-popover-title">{{ $t('Sort By') }}</h3>
            <div :style="`${table.sortableColumns.length > 8 ? 'max-height: 125px; overflow: auto;' : ''}`">
              <fct-choice type="radio" @change="storeColumns()" class="fct-radios-blocks" v-model="table.sort.sortBy"
                          :options="table.sortableColumns"/>
            </div>
            <hr/>
            <fct-choice type="radio" button size="small" v-model="table.sort.sortType"
                        :options="[{value: 'ASC', label: $t('Ascending')}, {value: 'DESC', label: $t('Descending')}]"/>
            <div class="filter-btn-wrap">
              <fct-button @click="applySort" type="primary">
                {{ $t('Apply') }}
              </fct-button>
            </div>
          </div>
          <template #reference>
              <span>
                <fct-tooltip
                    :content="$t('Sort')"
                    placement="top"
                >
                  <IconButton tag="button"
                              @click="sortingVisible = !sortingVisible">
                    <DynamicIcon name="ArrowUpDown"/>
                  </IconButton>
                </fct-tooltip>
              </span>
          </template>
        </fct-popover>
      </div>
    </div>
  </div>
</template>

<script setup>
import { getCurrentInstance, onMounted, ref} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {useFilterPopoverOutsideClickMixin} from '@/mixin/filterPopoverOutsideClickMixin';

defineOptions({
  name: 'Sorting'
})

const props = defineProps({
  table: Object,
})
const selfRef = getCurrentInstance().ctx;

const emit = defineEmits(['update:columns', 'sortingUpdated'])

const selectedColumns = ref(props.table.columns)
const sortingVisible = ref(false)

const sorting_popover_wrapper = ref();
const filterPopoverOutsideClickMixin = useFilterPopoverOutsideClickMixin();

onMounted(() => {

  selectedColumns.value = selfRef.Storage().get(props.table.tableName+'_columns');

  filterPopoverOutsideClickMixin.handle(sorting_popover_wrapper.value, () => {
    sortingVisible.value = false;
  })
})

const storeColumns = () => {
  emit('update:columns', selectedColumns.value)
  selfRef.Storage().set(props.table.tableName+'_columns', selectedColumns.value);
}

const applySort = () => {
  sortingVisible.value = false;
  props.table.applyLegacySort()
}
</script>
  
