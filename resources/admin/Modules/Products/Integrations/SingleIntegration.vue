<template>
    <div class="fct-integration-settings fct-layout-width">
        <CardContainer>
            <CardHeader border_bottom :title="integration_id ? $t('Edit Integration') : $t('Add Integration')">
                <template #action>
                    <el-button @click="saveIntegration" type="primary" :loading="saving">
                        {{ $t('Save') }}
                    </el-button>
                </template>
            </CardHeader>
            <CardBody>
                <el-form label-position="top" :model="formData.values" class="integration-form">
                    <el-form-item :label="$t('Integration Name')" required>
                        <el-input v-model="formData.values.name"
                                  placeholder="Enter a name for this integration"></el-input>
                    </el-form-item>

                    <el-form-item :label="$t('Integration Type')" v-if="!integration_id">
                        <el-select v-model="formData.values.integrationName" disabled class="fct-select">
                            <el-option
                                v-for="option in formData.fields.integrationName.options"
                                :key="option.value"
                                :label="option.label"
                                :value="option.value">
                            </el-option>
                        </el-select>
                    </el-form-item>

                    <template v-for="(field, fieldKey) in formData.fields" :key="fieldKey">
                        <el-form-item
                            v-if="fieldKey !== 'integrationName' && dependancyPass(field)"
                            :label="field.label">
                            <template v-if="field.type === 'text'">
                                <el-input v-model="formData.values[fieldKey]"
                                          :placeholder="field.placeholder"></el-input>
                            </template>
                            <template v-else-if="field.type === 'select'">
                                <el-select v-model="formData.values[fieldKey]" :placeholder="field.placeholder"
                                           class="fct-select">
                                    <el-option
                                        v-for="option in field.options"
                                        :key="option.value"
                                        :label="option.label"
                                        :value="option.value">
                                    </el-option>
                                </el-select>
                            </template>
                            <template v-else-if="field.type === 'textarea'">
                                <el-input type="textarea" v-model="formData.values[fieldKey]"
                                          :placeholder="field.placeholder"></el-input>
                            </template>
                        </el-form-item>
                    </template>
                </el-form>
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
    props: ['product', 'product_id', 'integration_id', 'integration_type'],
    components: {
        CardFooter,
        CardBody,
        CardHeader,
        CardContainer
    },
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
                    console.log(error);
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
    },
    mounted() {
        this.fetchIntegrationData();
    }
}
</script>

<style scoped lang="scss">
.integration-form {
    max-width: 800px;
}
</style>
