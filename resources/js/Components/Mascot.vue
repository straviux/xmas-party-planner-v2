<script setup>
import scorHatUrl from '../../assets/scorHatData.js';

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
        <div class="scor">
            <div class="scor-head">
                <img class="scor-hat-img" :src="scorHatUrl" alt="" />
                <div class="scor-face">
                    <div class="scor-eye scor-eye-left"></div>
                    <div class="scor-eye scor-eye-right"></div>
                    <div class="scor-face-lower">
                        <div class="scor-nose"></div>
                        <div class="scor-mouth">
                            <div class="scor-mouth-outer"></div>
                            <div class="scor-mouth-inner"></div>
                        </div>
                        <div class="scor-mouth-line"></div>
                    </div>
                    <div class="scor-blush scor-blush-left"></div>
                    <div class="scor-blush scor-blush-right"></div>
                </div>
                <div class="scor-face-fluff scor-face-fluff-left"></div>
                <div class="scor-face-fluff scor-face-fluff-right"></div>
                <div class="scor-ear scor-ear-left"></div>
                <div class="scor-ear-right-wrap">
                    <div class="scor-ear scor-ear-right"></div>
                    <div class="scor-ear-right-fluff"></div>
                </div>
                <div class="scor-beard"></div>
            </div>
            <div class="scor-body">
                <div class="scor-tail"></div>
                <div class="scor-torso"></div>
                <div class="scor-neck"></div>
                <div class="scor-arm scor-arm-left"></div>
                <div class="scor-arm scor-arm-right"></div>
                <div class="scor-leg scor-leg-left">
                    <div class="scor-leg-foot"><div class="scor-leg-foot-pad"></div></div>
                </div>
                <div class="scor-leg scor-leg-right">
                    <div class="scor-leg-foot"><div class="scor-leg-foot-pad"></div></div>
                </div>
            </div>
        </div>
    </div>
</template>
