<script setup>
import Card from "@/Bits/Components/Card/Card.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import {onMounted, ref, watch, nextTick} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Popover from "@/Modules/Settings/Parts/input-popover-dropdown.vue";
import {ArrowRight} from "@element-plus/icons-vue";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import NewEditorFrame from "@/Bits/Components/BlockEditorFrame.vue";
import EmailBodySkeleton from "@/Bits/Components/EmailBodySkeleton.vue";
import Alert from "../../../../public/customer-profile/Vue/parts/Alert.vue";
import AppConfig from "@/utils/Config/AppConfig";
import ProVersionGuard from "@/Bits/Components/ProVersionGuard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const isProActive = AppConfig.get('app_config.isProActive');

const props = defineProps({
    name: {
        type: String,
        required: true
    }
});

const loading = ref(false);
const saving = ref(false);
const name = ref(props.name);
const notificationData = ref({
    title: '',
    description: '',
    event: '',
    name: '',
    recipient: '',
    smartcode_groups: [],
    template_path: '',
    is_async: false,
    settings: {
        active: '',
        is_default_body: 'yes',
        email_body: '',
        subject: '',
    }
});
const editorFrameRef = ref(null);
const shortCodes = ref({});
const focusSubjectInput = ref(false);

// Preview
const previewVisible = ref(false);
const previewLoading = ref(false);
const previewHtml = ref('');
const previewMode = ref('desktop');
const previewIframe = ref(null);

const onPreviewRequest = () => {
    previewVisible.value = true;
    previewLoading.value = true;
    previewHtml.value = '';

    Rest.post('email-notification/preview', {
        notification_name: name.value,
    })
        .then((response) => {
            previewHtml.value = response.html;
            previewLoading.value = false;
            nextTick(() => loadPreviewIframe());
        })
        .catch((error) => {
            Notify.error(error.data?.message || 'Failed to generate preview');
            previewVisible.value = false;
            previewLoading.value = false;
        });
};

const loadPreviewIframe = () => {
    const iframe = previewIframe.value;
    if (!iframe || !previewHtml.value) return;

    const doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
    if (!doc) return;

    doc.open();
    doc.write(previewHtml.value);
    doc.close();
};

const onPreviewDrawerOpen = () => {
    nextTick(() => {
        if (previewHtml.value) {
            loadPreviewIframe();
        }
    });
};

const closePreview = () => {
    previewVisible.value = false;
    previewHtml.value = '';
    previewMode.value = 'desktop';
};

// Default template inline preview
const defaultTemplateHtml = ref('');
const defaultTemplateLoading = ref(false);

const loadDefaultTemplate = () => {
    const templatePath = notificationData.value?.template_path;
    if (!templatePath || defaultTemplateHtml.value) return;

    defaultTemplateLoading.value = true;
    Rest.post("email-notification/preview-default-template", { template: templatePath })
        .then((response) => {
            defaultTemplateHtml.value = response.data?.content || '';
        })
        .catch(() => {})
        .finally(() => {
            defaultTemplateLoading.value = false;
        });
};

watch(() => notificationData.value.settings.is_default_body, (val) => {
    if (val === 'yes' && !defaultTemplateHtml.value) {
        loadDefaultTemplate();
    }
});

const onShortCodeSelected = (code) => {
    notificationData.value.settings.subject += code;
};

const getNotification = () => {
    loading.value = true;
    Rest.get("email-notification/" + name.value)
        .then((response) => {
            notificationData.value = response.data;
            shortCodes.value = response.shortcodes;
            if (notificationData.value.settings.is_default_body === 'yes') {
                loadDefaultTemplate();
            }
        })
        .catch((error) => {
            if (error.status_code == '422') {
                Notify.validationErrors(error);
            } else {
                Notify.error(error.data?.message);
            }
        })
        .finally(() => {
            loading.value = false;
        });
};

const updateNotification = () => {
    saving.value = true;

    const payload = JSON.parse(JSON.stringify(notificationData.value));

    if (payload.settings.is_default_body === 'no') {
        if (editorFrameRef.value) {
            editorFrameRef.value.forceAutosave();
        }
    }

    Rest.put("email-notification/" + name.value, payload)
        .then((response) => {
            Notify.success(response.message);
        })
        .catch((errors) => {
            if (errors.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors.data?.message);
            }
        })
        .finally(() => {
            saving.value = false;
        });
}

onMounted(() => {
    getNotification();
});

</script>

<template>
    <div class="setting-wrap fct-edit-email-notification-wrapper">
      <SettingsHeader
          :loading="saving"
          :loading-text="translate('Updating')"
          :save-button-text="translate('Update')"
          @onSave="updateNotification"
      >
        <template #heading>
          <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
            <el-breadcrumb-item :to="{ path: '/settings/email_notifications' }">
              {{ $t("Email Notifications") }}
            </el-breadcrumb-item>
            <el-breadcrumb-item>
              <span class="capitalize">{{ notificationData.title }}</span>
            </el-breadcrumb-item>
          </el-breadcrumb>
        </template>
      </SettingsHeader>

      <div class="setting-wrap-inner">
        <template v-if="loading">
          <el-skeleton animated>
            <template #template>
              <div class="mb-5">
                <div class="setting-switcher flex items-center gap-3">
                  <el-skeleton-item variant="text" style="width: 40px; height: 20px; border-radius: 10px;"/>
                  <span class="text-sm text-system-dark dark:text-gray-50">{{ translate('Enable this email notification!') }}</span>
                </div>
              </div>
              <div class="fct-card p-6">
                <div class="mb-5">
                  <label class="el-form-item__label block mb-2">{{ translate('Email Subject') }}</label>
                  <el-skeleton-item variant="text" style="width: 100%; height: 36px; border-radius: 4px; display: block;"/>
                </div>
                <div class="mb-5">
                  <label class="el-form-item__label block mb-2">{{ translate('Email Body Type') }}</label>
                  <el-skeleton-item variant="text" style="width: 260px; height: 34px; border-radius: 6px; display: block;"/>
                </div>
                <div>
                  <label class="el-form-item__label block mb-2">{{ translate('Email Body') }}</label>
                  <EmailBodySkeleton />
                </div>
              </div>
            </template>
          </el-skeleton>
        </template>

        <template v-if="!loading">
          <div class="mb-5">
            <!-- Only show enable/disable switch for notifications that are not order_placed_admin or order_placed_customer -->
            <div v-if="notificationData?.manage_toggle !== 'no'" class="setting-switcher">
              <el-switch
                  v-model="notificationData.settings.active"
                  active-value="yes"
                  inactive-value="no"
                  :active-text="translate('Enable this email notification!')"
              ></el-switch>
            </div>

            <!-- Show a text indicator for order_placed notifications -->
            <div v-else class="setting-switcher text-system-mid text-sm dark:text-gray-300">
              {{ translate('Auto-enabled for offline payments') }}
            </div>
          </div>

          <Card>
            <CardBody>
              <el-form label-position="top">
                <el-form-item :label="translate('Email Subject')">
                  <el-input v-model="notificationData.settings.subject"
                            autocomplete="rutjfkde"
                            :class="{ 'is-focus': focusSubjectInput }" @focus="focusSubjectInput = true"
                            @blur="focusSubjectInput = false" :label="translate('Email Subject')">
                    <template #append>
                      <Popover
                          :data="shortCodes"
                          @command="onShortCodeSelected"
                          btnType="info"
                          btnSize="small"
                          plain
                          placement="bottom"
                      >
                        {{ translate("Add ShortCodes") }}
                      </Popover>
                    </template>
                  </el-input>
                </el-form-item>
                <el-form-item :label="translate('Email Body Type')">
                  <div class="fct-seg-wrap">
                    <button
                        type="button"
                        class="fct-seg-btn"
                        :class="{ active: notificationData.settings.is_default_body === 'yes' }"
                        @click="notificationData.settings.is_default_body = 'yes'"
                    >
                      <svg class="fct-seg-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg>
                      {{ translate('Default Body') }}
                    </button>
                    <button
                        type="button"
                        class="fct-seg-btn"
                        :class="{ active: notificationData.settings.is_default_body === 'no' }"
                        @click="notificationData.settings.is_default_body = 'no'"
                    >
                      <svg class="fct-seg-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                      {{ translate('Customized Body') }}
                      <DynamicIcon v-if="!isProActive" name="Crown" class="fct-pro-icon" style="margin-left: 4px; display: inline-flex;" />
                    </button>
                  </div>
                </el-form-item>
                <div v-if="notificationData.settings.is_default_body === 'yes'" class="fct-default-template-preview">
                  <label class="el-form-item__label">{{ translate('Email Body') }}</label>
                  <EmailBodySkeleton v-if="defaultTemplateLoading" />
                  <div v-else-if="defaultTemplateHtml" class="fct-default-template-frame">
                    <Alert
                        type="warning"
                        class="mb-3"
                        show-icon
                        :description="translate('The preview shown here use sample data. Actual emails will be populated with real information.')"
                    />
                    <iframe
                        :srcdoc="defaultTemplateHtml"
                        frameborder="0"
                        scrolling="auto"
                        style="width: 100%; height: 600px; border: 1px solid #e4e7ed; border-radius: 6px; display: block;"
                    ></iframe>
                  </div>
                </div>
                <template v-if="notificationData.settings.is_default_body === 'no' && !loading">
                  <ProVersionGuard
                      min-version="1.3.15"
                      :feature-title="translate('Custom Email Templates')"
                      :feature-text="translate('Design beautiful email templates with a drag-and-drop block editor, shortcodes, and conditional content.')"
                  >
                    <el-form-item :label="translate('Email Body')">
                      <Alert
                          class="mb-3"
                          type="warning"
                          show-icon
                          :description="translate('Shortcodes/Blocks shown here use sample data. Actual emails will be populated with real information.')"
                      />
                      <NewEditorFrame
                          ref="editorFrameRef"
                          :editorParams="{ block_type: 'template', campaign_title: notificationData.settings.subject, bid: notificationData.name }"
                          :frameHeight="'calc(100vh - 60px)'"
                          :documentTitle="notificationData.settings.subject"
                          @titleUpdated="((title) => { notificationData.settings.subject = title })"
                          @previewRequest="onPreviewRequest"
                          v-model="notificationData.settings.email_body"
                      />
                    </el-form-item>
                  </ProVersionGuard>
                </template>
              </el-form>
            </CardBody>
          </Card>
        </template>

        <div class="setting-save-action">
          <el-button
              @click="updateNotification"
              type="primary"
              :disabled="saving"
              :loading="saving"
          >
            {{ saving ? translate('Updating') : translate("Update") }}
          </el-button>
        </div>
      </div>

      <!-- Email Preview Drawer -->
      <el-drawer
          v-model="previewVisible"
          direction="rtl"
          size="70%"
          :title="translate('Email Preview')"
          :append-to-body="true"
          :close-on-click-modal="true"
          @open="onPreviewDrawerOpen"
      >
        <div v-if="previewLoading" v-loading="true" style="height: 300px;"></div>

        <template v-if="!previewLoading && previewHtml">
          <div class="fct-preview-toolbar">
            <div class="fct-preview-device-toggle">
              <button
                  type="button"
                  :class="['fct-device-btn', { active: previewMode === 'desktop' }]"
                  @click="previewMode = 'desktop'"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
                <span>{{ translate('Desktop') }}</span>
              </button>
              <button
                  type="button"
                  :class="['fct-device-btn', { active: previewMode === 'tablet' }]"
                  @click="previewMode = 'tablet'"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/></svg>
                <span>{{ translate('Tablet') }}</span>
              </button>
              <button
                  type="button"
                  :class="['fct-device-btn', { active: previewMode === 'mobile' }]"
                  @click="previewMode = 'mobile'"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>
                <span>{{ translate('Mobile') }}</span>
              </button>
            </div>
          </div>



          <Alert
              class="mb-3"
              type="warning"
              show-icon
              :description="translate('The preview shown here uses sample data. Actual emails will be populated with real information.')"
          />

          <div class="fct-preview-stage" :class="'fct-preview-stage--' + previewMode">
            <div :class="['fct-device-frame', 'fct-device-frame--' + previewMode]">
              <iframe
                  ref="previewIframe"
                  frameborder="0"
                  scrolling="auto"
                  style="width: 100%; height: 80vh; border: none; display: block;"
              ></iframe>
            </div>
          </div>
        </template>

        <template #footer>
          <el-button @click="closePreview">{{ translate('Close') }}</el-button>
        </template>
      </el-drawer>
    </div>
</template>
