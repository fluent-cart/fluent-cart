<script setup>
import translate from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import SettingsPage from "./Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";

import { useSaveShortcut } from "@/mixin/saveButtonShortcutMixin";
import Alert from "@/Bits/Components/Alert.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const invoicePacking = ref([]);
const confirmationShortcodes = ref([]);
const loading = ref(false);
const Saving = ref(false);
const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => {
  saveTemplate();
})

const availableTemplates = ref([
  translate('Invoice Template'),
  translate('Packing Slip Template'),
  translate('Delivery Slip Template'),
  translate('Shipping Slip Template'),
  translate('Dispatch Slip Template')
]);


const fetchInvoicePackingTemplates = () => {
  loading.value = true;
  Rest.get('templates/print-templates')
      .then(response => {
        invoicePacking.value = response.templates;
        loading.value = false;
      })
      .catch(errors => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false;
      });
}


const getShortcodes = () => {
  loading.value = true;
  Rest.get('settings/confirmation/shortcode', {})
      .then(response => {
        confirmationShortcodes.value = response.data;
      })
      .catch(errors => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false;
      });
}

const saveTemplate = () => {
  Saving.value = true;
  Rest.put('templates/print-templates', {
    templates: invoicePacking.value
  })
      .then(response => {
        Notify.success(response.message);
      })
      .catch(errors => {
        Notify.error(errors);
      })
      .finally(() => {
        Saving.value = false;
      });
}

onMounted(() => {
  fetchInvoicePackingTemplates();
  getShortcodes();
})

</script>

<template>
  <SettingsPage
      :heading="translate('Invoice & Packing')"
      :show-save-button="false"
  >
    <AdminNotice/>

    <Alert
        type="information"
        :content="translate('It will be available soon!')"
    />

    <template v-if="loading">
      <Section v-for="(template, index) in availableTemplates" :key="index" :title="template" divided>
        <fct-skeleton animated :rows="6" />
      </Section>
    </template>

    <template v-if="!loading && invoicePacking.length > 0">
      <Section v-for="(template, i) in invoicePacking" :key="i" :title="template.title + ' (Under Development)'" divided>
        <div class="setting-confirmation-content">
          <div class="custom-wp-editor-wrapper">
            <WpEditor
                :short-codes="confirmationShortcodes"
                v-model="template.content"
                @update="(val) => {
                template.content = val;
                    // updateVal(val)
                }
              "
            />
          </div>
        </div>
      </Section>
      <div class="setting-save-action" v-if="false">
        <fct-button @click="saveTemplate()" type="primary" :loading="Saving">
          {{translate('Save')}}
        </fct-button>
      </div>
    </template>
  </SettingsPage>
</template>
