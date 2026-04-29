<template>
    <div class="fct-connect-details">
        <!-- <h4 class="fct-connect-mode">{{ Stripe }}</h4> -->
        <div v-if="!connect || connect.error" class="fct-connect-require">
            <h4> {{
                /* translators: %s: payment method name */
                translate('Connect Your %s Account to your website to accept Payments', methodName)
              }}</h4>
            <el-button tag="a" type="info" plain :href="connect_config[mode+'_redirect']">
                <DynamicIcon name="ArrowLeftRight"/>
                {{
                    /* translators: %s: payment method name */
                    translate('Connect with %s', methodLabel)
                }}
            </el-button>
        </div>
        <ContentCard v-else
                     :title="
                      /* translators: %s: payment method name */
                      translate('Your %s Account is Up & Running 🎉', methodLabel)
                      ">
            <div class="flex items-center justify-between">
                <div>
                    <p class="display-name" v-if="connect.display_name">{{ connect.display_name }}</p>
                    <h4>{{ $t('Administrator') }} ({{ $t('Owner') }})</h4>
<!--                    <p v-if="connect.email" class="email">{{ connect.email }}</p>-->
                </div>
                <el-popconfirm
                    width="230"
                    :confirm-button-text="$t('Confirm')"
                    :cancel-button-text="$t('No, Thanks')"
                    icon="el-icon-info"
                    icon-color="red"
                    :title="connect_config?.disconnect_note ?? $t('Are you sure to disconnect?')"
                    position="top"
                    @confirm="disconnect">
                    <template #reference>
                        <el-button type="danger" plain>
                          {{
                            /* translators: %s: payment method name */
                            translate('Disconnect %s', methodLabel)
                          }}
                        </el-button>
                    </template>
                </el-popconfirm>
            </div>
        </ContentCard>
    </div>
</template>


<script setup>
    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
    import ContentCard from '@/Bits/Components/Card/ContentCard.vue';
    import Str from "@/utils/support/Str";
import translate from "../../../utils/translator/Translator";
</script>

<script type="text/babel">
export default {
    name: 'ConnectAccount',
    props: ['connect', 'connect_config', 'mode', 'method', 'methodName', 'methodLabel'],
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
