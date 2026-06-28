<script setup>
import ModalFooter from '@/Components/Modals/Components/ModalFooter.vue';
import ModalBody from '@/Components/Modals/Components/ModalBody.vue';
import Modal from '@/Components/Modals/Modal.vue';
import ModalHeader from '@/Components/Modals/Components/ModalHeader.vue';
import CommissionTiersEditor from '@/Components/Commission/CommissionTiersEditor.vue';
import InputLabel from '@/Components/InputLabel.vue';
import NumberInput from '@/Components/NumberInput.vue';
import InputError from '@/Components/InputError.vue';
import { storeToRefs } from 'pinia';
import { useModalStore } from '@/store/modal.js';
import { computed, ref, watch } from 'vue';

const modalStore = useModalStore();
const { userCommissionRatesModal } = storeToRefs(modalStore);

const loading = ref(false);
const processing = ref(false);
const errors = ref({});
const serverError = ref(null);
const paymentGateways = ref([]);
const groups = ref([]);

const userId = computed(() => userCommissionRatesModal.value.params?.user?.id ?? null);
const userEmail = computed(() => userCommissionRatesModal.value.params?.user?.email ?? '');

const close = () => {
    modalStore.closeModal('userCommissionRates');
};

const resetState = () => {
    loading.value = false;
    processing.value = false;
    errors.value = {};
    serverError.value = null;
    paymentGateways.value = [];
    groups.value = [];
};

const createGroup = () => ({
    payment_gateway_id: paymentGateways.value[0]?.id ?? null,
    operation_type: 'order',
    mode: 'flat',
    trader_commission_rate: '',
    tiers: [],
});

const addGroup = () => {
    groups.value = [...groups.value, createGroup()];
};

const removeGroup = (index) => {
    groups.value = groups.value.filter((_, groupIndex) => groupIndex !== index);
};

const loadData = async () => {
    if (!userId.value) {
        return;
    }

    loading.value = true;
    serverError.value = null;

    try {
        const [optionsResponse, ratesResponse] = await Promise.all([
            axios.get(route('admin.payment-gateways.options')),
            axios.get(route('admin.users.commission-rates.index', userId.value)),
        ]);

        paymentGateways.value = optionsResponse.data?.data?.paymentGateways ?? [];
        const rates = ratesResponse.data?.data ?? [];
        groups.value = buildGroupsFromRates(rates);

        if (!groups.value.length) {
            groups.value = [];
        }
    } catch (error) {
        serverError.value = 'Не удалось загрузить ставки трейдера.';
    } finally {
        loading.value = false;
    }
};

const buildGroupsFromRates = (rates) => {
    const grouped = {};

    rates.forEach((rate) => {
        const key = `${rate.payment_gateway_id}-${rate.operation_type}`;

        if (!grouped[key]) {
            grouped[key] = {
                payment_gateway_id: rate.payment_gateway_id,
                operation_type: rate.operation_type,
                mode: rate.min_amount === null && rate.max_amount === null ? 'flat' : 'tiered',
                trader_commission_rate: rate.min_amount === null && rate.max_amount === null ? rate.trader_commission_rate : '',
                tiers: [],
            };
        }

        if (rate.min_amount !== null && rate.max_amount !== null) {
            grouped[key].mode = 'tiered';
            grouped[key].tiers.push({
                min_amount: rate.min_amount,
                max_amount: rate.max_amount,
                trader_commission_rate: rate.trader_commission_rate,
            });
        }
    });

    return Object.values(grouped);
};

const serializeRates = () => {
    const payload = [];

    groups.value.forEach((group) => {
        if (group.mode === 'flat') {
            payload.push({
                payment_gateway_id: Number(group.payment_gateway_id),
                operation_type: group.operation_type,
                min_amount: null,
                max_amount: null,
                trader_commission_rate: Number(group.trader_commission_rate),
                is_active: true,
            });

            return;
        }

        group.tiers.forEach((tier) => {
            payload.push({
                payment_gateway_id: Number(group.payment_gateway_id),
                operation_type: group.operation_type,
                min_amount: Number(tier.min_amount),
                max_amount: Number(tier.max_amount),
                trader_commission_rate: Number(tier.trader_commission_rate),
                is_active: true,
            });
        });
    });

    return payload;
};

const submit = async () => {
    if (!userId.value || processing.value) {
        return;
    }

    processing.value = true;
    errors.value = {};
    serverError.value = null;

    try {
        const response = await axios.put(route('admin.users.commission-rates.sync', userId.value), {
            rates: serializeRates(),
        }, {
            headers: { Accept: 'application/json' },
        });

        if (response.data?.success) {
            close();
            resetState();
        }
    } catch (error) {
        if (error.response?.status === 422) {
            serverError.value = error.response?.data?.message ?? 'Ошибка валидации ставок.';
            errors.value = error.response?.data?.errors ?? {};
        } else {
            serverError.value = 'Не удалось сохранить ставки.';
        }
    } finally {
        processing.value = false;
    }
};

watch(
    () => userCommissionRatesModal.value.showed,
    (showed) => {
        if (showed) {
            resetState();
            loadData();
        } else {
            resetState();
        }
    }
);
</script>

<template>
    <Modal :show="userCommissionRatesModal.showed" max-width="4xl" @close="close">
        <ModalHeader
            :title="`Ставки по методам — ${userEmail}`"
            @close="close"
        />

        <ModalBody>
            <div v-if="loading" class="py-6 text-center">
                <span class="loading loading-spinner loading-md"></span>
            </div>
            <div v-else class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm text-base-content/70">
                        Индивидуальные ставки трейдера по платежным методам и суммам.
                    </div>
                    <button type="button" class="btn btn-outline btn-primary btn-xs" @click="addGroup">
                        Добавить метод
                    </button>
                </div>

                <div v-if="!groups.length" class="text-sm text-base-content/60">
                    Персональные ставки не заданы — используются значения платежного метода.
                </div>

                <div
                    v-for="(group, index) in groups"
                    :key="index"
                    class="rounded-box border border-base-300 p-4 space-y-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium">Настройка {{ index + 1 }}</div>
                        <button type="button" class="btn btn-ghost btn-xs text-error" @click="removeGroup(index)">
                            Удалить
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <InputLabel :for="`gateway_${index}`" value="Метод" />
                            <select
                                :id="`gateway_${index}`"
                                v-model="group.payment_gateway_id"
                                class="select select-bordered select-sm w-full mt-1"
                            >
                                <option v-for="gateway in paymentGateways" :key="gateway.id" :value="gateway.id">
                                    {{ gateway.name }} ({{ gateway.currency }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :for="`operation_${index}`" value="Тип операции" />
                            <select
                                :id="`operation_${index}`"
                                v-model="group.operation_type"
                                class="select select-bordered select-sm w-full mt-1"
                            >
                                <option value="order">Сделки</option>
                                <option value="payout">Выплаты</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :for="`mode_${index}`" value="Режим" />
                            <select
                                :id="`mode_${index}`"
                                v-model="group.mode"
                                class="select select-bordered select-sm w-full mt-1"
                            >
                                <option value="flat">Единая ставка</option>
                                <option value="tiered">По сумме</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="group.mode === 'flat'">
                        <InputLabel :for="`flat_rate_${index}`" value="Трейдер %" />
                        <NumberInput
                            :id="`flat_rate_${index}`"
                            v-model="group.trader_commission_rate"
                            class="mt-1 block w-full"
                            step="0.1"
                        />
                    </div>

                    <CommissionTiersEditor
                        v-else
                        v-model="group.tiers"
                        mode="trader_only"
                        :currency="paymentGateways.find((gateway) => gateway.id === group.payment_gateway_id)?.currency ?? 'RUB'"
                    />
                </div>

                <div v-if="serverError" class="alert alert-error">
                    <span>{{ serverError }}</span>
                </div>
                <InputError :message="errors.rates?.[0]" />
            </div>
        </ModalBody>

        <ModalFooter>
            <button type="button" class="btn btn-sm" @click="close">
                Отмена
            </button>
            <button
                type="button"
                class="btn btn-sm btn-primary"
                :class="{ 'btn-disabled': processing }"
                :disabled="processing"
                @click="submit"
            >
                Сохранить
            </button>
        </ModalFooter>
    </Modal>
</template>
