<template>
    <div 
        class="fct-customer-dashboard-empty-state"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <div class="fct-customer-dashboard-empty-state-inner">
            <div class="fct-empty-icon-box" aria-hidden="true">
                <slot name="icon"></slot>
            </div>
            <h4>{{ title }}</h4>
            <p>{{ text }}</p>

            <el-button 
                tag="a" 
                :href="shop_url" 
                rel="noopener noreferrer" 
                :aria-label="$t('Back to Shopping Now')"
            >
                {{ $t('Back to Shopping Now') }}
            </el-button>
        </div>
    </div>
</template>

<script>
    export default {
        name: 'EmptyState',
        props: ['title', 'text'],
        data() {
            return {
                shop_url: window.fluentcart_customer_profile_vars?.shop?.shop_url
            }
        }
    }
</script>
