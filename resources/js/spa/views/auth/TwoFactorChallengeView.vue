<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import BareLayout from '@/layouts/BareLayout.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: BareLayout })

/*
 * The second step of signing in.
 *
 * The caller is NOT authenticated here — the password stage verified the
 * credentials and logged straight back out, leaving only a pending user id in
 * the session. Nothing on this page identifies the account, deliberately: the
 * screen is reachable by anyone who guessed a password, so naming the member
 * would confirm the guess.
 */

const form = useForm({ code: '', recovery: false })

const codeInput = ref<HTMLInputElement | null>(null)

const heading = computed(() =>
  form.recovery ? t('two_factor_challenge.enter_a_recovery_code') : t('two_factor_challenge.enter_your_authentication_code'),
)

async function toggleRecovery() {
  form.recovery = !form.recovery
  form.code = ''
  form.clearErrors()
  await nextTick()
  codeInput.value?.focus()
}

function submit() {
  form.post('/two-factor-challenge', {
    onError: () => {
      form.code = ''
      codeInput.value?.focus()
    },
  })
}
</script>

<template>
  <div class="grid min-h-dvh place-items-center bg-surface-secondary px-5 py-8 sm:py-12">
    <div class="w-full max-w-md rounded-2xl border border-border bg-white p-5 sm:p-8">
      <h1 class="font-serif text-3xl font-bold text-ink">{{ t('two_factor_challenge.two_step_verification') }}</h1>
      <p class="mt-2 text-xs text-ink-secondary">
        {{
          form.recovery
            ? t('auth.two_factor.recovery_prompt')
            : t('auth.two_factor.prompt')
        }}
      </p>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <label class="block">
          <span class="text-xs font-semibold text-ink-secondary">{{ heading }}</span>
          <input
            ref="codeInput"
            v-model="form.code"
            type="text"
            :inputmode="form.recovery ? 'text' : 'numeric'"
            :autocomplete="form.recovery ? 'off' : 'one-time-code'"
            :placeholder="form.recovery ? 'XXXXX-XXXXX' : '123456'"
            :maxlength="form.recovery ? 11 : 6"
            autofocus
            spellcheck="false"
            class="mt-1 w-full rounded-md border border-border-input px-3 py-2.5 text-center font-mono text-lg tracking-[0.3em] text-ink focus:border-focus focus:outline-none"
          />
          <span v-if="form.errors.code" class="mt-1 block text-2xs text-danger" role="alert">
            {{ form.errors.code }}
          </span>
        </label>

        <button
          type="submit"
          class="btn-primary w-full rounded-md px-6 py-2.5 text-xs font-semibold disabled:opacity-60"
          :data-active="form.code.length > 0 || undefined"
          :disabled="form.processing || form.code.length === 0"
        >
          {{ form.processing ? t('settings.verifying') : t('auth.two_factor.verify') }}
        </button>
      </form>

      <button
        type="button"
        class="mt-5 text-2xs font-semibold text-ink-secondary hover:text-ink hover:underline"
        @click="toggleRecovery"
      >
        {{ form.recovery ? t('auth.two_factor.use_app') : "I've lost access to my authenticator" }}
      </button>
    </div>
  </div>
</template>
