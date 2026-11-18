<template>
  <div class="fct-add-filter-wrapper">
    <div class="filter-select-option-wrap">
      <h3 class="filter-popover-title">{{ $t('Add Filter') }}</h3>
      <fct-select
          @mousedown.prevent="handleSelectClick"
          v-model="selectedFilters"
          multiple
          :placeholder="$t('Select Filters')"
          :options="Object.keys(filterOptions).map(filterKey => ({label: filterOptions[filterKey].label, value: filterKey}))"
          @change="onSelectedFiltersChanged"
          @remove-tag="onSelectedFilterRemoved"
      />
    </div>

    <table style="width: 100%; margin-top: 10px;">
      <tr v-for="(filterItem, filterIndex) in filterArray" :key="filterIndex">
        <td style="width: 198px;">
                    <span class="filter-item-key" v-if="filterItem.item_key">
                        {{ filterOptions[filterItem.item_key].label }}:
                    </span>
          <template v-else>
            <fct-select @change="maybeChangeOperator(filterItem)" v-model="filterItem.item_key"
                        :placeholder="$t('Select Filter Group')"
                        :options="Object.keys(filterOptions).map(filterKey => ({label: filterOptions[filterKey].label, value: filterKey}))"/>
          </template>
        </td>
        <td>
          <template v-if="filterItem.item_key">
            <fct-select
                size="small"
                v-if="filterOptions[filterItem.item_key]?.type == 'options'"
                v-model="filterItem.item_values"
                :multiple="true"
                :placeholder="$t('Select Options')"
                :options="toOptions(filterOptions[filterItem.item_key]?.options)"/>
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'text'">
              <fct-select class="fct_inline_operator" v-model="filterItem.operator" :placeholder="$t('Select')"
                          :options="toOptions(textOptions)"/>
              <fct-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="text"/>
            </div>
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'number'">
              <fct-select class="fct_inline_operator" v-model="filterItem.operator"
                          :options="toOptions(number_options)"/>
              <fct-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="number" :controls="false" :min="1"/>
            </div>
          </template>
        </td>
      </tr>
    </table>
    <div class="filter-btn-wrap">
      <fct-button type="primary" @click="applyFilters()">
        {{ $t('Apply Filters') }}
      </fct-button>
    </div>
  </div>

</template>

<script type="text/babel">
import {isEmpty} from "@/utils/Utils";
import {each} from "@/utils/Utils";
export default {
  name: 'OrderFilterItem',
  props: ['filters', 'filterOptions'],
  emits: ['updateFilters'],
  data() {
    return {
      selectedFilters: [],
      oldSelectedFilters: [],
      filterArray: {},
      textOptions: {
        includes: this.$t('Includes'),
        not_includes: this.$t('not Includes')
      },
      number_options: {
        '=': 'equal',
        '!=': 'not equal',
        'gt': 'greater than',
        'lt': 'less than'
      }
    }
  },
  mounted() {
    // if (isEmpty(this.filters)) {
    //     this.filterArray.push({
    //         item_key: '',
    //         item_values: [],
    //         operator: 'IN'
    //     });
    // } else {
    //     each(this.filters, (filter, filterKey) => {
    //         this.filterArray.push({
    //             item_key: filterKey,
    //             item_values: filter.value,
    //             operator: filter.operator
    //         });
    //     });
    // }

    if (!isEmpty(this.filters)) {
      this.selectedFilters = Object.keys(this.filters);
      each(this.filters, (filter, filterKey) => {
        this.filterArray[filterKey] = ({
          item_key: filterKey,
          item_values: filter.value,
          operator: filter.operator
        });
      });
    }
  },
  methods: {
    // Same values the old v-for gave: an array's index or an object's key.
    toOptions(source) {
      if (Array.isArray(source)) {
        return source.map((label, value) => ({label, value}));
      }
      return Object.keys(source || {}).map(value => ({label: source[value], value}));
    },
    handleSelectClick(event) {
      event.stopPropagation();
      // Handle select click if needed
    },
    onSelectedFilterRemoved(val) {
      delete this.filterArray[val]
    },
    onSelectedFiltersChanged(values) {
      if (values.length > this.oldSelectedFilters.length) {
        this.filterArray[values[values.length - 1]] = ({
          item_key: values[values.length - 1],
          //operator: 'IN',
          operator: '',
          item_values: []
        });
      }
      this.oldSelectedFilters = values;
    },
    applyFilters() {
      const appliedFilters = {};
      // each(this.filterArray, (filterItem) => {
      //     if (filterItem.item_key && !isEmpty(filterItem.item_values)) {
      //         appliedFilters[filterItem.item_key] = {
      //             value: filterItem.item_values,
      //             operator: filterItem.operator
      //         };
      //     }
      // });
      Object.keys(this.filterArray).forEach((key, index) => {
        let filterItem = this.filterArray[key];
        if (filterItem.item_key && !isEmpty(filterItem.item_values)) {
          appliedFilters[filterItem.item_key] = {
            value: filterItem.item_values,
            operator: filterItem.operator
          };
        }
      });

      this.$emit('updateFilters', appliedFilters);
    },
    addNewFilter() {
      this.filterArray.push({
        item_key: '',
        operator: 'IN',
        item_values: []
      });
    },
    maybeChangeOperator(item) {
      if (!item.item_key) {
        return;
      }

      const type = this.filterOptions[item.item_key]?.type;
      if (type == 'options') {
        item.operator = 'IN';
        item.item_values = [];
      } else if (type == 'text') {
        item.operator = 'includes';
        item.item_values = '';
      } else if (type == 'number') {
        item.operator = 'gt';
        item.item_values = '';
      }
    },
    // removeFilter(index) {
    //     this.filterArray.splice(index, 1);
    // }
  }
}
</script>
