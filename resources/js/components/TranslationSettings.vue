<script setup>
import { ref, reactive, onMounted } from 'vue';
import { api, state, notify } from '../store';
import { translateText } from '../translation';
import LanguagePicker from './LanguagePicker.vue';
const form = reactive({ incoming: true, outgoing: false, auto_send: false, target: 'en', key: '' });
const hasKey = ref(false), loading = ref(true), saving = ref(false), testing = ref(false), error = ref(''), testResult = ref('');
async function load() { try { const config = await api('translation/config'); Object.assign(form, config.settings); hasKey.value = !!config.key; } catch (e) { error.value = e.message; } finally { loading.value = false; } }
async function save() { saving.value = true; error.value = ''; testResult.value = ''; try { const result = await api('translation/settings', { method: 'PUT', body: form }); state.workspace.translation = result.settings; Object.assign(form, result.settings); form.key = ''; hasKey.value = result.has_key; notify('Translation settings saved'); } catch (e) { error.value = e.message; } finally { saving.value = false; } }
async function test() { testing.value = true; error.value = ''; testResult.value = ''; try { const config = await api('translation/config'); const result = await translateText('Hola, necesito ayuda con mi pedido.', { target: form.target, key: form.key || config.key }); if (!result.sourceLanguage) throw new Error('Google returned text but no detected language. Check your translation provider.'); testResult.value = result.text + ' (detected: ' + result.sourceLanguage + ')'; } catch (e) { error.value = e.message; } finally { testing.value = false; } }
onMounted(load);
</script>
<template>
<p v-if="loading" class="muted">Loading translation settings…</p>
<form v-else class="settings-form" @submit.prevent="save"><div class="section-intro"><span class="setting-icon"><Icon name="globe" /></span><div><h2>Email translation</h2><p>Read conversations in your language and reply in your customer’s language.</p></div></div>
<label>Workspace reading language<LanguagePicker v-model="form.target" label="Workspace reading language" /></label>
<label class="check-label"><input type="checkbox" v-model="form.incoming" />Automatically translate emails when opening a ticket</label>
<p class="muted">Message text and image labels are translated together and saved for reuse. Subjects, links, and formatting stay as written. Original emails are always available with Show original.</p>
<label class="check-label"><input type="checkbox" v-model="form.outgoing" />Automatically use the customer’s language for replies</label>
<p class="muted">Google detects the source language of the latest customer email. That language is saved by email address across tickets; you can correct it in the ticket. Your reply uses that language. Private notes stay as written.</p>
<label class="check-label"><input type="checkbox" v-model="form.auto_send" :disabled="!form.outgoing" />Translate and send replies automatically when I click Send</label>
<p class="muted">Skip the preview step and send in the customer’s language. If translation fails, your draft is kept so you can retry. Replies already in the customer’s language are sent unchanged; other replies require successful translation.</p>
<div class="info-banner"><Icon name="clock" /><p>Automated replies, macros, and due follow-ups appear in Undelivered until you open their translation action. The automatic setting skips their preview too. The sending pause and recipient restrictions still apply.</p></div>
<label>Google browser translation API key<input v-model="form.key" type="password" autocomplete="new-password" :placeholder="hasKey ? 'Configured · leave blank to keep' : 'Enter your browser API key'" /></label>
<p class="muted">Uses the same Google translation flow as Areviews. The key is sent only to signed-in agents’ browsers. Use a browser key restricted to your helpdesk website. Email text is sent directly from the browser to Google.</p>
<p v-if="error" class="error-message" role="alert">{{ error }}</p><p v-if="testResult" class="translation-test" role="status">{{ testResult }}</p>
<div class="form-actions"><button type="button" class="secondary-button" @click="test" :disabled="testing || saving">{{ testing ? 'Testing Google…' : 'Test with a sample' }}</button><button class="primary-button" :disabled="saving || testing">{{ saving ? 'Saving…' : 'Save translation settings' }}</button></div>
</form>
</template>
