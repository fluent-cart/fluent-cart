<template>
  <div class="setting-wrap">
    <el-skeleton
        :loading="loading"
        class="bg-white rounded p-6 mb-7.5 dark:bg-dark-700"
        animated
    >
      <template #template>
        <el-skeleton-item variant="h3" class="w-[150px] mb-5" />
        <div class="grid gap-5">
          <div>
            <el-skeleton-item variant="h3" class="w-[50px] mb-3" />
            <el-skeleton-item variant="p" />
          </div>
          <div>
            <el-skeleton-item variant="h3" class="w-[50px] mb-3" />
            <el-skeleton-item variant="p" />
          </div>
        </div>
      </template>
    </el-skeleton>

    <template v-if="!loading">
      <WebhookHeader :settings="settings"/>

      <CardContainer>
        <CardBody>
          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Name')" :required="true"/>
              <p class="fct-inline-tip">{{$t('Webhook Feed Name')}}</p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields">
              <el-input v-model="settings.name" :class="errors.has('name') ? 'is-error' : ''"/>
              <ErrorView field="name" :errors="errors"></ErrorView>
            </div><!-- .fct-setting-form-fields -->
          </div>

          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Request URL')" :required="true"/>
              <p class="fct-inline-tip">{{$t('This endpoint receives data from webhooks when events occur')}}</p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields">
              <el-input v-model="settings.request_url" :class="errors.has('request_url') ? 'is-error' : ''"/>
              <ErrorView field="request_url" :errors="errors"></ErrorView>
            </div><!-- .fct-setting-form-fields -->
          </div>

          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Request Method')" :required="true"/>
              <p class="fct-inline-tip">{{$t('Specifies the HTTP method (e.g., GET, POST) used to send data to the webhook URL.')}}</p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields">
              <el-select v-model="settings.request_method" clearable :class="errors.has('request_method') ? 'is-error' : ''">
                <el-option value="GET" label="GET"/>
                <el-option value="POST" label="POST"/>
                <el-option value="PUT" label="PUT"/>
                <el-option value="PATCH" label="PATCH"/>
                <el-option value="DELETE" label="DELETE"/>
              </el-select>
              <ErrorView field="request_method" :errors="errors"></ErrorView>
            </div><!-- .fct-setting-form-fields -->
          </div>

          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Request Format')"/>
              <p class="fct-inline-tip">
                {{$t('Defines the structure and method used to send data in the webhook request.')}}
              </p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields">
              <el-select v-model="settings.request_format">
                <el-option value="JSON" label="JSON"/>
                <el-option value="FORM" label="Form"/>
              </el-select>
            </div><!-- .fct-setting-form-fields -->
          </div>
          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Request Headers')"/>
              <p class="fct-inline-tip">
                {{$t('Key-value pairs sent with the request for context like auth or content type.')}}
              </p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields self-center">
              <el-radio-group v-model="settings.request_headers">
                <el-radio value="no_headers">{{$t('No Headers')}}</el-radio>
                <el-radio value="custom_headers">{{$t('With Headers')}}</el-radio>
              </el-radio-group>

              <CustomHeaders v-if="settings.request_headers === 'custom_headers'" :settings="settings"/>
            </div><!-- .fct-setting-form-fields -->
          </div>

          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Request Body')" :required="true"/>
              <p class="fct-inline-tip">
                {{$t('The data sent in the request body.')}}
              </p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields self-center">
              <el-radio-group v-model="settings.request_body">
                <el-radio value="all_data">{{$t('All Data')}}</el-radio>
                <el-radio value="selected_fields">{{$t('Selected Fields')}}</el-radio>
              </el-radio-group>

              <SelectedFields v-if="settings.request_body === 'selected_fields'" :settings="settings" :selected_field_options="selected_field_options"/>
            </div><!-- .fct-setting-form-fields -->
          </div>

          <div class="fct-setting-form-row">
            <div class="fct-setting-form-content">
              <LabelHint :title="$t('Event Trigger')" :required="true"/>
              <p class="fct-inline-tip">
                {{$t('Defines the action that initiates the workflow or process.')}}
              </p>
            </div><!-- .fct-setting-form-content -->

            <div class="fct-setting-form-fields self-center">
              <el-select
                  v-model="settings.event_trigger" multiple
                 :placeholder="$t('Select event triggers')"
                 clearable
                 :class="errors.has('event_trigger') ? 'is-error' : ''"
              >
                <el-option-group
                    v-for="group in event_trigger_options"
                    :key="group.label"
                    :label="group.label"
                >
                  <el-option
                      v-for="item in group.options"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                  />
                </el-option-group>
              </el-select>
              <ErrorView field="event_trigger" :errors="errors"></ErrorView>
            </div><!-- .fct-setting-form-fields -->
          </div>

        </CardBody>
      </CardContainer>

      <div class="setting-save-action">
        <el-button :loading="saving" type="primary" @click="saveSettings">
          {{$t('Save Feed')}}
        </el-button>
      </div>
    </template>
  </div>
</template>

<script>
import * as Card from "@/Bits/Components/Card/Card.js";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import ErrorView from "@/Modules/Integrations/Templates/errorView.vue";
import Errors from "@/Modules/Integrations/Common/Errors";
import {ArrowRight} from "@element-plus/icons-vue";
import CustomHeaders from "@/Modules/Settings/Webhooks/CustomHeaders.vue";
import SelectedFields from "@/Modules/Settings/Webhooks/SelectedFields.vue";
import WebhookHeader from "@/Modules/Settings/Webhooks/WebhookHeader.vue";


export default {
  name: "Webhooks",
  components: {
    WebhookHeader,
    SelectedFields,
    CustomHeaders,
    CardContainer: Card.Container,
    CardBody: Card.Body,
    CardHeader: Card.Header,
    LabelHint,
    ErrorView
  },
  data() {
    return {
      webhook: {},
      settings: {
        name: '',
        request_url: '',
        request_method: 'POST',
        request_format: 'JSON',
        request_headers: 'no_headers',
        custom_header_values: [
            {
              header_name: '',
              header_value: ''
            }
        ],
        request_body: 'all_data',
        event_trigger: [],
        selected_field_values: [
          {
            field_name: '',
            field_value: '',
          },
        ],
        enabled: true
      },
      event_trigger_options: [],
      selected_field_options: [
        {
          label: 'Billing Address',
          options: [
            {
              label: 'First Name',
              value: '{{order.billing.first_name}}'
            },
            {
              label: 'Last Name',
              value: '{{order.billing.last_name}}'
            },
            {
              label: 'Full Name',
              value: '{{order.billing.full_name}}'
            },
            {
              label: 'Email',
              value: '{{order.billing.email}}'
            },
            {
              label: 'Country',
              value: '{{order.billing.country}}'
            },
            {
              label: 'City',
              value: '{{order.billing.city}}'
            },
            {
              label: 'State',
              value: '{{order.billing.state}}'
            },
            {
              label: 'Postcode',
              value: '{{order.billing.postcode}}'
            },
            {
              label: 'Address 1',
              value: '{{order.billing.address_1}}'
            },
            {
              label: 'Address 2',
              value: '{{order.billing.address_2}}'
            }
          ]
        },
        {
          label: 'Shipping Address',
          options: [
            {
              label: 'First Name',
              value: '{{order.shipping.first_name}}'
            },
            {
              label: 'Last Name',
              value: '{{order.shipping.last_name}}'
            },
            {
              label: 'Full Name',
              value: '{{order.shipping.full_name}}'
            },
            {
              label: 'Email',
              value: '{{order.shipping.email}}'
            },
            {
              label: 'Country',
              value: '{{order.shipping.country}}'
            },
            {
              label: 'City',
              value: '{{order.shipping.city}}'
            },
            {
              label: 'State',
              value: '{{order.shipping.state}}'
            },
            {
              label: 'Postcode',
              value: '{{order.shipping.postcode}}'
            },
            {
              label: 'Address 1',
              value: '{{order.shipping.address_1}}'
            },
            {
              label: 'Address 2',
              value: '{{order.shipping.address_2}}'
            }

          ]
        },
        {
          label: 'Order',
          options: [
            {
              label: 'Order ID',
              value: '{{order.id}}'
            },
            {
              label: 'Order Number',
              value: '{{order.uuid}}'
            },
            {
              label: 'Order Date',
              value: '{{order.created_at}}'
            },
            {
              label: 'Order Total',
              value: '{{order.total_amount}}'
            },
            {
              label: 'Order Status',
              value: '{{order.status}}'
            }
          ]
        }
      ],
      errors: new Errors(),
      loading: false,
      saving: false
    };
  },
  computed: {
    ArrowRight() {
      return ArrowRight
    }
  },
  mounted() {
    this.webhook.id = this.$route?.params?.feed_id;
    this.getSettings();
  },
  methods: {
    saveSettings() {
      this.saving = true;

      this.$post('webhook/feed', {
        settings: this.settings,
        webhook: this.webhook
      })
          .then((response) => {
            this.handleSuccess(response.message);
            this.$router.push({
              name: "webhooks_settings",
              params: {
                feed_id: response.id
              },
            });
            this.webhook.id = response.id;
          })
          .catch((error) => {
            this.errors.record(error);
            this.handleError(error?.data?.message || this.$t('Failed to save webhook settings'));
            this.saving = false;
          }).finally(() => {
            this.saving = false;
          });
    },
    getSettings() {
      this.loading = true;
      this.$get('webhook/feed/' + this.webhook.id)
          .then((response) => {
            if (response.settings) {
              this.settings = response.settings;
            }
            this.event_trigger_options = response?.event_trigger_options?.options;
            this.loading = false;
          })
          .catch((error) => {
            this.handleError(error?.data?.message || this.$t('Failed to load webhook settings'));
            this.loading = false;
          });
    }
  }
}
</script>
