<script setup>
import {computed, h, nextTick, onBeforeUnmount, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import PlacementPreviewBumpCard from "./parts/PlacementPreviewBumpCard.vue";
import PlacementSelect from "./parts/PlacementSelect.vue";
import PlacementPreviewCartDrawer from "./parts/PlacementPreviewCartDrawer.vue";
import PlacementPreviewCartPage from "./parts/PlacementPreviewCartPage.vue";
import CartIcon from "@/Bits/Components/Icons/Cart.vue";
import {placementOptions} from "./placementOptions";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    position: {
        type: String,
        default: ''
    },
    showInCart: {
        type: String,
        default: 'no'
    },
    bump: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:modelValue', 'apply']);

const isOpen = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const root = ref(null);
const canvas = ref(null);
const cartOpen = ref(false);
const view = ref('checkout');
const announcement = ref('');

// A try-out copy of the form; it reaches the form only through "Use this placement".
const currentPosition = ref(props.position);
const currentShowInCart = ref(props.showInCart);
const cartEnabled = computed(() => currentShowInCart.value === 'yes');
const changed = computed(() => currentPosition.value !== props.position || currentShowInCart.value !== props.showInCart);

const labels = Object.fromEntries(placementOptions().map((option) => [option.value, option.label]));

const reset = () => {
    currentPosition.value = props.position;
    currentShowInCart.value = props.showInCart;
};

const apply = () => {
    emit('apply', {position: currentPosition.value, showInCart: currentShowInCart.value});
    isOpen.value = false;
};

watch(() => props.modelValue, (open) => {
    if (open) {
        reset();
        view.value = 'checkout';
        cartOpen.value = false;
        announcement.value = '';
    }
});

// Unticking the option removes the cart button and the cart page, so both have to go.
watch(cartEnabled, (enabled) => {
    if (!enabled) {
        cartOpen.value = false;
        view.value = 'checkout';
    }
});

// Bring the bump into view after a placement change, so the move is easy to follow.
watch(currentPosition, (position) => {
    /* translators: %1$s: placement name */
    announcement.value = translate('Moved to %1$s', labels[position] || '');

    if (view.value !== 'checkout') {
        return;
    }

    nextTick(() => {
        const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        root.value?.querySelector('.fct-placement-preview__page .fct-placement-preview__slot')
            ?.scrollIntoView({behavior: reduce ? 'auto' : 'smooth', block: 'center'});
    });
});

const isHidden = computed(() => currentPosition.value === 'disabled');

// One standing live region: a notice that appears already filled is often not announced.
const liveMessage = computed(() => {
    if (!props.bump) {
        return translate('Save a product for this offer to see it here.');
    }

    return isHidden.value && view.value === 'checkout' ? translate('OrderBump_Preview_Hidden') : announcement.value;
});

// '' is the Default placement, so it is a real anchor, not "none".
const at = (anchor) => !!props.bump && currentPosition.value === anchor;

// Pointer events, so mouse, pen and touch all drag. The sidebar select is the keyboard path.
const dragging = ref(false);
const overZone = ref(null);
let drag = null;

const zone = (key) => ({
    class: ['fct-placement-preview__zone', {'is-empty': !at(key), 'is-over': overZone.value === key}],
    'data-zone': key,
    'data-label': labels[key]
});

const nearestZone = (x, y) => {
    let best = null;
    let bestDistance = Infinity;

    root.value.querySelectorAll('[data-zone]').forEach((element) => {
        const rect = element.getBoundingClientRect();
        const dx = Math.max(rect.left - x, 0, x - rect.right);
        const dy = Math.max(rect.top - y, 0, y - rect.bottom);
        const distance = Math.hypot(dx, dy);

        if (distance < bestDistance) {
            bestDistance = distance;
            best = element.getAttribute('data-zone');
        }
    });

    return bestDistance < 160 ? best : null;
};

const moveDrag = (event) => {
    if (!drag || event.pointerId !== drag.pointerId) {
        return;
    }

    // Offset from the pointer, so the zone under it stays visible.
    drag.ghost.style.left = (event.clientX + 18) + 'px';
    drag.ghost.style.top = (event.clientY + 18) + 'px';
    overZone.value = nearestZone(event.clientX, event.clientY);

    // Below lg the whole preview scrolls, not the canvas, and touch-none blocks native scrolling.
    const scroller = canvas.value.scrollHeight > canvas.value.clientHeight ? canvas.value : root.value;
    const area = scroller.getBoundingClientRect();
    if (event.clientY < area.top + 60) {
        scroller.scrollTop -= 14;
    } else if (event.clientY > area.bottom - 60) {
        scroller.scrollTop += 14;
    }
};

const stopDrag = () => {
    if (drag) {
        drag.ghost.remove();
        drag = null;
    }
    dragging.value = false;
    overZone.value = null;
};

const startDrag = (event) => {
    if (event.button !== 0) {
        return;
    }

    event.preventDefault();
    const slot = event.currentTarget;
    const ghost = slot.cloneNode(true);
    ghost.classList.add('fct-placement-preview__ghost');
    ghost.style.width = Math.min(slot.offsetWidth, 420) + 'px';
    document.body.appendChild(ghost);
    slot.setPointerCapture(event.pointerId);

    drag = {ghost, pointerId: event.pointerId};
    dragging.value = true;
    moveDrag(event);
};

const endDrag = (event) => {
    if (!drag || event.pointerId !== drag.pointerId) {
        return;
    }

    const target = event.type === 'pointerup' ? overZone.value : null;
    stopDrag();

    if (target !== null) {
        currentPosition.value = target;
    }
};

onBeforeUnmount(stopDrag);

const BumpSlot = () => h('div', {
    class: 'fct-placement-preview__slot',
    onPointerdown: startDrag,
    onPointermove: moveDrag,
    onPointerup: endDrag,
    onPointercancel: endDrag
}, [
    h('span', {class: 'fct-placement-preview__slot-label'}, translate('Drag to move')),
    h(PlacementPreviewBumpCard, {bump: props.bump})
]);

const methods = ['25%', '30%', '20%'];
const items = [['70%', '35%'], ['55%', '30%']];
</script>

<template>
    <el-config-provider :z-index="100000">
        <fct-dialog
            v-model="isOpen"
            fullscreen
            append-to-body
            :title="translate('Placement preview')"
            class="fct-placement-preview-modal"
            @closed="stopDrag"
        >
            <template #header>
                <div class="fct-placement-preview__header">
                    <span class="fct-placement-preview__title">{{ translate('Placement preview') }}</span>
                    <span class="fct-placement-preview__subtitle">
                        {{ translate('Drag the offer to where it should appear on the checkout.') }}
                    </span>
                </div>
            </template>

            <div ref="root" class="fct-placement-preview" :class="{'is-dragging': dragging}">
                <div class="fct-placement-preview__main">
                    <div ref="canvas" class="fct-placement-preview__canvas">
                        <div class="fct-placement-preview__switch">
                            <fct-choice
                                v-model="view"
                                type="radio"
                                button
                                size="small"
                                :options="[{label: translate('Checkout'), value: 'checkout'}, {label: translate('Cart page'), value: 'cart', disabled: !cartEnabled}]"
                                :aria-label="translate('Preview page')"
                            />
                            <p v-if="!cartEnabled" class="fct-placement-preview__sidebar-note">
                                {{ translate('Turn on the cart option in the sidebar to preview the cart page.') }}
                            </p>
                        </div>

                        <div v-if="!bump" class="fct-placement-preview__notice fct-placement-preview__notice--note">
                            {{ translate('Save a product for this offer to see it here.') }}
                        </div>

                        <div class="sr-only" role="status">{{ liveMessage }}</div>

                        <div class="fct-placement-preview__page" role="group" :aria-label="translate('Sample checkout')">
                            <PlacementPreviewCartPage v-if="view === 'cart'" :bump="bump"/>

                            <template v-else>
                                <div v-if="isHidden" class="fct-placement-preview__notice fct-placement-preview__notice--top">
                                    {{ translate('OrderBump_Preview_Hidden') }}
                                </div>

                                <div class="fct-placement-preview__grid">
                                    <div class="fct-placement-preview__col">
                                        <div v-bind="zone('above_customer')"><BumpSlot v-if="at('above_customer')"/></div>

                                        <div class="fct-placement-preview__card">
                                            <div v-for="width in ['35%', '30%', '50%']" :key="width" class="fct-placement-preview__field">
                                                <span class="fct-placement-preview-bar" :style="{width}"></span>
                                                <span class="fct-placement-preview-bar" style="width: 16%"></span>
                                            </div>
                                        </div>

                                        <div class="fct-placement-preview__check">
                                            <span class="fct-placement-preview__box"></span>
                                            <span class="fct-placement-preview-bar" style="width: 45%"></span>
                                        </div>

                                        <div v-bind="zone('below_customer')"><BumpSlot v-if="at('below_customer')"/></div>

                                        <div class="fct-placement-preview__heading-row">
                                            <h3 class="fct-placement-preview__heading">{{ translate('Billing Address') }}</h3>
                                            <span class="fct-placement-preview-bar" style="width: 12%"></span>
                                        </div>
                                        <div class="fct-placement-preview__card fct-placement-preview__card--padded fct-placement-preview__stack">
                                            <span class="fct-placement-preview-bar" style="width: 40%"></span>
                                            <span class="fct-placement-preview-bar" style="width: 70%"></span>
                                        </div>

                                        <div class="fct-placement-preview__check">
                                            <span class="fct-placement-preview__box"></span>
                                            <span class="fct-placement-preview-bar" style="width: 40%"></span>
                                        </div>

                                        <h3 class="fct-placement-preview__heading">{{ translate('Shipping Options') }}</h3>
                                        <div class="fct-placement-preview__card fct-placement-preview__row">
                                            <span class="fct-placement-preview__radio fct-placement-preview__radio--on"></span>
                                            <span class="fct-placement-preview__grow"><span class="fct-placement-preview-bar" style="width: 35%"></span></span>
                                            <span class="fct-placement-preview-bar" style="width: 48px"></span>
                                        </div>

                                        <div v-bind="zone('above_payment')"><BumpSlot v-if="at('above_payment')"/></div>

                                        <h3 class="fct-placement-preview__heading">{{ translate('Payment') }}</h3>
                                        <div
                                            v-for="(width, index) in methods"
                                            :key="width"
                                            class="fct-placement-preview__card fct-placement-preview__row"
                                        >
                                            <span class="fct-placement-preview__radio" :class="{'fct-placement-preview__radio--on': index === 0}"></span>
                                            <span class="fct-placement-preview__grow"><span class="fct-placement-preview-bar" :style="{width}"></span></span>
                                        </div>

                                        <div v-bind="zone('below_payment')"><BumpSlot v-if="at('below_payment')"/></div>

                                        <div class="fct-placement-preview__button">
                                            <span class="fct-placement-preview-bar fct-placement-preview-bar--light" style="width: 28%"></span>
                                        </div>
                                    </div>

                                    <div class="fct-placement-preview__col">
                                        <div v-bind="zone('above_summary')"><BumpSlot v-if="at('above_summary')"/></div>

                                        <div class="fct-placement-preview__card fct-placement-preview__card--padded">
                                            <h3 class="fct-placement-preview__heading fct-placement-preview__heading--flush">{{ translate('Order summary') }}</h3>
                                            <div v-for="item in items" :key="item[0]" class="fct-placement-preview__item">
                                                <span class="fct-placement-preview__thumb"></span>
                                                <div class="fct-placement-preview__grow fct-placement-preview__stack">
                                                    <span class="fct-placement-preview-bar" :style="{width: item[0]}"></span>
                                                    <span class="fct-placement-preview-bar" :style="{width: item[1]}"></span>
                                                </div>
                                                <span class="fct-placement-preview-bar" style="width: 44px"></span>
                                            </div>
                                            <div v-for="width in ['28%', '24%']" :key="width" class="fct-placement-preview__sum">
                                                <span class="fct-placement-preview-bar" :style="{width}"></span>
                                                <span class="fct-placement-preview-bar" style="width: 44px"></span>
                                            </div>
                                            <div class="fct-placement-preview__coupon">
                                                <span class="fct-placement-preview-bar" style="width: 40%"></span>
                                            </div>
                                            <div class="fct-placement-preview__sum fct-placement-preview__sum--total">
                                                <span class="fct-placement-preview-bar" style="width: 22%"></span>
                                                <span class="fct-placement-preview-bar" style="width: 56px"></span>
                                            </div>
                                        </div>

                                        <div v-bind="zone('below_summary')"><BumpSlot v-if="at('below_summary')"/></div>

                                        <div class="fct-placement-preview__note">
                                            <span class="fct-placement-preview__caret"></span>
                                            <span class="fct-placement-preview-bar" style="width: 30%"></span>
                                        </div>

                                        <div v-bind="zone('')"><BumpSlot v-if="at('')"/></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    <button
                        v-if="cartEnabled"
                        type="button"
                        class="fct-placement-preview__cart-btn"
                        :aria-label="translate('Open cart')"
                        @click="cartOpen = true"
                    >
                        <CartIcon aria-hidden="true"/>
                        <span class="fct-placement-preview__cart-count">2</span>
                    </button>
                </div>
                <aside class="fct-placement-preview__sidebar">
                    <template v-if="view === 'checkout'">
                        <h3 class="fct-placement-preview__sidebar-title">{{ translate('Checkout Placement') }}</h3>
                        <PlacementSelect v-model="currentPosition"/>
                        <p class="fct-placement-preview__sidebar-note">{{ translate('OrderBump_Position_Help') }}</p>

                        <h3 class="fct-placement-preview__sidebar-title mt-2">{{ translate('Hidden on checkout') }}</h3>
                        <div
                            class="fct-placement-preview__tray"
                            :class="{'is-over': overZone === 'disabled'}"
                            data-zone="disabled"
                        >
                            <BumpSlot v-if="at('disabled')"/>
                            <span v-else class="fct-placement-preview__sidebar-note text-center">
                                {{ translate('Drop the offer here to hide it on the checkout.') }}
                            </span>
                        </div>
                    </template>

                    <div class="fct-placement-preview__sidebar-group" :class="{'fct-placement-preview__sidebar-group--first': view === 'cart'}">
                        <fct-choice
                            v-model="currentShowInCart"
                            true-value="yes"
                            false-value="no"
                        >
                            {{ translate('Show in cart drawer and cart page') }}
                        </fct-choice>
                        <p class="fct-placement-preview__sidebar-note">
                            {{ translate('Also offer this item in the cart drawer and on the cart page.') }}
                        </p>
                    </div>

                    <div class="fct-placement-preview__actions">
                        <fct-button :disabled="!changed" @click="reset">{{ translate('Reset') }}</fct-button>
                        <fct-button type="primary" :disabled="!changed" @click="apply">{{ translate('Use this placement') }}</fct-button>
                    </div>
                </aside>
            </div>
        </fct-dialog>

        <PlacementPreviewCartDrawer v-if="cartEnabled" v-model="cartOpen" :bump="bump"/>
    </el-config-provider>
</template>
