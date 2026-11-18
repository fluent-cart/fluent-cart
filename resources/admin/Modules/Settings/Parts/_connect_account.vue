<template>
    <div class="fct-connect-details">
        <div v-if="!connect || connect.error" class="fct-connect-require">
            <img :src="connectConfig.image_url" alt="" class="w-10 h-10 mb-4">

            <h3> {{
                /* translators: %1$s: payment mode (test/live), %2$s: payment method name */
                translate('Connect your %1$s %2$s account', mode, methodName)
              }}</h3>

            <fct-button tag="a" :href="connectConfig[mode+'_redirect']" :style="{ backgroundColor: brandColor, borderColor: brandColor, color: 'white' }">
                {{
                    /* translators: %1$s: payment method label */
                    translate('Connect with %1$s', methodLabel)
                }}
            </fct-button>
        </div>

        <div v-else class="fct-connect-success">
            <div class="inline-flex items-center gap-3">
                <div>
                    <p class="display-name" v-if="connect.display_name">
                        {{ connect.display_name }}
                    </p>
                    <h3 class="display-role">
                        {{ $t('Administrator') }} ({{ $t('Owner') }})
                    </h3>
                </div>

                <el-tag size="small" type="success">
                    {{ translate('Connected') }}
                </el-tag>
            </div>

            <fct-popover
                confirm
                width="230"
                :confirm-button-text="$t('Confirm')"
                :cancel-button-text="$t('No, Thanks')"
                icon-color="red"
                :title="connectConfig?.disconnect_note ?? $t('Are you sure to disconnect?')"
                @confirm="disconnect">
                <template #reference>
                    <fct-button type="danger" plain>
                        {{ translate('Disconnect') }}
                    </fct-button>
                </template>
            </fct-popover>
        </div>
    </div>
</template>


<script setup>
import translate from "../../../utils/translator/Translator";
</script>

<script type="text/babel">
export default {
    name: 'ConnectAccount',
    props: ['connect', 'connectConfig', 'mode', 'method', 'methodName', 'methodLabel', 'brandColor'],
    data() {
        return {
            saving: false
        }
    },
    methods: {
        disconnect() {
            this.saving = true;
            this.$post('settings/payment-methods/disconnect', {
                method: this.method,
                mode: this.mode
            })
                .then((response) => {
                    this.saving = false;
                    this.$emit('reload_settings', true);
                })
        }
    }
}
</script>
