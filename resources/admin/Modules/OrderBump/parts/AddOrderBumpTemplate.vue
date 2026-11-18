<script setup>
import {ref} from "vue";
import {useRouter} from "vue-router";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const router = useRouter();

const showDialog = ref(false);
const creating = ref(false);
const title = ref('');

const createTemplate = () => {
    if (creating.value) {
        return;
    }

    if (!title.value.trim()) {
        Notify.error(translate('Please enter a name for the card.'));
        return;
    }

    creating.value = true;

    Rest.post('order_bump/templates', {title: title.value.trim()})
        .then((response) => {
            Notify.success(response.message);
            showDialog.value = false;
            router.push({name: 'order_bump_template_edit', params: {template: response.key}});
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to create the card'));
        })
        .finally(() => {
            creating.value = false;
        });
};
</script>

<template>
    <fct-button type="primary" @click="showDialog = true">
        {{ translate('Add Template') }}
    </fct-button>

    <fct-dialog
        v-model="showDialog"
        :title="translate('Add Custom Template')"
        :append-to-body="true"
        :close-on-click-modal="!creating"
        :close-on-press-escape="!creating"
        :show-close="!creating"
        @close="title = ''"
    >
        <fct-input
            v-model="title"
            :label="translate('Template Name')"
            :placeholder="translate('e.g. Black Friday offer')"
            required
            maxlength="100"
            @keyup.enter="createTemplate"
        />

        <template #footer>
            <div class="dialog-footer">
                <fct-button :disabled="creating" @click="showDialog = false">
                    {{ translate('Cancel') }}
                </fct-button>
                <fct-button type="primary" :loading="creating" @click="createTemplate">
                    {{ creating ? translate('Creating') : translate('Create') }}
                </fct-button>
            </div>
        </template>
    </fct-dialog>
</template>
