<script setup>
import {defineModel, nextTick, onMounted, ref, watch, computed} from "vue";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Rest from "@/utils/http/Rest";

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
const siteKey = ref('');
const secretKey = ref('');
const appReady = ref(false);

onMounted(() => {
    if (model.value) {
        isActive.value = model.value.active || 'no';
        siteKey.value = model.value.site_key || '';
        secretKey.value = model.value.secret_key || '';
    } else {
        model.value = {
            active: 'no',
            site_key: '',
            secret_key: ''
        };
    }
    appReady.value = true;
});

watch(() => model.value, (newVal) => {
    if (newVal) {
        isActive.value = newVal.active || 'no';
        siteKey.value = newVal.site_key || '';
        secretKey.value = newVal.secret_key || '';
    }
}, { deep: true });

const updateActive = (value) => {
    if (!model.value || typeof model.value !== 'object') {
        model.value = {};
    }
    nextTick(() => {
        model.value.active = value;
    });
};

const updateSiteKey = (value) => {
    if (!model.value || typeof model.value !== 'object') {
        model.value = {};
    }
    nextTick(() => {
        model.value.site_key = value;
    });
};

const updateSecretKey = (value) => {
    if (!model.value || typeof model.value !== 'object') {
        model.value = {};
    }
    nextTick(() => {
        model.value.secret_key = value;
    });
};

const verifying = ref(false);
const verifyResult = ref(null);
const turnstileContainer = ref(null);

const canVerify = computed(() => {
    return siteKey.value && secretKey.value && !verifying.value;
});

const loadTurnstileScript = () => {
    return new Promise((resolve, reject) => {
        if (typeof window.turnstile !== 'undefined') {
            resolve();
            return;
        }
        const existing = document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]');
        if (existing) {
            existing.addEventListener('load', resolve);
            existing.addEventListener('error', reject);
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Failed to load Turnstile script'));
        document.head.appendChild(script);
    });
};

const getTurnstileToken = (key) => {
    return new Promise((resolve, reject) => {
        const container = turnstileContainer.value;
        if (!container) {
            reject(new Error('Container not found'));
            return;
        }
        container.innerHTML = '';

        const timeout = setTimeout(() => {
            reject(new Error('Turnstile verification timed out. Please check your Site Key and ensure this domain is allowed in Cloudflare.'));
        }, 15000);

        try {
            window.turnstile.render(container, {
                sitekey: key,
                size: 'flexible',
                appearance: 'interaction-only',
                callback: (token) => {
                    clearTimeout(timeout);
                    resolve(token);
                },
                'error-callback': () => {
                    clearTimeout(timeout);
                    reject(new Error('Turnstile challenge failed. Please check your Site Key.'));
                }
            });
        } catch (e) {
            clearTimeout(timeout);
            reject(new Error('Invalid Site Key or Turnstile configuration.'));
        }
    });
};

const verifyKeys = async () => {
    verifying.value = true;
    verifyResult.value = null;

    try {
        await loadTurnstileScript();
        const token = await getTurnstileToken(siteKey.value);

        const response = await Rest.post('settings/modules/turnstile/verify', {
            site_key: siteKey.value,
            secret_key: secretKey.value,
            token: token
        });
        verifyResult.value = { success: true, message: response.message };
    } catch (error) {
        const message = error?.data?.message || error?.message || translate('Verification failed. Please check your keys.');
        verifyResult.value = { success: false, message };
    } finally {
        verifying.value = false;
        if (turnstileContainer.value) {
            turnstileContainer.value.innerHTML = '';
        }
    }
};

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
                <el-switch active-value="yes" inactive-value="no" v-model="isActive" @change="updateActive">
                </el-switch>
            </div>
        </div>

        <div v-if="isActive === 'yes'" class="fct-turnstile-settings mt-6 border-t border-gray-200 dark:border-gray-700">
            <div class="space-y-4 max-w-[600px] p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div>
                    <label class="block text-sm font-medium mb-2">{{ translate('Turnstile Site Key') }}</label>
                    <el-input
                        v-model="siteKey"
                        @input="updateSiteKey"
                        :placeholder="translate('Enter your Turnstile Site Key')"
                        type="text"
                    />
                    <p class="form-note mt-2">
                        {{ translate('Get your Site Key from Cloudflare Dashboard > Turnstile.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">{{ translate('Turnstile Secret Key') }}</label>
                    <el-input
                        v-model="secretKey"
                        @input="updateSecretKey"
                        :placeholder="translate('Enter your Turnstile Secret Key')"
                        type="password"
                        show-password
                    />
                    <p class="form-note mt-2">
                        {{ translate('Get your Secret Key from Cloudflare Dashboard > Turnstile. Keep this secret.') }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <el-button
                        :disabled="!canVerify"
                        :loading="verifying"
                        @click="verifyKeys"
                        type="primary"
                        size="small"
                    >
                        {{ translate('Verify Keys') }}
                    </el-button>
                    <span v-if="verifyResult" :class="verifyResult.success ? 'text-green-600' : 'text-red-600'" class="text-sm">
                        {{ verifyResult.message }}
                    </span>
                </div>
                <div ref="turnstileContainer" style="position: absolute; left: -9999px; visibility: hidden;"></div>
            </div>
        </div>
    </div>
</template>

