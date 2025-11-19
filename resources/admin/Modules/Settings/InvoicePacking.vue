<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

import { useSaveShortcut } from "@/mixin/saveButtonShortcutMixin";
import Alert from "@/Bits/Components/Alert.vue";

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
  <div class="setting-wrap">

    <Alert
        class="mb-5"
        type="information"
        :content="translate('It will be available soon!')"


    />

    <template v-if="loading">
      <Card.Container v-for="(template, index) in availableTemplates" :key="index">
        <Card.Header :title="template" border_bottom/>
        <Card.Body>
          <el-skeleton animated :rows="6" />
        </Card.Body>
      </Card.Container>
    </template>

    <template v-if="!loading && invoicePacking.length > 0">
      <Card.Container v-for="(template, i) in invoicePacking" :key="i">
        <Card.Header :title="template.title + ' (Under Development)'" border_bottom/>
        <Card.Body>
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
        </Card.Body>
      </Card.Container>
      <div class="setting-save-action">
        <el-button @click="saveTemplate()" type="primary" :loading="Saving" v-if="false">
          {{Saving ? translate('Saving') : translate('Save')}}
        </el-button>
      </div>
    </template>
  </div>
</template>

<style scoped>

</style>
