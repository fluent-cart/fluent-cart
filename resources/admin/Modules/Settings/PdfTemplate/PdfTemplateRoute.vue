<template>
    <div v-if="!isProActive" class="bg-white p-10 rounded m-10 dark:bg-dark-600">
        <ProFeatureNotice
            placement="feature_lock_pdf_templates"
            :title="translate('PDF Templates')"
            :text="translate('Customizable PDF receipts, invoices, and e-invoices with ZUGFeRD support.')"
        />
    </div>
    <router-view v-else />
</template>

<script setup>
import AppConfig from "@/utils/Config/AppConfig";
import translate from "@/utils/translator/Translator";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";

const isProActive = AppConfig.get('app_config.isProActive');
</script>
