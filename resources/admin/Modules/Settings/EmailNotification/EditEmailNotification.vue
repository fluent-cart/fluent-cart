<script setup>
import Section from "@/Bits/Components/Layout/Section.vue";
import {onMounted, ref, watch, nextTick} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Popover from "@/Modules/Settings/Parts/input-popover-dropdown.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import SettingsPage from "../Parts/SettingsPage.vue";
import NewEditorFrame from "@/Bits/Components/BlockEditorFrame.vue";
import EmailBodySkeleton from "@/Bits/Components/EmailBodySkeleton.vue";
import Alert from "../../../../public/customer-profile/Vue/parts/Alert.vue";
import AppConfig from "@/utils/Config/AppConfig";
import ProVersionGuard from "@/Bits/Components/ProVersionGuard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import DynamicFormLoader from "@/Bits/Components/DynamicTemplates/DynamicFormLoader.vue";

const isProActive = AppConfig.get('app_config.isProActive');

const props = defineProps({
    name: {
        type: String,
        required: true
    }
});

const loading = ref(true);
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
        attach_pdf_template: '',
    }
});
const editorFrameRef = ref(null);
const shortCodes = ref({});
const extraForms = ref([]);
const extraFormRefs = ref();

// Normalize declared extra_fields into a render-ready list, merging saved values
// over the declared defaults.
const buildExtraForms = (extraFields, saved = {}) => {
    if (!extraFields || typeof extraFields !== 'object') return [];

    const entries = extraFields.schema
        ? [[extraFields.form_name || 'form', extraFields]]
        : Object.entries(extraFields);

    return entries
        .filter(([, form]) => form?.schema && Object.keys(form.schema).length)
        .map(([formKey, form]) => ({
            ...form,
            form_key: formKey,
            values: { ...(form.values || {}), ...(saved[formKey] || {}) },
        }));
};

// Collect each loader's state into { [notificationName]: { form_key: values } }, or null.
const collectExtraState = () => {
    const loaders = [].concat(extraFormRefs.value || []);
    const forms = loaders.reduce(
        (acc, loader) => (typeof loader?.getState === 'function' ? { ...acc, ...loader.getState() } : acc),
        {}
    );
    return Object.keys(forms).length ? { [name.value]: forms } : null;
};
const focusSubjectInput = ref(false);
const hasFluentPdf = ref(false);
const pdfTemplates = ref([]);

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
        block_content: notificationData.value.settings.email_body || '',
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
            hasFluentPdf.value = response.has_fluent_pdf || false;
            pdfTemplates.value = response.pdf_templates || [];

            // Build the dynamic forms; saved values live at settings.extra[name][form_key].
            const saved = notificationData.value.settings.extra?.[name.value] || {};
            extraForms.value = buildExtraForms(notificationData.value.extra_fields, saved);

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

    // Persist the dynamic forms under settings.extra[name][form_key].
    const extra = collectExtraState();
    if (extra) {
        payload.settings.extra = extra;
    }

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
    <SettingsPage
        class="fct-edit-email-notification-wrapper"
        :breadcrumbs="[{label: translate('Email Notifications'), to: {path: '/settings/email_notifications'}}]"
        :loading="saving"
        :save-button-text="translate('Update')"
        @on-save="updateNotification"
    >
        <template #heading>
          <span class="capitalize">{{ notificationData.title }}</span>
        </template>

        <AdminNotice/>
        
        <template v-if="loading">
          <fct-skeleton animated>
            <div class="mb-5">
              <div class="setting-switcher flex items-center gap-3">
                <fct-skeleton variant="text" style="width: 40px; height: 20px; border-radius: 10px;"/>
                <span class="text-sm text-system-dark dark:text-gray-50">{{ translate('Enable this email notification!') }}</span>
              </div>
            </div>
            <div class="fct-card p-6">
              <div class="mb-5">
                <label class="el-form-item__label block mb-2">{{ translate('Email Subject') }}</label>
                <fct-skeleton variant="text" style="width: 100%; height: 36px; border-radius: 4px; display: block;"/>
              </div>
              <div class="mb-5">
                <label class="el-form-item__label block mb-2">{{ translate('Email Body Type') }}</label>
                <fct-skeleton variant="text" style="width: 260px; height: 34px; border-radius: 6px; display: block;"/>
              </div>
              <div>
                <label class="el-form-item__label block mb-2">{{ translate('Email Body') }}</label>
                <EmailBodySkeleton />
              </div>
            </div>
          </fct-skeleton>
        </template>

        <template v-if="!loading">
          <div>
            <!-- manage_toggle 'no' notifications are always on; show their label instead of a switch -->
            <div v-if="notificationData?.manage_toggle !== 'no'" class="setting-switcher">
              <fct-switch
                  v-model="notificationData.settings.active"
                  active-value="yes"
                  inactive-value="no"
                  :active-text="translate('Enable this email notification!')"
              />
            </div>

            <div v-else class="setting-switcher text-system-mid text-sm dark:text-gray-300">
              {{ notificationData?.toggle_label || translate('Auto-enabled for offline payments') }}
            </div>
          </div>

          <Section>
              <div>
                  <fct-input v-model="notificationData.settings.subject"
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
                  </fct-input>
                <Field :label="translate('Email Body Type')">
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
                </Field>
                <div v-if="notificationData.settings.is_default_body === 'yes'" class="fct-default-template-preview">
                  <label class="el-form-item__label">{{ translate('Email Body') }}</label>
                  <EmailBodySkeleton v-if="defaultTemplateLoading" />
                  <div v-else-if="defaultTemplateHtml" class="fct-default-template-frame">
                    <Alert
                        type="warning"
                        class="mb-3"
                        show-icon
                        :description="translate('Settings_Preview_Shown')"
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
                      placement="feature_lock_email_templates"
                      :feature-title="translate('Custom Email Templates')"
                      :feature-text="translate('Settings_Design_Beautiful')"
                  >
                    <Field :label="translate('Email Body')">
                      <Alert
                          class="mb-3"
                          type="warning"
                          show-icon
                          :description="translate('Settings_Shortcodes_Blocks')"
                      />
                      <NewEditorFrame
                          ref="editorFrameRef"
                          :editorParams="{ block_type: 'template', campaign_title: notificationData.settings.subject, bid: notificationData.name }"
                          :frameHeight="'calc(100vh - 60px)'"
                          :documentTitle="notificationData.settings.subject"
                          @title-updated="((title) => { notificationData.settings.subject = title })"
                          @preview-request="onPreviewRequest"
                          v-model="notificationData.settings.email_body"
                      />
                    </Field>
                  </ProVersionGuard>
                </template>
              </div>
          </Section>

          <!-- Dynamic forms declared by the notification (`extra_fields`). -->
          <Section v-for="form in extraForms" :key="form.form_key">
              <h3 v-if="form.title" class="el-form-item__label">{{ form.title }}</h3>
              <p v-if="form.sub_title" class="text-system-mid mb-3">{{ form.sub_title }}</p>
              <DynamicFormLoader
                  :schema="form.schema"
                  :values="form.values"
                  :form_name="form.form_key"
                  ref="extraFormRefs"
              />
          </Section>
        </template>

          <Section v-if="hasFluentPdf && pdfTemplates.length">
              <fct-select
                  v-model="notificationData.settings.attach_pdf_template"
                  :placeholder="translate('No PDF attachment')"
                  clearable
                  style="width: 100%; max-width: 360px;"
                  :options="pdfTemplates.map((tmpl) => ({label: tmpl.title, value: tmpl.id}))"
                  :label="translate('Attach PDF Template')"
              />
          </Section>

        <div class="setting-save-action">
          <fct-button
              @click="updateNotification"
              type="primary"
              :disabled="saving"
              :loading="saving"
          >
            {{ translate("Update") }}
          </fct-button>
        </div>

      <!-- Email Preview Drawer -->
      <fct-dialog
          drawer
          v-model="previewVisible"
          width="70%"
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
              :description="translate('Settings_Preview_Shown_Uses')"
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
          <fct-button @click="closePreview">{{ translate('Close') }}</fct-button>
        </template>
      </fct-dialog>
    </SettingsPage>
</template>
