<template>
    <div class="setting-wrap">
        <CardContainer class="overflow-hidden">
            <CardHeader :title="$t('Webhooks')">
                <template #action>
                    <el-button type="primary" tag="router-link" to="/settings/webhooks/0">
                        {{ $t("Add Webhook") }}
                    </el-button>
                </template>
            </CardHeader>
            <CardBody class="px-0 pb-0 pt-4">
                <el-skeleton class="px-5 pb-5" :loading="loading" animated :rows="5"/>

                <div v-if="!loading" class="fct-webhook-table">
                    <el-table :data="feeds">
                        <el-table-column :width="250" :label="$t('Name')">
                            <template #default="scope">
                                <div class="fct-integration-table-content">
                                    <div class="title"> {{ scope.row.name }}</div>
                                    <div class="text">{{ scope.row.settings.request_url }}</div>
                                </div>
                            </template>
                        </el-table-column>

                        <el-table-column :label="$t('Triggers')" :width="160">
                            <template #default="scope">
                                <div class="fct-event-triggers">
                                    <el-tag v-for="(event, index) in scope.row.event_trigger.slice(0, 2)" :key="event">
                                        <DynamicIcon name="Trigger"/>
                                        {{ Str.headline(event) }}
                                    </el-tag>

                                    <el-popover
                                        v-if="scope.row.event_trigger.length > 2"
                                        placement="bottom"
                                        width="300"
                                        trigger="hover"
                                    >
                                        <template #reference>
                                            <el-tag type="info">
                                                +{{ scope.row.event_trigger.length - 2 }}
                                            </el-tag>
                                        </template>

                                        <div class="fct-event-triggers">
                                            <el-tag
                                                v-for="(event, idx) in scope.row.event_trigger.slice(2)"
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

                        <el-table-column :label="$t('Enabled')" :width="40">
                            <template #default="scope">
                                <el-switch v-model="scope.row.status" @change="handleActive(scope.row)"/>
                            </template>
                        </el-table-column>

                        <el-table-column :width="50">
                            <template #default="scope">
                                <div class="fct-btn-group sm justify-end">
                                    <el-tooltip
                                        effect="dark"
                                        :content="$t('Edit')"
                                        placement="top"
                                        popper-class="fct-tooltip"
                                    >
                                        <IconButton
                                            tag="button"
                                            size="x-small"
                                            hover="primary"
                                            @click="editWebhook(scope.row)"
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
                                            hover="danger"
                                            @click="deleteWebhook(scope)"
                                        >
                                            <DynamicIcon name="Delete"/>
                                        </IconButton>
                                    </el-tooltip>
                                </div>
                            </template>
                        </el-table-column>
                        <template #empty>
                            <Empty icon="Empty/ListView" :has-dark="true" :text="$t('No webhooks found.')"/>
                        </template>
                    </el-table>
                </div>
            </CardBody>
        </CardContainer>
    </div>
</template>

<script>
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Str from "@/utils/support/Str";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

export default {
    name: "Webhooks",
    computed: {
        Str() {
            return Str
        }
    },
    components: {
        IconButton, Empty,
        DynamicIcon,
        CardContainer: Card.Container,
        CardBody: Card.Body,
        CardHeader: Card.Header
    },
    data() {
        return {
            feeds: [],
            loading: false,
            event_trigger_options: []
        };
    },
    methods: {
        fetchWebhookFeeds() {
            this.loading = true;
            this.$get('webhook/feed')
                .then(response => {
                    this.feeds = response.feeds.map(feed => {
                        return {
                            ...feed,
                            event_trigger: Array.isArray(feed.event_trigger) ? feed.event_trigger : []
                        };
                    });
                    this.event_trigger_options = response.event_trigger_options;
                })
                .catch(errors => {
                    this.handleError(errors);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        editWebhook(row) {
            this.$router.push({
                name: "webhooks_settings",
                params: {
                    feed_id: row.id
                },
            });
        },
        handleActive(row) {
            let data = {
                status: row.status,
                feed_id: row.id,
            };
            this.$post('webhook/feed/' + row.id + '/change-status', data)
                .then((response) => {
                    this.handleSuccess(response.message);
                })
                .catch((error) => {
                    this.handleError(error?.data?.message || 'Failed to update webhook status');
                });
        },
        deleteWebhook(scope) {
            this.$confirm('Are you sure you want to delete this webhook?', 'Confirm Delete!',
                {
                    confirmButtonText: 'Yes, Delete!',
                    cancelButtonText: 'Cancel',
                    type: 'warning'
                }
            )
                .then(() => {
                    this.$del('webhook/feed/' + scope?.row.id)
                        .then(response => {
                            this.handleSuccess(response.message);
                            this.feeds.splice(scope.$index, 1);
                        })
                        .catch(errors => {
                            this.handleError(errors);
                        });
                })
                .catch(() => {
                    // cancel response....
                });
        }
    },
    mounted() {
        this.fetchWebhookFeeds();
    }

}
</script>
