<template>
    <div class="fct-module-disabled-notice">
        <el-icon class="fct-disabled-notice-icon">
            <CircleCloseFilled />
        </el-icon>

        <h3 class="fct-disabled-notice-title">
            {{ translate('Dynamic Pricing Inactive') }}
        </h3>

        <p class="fct-disabled-notice-text">
            {{ notice[0] }}<router-link to="/settings/addons" target="_blank" rel="noopener noreferrer">{{ notice[1] }}</router-link>{{ notice[2] }}
        </p>
    </div>
</template>

<script setup>
import { CircleCloseFilled } from '@element-plus/icons-vue';
import translate from '@/utils/translator/Translator';

// One sentence so translators can place the link; the markers wrap its text
/* translators: %1$s: opening link tag, %2$s: closing link tag */
const notice = translate('Pricing_Dynamic_Pricing').split(/%[12]\$s/);

defineOptions({
    name: 'ModuleDisabledNotice'
});
</script>
