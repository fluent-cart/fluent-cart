<template>
    <div class="fct-module-disabled-notice">
        <el-icon class="fct-disabled-notice-icon">
            <CircleCloseFilled />
        </el-icon>

        <h3 class="fct-disabled-notice-title">
            {{ translate('Dynamic Pricing Inactive') }}
        </h3>

        <p class="fct-disabled-notice-text">
            {{ translate('Dynamic Pricing module is currently disabled. Please enable it in ') }}
            <router-link to="/settings/addons" target="_blank" rel="noopener noreferrer">
                {{ translate('module settings') }}
            </router-link>
            {{ translate(' to manage pricing rules.') }}
        </p>
    </div>
</template>

<script setup>
import { defineOptions } from 'vue';
import { CircleCloseFilled } from '@element-plus/icons-vue';
import translate from '@/utils/translator/Translator';

defineOptions({
    name: 'ModuleDisabledNotice'
});
</script>
