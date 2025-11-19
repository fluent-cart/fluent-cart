<template>
  <div class="setting-wrap">
    <div class="bg-white rounded p-6 dark:bg-dark-700" v-if="formLoading">
      <el-skeleton :loading="formLoading" animated>
        <template #template>
          <div class="grid gap-3 mb-6">
            <el-skeleton-item variant="p" class="w-[20%]"/>
            <el-skeleton-item variant="p"/>
          </div>
          <div class="grid gap-3 mb-6">
            <el-skeleton-item variant="p" class="w-[20%]"/>
            <el-skeleton-item variant="p"/>
          </div>
          <div class="grid gap-3 mb-6">
            <el-skeleton-item variant="p" class="w-[20%]"/>
            <el-skeleton-item variant="p"/>
          </div>
          <div class="grid gap-3">
            <el-skeleton-item variant="p" class="w-[20%]"/>
            <el-skeleton-item variant="p"/>
          </div>
        </template>
      </el-skeleton>
    </div>

    <template v-if="!formLoading">
      <el-form v-if="form.isReady">
        <VueForm
            v-if="hasSettings"
            :form="form"
            :showSubmitButton="true"
            @onSubmitButtonClick="saveSettings"
            :submitButtonText="translate('Save Settings')"
            :loading="saving"
            @on-change="(value) => {}"
            :validation-errors="validationErrors"
        />
        <Card.Container v-else>
          <Card.Header :title="translate('Features & addon')" border_bottom/>
          <Card.Body>
            <Empty class="fct-addon-empty-state" icon="Empty/Integrations" :text="translate('No module settings found.')" />
          </Card.Body>
        </Card.Container>
      </el-form>
    </template>
  </div>
  <!-- .setting-wrap -->
</template>

<script setup>

import {
  getCurrentInstance,
  onMounted,
  ref,
} from "vue";
import {useSaveShortcut} from '@/mixin/saveButtonShortcutMixin.js';
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import {useSettingsModel} from "@/Models/SettingsModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";

const settingsModel = useSettingsModel();
const {form} = settingsModel.data;

const saveShortcut = useSaveShortcut();


const selfRef = getCurrentInstance().ctx;


defineOptions({
  name: "StoreSettings",
});

const settings = ref([]);
const fields = ref([]);
const loading = ref(true);
const saving = ref(false);
const formLoading = ref(true);
const validationErrors = ref({});
const hasSettings = ref(false);

const saveSettings = () => {

  saving.value = true;
  // let value = JSON.parse(JSON.stringify(form.values));
  let value = form.values;
  selfRef
      .$post('settings/modules', {
        ...value,
      })
      .then((response) => {
        selfRef.handleSuccess(translate("Settings saved successfully"));
        setTimeout(() => {
          window.location.reload();
        }, 300)
      })
      .catch((errors) => {
        if (errors && errors.message) {
          return selfRef.handleError(errors);
        }
        validationErrors.value = errors;
      })
      .finally(() => {
        saving.value = false;
        formLoading.value = false;
        loading.value = false;
      });
};


const getSettings = () => {
  loading.value = true;
  selfRef.$get('settings/modules', {})
      .then((response) => {
        form.setSchema(response.fields).setDefaults(response.settings).initForm();
        settings.value = response.settings;
        fields.value = response.fields;
        formLoading.value = false;
        saving.value = false;

        const schema = response.fields?.modules_settings?.schema || [];
        let hasFields = false;
        if (Array.isArray(schema)) {
          hasFields = schema.length > 0;
        } else {
          hasFields = Object.keys(schema).length > 0;
        }

        hasSettings.value = hasFields;
      })
      .finally(() => {
        loading.value = false;
      });
};

onMounted(() => {
  getSettings();
});

saveShortcut.onSave(saveSettings);
</script>
