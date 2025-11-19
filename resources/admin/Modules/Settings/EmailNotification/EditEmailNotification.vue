<script setup>
import Card from "@/Bits/Components/Card/Card.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
// import FullGutenbergEditor from "@/Bits/Components/Inputs/FullGutenbergEditor.vue";
import Popover from "@/Modules/Settings/Parts/input-popover-dropdown.vue";
import {ArrowRight} from "@element-plus/icons-vue";

const props = defineProps({
    name: {
        type: String,
        required: true
    }
});

const loading = ref(false);
const saving = ref(false);
const name = ref(props.name);
const notificationData = ref({
    title: '',
    description: '',
    event: '',
    name: '',
    recipient: '',
    smartcode_groups: [],
    template_path: '',
    is_async: false,
    settings: {
        active: '',
        is_default_body: 'yes',
        email_body: '',
        subject: '',
    }
});
const shortCodes = ref({});
const focusSubjectInput = ref(false);

const onShortCodeSelected = (code) => {
    notificationData.value.settings.subject += code;
};

const getNotification = () => {
    loading.value = true;
    Rest.get("email-notification/" + name.value)
        .then((response) => {
            notificationData.value = response.data;
            shortCodes.value = response.shortcodes;

        })
        .catch((error) => {
            if (errors.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors.data?.message);
            }
        })
        .finally(() => {
            loading.value = false;
        });
};

const updateNotification = () => {
    saving.value = true;
    Rest.put("email-notification/" + name.value, notificationData.value)
        .then((response) => {
            Notify.success(response.message);
        })
        .catch((errors) => {
            if (errors.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors.data?.message);
            }
        })
        .finally(() => {
            saving.value = false;
        });
}

onMounted(() => {
    getNotification();
});

</script>

<template>
    <div class="setting-wrap fct-edit-email-notification-wrapper">
        <el-skeleton
            :loading="loading"
            class="bg-white rounded p-6 mb-7.5 dark:bg-dark-700"
            animated
        >
            <template #template>
                <el-skeleton-item variant="h3" class="w-[150px] mb-5"/>
                <div class="grid gap-5">
                    <div>
                        <el-skeleton-item variant="h3" class="w-[50px] mb-3"/>
                        <el-skeleton-item variant="p"/>
                    </div>
                    <div>
                        <el-skeleton-item variant="h3" class="w-[50px] mb-3"/>
                        <el-skeleton-item variant="p"/>
                    </div>
                </div>
            </template>
        </el-skeleton>

        <template v-if="!loading">
            <div class="mb-5 flex items-center justify-between">
                <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
                    <el-breadcrumb-item :to="{ path: '/settings/email_notifications' }">
                        {{ $t("Email Notifications") }}
                    </el-breadcrumb-item>
                    <el-breadcrumb-item>
                        <span class="capitalize">{{ notificationData.title }}</span>
                    </el-breadcrumb-item>
                </el-breadcrumb>

                <div class="setting-switcher">
                    <el-switch
                        v-model="notificationData.settings.active"
                        active-value="yes"
                        inactive-value="no"
                        :active-text="translate('Enable this email notification!')"
                    ></el-switch>
                </div>
            </div>

            <Card>
                <CardBody>
                    <el-form label-position="top">
                        <el-form-item :label="translate('Email Subject')">
                            <el-input v-model="notificationData.settings.subject"
                                      autocomplete="rutjfkde"
                                      :class="{ 'is-focus': focusSubjectInput }" @focus="focusSubjectInput = true"
                                      @blur="focusSubjectInput = false" :label="translate('Email Subject')">
                                <template #append>
                                    <Popover
                                        :data="shortCodes"
                                        @command="onShortCodeSelected"
                                        btnType="info"
                                        btnSize="small"
                                        plain
                                        placement="bottom"
                                    >
                                        {{ translate("Add ShortCodes") }}
                                    </Popover>
                                </template>
                            </el-input>
                        </el-form-item>
                        <el-form-item :label="translate('Email Body Type')">
                            <el-radio-group v-model="notificationData.settings.is_default_body">
                                <el-radio-button :label="translate('Default Body')" value="yes"/>
                                <el-radio-button :label="translate('Customized Body')" value="no"/>
                            </el-radio-group>
                        </el-form-item>
                        <el-form-item v-if="notificationData.settings.is_default_body === 'no' && !loading" :label="translate('Email Body')">
                            <wp-editor
                                v-model="notificationData.settings.email_body"
                                :short-codes="shortCodes"
                                :emailTemplateEditor="true"
                                @update="(val) => {
                  notificationData.settings.email_body = val;
                }"
                            ></wp-editor>
<!--                            <FullGutenbergEditor  v-model="notificationData.settings.email_body" />-->
                        </el-form-item>
                    </el-form>
                </CardBody>
            </Card>
        </template>

        <div class="setting-save-action">
            <el-button
                @click="updateNotification"
                type="primary"
                :disabled="saving"
                :loading="saving"
            >
                {{ saving ? translate('Updating') : translate("Update") }}
            </el-button>
        </div>
    </div>
</template>
