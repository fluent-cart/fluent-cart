<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import MailingSettingsLoader from "@/Modules/Settings/EmailNotification/MailingSettingsLoader.vue";
import AppConfig from "@/utils/Config/AppConfig";

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
        if (error.message) {
          Notify.error(error.message);
        } else {
          Notify.error(translate('Please Fill up all the fields'));
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
  <div class="setting-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('Mailing Settings')"
          :text="
            /* translators: %s is the plugin name */
            translate('Set your from name, email, and email footer. These configurations will be used to send emails from %s.', 'FluentCart')
          "
          border_bottom />
      <MailingSettingsLoader v-if="saving" />
      <Card.Body v-else>

        <el-row :gutter="15">
          <el-col :lg="12">
            <el-form-item :label="translate('From Name')" label-position="top">
              <el-input v-model="settingsForm.from_name"/>
              <br>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.from_name||{}"
                    field-key="from_name"
                />
                <div class="form-note">
                  <p>{{ translate('Name that will be used to send emails') }}</p>
                </div>
              </div>

            </el-form-item>
          </el-col>
          <el-col :lg="12">
            <el-form-item :label="translate('From Email')" label-position="top">
              <el-input v-model="settingsForm.from_email"/>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.from_email||{}"
                    field-key="from_name"
                />
                <div class="form-note">
                  <p>{{
                      translate(`Provide Valid Email Address email as per your domain/SMTP settings. This
email will be used to send emails like notifications`)
                    }}</p>
                </div>
              </div>

            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="15">
          <el-col :lg="12">
            <el-form-item :label="translate('Reply to name (Optional)')" label-position="top">
              <el-input v-model="settingsForm.reply_to_name"/>
              <div class="form-note">
                <p>{{ translate('Name that will be used for reply to attribute') }}</p>
              </div>

            </el-form-item>
          </el-col>
          <el-col :lg="12">
            <el-form-item :label="translate('Reply to email (Optional)')" label-position="top">
              <el-input v-model="settingsForm.reply_to_email"/>
              <div>
                <ValidationError
                    :validation-errors="validationErrors.reply_to_email||{}"
                    field-key="reply_to_email"
                />
                <div class="form-note">
                  <p>{{
                      translate(`If someone replies to your notification email, this is where you would like to
receive it.`)
                    }}</p>
                </div>
              </div>
            </el-form-item>
          </el-col>
        </el-row>

        <el-row :gutter="15">
          <el-col>

            <div class="el-form-item__label">
              {{ translate('Email Footer') }}
            </div>

            <wp-editor
                :short-codes="shortcodes"
                v-model="settingsForm.email_footer"
                @update="(val) => {
                settingsForm.email_footer = val;
              }"
            ></wp-editor>
            <div class="mt-2 mb-2">
              <ValidationError
                  :validation-errors="validationErrors.email_footer||{}"
                  field-key="email_footer"
              />
              <div class="form-note">
                <p>{{
                    translate(`This email footer will be used for all emails sent from FluentCart, It's highly recommended to use your business name and address in the footer for
compliance.`)
                  }}</p>
              </div>
            </div>

            <div class="fct-powered-by-email-footer-checkbox mb-4 pb-4 border-[1px] border-l-0 border-t-0 border-r-0 border-solid border-gray-outline dark:border-dark-400">
              <el-checkbox
                  v-model="settingsForm.show_email_footer"
                  true-value="yes"
                  false-value="no"
                  :disabled="!hasPro"
              >
                {{ translate('Enable powered by FluentCart in the email footer') }}
              </el-checkbox>
            </div>


          </el-col>
        </el-row>

        <el-row :gutter="15">
          <el-col>
            <el-form-item>

              <el-row :gutter="15" class="w-full">
                <el-col :lg="12">
                  <div class="setting-html-wrapper">
                    <span class="setting-label">{{ translate('Admin Email Address') }}</span>
                    <div class="form-note"><p>
                      {{ translate('FluentCart will send admin notification to this email address.') }}</p></div>
                  </div>
                </el-col>
                <el-col :lg="12">
                  <div class="setting-fields-inner w-full">
                    <el-input v-model="settingsForm.admin_email"/>
                  </div>
                </el-col>
              </el-row>

            </el-form-item>
          </el-col>
        </el-row>

      </Card.Body>
    </Card.Container>

    <div class="setting-save-action">
      <el-button type="primary" @click="saveEmailSettings()" :loading="saving">
        {{ saving ? translate('Saving') : translate('Save') }}
      </el-button>
    </div>
  </div>
</template>

<style scoped>

</style>
