<script setup>
import {computed, onMounted, onUnmounted, ref} from 'vue'
import translate from "@/utils/translator/Translator"
import Theme from "@/utils/Theme"
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin"
import Rest from "@/utils/http/Rest"
import Asset from "@/utils/support/Asset"
import AppConfig from "@/utils/Config/AppConfig"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import OnboardingStoreInfoStep from "@/Modules/Onboarding/OnboardingStoreInfoStep.vue"
import OnboardingTaxStep from "@/Modules/Onboarding/OnboardingTaxStep.vue"
import OnboardingPagesStep from "@/Modules/Onboarding/OnboardingPagesStep.vue"
import OnboardingProductsStep from "@/Modules/Onboarding/OnboardingProductsStep.vue"

// ─── Bootstrap data (fetched once, distributed to steps as props) ─────────────
const loading            = ref(false)
const pages              = ref([])
const currencies         = ref({})
const countries          = ref([])
const dummyProductInfo   = ref([])
const initialSettings    = ref(null)
const initialTaxSettings = ref(null)

// Snapshots of each step's in-progress form data, preserved across step switches
const savedStoreFormData = ref(null)
const savedTaxFormData   = ref(null)
const savedPagesFormData = ref(null)

// Shared across steps 1 & 2 — country set in step 1, read by step 2
const storeCountry = ref('')
const countryName  = computed(() => {
    if (!storeCountry.value) return ''
    const found = countries.value.find(c => c.value === storeCountry.value)
    return found ? found.name : storeCountry.value
})

// ─── Step registry ────────────────────────────────────────────────────────────
const steps = [
    {
        key:       'store-info',
        title:     () => translate('Welcome aboard! Let\'s create your online store.'),
        text:      () => translate('You\'ve successfully installed the plugin, let\'s configure the store.'),
        component: OnboardingStoreInfoStep,
        props: () => ({
            savedFormData:    savedStoreFormData.value,
            initialSettings:  initialSettings.value,
            countries:        countries.value,
            currencies:       currencies.value,
            onStoreCountryChanged: (val) => { storeCountry.value = val },
        }),
    },
    {
        key:      'tax',
        title:    () => translate('Set Up Tax Collection'),
        text:     () => translate('Configure tax rates so checkout calculates correctly.'),
        component: OnboardingTaxStep,
        canSkip:  true,
        props: () => ({
            savedFormData:       savedTaxFormData.value,
            storeCountry:        storeCountry.value,
            countryName:         countryName.value,
            initialTaxSettings:  initialTaxSettings.value,
        }),
    },
    {
        key:       'pages',
        title:     () => translate('Setup Your Pages'),
        text:      () => translate('Create the essential pages your store needs: cart, checkout, and more.'),
        component: OnboardingPagesStep,
        props: () => ({
            pages:           pages.value,
            initialSettings: initialSettings.value,
            savedFormData:   savedPagesFormData.value,
            onPagesUpdated:  (updated) => { pages.value = updated },
        }),
    },
    {
        key:     'products',
        title:   () => translate('Setup Your Products'),
        text:    () => translate('Add your first products and start building your business catalog.'),
        component: OnboardingProductsStep,
        isFinal: true,
        props: () => ({
            dummyProductInfo: dummyProductInfo.value,
        }),
    },
]

// Steps 1–N are input steps; N+1 is the done state (nothing rendered).
const maxStep     = steps.length + 1
const activeStep  = ref(1)
const currentStep = computed(() => steps[activeStep.value - 1])
const headerTitle = computed(() => currentStep.value?.title() ?? '')
const headerText  = computed(() => currentStep.value?.text() ?? '')

// ─── Navigation ───────────────────────────────────────────────────────────────
const stepRef     = ref(null)
const stepLoading = ref(false)

const advanceStep = () => { activeStep.value++ }

// True while the final step is inserting dummy products — hides the bottom bar
const stepInserting = computed(() => !!stepRef.value?.inserting)

const snapshotCurrentStep = () => {
    const snapshot = stepRef.value?.getFormData?.()
    if (!snapshot) return
    if (currentStep.value.key === 'store-info') savedStoreFormData.value = snapshot
    else if (currentStep.value.key === 'tax') savedTaxFormData.value = snapshot
    else if (currentStep.value.key === 'pages') savedPagesFormData.value = snapshot
}

const handleNext = async () => {
    if (stepLoading.value) return
    stepLoading.value = true
    try {
        snapshotCurrentStep()
        const result = await stepRef.value?.onNextStep?.()
        if (result !== false) advanceStep()
    } finally {
        stepLoading.value = false
    }
}

const handlePrev = () => {
    snapshotCurrentStep()
    stepRef.value?.onPreviousStep?.()
    if (activeStep.value > 1) activeStep.value--
}

const handleSkip = () => { advanceStep() }

const handleSkipAll = () => {
    stepRef.value?.onSkipAll?.()
    window.location.href = AppConfig.get('dashboard_url')
}

const saveShortcut = useSaveShortcut()
saveShortcut.onSave(() => {
    if (currentStep.value?.isFinal) handleNext()
})

// ─── Initial data load ────────────────────────────────────────────────────────
const loadError = ref(false)

const getSettings = () => {
    loading.value = true
    loadError.value = false
    Rest.get('onboarding/')
        .then(response => {
            pages.value              = response.pages
            currencies.value         = response.currencies
            initialSettings.value    = response.default_settings
            storeCountry.value       = response.default_settings?.store_country ?? ''
            initialTaxSettings.value = response.tax_settings ?? null
        })
        .catch(() => { loadError.value = true })
        .finally(() => { loading.value = false })
}

const fetchCountries = () => {
    Rest.get('address-info/countries')
        .then(response => { countries.value = response.data })
}

// ─── Theme / lifecycle ────────────────────────────────────────────────────────
const darkLogo  = Asset.getUrl('images/logo/logo-full-dark.svg')
const lightLogo = Asset.getUrl('images/logo/logo-full.svg')
const logo      = ref(AppConfig.get('asset_url') + '/images/logo/logo-full-dark.svg')

const onThemeChanged = () => { logo.value = Theme.isDark() ? lightLogo : darkLogo }

onMounted(() => {
    window.addEventListener('onFluentCartThemeChange', onThemeChanged)
    dummyProductInfo.value = AppConfig.get('dummy_product_info')
    jQuery('.fca_admin_menu').hide()
    jQuery('body').addClass('fct-onboarding-body')
    logo.value = Theme.isDark() ? lightLogo : darkLogo
    getSettings()
    fetchCountries()
})

onUnmounted(() => {
    window.removeEventListener('onFluentCartThemeChange', onThemeChanged)
    jQuery('.fca_admin_menu').show()
    jQuery('body').removeClass('fct-onboarding-body')
})

const setStep = (step) => { activeStep.value = step || 0 }
</script>

<template>
  <div class="fct-onboarding-wrap">
    <div v-if="activeStep < maxStep" class="fct-onboarding-card">
      <div class="fct-onboarding-card-body" v-loading="loading">

        <div class="fct-onboarding-content-box">
          <div class="fct-onboarding-card-head">
            <div class="fct-onboarding-card-head-top">
              <img :src="logo" :alt="translate('Fluent Cart')">
              <div class="fct-onboarding-steps-count">
                {{
                  /* translators: %1$s - current step number, %2$s - total steps */
                  translate('Step %1$s of %2$s', activeStep, steps.length)
                }}
              </div>
              <el-progress
                  :percentage="(activeStep / steps.length) * 100"
                  :stroke-width="8"
                  :show-text="false"
                  class="fct-onboarding-progress" />
            </div>
            
            <div class="fct-onboarding-card-head-content">
                <div class="flex-1">
                    <h2 class="fct-onboarding-card-title">
                        {{ headerTitle }}
                    </h2>
                    <p class="fct-onboarding-card-text">
                        {{ headerText }}
                    </p>
                </div>

                <div
                    v-if="currentStep.key === 'pages' && stepRef && !stepRef.pageGenerated"
                    class="fct-onboarding-card-head-action"
                >
                    <el-button @click="stepRef.createAllPage()" :loading="stepRef.generating" size="small">
                        <DynamicIcon v-if="!stepRef.generating" name="MagicPen" />
                        {{ translate('Generate All Pages') }}
                    </el-button>
                </div>
            </div>
          </div><!-- .fct-onboarding-card-head -->

          <div class="fct-onboarding-content-box-inner">
            <div v-if="loadError" class="fct-onboarding-load-error">
              <p>{{ translate('Failed to load settings. Please refresh and try again.') }}</p>
              <el-button @click="getSettings">{{ translate('Retry') }}</el-button>
            </div>
            <div v-else class="fct-onboarding-form-wrap active">
              <div class="fct-onboarding-form-body">
                <component
                    :is="currentStep.component"
                    :key="currentStep.key"
                    ref="stepRef"
                    v-bind="currentStep.props()"
                />
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Hidden while the products step inserts — its own progress bar takes over -->
  <div v-if="activeStep < maxStep && !stepInserting" class="fct-onboarding-bottom">
    <div class="fct-onboarding-bottom-left">
      <el-button v-if="activeStep > 1" text @click="handleSkipAll">
        {{ translate('Skip All') }}
      </el-button>
    </div>

    <div class="fct-onboarding-bottom-right">
      <el-button @click="handlePrev" type="info" plain :disabled="activeStep === 1">
        {{ translate('Go Back') }}
      </el-button>

      <el-button v-if="currentStep.canSkip" text @click="handleSkip" :disabled="stepLoading">
        {{ translate('Skip for now') }}
      </el-button>

      <el-button
          v-if="!currentStep.isFinal"
          type="primary"
          :loading="stepLoading"
          :disabled="loadError"
          @click="handleNext"
      >
        {{ translate('Continue') }}
      </el-button>
      <el-button
          v-else
          type="primary"
          :loading="stepLoading"
          @click="handleNext"
      >
        <span v-if="!stepLoading" class="cmd">⌘s</span>
        {{ stepLoading ? translate('Saving') : translate('Save') }}
      </el-button>
    </div>
  </div>
</template>
