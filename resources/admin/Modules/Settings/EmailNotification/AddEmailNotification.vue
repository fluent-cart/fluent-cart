<script setup>
import Card from "@/Bits/Components/Card/Card.vue";
import CardHeader from "@/Bits/Components/Card/CardHeader.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import { getCurrentInstance, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { useSaveShortcut } from "@/mixin/saveButtonShortcutMixin.js";
import Notify from "@/utils/Notify";

const selfRef = getCurrentInstance().ctx;
const wpEditor = ref();
const loading = ref(true);
const isModalVisible = ref(false);
const notification = ref({});
const htmlContent = ref();
const availableEvents = ref([
  // Define event options
  { label: "After Order Created", value: "fluent_cart/order_created" },
  { label: "After Order Updated", value: "fluent_cart/order_updated" },
  { label: "After Payment Paid", value: "fluent_cart/order_paid" },
  { label: "After User Created", value: "fluent_cart/user_created" },
  { label: "After Refund made", value: "fluent_cart/order_refunded" },
]);
const shortCodes = ref({});
const emailShortCodes = ref([
  { label: "Admin Email", value: "{wp.admin_email}" },
  { label: "Customer Email", value: "{order.customer.email}" },
  { label: "User Email", value: "{user.user_email}" },
]);
const emailTemplateEditor = ref(true);
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
    addNotification(event);
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

const showErrors = (fieldKey) => {};

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
  event.preventDefault();
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

const showPreviewModal = ref(false);
let previewContent = "";

const setPreviewContent = () => {
  if (hasWpEditor.value) {
    previewContent = wp.editor.getContent(props.editor_id);
    return;
  }
  previewContent = plainContent.value;
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
      :title="notification.title ? notification.title : 'Add Notification'"
      title_size="small"
      border_bottom
      icon="Message"
    >
      <template #action>
        <el-switch
          active-value="yes"
          inactive-value="no"
          v-model="notification.enabled"
          :active-text="
            notification.enabled == 'yes' ? $t('Enabled') : $t('Enable')
          "
        >
        </el-switch>
      </template>
    </CardHeader>
    <CardBody>
      <el-dialog v-model="isModalVisible" :title="$t('Preview')">
        <div v-html="htmlContent"></div>
      </el-dialog>
      <el-form label-position="top" require-asterisk-position="right">
        <el-form-item :label="$t('Notification Name')" required>
          <el-input
            v-model="notification.title"
            @focus="clearValidationError('title')"
          />
          <validation-error
            :validation-errors="validationErrors?.title?.required"
          />
        </el-form-item>

        <el-form-item :label="$t('Select notification event')" required>
          <el-select
            :multiple="true"
            v-model="notification.events"
            :placeholder="$t('Select Events')"
            @focus="clearValidationError('events')"
            clearable
          >
            <el-option
              v-for="option in availableEvents"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            ></el-option>
          </el-select>
          <validation-error
            :validation-errors="validationErrors?.events?.required"
          />
        </el-form-item>

        <el-row :gutter="15">
          <el-col :lg="12">
            <el-form-item :label="$t('Mail From:')" required>
              <el-input
                v-model="notification.from"
                @focus="clearValidationError('from')"
                :placeholder="$t('Select a Field or Type Custom Value')"
              >
                <template #append>
                  <el-select
                    v-model="selectedFromEmail"
                    @change="selectFromEmail()"
                    clearable
                  >
                    <el-option
                      v-for="option in emailShortCodes"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </template>
              </el-input>

              <validation-error
                :validation-errors="validationErrors?.from?.required"
              />
            </el-form-item>
          </el-col>

          <el-col :lg="12">
            <el-form-item :label="$t('Send Mail To:')" required>
              <el-input
                v-model="notification.to"
                @focus="clearValidationError('to')"
              >
                <template #append>
                  <el-select
                    v-model="selectedToEmail"
                    @change="selectToEmail()"
                    clearable
                  >
                    <el-option
                      v-for="option in emailShortCodes"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </template>
              </el-input>
              <validation-error
                :validation-errors="validationErrors?.to?.required"
              />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item :label="$t('Cc:')">
          <el-input v-model="notification.cc" />
        </el-form-item>

        <el-form-item :label="$t('Mail Subject:')" required>
          <el-input
            v-model="notification.subject"
            @focus="clearValidationError('subject')"
          />
          <validation-error
            :validation-errors="validationErrors?.subject?.required"
          />
        </el-form-item>

        <div>
          <wp-editor
            ref="wpEditor"
            :short-codes="shortCodes"
            :buttons="buttons"
            @onEditorReady="onEditorReady"
            @update="
              (value) => {
                notification.content = value;
              }
            "
            v-model="notification.content"
          >
            <template v-slot:action>
              <div class="fct-select-template-wrap">
                <el-select
                  @change="
                    (html) => {
                      wpEditor?.setContent(html);
                    }
                  "
                  :placeholder="$t('Select button')"
                  clearable
                >
                  <el-option
                    v-for="(button, index) in buttons"
                    :key="index"
                    :label="Object.keys(button)[0]"
                    :value="Object.values(button)[0]"
                  ></el-option>
                </el-select>
              </div>

              <div class="fct-select-template-wrap flex gap-2 flex-row">
                <el-select
                  @change="
                    (template) => {
                      getTemplate(template);
                    }
                  "
                  :placeholder="$t('Select template')"
                  clearable
                >
                  <el-option
                    v-for="(template, index) in templates"
                    :key="index"
                    :label="template.label"
                    :value="template.path"
                  ></el-option>
                </el-select>

                <span>
                  <el-tooltip
                    effect="dark"
                    :content="$t('Reset Template')"
                    placement="top"
                    popper-class="fct-tooltip"
                  >
                    <IconButton @click.prevent="resetTemplate" tag="button">
                      <DynamicIcon name="Refresh" />
                    </IconButton>
                  </el-tooltip>
                </span>
              </div>
            </template>
            <template v-slot:preview_button>
              <el-tooltip
                v-if="editor !== null"
                effect="dark"
                :content="$t('Preview Template')"
                placement="top"
                popper-class="fct-tooltip"
              >
                <IconButton @click.prevent="showPreview" tag="button">
                  <DynamicIcon name="Eye" />
                </IconButton>
              </el-tooltip>
            </template>
          </wp-editor>
        </div>
        <div class="setting-save-action">
          <el-button
            @click="addNotification"
            type="primary"
            :disabled="loading"
            :loading="loading"
          >
            {{ $t("Save Notification") }}
          </el-button>
        </div>
      </el-form>
    </CardBody>
  </Card>
</template>
