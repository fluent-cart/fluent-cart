<script setup>
import {computed, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    bump: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['changed']);

const saving = ref(false);
const isActive = computed(() => props.bump.status === 'active');

// The switch moves only when this resolves true, so it never shows a state that was not saved.
const save = () => {
    const status = isActive.value ? 'draft' : 'active';
    saving.value = true;

    return Rest.put('order_bump/' + props.bump.id + '/status', {status})
        .then((response) => {
            Notify.success(response.message);
            emit('changed', status);
            return true;
        })
        .catch((errors) => {
            Notify.error(errors?.data?.message || errors?.message || translate('Failed to update order bump'));
            return false;
        })
        .finally(() => {
            saving.value = false;
        });
};
</script>

<template>
    <fct-switch
        :model-value="bump.status"
        active-value="active"
        inactive-value="draft"
        :loading="saving"
        :before-change="save"
        :aria-label="translate('Enable %s', bump.title)"
    />
</template>
