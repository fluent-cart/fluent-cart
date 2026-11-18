<script setup>
import {computed, onMounted, ref} from "vue";
import {useRouter} from "vue-router";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import NewEditorFrame from "@/Bits/Components/BlockEditorFrame.vue";
import SettingsPage from "../Parts/SettingsPage.vue";
import {ArrowRight, MoreFilled} from "@element-plus/icons-vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const props = defineProps({
    template: {
        type: String,
        required: true
    }
});

const router = useRouter();
const loading = ref(true);
const saving = ref(false);
const downloading = ref(false);
const previewing = ref(false);
const templateData = ref(null);
const editorFrameRef = ref(null);
const pdfContent = ref('');

const decodeBase64Pdf = (base64Pdf) => {
    const byteCharacters = atob(base64Pdf);
    const byteNumbers = new Array(byteCharacters.length);

    for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
    }

    return new Blob([new Uint8Array(byteNumbers)], {type: 'application/pdf'});
};

const requestTestPdf = async () => {
    const response = await Rest.post('settings/pdf-templates/download', {template_id: props.template});

    if (!response?.pdf_base64) {
        throw new Error(translate('Failed to generate PDF'));
    }

    return response;
};

const fetchTemplate = () => {
    loading.value = true;
    Rest.get('settings/pdf-templates/receipt/' + props.template)
        .then((response) => {
            templateData.value = response.template;
            const pdfStructure = response.template?.pdf_settings?.[0]?.pdf_structure;
            if (pdfStructure) {
                pdfContent.value = pdfStructure.content || '';
            }
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to load template'));
        })
        .finally(() => {
            loading.value = false;
        });
};

const saveTemplate = async ({ showSuccess = true } = {}) => {
    saving.value = true;

    if (editorFrameRef.value) {
        await editorFrameRef.value.forceAutosave();
    }

    const pdfStructure = templateData.value?.pdf_settings?.[0]?.pdf_structure || {};
    pdfStructure.content = pdfContent.value;

    try {
        const response = await Rest.post('settings/pdf-templates/receipt/' + props.template, {
            pdf_structure: pdfStructure
        });

        if (showSuccess) {
            Notify.success(response.message || translate('Template saved'));
        }

        return true;
    } catch (error) {
        Notify.error(error.data?.message || translate('Failed to save template'));
        return false;
    } finally {
        saving.value = false;
    }
};

const downloadTestPdf = () => {
    downloading.value = true;

    requestTestPdf()
        .then((response) => {
            const blob = decodeBase64Pdf(response.pdf_base64);
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');

            a.href = url;
            a.download = response.filename || 'receipt-test.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            Notify.success(translate('PDF downloaded'));
        })
        .catch((error) => {
            Notify.error(error.data?.message || error.message || translate('Failed to generate PDF'));
        })
        .finally(() => {
            downloading.value = false;
        });
};

const openPreviewPdf = async () => {
    previewing.value = true;

    try {
        const response = await requestTestPdf();
        const blob = decodeBase64Pdf(response.pdf_base64);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        setTimeout(() => {
            URL.revokeObjectURL(url);
        }, 10000);
    } catch (error) {
        Notify.error(error.data?.message || error.message || translate('Failed to generate PDF'));
    } finally {
        previewing.value = false;
    }
};

const onPreviewRequest = async () => {
    if (saving.value || downloading.value || previewing.value) {
        return;
    }

    const saved = await saveTemplate({ showSuccess: false });
    if (!saved) {
        return;
    }

    await openPreviewPdf();
};

const isCustomTemplate = () => {
    return templateData.value && !templateData.value.is_default;
};

const deleteTemplate = () => {
    Rest.delete('settings/pdf-templates/delete/' + props.template)
        .then((response) => {
            Notify.success(response.message || translate('Template deleted'));
            router.push({ path: '/settings/pdf-template' });
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to delete template'));
        });
};

const revertToDefault = () => {
    Rest.get('settings/pdf-templates/factory-default')
        .then((response) => {
            const defaultTemplate = response.templates?.[props.template];
            if (!defaultTemplate) {
                return;
            }

            const pdfStructure = defaultTemplate.pdf_settings?.[0]?.pdf_structure || {};

            return Rest.post('settings/pdf-templates/receipt/' + props.template, {
                pdf_structure: pdfStructure
            }).then(() => {
                Notify.success(translate('Reverted to default template'));
                fetchTemplate();
            });
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to revert template'));
        });
};

const actionItems = computed(() => [
    {command: 'download', label: downloading.value ? translate('Downloading...') : translate('Test Download'), disabled: downloading.value},
    {command: 'revert', label: translate('Revert to Default')},
    ...(isCustomTemplate() ? [{command: 'delete', label: translate('Delete Template'), divided: true, danger: true}] : []),
]);

const onAction = (command) => {
    ({download: downloadTestPdf, revert: revertToDefault, delete: deleteTemplate})[command]();
};

onMounted(() => {
    fetchTemplate();
});
</script>

<template>
    <SettingsPage
        class="fct-pdf-template-edit-wrapper"
        :loading="saving"
        :save-button-text="translate('Save Template')"
        @on-save="saveTemplate"
    >
        <template #heading>
            <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="{ path: '/settings/pdf-template' }">
                    {{ translate("PDF Templates") }}
                </el-breadcrumb-item>
                <el-breadcrumb-item>
                    <span class="capitalize">{{ templateData?.title || translate('Edit Template') }}</span>
                </el-breadcrumb-item>
            </el-breadcrumb>
        </template>

        <template #action>
            <fct-dropdown trigger="click" style="margin-left: 12px; display: inline-flex; align-items: center;" :items="actionItems" @command="onAction">
                <fct-button size="small" text style="height: auto; padding: 4px;">
                    <el-icon style="transform: rotate(90deg); font-size: 16px;"><MoreFilled /></el-icon>
                </fct-button>
            </fct-dropdown>
        </template>

        <AdminNotice/>

        <fct-skeleton
            v-if="loading"
            class="bg-white rounded p-6 dark:bg-dark-700"
            animated
        >
            <fct-skeleton variant="p" class="w-[200px] h-[18px] mb-5"/>
            <fct-skeleton variant="p" style="width: 100%; height: 500px;"/>
        </fct-skeleton>

        <template v-if="!loading && templateData">
            <div class="bg-white rounded dark:bg-dark-700 p-6">
                <NewEditorFrame
                    ref="editorFrameRef"
                    :editorParams="{ block_type: 'pdf_template', campaign_title: templateData.title, bid: props.template }"
                    :frameHeight="'calc(100vh - 200px)'"
                    :documentTitle="templateData.title || translate('Receipt Template')"
                    @preview-request="onPreviewRequest"
                    v-model="pdfContent"
                />
            </div>

            <div class="setting-save-action">
                <fct-button
                    @click="saveTemplate"
                    type="primary"
                    :disabled="saving"
                    :loading="saving"
                >
                    {{ translate("Save Template") }}
                </fct-button>
                <fct-button
                    @click="downloadTestPdf"
                    :disabled="downloading"
                    :loading="downloading"
                >
                    {{ translate("Test Download") }}
                </fct-button>
            </div>
        </template>
    </SettingsPage>
</template>
