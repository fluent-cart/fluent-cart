<template>
    <el-button text class="underline-link-button" @click="showActivatedSitesModal" :aria-label="$t('View list of activated sites')">
        {{ $t('View List') }}
    </el-button>

    <el-dialog 
        v-model="activatedSitesModal" 
        :append-to-body="true" 
        :title="$t('License Activations')"
        modal-class="fct-activated-sites-modal"
        role="dialog"
        aria-labelledby="activated-sites-title"
        aria-describedby="activated-sites-description"
    >

        <h2 id="activated-sites-title" class="sr-only">{{ $t('License Activations') }}</h2>
        <p id="activated-sites-description" class="sr-only">
            {{ $t('List of sites where the license is activated') }}
        </p>

        <div
            v-loading="loading"
            class="fct-table-style-two fct-activated-sites-table"
            aria-live="polite"
            id="activated-sites-description"
            :class="activations?.length > 10 ? 'scrollable' : ''"
        >
            <el-table :data="activations" stripe role="grid"
        :aria-label="$t('Table of activated sites')">
                <el-table-column :label="$t('Site')" :min-width="270">
                    <template #default="scope">
                        <span>{{ scope.row.site_url }}</span>
                        <span v-if="scope.row.is_local != '0'"
                            style="margin-left: 10px;"
                            role="status"
                            :aria-label="$t('local')"
                        >
                            <Badge :status="'warning'" size="small" :hide-icon="true" :text="$t('local')"/>
                        </span>
                    </template>
                </el-table-column>

                <el-table-column :label="$t('Status')" :width="100">
                    <template #default="scope">
                        <Badge :status="scope.row.status" size="small" :hide-icon="true" :aria-label="`${$t('Status')}: ${$t(scope.row.status)}`"/>
                    </template>
                </el-table-column>

                <el-table-column :width="60">
                    <template #default="scope">
                        <span>
                          <el-popconfirm
                              :width="235"
                              :confirm-button-text="$t('Confirm')"
                              :cancel-button-text="$t('No, Thanks')"
                              icon-color="red"
                              :title="$t('Are you sure to disconnect?')"
                              @confirm="deactivate(scope.row)"
                              aria-labelledby="delete-confirm-title"
                          >
                            <template #reference>
                              <IconButton 
                                tag="a" 
                                size="x-small" 
                                hover="danger" 
                                :aria-label="$t('Delete activation for site')"
                                :aria-describedby="`site-${scope.$index}`"
                            >
                                <DynamicIcon name="TrashIcon"/>
                              </IconButton>
                            </template>
                          </el-popconfirm>

                          <span :id="`site-${scope.$index}`" class="sr-only">{{ scope.row.site_url }}</span>
                          
                          <span id="delete-confirm-title" class="sr-only">
                            {{ $t('Are you sure to disconnect the site') }} {{ scope.row.site_url }}?
                          </span>
                        </span>
                    </template>
                </el-table-column>

                <template #empty>
                    <span role="alert">{{ $t('No activations found for this license') }}</span>
                </template>

            </el-table>
        </div>
    </el-dialog>
</template>

<script>
import Badge from '@/Bits/Components/Badge.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';

export default {
    props: ['license_key'],
    components: {
        Badge,
        DynamicIcon,
        IconButton
    },
    data() {
        return {
            loading: false,
            activations: [],
            activatedSitesModal: false
        };
    },
    methods: {
        showActivatedSitesModal() {
            this.activatedSitesModal = true;
            this.fetchActivatedSites();
        },
        fetchActivatedSites() {
            this.loading = true;
            this.$get(`customer-profile/licenses/${this.license_key}/activations`)
                .then((response) => {
                    this.activations = response.activations;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        deactivate(site) {
            this.$post(`customer-profile/licenses/${this.license_key}/deactivate_site`, {
                site_url: site.site_url
            })
                .then((response) => {
                    this.$notify.success(response.message);
                    this.activations = this.activations.filter((activation) => activation.site_url !== site.site_url);
                })
                .catch((error) => {
                    this.handleError(error);
                });
        }
    }
};
</script>
