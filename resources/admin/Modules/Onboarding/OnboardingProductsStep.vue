<script setup>
import {computed, ref} from 'vue'
import translate from "@/utils/translator/Translator"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import {CircleCheckFilled} from '@element-plus/icons-vue'
import Rest from "@/utils/http/Rest"
import Url from "@/utils/support/Url"
import AppConfig from "@/utils/Config/AppConfig"

const props = defineProps({
    dummyProductInfo: {type: Object, default: () => ({})},
})

const selectedCategory = ref('start-from-scratch')

// ─── Insertion progress ───────────────────────────────────────────────────────
const inserting          = ref(false)
const running            = ref(false)
const currentInfo        = ref(null)
const lastRequestedIndex = ref(0)
const percentage         = ref(0)

// Indices that did not insert — a partial import is never reported as complete
const failedIndexes = ref([])
const aborted       = ref(false)

const hasFailures = computed(() => aborted.value || failedIndexes.value.length > 0)

const resetProgress = () => {
    percentage.value = 0
    lastRequestedIndex.value = 0
    inserting.value = false
    running.value = false
    currentInfo.value = null
    failedIndexes.value = []
    aborted.value = false
}

// Inserts the catalog in order, recording every index that failed so progress
// tracks real successes. A 400 stops the run; the untouched remainder counts
// as failed too. The endpoint is not idempotent, so nothing is ever resent.
const insertIndexes = async (info) => {
    let completed = 0

    for (let index = 0; index < info.count; index++) {
        lastRequestedIndex.value = index

        try {
            await Rest.post('products/create-dummy', {category: info.category, index})
            completed++
            percentage.value = parseFloat(((completed / info.count) * 100).toFixed(0))
        } catch (errors) {
            if (errors.status_code === 400) {
                aborted.value = true
                for (let rest = index; rest < info.count; rest++) {
                    failedIndexes.value.push(rest)
                }
                return
            }
            failedIndexes.value.push(index)
        }
    }
}

const createDummyProduct = async () => {
    if (!selectedCategory.value || selectedCategory.value === 'start-from-scratch') {
        resetProgress()
        return
    }

    const info = props.dummyProductInfo[selectedCategory.value]
    if (!info) {
        resetProgress()
        return
    }

    failedIndexes.value = []
    aborted.value = false
    inserting.value = true
    running.value = true
    currentInfo.value = info

    try {
        await insertIndexes(info)
    } finally {
        running.value = false
    }
}

// Only a clean run reaches 100% and redirects; otherwise the error state stays put
const finishInsertion = () => {
    if (hasFailures.value) return
    percentage.value = 100
    setTimeout(redirectToDashboard, 1000)
}

const redirectToDashboard = () => {
    // Base on dashboard_url, never the current URL — appending to the current
    // '#/onboarding' route would keep the user on the onboarding page
    let target = AppConfig.get('dashboard_url')
    if (selectedCategory.value && selectedCategory.value !== 'start-from-scratch') {
        target = Url.appendToVueUrl(target, {import_product: selectedCategory.value})
    }
    window.location.href = target
}

// ─── Step protocol ────────────────────────────────────────────────────────────

const onNextStep = async () => {
    const isScratch = !selectedCategory.value || selectedCategory.value === 'start-from-scratch'

    try {
        // Deliberately no `category` — the onboarding endpoint answers that key
        // by dispatching an async createAll() of the whole catalog, which would
        // duplicate the per-index inserts below and write products this bar
        // cannot see. This step owns the import; the endpoint only saves settings.
        await Rest.post('onboarding/')
    } catch (e) {
        // Proceed regardless
    }

    // "Start from scratch" inserts nothing — no progress bar, straight to the dashboard
    if (isScratch) {
        setTimeout(redirectToDashboard, 300)
        return false
    }

    // Otherwise the radio group is swapped for the progress bar until insertion finishes
    await createDummyProduct()
    finishInsertion()
    return false  // navigation is handled by the redirect above (or the error state)
}

const dashboardUrl = AppConfig.get('dashboard_url')

// `inserting` is read by the parent to hide the onboarding bottom bar while products insert
defineExpose({onNextStep, inserting})
</script>

<template>
    <!-- Failure state — never redirects on its own, the merchant chooses -->
    <div v-if="inserting && hasFailures && !running" class="fct-dummy-product-content">
        <div v-if="currentInfo != null" class="title">
            {{
                /* translators: %1$s - number of products that failed, %2$s - total product number */
                translate('%1$s of %2$s products could not be created', failedIndexes.length, currentInfo.count)
            }}
        </div>
        <p class="fct-dummy-product-hint">
            {{
                aborted
                    ? translate('The import stopped early. Products created before it stopped were kept, and you can add the rest from the Products page.')
                    : translate('The products that were created have been kept. You can add the rest from the Products page.')
            }}
        </p>
        <div class="fct-btn-group mt-4">
            <el-button type="primary" @click="redirectToDashboard">
                {{ translate('Continue to Dashboard') }}
            </el-button>
        </div>
    </div>

    <div v-else-if="inserting" class="fct-dummy-product-content">
        <div v-if="currentInfo != null" class="title">
            {{
                /* translators: %1$s - current product number, %2$s - total product number, %3$s - product category name */
                translate('Inserting %1$s of %2$s %3$s Product', lastRequestedIndex + 1, currentInfo.count, currentInfo.title)
            }}
        </div>
        <div class="text">
            {{ translate('Please wait, your products are being added.') }}
            <div class="fct-dummy-product-loading">
                <div class="fct-loading-bars">
                    <div v-for="i in 8" :key="i" class="bar-block" :id="`bar-block-${i + 1}`"></div>
                </div>
                {{
                    /* translators: %1$s: upload progress percentage, e.g. "45%" */
                    translate('Uploading... %1$s', `${percentage}%`)
                }}
            </div>
        </div>
        <el-progress :percentage="percentage" striped-flow :stroke-width="6" striped :show-text="false"/>

        <p class="fct-dummy-product-hint">
            {{ translate("Please keep this window open, we'll take you to your") }}
            <a :href="dashboardUrl" target="_blank" rel="noopener">{{ translate('Dashboard') }}</a>
            {{ translate('as soon as your products are ready.') }}
        </p>
    </div>

    <div v-else class="fct-form-group">
        <el-radio-group
            class="fct-import-onboarding-products"
            v-model="selectedCategory"
            size="large"
        >
            <el-radio-button value="start-from-scratch">
                <DynamicIcon name="Scratch" />
                <span class="label">{{ translate('Start') }} <br/>
                    {{ translate('From scratch') }}
                </span>
                <span class="marker">
                    <el-icon><CircleCheckFilled /></el-icon>
                </span>
            </el-radio-button>
            <el-radio-button v-for="(info, key) in dummyProductInfo" :key="key" :value="key">
                <DynamicIcon :name="info.icon" />
                <span class="label">{{ info.title }}</span>
                <span class="marker">
                    <el-icon><CircleCheckFilled /></el-icon>
                </span>
            </el-radio-button>
        </el-radio-group>
    </div>
</template>
