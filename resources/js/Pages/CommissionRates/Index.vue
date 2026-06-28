<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    trader: {
        type: Object,
        default: null,
    },
    canSelectTrader: {
        type: Boolean,
        default: false,
    },
    traders: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const pageTitle = computed(() => {
    if (props.canSelectTrader) {
        if (props.trader) {
            return `Ставки: ${props.trader.name}`;
        }

        return 'Ставки трейдеров';
    }

    return 'Мои ставки';
});

const traderOptions = computed(() => {
    return props.traders.map((trader) => ({
        value: trader.id,
        label: `${trader.name} (${trader.email})`,
    }));
});

const selectedTraderId = computed(() => props.filters.user_id ?? '');

const sortedRows = computed(() => {
    return [...props.rows].sort((left, right) => {
        const gatewayCompare = String(left.gateway_sort).localeCompare(String(right.gateway_sort), 'ru');

        if (gatewayCompare !== 0) {
            return gatewayCompare;
        }

        const operationCompare = String(left.operation_type).localeCompare(String(right.operation_type));

        if (operationCompare !== 0) {
            return operationCompare;
        }

        return (left.min_amount ?? 0) - (right.min_amount ?? 0);
    });
});

const operationTypeLabel = (type) => {
    return type === 'payout' ? 'Выплаты' : 'Сделки';
};

const amountRangeLabel = (row) => {
    if (row.min_amount === null || row.max_amount === null) {
        return 'Все суммы';
    }

    return `${row.min_amount} – ${row.max_amount}`;
};

const sourceLabel = (source) => {
    const labels = {
        individual: 'Индивидуальная',
        gateway_flat: 'Стандарт метода',
        gateway_tier: 'Стандарт метода',
    };

    return labels[source] ?? source;
};

const sourceBadgeClass = (source) => {
    if (source === 'individual') {
        return 'badge-primary';
    }

    return 'badge-ghost';
};

const showBaseRate = (row) => {
    if (row.base_trader_commission_rate === null || row.base_trader_commission_rate === undefined) {
        return false;
    }

    return Math.abs(row.trader_commission_rate - row.base_trader_commission_rate) >= 0.0001;
};

const formatRate = (rate) => {
    if (rate === null || rate === undefined) {
        return '—';
    }

    return `${rate}%`;
};

const onTraderChange = (event) => {
    const userId = event.target.value;

    router.visit(route('trader.commission-rates.index', userId ? { user_id: userId } : {}), {
        preserveScroll: true,
    });
};

defineOptions({ layout: AuthenticatedLayout });
</script>

<template>
    <div>
        <Head :title="pageTitle" />

        <div class="mx-auto space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-base-content">{{ pageTitle }}</h2>
                    <p class="text-sm text-base-content/70 mt-1">
                        Индивидуальные и стандартные ставки по методам. Для сделки применяется ставка по сумме и методу.
                    </p>
                </div>
            </div>

            <div v-if="canSelectTrader" class="card bg-base-100 shadow">
                <div class="card-body p-4 sm:p-6">
                    <label class="form-control w-full max-w-xl">
                        <span class="label-text mb-2">Трейдер</span>
                        <select
                            class="select select-bordered w-full"
                            :value="selectedTraderId"
                            @change="onTraderChange"
                        >
                            <option value="">Выберите трейдера</option>
                            <option
                                v-for="option in traderOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>
            </div>

            <div v-if="!trader && canSelectTrader" class="alert">
                <span>Выберите трейдера, чтобы посмотреть его ставки.</span>
            </div>

            <div v-else-if="sortedRows.length === 0" class="card bg-base-100 shadow">
                <div class="card-body p-6 text-sm text-base-content/70">
                    Нет данных по ставкам. Добавьте реквизиты на активные методы или назначьте индивидуальные ставки.
                </div>
            </div>

            <div v-else class="card bg-base-100 shadow">
                <div class="card-body p-0 sm:p-0">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Метод</th>
                                    <th>Операция</th>
                                    <th>Диапазон</th>
                                    <th class="text-right">Ваша ставка</th>
                                    <th class="text-right hidden sm:table-cell">Стандарт метода</th>
                                    <th>Источник</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in sortedRows" :key="row.id">
                                    <td class="whitespace-nowrap">{{ row.payment_gateway_name }}</td>
                                    <td class="whitespace-nowrap">{{ operationTypeLabel(row.operation_type) }}</td>
                                    <td class="whitespace-nowrap">{{ amountRangeLabel(row) }}</td>
                                    <td class="text-right font-medium whitespace-nowrap">{{ formatRate(row.trader_commission_rate) }}</td>
                                    <td class="text-right whitespace-nowrap hidden sm:table-cell text-base-content/70">
                                        <span v-if="showBaseRate(row)">{{ formatRate(row.base_trader_commission_rate) }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm" :class="sourceBadgeClass(row.source)">
                                            {{ sourceLabel(row.source) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
