<script setup>
import AppConfig from "@/utils/Config/AppConfig";
import translate from "@/utils/translator/Translator";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";

const props = defineProps({
    minVersion: {
        type: String,
        required: true,
    },
    featureTitle: {
        type: String,
        default: '',
    },
    featureText: {
        type: String,
        default: '',
    },
    placement: {
        type: String,
        default: 'upgrade_page',
    },
});

const isProActive = AppConfig.get('app_config.isProActive');
const proVersion = AppConfig.get('app_config.proVersion');

const needsUpdate = isProActive && proVersion
    && proVersion.localeCompare(props.minVersion, undefined, { numeric: true }) < 0;

const updateMessage = translate(
    'Pro_Feature_Requires'
).replace('%s', props.minVersion);
</script>

<template>
    <template v-if="isProActive && !needsUpdate">
        <slot />
    </template>

    <el-alert
        v-else-if="needsUpdate"
        type="warning"
        show-icon
        :closable="false"
        :title="translate('Update Required')"
        :description="updateMessage"
    />

    <ProFeatureNotice
        v-else
        :title="featureTitle"
        :text="featureText"
        :placement="placement"
    />
</template>
