<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link as RouterLink, useForm, usePage, router } from '@inertiajs/vue3'
import AuthBrandPanel from '@/components/shell/AuthBrandPanel.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import mailIcon from '@/assets/figma/icons/mail.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * "Back to sign in" has to sign you OUT first.
 *
 * This screen is behind `auth` and /login is behind `guest`, so a link there
 * was bounced straight back by the middleware — the control looked dead
 * because nothing moved. Anyone reading this page is signed in already; what
 * they want is to leave and use a different account.
 *
 * Posting through Inertia rather than a bare <form>: it sends the X-XSRF-TOKEN
 * header from the cookie, which is how every other write on this front end
 * authenticates. A native form with no token is a 419 that leaves the session
 * intact — the same bug the header's logout had.
 */
const leaving = ref(false)

function signOut() {
  leaving.value = true
  router.post('/logout', {}, { onFinish: () => (leaving.value = false) })
}

/*
 * Verify email — Figma 279:12934 (1280x900).
 * left-brand-panel 366:17128 (580) + right-form-panel 279:12981 (700).
 *
 * Orphaned in the prototype: no frame links here. Reached after registration
 * in a real flow.
 */

// The route passes the signed-in user's address; the screen showed only the
// generic "your email address".
defineProps<{ email: string | null }>()

const form = useForm({})

const page = usePage()
const resent = computed(() => page.props.flash.status === 'verification-link-sent')
// The mail provider refused the message; the account exists, the link does not.
const sendFailed = computed(() => page.props.flash.status === 'verification-link-failed')

function resend() {
  form.post('/email/verification-notification', { preserveScroll: true })
}
</script>

<template>
  <div class="flex min-h-dvh items-start">
    <AuthBrandPanel />

    <section class="flex min-h-dvh flex-1 items-center justify-center bg-surface-secondary px-5 py-8 sm:py-12">
      <div class="w-full max-w-[420px]">
        <RouterLink href="/" class="lg:hidden">
          <img :src="logo" alt="LW Registry" class="logo-adaptive mb-8 h-9 w-auto object-contain" />
        </RouterLink>

        <span class="grid size-12 place-items-center rounded-full bg-[#dcfce7]" aria-hidden="true">
          <img :src="mailIcon" alt="" class="size-6" />
        </span>

        <h1 class="mt-5 font-serif text-3xl font-bold text-[#0f172a]">
          {{ t('verify_email.verify_your_email_address') }}
        </h1>
        <p class="mt-3 text-base leading-relaxed text-[#475569]">
          {{ t('verify_email.we_ve_sent_a_verification_link_to') }}
          <strong v-if="email" class="font-semibold text-[#0f172a]">{{ email }}</strong>
          <template v-else>{{ t('verify_email.your_email_address') }}</template>{{ t('verify_email.please_check_your_inbox_and_follow_the_lin') }}
        </p>

        <p
          v-if="resent"
          class="mt-6 rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
          role="status"
        >
          {{ t('verify_email.a_fresh_verification_link_is_on_its_way') }}
        </p>

        <p
          v-if="sendFailed"
          class="mt-6 rounded-md border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-[#b91c1c]"
          role="alert"
        >
          {{ t('verify_email.we_could_not_send_the_verification_email') }}
        </p>

        <button
          type="button"
          :disabled="form.processing"
          class="mt-8 w-full rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
          @click="resend"
        >
          {{ form.processing ? t('forgot_password.sending') : t('verify_email.resend_verification_email') }}
        </button>

        <p class="mt-5 text-center text-sm">
          <button
            type="button"
            :disabled="leaving"
            class="font-semibold text-[#1b7f4b] hover:underline disabled:opacity-60"
            @click="signOut"
          >
            {{ leaving ? t('app_header.signing_out') : t('verify_email.back_to_sign_in') }}
          </button>
        </p>
      </div>
    </section>
  </div>
</template>
