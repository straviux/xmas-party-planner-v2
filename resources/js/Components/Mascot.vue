<script setup>
defineProps({
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="mascot-wrap" aria-hidden="true">
        <div v-if="message" class="mascot-bubble">{{ message }}</div>
        <div class="mascot">
            <div class="mascot-hat">
                <div class="mascot-hat-pom"></div>
                <div class="mascot-hat-brim"></div>
            </div>
            <div class="mascot-arm left"></div>
            <div class="mascot-arm right"></div>
            <div class="mascot-body">
                <div class="mascot-eye left"></div>
                <div class="mascot-eye right"></div>
                <div class="mascot-cheek left"></div>
                <div class="mascot-cheek right"></div>
                <div class="mascot-smile"></div>
                <div class="mascot-belly"></div>
            </div>
        </div>
    </div>
</template>
