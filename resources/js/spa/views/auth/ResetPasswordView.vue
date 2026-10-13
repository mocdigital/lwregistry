<script setup lang="ts">
import { computed } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import AuthBrandPanel from '@/components/shell/AuthBrandPanel.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import UiPasswordField from '@/components/ui/UiPasswordField.vue'
import checkIcon from '@/assets/figma/icons/check.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Reset password — Figma 492:47337 (1280x900).
 *
 * NOTE ON THE NODE NAME: the frame is called "wizard-step-1-business-identity",
 * but its right-form-panel (492:47378) is a password-reset form — New Password,
 * Confirm Password, a strength meter and a rules checklist. The name is wrong,
 * not the content. That also explains why the prototype has no link into it and
 * no link from it to step 2: it was never part of the registration wizard.
 *
 * Same 580/700 split and brand panel as verify-email / forgot-password.
 */

/*
 * The token and the address come from the signed link
 * (`GET /reset-password/{token}?email=…`), and both have to be posted back —
 * `Password::reset()` matches on the pair. The screen collected neither.
 */
const props = defineProps<{ token: string; email: string | null }>()

const form = useForm({
  token: props.token,
  email: props.email ?? '',
  password: '',
  password_confirmation: '',
})

const password = computed(() => form.password)

const rules = computed(() => [
  { label: t('reset_password.at_least_8_characters'), ok: password.value.length >= 8 },
  { label: t('reset_password.at_least_one_uppercase_letter'), ok: /[A-Z]/.test(password.value) },
  { label: t('reset_password.at_least_one_lowercase_letter'), ok: /[a-z]/.test(password.value) },
  { label: t('reset_password.at_least_one_number_0_9'), ok: /\d/.test(password.value) },
  { label: t('reset_password.at_least_one_special_character'), ok: /[^A-Za-z0-9]/.test(password.value) },
])

function submit() {
  form.post('/reset-password', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}

const met = computed(() => rules.value.filter((r) => r.ok).length)
const strength = computed(() =>
  met.value <= 2 ? t('settings.weak') : met.value <= 4 ? t('data.status.medium') : t('settings.strong'),
)
</script>

<template>
  <div class="flex min-h-dvh items-start">
    <AuthBrandPanel />

    <section class="flex min-h-dvh flex-1 items-center justify-center bg-surface-secondary px-5 py-8 sm:py-12">
      <form class="w-full max-w-[420px]" @submit.prevent="submit">
        <RouterLink href="/" class="lg:hidden">
          <img :src="logo" alt="LW Registry" class="logo-adaptive mb-8 h-9 w-auto object-contain" />
        </RouterLink>

        <h1 class="font-serif text-3xl font-bold text-[#0f172a]">
          {{ t('reset_password.reset_your_password') }}
        </h1>
        <p class="mt-3 text-base text-[#475569]">
          {{ t('reset_password.create_a_new_secure_password_for_your_lw_r') }}
        </p>

        <!-- The address is fixed by the link that got here; showing it read-only
             makes it obvious which account is being reset, and posting it is
             required by Password::reset(). -->
        <p v-if="form.email" class="mt-6 text-sm text-[#475569]">
          {{ t('reset_password.resetting_the_password_for') }}
          <strong class="font-semibold text-[#0f172a]">{{ form.email }}</strong>
        </p>

        <p
          v-if="form.errors.email"
          class="mt-4 rounded-md border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-danger"
          role="alert"
        >
          {{ form.errors.email }}
        </p>

        <div class="mt-8">
          <UiPasswordField
            v-model="form.password"
            label="New Password"
            autocomplete="new-password"
          />
          <p v-if="form.errors.password" class="mt-2 text-xs text-danger">
            {{ form.errors.password }}
          </p>
        </div>
        <div class="mt-5">
          <UiPasswordField
            v-model="form.password_confirmation"
            label="Confirm Password"
            autocomplete="new-password"
          />
        </div>

        <p class="mt-5 flex items-center gap-2 text-sm">
          <span class="text-[#475569]">{{ t('reset_password.password_strength') }}</span>
          <span
            class="font-semibold"
            :class="strength === 'Strong' ? 'text-primary' : strength === 'Medium' ? 'text-warning' : 'text-danger'"
          >
            {{ strength }}
          </span>
        </p>

        <ul class="mt-3 space-y-2">
          <li v-for="r in rules" :key="r.label" class="flex items-center gap-2 text-2xs">
            <span
              class="grid size-4 shrink-0 place-items-center rounded-full"
              :class="r.ok ? 'bg-primary' : 'bg-surface-tertiary'"
              aria-hidden="true"
            >
              <img v-show="r.ok" :src="checkIcon" alt="" class="size-2.5" />
            </span>
            <span :class="r.ok ? 'text-ink' : 'text-[#94a3b8]'">{{ r.label }}</span>
          </li>
        </ul>

        <button
          type="submit"
          :disabled="form.processing"
          class="mt-7 w-full rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
        >
          {{ form.processing ? t('reset_password.resetting') : t('reset_password.reset_password') }}
        </button>

        <p class="mt-5 text-center text-sm">
          <RouterLink href="/login" class="font-semibold text-[#1b7f4b] hover:underline">
            {{ t('reset_password.back_to_sign_in') }}
          </RouterLink>
        </p>
      </form>
    </section>
  </div>
</template>
