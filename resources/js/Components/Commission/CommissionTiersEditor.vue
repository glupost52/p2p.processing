<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import NumberInput from '@/Components/NumberInput.vue';
import InputHelper from '@/Components/InputHelper.vue';
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    mode: {
        type: String,
        default: 'full',
    },
    currency: {
        type: String,
        default: 'RUB',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const tiers = computed({
    get: () => props.modelValue ?? [],
    set: (value) => emit('update:modelValue', value),
});

const showTraderRate = computed(() => props.mode === 'full' || props.mode === 'trader_only');
const showTotalRate = computed(() => props.mode === 'full' || props.mode === 'total_only');

const createEmptyTier = () => {
    const tier = {
        min_amount: '',
        max_amount: '',
    };

    if (showTraderRate.value) {
        tier.trader_commission_rate = '';
    }

    if (showTotalRate.value) {
        tier.total_service_commission_rate = '';
    }

    return tier;
};

const addTier = () => {
    tiers.value = [...tiers.value, createEmptyTier()];
};

const removeTier = (index) => {
    tiers.value = tiers.value.filter((_, tierIndex) => tierIndex !== index);
};

const updateTierField = (index, field, value) => {
    const nextTiers = tiers.value.map((tier, tierIndex) => {
        if (tierIndex !== index) {
            return tier;
        }

        return {
            ...tier,
            [field]: value,
        };
    });

    tiers.value = nextTiers;
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-medium">Ставки по сумме</div>
            <button
                type="button"
                class="btn btn-outline btn-primary btn-xs"
                :disabled="disabled"
                @click="addTier"
            >
                Добавить диапазон
            </button>
        </div>

        <InputHelper
            :model-value="`Суммы в ${currency.toUpperCase()}. Если диапазоны не заданы — используются значения по умолчанию.`"
        />

        <div v-if="!tiers.length" class="text-xs text-base-content/60">
            Tier'ы не настроены.
        </div>

        <div
            v-for="(tier, index) in tiers"
            :key="index"
            class="rounded-box border border-base-300 p-3 space-y-3"
        >
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase text-base-content/60">
                    Диапазон {{ index + 1 }}
                </div>
                <button
                    type="button"
                    class="btn btn-ghost btn-xs text-error"
                    :disabled="disabled"
                    @click="removeTier(index)"
                >
                    Удалить
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <InputLabel :for="`tier_min_${index}`" value="От" />
                    <NumberInput
                        :id="`tier_min_${index}`"
                        :model-value="tier.min_amount"
                        class="mt-1 block w-full"
                        placeholder="1000"
                        :disabled="disabled"
                        @update:model-value="updateTierField(index, 'min_amount', $event)"
                    />
                </div>
                <div>
                    <InputLabel :for="`tier_max_${index}`" value="До" />
                    <NumberInput
                        :id="`tier_max_${index}`"
                        :model-value="tier.max_amount"
                        class="mt-1 block w-full"
                        placeholder="50000"
                        :disabled="disabled"
                        @update:model-value="updateTierField(index, 'max_amount', $event)"
                    />
                </div>
                <div v-if="showTraderRate">
                    <InputLabel :for="`tier_trader_${index}`" value="Трейдер %" />
                    <NumberInput
                        :id="`tier_trader_${index}`"
                        :model-value="tier.trader_commission_rate"
                        class="mt-1 block w-full"
                        step="0.1"
                        placeholder="0.0"
                        :disabled="disabled"
                        @update:model-value="updateTierField(index, 'trader_commission_rate', $event)"
                    />
                </div>
                <div v-if="showTotalRate">
                    <InputLabel :for="`tier_total_${index}`" value="Тотал %" />
                    <NumberInput
                        :id="`tier_total_${index}`"
                        :model-value="tier.total_service_commission_rate"
                        class="mt-1 block w-full"
                        step="0.1"
                        placeholder="0.0"
                        :disabled="disabled"
                        @update:model-value="updateTierField(index, 'total_service_commission_rate', $event)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
