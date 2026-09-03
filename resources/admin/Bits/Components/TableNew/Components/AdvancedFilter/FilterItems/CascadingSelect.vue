<script setup>
import {ref, watch, computed, onMounted} from 'vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    table: {type: Object, required: true},
    itemConfig: {type: Object, required: true}
});

const model = defineModel();

const groupOptions = ref([]);
const groupCache = ref([]);
const groupLoading = ref(false);
const selectedGroupId = ref(null);

const termOptions = ref([]);
const termCache = ref([]);
const termLoading = ref(false);
const selectedTermIds = ref([]);
const match = ref('any');

// Restore from saved view
if (model.value && model.value.group_id) {
    selectedGroupId.value = model.value.group_id;
    selectedTermIds.value = model.value.term_ids || [];
    match.value = model.value.match || 'any';
}

const fetchGroups = () => {
    if (groupCache.value.length) {
        groupOptions.value = groupCache.value;
        return;
    }
    groupLoading.value = true;
    Rest.get('advance_filter/get-filter-options', {
        remote_data_key: 'attr_groups',
    }).then(res => {
        groupCache.value = res.options || [];
        groupOptions.value = groupCache.value;
    }).catch(err => {
        Notify.error(err);
    }).finally(() => {
        groupLoading.value = false;
    });
};

const fetchTerms = (groupId) => {
    if (!groupId) {
        termOptions.value = [];
        termCache.value = [];
        return;
    }
    if (termCache.value.length) {
        termOptions.value = termCache.value;
        return;
    }
    termLoading.value = true;
    Rest.get('advance_filter/get-filter-options', {
        remote_data_key: 'attr_terms',
        parent_id: groupId,
    }).then(res => {
        if (selectedGroupId.value !== groupId) {
            return;
        }
        termCache.value = res.options || [];
        termOptions.value = termCache.value;
    }).catch(err => {
        Notify.error(err);
    }).finally(() => {
        if (selectedGroupId.value === groupId) {
            termLoading.value = false;
        }
    });
};

const pushModel = (autoApply = true) => {
    if (!selectedGroupId.value) {
        const hadFilter = !!model.value;
        model.value = null;
        if (autoApply && hadFilter) {
            props.table.applyAdvancedFilter();
        }
        return;
    }
    model.value = {
        group_id: selectedGroupId.value,
        term_ids: selectedTermIds.value,
        match: match.value,
    };
    if (autoApply) {
        props.table.applyAdvancedFilter();
    }
};

watch(selectedGroupId, (newId, oldId) => {
    if (newId !== oldId) {
        selectedTermIds.value = [];
        termOptions.value = [];
        termCache.value = [];
        match.value = 'any';
    }
    fetchTerms(newId);
    pushModel(false);
});

watch(selectedTermIds, () => {
    pushModel();
});

watch(match, () => {
    pushModel();
});

const showMatch = computed(() => !!selectedGroupId.value);

onMounted(() => {
    fetchGroups();
    if (selectedGroupId.value) {
        fetchTerms(selectedGroupId.value);
    }
});
</script>

<template>
  <div class="flex items-center gap-2" style="flex-wrap: nowrap; min-width: 0">
    <el-select
        v-model="selectedGroupId"
        :loading="groupLoading"
        :placeholder="translate('Select attribute')"
        filterable
        clearable
        size="small"
        style="width: 160px; flex-shrink: 0"
    >
      <el-option
          v-for="group in groupOptions"
          :key="group.id"
          :value="group.id"
          :label="group.title"
      />
    </el-select>

    <div v-if="selectedGroupId" class="terms-group" style="flex: 1; min-width: 0; display: flex; align-items: stretch">
      <el-select
          v-model="selectedTermIds"
          :loading="termLoading"
          :placeholder="translate('Select terms')"
          multiple
          filterable
          size="small"
          style="flex: 1; min-width: 0"
      >
        <el-option
            v-for="term in termOptions"
            :key="term.id"
            :value="term.id"
            :label="term.title"
        />
      </el-select>

      <el-select
          v-if="showMatch"
          v-model="match"
          :disabled="selectedTermIds.length < 2"
          size="small"
          class="match-select"
          style="width: 80px; flex-shrink: 0"
      >
        <el-option value="any" label="OR" />
        <el-option value="all" label="AND" />
      </el-select>
    </div>
  </div>
</template>


