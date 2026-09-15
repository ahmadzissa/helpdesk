<script setup>
defineProps({ disabled: Boolean, uploadingImage: Boolean });
defineEmits(['format', 'insert', 'link', 'image', 'image-url']);
</script>

<template>
    <div class="format-toolbar" role="toolbar" aria-label="Message formatting">
        <button type="button" :disabled="disabled" @click="$emit('format', '**')" title="Bold" aria-label="Bold"><b>B</b></button>
        <button type="button" :disabled="disabled" @click="$emit('format', '_')" title="Italic" aria-label="Italic"><i>I</i></button>
        <button type="button" :disabled="disabled" @click="$emit('format', '~~')" title="Strikethrough" aria-label="Strikethrough"><s>S</s></button>
        <button type="button" :disabled="disabled" @click="$emit('format', '&#96;')" title="Code" aria-label="Code">&lt;/&gt;</button>
        <span class="divider" />
        <button type="button" :disabled="disabled" @click="$emit('insert', '\n1. ')" title="Numbered list" aria-label="Numbered list">1.</button>
        <button type="button" :disabled="disabled" @click="$emit('insert', '\n- ')" title="Bulleted list" aria-label="Bulleted list">•</button>
        <span class="divider" />
        <button type="button" :disabled="disabled" @click="$emit('link')" title="Insert link" aria-label="Insert link"><Icon name="link" :size="15" /></button>
        <button type="button" :disabled="disabled || uploadingImage" @click="$emit('image')" title="Insert image" aria-label="Insert image"><Icon :name="uploadingImage ? 'loader' : 'image'" :size="15" /></button>
        <button type="button" :disabled="disabled" @click="$emit('image-url')" title="Insert image from URL" aria-label="Insert image from URL">Image URL</button>
    </div>
</template>
