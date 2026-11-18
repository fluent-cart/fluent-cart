<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import MailingSettingsLoader from "@/Modules/Settings/EmailNotification/MailingSettingsLoader.vue";
import AppConfig from "@/utils/Config/AppConfig";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const settingsForm = ref({
  from_name: '',
  from_email: '',
  reply_to_name: '',
  reply_to_email: '',
  email_footer: '',
  show_email_footer: 'yes',
  admin_email: '{{wp.admin_email}}',
});

const hasPro = AppConfig.get('app_config.isProActive');

const saving = ref(false);
const validationErrors = ref({});
const shortcodes = ref({});
const getSettings = () => {
  saving.value = true;
  Rest
      .get("email-notification/get-settings")
      .then((response) => {
        settingsForm.value = {
          from_name: response.data?.from_name,
          from_email: response.data?.from_email,
          reply_to_name: response.data?.reply_to_name,
          reply_to_email: response.data?.reply_to_email,
          email_footer: response.data?.email_footer,
          admin_email: response.data?.admin_email,
          show_email_footer: response.data?.show_email_footer || 'yes',
        };
        shortcodes.value = response.shortcodes;
      })
      .catch((error) => {

      })
      .finally(() => {
        saving.value = false;
      });
}

const saveEmailSettings = () => {
  saving.value = true;
  validationErrors.value = {};
  Rest
      .post("email-notification/save-settings", settingsForm.value)
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((error) => {
        if (error.status_code === 422 && error.data) {
          validationErrors.value = error.data;
        } else if (error.message) {
          Notify.error(error.message);
        } else {
          Notify.error(translate('Something went wrong'));
          validationErrors.value = error;
        }
      })
      .finally(() => {
        saving.value = false;
      });
}

onMounted(() => {
  getSettings();
})
</script>

<template>
  <SettingsPage
      :heading="translate('Mailing Settings')"
      :loading="saving"
      @on-save="saveEmailSettings"
  >
    <AdminNotice/>

    <!-- The loader brings its own Card.Body padding. -->
    <Section
        :title="translate('Email Configuration')"
        :description="
          /* translators: %s is the plugin name */
          translate('Settings_Set_Name', 'FluentCart')
        "
        divided
        :flush="saving"
    >
      <MailingSettingsLoader v-if="saving" />
      <template v-else>
        <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
            <Field :label="translate('From Name')" v-slot="{id}">
              <fct-input :id="id" v-model="settingsForm.from_name"/>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.from_name||{}"
                    field-key="from_name"
                />
                <div class="form-note">
                  <p>{{ translate('Name that will be used to send emails') }}</p>
                </div>
              </div>

            </Field>
            <Field :label="translate('From Email')" v-slot="{id}">
              <fct-input :id="id" v-model="settingsForm.from_email"/>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.from_email||{}"
                    field-key="from_email"
                />
                <div class="form-note">
                  <p>{{
                      translate('Settings_Provide_Valid')
                    }}</p>
                </div>
              </div>

            </Field>
            <fct-input v-model="settingsForm.reply_to_name" :label="translate('Reply to name (Optional)')" :help="translate('Name that will be used for reply to attribute')"/>
            <Field :label="translate('Reply to email (Optional)')" v-slot="{id}">
              <fct-input :id="id" v-model="settingsForm.reply_to_email"/>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.reply_to_email||{}"
                    field-key="reply_to_email"
                />
                <div class="form-note">
                  <p>{{
                      translate('Settings_Someone_Replies')
                    }}</p>
                </div>
              </div>
            </Field>
        </div>

        <div>

            <div class="el-form-item__label">
              {{ translate('Email Footer') }}
            </div>

            <WpEditor
                :short-codes="shortcodes"
                v-model="settingsForm.email_footer"
                @update="(val) => {
              settingsForm.email_footer = val;
            }"
            ></WpEditor>
            <div class="mt-2 mb-2">
              <ValidationError
                  :validation-errors="validationErrors.email_footer||{}"
                  field-key="email_footer"
              />
              <div class="form-note">
                <p>{{
                    translate('Settings_Email_Footer')
                  }}</p>
              </div>
            </div>

            <div class="fct-powered-by-email-footer-checkbox">
              <fct-choice
                  v-model="settingsForm.show_email_footer"
                  true-value="yes"
                  false-value="no"
                  :disabled="!hasPro"
              >
                {{ translate('Enable powered by FluentCart in the email footer') }}

                <fct-tooltip
                    v-if="!hasPro"
                    placement="top"
                >
                  <template #content>
                    {{ translate('This feature is available in pro version only.') }}
                  </template>

                  <DynamicIcon name="Crown" class="fct-pro-icon"/>
                </fct-tooltip>
              </fct-choice>
            </div>


        </div>

        <div class="mb-[18px] grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
            <div class="setting-html-wrapper">
              <span class="setting-label">{{ translate('Admin Email Address') }}</span>
              <div class="form-note"><p>
                {{ translate('FluentCart will send admin notification to this email address.') }}</p></div>
            </div>
            <div class="setting-fields-inner w-full">
              <fct-input v-model="settingsForm.admin_email"/>
            </div>
        </div>

      </template>
    </Section>

    <div class="setting-save-action">
      <fct-button type="primary" @click="saveEmailSettings()" :loading="saving">
        {{ translate('Save Settings') }}
      </fct-button>
    </div>
  </SettingsPage>
</template>
