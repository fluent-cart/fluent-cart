<template>
    <div class="fct-integration-container fct-layout-width">
        <PageHeading :title="$t('Integrations & Addons')"/>

        <GlobalIntegrationFeeds/>

        <div style="margin-top: 100px;" class="fct-integration-content">
            <CardContainer>
                <CardBody>
                    <div class="fct-integration-content-head">
                        <IconButton circle size="large">
                            <DynamicIcon name="EqualizerLine"/>
                        </IconButton>
                        <div>
                            <h2 class="fct-card-header-title mb-1">
                                {{ $t("Integration Modules") }}
                            </h2>
                            <p class="card-header-text mt-0">
                                {{
                                    $t("Configure your integrations and extend FluentCart to enhance your store\'s functionality.")
                                }}
                            </p>
                        </div>
                    </div>
                    <!-- .fct-integration-content-head -->

                    <div
                        class="flex items-center gap-3 justify-between py-5 mt-5 border border-solid border-gray-divider border-x-0 dark:border-dark-500 flex-col md:flex-row"
                    >
                        <FluidTab>
                            <FluidTabItem
                                v-for="(module, moduleIndex) in modules"
                                :key="moduleIndex"
                                class="uppercase"
                                @click="changeModuleType(module)"
                                :class="module === module_type ? 'active' : ''"
                            >
                                {{ module  }}
                            </FluidTabItem>
                        </FluidTab>

                        <div class="fct-integration-search">
                            <el-input :placeholder="$t('Search...')" v-model="search">
                                <template #prefix>
                                    <DynamicIcon name="Search"/>
                                </template>
                            </el-input>
                        </div>
                    </div>

                    <el-skeleton v-if="fetching" :loading="true" animated :rows="4"/>

                    <div v-if="!fetching">
                        <div
                            v-for="(addon, addonKey) in filteredAddons"
                            :key="addonKey"
                            class="fct-integration-card"
                            :class="'addon-enabled-' + addon.enabled"
                        >
                            <div class="fct-integration-card-content">
                                <div class="fct-integration-card-content-inner">
                                    <div class="fct-integration-card-logo">
                                        <img v-if="addon.logo" :src="addon.logo" :alt="addon.title"/>
                                    </div>
                                    <div>
                                        <h5 class="title">
                                            {{ addon.title }}
                                            <Badge v-if="addon.is_pro && !addon.enabled" status="warning" text="Pro"/>
                                            <Badge v-else :status="addon.enabled ? 'active' : 'info'"
                                                   :text="addon.enabled ? 'enabled' : 'disabled'"/>
                                        </h5>
                                        <p class="desc">{{ addon.description }}</p>
                                        <div v-if="addon.is_pro && !addon.is_pro_active" class="mt-2">
                                            <el-button type="warning" size="small" tag="a" :href="upgradeUrl" target="_blank">
                                                <DynamicIcon name="Crown" class="mr-1"/>
                                                {{ translate('Upgrade to Pro') }}
                                            </el-button>
                                        </div>
                                        <div v-else-if="!addon.enabled && addon.installable" class="mt-2">
                                            <el-button :loading="installingAdding == addonKey"
                                                       :disabled="installingAdding == addonKey"
                                                       @click="installAddon(addon, addonKey)" type="primary" size="small">
                                                {{
                                                  /* translators: %s - addon name */
                                                  translate('Install %s', addon.title)
                                                }}
                                            </el-button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- .fct-integration-card -->

                    <Empty
                        icon="Folder"
                        v-if="is_no_modules && !fetching"
                        :text="$t('Sorry! No modules found based on your filter.')"
                    />
                </CardBody>
            </CardContainer>
        </div>
    </div>
</template>


<script type="text/babel">
import {isEmpty, each} from "@/utils/Utils";

import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import Empty from '@/Bits/Components/Table/Empty.vue';
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {Tab as FluidTab, Item as FluidTabItem} from "@/Bits/Components/FluidTab/FluidTab.js";
import translate from "../../utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Notify from "@/utils/Notify";
import GlobalIntegrationFeeds from '@/Modules/Integrations/Feeds.vue';
import AppConfig from "@/utils/Config/AppConfig";

export default {
    name: "Integrations",
    components: {
        Badge,
        FluidTabItem,
        FluidTab,
        CardBody,
        CardContainer,
        CardHeader,
        PageHeading,
        IconButton,
        DynamicIcon,
        Empty,
        GlobalIntegrationFeeds
    },
    data() {
        return {
            search: "",
            fetching: false,
            addOns: {},
            module_type: "all",
            integrationTab: "global",
            modules: ["all", "crm", "lms", "core", 'marketing'],
            installationLoading: false,
            admin_url: AppConfig.get('admin_url'),
            upgradeUrl: AppConfig.get('app_config.upgrade_url'),
            installing: false,
            installingAdding: ''
        };
    },
    computed: {
        filteredAddons() {
            let addons = this.addOns;
            if (this.search) {
                let filteredAddons = {};
                each(addons, (addOn, addOnKey) => {
                    let obString = JSON.stringify(addOn);
                    if (obString.indexOf(this.search) !== -1) {
                        filteredAddons[addOnKey] = addOn;
                    }
                });
                addons = filteredAddons;
            }

            if (this.module_type != "all") {
                let filteredAddons = {};
                each(addons, (addOn, addOnKey) => {
                    if(addOn.categories && addOn.categories.includes(this.module_type)) {
                        filteredAddons[addOnKey] = addOn;
                    }
                });
                addons = filteredAddons;
            }
            return addons;
        },
        is_no_modules() {
            return isEmpty(this.filteredAddons);
        },
    },
    methods: {
        fetchAddons() {
            this.fetching = true;
            this.$get("integration/addons")
                .then((response) => {
                    this.addOns = response.addons;
                })
                .catch((errors) => {
                    this.handleError(errors);
                })
                .finally(() => {
                    this.fetching = false;
                });
        },
        translate,
        changeModuleType(module_type) {
            this.module_type = module_type;
        },
        installAddon(addon, addonKey) {
            this.installing = true;
            this.installingAdding = addonKey;
            this.$post("integration/feed/install-plugin", {
                addon: addonKey
            })
                .then((response) => {
                    this.$notify.success(response.message);
                    // window relead with js
                    window.location.reload();
                })
                .catch((errors) => {
                    this.handleError(errors);
                })
                .finally(() => {
                    this.installing = false;
                    this.installingAdding = '';
                });
        }
    },
    mounted() {
        this.fetchAddons();
    }
};
</script>
