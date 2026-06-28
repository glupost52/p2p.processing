<script setup>
import { computed } from 'vue';

const props = defineProps({
    current_daily_limit: {
        type: [String, Number, null],
        default: null,
    },
    daily_limit: {
        type: [String, Number, null],
        default: null,
    },
});

const normalizeNumber = (value) => {
    if (value === null || value === undefined || value === '') {
        return 0;
    }

    return Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0;
};

const hasLimit = computed(() => normalizeNumber(props.daily_limit) > 0);

const percent = computed(() => {
    if (!hasLimit.value) {
        return 0;
    }

    const current = normalizeNumber(props.current_daily_limit);
    const limit = normalizeNumber(props.daily_limit);

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
                    {{ current_daily_limit }}
                </span>
                <span class="mx-1 opacity-70">из</span>
                <span class="text-xs font-semibold">
                    {{ daily_limit }}
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
