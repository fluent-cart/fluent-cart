<template>
  <div class="setting-wrap" :class="route_name">
    <div class="fct-setting-header">
      <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
        <el-breadcrumb-item :to="{ name: 'storage' }">
          {{ translate("Storage Providers") }}
        </el-breadcrumb-item>
        <el-breadcrumb-item v-if="Str.headline(route_name)">
          {{ Str.headline(route_name) }}
        </el-breadcrumb-item>
      </el-breadcrumb>
      <div class="fct-setting-header-action" v-if="!hasDynamicTemplate">
        <el-button type="primary" @click="saveSettings" :loading="saving" :disabled="saving || fetching" size="small">
          {{ translate('Save') }}
        </el-button>
      </div>
    </div>

    <div class="setting-wrap-inner">
      <Card.Container v-if="fetching">
        <Card.Body>
          <el-skeleton :loading="fetching" animated :rows="5"/>
        </Card.Body>
      </Card.Container>

      <template v-else>
        <VueTemplateLoader
            v-if="hasDynamicTemplate"
            :widget="{
              type: 'vue-template',
              component: dynamicTemplate,
              payload: dynamicTemplatePayload
            }"
            :data="{ route_name }"
        />
        <VueForm
            v-else
            :form="form"
            :showSubmitButton="false"
            :loading="saving"
            @on-change="(value) => {}"
            :validation-errors="validationErrors"
        />

      </template>
    </div>
  </div><!-- .setting-wrap -->
</template>

<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import * as Card from '@/Bits/Components/Card/Card.js'
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin"
import Str from "@/utils/support/Str"
import Notify from "@/utils/Notify"
import translate from "@/utils/translator/Translator"
import Rest from "@/utils/http/Rest";
import {useFormModel} from "@/utils/model/form/FormModel";
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import VueTemplateLoader from "@/Bits/Components/DynamicTemplates/VueTemplateLoader.vue";
import {ArrowRight} from "@element-plus/icons-vue";

// Reactive data
const settings = ref({})
const dynamicTemplate = ref('')
const dynamicTemplatePayload = ref({})
const saving = ref(false)
const fetching = ref(false)
const route_name = ref('')
const form = useFormModel();
const validationErrors = ref({});
const hasDynamicTemplate = computed(() => !!dynamicTemplate.value);

// Vue instances and composables
const route = useRoute()
const saveShortcut = useSaveShortcut()

// Methods
const getSettings = () => {
  fetching.value = true
  Rest.get('settings/storage-drivers/' + route_name.value)
      .then((response) => {
        settings.value = response.settings || {}
        dynamicTemplate.value = response.template || ''
        dynamicTemplatePayload.value = response.template_payload || {}
        validationErrors.value = {}

        if (!response.template) {
          form.setSchema(response.fields).setDefaults(response.settings).initForm();
        }
      })
      .catch((errors) => {
        settings.value = {}
        dynamicTemplate.value = ''
        dynamicTemplatePayload.value = {}
        validationErrors.value = {}
        form.setSchema({}).setDefaults({})

        Notify.error(errors?.data?.message || translate('Failed to load storage settings.'))
      })
      .finally(() => {
        fetching.value = false
      })
}

const saveSettings = () => {
  if (hasDynamicTemplate.value) {
    return;
  }

  let value = form.values;

  saving.value = true
  Rest.post('settings/storage-drivers', {
    settings: {...value},
    driver: route_name.value
  })
      .then(response => {
        settings.value.is_active = response.data?.is_active;
        Notify.success(response.message || translate('Storage Settings updated!'));
        form.values = response.data;

        if(response.data.shouldReload){
          getSettings();
        }
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {
        saving.value = false
      })
}

const getRoute = () => {
  route_name.value = route.name
}

// Watchers
watch(route, (to, from) => {
  getRoute()
  getSettings()
})

// Save shortcut setup
saveShortcut.onSave(() => {
  if (!hasDynamicTemplate.value) {
    saveSettings()
  }
})

// Mounted lifecycle
onMounted(() => {
  getRoute()
  getSettings()
})
</script>
