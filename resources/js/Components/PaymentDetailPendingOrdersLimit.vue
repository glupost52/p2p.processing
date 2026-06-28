<script setup>
import { computed } from 'vue';

const props = defineProps({
    pending_orders_count: {
        type: [Number, String],
        default: 0,
    },
    max_pending_orders_quantity: {
        type: [Number, String, null],
        default: null,
    },
});

const normalizeNumber = (value) => {
    if (value === null || value === undefined || value === '') {
        return 0;
    }

    return Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0;
};

const hasLimit = computed(() => normalizeNumber(props.max_pending_orders_quantity) > 0);

const percent = computed(() => {
    if (!hasLimit.value) {
        return 0;
    }

    const current = normalizeNumber(props.pending_orders_count);
    const limit = normalizeNumber(props.max_pending_orders_quantity);

    if (limit <= 0) {
        return 0;
    }

    return Math.min(100, (current / limit) * 100);
});
</script>

<template>
    <div class="flex justify-end mb-1">
        <div class="relative text-nowrap">
            <template v-if="hasLimit">
                <span
                    class="text-xs font-semibold"
                    :class="{
                        'text-success': percent < 40,
                        'text-warning': percent >= 40 && percent < 80,
                        'text-error': percent >= 80,
                    }"
                >
                    {{ pending_orders_count }}
                </span>
                <span class="mx-1 opacity-70">из</span>
                <span class="text-xs font-semibold">
                    {{ max_pending_orders_quantity }}
                </span>
            </template>
            <template v-else>
                <span class="text-xs text-base-content/70">Без лимита</span>
            </template>
        </div>
    </div>
    <progress
        class="progress w-full"
        :class="{
            'progress-success': percent < 40,
            'progress-warning': percent >= 40 && percent < 80,
            'progress-error': percent >= 80,
        }"
        :value="percent"
        max="100"
        :aria-hidden="!hasLimit"
    ></progress>
</template>
