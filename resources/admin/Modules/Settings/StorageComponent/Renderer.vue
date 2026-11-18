<script setup>
import {getCurrentInstance, onMounted, ref, nextTick} from "vue";
import Tabs from "@/Modules/Settings/StorageComponent/Tabs.vue";
import {InfoFilled} from "@element-plus/icons-vue";
import ContentCard from '@/Bits/Components/Card/ContentCard.vue';
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;
const testing_config = ref(false);

const props = defineProps({
  fields: Object,
  settings: Object,
  route_name: String,
});

const emit = defineEmits(['onSettingsChange']);

const testConfig = () => {
  testing_config.value = true;
  selfRef.$post('settings/storage-drivers/verify-info', {
    driver: props.route_name,
    settings: props.settings
  })
      .then(response => {
        if (response.success) {
          props.settings.is_active = 'yes';
          Notify.success(
              /* translators: %s is the storage driver name */
              translate("%s Config Test Success", props.route_name)
          );
        }
      }).catch(error => {
    selfRef.handleError(error.message);
  })
      .finally(() => {
        testing_config.value = false;
      })
}

onMounted(() => {
  nextTick(() => {

  })
})

</script>

<template>
  <div class="grid grid-cols-1 gap-4">
    <div class="fct-row fct-setting-row" v-for="(field, index) in fields" :key="index">
      <div class="fct-col" v-if="field.type === 'enable'">
        <fct-switch
            v-model="settings[index]"
            active-value="yes"
            inactive-value="no"
            active-text="Active"
            inactive-text="Inactive"
        />
        <p class="setting-label" v-if="field.tooltip">
          <fct-tooltip placement="top-start">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </fct-tooltip>
        </p>
      </div><!-- .fct-col -->

      <div class="fct-col" v-if="(field.type === 'input' || field.type === 'password')">
        <p class="setting-label">{{ field.label }}</p>
        <fct-input :type="field.type === 'password' ? 'password' : 'text'" :placeholder="field.placeholder" v-model="settings[index]"/>
      </div><!-- .fct-col -->

      <div class="fct-col" v-if="field.type === 'text'">
        <p class="setting-label">{{ field.label }}</p>
        <fct-input type="text" :placeholder="field.placeholder" v-model="settings[index]"/>
      </div><!-- .fct-col -->

      <div class="fct-col" v-if="field.type === 'select'">
        <p class="setting-label">{{ field.label }}</p>
        <fct-select :placeholder="field.placeholder" v-model="settings[index]"
                   :filterable="(field.filterable !== false)" :options="Object.values(field.options || {})"/>
      </div><!-- .fct-col -->

      <div class="fct-col" v-if="field.type === 'button' && settings['is_active'] === 'yes'">
        <ContentCard :title="field.label">
          <fct-button @click="testConfig()" type="primary" :disabled="testing_config"
                     :loading="testing_config">
            {{
              /* translators: %s is the storage driver name */
              translate("Test %s Config", route_name)
            }}
          </fct-button>
        </ContentCard>
      </div><!-- .fct-col -->
    </div>
  </div>
</template>
