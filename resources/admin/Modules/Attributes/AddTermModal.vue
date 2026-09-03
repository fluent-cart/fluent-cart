<template>
    <div class="fct-add-term-modal">
        <TermForm
            ref="formRef"
            :term="term"
            :group-type="groupType"
            :show-remove-button="false"
            :validation-errors="validationErrors"
            field-key="terms.0"
            @submit="createTerm"
            @remove="emit('isTermCreatingDone')"
        />

        <div class="dialog-footer">
            <el-button
                :disabled="!hasValidTitle || loading"
                :loading="loading"
                type="primary"
                @click="createTerm"
            >
                {{ translate('Add term') }}
            </el-button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from "vue";
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import { handleSuccess } from "@/Bits/common";
import Notify from "@/utils/Notify";
import TermForm from "./TermForm.vue";

const props = defineProps({
    groupId: { type: [String, Number], required: true },
    group: { type: Object, default: () => ({}) },
    // Pre-seeded title. Used by the advanced-variation editor: when the
    // merchant types an unknown term name in the color / image option-values
    // picker and hits Enter, we open this modal with the typed text already
    // staged so they only need to pick a color or image, not retype the
    // name.
    initialTitle: { type: String, default: '' },
});

const emit = defineEmits(['isTermCreatingDone']);

const loading = ref(false);
const validationErrors = ref({});
const formRef = ref(null);

const groupType = computed(() => {
    const settings = props.group && props.group.settings;
    return settings && settings.type ? settings.type : 'options';
});

const term = ref({
    title: (props.initialTitle || '').trim(),
    settings: { color: '', image: '' },
});

const hasValidTitle = computed(() => (term.value.title || '').trim() !== '');

const createTerm = () => {
    if (!hasValidTitle.value || loading.value) return;
    loading.value = true;
    validationErrors.value = {};

    const payload = {
        terms: [{
            title: term.value.title.trim(),
            settings: {
                color: term.value.settings.color || '',
                image: term.value.settings.image || '',
            },
        }],
    };

    Rest.post('options/attr/group/' + props.groupId + '/terms', payload)
        .then(response => {
            handleSuccess(response.message);
            // The bulk-create endpoint returns response.data as an array of
            // created terms. The single-add consumer (AdvancedVariationConfig
            // termCreatingIsDone) expects a single object — push the first
            // (and only) created term up.
            const created = Array.isArray(response.data) ? response.data[0] : response.data;
            emit('isTermCreatingDone', created);
        }).catch(errors => {
            if (errors?.status_code == 422 && errors.data) {
                // Bulk-create returns keys like `terms.0.title` — pass the
                // raw map straight through. TermForm composes lookups via
                // its `fieldKeyPrefix="terms.0."` prop so the inline error
                // resolves dynamically without any renaming step here.
                validationErrors.value = errors.data;
            } else {
                Notify.error(errors?.data?.message || errors?.message || translate('Failed to add the term.'));
            }
        }).finally(() => {
            loading.value = false;
        });
};
</script>
