<script setup>
import { defineModel, onMounted, ref, computed } from "vue";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ClipBoard from "@/utils/Clipboard";
import Alert from "@/Bits/Components/Alert.vue";

// MCP's on/off lives under the `mcp.active` key of the shared modules blob.
// We keep this model in sync with our own status/toggle endpoints so the page's
// generic "Save Settings" rewrites the SAME value (and can't clobber the toggle).
const model = defineModel();

const syncModel = (enabled) => {
    const current = (model.value && typeof model.value === 'object') ? model.value : {};
    model.value = { ...current, active: enabled ? 'yes' : 'no' };
};

const props = defineProps({
    name: { type: String, required: true },
    field: { type: Object },
    fieldKey: { type: String },
    value: { required: true },
    variant: { type: String },
    nesting: { type: Boolean, default: false },
    statePath: { type: String },
    form: { type: Object, required: true },
    callback: { type: Function, required: true },
    label: { type: String },
    attribute: { required: true }
});

const CLIENTS = [
    { key: 'claude-code', label: 'Claude Code' },
    { key: 'claude-desktop', label: 'Claude Desktop' },
    { key: 'cursor', label: 'Cursor' },
    { key: 'codex', label: 'Codex' },
    { key: 'generic', label: translate('Other') }
];

const appReady = ref(false);
const loading = ref(false);
const saving = ref(false);
const drawerVisible = ref(false);
const status = ref({
    mcp_enabled: false,
    adapter_available: false,
    toolkit_installed: false,
    can_auto_install: false,
    toolkit_download_url: '',
    endpoint_url: '',
    tools_count: 0,
    app_passwords_url: '',
    current_user_login: '',
    is_local_dev: false
});
const mcpEnabled = ref('no');
const installing = ref(false);

const activeClient = ref('claude-code');
const snippets = ref({});
const snippetsLoaded = ref(false);
const snippetLoading = ref(false);
const username = ref('');
const appPassword = ref('');
const localDevOverride = ref(false);
const localDevInitialized = ref(false);

const isEnabled = computed(() => mcpEnabled.value === 'yes');

// Without the MCP adapter (FluentHub) the endpoint can't respond, so the
// on/off toggle would be misleading — the card then shows only "Configure",
// which opens the drawer and walks the operator through installing the toolkit.
const adapterReady = computed(() => !!status.value.adapter_available);

const badgeLabel = computed(() => {
    if (!adapterReady.value) {
        return translate('Setup required');
    }
    return isEnabled.value ? translate('Active') : translate('Inactive');
});

const badgeType = computed(() => (adapterReady.value && isEnabled.value) ? 'active' : 'inactive');

const activeSnippet = computed(() => snippets.value[activeClient.value] || null);

const renderedSnippet = computed(() => {
    const data = activeSnippet.value;
    if (!data || !data.snippet) {
        return '';
    }
    let text = data.snippet;
    const u = username.value.trim();
    const p = appPassword.value.trim();
    if (u && p) {
        let basic = '';
        try {
            basic = btoa(`${u}:${p}`);
        } catch (e) {
            basic = '';
        }
        if (basic) {
            text = text.split('<base64(your-username:application-password)>').join(basic);
        }
        text = text.split('<your-username>').join(u);
        text = text.split('<your-application-password>').join(p);
    }
    return text;
});

const loadStatus = () => {
    loading.value = true;
    return Rest.get('settings/mcp')
        .then((response) => {
            status.value = { ...status.value, ...response };
            mcpEnabled.value = response.mcp_enabled ? 'yes' : 'no';
            syncModel(!!response.mcp_enabled);
            if (response.current_user_login && !username.value) {
                username.value = response.current_user_login;
            }
            if (!localDevInitialized.value) {
                localDevOverride.value = !!response.is_local_dev;
                localDevInitialized.value = true;
            }
        })
        .catch((errors) => Notify.error(errors))
        .finally(() => { loading.value = false; });
};

const toggleMcp = (value) => {
    saving.value = true;
    Rest.post('settings/mcp/toggle', { mcp_enabled: value })
        .then((response) => {
            Notify.success(response.message);
            status.value.mcp_enabled = !!response.mcp_enabled;
            syncModel(!!response.mcp_enabled);
            if (isEnabled.value && drawerVisible.value) {
                loadSnippets();
            }
        })
        .catch((errors) => {
            mcpEnabled.value = value === 'yes' ? 'no' : 'yes';
            Notify.error(errors);
        })
        .finally(() => { saving.value = false; });
};

// One request for every client's snippet — they're cheap server-side templates,
// so the tabs just switch between cached entries with no extra round-trips.
const loadSnippets = () => {
    snippetLoading.value = true;
    return Rest.get('settings/mcp/config-snippets', {
            local_dev: localDevOverride.value ? 'yes' : 'no'
        })
        .then((response) => {
            snippets.value = response.snippets || {};
            snippetsLoaded.value = true;
            return response;
        })
        .catch((errors) => Notify.error(errors))
        .finally(() => { snippetLoading.value = false; });
};

const onClientChange = (client) => {
    activeClient.value = client;
};

const onLocalDevChange = () => {
    // The TLS flag only affects the Claude Desktop snippet, but a single refetch
    // keeps the whole cached set consistent and the code path simple.
    loadSnippets();
};

const copySnippet = () => {
    if (!renderedSnippet.value) {
        return;
    }
    ClipBoard.copy(renderedSnippet.value, {
        successMessage: translate('Connection snippet copied to your clipboard')
    });
};

const openDrawer = () => {
    drawerVisible.value = true;
    if (isEnabled.value && !snippetsLoaded.value) {
        loadSnippets();
    }
};

// One-click FluentHub install (works when a Fluent Pro plugin is active).
// The backend falls back to a manual download link when it can't auto-install.
const installAdapter = () => {
    installing.value = true;
    Rest.post('settings/mcp/install-adapter', {})
        .then((response) => {
            Notify.success(response.message);
            // The adapter's ability/server hooks already ran for this request, so
            // reload to bring the endpoint fully online (keep the toast briefly).
            setTimeout(() => { window.location.reload(); }, 800);
        })
        .catch((errors) => {
            Notify.error(errors);
            installing.value = false;
        });
};

const copyEndpoint = () => {
    if (!status.value.endpoint_url) {
        return;
    }
    ClipBoard.copy(status.value.endpoint_url, {
        successMessage: translate('Endpoint URL copied to your clipboard')
    });
};

onMounted(() => {
    loadStatus().then(() => {
        appReady.value = true;
    });
});
</script>

<template>
    <!-- Flex row (override the shared item's grid + right-padding reservation):
         content shrinks/wraps on the left, actions stay pinned right with a gap,
         so the description can never run under the buttons. -->
    <div v-if="appReady" class="fct-content-card-list-item py-4 px-6 !flex !pr-6 items-start justify-between !gap-4">
        <div class="min-w-0">
            <div class="fct-content-card-list-head">
                <div class="flex items-start gap-2 flex-row">
                    <h4 class="mb-0">{{ field.title }}</h4>
                    <Badge size="small" :type="badgeType" :hide-icon="true">
                        {{ badgeLabel }}
                    </Badge>
                </div>
            </div>
            <div class="fct-content-card-list-content mt-1" v-if="field.description">
                <p>{{ field.description }}</p>
            </div>
        </div>

        <div class="flex items-center gap-3 shrink-0">
            <el-button v-if="!adapterReady || isEnabled" size="small" @click="openDrawer">
                {{ translate('Configure') }}
            </el-button>
            <!-- No adapter → no toggle; flipping it on would have no effect. The
                 Configure button drives the install instead. -->
            <el-switch
                v-if="adapterReady"
                active-value="yes"
                inactive-value="no"
                v-model="mcpEnabled"
                :loading="saving"
                @change="toggleMcp"
            />
        </div>

        <el-drawer
            v-model="drawerVisible"
            :title="translate('MCP Connection')"
            :aria-label="translate('MCP Connection')"
            size="640px"
            append-to="#fct_admin_app_wrapper"
            :close-on-click-modal="true"
            class="fct-mcp-settings-drawer"
        >
            <div class="space-y-4">

                <div class="fct-mcp-content-box" v-if="!status.adapter_available">
                    <div class="fct-mcp-content-box-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <g clip-path="url(#clip0_23040_79251)">
                                <path d="M28.8 0H3.2C1.43269 0 0 1.43269 0 3.2V28.8C0 30.5673 1.43269 32 3.2 32H28.8C30.5673 32 32 30.5673 32 28.8V3.2C32 1.43269 30.5673 0 28.8 0Z" fill="#008CFF"/>
                                <path d="M7.5 12.8637C7.5 9.3491 10.3491 6.5 13.8637 6.5H15.4545V19.2272C15.4545 22.7418 12.6055 25.5909 9.0909 25.5909H7.5V12.8637Z" fill="white"/>
                                <path d="M17.0454 17.6371C17.0454 14.1225 19.8945 11.2734 23.4091 11.2734H25V19.228C25 22.7425 22.1509 25.5916 18.6363 25.5916H17.0454V17.6371Z" fill="white"/>
                            </g>
                            <defs>
                                <clipPath id="clip0_23040_79251">
                                <rect width="32" height="32" fill="white"/>
                                </clipPath>
                            </defs>
                        </svg>
                    </div>

                    <h4 class="fct-mcp-content-box-title">
                        {{ translate('FluentHub is required') }}
                    </h4>

                    <p class="fct-mcp-content-box-text">
                        {{ translate('FluentCart speaks to AI agents through the MCP adapter bundled with FluentHub (WordPress 6.9+). The endpoint will not respond until it is installed and active.') }}
                    </p> 
                    
                    <div class="fct-mcp-content-box-action">
                        <el-button
                            v-if="status.can_auto_install"
                            :loading="installing"
                            @click="installAdapter"
                        >
                            {{ status.toolkit_installed ? translate('Activate FluentHub') : translate('Install FluentHub') }}
                        </el-button>

                        <el-button
                            v-else
                            tag="a"
                            :href="status.toolkit_download_url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ translate('Download FluentHub') }}
                        </el-button>
                    </div>
                </div>

                <Alert
                    v-if="!status.can_auto_install"
                    type="info"
                    icon="InformationFill"
                    :content="translate('Tip: FluentCart Pro can install it for you in one click.')"
                />

                <!-- Adapter live but MCP still off (e.g. just after an in-drawer
                     install): point the operator back to the card toggle. -->
                <div
                    v-if="status.adapter_available && !isEnabled"
                    class="fct-mcp-content-box is-active"
                >
                    <div class="fct-mcp-content-box-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <g clip-path="url(#clip0_23040_79251)">
                                <path d="M28.8 0H3.2C1.43269 0 0 1.43269 0 3.2V28.8C0 30.5673 1.43269 32 3.2 32H28.8C30.5673 32 32 30.5673 32 28.8V3.2C32 1.43269 30.5673 0 28.8 0Z" fill="#008CFF"/>
                                <path d="M7.5 12.8637C7.5 9.3491 10.3491 6.5 13.8637 6.5H15.4545V19.2272C15.4545 22.7418 12.6055 25.5909 9.0909 25.5909H7.5V12.8637Z" fill="white"/>
                                <path d="M17.0454 17.6371C17.0454 14.1225 19.8945 11.2734 23.4091 11.2734H25V19.228C25 22.7425 22.1509 25.5916 18.6363 25.5916H17.0454V17.6371Z" fill="white"/>
                            </g>
                            <defs>
                                <clipPath id="clip0_23040_79251">
                                <rect width="32" height="32" fill="white"/>
                                </clipPath>
                            </defs>
                        </svg>
                    </div>

                    <h4 class="fct-mcp-content-box-title">
                        {{ translate('FluentHub is active') }}
                    </h4>

                    <p class="fct-mcp-content-box-text">
                        {{ translate('Turn MCP on from the card to generate connection snippets for your AI clients.') }}
                    </p>
                </div>

                <!-- Connection details only make sense once the adapter is live and
                     MCP is enabled; before that the drawer is a setup prompt. -->
                <template v-if="status.adapter_available && isEnabled">
                <div class="fct-mcp-agents-text">
                    <span class="tools-count">
                        {{ status.tools_count }}
                    </span>
                    <span class="tools-label">{{ translate('AI tools available to connected agents') }}</span>
                </div>

                <div class="fct-form-group">
                    <label>{{ translate('Endpoint URL') }}</label>
                    <el-input v-model="status.endpoint_url" readonly>
                        <template #append>
                            <el-button @click="copyEndpoint">{{ translate('Copy') }}</el-button>
                        </template>
                    </el-input>
                </div>

                <div class="fct-form-group">
                    <label>{{ translate('Connect a client') }}</label>
                    <p class="form-note mb-3">
                        {{ translate('Create an application password for your WordPress user, then fill it in below. Your password stays in the browser — it is only written into the snippet you copy.') }}
                        <a :href="status.app_passwords_url" target="_blank" rel="noopener noreferrer">
                            {{ translate('Create an application password') }}
                        </a>
                    </p>

                    <div class="grid gap-3 md:grid-cols-2 mb-3">
                        <el-input
                            v-model="username"
                            :placeholder="translate('WordPress username')"
                        />
                        <el-input
                            v-model="appPassword"
                            type="password"
                            show-password
                            :placeholder="translate('Application password')"
                        />
                    </div>

                    <el-tabs v-model="activeClient" @tab-change="onClientChange">
                        <el-tab-pane
                            v-for="client in CLIENTS"
                            :key="client.key"
                            :label="client.label"
                            :name="client.key"
                        />
                    </el-tabs>

                    <div v-if="activeClient === 'claude-desktop'" class="mb-3">
                        <el-checkbox v-model="localDevOverride" @change="onLocalDevChange">
                            {{ translate('Local/self-signed site (disable TLS verification)') }}
                        </el-checkbox>
                    </div>

                    <p v-if="activeSnippet && activeSnippet.instructions" class="form-note mb-2">
                        {{ activeSnippet.instructions }}
                    </p>

                    <div class="relative fct-mcp-snippet">
                        <pre
                            v-loading="snippetLoading"
                            class="fct-mcp-snippet-code"
                        >{{renderedSnippet}}</pre>
                        <div class="mt-2 flex justify-end">
                            <el-button type="primary" :disabled="!renderedSnippet" @click="copySnippet">
                                {{ translate('Copy snippet') }}
                            </el-button>
                        </div>
                    </div>
                </div>
                </template>

            </div>
        </el-drawer>
    </div>
</template>
