<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";

const loading = ref(true);
const saving = ref(false);
const validationErrors = ref({});
const storeCountrySet = ref(true);
const storeSettingsUrl = ref('');

const form = ref({
    zugferd_enabled: '0',
    zugferd_profile: 'en16931',
    seller_vat_id: '',
    seller_tax_id: '',
    seller_legal_name: '',
    seller_legal_registration_id: '',
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

onMounted(() => {
    fetchSettings();
});
</script>

<template>
    <div class="setting-wrap">
        <SettingsHeader
            :heading="translate('E-Invoice Settings')"
            :loading="saving"
            @onSave="saveSettings"
        />

        <div class="setting-wrap-inner">
            <template v-if="loading">
                <Card.Container>
                    <Card.Body>
                        <el-skeleton animated>
                            <template #template>
                                <div class="grid gap-3 mb-6">
                                    <el-skeleton-item variant="p" class="w-[20%]"/>
                                    <el-skeleton-item variant="p"/>
                                </div>
                                <div class="grid gap-3 mb-6">
                                    <el-skeleton-item variant="p" class="w-[20%]"/>
                                    <el-skeleton-item variant="p"/>
                                </div>
                                <div class="grid gap-3 mb-6">
                                    <el-skeleton-item variant="p" class="w-[20%]"/>
                                    <el-skeleton-item variant="p"/>
                                </div>
                                <div class="grid gap-3">
                                    <el-skeleton-item variant="p" class="w-[20%]"/>
                                    <el-skeleton-item variant="p"/>
                                </div>
                            </template>
                        </el-skeleton>
                    </Card.Body>
                </Card.Container>
            </template>

            <template v-if="!loading">
                <div
                    v-if="form.zugferd_enabled === '1' && !storeCountrySet"
                    class="mb-4 p-4 rounded-lg border border-yellow-300 bg-yellow-50 text-yellow-800 text-sm"
                >
                    <strong>{{ translate('Store country is not configured.') }}</strong>
                    {{ translate('ZUGFeRD XML cannot be embedded in PDFs until your store country is set.') }}
                    <a v-if="storeSettingsUrl" :href="storeSettingsUrl" class="underline ml-1">
                        {{ translate('Go to Store Settings') }}
                    </a>
                </div>

                <Card.Container>
                    <Card.Header
                        :title="translate('E-Invoice Configuration')"
                        :text="translate('Configure ZUGFeRD / Factur-X e-invoice encoding and seller details for your PDFs.')"
                        border_bottom
                    >
                        <template #action>
                            <el-switch
                                v-model="form.zugferd_enabled"
                                active-value="1"
                                inactive-value="0"
                            />
                        </template>
                    </Card.Header>
                    <Card.Body>
                        <el-form-item :label="translate('ZUGFeRD Profile')" label-position="top">
                            <el-select v-model="form.zugferd_profile">
                                <el-option
                                    v-for="opt in zugferdProfileOptions"
                                    :key="opt.value"
                                    :label="opt.label"
                                    :value="opt.value"
                                />
                            </el-select>
                            <div class="form-note">
                                <p>{{ translate('Select the e-invoice formatter used when generating the embedded XML.') }}</p>
                            </div>
                        </el-form-item>

                        <h4 class="mb-4">{{ translate('Seller Tax Details') }}</h4>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Seller VAT ID')" label-position="top" :required="form.zugferd_enabled === '1'">
                                    <el-input v-model="form.seller_vat_id"/>
                                    <ValidationError :validation-errors="validationErrors.seller_vat_id || {}" field-key="seller_vat_id"/>
                                    <div class="form-note">
                                        <p>{{ translate('VAT identification number (e.g. DE123456789)') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12">
                                <el-form-item :label="translate('Seller Tax ID')" label-position="top">
                                    <el-input v-model="form.seller_tax_id"/>
                                    <div class="form-note">
                                        <p>{{ translate('National tax identification number') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Legal Name')" label-position="top">
                                    <el-input v-model="form.seller_legal_name"/>
                                    <div class="form-note">
                                        <p>{{ translate('Registered legal name of the seller') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12">
                                <el-form-item :label="translate('Legal Registration ID')" label-position="top" :required="form.zugferd_enabled === '1'">
                                    <el-input v-model="form.seller_legal_registration_id"/>
                                    <div class="form-note">
                                        <p>{{ translate('Company registration number (e.g. HRB 12345)') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Legal Registration Scheme')" label-position="top">
                                    <el-select v-model="form.seller_legal_registration_scheme" :placeholder="translate('Select scheme')" clearable filterable>
                                        <el-option
                                            v-for="opt in icdSchemeOptions"
                                            :key="opt.value"
                                            :label="opt.label"
                                            :value="opt.value"
                                        />
                                    </el-select>
                                    <ValidationError :validation-errors="validationErrors.seller_legal_registration_scheme || {}" field-key="seller_legal_registration_scheme"/>
                                    <div class="form-note">
                                        <p>{{ translate('ISO 6523 ICD code for the legal registration identifier') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-divider/>

                        <h4 class="mb-4">{{ translate('Seller Contact') }}</h4>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Contact Name')" label-position="top">
                                    <el-input v-model="form.seller_contact_name"/>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12">
                                <el-form-item :label="translate('Contact Email')" label-position="top">
                                    <el-input v-model="form.seller_contact_email" type="email"/>
                                    <ValidationError :validation-errors="validationErrors.seller_contact_email || {}" field-key="seller_contact_email"/>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Contact Phone')" label-position="top">
                                    <el-input v-model="form.seller_contact_phone"/>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-divider/>

                        <h4 class="mb-4">{{ translate('Seller Bank Details') }}</h4>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Bank IBAN')" label-position="top">
                                    <el-input v-model="form.seller_bank_iban"/>
                                    <ValidationError :validation-errors="validationErrors.seller_bank_iban || {}" field-key="seller_bank_iban"/>
                                    <div class="form-note">
                                        <p>{{ translate('International Bank Account Number') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12">
                                <el-form-item :label="translate('Bank BIC')" label-position="top">
                                    <el-input v-model="form.seller_bank_bic"/>
                                    <div class="form-note">
                                        <p>{{ translate('Bank Identifier Code (SWIFT)') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                        </el-row>

                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item :label="translate('Bank Account Name')" label-position="top">
                                    <el-input v-model="form.seller_bank_account_name"/>
                                    <div class="form-note">
                                        <p>{{ translate('Name of the bank account holder') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12">
                                <el-form-item :label="translate('Electronic Address')" label-position="top">
                                    <el-input v-model="form.seller_electronic_address"/>
                                    <div class="form-note">
                                        <p>{{ translate('Electronic address for receiving e-invoices (e.g. PEPPOL ID)') }}</p>
                                    </div>
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </Card.Body>
                </Card.Container>

                <div class="setting-save-action">
                    <el-button type="primary" @click="saveSettings()" :loading="saving">
                        {{ saving ? translate('Saving Settings') : translate('Save Settings') }}
                    </el-button>
                </div>
            </template>
        </div>
    </div>
</template>
