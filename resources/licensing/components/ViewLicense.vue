<template>
    <div>

        <SingleLicenseLoader v-if="loading"/>
        <NotFound v-if="notFound.show" :button-text="notFound.buttonText" :message="notFound.message"
                  :route="notFound.route"/>
        <div v-if="notFound.show === false" class="fct-view-license-wrap fct-layout-width">
            <template v-if="!loading">
                <template v-if="license">
                    <div class="single-page-header">
                        <el-breadcrumb class="hide-license-breadcrumb-on-mobile" :separator-icon="ArrowRight">
                            <el-breadcrumb-item :to="{ name: 'licenses' }">{{
                                    translate('Licenses')
                                }}
                            </el-breadcrumb-item>
                            <el-breadcrumb-item>
                                <div class="flex items-center gap-3 flex-wrap whitespace-pre-wrap" style="overflow-wrap: anywhere;">
                                    {{ license?.license_key }}
                                    <Badge :status="license.status" size="small"/>
                                </div>
                            </el-breadcrumb-item>
                        </el-breadcrumb>

                      <!-- mobile view -->
                      <ul class="license-breadcrumb-only-mobile">
                        <li>
                          <router-link :to="{ name: 'licenses' }">
                            <DynamicIcon name="ArrowLeft" class="cursor-pointer"/>
                          </router-link>
                        </li>
                        <li>{{ license?.license_key }}</li>
                      </ul>
                      <!-- mobile view -->
                    </div><!-- .single-page-header -->

                    <div class="single-page-body">
                        <el-row :gutter="30">
                            <el-col :lg="17">
                                <div class="fct-view-license-content-wrap">
                                    <Card.Container>
                                        <Card.Header border_bottom title_size="small"
                                                     :title="translate('License Details')">
                                            <template #action>
                                              <el-dropdown
                                                  trigger="click"
                                                  class="fct-more-option-wrap"
                                                  popper-class="fct-dropdown"
                                                  @command="handleLicenseCommand"
                                                  placement="bottom-end"
                                              >
                                                  <span class="more-btn">
                                                    <DynamicIcon name="More"/>
                                                  </span>

                                                <template #dropdown>
                                                  <el-dropdown-menu>

                                                    <el-dropdown-item
                                                        command="delete_license"
                                                        class="item-destructive"
                                                    >
                                                      <DynamicIcon name="Delete"/>
                                                      {{ translate('Delete License') }}
                                                    </el-dropdown-item>
                                                  </el-dropdown-menu>
                                                </template>
                                              </el-dropdown>
                                            </template>
                                        </Card.Header>

                                        <Card.Body>
                                            <LicenseInformation
                                                :license="license"
                                                :customerId="customerId"
                                                :license_id="license_id"
                                                :activations="activations"
                                                :product="product"
                                                :order="order"
                                                :upgrade_path_base="upgrade_path_base"
                                                @fetchLicense="fetchLicense"
                                            />
                                        </Card.Body>
                                    </Card.Container>

                                    <Card.Container class="overflow-hidden">
                                        <Card.Header :title="translate('Related Orders')" title_size="small"/>
                                        <Card.Body class="px-0 pb-0">
                                            <div class="fct-related-license-content">
                                                <el-table :data="orders" class="w-full fct-license-related-orders-hide-on-mobile">
                                                    <el-table-column :width="120" :label="translate('ID')">
                                                        <template #default="scope">
                                                            <router-link class="link"
                                                                         :to="{name: 'view_order', params: { order_id: scope.row.id } }">
                                                                #{{ translateNumber(scope.row.id) }}
                                                            </router-link>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="120" :label="translate('Amount')">
                                                        <template #default="scope">
                                                            <span>{{ formatNumber(scope.row.total_paid) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="150" :label="translate('Date')" prop="created_at">
                                                        <template #default="scope">
                                                            <span>{{ formatDate(scope.row.created_at) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :label="translate('Payment Method')">
                                                      <template #default="scope">
                                                        {{ scope.row.payment_method }}
                                                      </template>
                                                    </el-table-column>
                                                    <el-table-column :label="translate('Status')">
                                                        <template #default="scope">
                                                            <Badge :type="scope.row.payment_status">
                                                                {{ scope.row.payment_status }}
                                                            </Badge>
                                                            <Badge style="margin-left: 5px; margin-right: 5px;"
                                                                   :type="scope.row.type">
                                                                {{ scope.row.type }}
                                                            </Badge>
                                                            <OrderUpDownIndicator :order="scope.row"/>
                                                        </template>
                                                    </el-table-column>
                                                </el-table>

                                              <!-- mobile view -->
                                                <div class="fct-license-related-orders-show-on-mobile fct-table-mobile-wrap">
                                                  <div
                                                      v-for="(row, rowIndex) in orders" :key="rowIndex"
                                                      class="fct-table-mobile-row"
                                                  >
                                                    <div class="fct-table-mobile-header">
                                                      <div class="fct-table-date-col">
                                                        {{ formatDate(row.created_at) }}
                                                        <div class="bullet">•</div>
                                                        <router-link
                                                            class="link"
                                                            :to="{name: 'view_order', params: { order_id: row.id } }"
                                                        >
                                                          #{{ row.id }}
                                                        </router-link>
                                                      </div>

                                                      <div class="fct-table-price-col">
                                                        {{ formatNumber(row.total_paid) }}
                                                      </div>
                                                    </div><!-- fct-table-mobile-header -->

                                                    <div class="fct-table-mobile-footer">
                                                      <div class="fct-table-mobile-footer-row">
                                                        <div>
                                                          <div class="title">{{translate('Status')}}</div>
                                                          <div class="fct-table-status-col flex items-center gap-1">
                                                            <Badge :type="row.payment_status">
                                                              {{ row.payment_status }}
                                                            </Badge>

                                                            <Badge :type="row.type">
                                                              {{ row.type }}
                                                            </Badge>

                                                            <OrderUpDownIndicator :order="row"/>
                                                          </div>
                                                        </div>
                                                        <div>
                                                          <div class="title">{{translate('Payment Method')}}</div>
                                                          <div class="value capitalize">
                                                            {{ row.payment_method }}
                                                          </div>
                                                        </div>
                                                      </div>
                                                    </div><!-- fct-table-mobile-footer -->



                                                  </div><!-- fct-license-related-orders-mobile-item -->
                                                </div>
                                              <!-- mobile view -->
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <Card.Container v-if="prevOrders && prevOrders.length" class="overflow-hidden">
                                        <Card.Header :title="translate('Previous Orders')" title_size="small"/>
                                        <Card.Body class="px-0 pb-0">
                                            <div class="fct-related-license-content">
                                                <el-table :data="prevOrders" class="w-full">
                                                    <el-table-column :width="120" :label="translate('ID')">
                                                        <template #default="scope">
                                                            <router-link class="link"
                                                                         :to="{name: 'view_order', params: { order_id: scope.row.id } }">
                                                                #{{ translateNumber(scope.row.id) }}
                                                            </router-link>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="120" :label="translate('Amount')">
                                                        <template #default="scope">
                                                            <span>{{ formatNumber(scope.row.total_paid) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :width="150" :label="translate('Date')" prop="created_at">
                                                        <template #default="scope">
                                                            <span>{{ formatDate(scope.row.created_at) }}</span>
                                                        </template>
                                                    </el-table-column>
                                                    <el-table-column :label="translate('Status')">
                                                        <template #default="scope">
                                                            <Badge :type="scope.row.payment_status">
                                                                {{ scope.row.payment_status }}
                                                            </Badge>
                                                            <Badge style="margin-left: 5px; margin-right: 5px;"
                                                                   :type="scope.row.type">
                                                                {{ scope.row.type }}
                                                            </Badge>
                                                            <OrderUpDownIndicator :order="scope.row"/>
                                                        </template>
                                                    </el-table-column>
                                                </el-table>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <Card.Container class="overflow-hidden">
                                        <Card.Header :title="translate('Activated Sites')" title_size="small"
                                                     border_bottom/>
                                        <Card.Body>
                                            <ActivatedSiteInformation
                                                v-model="site_url"
                                                @siteActivated="activateSite"
                                                @siteDeactivated="deactivateSite"
                                                :activatedSites="activatedSites"
                                                :activationLimit="limit"
                                                :activationCount="activations.length"
                                            />
                                            <!--                              <div class="fct-activated-site-url-container grid gap-5 hidden">-->
                                            <!--                                  <div class="fct-input-with-action-wrap">-->
                                            <!--                                      <el-input :prefix-icon="Link" v-model="site_url" placeholder="New site URL (including http://)"></el-input>-->
                                            <!--                                      <div class="fct-input-with-action">-->
                                            <!--                                          <el-button type="info" size="small" soft @click="activateSite">-->
                                            <!--                                              <DynamicIcon name="Plus" />-->
                                            <!--                                              {{ $t('Add Site') }}-->
                                            <!--                                          </el-button>-->
                                            <!--                                      </div>-->
                                            <!--                                  </div>-->
                                            <!--                                  <div class="-mx-6 -mb-6 fct-license-site-url-table-wrap" v-if="Object.keys(activatedSites).length > 0">-->
                                            <!--                                      <table class="fct-license-site-url-table">-->
                                            <!--                                          <thead>-->
                                            <!--                                          <tr>-->
                                            <!--                                              <th>{{$t('Site URL')}}</th>-->
                                            <!--                                              <th></th>-->
                                            <!--                                          </tr>-->
                                            <!--                                          </thead>-->
                                            <!--                                          <tbody>-->
                                            <!--                                          <tr v-for="(url, activationId) in activatedSites" :key="activationId">-->
                                            <!--                                              <td>-->
                                            <!--                                                  <span class="text">{{url}}</span>-->
                                            <!--                                              </td>-->
                                            <!--                                              <td>-->
                                            <!--                                                  <el-popconfirm-->
                                            <!--                                                          width="200"-->
                                            <!--                                                          :confirm-button-text="$t('Confirm')"-->
                                            <!--                                                          :cancel-button-text="$t('No, Thanks')"-->
                                            <!--                                                          :title="$t('Are you sure?')"-->
                                            <!--                                                          @confirm="deactivateSite(activationId)"-->
                                            <!--                                                  >-->
                                            <!--                                                      <template #reference>-->
                                            <!--                                                          <IconButton tag="a" size="small" class="ml-auto el-button&#45;&#45;x-small">-->
                                            <!--                                                              <DynamicIcon name="TrashIcon"/>-->
                                            <!--                                                          </IconButton>-->
                                            <!--                                                      </template>-->
                                            <!--                                                  </el-popconfirm>-->
                                            <!--                                              </td>-->
                                            <!--                                          </tr>-->
                                            <!--                                          </tbody>-->
                                            <!--                                      </table>-->
                                            <!--                                  </div>-->
                                            <!--                              </div>-->
                                        </Card.Body>
                                    </Card.Container>

                                    <Card.Container v-if="logs.length > 0">

                                        <Card.Body>
                                            <div
                                                class="value-column-with-space"
                                                style="max-height: 500px; overflow-y: auto"
                                            >
                                                <el-timeline style="padding-left: 2px">
                                                    <el-timeline-item
                                                        v-for="log in logs"
                                                        :key="log"
                                                        :timestamp="dayjs(license?.updated_at).format('MMMM DD, YYYY')"
                                                        :placement="'top'"
                                                        :color="'blue'"
                                                    >
                                                        <div v-html="log.meta_value"></div>
                                                    </el-timeline-item>
                                                </el-timeline>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>
                                </div>
                            </el-col>
                            <el-col :lg="7">
                                <div class="fct-admin-sidebar">

                                    <OrderCustomerInformation
                                        :order="order"
                                        :shouldEnableEditing="true"
                                        @onAddressSelected="(address)=>{
                          if (address.type === 'shipping') {
                            order.shipping_address_id = address.id;
                          } else {
                            order.billing_address_id = address.id;
                          }
                      }"
                                    />

                                    <Card.Container>
                                        <Card.Header :title="translate('Actions')" title_size="small" border_bottom/>
                                        <Card.Body>
                                            <div class="fct-admin-sidebar-item">
                                                <ul class="fct-lists">
                                                    <li class="!mb-0">
                                                        <el-popconfirm
                                                            v-if="license.status !== 'disabled'"
                                                            width="210"
                                                            :confirm-button-text="translate('Confirm')"
                                                            :cancel-button-text="translate('No, Thanks')"
                                                            :title="translate('Are you sure?')"
                                                            @confirm="updateLicenseStatus('disabled')"
                                                        >
                                                            <template #reference>
                                                                <el-button text class="!text-red-500 !mt-0">
<!--                                                                    <DynamicIcon name="Stop"/>-->
                                                                    {{ translate('Disable') }}
                                                                </el-button>
                                                            </template>
                                                        </el-popconfirm>
                                                        <el-popconfirm
                                                            v-if="license.status === 'disabled'"
                                                            width="210"
                                                            :confirm-button-text="translate('Confirm')"
                                                            :cancel-button-text="translate('No, Thanks')"
                                                            :title="translate('Are you sure?')"
                                                            @confirm="updateLicenseStatus('active')"
                                                        >
                                                            <template #reference>
                                                                <el-button text class="!text-primary-500 !mt-0">
<!--                                                                    <DynamicIcon name="CheckCircle"/>-->
                                                                    {{ translate('Enable') }}
                                                                </el-button>
                                                            </template>
                                                        </el-popconfirm>
                                                    </li>
                                                </ul>
                                            </div>
                                        </Card.Body>
                                    </Card.Container>

                                    <Labels
                                        v-if="!loading"
                                        :bind-to-id="license.id"
                                        bind-to-type="FluentCartPro\App\Modules\Licensing\Models\License"
                                        :selectedLabels="selectedLabels"
                                        :shouldEnableEditing="true"
                                        @update:update-label="()=>{
                        //onChangeLabel
                      }"
                                    />
                                </div><!-- .fct-admin-sidebar -->
                            </el-col>
                        </el-row>
                    </div><!-- .single-page-body -->
                </template>
            </template>
        </div>
    </div>
</template>

<script setup>
import {ArrowRight, Link} from "@element-plus/icons-vue";
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from "@/Bits/Components/Badge.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import dayjs from "dayjs";
import LicenseInformation from "./LicenseInformation.vue";
import ActivatedSiteInformation from "./ActivatedSiteInformation.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {isValidURL, handleError, handleSuccess, formatDate} from "@/Bits/common";
import Labels from "../../admin/Modules/Parts/Labels/Label.vue";
import SingleLicenseLoader from "./SingleLicenseLoader.vue";
import NotFound from "@/Pages/NotFound.vue";
import translateNumber from "../../admin/utils/translator/Translator";
import OrderCustomerInformation from "../../admin/Modules/Orders/OrderCustomerInformation.vue";
</script>

<script>
import {isValidURL} from "@/Bits/common";
import Arr from "../../admin/utils/support/Arr";
import translate from "../../admin/utils/translator/Translator";
import OrderUpDownIndicator from "../../admin/Bits/Components/OrderUpDownIndicator.vue";

export default {
    name: "ViewLicense",
    components: {
        OrderUpDownIndicator
    },
    props: ["license_id"],
    data() {
        return {
            changes_made: 0,
            isDateSelected: false,
            isCalendarOpen: false,
            activeTab: "license",
            activeMenu: "details",
            license: null,
            order: null,
            selectedDate: null,
            orders: [],
            prevOrders: [],
            activations: [],
            loading: true,
            saving: false,
            limit: null,
            site_url: null,
            upgrade_path_base: '',
            showConfirmationIcons: false,
            customerId: null,
            activatedSites: [],
            logs: [],
            selectedLabels: [],
            renewalNoticeValue: '',
            renewalNoticeOptions: [
                {
                    value: 'option1',
                    label: 'Need to connect backend',
                },
                {
                    value: 'option2',
                    label: 'Need to connect backend',
                },
            ],
            product: {},
            notFound: {
                show: false,
                message: '',
                buttonText: '',
                route: ''
            },
        };
    },
    watch: {
        license: {
            handler() {
                this.changes_made++;
            },
            deep: true,
            immediate: true
        },

        product: {
            handler() {
                if (this.product?.variants) {
                    this.variations = this.product.variants
                }
            },
            deep: true,
            immediate: true
        },
        license_id: {
            handler(newVal, oldVal) {
                this.fetchLicense();
            },
        },
    },
    methods: {
        handleLicenseCommand(command) {
          if (command === 'delete_license') {
            this.$confirm('Are you sure you want to delete this license?', 'Confirm Delete!', {
              confirmButtonText: 'Yes, Delete!',
              cancelButtonText: 'Cancel',
              confirmButtonClass: 'el-button--primary',
              cancelButtonClass: 'el-button--info is-plain',
              type: 'warning',
            })
                .then(() => {
                  this.deleteLicense();
                })
                .catch(() => {
                  // cancel response....
                });
          }
        },
        deleteLicense() {
          this.$del(`licensing/licenses/${this.license_id}/delete`)
              .then((response) => {
                this.handleSuccess(response.message);
                this.$router.push({name: 'licenses'});
              })
              .catch((error) => {
                this.handleError(error);
              });
        },
        updateUrl(index, newValue) {
            this.activatedSites[index] = newValue;
        },
        activateSite() {
            let $isValidURL = isValidURL(this.site_url);

            if ($isValidURL) {
                this.$post("licensing/licenses/" + this.license_id + "/activate_site", {
                    url: this.site_url,
                }).then((response) => {
                    this.fetchLicense();
                    // this.fetchLicenseMeta();
                    this.site_url = null;

                    if (response.success) {
                        this.handleSuccess('Site activated successfully!');
                    } else {
                        let error = response.error ?? 'An error occurred while activating the site';
                        this.handleError(error);
                    }
                })
                    .catch((error) => {
                        this.handleError(error);
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            } else {
                this.handleError('Enter a valid site URL');
            }
        },
        deactivateSite(activationId) {
            this.$post("licensing/licenses/" + this.license_id + "/deactivate_site", {
                activation_id: activationId,
            })
                .then((response) => {
                    this.fetchLicense();
                    // this.fetchLicenseMeta();
                    this.handleSuccess(response.message);
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        fetchLicense() {
            this.loading = true;
            this.notFound.show = false;
            this.$get("licensing/licenses/" + this.license_id)
                .then((response) => {
                    // this.fetchLicenseMeta();
                    this.license = response.license;
                    this.order = response.order;
                    //TODO temporary added customer to order
                    this.order.customer = this.license?.customer;
                    // delete this.license.customer;
                    this.upgrade_path_base = response.upgrade_path_base;
                    this.orders = response.orders;
                    this.activations = response.activations;
                    this.limit = this.license.limit;
                    this.customerId = this.license.customer?.id;
                    this.product = response.product;
                    this.selectedLabels = response.selected_labels;

                    this.prevOrders = response.prev_orders;

                    this.activatedSites = this.activations
                        .map((activation) => ({
                            id: activation.id,
                            site_url: activation.site?.site_url,
                            created_at: activation.site?.created_at,
                            status: activation.status,
                        }))
                        .sort((a, b) => b.id - a.id);
                })
                .catch((errors) => {
                    if (errors) {
                        if (errors?.code === 'fluent_cart_entity_not_found') {
                            this.notFound.show = true;
                            this.notFound.buttonText = Arr.get(errors, 'data.buttonText');
                            this.notFound.message = Arr.get(errors, 'data.message');
                            this.notFound.route = Arr.get(errors, 'data.route');
                        } else {
                            this.handleError(errors);
                        }
                    }

                })
                .finally(() => {
                    this.loading = false;
                });
        },
        fetchLicenseMeta() {
            this.$get("licensing/licenses/meta/" + this.license_id)
                .then((response) => {
                    this.logs = response;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        updateLicenseStatus(newStatus) {
            this.$post("licensing/licenses/" + this.license_id + "/update_status", {
                status: newStatus,
            })
                .then((response) => {
                    // Update the local license status immediately
                    if (this.license) {
                        this.license.status = newStatus;
                    }
                    this.$emit("fetchLicense");
                    this.handleSuccess(response.message);
                    this.fetchLicense();
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        getCustomerTotalOrders(purchase_count) {
            return purchase_count > 0 ? purchase_count + " " + (purchase_count == 1 ? translate("order") : translate("orders")) : translate("No orders");
        },
        handleMenuClick(menu) {
            this.activeMenu = menu;
        },
        discard() {
            return window.location.reload();
        }
    },
    mounted() {
        this.fetchLicense();
        // this.fetchLicenseMeta();
    },
};
</script>
