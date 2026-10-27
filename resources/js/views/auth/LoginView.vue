<template>
  <div>
    <h1 class="text-center text-[28px] font-extrabold leading-tight text-[var(--text)]">
      {{ $t('auth.loginTitle') }}
    </h1>
    <p class="mt-2 text-center text-sm leading-7 text-[var(--muted)]">
      {{ $t('auth.loginSubtitle') }}
    </p>

    <!-- General error -->
    <div
      v-if="generalError"
      class="mt-6 flex items-start gap-2.5 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold leading-6 text-[var(--crimson)]"
      role="alert"
      aria-live="polite"
    >
      <AlertCircle class="mt-0.5 h-[18px] w-[18px] shrink-0" aria-hidden="true" />
      <span>{{ generalError }}</span>
    </div>

    <form class="mt-7 space-y-5" novalidate @submit.prevent="handleLogin">
      <!-- Email -->
      <div>
        <label for="email" class="mb-2 block text-sm font-semibold text-[var(--text)]">
          {{ $t('auth.email') }}
        </label>

        <div class="relative">
          <Mail
            class="pointer-events-none absolute right-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
            aria-hidden="true"
          />
          <input
            id="email"
            ref="emailInput"
            v-model.trim="form.email"
            type="email"
            dir="ltr"
            inputmode="email"
            autocomplete="username"
            spellcheck="false"
            placeholder="name@example.com"
            class="h-12 w-full rounded-xl border bg-[var(--card)] pl-4 pr-11 text-left text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70"
            :class="fieldClass(errors.email)"
            :aria-invalid="Boolean(errors.email)"
            :aria-describedby="errors.email ? 'email-error' : undefined"
          />
        </div>

        <p
          v-if="errors.email"
          id="email-error"
          class="mt-2 flex items-center gap-1.5 text-sm font-medium text-[var(--crimson)]"
          role="alert"
        >
          <AlertCircle class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ errors.email }}</span>
        </p>
      </div>

      <!-- Password -->
      <div>
        <div class="mb-2 flex items-center justify-between gap-3">
          <label for="password" class="block text-sm font-semibold text-[var(--text)]">
            {{ $t('auth.password') }}
          </label>
        </div>

        <div class="relative">
          <Lock
            class="pointer-events-none absolute right-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
            aria-hidden="true"
          />
          <input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            dir="ltr"
            autocomplete="current-password"
            spellcheck="false"
            placeholder="••••••••"
            class="h-12 w-full rounded-xl border bg-[var(--card)] pl-12 pr-11 text-left text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70"
            :class="fieldClass(errors.password)"
            :aria-invalid="Boolean(errors.password)"
            :aria-describedby="errors.password ? 'password-error' : undefined"
          />
          <button
            type="button"
            class="absolute left-2.5 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :aria-label="showPassword ? $t('auth.hidePassword') : $t('auth.showPassword')"
            :title="showPassword ? $t('auth.hidePassword') : $t('auth.showPassword')"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-[18px] w-[18px]" aria-hidden="true" />
            <Eye v-else class="h-[18px] w-[18px]" aria-hidden="true" />
          </button>
        </div>

        <p
          v-if="errors.password"
          id="password-error"
          class="mt-2 flex items-center gap-1.5 text-sm font-medium text-[var(--crimson)]"
          role="alert"
        >
          <AlertCircle class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ errors.password }}</span>
        </p>
      </div>

      <!-- Remember me / forgot password -->
      <div class="flex flex-wrap items-center justify-between gap-3 pt-0.5">
        <label for="remember" class="flex cursor-pointer select-none items-center gap-2.5 text-sm font-medium text-[var(--muted)]">
          <AppCheckbox
            id="remember"
            :checked="rememberMe"
            @change="rememberMe = $event.target.checked"
          />
          <span>{{ $t('auth.rememberMe') }}</span>
        </label>

        <button
          type="button"
          class="text-sm font-semibold text-[var(--crimson)] transition-colors hover:text-[var(--crimson-hover)] hover:underline"
          @click="showForgotHint"
        >
          {{ $t('auth.forgotPassword') }}
        </button>
      </div>

      <!-- Submit -->
      <button
        type="submit"
        :disabled="loading || cooldown > 0"
        class="mt-1 flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] text-[15px] font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-60"
      >
        <span
          v-if="loading"
          class="h-[18px] w-[18px] animate-spin rounded-full border-2 border-white/35 border-t-white"
          aria-hidden="true"
        ></span>
        <span>
          {{ cooldown > 0 ? $t('auth.retryAfter', { seconds: cooldown }) : $t('auth.loginButton') }}
        </span>
      </button>
    </form>

    <p class="mt-6 text-center text-sm leading-7 text-[var(--muted)]">
      {{ $t('auth.helpText') }}
    </p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertCircle, Eye, EyeOff, Lock, Mail } from 'lucide-vue-next';
import { useAuthStore } from '../../stores/auth';
import { useNotificationStore } from '../../stores/notification';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';

const { t } = useI18n();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();
const router = useRouter();
const route = useRoute();

const emailInput = ref(null);
const form = reactive({ email: '', password: '' });
const errors = reactive({ email: '', password: '' });
const generalError = ref('');
const showPassword = ref(false);
const rememberMe = ref(false);
const loading = ref(false);
const cooldown = ref(0);
let cooldownTimer = null;

const fieldClass = (error) =>
  error
    ? 'border-[var(--crimson)] ring-4 ring-[var(--crimson-soft)]'
    : 'border-[var(--border)] focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]';

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const validate = () => {
  errors.email = !form.email
    ? t('auth.emailRequired')
    : emailPattern.test(form.email)
      ? ''
      : t('auth.emailInvalid');

  errors.password = form.password ? '' : t('auth.passwordRequired');

  return !errors.email && !errors.password;
};

const startCooldown = (seconds) => {
  cooldown.value = seconds;
  clearInterval(cooldownTimer);
  cooldownTimer = setInterval(() => {
    cooldown.value -= 1;
    if (cooldown.value <= 0) {
      clearInterval(cooldownTimer);
      cooldownTimer = null;
      cooldown.value = 0;
    }
  }, 1000);
};

const showForgotHint = () => {
  notificationStore.info(t('auth.forgotHint'));
};

const handleLogin = async () => {
  if (loading.value || cooldown.value > 0) return;

  generalError.value = '';
  errors.email = '';
  errors.password = '';

  if (!validate()) {
    emailInput.value?.focus();
    return;
  }

  loading.value = true;
  const res = await authStore.login(form.email, form.password, null, rememberMe.value);
  loading.value = false;

  if (res.success) {
    notificationStore.success(t('auth.welcomeBack'));
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
    router.push(redirect.startsWith('/') ? redirect : '/');
    return;
  }

  if (res.status === 429) {
    generalError.value = t('auth.tooManyAttempts');
    startCooldown(60);
    return;
  }

  if (res.status === 403) {
    generalError.value = res.message || t('auth.inactiveAccount');
    return;
  }

  generalError.value = t('auth.invalidCredentials');

  if (res.errors?.email?.[0]) {
    errors.email = res.errors.email[0];
  }
  if (res.errors?.password?.[0]) {
    errors.password = res.errors.password[0];
  }
};

onBeforeUnmount(() => {
  clearInterval(cooldownTimer);
});
</script>
