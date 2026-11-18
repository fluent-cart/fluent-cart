<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import AdminAlert from "@/Bits/Components/Alert.vue";
import Alert from "../../../../public/customer-profile/Vue/parts/Alert.vue";

const loading = ref(true);
const saving = ref(false);
const validationErrors = ref({});
const storeCountrySet = ref(true);
const storeSettingsUrl = ref('');
// Fluent PDF builds the receipts that carry the e-invoice; addonInfo is set only while it is not active.
const hasFluentPdf = ref(true);
const addonInfo = ref(null);
const settingUpPdf = ref(false);

const form = ref({
    zugferd_enabled: '0',
    zugferd_profile: 'en16931',
    seller_legal_registration_scheme: '',
    seller_contact_name: '',
    seller_contact_email: '',
    seller_contact_phone: '',
    seller_bank_iban: '',
    seller_bank_bic: '',
    seller_bank_account_name: '',
    seller_electronic_address: '',
});

const zugferdProfileOptions = [
    {label: 'EN 16931 (ZUGFeRD)', value: 'en16931'},
];

const icdSchemeOptions = [
    {value: '', label: translate('None')},
    {value: '0002', label: '0002 — SIRENE (France)'},
    {value: '0009', label: '0009 — SIRET (France)'},
    {value: '0021', label: '0021 — SWIFT'},
    {value: '0060', label: '0060 — DUNS'},
    {value: '0088', label: '0088 — EAN / GS1 GLN'},
    {value: '0096', label: '0096 — Danish P number'},
    {value: '0130', label: '0130 — EU Directorates'},
    {value: '0183', label: '0183 — Swiss UID'},
    {value: '0184', label: '0184 — Danish CVR'},
    {value: '0190', label: '0190 — Dutch OIN'},
    {value: '0191', label: '0191 — Dutch KvK'},
    {value: '0192', label: '0192 — Dutch Organisation ID'},
    {value: '0195', label: '0195 — Singapore UEN'},
    {value: '0196', label: '0196 — Icelandic kennitala'},
    {value: '0198', label: '0198 — Danish SE'},
    {value: '0199', label: '0199 — LEI'},
    {value: '0200', label: '0200 — Lithuanian legal entity'},
    {value: '0204', label: '0204 — German Leitweg-ID'},
    {value: '0208', label: '0208 — Belgian CBE / KBO'},
    {value: '0210', label: '0210 — Italian Codice Fiscale'},
    {value: '0211', label: '0211 — Italian Partita IVA'},
    {value: '0213', label: '0213 — Finnish OVT'},
];

const saveShortcut = useSaveShortcut();
saveShortcut.onSave(() => {
    saveSettings();
});

const fetchSettings = () => {
    loading.value = true;
    Rest.get('settings/pdf-templates/seller-details')
        .then((response) => {
            if (response.seller_details) {
                Object.keys(form.value).forEach(key => {
                    if (response.seller_details[key] !== undefined) {
                        form.value[key] = response.seller_details[key];
                    }
                });
            }
            storeCountrySet.value = response.store_country_set !== false;
            storeSettingsUrl.value = response.store_settings_url || '';
            hasFluentPdf.value = response.has_fluent_pdf !== false;
            addonInfo.value = response.addon_info || null;
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to load settings'));
        })
        .finally(() => {
            loading.value = false;
        });
};

const saveSettings = () => {
    saving.value = true;
    validationErrors.value = {};
    Rest.post('settings/pdf-templates/seller-details', form.value)
        .then((response) => {
            Notify.success(response.message || translate('Settings saved'));
        })
        .catch((error) => {
            if (error.status_code === 422 && error.data?.data) {
                validationErrors.value = error.data.data;
            }
            Notify.error(error.data?.message || translate('Failed to save settings'));
        })
        .finally(() => {
            saving.value = false;
        });
};

// Same endpoints as the PDF Templates screen. No reload: this page needs nothing new from Fluent PDF,
// and a reload would drop the unsaved toggle and fields.
const setupFluentPdf = () => {
    const installed = addonInfo.value.is_installed;
    settingUpPdf.value = true;
    Rest.post(installed ? 'settings/modules/plugin-addons/activate' : 'settings/modules/plugin-addons/install', installed
        ? {plugin_file: addonInfo.value.plugin_file}
        : {plugin_slug: addonInfo.value.plugin_slug, source_type: addonInfo.value.source_type || 'wordpress'})
        .then((response) => {
            Notify.success(response.message || translate('Plugin activated successfully'));
            hasFluentPdf.value = true;
            addonInfo.value = null;
            settingUpPdf.value = false;
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to activate plugin'));
            settingUpPdf.value = false;
        });
};

onMounted(() => {
    fetchSettings();
});
</script>

<template>
    <SettingsPage
        :heading="translate('E-Invoice Settings')"
        :loading="saving"
        @on-save="saveSettings"
    >
        <AdminNotice/>

        <Section v-if="loading">
            <fct-skeleton animated>
                <div class="grid gap-3 mb-6">
                    <fct-skeleton variant="p" class="w-[20%]"/>
                    <fct-skeleton variant="p"/>
                </div>
                <div class="grid gap-3 mb-6">
                    <fct-skeleton variant="p" class="w-[20%]"/>
                    <fct-skeleton variant="p"/>
                </div>
                <div class="grid gap-3 mb-6">
                    <fct-skeleton variant="p" class="w-[20%]"/>
                    <fct-skeleton variant="p"/>
                </div>
                <div class="grid gap-3">
                    <fct-skeleton variant="p" class="w-[20%]"/>
                    <fct-skeleton variant="p"/>
                </div>
            </fct-skeleton>
        </Section>

        <template v-if="!loading">
            <div
                v-if="form.zugferd_enabled === '1' && !storeCountrySet"
                class="p-4 rounded-lg [border-width:1px] border-yellow-300 bg-yellow-50 text-yellow-800 text-sm"
            >
                <strong>{{ translate('Store country is not configured.') }}</strong>
                {{ translate('ZUGFeRD XML cannot be embedded in PDFs until your store country is set.') }}
                <a v-if="storeSettingsUrl" :href="storeSettingsUrl" class="underline ms-1">
                    {{ translate('Go to Store Settings') }}
                </a>
            </div>

            <AdminAlert v-if="form.zugferd_enabled === '1' && !hasFluentPdf && addonInfo" type="warning" icon="InformationFill" class="items-center">
                <template #content>
                    <div class="flex flex-1 flex-wrap items-center gap-3">
                        <span class="flex-1 min-w-[240px]">
                            <strong>{{ translate('Fluent PDF is required.') }}</strong>
                            {{ translate('E-invoices are embedded in PDF receipts, and Fluent PDF creates them.') }}
                        </span>
                        <fct-button size="small" :loading="settingUpPdf" @click="setupFluentPdf">
                            {{ addonInfo.is_installed ? translate('Activate') : translate('Install & Activate') }}
                        </fct-button>
                    </div>
                </template>
            </AdminAlert>

            <Section
                :title="translate('E-Invoice Configuration')"
                :description="translate('Settings_Configure_Zugferd')"
                divided
            >
                <template #actions>
                    <fct-switch
                        v-model="form.zugferd_enabled"
                        active-value="1"
                        inactive-value="0"
                    />
                </template>

                <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
                    <fct-select v-model="form.zugferd_profile" :options="zugferdProfileOptions" :label="translate('ZUGFeRD Profile')" :help="translate('Select the e-invoice formatter used when generating the embedded XML.')"/>
                    <Field :label="translate('Legal Registration Scheme')" v-slot="{id}">
                        <fct-select :id="id" v-model="form.seller_legal_registration_scheme" :options="icdSchemeOptions" :placeholder="translate('Select scheme')" clearable filterable/>

                        <div class="form-note">
                            <p>{{ translate('ISO 6523 ICD code for the legal registration identifier') }}</p>
                        </div>

                        <ValidationError :validation-errors="validationErrors.seller_legal_registration_scheme || {}" field-key="seller_legal_registration_scheme"/>
                    </Field>
                </div>

                <Alert type="info" :closable="false" show-icon class="mb-4">
                    <template #default>
                        {{ translate('Company Name, VAT ID, Tax ID, and Legal Registration ID are managed in') }}

                        <a v-if="storeSettingsUrl" :href="storeSettingsUrl" target="_blank" class="">{{ translate('Store Settings → Business Details') }}</a>
                    </template>
                </Alert>

                <h3 class="mb-4 text-sm">{{ translate('Seller Contact') }}</h3>

                <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
                    <fct-input v-model="form.seller_contact_name" :label="translate('Contact Name')"/>
                    <fct-input v-model="form.seller_contact_email" type="email" :label="translate('Contact Email')" :validation-errors="validationErrors" field-key="seller_contact_email"/>
                    <fct-input v-model="form.seller_contact_phone" :label="translate('Contact Phone')"/>
                </div>

                <el-divider/>

                <h3 class="mb-4 text-sm">{{ translate('Seller Bank Details') }}</h3>

                <div class="grid grid-cols-1 gap-x-[15px] min-[1200px]:grid-cols-2">
                    <Field :label="translate('Bank IBAN')" v-slot="{id}">
                        <fct-input :id="id" v-model="form.seller_bank_iban"/>
                        <ValidationError :validation-errors="validationErrors.seller_bank_iban || {}" field-key="seller_bank_iban"/>
                        <div class="form-note">
                            <p>{{ translate('International Bank Account Number') }}</p>
                        </div>
                    </Field>
                    <fct-input v-model="form.seller_bank_bic" :label="translate('Bank BIC')" :help="translate('Bank Identifier Code (SWIFT)')"/>
                    <fct-input v-model="form.seller_bank_account_name" :label="translate('Bank Account Name')" :help="translate('Name of the bank account holder')"/>
                    <fct-input v-model="form.seller_electronic_address" :label="translate('Electronic Address')" :help="translate('Electronic address for receiving e-invoices (e.g. PEPPOL ID)')"/>
                </div>
            </Section>

            <div class="setting-save-action">
                <fct-button type="primary" @click="saveSettings()" :loading="saving">
                    {{ translate('Save Settings') }}
                </fct-button>
            </div>
        </template>
    </SettingsPage>
</template>
