<script setup>
import {computed, onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const settingsForm = ref({
  recipients: '{{wp.admin_email}}',
  send_when_empty: 'no',
  daily: {enabled: 'no', send_hour: 8},
  weekly: {enabled: 'no', send_hour: 8, send_dow: 1},
  monthly: {enabled: 'no', send_hour: 8},
});

const saving = ref(false);
const validationErrors = ref({});
const testing = ref('');

const cadences = [
  {key: 'daily', label: translate('Daily digest'), desc: translate("Yesterday's performance, sent every morning.")},
  {key: 'weekly', label: translate('Weekly digest'), desc: translate('The previous 7 days, sent on your chosen day.')},
  {key: 'monthly', label: translate('Monthly digest'), desc: translate('The previous calendar month, sent on the 1st.')},
];

// Kept in script (not inline in the template): a literal {{ }} token in a
// template mustache would be parsed as a Vue interpolation and break the build.
const adminEmailToken = '{{wp.admin_email}}';
/* translators: %1$s: the {{wp.admin_email}} shortcode token */
const recipientsHint = translate('Comma-separate multiple addresses. %1$s resolves to the site admin email.', adminEmailToken);

const hourOptions = computed(() => {
  const out = [];
  for (let h = 0; h < 24; h++) {
    out.push({value: h, label: String(h).padStart(2, '0') + ':00'});
  }
  return out;
});

const dowOptions = [
  {value: 1, label: translate('Monday')},
  {value: 2, label: translate('Tuesday')},
  {value: 3, label: translate('Wednesday')},
  {value: 4, label: translate('Thursday')},
  {value: 5, label: translate('Friday')},
  {value: 6, label: translate('Saturday')},
  {value: 7, label: translate('Sunday')},
];

const getSettings = () => {
  saving.value = true;
  Rest
      .get("email-notification/digest-settings")
      .then((response) => {
        if (response.data) {
          settingsForm.value = response.data;
        }
      })
      .catch(() => {
      })
      .finally(() => {
        saving.value = false;
      });
};

const saveSettings = () => {
  saving.value = true;
  validationErrors.value = {};
  Rest
      .post("email-notification/digest-settings", settingsForm.value)
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
        }
      })
      .finally(() => {
        saving.value = false;
      });
};

const sendTest = (frequency) => {
  testing.value = frequency;
  Rest
      .post("email-notification/digest-settings/send-test", {frequency})
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((error) => {
        Notify.error(error.message || translate('Could not send the test email.'));
      })
      .finally(() => {
        testing.value = '';
      });
};

onMounted(() => {
  getSettings();
});
</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Store Digest Emails')"
        :loading="saving"
        @on-save="saveSettings"
    />

    <div class="setting-wrap-inner">
      <AdminNotice/>

      <Card.Container>
        <Card.Header
            :title="translate('Recipients & General')"
            :text="translate('A system email summarising your store activity. Numbers match the Reports dashboard.')"
            border_bottom/>
        <Card.Body>
          <el-row :gutter="15">
            <el-col :lg="12">
              <el-form-item :label="translate('Recipient email addresses')" label-position="top">
                <el-input
                    v-model="settingsForm.recipients"
                    :placeholder="adminEmailToken"
                />
                <div>
                  <ValidationError
                      :validation-errors="validationErrors.recipients||{}"
                      field-key="recipients"
                  />
                  <div class="form-note">
                    <p>{{ recipientsHint }}</p>
                  </div>
                </div>
              </el-form-item>
            </el-col>
            <el-col :lg="12">
              <el-form-item :label="translate('Empty periods')" label-position="top">
                <el-checkbox
                    v-model="settingsForm.send_when_empty"
                    true-value="yes"
                    false-value="no"
                >
                  {{ translate('Send even when there was no activity') }}
                </el-checkbox>
                <div class="form-note">
                  <p>{{ translate('When off, a digest is skipped for any period with no paid orders.') }}</p>
                </div>
              </el-form-item>
            </el-col>
          </el-row>
        </Card.Body>
      </Card.Container>

      <Card.Container v-for="cadence in cadences" :key="cadence.key" class="fct-mt-4">
        <Card.Header :title="cadence.label" :text="cadence.desc" border_bottom/>
        <Card.Body>
          <el-row :gutter="15" align="middle">
            <el-col :lg="6">
              <el-form-item :label="translate('Enable')" label-position="top">
                <el-switch
                    v-model="settingsForm[cadence.key].enabled"
                    active-value="yes"
                    inactive-value="no"
                />
              </el-form-item>
            </el-col>

            <el-col :lg="6">
              <el-form-item :label="translate('Send time')" label-position="top">
                <el-select v-model="settingsForm[cadence.key].send_hour" class="w-full">
                  <el-option
                      v-for="opt in hourOptions"
                      :key="opt.value"
                      :label="opt.label"
                      :value="opt.value"
                  />
                </el-select>
              </el-form-item>
            </el-col>

            <el-col :lg="6" v-if="cadence.key === 'weekly'">
              <el-form-item :label="translate('Day of week')" label-position="top">
                <el-select v-model="settingsForm.weekly.send_dow" class="w-full">
                  <el-option
                      v-for="opt in dowOptions"
                      :key="opt.value"
                      :label="opt.label"
                      :value="opt.value"
                  />
                </el-select>
              </el-form-item>
            </el-col>

            <el-col :lg="6" v-if="cadence.key === 'monthly'">
              <el-form-item :label="translate('Send day')" label-position="top">
                <div class="form-note" style="padding-top: 6px;">
                  <p>{{ translate('Sent on the 1st of each month.') }}</p>
                </div>
              </el-form-item>
            </el-col>

            <el-col :lg="6">
              <el-form-item label=" " label-position="top">
                <el-button
                    :loading="testing === cadence.key"
                    @click="sendTest(cadence.key)"
                    class="mt-2"
                >
                  {{ translate('Send test email') }}
                </el-button>
              </el-form-item>
            </el-col>
          </el-row>
          <div class="form-note">
            <p>{{ translate('Send time is in your store timezone. Test emails use your saved recipients.') }}</p>
          </div>
        </Card.Body>
      </Card.Container>

      <div class="setting-save-action">
        <el-button type="primary" @click="saveSettings()" :loading="saving">
          {{ translate('Save Settings') }}
        </el-button>
      </div>
    </div>
  </div>
</template>
