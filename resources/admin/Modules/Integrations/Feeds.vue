<template>
    <div class="setting-wrap">
        <Card.Container class="overflow-hidden">
            <Card.Header :title="$t('Global Integrations Feeds')"
                         :text="$t('These integration feeds will be applied globally across all your orders and related actions.')"
            >
                <template #action>
                    <div
                        v-if="hasActiveIntegrations"
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
                                        v-for="(
                      integration, integration_name
                    ) in available_integrations"
                                        :key="integration_name"
                                    >
                                        <el-dropdown-item
                                            :command="integration_name"
                                        >
                                            {{ integration.title }}
                                        </el-dropdown-item>
                                    </template>
                                </el-dropdown-menu>
                            </template>
                        </el-dropdown>
                    </div>
                </template>
            </Card.Header>
            <Card.Body class="px-0 pb-0 pt-4">
                <el-skeleton class="px-5 pb-5" :loading="loading" animated :rows="6"/>

                <template v-if="!loading">

                    <div v-if="!hasActiveIntegrations"
                         class="fct-empty-integration-wrap text-center max-w-[600px] mx-auto mb-5">
                        <p class="text-base">
                            {{
                                $t("You don't have any integration module enabled. Please go to integration modules and enable and configured from available modules")
                            }}
                        </p>
                        <el-button
                            tag="a"
                            type="primary"
                            :href="all_module_config_url"
                        >{{ $t("Configure Modules") }}
                        </el-button
                        >
                    </div>

                    <div v-else class="fct-integrations-table-wrap">
                        <el-table v-loading="loading" :data="feeds">
                            <el-table-column :label="$t('Enabled')" :width="60">
                                <template #default="scope">
                                    <el-switch
                                        active-value="yes"
                                        inactive-value="no"
                                        @change="handleActive(scope.row)"
                                        v-model="scope.row.enabled"
                                    />

                                </template>
                            </el-table-column>

                            <el-table-column :width="120" :label="$t('Integration')">
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

                            <el-table-column :width="220" :label="$t('Title')">
                                <template #default="scope">
                                    <a class="fct-action-link-btn" @click.prevent="edit(scope.row)" href="#">
                                        {{ scope.row.name }}
                                    </a>
                                </template>
                            </el-table-column>

                            <el-table-column :width="160" :label="$t('Triggers')">
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

                            <el-table-column :width="80">
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
                                                @click="removeFeed(scope.row.id, scope)"
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
                                    :text="$t('You did not add any integration actions yet.')"
                                />
                            </template>
                        </el-table>
                    </div>

                    <div v-if="!feeds.length" class="fct-integration-table-footer">
                        <a
                            target="_blank"
                            rel="noopener"
                            href="https://docs.fluentcart.com"
                        >{{ $t("View Documentations") }}</a
                        >
                    </div>
                </template>
            </Card.Body>
        </Card.Container>
    </div>
    <!-- .setting-wrap -->
</template>

<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Str from "../../utils/support/Str.js";
</script>

<script>
import {isEmpty} from "@/utils/Utils";
export default {
    name: "GloablIntegration",
    props: ["editorShortcodes"],
    data() {
        return {
            loading: true,
            feeds: [],
            available_integrations: {},
            all_module_config_url: '',
        };
    },
    computed: {
        hasActiveIntegrations() {
            return Object.values(this.available_integrations).some(
                (integration) => integration.enabled
            );
        },
    },
    methods: {
        getFeeds() {
            this.loading = true;
            this.$get('integration/global-feeds')
                .then((response) => {
                    this.available_integrations = response.available_integrations;
                    this.feeds = response.feeds;
                    this.all_module_config_url = response.all_module_config_url;
                })
                .catch((error) => {
                    this.loading = false;
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        handleActive(row) {
            let data = {
                status: row.enabled
            };

            this.$post(`integration/global-feeds/change-status/${row.id}`, data)
                .then((response) => {
                    this.handleSuccess(response.message);
                })
                .catch((error) => {
                    this.handleError(error?.data?.message);
                });
        },

        removeFeed(id, scope) {
            let $index = scope.$index;
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
                    this.$del(`integration/global-feeds/${id}`)
                        .then((response) => {
                            this.handleSuccess(response.message);
                            this.feeds.splice($index, 1);
                        })
                        .catch((e) => {
                            this.handleError(e?.data?.message || "Failed to delete integration.");
                        });
                })
                .catch(() => {
                    // cancel response....
                });
        },

        add(integration_name) {
            let integration = this.available_integrations[integration_name];
            if (integration && integration.enabled) {

                this.$router.push({
                    name: "feed_editor",
                    params: {
                        integration_id: 0,
                        integration_name: integration_name,
                    },
                });
            } else {
                this.$notify.error({
                    message: this.$t(
                        "This integration is not enabled. Please enable it from the integration modules."
                    ),
                });
            }
        },
        edit(integration) {
            this.$router.push({
                name: "feed_editor",
                params: {
                    integration_id: integration.id,
                    integration_name: integration.provider,
                },
            });
        },
        isEmpty,
    },
    mounted() {
        this.getFeeds();
    },
};
</script>
