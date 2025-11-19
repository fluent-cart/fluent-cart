<script setup>
import {defineModel, nextTick, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";


const model = defineModel();

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
                <span class="">{{ trnaslate('Manage') }}</span>
            </button>
        </div>
    </div>
</template>

