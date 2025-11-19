<template>
    <div class="fct-merge-fields" v-if="appReady">
      <div class="fct-merge-fields-container">
        <div class="fct-merge-fields-item" v-for="(primary_field, primary_field_index) in field.primary_fileds" :key="primary_field_index">
          <el-form-item :label="primary_field.label" :required="primary_field.required">
            <el-select
                v-if="primary_field.input_options === 'emails'"
                v-model="settings[primary_field.key]"
                :placeholder="$t('Select a Field')"
                clearable
                :class="errors.errors[primary_field.key] ? 'is-error' : ''"
            >
              <template v-for="(option, index) in inputs" :key="index">
                <el-option v-if="option?.attributes.type === 'email'"
                           :value="option?.attributes.code"
                           :label="option?.attributes.name"
                ></el-option>
              </template>
            </el-select>
            <el-select
                v-else-if="primary_field.input_options === 'all'"
                v-model="settings[primary_field.key]"
                :placeholder="$t('Select a Field')"
                clearable
            >
              <el-option
                  v-for="(option, index) in inputs"
                  :key="index" :value="option.attributes.name"
                  :label="option.admin_label"
              ></el-option>
            </el-select>

            <template v-else>
              <field-general
                  :editorShortcodes="editorShortcodes"
                  :defaultValue="settings[primary_field.key]"
                  v-model="settings[primary_field.key]"
              ></field-general>
            </template>

            <div v-if="primary_field.help_text">{{ primary_field.help_text }}</div>

            <error-view field="fieldEmailAddress" :errors="errors"></error-view>
          </el-form-item>
        </div><!-- .fct-merge-fields-item -->

        <template v-if="field.default_fields">
          <div class="fct-merge-fields-item" v-for="default_field in field.default_fields" :key="default_field.name">
            <el-form-item :label="default_field.label" :required="default_field.required">
              <field-general
                  :editorShortcodes="editorShortcodes"
                  :defaultValue="settings[primary_field.key]"
                  v-model="settings.default_fields[default_field.name]"
              ></field-general>
              <error-view field="default_fields" :errors="errors"></error-view>
            </el-form-item>
          </div><!-- .fct-merge-fields-item -->
        </template>


        <div class="fct-merge-fields-item" v-for="(field_name, field_index) in merge_fields" :key="field_index">
          <el-form-item :label="field_name">
            <field-general
                :editorShortcodes="editorShortcodes"
                :defaultValue="merge_model[field_index]"
                @onValueUpdated="function(value) {
                    onValueUpdated(value, field_index)
                }"
            ></field-general>
          </el-form-item>
        </div><!-- .fct-merge-fields-item -->

      </div><!-- .fct-merge-fields-container -->
    </div>
</template>

<script setup>

    import {QuestionFilled} from '@element-plus/icons-vue';
    import LabelHint from '@/Bits/Components/LabelHint.vue';
</script>

<script type="text/babel">
import ErrorView from './errorView.vue';
import FieldGeneral from './_FieldGeneral.vue';


export default {
  name: 'field_maps',
  components: {
    ErrorView,
    FieldGeneral
  },
  methods:{
    onValueUpdated(value, field_index) {
      this.merge_model[field_index] = value;
    },
  },
  props: ['settings', 'merge_fields', 'field', 'errors', 'merge_model', 'inputs', 'editorShortcodes'],
  data() {
    return {
      appReady: false
    }
  },
  mounted() {
    if (Array.isArray(this.merge_model) || !this.merge_model) {
      this.$emit('setMergeModelObj');
    }
    this.appReady = true;
  }

};
</script>
