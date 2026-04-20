<script setup>
import {onMounted, ref} from "vue";
import {useRouter} from "vue-router";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Card from "@/Bits/Components/Card/Card.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import AppConfig from "@/utils/Config/AppConfig";

const router = useRouter();
const loading = ref(true);
const hasFluentPdf = ref(false);
const templates = ref({});

// Add template dialog
const addDialogVisible = ref(false);
const newTemplateName = ref('');
const creating = ref(false);

// Delete
const deleting = ref('');

// Addon install/activate
const addonInfo = ref(null);
const installing = ref(false);
const activating = ref(false);
const hasPro = AppConfig.get('app_config.isProActive');

const fetchTemplates = () => {
    loading.value = true;
    Rest.get('settings/pdf-templates/receipt')
        .then((response) => {
            templates.value = response.templates || {};
            hasFluentPdf.value = response.has_fluent_pdf || false;
            addonInfo.value = response.addon_info || null;
        })
        .catch((error) => {
            Notify.error(error.data?.message || 'Failed to load templates');
        })
        .finally(() => {
            loading.value = false;
        });
};

const installAddon = () => {
    if (!addonInfo.value) return;

    if (!hasPro && addonInfo.value.repo_link) {
        window.open(addonInfo.value.repo_link, '_blank', 'noopener,noreferrer');
        return;
    }

    installing.value = true;
    Rest.post('settings/modules/plugin-addons/install', {
        plugin_slug: addonInfo.value.plugin_slug,
        source_type: addonInfo.value.source_type || 'wordpress',
        source_link: addonInfo.value.source_link || '',
        asset_path: addonInfo.value.asset_path || ''
    })
        .then((response) => {
            Notify.success(response.message || translate('Plugin installed successfully'));
            setTimeout(() => { window.location.reload(); }, 500);
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to install plugin'));
        })
        .finally(() => {
            installing.value = false;
        });
};

const activateAddon = () => {
    if (!addonInfo.value) return;

    activating.value = true;
    Rest.post('settings/modules/plugin-addons/activate', {
        plugin_file: addonInfo.value.plugin_file
    })
        .then((response) => {
            Notify.success(response.message || translate('Plugin activated successfully'));
            setTimeout(() => { window.location.reload(); }, 500);
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to activate plugin'));
        })
        .finally(() => {
            activating.value = false;
        });
};

const openAddDialog = () => {
    newTemplateName.value = '';
    addDialogVisible.value = true;
};

const createTemplate = () => {
    if (!newTemplateName.value.trim()) {
        Notify.error(translate('Please enter a template name'));
        return;
    }
    creating.value = true;
    Rest.post('settings/pdf-templates/create', { title: newTemplateName.value.trim() })
        .then((response) => {
            addDialogVisible.value = false;
            Notify.success(response.message || translate('Template created'));
            if (response.slug) {
                router.push({ name: 'edit-pdf-template', params: { template: response.slug } });
            } else {
                fetchTemplates();
            }
        })
        .catch((error) => {
            Notify.error(error.data?.message || 'Failed to create template');
        })
        .finally(() => {
            creating.value = false;
        });
};

const deleteTemplate = (key) => {
    deleting.value = key;
    Rest.delete('settings/pdf-templates/delete/' + key)
        .then((response) => {
            Notify.success(response.message || translate('Template deleted'));
            fetchTemplates();
        })
        .catch((error) => {
            Notify.error(error.data?.message || 'Failed to delete template');
        })
        .finally(() => {
            deleting.value = '';
        });
};

onMounted(() => {
    fetchTemplates();
});
</script>

<template>
    <div class="setting-wrap">
        <SettingsHeader :heading="translate('PDF Templates')" :show-save-button="false">
            <template #action v-if="hasFluentPdf">
                <el-button type="primary" size="small" @click="openAddDialog">
                    {{ translate('Add Template') }}
                </el-button>
            </template>
        </SettingsHeader>

        <div class="setting-wrap-inner">
            <template v-if="loading">
                <Card>
                    <CardBody>
                        <el-skeleton animated>
                            <template #template>
                                <el-skeleton-item variant="text" class="w-[300px] mb-4"/>
                                <div class="flex gap-5">
                                    <el-skeleton-item variant="rect" style="width: 220px; height: 280px; border-radius: 8px;"/>
                                    <el-skeleton-item variant="rect" style="width: 220px; height: 280px; border-radius: 8px;"/>
                                </div>
                            </template>
                        </el-skeleton>
                    </CardBody>
                </Card>
            </template>

            <!-- Fluent PDF not installed or not active -->
            <template v-if="!loading && !hasFluentPdf && addonInfo">
                <Card>
                    <CardBody>
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            <svg class="mb-4" width="48" height="48" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M8 2C6.9 2 6 2.9 6 4v24c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V10l-8-8H8z"/>
                                <path d="M18 2v8h8"/>
                                <path d="M12 18h8M12 22h6"/>
                            </svg>
                            <h3 class="text-lg font-semibold mb-2 text-system-dark dark:text-gray-200">
                                {{ translate('Fluent PDF Required') }}
                            </h3>
                            <p class="text-sm text-system-mid dark:text-gray-400 mb-6 max-w-md">
                                {{ translate('To use PDF receipt templates, you need to install and activate the Fluent PDF plugin.') }}
                            </p>
                            <el-button
                                v-if="!addonInfo.is_installed"
                                type="primary"
                                :loading="installing"
                                @click="installAddon"
                            >
                                {{ hasPro ? translate('Install & Activate') : translate('Download') }}
                            </el-button>
                            <el-button
                                v-else-if="addonInfo.is_installed && !addonInfo.is_active"
                                type="success"
                                :loading="activating"
                                @click="activateAddon"
                            >
                                {{ translate('Activate Plugin') }}
                            </el-button>
                        </div>
                    </CardBody>
                </Card>
            </template>

            <!-- Templates list -->
            <template v-if="!loading && hasFluentPdf">
                <Card>
                    <CardBody>
                        <p class="fct-pdf-template-desc">
                            {{ translate('Customize PDF templates for email attachments. Select which template to attach in each email notification\'s settings.') }}
                        </p>
                        <div class="fct-pdf-template-grid">
                            <div
                                v-for="(template, key) in templates"
                                :key="key"
                                class="fct-pdf-template-card"
                            >
                                <div class="fct-pdf-template-preview">
                                    <div class="fct-pdf-template-preview-placeholder">
                                        <svg width="40" height="40" viewBox="0 0 32 32" fill="none" stroke="#525866" stroke-width="1.2">
                                            <path d="M8 2C6.9 2 6 2.9 6 4v24c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V10l-8-8H8z"/>
                                            <path d="M18 2v8h8"/>
                                            <path d="M12 18h8M12 22h6"/>
                                        </svg>
                                        <span class="fct-pdf-placeholder-text">{{ template.title || translate('Receipt Template') }}</span>
                                    </div>
                                    <div class="fct-pdf-template-overlay">
                                        <el-button type="primary" size="small" tag="router-link" :to="{ name: 'edit-pdf-template', params: { template: key } }">
                                            {{ translate('Edit Template') }}
                                        </el-button>
                                    </div>
                                </div>
                                <div class="fct-pdf-template-footer">
                                    <div class="fct-pdf-template-name-row">
                                        <span class="fct-pdf-template-title">{{ template.title || key }}</span>
                                        <span v-if="template.is_default" class="fct-pdf-badge fct-pdf-badge--default">
                                            {{ translate('Built-in') }}
                                        </span>
                                    </div>
                                    <el-popconfirm
                                        v-if="!template.is_default"
                                        :title="translate('Delete this template?')"
                                        :confirm-button-text="translate('Delete')"
                                        :cancel-button-text="translate('Cancel')"
                                        confirm-button-type="danger"
                                        @confirm="deleteTemplate(key)"
                                    >
                                        <template #reference>
                                            <el-button
                                                type="danger"
                                                size="small"
                                                text
                                                :loading="deleting === key"
                                                class="fct-pdf-delete-btn"
                                            >
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                                </svg>
                                            </el-button>
                                        </template>
                                    </el-popconfirm>
                                </div>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            </template>
        </div>

        <!-- Add Template Dialog -->
        <el-dialog
            v-model="addDialogVisible"
            :title="translate('Add Custom Template')"
            width="420px"
            :close-on-click-modal="false"
            :append-to-body="true"
        >
            <el-form @submit.prevent="createTemplate" label-position="top">
                <el-form-item :label="translate('Template Name')">
                    <el-input
                        v-model="newTemplateName"
                        :placeholder="translate('e.g. Custom Invoice')"
                        autofocus
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="addDialogVisible = false">{{ translate('Cancel') }}</el-button>
                <el-button type="primary" @click="createTemplate" :loading="creating">
                    {{ translate('Create') }}
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>

