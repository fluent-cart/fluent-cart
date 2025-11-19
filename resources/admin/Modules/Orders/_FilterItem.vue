<template>
  <div>

    <div>
      <h3 class="filter-popover-title">{{ $t('Add Filter') }}</h3>
      <el-select
          v-model="selectedFilters"
          multiple
          :placeholder="$t('Select Filters') "
          @change="onSelectedFiltersChanged"
          @remove-tag="onSelectedFilterRemoved"
      >
        <el-option v-for="(filterItem, filterKey) in filterOptions"
                   :key="filterKey"
                   :label="filterItem.label"
                   :value="filterKey"
        />
      </el-select>
    </div>

    <table style="width: 100%; margin-top: 10px;">
      <tr v-for="(filterItem, filterIndex) in filterArray" :key="filterIndex">
        <td style="width: 198px;">
          <span class="filter-item-key" v-if="filterItem.item_key">
              {{ filterOptions[filterItem.item_key].label }}:
          </span>
          <template v-else>
            <el-select @change="maybeChangeOperator(filterItem)" v-model="filterItem.item_key"
                       :placeholder="$t('Select Filter Group')">
              <el-option v-for="(filterItem, filterKey) in filterOptions"
                         :key="filterKey"
                         :label="filterItem.label"
                         :value="filterKey"
              />
            </el-select>
          </template>
        </td>
        <td>
          <template v-if="filterItem.item_key">
            <el-select
                v-if="filterOptions[filterItem.item_key]?.type == 'options'"
                v-model="filterItem.item_values"
                :multiple="true"
                :placeholder="$t('Select Options')">
              <el-option
                  v-for="(option, optionKey) in filterOptions[filterItem.item_key]?.options"
                  :key="optionKey"
                  :label="option"
                  :value="optionKey"
              />
            </el-select>
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'text'">
              <el-select class="fct_inline_operator" v-model="filterItem.operator">
                <el-option v-for="(option, optionKey) in textOptions"
                           :key="optionKey"
                           :label="option"
                           :value="optionKey"
                />
              </el-select>
              <el-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="text"/>
            </div>
            <div class="fct_inline_inputs" v-if="filterOptions[filterItem.item_key]?.type == 'number'">
              <el-select class="fct_inline_operator" v-model="filterItem.operator">
                <el-option v-for="(option, optionKey) in number_options"
                           :key="optionKey"
                           :label="option"
                           :value="optionKey"
                />
              </el-select>
              <el-input v-model="filterItem.item_values" :placeholder="$t('Search Value')" type="number"/>
            </div>
          </template>
        </td>

      </tr>
    </table>

    <div class="filter-btn-wrap justify-end">
      <el-button type="primary" @click="applyFilters()">
        {{ $t('Apply Filter') }}
      </el-button>
    </div>

  </div>

</template>

<script type="text/babel">
import {isEmpty} from "@/utils/Utils";
import {each} from "@/utils/Utils";
import {Close} from '@element-plus/icons-vue';
import {markRaw} from "vue";

export default {
  name: 'OrderFilterItem',
  props: ['filters', 'filterOptions'],
  emits: ['updateFilters'],
  components: {
    Close: markRaw(Close)
  },
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
  }
}
</script>
