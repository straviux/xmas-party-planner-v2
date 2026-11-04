<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { CameraOff, CircleAlert, Keyboard, ScanLine, X } from 'lucide-vue-next';
import jsQR from 'jsqr';

/**
 * Camera QR scanner for the party invite code. Opens as a modal card in
 * the guest theme, decodes with jsQR, and emits the extracted invite code.
 *
 * Events:
 * - scanned(code): a usable invite code was read. The camera is already
 *   stopped when this fires; the parent decides where the code goes
 *   (Landing navigates to /enter?code=, Enter runs its invite check).
 * - close: the guest dismissed the scanner (X, Cancel, backdrop, Escape).
 * - type-instead: the guest chose to type the code (also the only way out
 *   of the camera failure states). The parent routes to the code form.
 *
 * Failure states (unsupported browser, permission denied, no camera) are
 * shown inline in the card with a "type the code instead" button, never
 * as a black box or a dead end.
 */
const emit = defineEmits(['scanned', 'close', 'type-instead']);

// States: starting | scanning | unsupported | denied | no-camera | failed
const state = ref('starting');
const scanError = ref('');
const videoEl = ref(null);

let stream = null;
let rafId = null;
let lastScanAt = 0;
let lastBadText = '';
let stopped = false;

const FAILURE_COPY = {
    unsupported: {
        title: 'Camera scanning is not available here',
        text: 'This browser cannot use the camera on this page. You can type the invite code instead, it only takes a moment.',
    },
    denied: {
        title: 'Camera permission was not given',
        text: 'The scanner needs camera access to read the QR code. You can allow it in your browser settings, or type the invite code instead.',
    },
    'no-camera': {
        title: 'No camera was found',
        text: 'This device does not seem to have a camera the browser can use. You can type the invite code instead.',
    },
    failed: {
        title: 'The camera could not start',
        text: 'Something went wrong starting the camera. You can type the invite code instead.',
    },
};

/**
 * Pull the invite code out of whatever the QR code contains. Accepts the
 * invite link (/join/{code}), any URL carrying ?code={code}, or a bare
 * code on its own. Returns null when there is no usable code.
 */
function extractInviteCode(raw) {
    const text = (raw || '').trim();
    if (!text) return null;

    const joinMatch = text.match(/\/join\/([A-Za-z0-9_-]+)/);
    if (joinMatch) return joinMatch[1];

    try {
        const url = new URL(text);
        const fromQuery = url.searchParams.get('code');
        if (fromQuery && /^[A-Za-z0-9_-]+$/.test(fromQuery.trim())) {
            return fromQuery.trim();
        }
    } catch {
        // Not a URL, fall through to the bare-code check.
    }

    if (/^[A-Za-z0-9_-]+$/.test(text)) return text;

    return null;
}

function stopCamera() {
    stopped = true;
    if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
    }
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
    if (videoEl.value) {
        videoEl.value.srcObject = null;
    }
}

function scanFrame() {
    if (stopped) return;
    rafId = requestAnimationFrame(scanFrame);

    const video = videoEl.value;
    if (!video || video.readyState < 2 || !video.videoWidth) return;

    const now = performance.now();
    if (now - lastScanAt < 100) return; // about 10 decodes per second
    lastScanAt = now;

    // Cap the decode size so large camera frames stay cheap to scan.
    const scale = Math.min(1, 640 / video.videoWidth);
    const width = Math.round(video.videoWidth * scale);
    const height = Math.round(video.videoHeight * scale);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return;
    context.drawImage(video, 0, 0, width, height);

    let result = null;
    try {
        const imageData = context.getImageData(0, 0, width, height);
        result = jsQR(imageData.data, width, height);
    } catch {
        result = null;
    }
    if (!result || !result.data) return;

    const code = extractInviteCode(result.data);
    if (code) {
        stopCamera();
        emit('scanned', code);
        return;
    }

    // A readable QR code that is not a party invite: say so and keep
    // scanning (once per distinct code, so the message does not flap).
    if (result.data !== lastBadText) {
        lastBadText = result.data;
        scanError.value = 'That code is not a party invite. Try the QR code from your host.';
    }
}

async function startCamera() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        state.value = 'unsupported';
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
            audio: false,
        });
    } catch (err) {
        if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
            state.value = 'denied';
        } else if (
            err &&
            (err.name === 'NotFoundError' ||
                err.name === 'OverconstrainedError' ||
                err.name === 'DevicesNotFoundError')
        ) {
            state.value = 'no-camera';
        } else {
            state.value = 'failed';
        }
        return;
    }

    if (stopped) {
        // Closed while the permission prompt was up.
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
        return;
    }

    state.value = 'scanning';
    if (videoEl.value) {
        videoEl.value.srcObject = stream;
        try {
            await videoEl.value.play();
        } catch {
            // Autoplay hiccups leave a live preview in most browsers; the
            // scan loop also guards on readyState, so carry on.
        }
    }
    rafId = requestAnimationFrame(scanFrame);
}

function close() {
    stopCamera();
    emit('close');
}

function typeInstead() {
    stopCamera();
    emit('type-instead');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    startCamera();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    stopCamera();
});
</script>

<template>
    <div class="modal-backdrop" @click.self="close">
        <div class="modal-card pop-in scanner-card" role="dialog" aria-modal="true" aria-label="Scan QR code">
            <div class="scanner-head">
                <h3 class="modal-title" style="margin: 0">Scan the invite QR code</h3>
                <button type="button" class="icon-btn" aria-label="Close scanner" @click="close">
                    <X :size="19" />
                </button>
            </div>

            <template v-if="state === 'scanning' || state === 'starting'">
                <div class="scanner-frame-wrap">
                    <video ref="videoEl" class="scanner-video" muted playsinline autoplay></video>
                    <div class="scanner-frame" aria-hidden="true"></div>
                </div>
                <p class="scanner-hint">
                    <ScanLine :size="17" style="flex: none; margin-top: 1px" />
                    <span>
                        {{
                            state === 'starting'
                                ? 'Starting your camera...'
                                : 'Point your camera at the QR code your host shared.'
                        }}
                    </span>
                </p>
                <p v-if="scanError" class="error-text" style="margin: 12px 0 0">
                    <CircleAlert :size="18" style="flex: none; margin-top: 1px" />
                    <span>{{ scanError }}</span>
                </p>
            </template>

            <template v-else>
                <div class="scanner-failure">
                    <CameraOff :size="40" />
                    <p class="scanner-failure-title">{{ FAILURE_COPY[state].title }}</p>
                    <p class="muted" style="margin: 0; line-height: 1.55">{{ FAILURE_COPY[state].text }}</p>
                </div>
            </template>

            <div class="modal-actions" style="justify-content: space-between">
                <button type="button" class="btn btn-primary" @click="typeInstead">
                    <Keyboard :size="16" />
                    <span>Type the code instead</span>
                </button>
                <button type="button" class="btn btn-secondary" @click="close">Cancel</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scanner-card {
    width: min(94vw, 480px);
}

.scanner-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}

.scanner-frame-wrap {
    position: relative;
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
}

.scanner-video {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    background: #12122b;
    border: 1px solid var(--card-border);
    border-radius: 16px;
}

/* Viewfinder: a magenta-bordered box that dims everything outside it. */
.scanner-frame {
    position: absolute;
    inset: 12% 16%;
    border: 3px solid var(--magenta);
    border-radius: 18px;
    box-shadow: 0 0 0 999px rgba(10, 8, 30, 0.45);
    pointer-events: none;
}

.scanner-hint {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    color: var(--text-secondary);
    font-size: 0.92rem;
    line-height: 1.45;
    margin: 14px 0 0;
}

.scanner-failure {
    text-align: center;
    padding: 14px 0 4px;
    color: var(--text-secondary);
}

.scanner-failure-title {
    color: var(--text-primary);
    font-weight: 700;
    font-size: 1.02rem;
    margin: 12px 0 6px;
}
</style>
