<script setup>
import {computed, onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
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
  <SettingsPage
      :heading="translate('Store Digest Emails')"
      :loading="saving"
      @on-save="saveSettings"
  >
    <AdminNotice/>

    <Section
        :title="translate('Recipients & General')"
        :description="translate('Settings_System_Email')"
        divided
    >
      <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
        <Field :label="translate('Recipient email addresses')" v-slot="{id}">
          <fct-input :id="id" v-model="settingsForm.recipients" :placeholder="adminEmailToken"/>
          <div>
            <ValidationError
                :validation-errors="validationErrors.recipients||{}"
                field-key="recipients"
            />
            <div class="form-note">
              <p>{{ recipientsHint }}</p>
            </div>
          </div>
        </Field>
        <fct-choice
            v-model="settingsForm.send_when_empty"
            true-value="yes"
            false-value="no"
            :label="translate('Empty periods')"
            :help="translate('When off, a digest is skipped for any period with no paid orders.')"
        >
          {{ translate('Send even when there was no activity') }}
        </fct-choice>
      </div>
    </Section>

    <Section v-for="cadence in cadences" :key="cadence.key" :title="cadence.label" :description="cadence.desc" divided>
      <div class="grid grid-cols-1 items-center gap-x-[15px] min-[1200px]:grid-cols-4">
        <fct-switch
            v-model="settingsForm[cadence.key].enabled"
            active-value="yes"
            inactive-value="no"
            :label="translate('Enable')"
        />

        <fct-select
            v-model="settingsForm[cadence.key].send_hour"
            class="w-full"
            :options="hourOptions"
            :label="translate('Send time')"
        />

        <fct-select
            v-if="cadence.key === 'weekly'"
            v-model="settingsForm.weekly.send_dow"
            class="w-full"
            :options="dowOptions"
            :label="translate('Day of week')"
        />

        <Field v-if="cadence.key === 'monthly'" :label="translate('Send day')">
          <div class="form-note" style="padding-top: 6px;">
            <p>{{ translate('Sent on the 1st of each month.') }}</p>
          </div>
        </Field>

        <Field label=" ">
          <fct-button
              :loading="testing === cadence.key"
              @click="sendTest(cadence.key)"
          >
            {{ translate('Send test email') }}
          </fct-button>
        </Field>
      </div>
      <div class="form-note">
        <p>{{ translate('Send time is in your store timezone. Test emails use your saved recipients.') }}</p>
      </div>
    </Section>

    <div class="setting-save-action">
      <fct-button type="primary" @click="saveSettings()" :loading="saving">
        {{ translate('Save Settings') }}
      </fct-button>
    </div>
  </SettingsPage>
</template>
