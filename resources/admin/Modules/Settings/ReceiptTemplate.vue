<template>
  <div class="setting-wrap">
    <Card.Container>
      <Card.Header :title="$t('Receipt template')" border_bottom/>
      <Card.Body>
        <el-skeleton animated :rows="6" :loading="loading" />
        <div v-if="!loading " class="setting-confirmation-content">
          <div class="custom-wp-editor-wrapper">
              <WpEditor
                  :short-codes="confirmationShortcodes"
                  v-model="template"
                  @update="(val) => {
                      updateVal(val)
                    }
                  "
              />

<!--            <WpEditor :editorShortcodes="confirmationShortcodes" @input="updateVal" :height="150" :value="settings.message_to_show" v-model="settings.message_to_show"/>-->
          </div>

<!--          <DynamicFields v-for="(field, index) in fields" :field="field" :key="index" :index="index" :settings="settings"/>-->
        </div>
      </Card.Body>
    </Card.Container>
    <div class="setting-save-action">
      <el-button @click="saveSettings()" type="primary" :loading="saving">
        <span v-if="!saving" class="cmd">⌘s</span>
        {{saving ? translate('Saving') : translate('Save')}}
      </el-button>
    </div>
  </div>
</template>

<script setup>
  import * as Card from '@/Bits/Components/Card/Card.js';
  // import WpEditor from './Parts/_wp_editor.vue';
  import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
  import DynamicFields from './Parts/_setting_fields.vue';
  import {onMounted, ref} from 'vue';
  import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
  import {onBeforeRouteLeave} from "vue-router";
  import Rest from "@/utils/http/Rest";
  import Notify from "@/utils/Notify";
  import translate from "@/utils/translator/Translator";
  const keyboardShortcuts = useKeyboardShortcuts();

  keyboardShortcuts.bind(['mod+s'], (event) => {
    event.preventDefault();
    saveSettings();
  });

  const loading = ref(false);
  const template = ref('');
  const confirmationShortcodes = ref([]);
  const saving = ref(false);

  const saveSettings = () => {
    saving.value = true;
    Rest.post('templates/receipt-template', {
      template: template.value
    }).then(response => {
      Notify.success(response.message);
    })
        .catch(errors => {
          Notify.error(errors);
        })
        .finally(() => {
          saving.value = false;
        });
  }

  const getTemplate = () => {
    Rest.get('templates/receipt-template', {})
        .then(response => {
          template.value = response.template;
          // this.fields = response.fields;
          // this.registerCopyAction()
        })
        .catch(errors => {
          Notify.error(errors);
        })
        .finally(() => {
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

  const updateVal = (val) => {
    template.value = val;
  }

  onMounted(() => {
    getTemplate();
    getShortcodes();
  })

  onBeforeRouteLeave(() => {
    keyboardShortcuts.unbind('mod+s');
  });
</script>
