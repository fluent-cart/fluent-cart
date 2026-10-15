<script setup>
import {computed, onBeforeUnmount, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ProductChips from "@/Bits/Components/ProductChips/ProductChips.vue";
import {groupIdsByLabels, mergeOptionLabels} from "@/Bits/Components/ProductChips/labelIndex";

/*
 * A remote select that knows nothing about what it is selecting.
 *
 * `dataKey` names a data set registered against the shared
 * advance_filter/get-filter-options route, so a second resource needs a
 * backend registration and a different prop value — not another component.
 */
const props = defineProps({
    dataKey: {
        type: String,
        required: true,
    },
    modelValue: {
        type: [String, Number, Array, null],
        default: null,
    },
    multiple: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: '',
    },
    limit: {
        type: Number,
        default: 20,
    },
    size: {
        type: String,
        default: 'small',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const options = ref([]);
const loading = ref(false);
const failed = ref(false);
const dropdownOpen = ref(false);

// Labels of every option loaded so far; a search replaces `options` but picks still need names.
const knownLabels = ref({});

/*
 * el-select needs an array in multiple mode and rebuilds its tags from it, so
 * a parent still holding the single-value default ('' or an id) has to be read
 * as a list rather than handed over as-is.
 */
const selected = computed({
    get: () => {
        const value = props.modelValue;
        const isEmpty = value === null || value === '' || value === undefined;

        if (props.multiple) {
            if (Array.isArray(value)) {
                return value;
            }

            return isEmpty ? [] : [value];
        }

        // A list reaching a single-value picker cannot be matched against the
        // options and would render as an empty field, so read the first entry.
        if (Array.isArray(value)) {
            return value.length ? value[0] : '';
        }

        return value;
    },
    set: (value) => emit('update:modelValue', value),
});

const chipGroups = computed(() => groupIdsByLabels(selected.value, knownLabels.value));

const selectedIds = computed(() => {
    if (props.modelValue === null || props.modelValue === '') {
        return [];
    }

    return Array.isArray(props.modelValue) ? props.modelValue : [props.modelValue];
});

/*
 * Only the newest request may write to `options`. Typing fires several, and a
 * slower earlier one landing last would replace the list with results for a
 * query the box no longer holds.
 */
let latestRequest = 0;

/*
 * What an empty query last returned. Clearing the box restores this instead of
 * refetching, so the menu goes back to the full list rather than keeping the
 * results of a search that is no longer typed.
 */
let unfilteredOptions = [];
let unfilteredLoaded = false;

let searchTimer = null;

/*
 * The request id of a search still in flight, 0 when the pending request is an
 * unfiltered one. Clearing the box may retire a search, but never the initial
 * load — discarding that would leave the picker empty with nothing queued to
 * fill it.
 */
let activeSearchId = 0;

const fetchOptions = (search = '') => {
    const requestId = ++latestRequest;

    if (search) {
        activeSearchId = requestId;
    }

    loading.value = true;
    failed.value = false;

    const params = {
        remote_data_key: props.dataKey,
        search: search,
        limit: props.limit,
    };

    /*
     * Sent only when there is no search term: the backend cannot honour both
     * at once, and pinning an already chosen row is only needed for the first
     * load — while someone is typing, they are looking for something else.
     */
    if (!search && selectedIds.value.length) {
        params.include_ids = selectedIds.value;
    }

    Rest.get('advance_filter/get-filter-options', params)
        .then((response) => {
            if (requestId !== latestRequest) {
                return;
            }

            options.value = response.options || [];
            knownLabels.value = mergeOptionLabels(knownLabels.value, options.value);

            if (!search) {
                unfilteredOptions = options.value;
                unfilteredLoaded = true;
            }
        })
        .catch((errors) => {
            if (requestId !== latestRequest) {
                return;
            }

            failed.value = true;
            Notify.error(errors?.data?.message || translate('Failed to load options'));
        })
        .finally(() => {
            if (activeSearchId === requestId) {
                activeSearchId = 0;
            }

            if (requestId === latestRequest) {
                loading.value = false;
            }
        });
};

/*
 * An empty query arrives two ways: the box was cleared, and el-select's own
 * call on every dropdown open. Both want the unfiltered list back, which is
 * usually already in hand — refetching it is what made the menu flash on open.
 *
 * A search queued or in flight has to go first, or it lands afterwards and
 * fills the menu with results for text no longer in the box.
 */
const search = (query) => {
    clearTimeout(searchTimer);

    if (query) {
        loading.value = true;
        searchTimer = setTimeout(() => fetchOptions(query), 400);
        return;
    }

    // Only a search is retired. An unfiltered request in flight is the first
    // load, and dropping it would leave the picker empty for good.
    if (activeSearchId) {
        latestRequest++;
        activeSearchId = 0;
        loading.value = false;
    }

    if (unfilteredLoaded) {
        // A queued-only search leaves no request to turn the spinner off.
        loading.value = false;
        options.value = unfilteredOptions;
        return;
    }

    // Never got a full list — either the first load failed, or it is still
    // running and will fill the menu when it lands.
    if (!loading.value) {
        fetchOptions();
    }
};

/*
 * A saved value arriving after mount — the parent re-fetched its record — has
 * no label until the matching row is loaded, so ask for it.
 */
watch(() => props.modelValue, (value) => {
    if (!value) {
        return;
    }

    const known = options.value.some((option) => selectedIds.value.includes(option.value));

    if (!known) {
        fetchOptions();
    }
});

/*
 * The same instance can be pointed at a different resource — condition rows are
 * keyed by index, so removing one re-points a surviving row at another
 * attribute. Everything held here belongs to the old key, and leaving it would
 * offer rows from one resource under another and let one be saved.
 */
watch(() => props.dataKey, () => {
    clearTimeout(searchTimer);
    activeSearchId = 0;
    unfilteredOptions = [];
    unfilteredLoaded = false;
    failed.value = false;
    options.value = [];
    knownLabels.value = {};

    // Bumps the request counter, so anything still in flight for the old key
    // is retired as it lands.
    fetchOptions();
});

onMounted(() => fetchOptions());

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <el-select
        v-model="selected"
        class="w-full fct-resource-selector"
        filterable
        remote
        clearable
        :reserve-keyword="false"
        :multiple="multiple"
        :size="size"
        :disabled="disabled"
        :loading="loading"
        :loading-text="translate('Loading...')"
        :remote-method="search"
        :placeholder="placeholder || translate('Search and select')"
        popper-class="fct-resource-selector-popper"
        @visible-change="(visible) => dropdownOpen = visible"
    >
        <template v-if="multiple" #tag>
            <ProductChips class="fct-product-chips--wrap" :groups="chipGroups" :tooltips-disabled="dropdownOpen"/>
        </template>
        <el-option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            :label="option.label"
        >
            <span class="fct-resource-option-label">
                {{ option.label }}
            </span>
            <span v-if="option.description" class="fct-resource-option-description">
                {{ option.description }}
            </span>
        </el-option>
    </el-select>
</template>

