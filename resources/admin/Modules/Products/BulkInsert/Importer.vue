<script setup>
import {getCurrentInstance, onMounted, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Papa from 'papaparse';
import Storage from "@/utils/Storage";
import translate from "../../../utils/translator/Translator";

const self = getCurrentInstance().ctx;
const showModal = ref(false);
const files = ref();
const file = ref();

const headers = ref([]);
const data = ref([]);

const mapFields = ref({})
const fieldMapOptions = ref({});

const columnMapReference = [
  'attribute',
  'attributes',
  "attribute's",
  'variation',
  'variations',
  "variation's",
];

let maxIteration = 0;
const shouldShowMappers = ref(false);
const parseCsv = (file) => {
  if (file) {
    Papa.parse(file, {
      header: true,
      complete: function (results) {
        data.value = results.data;
        headers.value = results.meta.fields;
        parseFieldMapOptions();
        shouldShowMappers.value = true;
      }
    });
  }
};

const resetMappingsOptions = () => {
  fieldMapOptions.value = {};
  headers.value = [];
  data.value = [];
  shouldShowMappers.value = false;
}

const parseFieldMapOptions = () => {

  const referenceOptions = [];
  const formattedOptions = [];
  let maxIterationNumber = 0;
  //this regex used to determine if a colum contain a numeric value e.g. Variation 1 title
  headers.value.forEach((value) => {
    let referenceColumnValue = null;
    let referenceColumnTitle = null;

    const lowerValue = value.toString();

    for (const [index, el] of columnMapReference.entries()) {
      if (lowerValue.toLocaleLowerCase().startsWith(el)) {


        const match = value.toString().match(/ \d+ /g);
        if (Array.isArray(match) && match.length === 1) {
          let number = match[0];
          maxIterationNumber = number > maxIterationNumber ? number : maxIterationNumber;
          referenceColumnValue = value.toString().replace(match[0], " %d ");
          referenceColumnTitle = value.toString().replace(match[0], " ");
          break;
        }
      }
    }

    if (referenceColumnValue != null) {
      if (!referenceOptions.includes(referenceColumnValue)) {
        referenceOptions.push(referenceColumnValue);

        formattedOptions.push({
          title: referenceColumnTitle,
          value: referenceColumnValue
        })
      }

    } else {
      formattedOptions.push({
        title: value,
        value: value
      })
    }
  })

  fieldMapOptions.value = formattedOptions;
  maxIteration = maxIterationNumber;

}
const getFileUploadUrl = () => window.fluentCartRestVars.rest.url + "/files/upload";

const populateData = (concat = true) => {
  const products = [];
  data.value.forEach((value) => {
    const product = {};
    const fields = mapFields.value;
    product['post_title'] = value[fields['post_title'].value] ?? '';
    product['post_name'] = value[fields['post_name'].value] ?? product['post_title'];
    product['ID'] = value[fields['post_id'].value] ?? '';
    product['post_content'] = value[fields['post_content'].value] ?? '';
    product['post_excerpt'] = value[fields['post_excerpt'].value] ?? '';
    const details = {
      variation_type: 'simple',
      manage_stock: '0',
      fulfillment_type: 'physical',
    };

    const variantOtherInfo = {
      description: '',
      payment_type: 'onetime',
      times: '',
      repeat_interval: '',
      trial_days: '',
      billing_summary: '',
      manage_setup_fee: 'no',
      signup_fee_name: '',
      signup_fee: '',
      setup_fee_per_item: 'no',
    };


    product['variants'] = [];

    for (let i = 1; i <= maxIteration; i++) {
      const variant = {};

      ['variation_title', 'item_price', 'compare_price'].forEach((indexKey) => {
        const variationKey = fields[indexKey]?.value ?? '';
        if (variationKey.length > 0) {
          const titleIndexedKey = variationKey.replace('%d', i.toString());
          variant[indexKey] = value[titleIndexedKey];
        }
      })

      if (validateData(variant)) {
        variant['other_info'] = {
          ...variantOtherInfo
        };
        product['variants'].push(variant);
      }
    }

    if (product.variants.length > 0) {
      details.variation_type = 'simple_variations';
      product['detail'] = details;
      products.push(product);
    } else if (validateData(product)) {
      product['detail'] = details;
      products.push(product);
    }
  })
  emit('onDataPopulated', {
    products,
    concat
  });
}

const validateData = (data) => {

  let validated = false;
  for (const [index, el] of Object.entries(data)) {

    if (typeof el !== null && typeof el !== "undefined") {
      if ((Array.isArray(el) && el.length > 0) || el.toString().length > 0) {
        validated = true;
        break;
      }
    }
  }
  return validated;
}

onMounted(() => {
  mapFields.value = {

    post_id: {
      title: self.$t('Post ID'),
      value: ''
    },

    post_title: {
      title: self.$t('Post Title'),
      value: ''
    },
    post_name: {
      title: self.$t('Post Name'),
      value: ''
    },
    post_content: {
      title: self.$t('Post Content'),
      value: ''
    },
    post_excerpt: {
      title: self.$t('Post Excerpt'),
      value: ''
    },
    post_status: {
      title: self.$t('Post Status'),
      value: ''
    },
    post_date: {
      title: self.$t('Post Date'),
      value: ''
    },
    comment_status: {
      title: self.$t('Comment Status'),
      value: ''
    },

    variation_title: {
      title: self.$t('Variation Title'),
      value: ''
    },
    item_price: {
      title: self.$t('Variation Price'),
      value: ''
    },
    compare_price: {
      title: self.$t('Variation Compare Price'),
      value: ''
    },
  }
})
const emit = defineEmits(['onDataPopulated'])

</script>

<template>
  <el-button @click="showModal = true">
    {{ $t('Import') }}
  </el-button>

  <el-dialog v-model="showModal">
    <el-upload
        v-if="!shouldShowMappers"
        class="fct-file-uploader"
        ref="uploaderRef"
        v-model:file-list="files"
        drag
        :action="getFileUploadUrl()"
        :on-progress="()=>{}"
        :on-success="()=>{}"
        :on-error="()=>{}"
        :auto-upload="false"
        :on-change="(selectedFile)=>{
          resetMappingsOptions();
          file = selectedFile;
          parseCsv(selectedFile?.raw);
        }"
        :multiple="false"
        :limit="1"
        :show-file-list="true"
    >
      <IconButton circle bg="primary" soft>
        <DynamicIcon name="Upload"/>
      </IconButton>
      <div class="el-upload__text">
        {{ $t('Drag & Drop or') }} <em>{{ $t('Browse/Upload') }}</em> {{ $t('Files') }}
        <span>{{
            /* translators: %s - maximum upload size */
            translate('Any Format upto %s', Storage.serverMaxUploadSize())
          }}</span>
      </div>
      <!--    <template #tip>-->
      <!--      <div class="el-upload__tip">-->
      <!--        jpg/png files with a size less than 500kb-->
      <!--      </div>-->
      <!--    </template>-->
      <!--            <template v-slot:file="file">-->
      <!--              {{file}}-->
      <!--            </template>-->
    </el-upload>
    <div v-if="shouldShowMappers">
      <table class="w-full">
        <tbody>
        <tr v-for="(field, index) of mapFields">
          <td>
            {{ field.title }}
          </td>

          <td width="50%">
            <el-select v-model="field.value" filterable>
              <el-option
                  v-for="option in fieldMapOptions"
                  :key="option.value"
                  :label="option.title"
                  :value="option.value"
              />
            </el-select>
          </td>
        </tr>
        </tbody>
      </table>

      <div class="mt-4">
        <el-button @click="()=>populateData()">
          {{ $t('Add Products') }}
        </el-button>

        <el-button @click="()=>populateData(false)">
          {{ $t('Clear and Add Products') }}
        </el-button>
      </div>
    </div>
  </el-dialog>
</template>

<style scoped>

</style>
