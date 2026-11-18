<template>
    <div class="fct-integration-settings fct-layout-width">
        <CardContainer>
            <CardHeader border_bottom :title="integration_id ? $t('Edit Integration') : $t('Add Integration')">
                <template #action>
                    <fct-button @click="saveIntegration" type="primary" :loading="saving">
                        {{ $t('Save') }}
                    </fct-button>
                </template>
            </CardHeader>
            <CardBody>
                <div class="integration-form">
                    <fct-input v-model="formData.values.name"
                               :label="$t('Integration Name')"
                               required
                               :placeholder="$t('Enter a name for this integration')"/>

                    <fct-select v-if="!integration_id"
                                v-model="formData.values.integrationName"
                                :label="$t('Integration Type')"
                                disabled
                                class="fct-select"
                                :options="formData.fields.integrationName.options"/>

                    <template v-for="(field, fieldKey) in formData.fields" :key="fieldKey">
                        <template v-if="fieldKey !== 'integrationName' && dependancyPass(field)">
                            <fct-input v-if="field.type === 'text'"
                                       v-model="formData.values[fieldKey]"
                                       :label="field.label"
                                       :placeholder="field.placeholder"/>
                            <fct-select v-else-if="field.type === 'select'"
                                        v-model="formData.values[fieldKey]"
                                        :label="field.label"
                                        :placeholder="field.placeholder"
                                        class="fct-select"
                                        :options="field.options"/>
                            <fct-input v-else-if="field.type === 'textarea'"
                                       type="textarea"
                                       v-model="formData.values[fieldKey]"
                                       :label="field.label"
                                       :placeholder="field.placeholder"/>
                        </template>
                    </template>
                </div>
            </CardBody>
        </CardContainer>
    </div>
</template>

<script>
import {
    Container as CardContainer,
    Header as CardHeader,
    Body as CardBody,
    Footer as CardFooter
} from '@/Bits/Components/Card/Card.js';

export default {
    name: 'SingleIntegration',
    components: {
        CardFooter,
        CardBody,
        CardHeader,
        CardContainer
    },
    props: ['product', 'product_id', 'integration_id', 'integration_type'],
    data() {
        return {
            integration_id: this.integration_id,
            product_id: this.product_id,
            integration_type: this.integration_type || '',
            saving: false,
            formData: {
                values: {
                    name: '',
                    integrationName: this.integration_type || '',
                    description: ''
                },
                fields: {
                    integrationName: {
                        type: 'select',
                        label: 'Integration Type',
                        placeholder: 'Select Integration',
                        options: []
                    }
                }
            }
        }
    },
    mounted() {
        this.fetchIntegrationData();
    },
    methods: {
        saveIntegration() {
            if (!this.formData.values.name) {
                this.$notify({
                    type: "error",
                    message: 'Integration name is required',
                    offset: 40
                });
                return;
            }

            this.saving = true;

            const endpoint = this.integration_id
                ? `products/${this.product_id}/integrations/${this.integration_id}`
                : `products/${this.product_id}/integrations`;

            const method = this.integration_id ? 'put' : 'post';

            this[`$${method}`](endpoint, {
                integration: this.formData.values
            })
                .then(response => {
                    this.saving = false;
                    this.$notify({
                        type: "success",
                        message: response.message || 'Integration saved successfully',
                        offset: 40
                    });

                    // Redirect back to integrations list
                    this.$router.push({
                        name: 'ProductIntegrations',
                        params: {
                            product_id: this.product_id
                        }
                    });
                })
                .catch(error => {
                    this.saving = false;
                    this.$notify({
                        type: "error",
                        message: error?.message || 'Failed to save integration',
                        offset: 40
                    });
                });
        },
        fetchIntegrationData() {
            if (!this.integration_id) {
                this.fetchIntegrationType();
                return;
            }

            this.$get(`products/${this.product_id}/integrations/${this.integration_id}`)
                .then(response => {
                    if (response.integration) {
                        this.formData.values = {...response.integration};
                        this.integration_type = response.integration.integrationName;
                        this.fetchIntegrationType();
                    }
                })
                .catch(error => {
                    this.$notify({
                        type: "error",
                        message: error.message || 'Failed to load integration data',
                        offset: 40
                    });
                });
        },
        fetchIntegrationType() {
            if (!this.integration_type) return;

            this.$get(`integration/settings?integration_name=${this.integration_type}`)
                .then(response => {
                    if (response.settings_fields) {
                        this.formData.fields = response.settings_fields;
                    }

                    // Initialize default values if they don't exist
                    Object.keys(this.formData.fields).forEach(key => {
                        if (this.formData.values[key] === undefined) {
                            this.formData.values[key] = '';
                        }
                    });
                })
                .catch(error => {
                    this.$notify({
                        type: "error",
                        message: error.message || 'Failed to load integration fields',
                        offset: 40
                    });
                });
        },
        compare(operand1, operator, operand2) {
            switch (operator) {
                case '=':
                    return operand1 === operand2
                case '!=':
                    return operand1 !== operand2
                default:
                    return true
            }
        },
        dependancyPass(field) {
            if (field.dependency) {
                const optionPaths = field.dependency.depends_on.split('/');
                const dependencyVal = optionPaths.reduce((obj, prop) => {
                    return obj[prop]
                }, this.formData.values);
                return this.compare(dependencyVal, field.dependency.operator, field.dependency.value);
            }
            return true;
        }
    }
}
</script>

<style scoped lang="scss">
.integration-form {
    max-width: 800px;
}
</style>
