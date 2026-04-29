<script setup>
import {defineModel, nextTick, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Animation from "@/Bits/Components/Animation.vue";
import AppConfig from "@/utils/Config/AppConfig";


const model = defineModel();
const isProActive = AppConfig.get('app_config.isProActive');

const props = defineProps({
    name: {
        type: String,
        required: true
    },

    field: {
        type: Object
    },

    fieldKey: {
        type: String
    },
    value: {
        required: true
    },
    variant: {
        type: String
    },
    nesting: {
        type: Boolean,
        default: false
    },
    statePath: {
        type: String
    },
    form: {
        type: Object,
        required: true
    },
    callback: {
        type: Function,
        required: true
    },
    label: {
        type: String
    },
    attribute: {
        required: true
    }
})

const isActive = ref(false);
const appReady = ref(false);

const handleSettingChange = (key, value) => {
    if (key === 'enable_advanced_inventory' && !isProActive && value === 'yes') {
        model[key] = 'no';
    }
};

onMounted(() => {
    isActive.value = model.value.active;
    appReady.value = true;
});

</script>

<template>
    <div v-if="appReady" class="fct-content-card-list-item py-4 px-6">
        <div class="fct-content-card-list-head">
            <div class="flex items-start gap-2 flex-row">
                <h4 class="mb-0">{{ field.title }}</h4>
                <Badge size="small" :type="isActive === 'yes' ? 'active':'inactive'" :hide-icon="true">
                    {{ isActive === 'yes' ? translate('Active') : translate('Inactive') }}
                </Badge>
            </div>
        </div>
        <div class="fct-content-card-list-content" v-if="field.description">
            <p>{{ field.description }}</p>

            <Animation v-if="field.settings" :visible="isActive === 'yes'" accordion>
                <div class="mt-3 space-y-2 fct-module-settings-option-wrap">
                    <template v-for="(setting, key) in field.settings" :key="key">
                        <div class="fct-module-settings-option">
                            <el-checkbox
                                v-model="model[key]"
                                true-value="yes"
                                false-value="no"
                                :disabled="key === 'enable_advanced_inventory' && !isProActive"
                                @change="(value) => handleSettingChange(key, value)"
                            >
                                {{ setting.label }}

                                <span
                                    v-if="key === 'enable_advanced_inventory' && !isProActive"
                                    class="text-xs block"
                                >
                                    {{ translate('This feature is only available in FluentCart Pro.') }}
                                    <el-button
                                        size="small"
                                        tag="a"
                                        link
                                        href="https://fluentcart.com/discount-deal" target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {{ translate('Upgrade to Pro') }}
                                    </el-button>
                                </span>
                            </el-checkbox>
                        </div>
                    </template>
                </div>
            </Animation>
        </div>

        <div class="fct-content-card-list-action">
            <div class="pr-4">
                <el-switch active-value="yes" inactive-value="no" v-model="isActive" @change="(value)=>{
        if(!model || typeof model !== 'object'){
          model = {}
        }
        nextTick(()=>{
          model.active = value;
        })
      }">
                </el-switch>
            </div>
            <button v-if="false" aria-disabled="false" type="button" class="el-button el-button--x-small">
                <span class="">{{ translate('Manage') }}</span>
            </button>
        </div>
    </div>
</template>

