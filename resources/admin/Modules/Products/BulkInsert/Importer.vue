<script setup>
import {getCurrentInstance, onMounted, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Papa from 'papaparse';
import Storage from "@/utils/Storage";
import translate from "../../../utils/translator/Translator";
import {generateCid} from "@/utils/cid";

const self = getCurrentInstance().ctx;
const showModal = ref(false);
const files = ref();
const file = ref();

const headers = ref([]);
const data = ref([]);

const mapFields = ref({})
const fieldMapOptions = ref({});
const isWooFormat = ref(false);

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

        isWooFormat.value = headers.value.includes('Type') && headers.value.includes('Parent');

        parseFieldMapOptions();

        if (isWooFormat.value) {
          prefillWooCommerceMapping();
        }

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
  isWooFormat.value = false;
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

const wooFieldMap = {
  post_id: ['ID'],
  post_title: ['Name'],
  post_name: ['SKU'],
  post_content: ['description', 'Description'],
  post_excerpt: ['Short description', 'Short Description'],
  post_status: ['Published'],
  comment_status: ['Allow customer reviews?'],
  item_price: ['Regular price', 'Meta: _subscription_price'],
  compare_price: ['Sale price'],
  images: ['Images'],
  categories: ['Categories'],
  payment_type: ['Payment Type'],
  repeat_interval: ['Subscription Interval', 'Meta: _subscription_period'],
  trial_days: ['Trial Days', 'Meta: _subscription_trial_length'],
  installment: ['Installment'],
  installment_count: ['Installment Count'],
  manage_setup_fee: ['Setup Fee'],
  signup_fee_name: ['Setup Fee Name'],
  signup_fee: ['Setup Fee Amount', 'Meta: _subscription_sign_up_fee'],
};

const prefillWooCommerceMapping = () => {
  for (const [fieldKey, csvColumns] of Object.entries(wooFieldMap)) {
    if (!mapFields.value[fieldKey]) continue;
    for (const col of csvColumns) {
      if (headers.value.includes(col)) {
        mapFields.value[fieldKey].value = col;
        break;
      }
    }
  }
};

const normalizeInterval = (value) => {
  if (!value) return '';
  const map = { day: 'daily', week: 'weekly', month: 'monthly', year: 'yearly' };
  return map[value.trim().toLowerCase()] || value;
};

const resolveWooType = (rawType) => {
  if (!rawType) return '';
  const types = rawType.split(',').map(t => t.trim().toLowerCase());
  if (types.includes('variation')) return 'variation';
  if (types.includes('variable')) return 'variable';
  if (types.includes('subscription')) return 'subscription';
  if (types.includes('simple')) return 'simple';
  return types[0] || '';
};

const populateWooCommerceData = (concat = true) => {
  const products = [];
  const parentMap = {};
  const fields = mapFields.value;

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

  const getAttributeValues = (row) => {
    const parts = [];
    for (let i = 1; i <= 10; i++) {
      const nameCol = `Attribute ${i} name`;
      const valueCol = `Attribute ${i} value(s)`;
      if (row[nameCol] && row[valueCol]) {
        parts.push(row[valueCol]);
      }
    }
    return parts.join(' - ');
  };

  const resolveStatus = (value) => {
    if (value === '1' || value === 1) return 'published';
    if (value === '0' || value === 0) return 'draft';
    return value || 'published';
  };

  // First pass: simple + variable + subscription (parent) products
  data.value.forEach((row) => {
    const type = resolveWooType(row['Type']);
    if (type !== 'simple' && type !== 'variable' && type !== 'subscription') return;

    const product = {};
    product._cid = generateCid();
    product['post_title'] = row[fields['post_title']?.value] || '';
    product['post_name'] = row[fields['post_name']?.value] || product['post_title'];
    product['ID'] = row[fields['post_id']?.value] || '';
    product['post_content'] = row[fields['post_content']?.value] || '';
    product['post_excerpt'] = row[fields['post_excerpt']?.value] || '';
    product['post_status'] = resolveStatus(row[fields['post_status']?.value]);

    const details = {
      variation_type: type === 'variable' ? 'simple_variations' : 'simple',
      manage_stock: '0',
      fulfillment_type: 'physical',
    };

    // Parse image URLs (comma-separated in WooCommerce CSV) into gallery objects
    const imagesRaw = row[fields['images']?.value] || '';
    const galleryItems = [];
    if (imagesRaw) {
      imagesRaw.split(',').map(url => url.trim()).filter(Boolean).forEach(url => {
        const filename = url.split('/').pop().split('?')[0] || 'image';
        galleryItems.push({ id: 0, url, title: filename });
      });
    }

    product['detail'] = details;
    product['gallery'] = galleryItems;
    product['variants'] = [];

    // Parse categories (comma-separated paths, e.g. "Clothing, Clothing > T-Shirts")
    const categoriesRaw = row[fields['categories']?.value] || row['Categories'] || '';
    if (categoriesRaw) {
      product['categories'] = categoriesRaw.split(',').map(c => c.trim()).filter(Boolean);
    } else {
      product['categories'] = [];
    }

    if (type === 'simple' || type === 'subscription') {
      const price = row[fields['item_price']?.value] || '';
      const comparePrice = row[fields['compare_price']?.value] || '';
      const simpleSku = row['SKU'] || '';
      const paymentType = type === 'subscription' ? 'subscription' : (row[fields['payment_type']?.value] || 'onetime');
      const repeatInterval = normalizeInterval(row[fields['repeat_interval']?.value] || '');
      const trialDays = row[fields['trial_days']?.value] || '';
      const installment = row[fields['installment']?.value] || '';
      const installmentCount = row[fields['installment_count']?.value] || '';
      const setupFee = row[fields['manage_setup_fee']?.value] || '';
      const setupFeeName = row[fields['signup_fee_name']?.value] || '';
      const setupFeeAmount = row[fields['signup_fee']?.value] || '';
      if (price || product['post_title']) {
        product['variants'].push({
          _cid: generateCid(),
          variation_title: product['post_title'],
          sku: simpleSku,
          item_price: price,
          compare_price: comparePrice,
          other_info: {
            ...variantOtherInfo,
            payment_type: paymentType,
            repeat_interval: repeatInterval,
            trial_days: trialDays,
            installment: installment === 'yes' ? 'yes' : 'no',
            times: installmentCount,
            manage_setup_fee: setupFee === 'yes' ? 'yes' : 'no',
            signup_fee_name: setupFeeName,
            signup_fee: setupFeeAmount,
          },
          media: [],
        });
      }
    }

    const sku = row['SKU'] || '';
    if (type === 'variable' && sku) {
      parentMap[sku] = product;
    }

    products.push(product);
  });

  // Second pass: variation rows → attach to parent
  data.value.forEach((row) => {
    if (resolveWooType(row['Type']) !== 'variation') return;

    const parentSku = row['Parent'];
    if (!parentSku || !parentMap[parentSku]) return;

    const variationTitle = getAttributeValues(row);
    const price = row[fields['item_price']?.value] || '';
    const comparePrice = row[fields['compare_price']?.value] || '';

    const variantImagesRaw = row[fields['images']?.value] || '';
    const variantMedia = [];
    if (variantImagesRaw) {
      variantImagesRaw.split(',').map(url => url.trim()).filter(Boolean).forEach(url => {
        const filename = url.split('/').pop().split('?')[0] || 'image';
        variantMedia.push({ id: 0, url, title: filename });
      });
    }

    const variantSku = row['SKU'] || '';
    const variantPaymentType = row[fields['payment_type']?.value] || 'onetime';
    const variantRepeatInterval = normalizeInterval(row[fields['repeat_interval']?.value] || '');
    const variantTrialDays = row[fields['trial_days']?.value] || '';
    const variantInstallment = row[fields['installment']?.value] || '';
    const variantInstallmentCount = row[fields['installment_count']?.value] || '';
    const variantSetupFee = row[fields['manage_setup_fee']?.value] || '';
    const variantSetupFeeName = row[fields['signup_fee_name']?.value] || '';
    const variantSetupFeeAmount = row[fields['signup_fee']?.value] || '';

    const variantEntry = {
      _cid: generateCid(),
      variation_title: variationTitle || row['Name'] || '',
      sku: variantSku,
      item_price: price,
      compare_price: comparePrice,
      other_info: {
        ...variantOtherInfo,
        payment_type: variantPaymentType,
        repeat_interval: variantRepeatInterval,
        trial_days: variantTrialDays,
        installment: variantInstallment === 'yes' ? 'yes' : 'no',
        times: variantInstallmentCount,
        manage_setup_fee: variantSetupFee === 'yes' ? 'yes' : 'no',
        signup_fee_name: variantSetupFeeName,
        signup_fee: variantSetupFeeAmount,
      },
      media: variantMedia,
    };

    parentMap[parentSku]['variants'].push(variantEntry);
  });

  emit('onDataPopulated', { products, concat });
};

const populateData = (concat = true) => {
  if (isWooFormat.value) {
    return populateWooCommerceData(concat);
  }

  const products = [];
  data.value.forEach((value) => {
    const product = {};
    product._cid = generateCid();
    const fields = mapFields.value;
    product['post_title'] = value[fields['post_title'].value] ?? '';
    product['post_name'] = value[fields['post_name'].value] ?? product['post_title'];
    product['ID'] = value[fields['post_id'].value] ?? '';
    product['post_content'] = value[fields['post_content'].value] ?? '';
    product['post_excerpt'] = value[fields['post_excerpt'].value] ?? '';
    product['post_status'] = value[fields['post_status']?.value] || 'published';
    product['comment_status'] = 'close';

    // Parse image URLs (comma-separated) into gallery objects
    const imagesRaw = value[fields['images']?.value] || '';
    const galleryItems = [];
    if (imagesRaw) {
      imagesRaw.split(',').map(url => url.trim()).filter(Boolean).forEach(url => {
        const filename = url.split('/').pop().split('?')[0] || 'image';
        galleryItems.push({ id: 0, url, title: filename });
      });
    }

    // Parse categories (comma-separated)
    const categoriesRaw = value[fields['categories']?.value] || '';
    if (categoriesRaw) {
      product['categories'] = categoriesRaw.split(',').map(c => c.trim()).filter(Boolean);
    } else {
      product['categories'] = [];
    }

    // Read SKU and subscription fields
    const skuValue = value[fields['sku']?.value] || '';
    const paymentTypeValue = value[fields['payment_type']?.value] || 'onetime';
    const repeatIntervalValue = value[fields['repeat_interval']?.value] || '';
    const trialDaysValue = value[fields['trial_days']?.value] || '';
    const installmentValue = value[fields['installment']?.value] || '';
    const installmentCountValue = value[fields['installment_count']?.value] || '';
    const setupFeeValue = value[fields['manage_setup_fee']?.value] || '';
    const setupFeeNameValue = value[fields['signup_fee_name']?.value] || '';
    const setupFeeAmountValue = value[fields['signup_fee']?.value] || '';

    const details = {
      variation_type: 'simple',
      manage_stock: '0',
      fulfillment_type: 'physical',
    };

    const variantOtherInfo = {
      description: '',
      payment_type: paymentTypeValue,
      times: installmentCountValue,
      repeat_interval: repeatIntervalValue,
      trial_days: trialDaysValue,
      billing_summary: '',
      manage_setup_fee: setupFeeValue === 'yes' ? 'yes' : 'no',
      signup_fee_name: setupFeeNameValue,
      signup_fee: setupFeeAmountValue,
      setup_fee_per_item: 'no',
      installment: installmentValue === 'yes' ? 'yes' : 'no',
    };


    product['gallery'] = galleryItems;
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
        variant._cid = generateCid();
        variant['sku'] = skuValue;
        variant['other_info'] = {
          ...variantOtherInfo
        };
        variant['media'] = [];
        product['variants'].push(variant);
      }
    }

    if (product.variants.length > 0) {
      details.variation_type = 'simple_variations';
      product['detail'] = details;
      products.push(product);
    } else if (validateData(product)) {
      // Create a default variant for simple products so template can bind to variants[0]
      product.variants.push({
        _cid: generateCid(),
        variation_title: '',
        sku: skuValue,
        item_price: 0,
        compare_price: 0,
        other_info: { ...variantOtherInfo },
        media: [],
      });
      product['detail'] = details;
      products.push(product);
    }
  })
  emit('onDataPopulated', {
    products,
    concat
  });
}

const closeImporterDialog = () => {
  showModal.value = false;
  files.value = [];
  file.value = null;
  resetMappingsOptions();
};

const addProductsToTable = (concat = true) => {
  populateData(concat);
  closeImporterDialog();
};

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

    images: {
      title: self.$t('Images'),
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
    sku: {
      title: self.$t('SKU'),
      value: ''
    },
    categories: {
      title: self.$t('Categories'),
      value: ''
    },
    payment_type: {
      title: self.$t('Payment Type'),
      value: ''
    },
    repeat_interval: {
      title: self.$t('Subscription Interval'),
      value: ''
    },
    trial_days: {
      title: self.$t('Trial Days'),
      value: ''
    },
    installment: {
      title: self.$t('Installment'),
      value: ''
    },
    installment_count: {
      title: self.$t('Installment Count'),
      value: ''
    },
    manage_setup_fee: {
      title: self.$t('Setup Fee'),
      value: ''
    },
    signup_fee_name: {
      title: self.$t('Setup Fee Name'),
      value: ''
    },
    signup_fee: {
      title: self.$t('Setup Fee Amount'),
      value: ''
    },
  }
})
const downloadSampleCsv = () => {
  const sampleData = [
    {
      'ID': '',
      'Type': 'simple',
      'SKU': 'BASIC-TEE-001',
      'Name': 'Classic Cotton T-Shirt',
      'Published': 1,
      'Short description': 'Comfortable everyday cotton tee',
      'description': 'Made from 100% organic cotton. Pre-shrunk and machine washable.',
      'Categories': 'Clothing, Clothing > T-Shirts',
      'Images': 'https://example.com/images/tee-front.jpg, https://example.com/images/tee-back.jpg',
      'Parent': '',
      'Regular price': '29.99',
      'Sale price': '24.99',
      'Attribute 1 name': '',
      'Attribute 1 value(s)': '',
      'Payment Type': 'onetime',
      'Subscription Interval': '',
      'Trial Days': '',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
    {
      'ID': '',
      'Type': 'simple',
      'SKU': 'STARTER-PLAN',
      'Name': 'Starter Membership Plan',
      'Published': 1,
      'Short description': 'Monthly access to premium content',
      'description': 'Get access to all premium tutorials and resources. Cancel anytime.',
      'Categories': 'Memberships',
      'Images': '',
      'Parent': '',
      'Regular price': '9.99',
      'Sale price': '',
      'Attribute 1 name': '',
      'Attribute 1 value(s)': '',
      'Payment Type': 'subscription',
      'Subscription Interval': 'monthly',
      'Trial Days': '14',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': 'yes',
      'Setup Fee Name': 'Activation Fee',
      'Setup Fee Amount': '4.99',
    },
    {
      'ID': '',
      'Type': 'simple',
      'SKU': 'PRO-YEARLY',
      'Name': 'Pro Annual Plan',
      'Published': 1,
      'Short description': '12-month installment plan',
      'description': 'Pay in 12 monthly installments for annual access to all pro features.',
      'Categories': 'Memberships',
      'Images': '',
      'Parent': '',
      'Regular price': '19.99',
      'Sale price': '',
      'Attribute 1 name': '',
      'Attribute 1 value(s)': '',
      'Payment Type': 'subscription',
      'Subscription Interval': 'monthly',
      'Trial Days': '',
      'Installment': 'yes',
      'Installment Count': '12',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
    {
      'ID': '',
      'Type': 'variable',
      'SKU': 'HOODIE-PREMIUM',
      'Name': 'Premium Zip Hoodie',
      'Published': 1,
      'Short description': 'Heavyweight zip-up hoodie',
      'description': 'Premium quality hoodie with YKK zipper. Available in multiple colors.',
      'Categories': 'Clothing, Clothing > Hoodies',
      'Images': 'https://example.com/images/hoodie-main.jpg, https://example.com/images/hoodie-side.jpg, https://example.com/images/hoodie-detail.jpg',
      'Parent': '',
      'Regular price': '',
      'Sale price': '',
      'Attribute 1 name': '',
      'Attribute 1 value(s)': '',
      'Payment Type': '',
      'Subscription Interval': '',
      'Trial Days': '',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
    {
      'ID': '',
      'Type': 'variation',
      'SKU': 'HOODIE-BLK',
      'Name': '',
      'Published': 1,
      'Short description': '',
      'description': '',
      'Categories': '',
      'Images': 'https://example.com/images/hoodie-black.jpg',
      'Parent': 'HOODIE-PREMIUM',
      'Regular price': '59.99',
      'Sale price': '',
      'Attribute 1 name': 'Color',
      'Attribute 1 value(s)': 'Black',
      'Payment Type': '',
      'Subscription Interval': '',
      'Trial Days': '',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
    {
      'ID': '',
      'Type': 'variation',
      'SKU': 'HOODIE-WHT',
      'Name': '',
      'Published': 1,
      'Short description': '',
      'description': '',
      'Categories': '',
      'Images': '',
      'Parent': 'HOODIE-PREMIUM',
      'Regular price': '59.99',
      'Sale price': '',
      'Attribute 1 name': 'Color',
      'Attribute 1 value(s)': 'White',
      'Payment Type': '',
      'Subscription Interval': '',
      'Trial Days': '',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
    {
      'ID': '',
      'Type': 'variation',
      'SKU': 'HOODIE-BLU-LE',
      'Name': '',
      'Published': 1,
      'Short description': '',
      'description': '',
      'Categories': '',
      'Images': '',
      'Parent': 'HOODIE-PREMIUM',
      'Regular price': '69.99',
      'Sale price': '64.99',
      'Attribute 1 name': 'Color',
      'Attribute 1 value(s)': 'Blue',
      'Payment Type': '',
      'Subscription Interval': '',
      'Trial Days': '',
      'Installment': '',
      'Installment Count': '',
      'Setup Fee': '',
      'Setup Fee Name': '',
      'Setup Fee Amount': '',
    },
  ];

  const csv = Papa.unparse(sampleData);
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'fluent-cart-sample-import.csv';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

const emit = defineEmits(['onDataPopulated'])

</script>

<template>
  <el-button @click="showModal = true">
    {{ $t('Import') }}
  </el-button>

  <el-dialog v-model="showModal" :append-to-body="true" :title="$t('Import Products')" class="fct-bulk-import-dialog">
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

    <el-button v-if="!shouldShowMappers" @click="downloadSampleCsv" link>
      <DynamicIcon name="Download"/>
      {{ $t('Download a Sample CSV File') }}
    </el-button>

    <template v-if="shouldShowMappers">
      <div class="fct-import-map-table-wrap">
        <h4 class="fct-import-map-heading">{{$t('Map Columns')}}</h4>
        <table class="fct-import-map-table">
          <thead>
            <tr>
              <th>{{$t('Products Fields')}}</th>
              <th>{{$t('CSV Headers')}}</th>
            </tr>
          </thead>

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
      </div>

      <div class="dialog-footer">
        <el-button @click="addProductsToTable()">
          {{ $t('Add Products') }}
        </el-button>

        <el-button @click="addProductsToTable(false)">
          {{ $t('Clear and Add Products') }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>
