<template>
    <div class="fct-integration-settings fct-layout-width">
        <CardContainer>
            <CardHeader :title="$t('Product Integrations')">
                <template #action>
                    <div
                        v-if="hasActiveModules"
                        class="integration-actions"
                    >
                        <el-dropdown
                            @command="add"
                            trigger="click"
                            popper-class="fct-dropdown"
                        >
                            <el-button type="info" plain size="small">
                                {{ $t("Add Integration") }}
                                <DynamicIcon name="ChevronDown"/>
                            </el-button>
                            <template #dropdown>
                                <el-dropdown-menu>
                                    <template
                                        v-for="( integration, integration_name ) in available_integrations"
                                        :key="integration_name"
                                    >
                                        <el-dropdown-item :command="integration_name">
                                            {{ integration.title }}
                                        </el-dropdown-item>
                                    </template>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                    </div>
                </template>
            </CardHeader>
            <CardBody class="px-0 pb-0 pt-4">
                <el-skeleton class="px-5 pb-5" :loading="loading" animated :rows="6"/>

                <template v-if="!loading">

                    <div
                        v-if="!hasActiveModules"
                        class="fct-empty-integration-wrap px-4 text-center mx-auto mb-5 md:max-w-[600px]"
                    >
                        <p class="text-sm">
                            {{
                                $t( "You don't have any integration module enabled. Please go to integration modules and enable and configured from available modules")
                            }}
                        </p>

                        <el-button tag="a" type="primary" :href="all_module_config_url">
                          {{ $t("Configure Modules") }}
                        </el-button>
                    </div>

                    <div v-else class="fct-integrations-table-wrap">
                        <el-table v-loading="loading" :data="feeds">
                            <el-table-column :label="$t('Status')" :width="90">
                                <template #default="scope">
                                    <el-switch
                                        active-value="yes"
                                        inactive-value="no"
                                        @change="handleActive(scope.row)"
                                        v-model="scope.row.enabled"
                                    />
                                </template>
                            </el-table-column>

                            <el-table-column :width="160" :label="$t('Integration')">
                                <template #default="scope">
                                    <div class="fct-integration-logo-wrap">
                                        <img
                                            style="min-width: 24px;"
                                            v-if="available_integrations[scope.row.provider]?.logo"
                                            class="fct-integration-logo"
                                            :src="available_integrations[scope.row.provider].logo"
                                            :alt="scope.row.provider"
                                        />
                                        <span class="text-xs text-gray-700">
                                            {{ available_integrations[scope.row.provider]?.title || scope.row.provider }}
                                        </span>
                                    </div>
                                </template>
                            </el-table-column>

                            <el-table-column :min-width="150" :label="$t('Title')">
                                <template #default="scope">
                                    <a class="fct-action-link-btn" @click.prevent="edit(scope.row)" href="#">
                                        {{ scope.row.name }}
                                    </a>
                                </template>
                            </el-table-column>

                            <el-table-column :min-width="150" :label="$t('Triggers')">
                                <template #default="scope">
                                    <div class="fct-event-triggers">
                                        <el-tag
                                            v-for="(event, index) in (scope.row.feed.event_trigger || []).slice(0, 2)"
                                            :key="event">
                                            <DynamicIcon name="Trigger"/>
                                            {{ Str.headline(event) }}
                                        </el-tag>

                                        <el-popover
                                            v-if="(scope.row.feed.event_trigger || []).length > 2"
                                            placement="bottom"
                                            width="300"
                                            trigger="hover"
                                        >
                                            <template #reference>
                                                <el-tag type="info">
                                                    +{{ (scope.row.feed.event_trigger || []).length - 2 }}
                                                </el-tag>
                                            </template>

                                            <div class="fct-event-triggers">
                                                <el-tag
                                                    v-for="(event, idx) in (scope.row.feed.event_trigger || []).slice(2)"
                                                    :key="event + idx"
                                                >
                                                    <DynamicIcon name="Trigger"/>
                                                    {{ Str.headline(event) }}
                                                </el-tag>
                                            </div>
                                        </el-popover>
                                    </div>
                                </template>
                            </el-table-column>

                            <el-table-column :width="90">
                                <template #default="scope">
                                    <div class="fct-btn-group sm">
                                        <el-tooltip
                                            effect="dark"
                                            :content="$t('Edit')"
                                            placement="top"
                                            popper-class="fct-tooltip"
                                        >
                                            <IconButton
                                                tag="button"
                                                @click="edit(scope.row)"
                                                size="x-small"
                                                hover="primary"
                                            >
                                                <DynamicIcon name="Edit"/>
                                            </IconButton>
                                        </el-tooltip>

                                        <el-tooltip
                                            effect="dark"
                                            :content="$t('Delete')"
                                            placement="top"
                                            popper-class="fct-tooltip"
                                        >
                                            <IconButton
                                                tag="button"
                                                size="x-small"
                                                @click="remove(scope.row.id, scope)"
                                                hover="danger"
                                            >
                                                <DynamicIcon name="Delete"/>
                                            </IconButton>
                                        </el-tooltip>
                                    </div>
                                </template>
                            </el-table-column>

                            <template #empty>
                                <Empty
                                    icon="Folder"
                                    :text="$t('No Integrations Available.')"
                                />
                            </template>
                        </el-table>
                    </div>

                    <div class="fct-integration-table-footer">
                        <a :href="all_module_config_url">{{
                                $t("Check Global Integration Settings")
                            }}</a>
                        <a
                            target="_blank"
                            rel="noopener"
                            href="https://docs.fluentcart.com">
                            {{ $t("View Documentations") }}
                        </a>
                    </div>
                </template>
            </CardBody>
        </CardContainer>
    </div>
</template>

<script type="text/babel">
import {
    Container as CardContainer,
    Header as CardHeader,
    Body as CardBody,
    Footer as CardFooter
} from '@/Bits/Components/Card/Card.js';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {isEmpty} from "@/utils/Utils";
import Str from "@/utils/support/Str";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

export default {
    name: 'ProductIntegrations',
    props: ['product', 'product_id'],
    components: {
        IconButton, Empty,
        DynamicIcon,
        CardFooter,
        CardBody,
        CardHeader,
        CardContainer
    },
    data() {
        return {
            product_id: this.product_id,
            selectedIntegrationType: '',
            integrationLists: [],
            showIntegrationDialog: false,
            feeds: [],
            available_integrations: {},
            all_module_config_url: {},
            totalActiveModules: 0,
            loading: false
        }
    },
    computed: {
        Str() {
            return Str
        },
        hasActiveModules() {
            return Object.values(this.available_integrations).some(integration => integration.enabled);
        },
    },
    methods: {
        isEmpty,
        fetchIntegrations() {
            this.loading = true;
            this.$get(`products/${this.product_id}/integrations`)
                .then(response => {
                    this.available_integrations = response.available_integrations;
                    this.feeds = response.feeds;
                    this.all_module_config_url = response.all_module_config_url;
                })
                .catch(error => {
                    this.handleError(error.message || 'Failed to load integrations');
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        add(integration_name) {
            let integration = this.available_integrations[integration_name];
            if (!integration.enabled) {
                // Handle Inactive state
                this.$confirm(
                    integration.configure_message,
                    integration.configure_title,
                    {
                        confirmButtonText: integration.configure_button_text,
                        cancelButtonText: "Cancel",
                        cancelButtonClass: "el-button--info is-plain",
                        type: "warning",
                    }
                ).then(() => {
                    window.location.href = integration.global_configure_url;
                    return;
                });
                return;
            }

            this.$router.push({
                name: 'product_integration_feed_editor',
                params: {
                    integration_id: 0,
                    integration_name: integration_name,
                },
            });
            return;
        },
        edit(integration) {
            this.$router.push({
                name: "product_integration_feed_editor",
                params: {
                    integration_id: integration.id,
                    integration_name: integration.provider,
                },
            });
        },
        handleActive(row) {
            let data = {
                status: row.enabled,
                notification_id: row.id,
            };
            this.$post(`products/${this.product_id}/integrations/feed/change-status`, data)
                .then((response) => {
                    this.handleSuccess(response.message);
                })
                .catch((error) => {
                    this.handleError(error.responseJSON.data.message);
                });
        },
        remove(id, scope) {
            let $index = scope.$index;
            let data = {
                integration_id: id,
            };

            this.$confirm(
                "Are you sure you want to delete this integration?",
                "Confirm Delete!",
                {
                    confirmButtonText: "Yes, Delete!",
                    cancelButtonText: "Cancel",
                    confirmButtonClass: "el-button--primary",
                    cancelButtonClass: "el-button--info is-plain",
                    type: "warning",
                }
            )
                .then(() => {
                    this.$del(`products/${this.product_id}/integrations/${id}`, data)
                        .then((response) => {
                            this.handleSuccess(response.message);
                            this.feeds.splice($index, 1);
                        })
                        .catch((e) => console.log(e));
                })
                .catch(() => {
                    // cancel response....
                });
        },
        handleSuccess(message) {
            this.$notify({
                type: "success",
                message: message,
                offset: 40
            });
        },
        handleError(message) {
            this.$notify({
                type: "error",
                message: message,
                offset: 40
            });
        }
    },
    mounted() {
        this.fetchIntegrations();
        const header = document.querySelector('#fct_admin_menu_holder .fct-admin-product-header');
        if (header) {
            const app = document.querySelector('#fluent_cart_plugin_app');
            app.prepend(header);
        }
    }
}
</script>
