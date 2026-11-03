import { reactive } from 'vue';

/**
 * Tiny reactive toast store. One global toast surface (Toast.vue) renders
 * whatever is queued here. Each toast auto-dismisses after ~3 seconds.
 */

let nextId = 1;

export const toastState = reactive({
    items: [],
});

export function dismissToast(id) {
    const index = toastState.items.findIndex((item) => item.id === id);
    if (index !== -1) {
        toastState.items.splice(index, 1);
    }
}

export function toast(message, variant = 'success') {
    const id = nextId++;
    toastState.items.push({ id, message, variant });
    window.setTimeout(() => dismissToast(id), 3000);
    return id;
}

export function toastSuccess(message) {
    return toast(message, 'success');
}

export function toastError(message) {
    return toast(message, 'error');
}

export default toast;
