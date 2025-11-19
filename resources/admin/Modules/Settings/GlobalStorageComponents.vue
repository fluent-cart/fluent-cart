<template>
  <div class="setting-wrap" :class="route_name">
    <div class="single-page-header flex items-center justify-between">
      <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
        <el-breadcrumb-item :to="{ name: 'storage' }">
          {{ translate("Storage Providers") }}
        </el-breadcrumb-item>
        <el-breadcrumb-item v-if="Str.headline(route_name)">
          {{ Str.headline(route_name) }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>
    <el-skeleton :loading="fetching" animated :rows="6"/>

    <template v-if="!fetching">

      <VueForm
          :form="form"
          :showSubmitButton="true"
          @onSubmitButtonClick="saveSettings"
          :submitButtonText="translate('Save')"
          :loading="saving"
          @on-change="(value) => {}"
          :validation-errors="validationErrors"
      />

    </template>
  </div><!-- .setting-wrap -->
</template>

<script setup>
import {ref, reactive, onMounted, watch, getCurrentInstance} from 'vue'
import {useRoute} from 'vue-router'
import Renderer from "@/Modules/Settings/StorageComponent/Renderer.vue"
import * as Card from '@/Bits/Components/Card/Card.js'
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin"
import Str from "@/utils/support/Str"
import Notify from "@/utils/Notify"
import translate from "@/utils/translator/Translator"
import Rest from "@/utils/http/Rest";
import {useFormModel} from "@/utils/model/form/FormModel";
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import {ArrowRight} from "@element-plus/icons-vue";

// Reactive data
const fields = ref({})
const settings = ref({})
const saving = ref(false)
const fetching = ref(false)
const is_key_defined = ref(false)
const labelPosition = ref('top')
const fetchRoute = ref('')
const pages = ref([])
const route_name = ref('')
const verifiedMessage = ref(false)
const verifiedStatus = ref(false)
const verifying = ref(false);
const form = useFormModel();
const validationErrors = ref({});

// Vue instances and composables
const route = useRoute()
const instance = getCurrentInstance()
const saveShortcut = useSaveShortcut()

// Methods
const updateSettings = (newSettings) => {
  settings.value = newSettings
}

const getSettings = () => {
  fetching.value = true
  Rest.get('settings/storage-drivers/' + route_name.value)
      .then((response) => {
        fetching.value = false
        //fields.value = response.fields
        //settings.value = response.settings
        form.setSchema(response.fields).setDefaults(response.settings).initForm();
      })
}

const saveSettings = () => {
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

const verifyKeys = (req, method) => {
  verifying.value = true
}

const getRoute = () => {
  route_name.value = route.name
  fetchRoute.value = `settings/storage/${route.name}`
}

const getPageName = () => {
  let pageName = ''
  if (route.meta.title) {
    pageName = route.meta.title + ' Settings'
  } else {
    pageName = route.name.charAt(0).toUpperCase() + route.name.slice(1).toLowerCase() + ' Settings'
  }
  return pageName
}

// Watchers
watch(route, (to, from) => {
  getRoute()
  getSettings()
  getPageName()
})

// Save shortcut setup
saveShortcut.onSave(() => {
  saveSettings()
})

// Mounted lifecycle
onMounted(() => {
  getRoute()
  getSettings()
  if (window.outerWidth < 500) {
    labelPosition.value = "top"
  }
})
</script>
