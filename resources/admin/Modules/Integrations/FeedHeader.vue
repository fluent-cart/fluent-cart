<template>
    <div class="fct-feed-header mb-5">
        <div class="mb-5 flex items-center justify-between">
            <el-breadcrumb :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="prevRoute">
                    {{ $t("Integrations") }}
                </el-breadcrumb-item>
                <el-breadcrumb-item>
                    <span class="capitalize">{{ integration_name }}</span>
                </el-breadcrumb-item>
            </el-breadcrumb>
            <el-switch
                v-model="settings.enabled"
                active-value="yes"
                inactive-value="no"
                :active-text="$t('Enable this Integration')"
            />
        </div>
    </div>
</template>

<script type="text/babel">
import {ArrowRight} from "@element-plus/icons-vue";

export default {
    name: 'FeedHeader',
    props: {
        integration_name: String,
        settings: Object
    },
    computed: {
        ArrowRight() {
            return ArrowRight
        },
        prevRoute() {
            if (this.$route.name === 'product_integration_feed_editor') {
                return {
                    name: 'product_integrations',
                    params: {
                        product_id: this.$route.params.product_id
                    }
                }
            } else {
                return {
                    name: 'integrations'
                }
            }
        }
    }

}
</script>

