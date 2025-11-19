<script setup>
import {getCurrentInstance, onMounted, ref, nextTick, computed} from "vue";
import ConnectAccount from "@/Modules/Settings/Parts/_connect_account.vue";
import Tabs from "@/Modules/Settings/PaymentComponet/Tabs.vue";
import ColorPicker   from "@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import RadioSelectDependants from "@/Modules/Settings/PaymentComponet/RadioSelectDependants.vue";
import {InfoFilled} from "@element-plus/icons-vue";
import ContentCard from "@/Bits/Components/Card/ContentCard.vue";
import PayPalWebhookSetup from "@/Modules/Settings/PaymentComponet/PayPalWebhookSetup.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

const selfRef = getCurrentInstance().ctx;
const connect_config = ref({});
const live_account = ref(null);
const test_account = ref(null);
const fetching_connect = ref(false);
const errors = ref({});
const orderMode = AppConfig.get('shop.order_mode');

const props =
    defineProps({
      fields: Object,
      settings: Object,
      route_name: String,
      methodName: String,
      methodTitle: String,
      methodLabel: String
    });

const emit = defineEmits(['onSettingsChange']);


const checkProviderType = computed(() => {
  return props.settings?.payment_mode === 'test' ? props.settings?.define_test_keys : props.settings?.define_live_keys;
})

const paymentModeCheck = computed(() => {
  if (props.fields?.payment_mode && props.settings?.payment_mode !== orderMode) {
    emit('onSettingsChange', { ...props.settings, payment_mode: orderMode });
  }
  return true;
});

const currentMode = computed(() => {
  return props.settings?.payment_mode === 'test' ? 'test' : 'live';
});

const getConnectConfig = () => {
  fetching_connect.value = true;
  selfRef.$get('settings/payment-methods/connect/info', {
    method: props.route_name
  })
  .then(response => {
    if (response) {
      fetching_connect.value = false;
      connect_config.value = response.connect_config;
      emit('onSettingsChange', response.settings);
      live_account.value = response.live_account;
      test_account.value = response.test_account;
          if (live_account.value.error) {
            errors.value = live_account.value;
          }
          if (test_account.value.error) {
            errors.value = test_account.value;
          }
          fetching_connect.value = false;
        }
      }).catch(error => {
        fetching_connect.value = false;
        errors.value = error;
      })
      .finally(() => {
        fetching_connect.value = false;
      })
}

onMounted(() => {
  getConnectConfig();
})

</script>

<template>

  <div :class="currentMode === 'test' ? 'fct_test_mode' : 'fct_live_mode'" class="grid grid-cols-1 gap-4">
    <div class="fct-setting-row fct-row" v-for="(field, index) in fields" :key="index">
      <div class="fct-col" v-if="field.type === 'notice'">
        <p v-html="field.value"></p>
      </div>
      <div class="fct-col" v-if="field.type === 'upcoming'">
        <h1 class="font-bold p-6 mt-6 text-center bg-gray-200">{{ translate('Upcoming') }}</h1>
      </div>
      <div class="fct-col" v-if="field.type === 'provider'">
        <template v-if="field.value == 'connect'">
          <template v-if="settings.payment_mode == 'test'">
            <el-skeleton :loading="fetching_connect" animated>
              <template #template>
                <el-skeleton-item variant="h3" class="w-[150px] mb-3" />
                <el-skeleton-item variant="p" class="h-[50px]" />
              </template>
            </el-skeleton>
            <ConnectAccount v-if="!fetching_connect"
                            @reload_settings="getConnectConfig()"
                            :method="route_name"
                            :methodName="methodName"
                            :methodLabel="methodLabel"
                            :connect_config="connect_config"
                            mode="test"
                            :connect="test_account"
            />
          </template>
          <template v-else-if="settings.payment_mode == 'live'">
            <ConnectAccount v-if="!fetching_connect"
                            :method="route_name"
                            :methodName="methodName"
                            @reload_settings="getConnectConfig()"
                            :connect_config="connect_config"
                            mode="live"
                            :methodLabel="methodLabel"
                            :connect="live_account"
            />
          </template>
          <br/>
          <!-- <p>You may use direct API keys defined as constant
            <br/>For test publishable key and secret key <code>FCT_STRIPE_TEST_PUBLIC_KEY</code>,<code>FCT_STRIPE_TEST_SECRET_KEY</code> and
            <br/>For live mode <code>FCT_STRIPE_LIVE_PUBLIC_KEY</code> and<code>FCT_STRIPE_LIVE_SECRET_KEY</code></p> -->
          <p class="text-red-700">{{ errors?.error }}</p>
          <!-- <ErrorView field="connect" :errors="errors" /> -->
        </template>
        <div v-else>
          <div v-if="checkProviderType">
            <div>
              <ContentCard :title="translate('Your API setup is Up & Running 🎉')">
                <div class="flex items-center justify-between">
                  <div>
                    <b>{{ translate('Payment API settings is enabled by defined API keys.') }}</b>
                  </div>
                </div>
              </ContentCard>
            </div>
          </div>
          <div v-else class="flex items-center justify-between">
            <div>
              <b>{{ translate('Payment API settings is not enabled by API keys / Connect') }}</b>
              <br/>
              <p>{{ translate('You can Set Up API keys defined as constant, Or use connect.') }}</p>
              <p class="text-red-700">{{ errors?.error }}</p>
            </div>
          </div>
        </div>
      </div>
      <div class="fct-col" v-if="field.type === 'enable'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <el-switch
            v-model="settings[index]"
            active-value="yes"
            inactive-value="no"
            :active-text="translate('Active')"
            :inactive-text="translate('Inactive')"
        />
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'checkbox'">
        <el-checkbox
            true-value="yes"
            false-value="no"
            v-model="settings[index]"
            :disabled="field.disabled"
        >
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </el-checkbox>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>
      <div class="fct-col" v-if="field.type === 'select'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <el-select :placeholder="field.placeholder" v-model="settings[index]"
                   :filterable="(field.filterable !== false)">
          <el-option v-for="(opt, ind) in field.options" :label="opt.label" :value="opt.value" :key="ind"/>
        </el-select>

        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'radio'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip v-if="field.tooltip" placement="top-start"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip">
              </p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <el-radio-group v-model="settings[index]">
          <el-radio v-for="(opt, ind) in field.options" :label="opt" :value="ind" :key="ind"/>
        </el-radio-group>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div><!-- .fct-col -->
      <!-- .fct-col -->

      <div class="fct-col" v-if="field.type === 'webhook_info' && settings?.payment_mode === field.mode">
        <div v-if="route_name === 'paypal' && !checkProviderType">
          <PayPalWebhookSetup
              :testConnect="test_account"
              :liveConnect="live_account"
              :webhook_info="field.info"
              @reload_settings="getConnectConfig()"
              :mode="field?.mode"/>
        </div>
        <div v-else-if="field.info" v-html="field.info"></div>
      </div>


      <div class="fct-col" v-if="field.type === 'input' || field.type === 'password'">
        <p class="setting-label">{{ field.label }}</p>
        <el-input :type="field.type" :placeholder="field.placeholder" v-model="settings[index]" :maxlength="field.max_length"></el-input>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'email'">
        <p class="setting-label">{{ field.label }}</p>
        <el-input type="email" :placeholder="field.placeholder" v-model="settings[index]">
        </el-input>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'number'">
        <p class="setting-label">{{ field.label }}</p>
        <el-input-number  :placeholder="field.placeholder" v-model="settings[index]" :max="field.max" :min="field.min"></el-input-number>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'text'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <el-input type="text" :placeholder="field.placeholder" v-model="settings[index]" :disabled="field.disabled" :maxlength="field.max_length">
        </el-input>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'color'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <ColorPicker v-model="settings[index]" :field="field"/>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'file'">
        <p class="setting-label">
          {{ field.label }}
          <el-tooltip placement="top-start" v-if="field.tooltip"
                      popper-class="fct-tooltip">
            <template #content>
              <p v-html="field.tooltip"></p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <MediaInput v-model="settings[index]" icon="Upload" :title="translate('Upload')"/>
        <p v-if="field.description" class="fct-settings-description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'checkbox_group'">
        <p class="setting-label">
          {{ field.title }}
          <el-tooltip v-if="field.tooltip" placement="top-start"
                      popper-class="fct-tooltip">
            <template #content>
              <p>
                {{ field.tooltip }}
              </p>
            </template>
            <el-icon>
              <InfoFilled/>
            </el-icon>
          </el-tooltip>
        </p>
        <el-checkbox-group v-model="settings[index]">
          <el-checkbox v-for="(opt, ind) in field.options" :label="ind" :key="ind">{{ opt }}
          </el-checkbox>
        </el-checkbox-group>
        <p class="fct-settings-description" v-if="field.description">{{ field.description }}</p>
      </div>

      <div class="fct-col" v-if="field.type === 'html_attr'">
        <div v-html="field.value"></div>
      </div>

      <div class="fct-col" v-if="field.type === 'active_methods'">
        <div v-if="field.value?.activated_methods">
          <div v-if="settings.payment_mode === 'live' && index === 'live_active_methods'">
            <p class="setting-label">{{field.label}}</p>
            {{active_methods}}
            <ul class="flex flex-wrap gap-2">
                <li class="bg-gray-50 dark:bg-gray-800 p-2 rounded text-sm text-gray-600 dark:text-gray-200 border border-gray-100 dark:border-gray-700" v-for="(method, index) in field.value.activated_methods" :key="index">{{ method?.name ? method.name : method }}</li>
            </ul>
            <p class="mt-4 text-gray-700 dark:text-gray-300">{{translate('You can configure methods ')}}<a :href="field.value?.configure_url" target="_blank" class="underline text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">{{translate('here')}}</a></p>
          </div>
          <div v-if="settings.payment_mode === 'test' && index === 'test_active_methods'">
            <p class="setting-label">{{field.label}}</p>
            <ul class="flex flex-wrap gap-2">
              {{active_methods}}
              <li class="bg-gray-50 dark:bg-gray-800 p-2 rounded text-sm text-gray-600 dark:text-gray-200 border border-gray-100 dark:border-gray-700" v-for="(method, index) in field.value.activated_methods" :key="index">{{ method?.name ? method.name : method }}</li>
            </ul>
            <p class="mt-4 text-gray-700 dark:text-gray-300">{{translate('You can configure methods ')}}<a :href="field.value?.configure_url" target="_blank" class="underline text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">{{translate('here')}}</a></p>
          </div>
        </div>
      </div>

      <div class="fct-col" v-if="field.type === 'tabs'">
        <Tabs :fields="fields" :settings="settings" :index="index" :tabs="fields[index]"/>
      </div>

      <div class="fct-col" v-if="field.type === 'radio-select-dependants'">
        <RadioSelectDependants  :fields="fields" :settings="settings" :index="index"/>
      </div>
    </div>
  </div>
</template>

<style lang="scss">
.fct_test_mode {
    .fct_hide_on_test {
        display: none !important;
    }
}
.fct_live_mode {
    .fct_hide_on_live {
        display: none !important;
    }
}
.fct-settings-description {
  font-size: 0.875rem;
  color: #6b7280;
  padding-top: 0.25rem;
}
</style>
