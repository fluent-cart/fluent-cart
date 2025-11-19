<template>
  <tr>
    <td class="w-[250px] fct-filter-label">
      {{ getProviderLabel(itemConfig.provider) }}
      <span class="fct-provider-separator">/</span>
      {{ itemConfig.label }}
    </td>

    <td class="fct-filter-operator w-[190px]">

      <el-select
          size="small"
          :placeholder="$t('Select Operator')"
          v-model="item.operator"
          @visible-change="operatorSelected"
          @change="()=>{
            const filterValue = typeof item.value === 'object'? Object.values(item.value): item.value;
            if(Array.isArray(filterValue) && filterValue > 0){
              table.applyAdvancedFilter();
            }else if(filterValue.toString().length > 0){
              table.applyAdvancedFilter();
            }

          }"

      >

        <template v-for="(optionLabel, option) in operatorOptions" :key="option">
          <el-option v-if="typeof optionLabel === 'string'"
                     :value="option"
                     :label="optionLabel">
          </el-option>

          <el-option v-else
                     style="height: auto"
                     :value="option"
                     :label="optionLabel.title">
            <p v-if="optionLabel.description" class="fct-operator-description p-2">
              <span>
                {{ optionLabel.title }}
              <br>
              <span style="padding-left: 8px;">- {{ optionLabel.description }}</span>
              </span>
            </p>
          </el-option>
        </template>

      </el-select>
    </td>

    <td class="fct-filter-value">
      <el-input
          size="small"
          v-if="!itemConfig.type || itemConfig.type === 'text'"
          :placeholder="$t('Condition Value')"
          type="text"
          v-model="item.value"
          @keydown.enter="applyFilter"
      />

      <el-input
          size="small"
          v-else-if="itemConfig.type === 'numeric'"
          type="number"
          :placeholder="$t('Condition Value')"
          v-model="item.value"
          @keydown.enter="applyFilter"
      />

      <template v-else-if="itemConfig.type === 'dates'">
        <el-input
            size="small"
            v-if="item.operator === 'days_before' || item.operator === 'days_within'"
            type="number"
            :placeholder="$t('Days')"
            v-model="item.value"
            @keydown.enter="applyFilter"
        />
        <el-date-picker
            @change="applyFilter"
            v-else-if="item.operator"
            type="datetime"
            value-format="YYYY-MM-DDTHH:mm:ssZ"
            size="small"
            v-model="item.value">
        </el-date-picker>
      </template>

      <template v-if="itemConfig.type === 'selections'">
        <SelectItem :table="table"
                    :item-config="itemConfig"
                    v-model="item.value"
        />
      </template>

      <template v-if="itemConfig.type === 'remote_tree_select'">
        <RemoteTreeSelect
            :table="table"
            :item-config="itemConfig"
            v-model="item.value"
        />
      </template>


    </td>

    <td class="w-[40px] text-right fct-filter-action">
      <IconButton
          @click="removeItem"
          size="x-small"
          bg="info"
          tag="button"
      >
        <DynamicIcon name="Delete"/>
      </IconButton>
    </td>

  </tr>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import RemoteTreeSelect from "@/Bits/Components/TableNew/Components/AdvancedFilter/FilterItems/RemoteTreeSelect.vue";
import Arr from "@/utils/support/Arr";
import SelectItem from "@/Bits/Components/TableNew/Components/AdvancedFilter/FilterItems/SelectItem.vue";


export default {
  name: "FilterItem",
  props: ['item', 'filterLabels', 'table'],
  components: {
    SelectItem,
    RemoteTreeSelect,
    DynamicIcon,
    IconButton
  },
  data() {
    return {}
  },
  computed: {
    operatorOptionsNative() {
      const inputType = this.itemConfig?.type || 'text';
      const filterType = this.itemConfig?.filter_type;
      const operators = this.itemConfig?.operators;

      if (filterType === 'custom' && operators) {
        return operators;
      }

      const operatorMaps = {
        text: {
          '=': this.$t('Equal'),
          '!=': this.$t(`Doesn't equal`),
          contains: this.$t('Includes'),
          not_contains: this.$t(`Doesn't includes`)
        },
        numeric: {
          '>': this.$t('Greater Than'),
          '<': this.$t('Less Than'),
          '=': this.$t('Equal'),
          '!=': this.$t(`Doesn't equal`)
        },
        remote_tree_select: {
          contains: this.$t('Includes'),
          not_contains: this.$t(`Doesn't includes`)
        },
        dates: {
          before: this.$t('Before'),
          after: this.$t('After'),
          date_equal: this.$t('At the date'),
          days_before: this.$t('Days before'),
          days_within: this.$t('Within days')
        }
      };

      if (inputType === 'selections') {
        if (this.itemConfig.is_multiple) {
          return {
            in: {
              title: this.$t('Includes any of'),
              description: this.$t('At least one matches')
            },
            in_all: {
              title: this.$t('Includes all of'),
              description: this.$t('All must match')
            },
            not_in: {
              title: this.$t('Exclude if any'),
              description: this.$t('Exclude if one matches')
            },
            not_in_all: {
              title: this.$t('Exclude if all'),
              description: this.$t('Exclude only if all match')
            }
          };


        }

        if (this.itemConfig.is_only_in) {
          return {
            in: this.$t('Includes in')
          }
        }

        return {
          '=': this.$t('Is'),
          '!=': this.$t('Is Not')
        };
      }


      return Arr.get(operatorMaps, inputType, {});

    },
    operatorOptions() {
      return this.operatorOptionsNative;
    },
    itemConfig() {
      const key = this.item.source.join('-');
      return this.filterLabels[key] || {}
    }
  },
  methods: {
    removeItem() {
      this.$emit('removeItem');
    },
    getProviderLabel(provider) {
      // replace _ and - with space
      provider = provider.replace(/_/g, ' ').replace(/-/g, ' ');

      return provider.charAt(0).toUpperCase() + provider.slice(1);
    },
    operatorSelected(status) {
      if (!status && this.item.operator) {
        setTimeout(() => {
          jQuery(this.$el).find('.fct-filter-value input').focus();
        }, 200);
      }
    },
    applyFilter() {
      setTimeout(() => {
        this.table.applyAdvancedFilter();
      }, 200);
    }
  },
  mounted() {
    if (!this.item.operator) {
      const objectKeys = Object.keys(this.operatorOptions);
      if (objectKeys.length) {
        this.item.operator = objectKeys[0];
        jQuery(this.$el).find('.fct-filter-value input').focus();
        jQuery(this.$el).find('.fct-filter-operator .el-select').trigger('click');
      }
    }
  }
}
</script>

