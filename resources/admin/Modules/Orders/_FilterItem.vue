<template>
  <div>

    <div>
      <h3 class="filter-popover-title">{{ $t('Add Filter') }}</h3>
      <fct-select
          v-model="selectedFilters"
          multiple
          :placeholder="$t('Select Filters') "
          :options="Object.entries(filterOptions).map(([filterKey, filterItem]) => ({label: filterItem.label, value: filterKey}))"
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
                       :options="Object.entries(filterOptions).map(([filterKey, option]) => ({label: option.label, value: filterKey}))"
            />
          </template>
        </td>
        <td>
          <template v-if="filterItem.item_key">
            <fct-select
                v-if="filterOptions[filterItem.item_key]?.type == 'options'"
                v-model="filterItem.item_values"
                :multiple="true"
                :placeholder="$t('Select Options')"
                :options="Object.entries(filterOptions[filterItem.item_key]?.options || {}).map(([optionKey, option]) => ({label: option, value: optionKey}))"
            />
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'text'">
              <fct-select class="fct_inline_operator" v-model="filterItem.operator"
                          :options="Object.entries(textOptions).map(([optionKey, option]) => ({label: option, value: optionKey}))"
              />
              <fct-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="text"/>
            </div>
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'number'">
              <fct-select class="fct_inline_operator" v-model="filterItem.operator"
                          :options="Object.entries(number_options).map(([optionKey, option]) => ({label: option, value: optionKey}))"
              />
              <fct-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="number" :controls="false"/>
            </div>
          </template>
        </td>

      </tr>
    </table>

    <div class="filter-btn-wrap justify-end">
      <fct-button type="primary" @click="applyFilters()">
        {{ $t('Apply Filter') }}
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

    if (!isEmpty(this.filters)) {
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

  }
}
</script>
