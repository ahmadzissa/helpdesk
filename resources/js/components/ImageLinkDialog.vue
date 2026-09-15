<script setup>
import { ref } from 'vue';
import { imageLink } from '../replyEditor';
import Modal from './Modal.vue';

const emit = defineEmits(['close', 'insert']);
const address = ref(''), label = ref(''), error = ref('');
function insert() {
    try { emit('insert', imageLink(address.value, label.value)); }
    catch (exception) { error.value = exception.message; }
}
</script>

<template>
    <Modal title="Insert image from URL" @close="emit('close')">
        <form class="form-stack" @submit.prevent="insert">
            <label>Image URL<input v-model="address" type="url" placeholder="https://example.com/image.png" required autofocus /></label>
            <label>Image description (optional)<input v-model="label" placeholder="Describe the image" /></label>
            <p class="form-description">Display an image directly from its link. No upload needed.</p>
            <p v-if="error" class="error-message" role="alert">{{ error }}</p>
            <div class="form-actions"><button type="button" class="secondary-button" @click="emit('close')">Cancel</button><button type="submit" class="primary-button">Insert image</button></div>
        </form>
    </Modal>
</template>
