<script setup>
import {computed, onMounted, ref, watch} from "vue";
import {useRouter} from "vue-router";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import {$confirm} from "@/Bits/common";
import translate from "@/utils/translator/Translator";
import NewEditorFrame from "@/Bits/Components/BlockEditorFrame.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import {MoreFilled} from "@element-plus/icons-vue";
import Page from "@/Bits/Components/Layout/Page.vue";

const props = defineProps({
    template: {
        type: String,
        required: true
    }
});

const loading = ref(true);
const saving = ref(false);
const reverting = ref(false);
const deleting = ref(false);
const router = useRouter();
const templateData = ref(null);
const editorFrameRef = ref(null);
const content = ref('');

// Only the newest answer counts when the address switches cards quickly.
let latestLoad = 0;

const fetchTemplate = () => {
    const load = ++latestLoad;
    loading.value = true;
    templateData.value = null;
    content.value = '';

    Rest.get('order_bump/templates/' + props.template)
        .then((response) => {
            if (load !== latestLoad) {
                return;
            }
            templateData.value = response.template;
            content.value = response.template?.content || '';
        })
        .catch((error) => {
            if (load === latestLoad) {
                Notify.error(error.data?.message || translate('Failed to load template'));
            }
        })
        .finally(() => {
            if (load === latestLoad) {
                loading.value = false;
            }
        });
};

// Posts the latest markup: the editor's own answer when it gave one, so the page never sends an older copy.
const postTemplate = (answer) => {
    const markup = answer && typeof answer.content === 'string' ? answer.content : content.value;

    return Rest.post('order_bump/templates/' + props.template, {content: markup})
        .then((response) => {
            Notify.success(response.message);
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to save template'));
        })
        .finally(() => {
            saving.value = false;
        });
};

const saveTemplate = () => {
    // Without the loaded card there is nothing to save; posting the empty copy would wipe it.
    if (loading.value || !templateData.value || saving.value || reverting.value) {
        return;
    }

    saving.value = true;

    // The editor saves first and answers with its latest content, so the POST comes after and never competes with it.
    // Without that answer the page copy may be stale, so the save fails instead of posting it.
    if (editorFrameRef.value) {
        return editorFrameRef.value.forceAutosave({requireAck: true}).then((answer) => {
            if (!answer.acknowledged) {
                saving.value = false;
                Notify.error(translate('The editor did not answer. Try saving again.'));
                return;
            }
            return postTemplate(answer);
        });
    }

    return postTemplate();
};

const revertToDefault = () => {
    if (saving.value || reverting.value || deleting.value) {
        return;
    }

    reverting.value = true;
    Rest.post('order_bump/templates/' + props.template + '/reset')
        .then((response) => {
            Notify.success(response.message);
            fetchTemplate();
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to revert template'));
        })
        .finally(() => {
            reverting.value = false;
        });
};

// A custom card can go. The built-in cards stay, so they only have Revert.
const deleteTemplate = () => {
    if (saving.value || reverting.value || deleting.value) {
        return;
    }

    $confirm(translate('Delete "%s"? This cannot be undone.', templateData.value?.title || ''), translate('Confirm Delete!'), {
        confirmButtonText: translate('Yes, Delete!'),
        cancelButtonText: translate('Cancel'),
        type: 'warning',
    }).then(() => {
        deleting.value = true;

        return Rest.delete('order_bump/templates/' + props.template)
            .then((response) => {
                Notify.success(response.message);
                router.push({name: 'order_bump_design'});
            })
            .catch((error) => {
                Notify.error(error.data?.message || translate('Failed to delete'));
            })
            .finally(() => {
                deleting.value = false;
            });
    }).catch(() => {});
};

const menuItems = computed(() => {
    const busy = saving.value || reverting.value || deleting.value;
    const items = [{command: 'revert', label: translate('Revert to Default'), disabled: busy}];

    if (templateData.value?.is_custom) {
        items.push({command: 'delete', label: translate('Delete Template'), danger: true, disabled: busy});
    }

    return items;
});

const onMenuCommand = (command) => {
    if (command === 'revert') {
        revertToDefault();
    } else if (command === 'delete') {
        deleteTemplate();
    }
};

onMounted(() => {
    fetchTemplate();
});

// The same page is reused when the address switches to the other card.
watch(() => props.template, fetchTemplate);
</script>

<template>
    <Page class="fct-order-bump-template-edit" header="bar"
          :breadcrumbs="[{label: translate('Order Bumps'), to: {name: 'order_bump'}}, {label: translate('Design'), to: {name: 'order_bump_design'}}]">
        <template #title>
            <span class="capitalize">{{ templateData?.title || translate('Edit Template') }}</span>
        </template>
        <template #actions>
            <fct-button type="primary" size="small" :loading="saving" :disabled="saving || loading || reverting || deleting || !templateData" @click="saveTemplate">
                {{ translate('Save Template') }}
            </fct-button>
            <fct-dropdown trigger="click"
                          :items="menuItems"
                          @command="onMenuCommand">
                <fct-button size="small" text class="fct-order-bump-template-more" :aria-label="translate('More actions')">
                    <el-icon style="transform: rotate(90deg); font-size: 16px;"><MoreFilled /></el-icon>
                </fct-button>
            </fct-dropdown>
        </template>

        <div class="fct-order-bump-edit-body">
            <AdminNotice/>

            <fct-skeleton v-if="loading" class="bg-white rounded p-6 dark:bg-dark-700" animated>
                <fct-skeleton variant="h3" class="w-[200px] mb-5"/>
                <fct-skeleton variant="rect" style="width: 100%; height: 500px;"/>
            </fct-skeleton>

            <template v-if="!loading && templateData">
                <div class="bg-white rounded dark:bg-dark-700 p-6">
                    <NewEditorFrame
                        ref="editorFrameRef"
                        :editorParams="{ block_type: 'order_bump_template', campaign_title: templateData.title, bid: props.template }"
                        :frameHeight="'calc(100vh - 200px)'"
                        :documentTitle="templateData.title || translate('Order Bump Card')"
                        v-model="content"
                    />
                </div>
            </template>
        </div>
    </Page>
</template>
