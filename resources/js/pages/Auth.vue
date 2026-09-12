<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { state } from '../store';
import { useNavigation } from '../useNavigation';
import { appUrl } from '../urls';
const props = defineProps({ mode: String });
const route = useNavigation(), page = usePage();
const mode = computed(() => props.mode), notice = computed(() => page.props.notice);
const form = useForm({ name: '', email: props.mode === 'reset' ? String(route.query.email || '') : '', password: '', password_confirmation: '', workspace_name: 'Areviews', sample_data: false, remember: true, token: String(route.query.token || '') });
const saving = computed(() => form.processing), error = computed(() => Object.values(form.errors).join(' '));
function submit() {
    const path = { register: '/register', login: '/login', forgot: '/forgot-password', reset: '/reset-password' }[mode.value];
    form.post(appUrl(path), { onSuccess: () => form.reset('password', 'password_confirmation') });
}
</script>
<template>
<main class="auth-page">
    <section class="auth-story">
        <Link class="wordmark" :href="$appUrl('/')"><span class="brand-mark">r<Icon name="arrow" :size="18" /></span> relay<span class="brand-period">.</span></Link>
        <div><span class="eyebrow">A LITTLE MORE HUMAN</span><h1>A calmer inbox.<br>A happier team.</h1><p>Bring every conversation into focus.<br>Make room for the people behind the tickets.</p>
            <div class="auth-illustration"><div class="illustration-line"><span class="avatar">OR</span><div><strong>Can I change my shipping address?</strong><small>Olivia Rhye · Just now</small></div><span class="status-badge open"><Icon name="circle" :size="13" />Open</span></div><div class="illustration-line faded"><Icon name="checks" /><span>One workspace. Everything in its place.</span></div></div>
        </div>
        <span class="auth-foot">Thoughtful support starts here.</span>
    </section>
    <section class="auth-form-wrap">
        <form class="auth-form" @submit.prevent="submit">
            <span class="eyebrow">{{ state.needsSetup ? 'FIRST-TIME SETUP' : 'YOUR SUPPORT WORKSPACE' }}</span>
            <h2>{{ state.needsSetup ? 'Create your administrator account' : mode === 'forgot' ? 'Reset your password.' : mode === 'reset' ? 'Choose a new password.' : 'Sign in' }}</h2>
            <p>{{ state.needsSetup ? 'No accounts exist yet. Register the first administrator to manage your helpdesk.' : mode === 'forgot' ? 'Enter the email address for your existing account.' : mode === 'reset' ? 'Use at least 12 characters.' : 'Sign in to pick up the conversation.' }}</p>
            <div v-if="error" class="error-message" role="alert">{{ error }}</div>
            <div v-if="notice" class="info-banner" role="status"><p>{{ notice }}</p></div>
            <template v-if="state.needsSetup"><label>Your name<input v-model="form.name" autocomplete="name" required placeholder="Ahmad Khalid" /></label><label>Workspace name<input v-model="form.workspace_name" required maxlength="100" /></label></template>
            <label>Email address<input v-model="form.email" type="email" autocomplete="username" required placeholder="you@company.com" /></label>
            <label v-if="state.needsSetup || mode !== 'forgot'">Password<input v-model="form.password" type="password" :autocomplete="state.needsSetup || mode === 'reset' ? 'new-password' : 'current-password'" :minlength="state.needsSetup || mode === 'reset' ? 12 : 1" required :placeholder="state.needsSetup || mode === 'reset' ? 'At least 12 characters' : 'Enter your password'" /></label>
            <label v-if="state.needsSetup || mode === 'reset'">Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required /></label>
            <label class="check-label" v-if="state.needsSetup"><input type="checkbox" v-model="form.sample_data" />Start with sample conversations and settings</label>
            <label class="check-label" v-else-if="mode === 'login'"><input type="checkbox" v-model="form.remember" />Keep me signed in</label>
            <button class="primary-button" :disabled="saving">{{ saving ? 'Just a moment…' : state.needsSetup ? 'Register administrator' : mode === 'forgot' ? 'Send reset link' : mode === 'reset' ? 'Update password' : 'Sign in' }}<Icon name="arrow" /></button>
            <Link v-if="!state.needsSetup" class="text-button" :href="$appUrl(mode === 'login' ? '/forgot-password' : '/login')">{{ mode === 'login' ? 'Forgot your password?' : 'Back to sign in' }}</Link>
            <small v-if="state.needsSetup">This first account gets full administrator access. After registration, connect your support email in Settings → Email accounts. Additional account registration stays disabled.</small>
            <small v-else>This is a private workspace. Sign in with your existing account.</small>
        </form>
    </section>
</main>
</template>

