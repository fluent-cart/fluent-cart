<script setup>
import { computed, ref } from "vue";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import ClipBoard from "@/utils/Clipboard";
import { hubCardMode } from "@/Bits/Components/Form/Components/mcpHubCard.js";

// The "Connect with one URL" block of the MCP drawer. FluentHub serves every Fluent
// plugin from one endpoint and signs the user in with their WordPress account. `hub` is
// what the server read from the fluent_kit/mcp_hub_state filter (fluent-toolkit
// docs/mcp-product-connect.md), with the per-app text, so it reads as on FluentHub's page.
const props = defineProps({
    hub: { type: Object, required: true },
    // What the parent is turning on right now: 'hub', a plugin slug, or ''.
    enabling: { type: String, default: '' }
});

const emit = defineEmits(['enable']);

const activeKey = ref('');

const clients = computed(() => props.hub.clients || []);
const products = computed(() => props.hub.products || []);

const activeClient = computed(() => {
    return clients.value.find(client => client.key === activeKey.value) || clients.value[0] || { key: '', note: '', snippet: '' };
});

const mode = computed(() => hubCardMode(props.hub));
const isUsable = computed(() => mode.value === 'ready');
const canTurnOn = computed(() => mode.value === 'turn_on');

const hasProductToTurnOn = computed(() => products.value.some(product => !product.served && product.can_enable));

// An administrator reaches every Fluent plugin; a store user reaches FluentCart only.
const introText = computed(() => props.hub.can_manage ? translate('Mcp_Hub_Intro_Admin') : translate('Mcp_Hub_Intro_User'));

const manageLink = computed(() => {
    if (props.hub.can_manage && props.hub.manage_url) {
        return { url: props.hub.manage_url, label: translate('Manage connections in FluentHub') };
    }
    if (props.hub.profile_url) {
        return { url: props.hub.profile_url, label: translate('Manage your connected apps') };
    }
    return null;
});

const reasonText = computed(() => {
    const hub = props.hub;

    // For a user without access no other reason matters, and none is theirs to fix.
    if (!hub.user_can_connect) {
        return translate("Your account has no access to this plugin's AI tools.");
    }
    // sign_in_reason repeats the hub's own state as hub_off and hub_unavailable.
    if (hub.hub_reason === 'off' || hub.sign_in_reason === 'hub_off') {
        return translate('The FluentHub MCP server is off. A site administrator can turn it on.');
    }
    if (hub.hub_reason === 'adapter_missing' || hub.hub_reason === 'adapter_outdated' || hub.sign_in_reason === 'hub_unavailable') {
        return translate('FluentHub needs a newer MCP adapter for it.');
    }
    if (hub.sign_in_reason === 'plain_permalinks') {
        return translate('Sign-in needs pretty permalinks. Change them in Settings, Permalinks.');
    }
    if (hub.sign_in_reason === 'app_passwords_off') {
        return translate('Mcp_Hub_Reason_App_Passwords');
    }
    if (hub.sign_in_reason === 'off') {
        return translate('Sign-in is switched off in FluentHub.');
    }
    return translate('It is not ready on this site yet. Reload this page, or check FluentHub.');
});

// FluentHub has no setting for who connects: access comes from the plugin's own permissions,
// so a user without access is not sent there.
const showHubLink = computed(() => !!props.hub.can_manage && !!props.hub.manage_url && !!props.hub.user_can_connect);

const copyUrl = () => {
    ClipBoard.copy(props.hub.endpoint_url, {
        successMessage: translate('Endpoint URL copied to your clipboard')
    });
};

const copySnippet = () => {
    if (activeClient.value.snippet) {
        ClipBoard.copy(activeClient.value.snippet, {
            successMessage: translate('Connection snippet copied to your clipboard')
        });
    }
};
</script>

<template>
    <div v-if="isUsable" class="fct-mcp-hub" data-testid="mcp-hub-ready">
        <h4 class="fct-mcp-hub__title">{{ translate('Connect with one URL') }}</h4>
        <p class="fct-mcp-hub__text">{{ introText }}</p>

        <div v-if="hub.endpoint_url" class="fct-form-group mb-3">
            <fct-input :model-value="hub.endpoint_url" readonly data-testid="mcp-hub-url">
                <template #append>
                    <fct-button @click="copyUrl">{{ translate('Copy') }}</fct-button>
                </template>
            </fct-input>
        </div>

        <el-tabs :model-value="activeClient.key" @update:model-value="activeKey = $event">
            <el-tab-pane v-for="client in clients" :key="client.key" :label="client.label" :name="client.key"/>
        </el-tabs>

        <p class="form-note mb-2">{{ activeClient.note }}</p>
        <div class="fct-mcp-snippet">
            <pre class="fct-mcp-snippet-code" data-testid="mcp-hub-snippet">{{ activeClient.snippet }}</pre>
        </div>
        <div class="fct-mcp-hub__actions">
            <a v-if="manageLink" :href="manageLink.url" target="_blank" rel="noopener noreferrer" data-testid="mcp-hub-manage">{{ manageLink.label }}</a>
            <fct-button type="primary" @click="copySnippet">{{ translate('Copy snippet') }}</fct-button>
        </div>

        <p class="form-note">{{ translate('Mcp_Hub_Who') }}</p>

        <div v-if="hub.can_manage && products.length" class="fct-mcp-hub__products" data-testid="mcp-hub-products">
            <h4 class="fct-mcp-hub__title">{{ translate('Fluent plugins on this URL') }}</h4>
            <div v-for="product in products" :key="product.slug" class="fct-mcp-hub__product" :data-testid="'mcp-product-' + product.slug">
                <span>{{ product.name }}</span>
                <Badge v-if="product.served" size="small" type="active" :hide-icon="true">{{ translate('On') }}</Badge>
                <fct-button
                    v-else-if="product.can_enable"
                    size="small"
                    :loading="enabling === product.slug"
                    :disabled="!!enabling"
                    @click="emit('enable', product.slug)"
                >
                    {{ translate('Turn on') }}
                </fct-button>
                <Badge v-else size="small" type="inactive" :hide-icon="true">{{ translate('Off') }}</Badge>
            </div>
            <p v-if="hasProductToTurnOn" class="form-note">{{ translate('Mcp_Hub_Turn_On_Plugin') }}</p>
        </div>
    </div>

    <div v-else-if="canTurnOn" class="fct-mcp-hub" data-testid="mcp-hub-turn-on">
        <h4 class="fct-mcp-hub__title">{{ translate('Connect with one URL') }}</h4>
        <p class="fct-mcp-hub__text">{{ translate('Mcp_Hub_Off_Intro') }}</p>
        <fct-button type="primary" :loading="enabling === 'hub'" :disabled="!!enabling" @click="emit('enable', 'hub')">
            {{ translate('Turn on FluentHub sign-in') }}
        </fct-button>
        <p class="form-note mt-2">{{ translate('Mcp_Hub_Turn_On_Note') }}</p>
    </div>

    <div v-else class="fct-mcp-hub" data-testid="mcp-hub-not-ready">
        <p class="fct-mcp-hub__text">
            <strong>{{ translate('Connect with one URL and no password to copy.') }}</strong>
            {{ reasonText }}
            <a v-if="showHubLink" :href="hub.manage_url" target="_blank" rel="noopener noreferrer">{{ translate('Open FluentHub') }}</a>
        </p>
    </div>
</template>
