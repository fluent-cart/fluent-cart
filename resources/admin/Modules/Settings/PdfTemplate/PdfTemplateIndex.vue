<script setup>
import {nextTick, onBeforeUnmount, onMounted, reactive, ref} from "vue";
import {useRouter} from "vue-router";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import AppConfig from "@/utils/Config/AppConfig";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import {$confirm} from "@/Bits/common";

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

// Reset
const resetting = ref('');

// A long title is cut off with an ellipsis, and only then does its tooltip show the full title.
// The titles are measured before any hover, so the tooltip is already on when the pointer arrives.
const truncated = reactive({});
const titleElements = {};

const setTitleElement = (key, element) => {
    if (element) {
        titleElements[key] = element;
    } else {
        delete titleElements[key];
    }
};

const measureTitles = () => {
    Object.keys(titleElements).forEach((key) => {
        truncated[key] = titleElements[key].scrollWidth > titleElements[key].clientWidth;
    });
};

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
            Notify.error(error.data?.message || translate('Failed to load templates'));
        })
        .finally(() => {
            loading.value = false;
            // The cards are drawn on the next tick, and only then can their titles be measured.
            nextTick(measureTitles);
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
        plugin_slug: addonInfo.value.plugin_slug
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
            Notify.error(error.data?.message || translate('Failed to create template'));
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
            Notify.error(error.data?.message || translate('Failed to delete template'));
        })
        .finally(() => {
            deleting.value = '';
        });
};

// An edited template goes back to the design it started as, the same way the editor's revert does it.
// A built-in template has its own factory design; one the admin added started as the order receipt.
const STARTING_TEMPLATE = 'order_receipt';

const resetTemplate = async (key, template) => {
    if (resetting.value) {
        return;
    }

    const question = template.is_default
        ? translate('Reset "%s" to its built-in design? Your changes to it will be lost.', template.title || key)
        : translate('Reset "%s" to the Order Receipt design? Your changes to it will be lost.', template.title || key);

    try {
        await $confirm(question, translate('Confirm Reset!'), {
            confirmButtonText: translate('Yes, Reset!'),
            cancelButtonText: translate('Cancel'),
            type: 'warning',
        });
    } catch (cancelled) {
        return;
    }

    resetting.value = key;

    try {
        const response = await Rest.get('settings/pdf-templates/factory-default');
        const factory = template.is_default ? response.templates?.[key] : response.templates?.[STARTING_TEMPLATE];

        if (!factory) {
            Notify.error(translate('Failed to revert template'));
            return;
        }

        await Rest.post('settings/pdf-templates/receipt/' + key, {
            pdf_structure: factory.pdf_settings?.[0]?.pdf_structure || {}
        });

        Notify.success(translate('Reverted to default template'));
        fetchTemplates();
    } catch (error) {
        Notify.error(error.data?.message || translate('Failed to revert template'));
    } finally {
        resetting.value = '';
    }
};

onMounted(() => {
    fetchTemplates();
    window.addEventListener('resize', measureTitles);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', measureTitles);
});
</script>

<template>
    <SettingsPage :heading="translate('PDF Templates')" :show-save-button="false">
        <template #action v-if="hasFluentPdf">
            <fct-button type="primary" size="small" @click="openAddDialog">
                {{ translate('Add Template') }}
            </fct-button>
        </template>

        <AdminNotice/>

        <Section v-if="loading" :title="translate('Manage')" :description="translate('Settings_Customize_Pdf')">
            <fct-skeleton animated>
                <!-- One per built-in template, in the grid the real cards use. -->
                <div class="fct-pdf-template-grid">
                    <div v-for="n in 4" :key="n" class="fct-pdf-template-card fct-pdf-template-card--loading">
                        <fct-skeleton variant="p" class="fct-pdf-template-skeleton"/>
                        <div class="fct-pdf-template-footer">
                            <fct-skeleton variant="text" class="w-[120px]"/>
                        </div>
                    </div>
                </div>
            </fct-skeleton>
        </Section>

        <!-- Fluent PDF not installed or not active -->
        <Section v-if="!loading && !hasFluentPdf && addonInfo">
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
                    {{ translate('Settings_Pdf_Receipt') }}
                </p>
                <fct-button
                    v-if="!addonInfo.is_installed"
                    type="primary"
                    :loading="installing"
                    @click="installAddon"
                >
                    {{ hasPro ? translate('Install & Activate') : translate('Download') }}
                </fct-button>
                <fct-button
                    v-else-if="addonInfo.is_installed && !addonInfo.is_active"
                    type="success"
                    :loading="activating"
                    @click="activateAddon"
                >
                    {{ translate('Activate Plugin') }}
                </fct-button>
            </div>
        </Section>

        <!-- Templates list -->
        <Section v-if="!loading && hasFluentPdf" :title="translate('Manage')" :description="translate('Settings_Customize_Pdf')">
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
                    </div>
                    <div class="fct-pdf-template-footer">
                        <div class="fct-pdf-template-name-row">
                            <fct-tooltip :content="template.title || key" :disabled="!truncated[key]">
                                <span :ref="(element) => setTitleElement(key, element)" class="fct-pdf-template-title">{{ template.title || key }}</span>
                            </fct-tooltip>
                            <span
                                v-if="template.is_default || template.is_customized"
                                class="fct-pdf-badge"
                                :class="template.is_customized ? 'fct-pdf-badge--customized' : 'fct-pdf-badge--default'"
                            >
                                {{ template.is_customized ? translate('Customized') : translate('Built-in') }}
                            </span>
                        </div>
                        <div class="fct-pdf-template-actions">
                            <fct-button size="small" :to="{ name: 'edit-pdf-template', params: { template: key } }">
                                {{ translate('Customize') }}
                            </fct-button>
                            <fct-button
                                v-if="template.is_customized"
                                size="small"
                                class="fct-pdf-template-reset"
                                :loading="resetting === key"
                                :disabled="!!resetting"
                                @click="resetTemplate(key, template)"
                            >
                                {{ translate('Reset') }}
                            </fct-button>
                            <span v-if="!template.is_default" class="fct-pdf-template-delete">
                                <fct-popover
                                    confirm
                                    :title="translate('Delete this template?')"
                                    :confirm-button-text="translate('Delete')"
                                    :cancel-button-text="translate('Cancel')"
                                    confirm-button-type="danger"
                                    @confirm="deleteTemplate(key)"
                                >
                                    <template #reference>
                                        <fct-button
                                            size="small"
                                            :loading="deleting === key"
                                            class="fct-pdf-delete-btn"
                                            :aria-label="translate('Delete')"
                                        >
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                            </svg>
                                        </fct-button>
                                    </template>
                                </fct-popover>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </Section>

        <!-- Add Template Dialog -->
        <fct-dialog
            v-model="addDialogVisible"
            :title="translate('Add Custom Template')"
            width="420px"
            :close-on-click-modal="false"
            :append-to-body="true"
        >
            <form @submit.prevent="createTemplate">
                <fct-input
                    v-model="newTemplateName"
                    :label="translate('Template Name')"
                    :placeholder="translate('e.g. Custom Invoice')"
                    autofocus
                />
            </form>
            <template #footer>
                <fct-button @click="addDialogVisible = false">{{ translate('Cancel') }}</fct-button>
                <fct-button type="primary" @click="createTemplate" :loading="creating">
                    {{ translate('Create') }}
                </fct-button>
            </template>
        </fct-dialog>
    </SettingsPage>
</template>

