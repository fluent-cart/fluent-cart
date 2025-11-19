<template>
  <div v-loading="loading" class="fluent_cart_chained_filter">
    <el-row :gutter="0">
      <el-col :span="12" v-for="(options, optionKey) in field.fields_options" :key="optionKey"
              class="fluent_carteach_filter">
        <label>{{ field.options_labels[optionKey].label }}</label>
        <el-select
            @change="handleSlectChange(optionKey)"
            filterable
            clearable
            :placeholder="field.options_labels[optionKey].placeholder"
            :multiple="field.options_labels[optionKey].type == 'multi-select'"
            v-model="value[optionKey]">
          <el-option v-for="(option,optionId) in options" :key="optionId" :value="optionId"
                     :label="option"></el-option>
        </el-select>
      </el-col>
    </el-row>
  </div>
</template>
<script type="text/babel">
import {each} from "@/utils/Utils";

export default {
  name: 'ChainedSelects',
  props: ['settings', 'field', 'value'],
  data() {
    return {
      app_ready: false,
      loading: false
    }
  },
  methods: {
    fetchSettings() {
      this.loading = true;
      this.$get(this.field.remote_url, {
        settings: this.settings
      })
          .then(response => {
            this.loading = false;
            let dataOptions = response.data;
            each(dataOptions, (data, dataKey) => {
              this.$set(this.field, dataKey, data);
            });
          })
          .catch(error => {
            console.log(error);
          })
      //   .always(() => {
      //       this.loading = false;
      //   });
    },
    handleSlectChange(targetId) {
      if (targetId === this.field.primary_key) {
        this.fetchSettings();
        each(this.value, (itemValue, valueKey) => {
          if (valueKey != targetId) {
            if (this.field.options_labels[valueKey].type == 'multi-select') {
              this.value[valueKey] = [];
            } else {
              this.value[valueKey] = '';
            }
          }
        });
      }
    }
  },
  mounted() {
    this.fetchSettings();
    this.app_ready = true;
  }
}
</script>

