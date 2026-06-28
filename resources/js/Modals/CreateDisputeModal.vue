<script setup>
import Modal from "@/Components/Modals/Modal.vue";
import ModalHeader from "@/Components/Modals/Components/ModalHeader.vue";
import ModalBody from "@/Components/Modals/Components/ModalBody.vue";
import ModalFooter from "@/Components/Modals/Components/ModalFooter.vue";
import Dropzone from "@/Components/Form/Dropzone.vue";
import InputError from "@/Components/InputError.vue";
import { storeToRefs } from "pinia";
import { useModalStore } from "@/store/modal.js";
import { useViewStore } from "@/store/view.js";
import { useForm, router } from "@inertiajs/vue3";
import { computed, watch } from "vue";

const modalStore = useModalStore();
const viewStore = useViewStore();
const { createDisputeModal } = storeToRefs(modalStore);

const form = useForm({
    receipt: null,
});

const orderId = computed(() => createDisputeModal.value.params?.order_id ?? null);

const storeRoute = computed(() => {
    if (viewStore.isSupportViewMode) {
        return route('support.disputes.store', orderId.value);
    }

    return route('admin.disputes.store', orderId.value);
});

const redirectRoute = computed(() => {
    if (viewStore.isSupportViewMode) {
        return route('support.orders.index');
    }

    return route(viewStore.adminPrefix + 'orders.index');
});

const close = () => {
    modalStore.closeModal('createDispute');
};

const resetForm = () => {
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (! orderId.value || form.processing) {
        return;
    }

    form.post(storeRoute.value, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            modalStore.closeAll();
            resetForm();
            router.visit(redirectRoute.value, {
                preserveScroll: true,
            });
        },
    });
};

watch(
    () => createDisputeModal.value.showed,
    (showed) => {
        if (showed) {
            resetForm();
        }
    }
);
</script>

<template>
    <Modal :show="createDisputeModal.showed" max-width="sm" @close="close">
        <ModalHeader title="Открыть спор" @close="close" />
        <ModalBody>
            <p class="text-sm text-base-content/70 mb-4">
                Прикрепите чек или другое подтверждение оплаты. Спор будет отправлен трейдеру на проверку.
            </p>
            <Dropzone
                v-model="form.receipt"
                description="Расширение: jpeg, jpg, png, pdf. Максимум 5 МБ."
            />
            <InputError :message="form.errors.receipt" class="mt-2" />
        </ModalBody>
        <ModalFooter>
            <div class="flex justify-end gap-2 w-full">
                <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    :disabled="form.processing"
                    @click="close"
                >
                    Отмена
                </button>
                <button
                    type="button"
                    class="btn btn-warning btn-sm"
                    :disabled="form.processing || ! form.receipt"
                    @click="submit"
                >
                    Открыть спор
                </button>
            </div>
        </ModalFooter>
    </Modal>
</template>
