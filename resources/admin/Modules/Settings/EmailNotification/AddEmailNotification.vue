<script setup>
import Card from "@/Bits/Components/Card/Card.vue";
import CardHeader from "@/Bits/Components/Card/CardHeader.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import { getCurrentInstance, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { useSaveShortcut } from "@/mixin/saveButtonShortcutMixin.js";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;
const wpEditor = ref();
const loading = ref(true);
const isModalVisible = ref(false);
const notification = ref({});
const htmlContent = ref();
const availableEvents = ref([
  // Define event options
  { label: translate("After Order Created"), value: "fluent_cart/order_created" },
  { label: translate("After Order Updated"), value: "fluent_cart/order_updated" },
  { label: translate("After Payment Paid"), value: "fluent_cart/order_paid" },
  { label: translate("After User Created"), value: "fluent_cart/user_created" },
  { label: translate("After Refund made"), value: "fluent_cart/order_refunded" },
]);
const shortCodes = ref({});
const emailShortCodes = ref([
  { label: "Admin Email", value: "{wp.admin_email}" },
  { label: "Customer Email", value: "{order.customer.email}" },
  { label: "User Email", value: "{user.user_email}" },
]);
const templates = ref({});
const buttons = ref({});
const router = useRouter();
const route = useRoute();
let editor = null;

const selectedToEmail = ref([]);
const selectedFromEmail = ref([]);

const validationErrors = ref({});

const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => {
    addNotification();
});

const getTemplate = (selectedTemplate) => {
  const template = selectedTemplate;
  selfRef
    .$post("email-notification/getTemplate/", { template })
    .then((response) => {
      notification.value.content = response.data.content;
      wpEditor.value?.setContent(notification.value.content);
    })
    .catch((error) => {})
    .finally(() => {
      loading.value = false;
    });
};

const resetTemplate = () => {
  wpEditor.value?.setContent("");
};

const getShortCodes = () => {
  loading.value = true;
  selfRef
    .$get("email-notification/get-short-codes")
    .then((response) => {
      shortCodes.value = response.data.shortcodes;
      templates.value = response.data.email_templates;
      buttons.value = Object.keys(response.data.buttons).map((key) => ({
        [key]: response.data.buttons[key],
      }));
    })
    .catch((error) => {})
    .finally(() => {
      loading.value = false;
    });
};

const addNotification = (event) => {
  if (event) event.preventDefault();
  validationErrors.value = {};
  loading.value = true;
  selfRef
    .$post("email-notification/create", notification.value)
    .then((response) => {
      selfRef.handleSuccess(response);
    })
    .catch((errors) => {
      if (errors.status_code == '422') {
        Notify.validationErrors(errors);
        validationErrors.value = errors.data;
      } else {
        Notify.error(errors.data?.message);
      }
    })
    .finally(() => {
      loading.value = false;
    });
};

const showPreview = () => {
  isModalVisible.value = true;
  htmlContent.value = notification.value.content;
};

onMounted(() => {
  getShortCodes();
});

const onEditorReady = (editorInstance) => {
  editor = editorInstance;
};

const clearValidationError = (fieldKey) => {
  if (validationErrors.value && validationErrors.value.hasOwnProperty(fieldKey)) {
    delete validationErrors.value[fieldKey];
  }
}

const selectToEmail = () => {
  notification.value.to = selectedToEmail.value;
};

const selectFromEmail = () => {
  notification.value.from = selectedFromEmail.value;
};
</script>

<template>
  <Card>
    <CardHeader
      :title="notification.title ? notification.title : translate('Add Notification')"
      title_size="small"
      border_bottom
      icon="Message"
    >
      <template #action>
        <!-- Since order_placed notifications should always be enabled for offline payments, we don't show the switch -->
        <!-- The switch is not relevant for our use case since these notifications auto-trigger for offline payments -->
      </template>
    </CardHeader>
    <CardBody>
      <fct-dialog v-model="isModalVisible" :title="$t('Preview')">
        <div v-html="htmlContent"></div>
      </fct-dialog>
      <div>
        <fct-input
          v-model="notification.title"
          @focus="clearValidationError('title')"
          :label="$t('Notification Name')"
          required
          :validation-errors="validationErrors"
          field-key="title"
        />

        <fct-select
          :multiple="true"
          v-model="notification.events"
          :placeholder="$t('Select Events')"
          @focus="clearValidationError('events')"
          clearable
          :options="availableEvents"
          :label="$t('Select notification event')"
          required
          :validation-errors="validationErrors"
          field-key="events"
        />

        <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
          <fct-input
            v-model="notification.from"
            @focus="clearValidationError('from')"
            :placeholder="$t('Select a Field or Type Custom Value')"
            :label="$t('Mail From:')"
            required
            :validation-errors="validationErrors"
            field-key="from"
          >
            <template #append>
              <fct-select
                v-model="selectedFromEmail"
                @change="selectFromEmail()"
                clearable
                :options="emailShortCodes"
              />
            </template>
          </fct-input>

          <fct-input
            v-model="notification.to"
            @focus="clearValidationError('to')"
            :label="$t('Send Mail To:')"
            required
            :validation-errors="validationErrors"
            field-key="to"
          >
            <template #append>
              <fct-select
                v-model="selectedToEmail"
                @change="selectToEmail()"
                clearable
                :options="emailShortCodes"
              />
            </template>
          </fct-input>
        </div>

        <fct-input v-model="notification.cc" :label="$t('Cc:')"/>

        <fct-input
          v-model="notification.subject"
          @focus="clearValidationError('subject')"
          :label="$t('Mail Subject:')"
          required
          :validation-errors="validationErrors"
          field-key="subject"
        />

        <div>
          <WpEditor
            ref="wpEditor"
            :short-codes="shortCodes"
            :buttons="buttons"
            @on-editor-ready="onEditorReady"
            @update="
              (value) => {
                notification.content = value;
              }
            "
            v-model="notification.content"
          >
            <template #action>
              <div class="fct-select-template-wrap">
                <fct-select
                  @change="
                    (html) => {
                      wpEditor?.setContent(html);
                    }
                  "
                  :placeholder="$t('Select button')"
                  clearable
                  :options="Object.values(buttons).map((button) => ({label: Object.keys(button)[0], value: Object.values(button)[0]}))"
                />
              </div>

              <div class="fct-select-template-wrap flex gap-2 flex-row">
                <fct-select
                  @change="
                    (template) => {
                      getTemplate(template);
                    }
                  "
                  :placeholder="$t('Select template')"
                  clearable
                  :options="Object.values(templates).map((template) => ({label: template.label, value: template.path}))"
                />

                <span>
                  <fct-tooltip
                    :content="$t('Reset Template')"
                    placement="top"
                  >
                    <IconButton @click.prevent="resetTemplate" tag="button">
                      <DynamicIcon name="Refresh" />
                    </IconButton>
                  </fct-tooltip>
                </span>
              </div>
            </template>
            <template #preview_button>
              <fct-tooltip
                v-if="editor !== null"
                :content="$t('Preview Template')"
                placement="top"
              >
                <IconButton @click.prevent="showPreview" tag="button">
                  <DynamicIcon name="Eye" />
                </IconButton>
              </fct-tooltip>
            </template>
          </WpEditor>
        </div>
        <div class="setting-save-action">
          <fct-button
            @click="addNotification"
            type="primary"
            :disabled="loading"
            :loading="loading"
          >
            {{ $t("Save Notification") }}
          </fct-button>
        </div>
      </div>
    </CardBody>
  </Card>
</template>
