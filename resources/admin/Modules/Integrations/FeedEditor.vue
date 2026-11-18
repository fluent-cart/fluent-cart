<template>
    <div class="fct-integration-container fct-layout-width">
        <div class="fct-add-integration py-7.5">
            <fct-skeleton
                v-if="loading_app"
                class="bg-white rounded p-6 mb-7.5 dark:bg-dark-700"
                animated
            >
                <fct-skeleton variant="h3" class="w-[150px] mb-5"/>
                <div class="grid gap-5">
                    <div>
                        <fct-skeleton variant="h3" class="w-[50px] mb-3"/>
                        <fct-skeleton variant="p"/>
                    </div>
                    <div>
                        <fct-skeleton variant="h3" class="w-[50px] mb-3"/>
                        <fct-skeleton variant="p"/>
                    </div>
                </div>
            </fct-skeleton>

            <!-- Kept: add-on render_template markup may use el-form-item and needs this form's top labels. -->
            <el-form
                v-if="!loading_app"
                label-position="top"
                require-asterisk-position="right"
            >
                <FeedHeader
                    :settings="settings"
                    :integration_title="integrationTitle"
                />

                <CardContainer>
                    <CardBody>
                        <template v-for="(field, fieldIndex) in settings_fields.fields" :key="fieldIndex">
                            <div class="fct-setting-form-row" :class="field.key">
                                <div class="fct-setting-form-content" v-if="field.label">
                                    <LabelHint
                                        v-if="field.tips"
                                        :title="field.label"
                                        :content="field.tips"
                                        placement="top-start"
                                        :required="field.required"
                                    />
                                    <LabelHint v-else :title="field.label" :required="field.required"/>

                                    <p class="fct-inline-tip" v-if="field.inline_tip" v-html="field.inline_tip"></p>
                                </div><!-- .fct-setting-form-content -->
                                <div class="fct-setting-form-fields">
                                    <template v-if="field.component === 'text'">
                                        <fct-input
                                            :placeholder="field.placeholder"
                                            v-model="settings[field.key]"
                                            :class="errors.errors[field.key] ? 'is-error' : ''"
                                        />
                                        <ErrorView :field="field.key" :errors="errors"></ErrorView>
                                    </template>

                                    <template v-else-if="field.component === 'list_ajax_options'">
                                        <fct-select
                                            v-loading="loading_list"
                                            @change="loadMergeFields()"
                                            v-model="settings.list_id"
                                            :placeholder="field.placeholder"
                                            clearable
                                            :options="toOptions(field.options)"
                                        />
                                        <ErrorView :field="field.key" :errors="errors"></ErrorView>
                                    </template>

                                    <template v-else-if="field.component === 'map_fields'">
                                        <MergeFieldMapper
                                            @set-merge-model-obj="setMergeModel(field)"
                                            :errors="errors"
                                            :inputs="inputs"
                                            :field="field"
                                            :settings="settings"
                                            :editorShortcodes="editorShortcodes"
                                            :merge_model="settings[field.key]"
                                            :merge_fields="merge_fields"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'select'">
                                        <fct-select
                                            filterable
                                            clearable
                                            :multiple="field.is_multiple"
                                            v-model="settings[field.key]"
                                            :placeholder="field.placeholder"
                                            :options="toOptions(field.options)"
                                        />
                                        <ErrorView :field="field.key" :errors="errors"></ErrorView>
                                    </template>

                                    <template v-else-if="field.component === 'checkbox-single'">
                                        <fct-choice v-model="settings[field.key]">
                                            {{ field.checkbox_label }}
                                        </fct-choice>
                                        <p class="fct-inline-tip pl-6" v-if="field.inline_tip"
                                           v-html="field.inline_tip"></p>
                                    </template>

                                    <template v-else-if="field.component === 'yes-no-checkbox'">
                                        <fct-choice true-value="yes" false-value="no" v-model="settings[field.key]">
                                            {{ field.checkbox_label }}
                                        </fct-choice>
                                        <p class="fct-inline-tip pl-6" v-if="field.inline_tip"
                                           v-html="field.inline_tip"></p>
                                    </template>

                                    <template v-else-if="field.component === 'checkbox-multiple'">
                                        <fct-choice
                                            v-model="settings[field.key]"
                                            :options="toOptions(field.options).map((option) => ({...option, value: Number(option.value)}))"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'selection_group'">
                                        <fct-select
                                            v-model="settings[field.key]"
                                            :placeholder="field.placeholder"
                                            multiple
                                            :class="errors.errors[field.key] ? 'is-error' : ''"
                                            :options="Object.values(field.options || {})"
                                        />

                                        <ErrorView :field="field.key" :errors="errors"></ErrorView>
                                    </template>

                                    <template v-else-if="field.component === 'conditional_block'">
                                        <FilterFields
                                            :fields="inputs"
                                            :conditionals="settings[field.key]"
                                            :editorShortcodes="editorShortcodes"
                                        />
                                    </template>
                                    <template v-else-if="field.component === 'value_text'">
                                        <FiledGeneral
                                            :editorShortcodes="editorShortcodes"
                                            :defaultValue="settings[field.key]"
                                            v-model="settings[field.key]"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'value_textarea'">
                                        <FiledGeneral
                                            field_type="textarea"
                                            :editorShortcodes="editorShortcodes"
                                            :defaultValue="settings[field.key]"
                                            v-model="settings[field.key]"
                                            isIconBtn
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'list_select_filter'">
                                        <ListSelectFilter :settings="settings" :field="field"/>
                                    </template>

                                    <template v-else-if="field.component === 'dropdown_label_repeater'">
                                        <DropDownLabelRepeater
                                            :errors="errors"
                                            :inputs="inputs"
                                            :field="field"
                                            :settings="settings"
                                            :editorShortcodes="editorShortcodes"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'dropdown_many_fields'">
                                        <DropDownManyFields
                                            :errors="errors"
                                            :inputs="inputs"
                                            :field="field"
                                            :settings="settings"
                                            :editorShortcodes="editorShortcodes"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'radio_choice'">
                                        <fct-choice type="radio" v-model="settings[field.key]" :options="toOptions(field.options)"/>
                                    </template>
                                    <template v-else-if="field.component === 'number'">
                                        <fct-input
                                            type="number"
                                            size="default"
                                            v-model="settings[field.key]"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'chained_fields'">
                                        <ChainedFields
                                            :settings="settings"
                                            v-model="settings[field.key]"
                                            :field="field"
                                        ></ChainedFields>
                                    </template>

                                    <template v-else-if="field.component === 'chained_select'">
                                        <ChainedSelects
                                            :settings="settings"
                                            v-model="settings[field.key]"
                                            :field="field"
                                        ></ChainedSelects>
                                    </template>

                                    <template v-else-if="field.component === 'html_info'">
                                        <div v-html="field.html_info"></div>
                                    </template>

                                    <template v-else-if="field.component === 'selection_routing'">
                                        <SelectionRouting
                                            :inputs="inputs"
                                            :field="field"
                                            :editorShortcodes="editorShortcodes"
                                            :settings="settings"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'rest_selector'">
                                        <RestSelector
                                            :field="field"
                                            v-model="settings[field.key]"
                                        />
                                    </template>

                                    <template v-else-if="field.component === 'custom_component'">
                                        <component :is="'custom-component-render'" :field="field" :settings="settings"/>
                                    </template>

                                </div><!-- .fct-setting-form-fields -->
                            </div>
                        </template>

                        <template v-if="scope === 'product' && product_variations.length">
                            <div class="fct-setting-form-row">
                                <div class="fct-setting-form-content">
                                    <div class="fct-setting-form-content">
                                        <LabelHint :title="translate('Run on Selected Variations Only')"/>
                                    </div>
                                </div>
                                <div class="fct-setting-form-fields">
                                    <fct-select
                                        filterable
                                        clearable
                                        :multiple="true"
                                        v-model="settings.conditional_variation_ids"
                                        :placeholder="$t('Leave empty for all the variations')"
                                        :options="product_variations.map((variant) => ({label: variant.title, value: variant.id}))"
                                    />
                                    <p class="fct-inline-tip pl-6">
                                        {{ $t('Leave empty to run this feed for all variations') }}</p>
                                </div>
                            </div>
                        </template>

                    </CardBody>
                </CardContainer>

                <div class="setting-save-action" v-if="maybeShowSaveButton">
                    <fct-button type="primary" :loading="saving" @click="saveNotification">
                        {{
                            integration_id
                                ? `Update ${settings_fields.integration_title} Feed`
                                : `Create ${settings_fields.integration_title} Feed`
                        }}
                    </fct-button>
                </div>
            </el-form>
        </div>
    </div>
</template>

<script type="text/babel">
import FilterFields from "./Templates/FilterFields.vue";
import MergeFieldMapper from "./Templates/_field_maps.vue";
import FiledGeneral from "./Templates/_FieldGeneral.vue";
import ListSelectFilter from "./Templates/_ListSelectFilter.vue";
import DropDownLabelRepeater from "./Templates/_DropdownLabelRepeater.vue";
import DropDownManyFields from "./Templates/_DropdownManyFields.vue";
import ChainedFields from "./Templates/_ChainedFields.vue";
import ChainedSelects from "./Templates/_ChainedSelects.vue";
import SelectionRouting from "./Templates/_SelectionRouting.vue";
import CustomComponentRender from "./Templates/CustomComponentRender.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import FeedHeader from "@/Modules/Integrations/FeedHeader.vue";
import Errors from "./Common/Errors";
import ErrorView from "./Templates/errorView.vue";
import Notify from "@/utils/Notify";
import RestSelector from "./Templates/_RestSelector.vue";
import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str.js";

export default {
    name: "FeedEditor",
    components: {
        FilterFields,
        MergeFieldMapper,
        FiledGeneral,
        ListSelectFilter,
        DropDownLabelRepeater,
        DropDownManyFields,
        ChainedFields,
        ChainedSelects,
        SelectionRouting,
        CardContainer: Card.Container,
        CardBody: Card.Body,
        CardHeader: Card.Header,
        LabelHint,
        FeedHeader,
        ErrorView,
        CustomComponentRender,
        RestSelector
    },
    data() {
        return {
            // True until the first load, so the header never renders with empty settings.
            loading_app: true,
            loading_list: false,
            errors: new Errors(),
            integration_id: parseInt(this.$route.params.integration_id),
            integration_name: this.$route.params.integration_name,
            saving: false,
            merge_fields: {},
            settings: {},
            settings_fields: {},
            attachedForms: [],
            inputs: [],
            editorShortcodes: [],
            scope: '',
            product_variations: []
        };
    },
    computed: {
        integrationTitle() {
            return this.settings_fields?.integration_title || Str.studly(this.integration_name, '_');
        },
        getTitle() {
            let integrationName = this.settings_fields.integration_title || "";
            if (this.integration_id) {
                return `Update ${integrationName} Integration Feed`;
            } else {
                return `Add New ${integrationName} Integration Feed`;
            }
        },
        maybeShowSaveButton() {
            let fields = this.settings_fields;
            let mergeFields = this.merge_fields;
            return (
                (fields.button_require_list && mergeFields) ||
                !fields.button_require_list
            );
        },
    },
    mounted() {
        this.loadIntegrationSettings();
    },
    methods: {
      translate,
        // Object (value => label) or array (index => label) options, as the old v-for read them.
        toOptions(options) {
            return Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));
        },
        loadIntegrationSettings() {
            if (this.$route.name === 'product_integration_feed_editor') {
                this.loading_app = true;
                const product_id = this.$route.params.product_id;
                this.$get(`products/${product_id}/integrations/${this.integration_name}/settings`, {
                    integration_id: this.integration_id
                })
                    .then((response) => {
                        this.loading_app = false;
                        this.settings_fields = response.settings_fields;
                        this.scope = response.scope;
                        this.product_variations = response.product_variations;
                        this.settings = response.settings;
                        this.editorShortcodes = Object.values(response.shortcodes || {});
                        this.inputs = response.inputs;
                        if (!this.settings.name) {
                            this.settings.name =
                                response.settings_fields.integration_title +
                                " Integration Feed" || "";
                        }

                        this.merge_fields = response.merge_fields;
                    })
                    .catch((errors) => {
                        this.loading_app = false;
                        if (errors.status_code == '422') {
                            Notify.validationErrors(errors);
                        } else {
                            Notify.error(errors.data?.message);
                        }
                    });
            } else {
                // Original global integration loading logic
                this.loading_app = true;
                this.$get(`integration/global-feeds/settings`, {
                    integration_id: this.integration_id,
                    integration_name: this.integration_name,
                })
                    .then((response) => {
                        this.loading_app = false;
                        this.settings_fields = response.settings_fields;
                        this.settings = response.settings;
                        this.editorShortcodes = Object.values(response.shortcodes);
                        this.inputs = response.inputs;
                        if (!this.settings.name) {
                            this.settings.name =
                                response.settings_fields.integration_title +
                                " Integration Feed" || "";
                        }

                        this.merge_fields = response.merge_fields;
                    })
                    .catch((error) => {
                        this.loading_app = false;
                        this.handleError(error.responseJSON?.data?.message || 'Failed to load integration settings');
                    });
            }
        },
        loadMergeFields() {
            this.loading_list = true;
            this.fieldsLoaded = false;
            this.$get(`integration/feed/lists`, {
                integration_id: this.integration_id,
                list_id: this.settings.list_id,
                integration_name: this.integration_name,
            })
                .then((response) => {
                    this.loading_list = false;
                    this.merge_fields = response.merge_fields;
                    this.fieldsLoaded = true;
                })
                .catch((error) => {
                });
        },
        saveNotification() {
            this.errors.clear();
            if (this.$route.name === 'product_integration_feed_editor') {
                this.saving = true;
                const product_id = this.$route.params.product_id;

                let data = {
                    integration_id: this.integration_id,
                    integration_name: this.integration_name,
                    integration: JSON.stringify(this.settings)
                };

                this.$post(`products/${product_id}/integrations`, data)
                    .then((response) => {
                        if (response.feedData) {
                            this.settings = response.feedData;
                        }

                        if (response.created) {
                            this.$router.push({
                                name: 'product_integrations',
                                params: {
                                    product_id: product_id
                                }
                            });
                        }
                        this.handleSuccess(response.message || 'Integration saved successfully');
                        this.errors.clear();
                    })
                    .catch((error) => {
                        this.errors.record(error?.data?.errors || {});
                        this.handleError(error);
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            } else {
                // Original global integration save logic
                this.saving = true;
                let data = {
                    integration_id: this.integration_id,
                    integration_name: this.integration_name,
                    integration: JSON.stringify(this.settings)
                };
                this.$post(`integration/global-feeds/settings`, data)
                    .then((response) => {
                        if (response.created) {
                            this.$router.push({
                                name: 'integrations',
                            });
                        }
                        this.handleSuccess(response.message);
                    })
                    .catch((error) => {
                        this.errors.record(error?.data?.errors || {});
                        this.handleError(error);
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            }
        },
        setMergeModel(field) {
            this.settings[field.key] = {};
        },
    },
};
</script>
