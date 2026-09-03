<template>
    <div class="fct-connect-details">
        <div v-if="!connect || connect.error" class="fct-connect-require">
            <img :src="connectConfig.image_url" alt="" class="w-10 h-10 mb-4">

            <h4> {{
                /* translators: %1$s: payment mode (test/live), %2$s: payment method name */
                translate('Connect your %1$s %2$s account', mode, methodName)
              }}</h4>

            <el-button tag="a" :href="connectConfig[mode+'_redirect']" :style="{ backgroundColor: brandColor, borderColor: brandColor, color: 'white' }">
                {{
                    /* translators: %1$s: payment method label */
                    translate('Connect with %1$s', methodLabel)
                }}
            </el-button>
        </div>

        <div v-else class="fct-connect-success">
            <div class="inline-flex items-center gap-3">
                <div>
                    <p class="display-name" v-if="connect.display_name">
                        {{ connect.display_name }}
                    </p>
                    <h4 class="display-role">
                        {{ $t('Administrator') }} ({{ $t('Owner') }})
                    </h4>
                </div>

                <el-tag size="small" type="success">
                    {{ translate('Connected') }}
                </el-tag>
            </div>

            <el-popconfirm
                width="230"
                :confirm-button-text="$t('Confirm')"
                :cancel-button-text="$t('No, Thanks')"
                icon="el-icon-info"
                icon-color="red"
                :title="connectConfig?.disconnect_note ?? $t('Are you sure to disconnect?')"
                position="top"
                @confirm="disconnect">
                <template #reference>
                    <el-button type="danger" plain>
                        {{ translate('Disconnect') }}
                    </el-button>
                </template>
            </el-popconfirm>
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
