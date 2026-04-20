<script setup>
import {ref, onMounted} from 'vue';
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import {ElMessageBox} from "element-plus";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PackageDialog from "@/Modules/Shipping/Components/PackageDialog.vue";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";

const packages = ref([]);
const loading = ref(false);
const loadError = ref(false);
const showDialog = ref(false);
const editingPackage = ref(null);
const saving = ref(false);

const typeIcons = {
  box: 'Box',
  envelope: 'Envelope',
  soft_package: 'SoftPackage'
};


const fetchPackages = () => {
  loading.value = true;
  loadError.value = false;
  Rest.get('shipping/packages')
    .then(response => {
      packages.value = response.packages || [];
    })
    .catch(error => {
      loadError.value = true;
      Notify.error(translate('Failed to load packages'));
    })
    .finally(() => {
      loading.value = false;
    });
};

const savePackages = (snapshot) => {
  if (saving.value) return Promise.resolve();
  saving.value = true;
  return Rest.post('shipping/packages', {packages: packages.value})
    .then(response => {
      packages.value = response.packages || packages.value;
      showDialog.value = false;
      Notify.success(response.message);
    })
    .catch(error => {
      if (snapshot) {
        packages.value = snapshot;
      }
      Notify.error(translate('Failed to save packages'));
    })
    .finally(() => {
      saving.value = false;
    });
};

const openAddDialog = () => {
  if (loadError.value || saving.value) return;
  editingPackage.value = null;
  showDialog.value = true;
};

const openEditDialog = (pkg) => {
  if (saving.value) return;
  editingPackage.value = {...pkg};
  showDialog.value = true;
};

const onPackageSaved = (packageData) => {
  const snapshot = JSON.parse(JSON.stringify(packages.value));

  // If is_default, unset others
  if (packageData.is_default) {
    packages.value.forEach(p => {
      p.is_default = false;
    });
  }

  if (editingPackage.value) {
    // Update existing
    const index = packages.value.findIndex(p => p.slug === editingPackage.value.slug);
    if (index !== -1) {
      packages.value[index] = packageData;
    }
  } else {
    // Ensure unique slug
    let slug = packageData.slug;
    let counter = 1;
    while (packages.value.some(p => p.slug === slug)) {
      slug = packageData.slug + '-' + counter;
      counter++;
    }
    packageData.slug = slug;
    packages.value.push(packageData);
  }

  savePackages(snapshot);
};

const setAsDefault = (pkg) => {
  if (saving.value) return;
  const snapshot = JSON.parse(JSON.stringify(packages.value));
  packages.value.forEach(p => {
    p.is_default = p.slug === pkg.slug;
  });
  savePackages(snapshot);
};

const confirmDeletePackage = (pkg) => {
  if (saving.value) return;
  ElMessageBox.confirm(
    translate('Are you sure you want to delete this package?'),
    translate('Delete Package'),
    { confirmButtonText: translate('Delete'), cancelButtonText: translate('Cancel'), type: 'warning' }
  ).then(() => {
    const snapshot = JSON.parse(JSON.stringify(packages.value));
    const index = packages.value.findIndex(p => p.slug === pkg.slug);
    if (index !== -1) {
      packages.value.splice(index, 1);
      savePackages(snapshot);
    }
  }).catch(() => {});
};

const formatDimensions = (pkg) => {
  if (pkg.type === 'envelope') {
    return pkg.length + ' × ' + pkg.width + ' ' + pkg.dimension_unit;
  }
  return pkg.length + ' × ' + pkg.width + ' × ' + pkg.height + ' ' + pkg.dimension_unit;
};

const formatWeight = (pkg) => {
  return pkg.weight + ' ' + pkg.weight_unit;
};

onMounted(() => {
  fetchPackages();
});
</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader
      :heading="translate('Packages')"
      :save-button-text="translate('Add Package')"
      @onSave="openAddDialog"
    />

    <div class="setting-wrap-inner">
      <div v-loading="loading" class="fct-packages-list">
        <div v-if="loadError && !loading" class="fct-packages-empty">
          <p class="text-gray-500">{{ translate('Failed to load packages.') }}</p>
          <el-button type="primary" size="small" class="mt-3" @click="fetchPackages">{{ translate('Retry') }}</el-button>
        </div>

        <div v-else-if="packages.length === 0 && !loading" class="fct-packages-empty">
          <p class="text-gray-500">{{ translate('No packages defined yet. Add a package to define how your products are packaged for shipping.') }}</p>
        </div>

        <div v-else class="fct-packages-cards">
          <div
            v-for="pkg in packages"
            :key="pkg.slug"
            class="fct-package-card"
          >
            <div class="fct-package-card-left">
              <span class="fct-package-card-icon">
                <DynamicIcon :name="typeIcons[pkg.type] || 'Box'" />
              </span>
              <div class="fct-package-card-info">
                <div class="fct-package-card-name">{{ pkg.name }}</div>
                <div class="fct-package-card-dims">{{ formatDimensions(pkg) }}, {{ formatWeight(pkg) }}</div>
              </div>
            </div>

            <div class="fct-package-card-right">
              <el-tag v-if="pkg.is_default" type="info" size="small" effect="plain">
                {{ translate('Store default') }}
              </el-tag>
              <el-dropdown 
                class="fct-more-option-wrap"
                popper-class="fct-dropdown"
                trigger="click" 
                @command="(cmd) => {
                    if (cmd === 'edit') openEditDialog(pkg);
                    else if (cmd === 'default') setAsDefault(pkg);
                    else if (cmd === 'delete') confirmDeletePackage(pkg);
                }"
              >

                <el-button class="more-btn" :aria-label="translate('Package actions')">
                    <DynamicIcon name="More"/>
                </el-button>

                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item command="edit">
                        {{ translate('Edit') }}
                    </el-dropdown-item>
                    <el-dropdown-item v-if="!pkg.is_default" command="default">
                        {{ translate('Set as default') }}
                    </el-dropdown-item>
                    <el-dropdown-item command="delete" class="item-destructive">
                      {{ translate('Delete') }}
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </div>
          </div>
        </div>
      </div>

      <PackageDialog
        v-model="showDialog"
        :edit-data="editingPackage"
        :saving="saving"
        @save="onPackageSaved"
      />
    </div>
  </div>
</template>

