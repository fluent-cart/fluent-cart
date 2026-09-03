<script setup>
import {ref, watch} from 'vue'
import translate from "@/utils/translator/Translator"
import PageSelector from "@/Modules/Onboarding/PageSelector.vue"
import Rest from "@/utils/http/Rest"

const props = defineProps({
    pages:           {type: Array,    default: () => []},
    initialSettings: {type: Object,   default: null},
    savedFormData:   {type: Object,   default: null},
    onPagesUpdated:  {type: Function, default: () => {}},
})

const PAGE_KEYS = [
    'checkout_page_id',
    'cart_page_id',
    'receipt_page_id',
    'shop_page_id',
    'customer_profile_page_id',
]

const PAGE_LABELS = {
    checkout_page_id:         () => translate('Checkout'),
    cart_page_id:             () => translate('Cart'),
    receipt_page_id:          () => translate('Receipt'),
    shop_page_id:             () => translate('Shop'),
    customer_profile_page_id: () => translate('Customer Profile'),
}

const pageIds = ref(Object.fromEntries(PAGE_KEYS.map(k => [k, ''])))

watch(() => props.initialSettings, (settings) => {
    if (props.savedFormData) {
        PAGE_KEYS.forEach(key => { pageIds.value[key] = props.savedFormData[key] ?? '' })
        return
    }
    if (!settings) return
    PAGE_KEYS.forEach(key => { pageIds.value[key] = settings[key] ?? '' })
}, {immediate: true})

const pageGenerated = ref(false)
const generating    = ref(false)

const createAllPage = () => {
    generating.value = true
    Rest.post('onboarding/create-pages', {})
        .then(response => {
            PAGE_KEYS.forEach(key => {
                pageIds.value[key] = String(response.default_settings?.[key] ?? '')
            })
            pageGenerated.value = true
            props.onPagesUpdated(response.pages)
        })
        .finally(() => { generating.value = false })
}

const onPageCreated = (page, modelId) => {
    pageIds.value[modelId] = page.value
    props.onPagesUpdated([...props.pages, page])
}

// ─── Step protocol ────────────────────────────────────────────────────────────

const onNextStep = async () => {
    try {
        await Rest.post('onboarding/', {...pageIds.value})
    } catch (e) {
        // Non-blocking — proceed regardless
    }
    return true
}

const getFormData = () => ({...pageIds.value})

defineExpose({onNextStep, getFormData, createAllPage, pageGenerated, generating})
</script>

<template>
    <div v-for="key in PAGE_KEYS" :key="key" class="fct-form-group">
        <PageSelector
            :title="PAGE_LABELS[key]()"
            :pages="pages"
            :modelId="key"
            v-model="pageIds[key]"
            @on-page-created="onPageCreated"
        />
    </div>
</template>
