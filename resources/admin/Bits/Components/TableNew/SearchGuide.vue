<template>
  <el-button class="fct-search-guide-btn" link size="small" @click="dialogVisible = true">
    {{ $t('Search Help') }}
  </el-button>

  <el-dialog
      v-model="dialogVisible"
      :title="$t('Search Operators Guide')"
      :append-to-body="true"
      class="fct-search-guide-dialog"
  >
    <div class="fct-search-guide-wrap">

      <div class="fct-search-guide-example">
        <h5>{{ $t('Available Operators') }}</h5>
        <ul class="fct-search-guide-list">
          <li>
            <el-tag type="info">&gt; {{ $t('Greater Than') }}</el-tag>
          </li>
          <li>
            <el-tag type="info">&lt; {{ $t('Less Than') }}</el-tag>
          </li>
          <li>
            <el-tag type="info">&gt;= {{ $t('Greater Than or Equal') }}</el-tag>
          </li>
          <li>
            <el-tag type="info">&lt;= {{ $t('Less Than or Equal') }}</el-tag>
          </li>
          <li>
            <el-tag type="info">= {{ $t('Equal') }}</el-tag>
          </li>
          <li>
            <el-tag type="info">!= {{ $t('Not Equal') }}</el-tag>
          </li>
        </ul>
      </div>

      <Alert
          icon="InformationFill"
          type="info"
          :content="$t('Note: For plain text searches, simply type your query without operators. The system will search across all text fields.')"
      />

      <el-tabs v-model="activeName" class="demo-tabs">
        <el-tab-pane :label="$t('Examples')" name="examples">
          <div class="fct-search-guide-example">
            <div class="fct-search-guide-examples-list">
              <template v-for="(fields, index) in table.getSearchGuideOptions()" :key="index">

                <h5 v-if="fields.examples?.length">{{translate('Field: ') + index}}</h5>

                <template v-for="(example, index) in fields.examples" :key="index">
                  <div class="fct-search-guide-example-item" @click="()=>{
                         fillSearch(example)
                      }">
                    <div>
                      <div class="example-query">{{ example }}</div>
                    </div>
                    <div class="click-hint">{{ $t('Click to try') }}</div>
                  </div>
                </template>

              </template>

            </div>
          </div>
        </el-tab-pane>

        <el-tab-pane :label="$t('Fields')" name="fields">
          <div class="fct-search-guide-example">
            <div class="fct-search-guide-examples-list">
              <template v-for="(example, index) in table.getSearchGuideOptions()" :key="index">
                <div class="fct-search-guide-example-item" @click="()=>{
                  if(Array.isArray(example.examples) && example.examples.length > 0){
                    fillSearch(example.examples[0])
                  }
                }">

                  <div>
                    <div class="example-query">{{ index }}</div>
                    <div class="example-description">{{ example.description }}</div>
                    <div v-if="example.note" class="example-description">{{ example.note }}</div>
                  </div>
                  <div v-if="example.examples?.length" class="click-hint">{{ $t('Click to try') }}</div>
                </div><!-- /.fct-search-guide-example-item -->
              </template>

            </div>
          </div>
        </el-tab-pane>
      </el-tabs>


    </div>
  </el-dialog>
</template>

<script setup>
import {ref} from 'vue'
import Alert from "@/Bits/Components/Alert.vue";
import translate from "@/utils/translator/Translator";

const dialogVisible = ref(false);
const activeName = ref('examples');

const props = defineProps({
  table: {
    type: Object,
    required: true
  }
})


const fillSearch = (query) => {
  props.table.data.search = query;
  props.table.search();
  dialogVisible.value = false;
}


</script>
