<template>
    <div class="fct-integration-setting-content">
        <div class="fct-integration-setting-head">
            <div class="flex items-center gap-3 mb-2">
                <img class="h-8" v-if="settings.logo" :src="settings.logo" alt=""/>
                <h3 class="title">{{ settings.menu_title }}</h3>
            </div>
            <p class="desc" v-html="settings.menu_description"></p>
        </div>

        <div v-if="settings.hide_on_valid && integration.status" class="integration_success_state">
            <p v-html="settings.discard_settings.section_description"></p>
            <div>
                <fct-button @click="disconnect(settings.discard_settings.data)" type="danger" plain>
                    {{ settings.discard_settings.disconnect_button_text }}
                </fct-button>
                <fct-button v-if="settings.discard_settings.show_verify" @click="save()" type="primary">
                  {{ settings.discard_settings.verify_button_text }}
                </fct-button>
            </div>
        </div>

        <div v-else-if="!error_message" v-loading="loading" class="fct-integration-setting-body">
            <div class="fct-integration-setting-body-inner">
                <template v-if="settings.config_instruction && !integration.status">
                    <div class="integration_instraction" v-html="settings.config_instruction"></div>
                </template>

                <el-form label-width="205px" label-position="top">
                    <!--Site key-->
                    <el-form-item v-for="(field,fieldKey) in settings.fields" :key="fieldKey">
                        <template #label>
                            {{ field.label }}
                            <fct-tooltip v-if="field.label_tips" placement="top-start">
                                <template #content>
                                    <div v-html="field.label_tips">
                                    </div>
                                </template>
                                <el-icon><InfoFilled /></el-icon>
                            </fct-tooltip>
                        </template>

                        <template v-if="field.type == 'authenticate-button'">
                            <fct-button @click="authenticate(field.end_point, field.params)">
                                {{field.button_text}}
                            </fct-button>
                        </template>

                        <template v-else-if="field.type == 'select'">
                            <fct-select v-model="integration[fieldKey]" :options="toOptions(field.options)"/>
                        </template>
                        <template v-else-if="field.type == 'link'">
                            <a :target="field.target" :class="field.btn_class" :href="field.link">{{ field.link_text }}</a>
                            <p>{{ field.tips }}</p>
                        </template>
                        <template v-else>
                            <fct-input :placeholder="field.placeholder" :type="field.type" :controls="false"
                                    v-model="integration[fieldKey]"/>
                            <p v-if="field.tips">{{ field.tips }}</p>
                        </template>
                    </el-form-item>
                    <!--Validate Keys-->
                </el-form>

                <!-- <el-alert v-if="integration.status" :title="settings.valid_message" type="success" show-icon :closable="false" /> -->

                <!-- <el-alert v-else :title="settings.invalid_message" type="error" show-icon :closable="false" /> -->

            </div><!-- .fct-integration-setting-body-inner -->
           

            <div class="fct-integration-setting-body-footer" v-if="!loading">
                <fct-button type="primary" @click="save">
                    {{ settings.save_button_text }}
                </fct-button>
            </div>
        </div><!-- .fct-integration-setting-body -->

        <p v-if="error_message">{{ error_message }}</p>
    </div>
</template>

<script setup>
import {getCurrentInstance} from "vue";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
const selfRef = getCurrentInstance().ctx;
const saveShortcut = useSaveShortcut();
saveShortcut.onSave(()=>{
    if(!selfRef.integration.status) {
        selfRef.save()
    }
});
</script>

<script type="text/babel">
// import VideoDoc from '@/common/VideoInstruction.vue';
import { InfoFilled, Setting} from '@element-plus/icons-vue';

export default {
    name: "GeneralIntegration",
    components: {
        // VideoDoc,
        InfoFilled,
        Setting
    },
    props: ['settings_key'],
    data() {
        return {
            integration: {},
            loading: false,
            saving: false,
            settings: {},
            error_message: ''
        }
    },
    watch: {
        settings_key() {
            this.integration = {};
            this.settings = {};
            this.getIntegrationSettings();
        }
    },
    mounted() {
        this.getIntegrationSettings();
    },
    methods: {
        // Rest rejects with {data: <response body>}; wp_send_json_error nests the message one level deeper.
        errorMessage(error) {
            const body = (error && error.data) || {};
            return body.message || (body.data && body.data.message) || this.$t('Something is wrong!');
        },
        toOptions(options) {
            return Object.entries(options || {}).map(([value, label]) => ({value: Array.isArray(options) ? Number(value) : value, label}));
        },
        save() {
            this.saving = true;
            this.$post('integration/global-settings',{
                settings_key: this.settings_key,
                integration: this.integration
            })
                .then(response => {
                    this.saving = false;
                    this.handleSuccess(response.data.message);

                    if (response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                        return;
                    }

                    this.integration.status = response.data.status;

                })
                .catch(error => {
                    this.integration.status = false;
                    this.handleError(this.errorMessage(error));
                })
                .finally(() => {
                    this.saving = false;
                });

        },
        getIntegrationSettings() {
            this.loading = true;
            this.error_message = '';
            this.$get('integration/global-settings', {
                settings_key: this.settings_key
            })
                .then(response => {
                    this.loading = false;
                    this.integration = response.data.integration;
                    this.settings = response.data.settings;
                })
                .catch(error => {
                    this.error_message = this.errorMessage(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        disconnect(data) {
            this.integration = data;
            this.save();
        },
        authenticate(end_point, params) {
            this.loading = true;
            this.$post('integration/global-settings' + end_point,{
                settings_key: this.settings_key,
                integration: this.integration,
            })
            .then(response => {
                this.loading = false;
                if (response.data.url) {
                    window.open(response.data.url, '_blank')
                } else {
                    this.handleSuccess(response.data.message);
                }
            })
            .catch(error => {
                this.handleError(this.errorMessage(error));
            })
            .finally(() => {
                this.loading = false;
            });
        },
    }
}
</script>
