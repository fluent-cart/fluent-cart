<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import {onMounted, ref, watch} from "vue";
import Notify from "@/utils/Notify";
import TaxClassesLoader from "@/Modules/Tax/TaxClassesLoader.vue";
import {$confirm} from "@/Bits/common";
import LabelHint from "@/Bits/Components/LabelHint.vue";


const taxClasses = ref([]);
const fetchingTaxClasses = ref(false);

// modal state
const addClassModalVisible = ref(false);
const addingTaxClass = ref(false);
const newTaxClass = ref({
  id: 0,
  title: "",
  description: "",
  categories: [],
  priority: 10,
});

const props = defineProps({
  tax_settings_changed: {
    type: Boolean,
    default: false
  },
  enable_tax: {
    type: String,
    default: 'no'
  },
  categories_external: {
    type: Array,
    default: () => []
  }
});

// watch this enable_tax and fetch tax classes if any changes
watch(() => props.tax_settings_changed, (newVal) => {
    if (newVal) {
      fetchTaxClasses();
    }
});

const validationErrors = ref({});
const categories = ref([]);
const fetchingCategories = ref(false);

const fetchTaxClasses = () => {
  fetchingTaxClasses.value = true;
  Rest.get('tax/classes')
      .then(response => {
        taxClasses.value = response.tax_classes;
      })
      .catch((errors) => {
        if (errors && errors?.message) {
          Notify.error(errors);
        }
        console.error('Error fetching tax classes:', errors);
      })
      .finally(() => {
        fetchingTaxClasses.value = false;
      });
}

const openAddClassDrawer = () => {
  resetForm();
  addClassModalVisible.value = true;
}

const resetForm = () => {
  newTaxClass.value = {
    id: 0,
    title: "",
    description: "",
    categories: [],
    priority: 10
  }
}

const saveTaxClass = () => {
  addingTaxClass.value = true;
  if (!newTaxClass.value.title) {
    addingTaxClass.value = false;
    return Notify.error(translate("Title is required"));
  }

  Rest.post('tax/classes', newTaxClass.value)
      .then((response) => {
        Notify.success(response.message);
        fetchTaxClasses();
        addClassModalVisible.value = false;
        resetForm();
        validationErrors.value = {};
      })
      .catch((err) => {
        if (err && err?.message) {
          Notify.error(err?.message);
        } else {
          validationErrors.value = err;
        }
      })
      .finally(() => {
        addingTaxClass.value = false;
      });
};

const updateTaxClass = () => {
  addingTaxClass.value = true;
  if (!newTaxClass.value.title) {
    addingTaxClass.value = false;
    return Notify.error(translate("Title is required"));
  }

  Rest.put('tax/classes/' + newTaxClass.value.id, {
    title: newTaxClass.value.title,
    description: newTaxClass.value.description,
    categories: newTaxClass.value.categories,
    priority: newTaxClass.value.priority,
  })
      .then((response) => {
        Notify.success(response.message);
        fetchTaxClasses();
        addClassModalVisible.value = false;
        validationErrors.value = {};
      })
      .catch((err) => {
        if (err && err?.message) {
          Notify.error(err?.message);
        } else {
          validationErrors.value = err;
        }
      })
      .finally(() => {
        addingTaxClass.value = false;
      });
}

const handleCommandAction = (command) => {
  if (command.action === 'delete') {
    $confirm(translate('Are you sure you want to delete this tax class?'), translate('Confirm Delete!'), {
      confirmButtonText: translate('Yes, Delete!'),
      cancelButtonText: translate('Cancel'),
      type: 'warning'
    }).then(() => {
      deleteTaxClass(command.taxClass.id);
    }).catch(() => {
    });
  }
}

const deleteTaxClass = (id) => {
  Rest.delete('tax/classes/' + id)
      .then((response) => {
        Notify.success(response.message);
        fetchTaxClasses();
      })
      .catch((err) => {
        if (err && err?.message) {
          Notify.error(err?.message);
        } else {
          validationErrors.value = err;
        }
      })
      .finally(() => {

      });
}

const handleEditTaxClass = (taxClass) => {
  newTaxClass.value = {
    id: taxClass.id,
    title: taxClass.title,
    description: taxClass.description,
    categories: taxClass.meta?.categories ?? [],
    priority: taxClass.meta?.priority ?? 0,
  }
  addClassModalVisible.value = true;
}

const getProductCategories = () => {
  // Prefer categories from parent if provided
  if (props.categories_external && props.categories_external.length) {
    categories.value = props.categories_external.map(category => ({
      ...category,
      value: parseInt(category.value ?? category.term_id)
    }));
    return;
  }
  if (categories.value.length > 0) {
    return;
  }
  fetchingCategories.value = true;
  Rest.get('products/fetch-term')
      .then(response => {
        let terms = response.taxonomies["product-categories"].terms;
        categories.value = terms.map(category => ({
          ...category,
          value: parseInt(category.value)
        }));
      })
      .catch((errors) => {
        console.error('Error fetching product categories:', errors);
      })
      .finally(() => {
        fetchingCategories.value = false;
      });
}

// Get Label from categories by id
const getCategoryLabel = (id) => {
  return categories.value.find(category => category.value == id)?.label || id;
}

onMounted(() => {
  fetchTaxClasses();
  getProductCategories();
})
</script>

<template>
  <div class="setting-wrap" v-if="props.enable_tax === 'yes' && taxClasses.length > 0">
    <div class="fct-all-tax-classes-page">

      <Card.Container>
        <Card.Header
            :title="translate('Existing Tax Classes')"
            :text="translate('Define categories to group products with similar tax treatment.')">
          <template #action>
            <el-button type="default" @click="openAddClassDrawer">
              {{ translate('Add Tax Class') }}
            </el-button>
          </template>
        </Card.Header>

        <Card.Body>
          <div v-if="fetchingTaxClasses" class="fct-tax-classes-wrap">
            <TaxClassesLoader/>
          </div>
          <div v-else class="fct-tax-classes-wrap">
            <div v-for="(taxClass, index) in taxClasses" :key="index" class="fct-tax-classes">
              <div class="fct-tax-classes-content">
                <h4 class="fct-tax-classes-title">
                  <span class="heading">{{ taxClass.title }}</span>
                  <span v-if="taxClass.categories && taxClass.categories.length > 0" class="fct-tax-classes-categories">
                    <span
                        v-for="(category, catInd) in taxClass.categories.slice(0, 2)"
                        :key="catInd"
                        class="fct-tax-classes-category"
                    >
                      {{ getCategoryLabel(category) }}
                    </span>
                    <span
                        v-if="taxClass.categories.length > 2"
                        class="fct-tax-classes-category fct-tax-classes-more"
                    >
                      +{{ taxClass.categories.length - 2 }}
                    </span>
                  </span>
                </h4>
                <p class="fct-tax-classes-desc">{{ taxClass.description }}</p>
              </div>
              <div class="fct-tax-classes-actions">
                <IconButton size="small" tag="span" @click="handleEditTaxClass(taxClass)">
                  <DynamicIcon name="Edit" class="w-4 h-4"/>
                </IconButton>

                <el-dropdown v-if="!['standard', 'reduced', 'zero'].includes(taxClass.slug)" trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                             @command="handleCommandAction">
                  <IconButton size="small" tag="span">
                    <DynamicIcon name="More" class="w-4 h-4"/>
                  </IconButton>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item
                          :command="{
                            action: 'delete',
                            taxClass: taxClass,
                          }"
                          class="item-destructive"
                      >
                        <DynamicIcon name="Delete"/>
                        {{ translate("Delete") }}
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </div>
            </div>
          </div>
        </Card.Body>
      </Card.Container>

    </div>

    <!-- Add Tax Class Modal -->
    <el-dialog
        v-model="addClassModalVisible"
        :title="translate('Add Tax Class')"
        width="500px"
        append-to-body
        class="fluent-cart-admin-pages"
    >
      <el-form label-position="top" :model="newTaxClass">
        <el-row :gutter="20">
          <el-col :span="16">
              <el-form-item :label="translate('Tax Class Name')">
                <el-input v-model="newTaxClass.title" :placeholder="translate('Enter Tax Class Name')"/>
              </el-form-item>
          </el-col>
          <el-col :span="8">
              <el-form-item :label="translate('Priority')">
                <template #label>
                  <LabelHint
                          :title="translate('Priority')"
                          :content="translate('Higher numbers have higher priority (0-10). Tax classes with higher priority will be applied first.')"
                      />
                </template>
                <el-input-number
                    v-model="newTaxClass.priority"
                    :min="0"
                    :max="10"
                    :placeholder="translate('Enter priority (0-10)')"
                    controls-position="right"
                    class="w-full"
                />
              </el-form-item>
          </el-col>
        </el-row>

        <el-form-item :label="translate('Product Category')">
          <el-select
              v-model="newTaxClass.categories"
              v-loading="fetchingCategories"
              filterable
              multiple
              :placeholder="translate('Select Product Category')">
            <el-option
                v-for="(category, index) in categories"
                :key="index"
                :label="category.label"
                :value="category.value"/>
          </el-select>
          <div class="form-hint">
            <p>{{ translate('Select the product categories that this tax class will be applied to.') }}</p>
          </div>
        </el-form-item>

        <el-form-item :label="translate('Description')">
          <el-input
              type="textarea"
              rows="3"
              v-model="newTaxClass.description"
              :placeholder="translate('Enter Tax Class Description')"
          />
        </el-form-item>
      </el-form>
      <div v-if="validationErrors">
        <p class="text-red-500 m-0" v-for="(error, index) in validationErrors" :key="index">
          {{ error.required || error.max || error.unique }}
        </p>
      </div>

      <template #footer>
        <el-button @click="addClassModalVisible = false">
          {{ translate("Cancel") }}
        </el-button>

        <el-button v-if="newTaxClass.id > 0" type="primary" @click="updateTaxClass" :loading="addingTaxClass">
          {{ addingTaxClass ? translate("Updating") : translate("Update") }}
        </el-button>
        <el-button v-else type="primary" @click="saveTaxClass" :loading="addingTaxClass">
          {{ addingTaxClass ? translate("Creating") : translate("Create") }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>

</style>
