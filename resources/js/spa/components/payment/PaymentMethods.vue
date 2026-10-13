<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link as RouterLink, router as inertiaRouter, useForm, usePage } from '@inertiajs/vue3'
import type { FeePayload } from '@/components/payment/FeeCalculator.vue'
import { useRoute, useRouter } from '@/composables/useRoute'

import iconShield from '@/assets/figma/payment/shield.svg'
import iconCreditCard from '@/assets/figma/payment/credit-card.svg'
import iconBuildingCollapsed from '@/assets/figma/payment/building-collapsed.svg'
import iconChevronDown from '@/assets/figma/payment/chevron-down.svg'
import iconChevronUp from '@/assets/figma/payment/chevron-up.svg'
import iconBuilding16 from '@/assets/figma/payment/building-16.svg'
import iconBuilding20 from '@/assets/figma/payment/building-20.svg'
import iconSavedCheck from '@/assets/figma/payment/saved-check.svg'
import iconCopy from '@/assets/figma/payment/copy.svg'
import iconInfo from '@/assets/figma/payment/info.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * Registration payment methods — the block that "Application payment page 1"
 * (Figma 384:17211) puts at the foot of its fee card:
 *
 *   espees-secure-payment-card 387:18311 — teal-bordered, amber CTA
 *   manual-payment-collapsed   387:18323 — accordion row, chevron down
 *
 * Opening the accordion is the "Step 2: ... ( Manual payment)" screen
 * 387:18447, which draws screen-manual-payment-expanded 387:19269 as a 628px
 * dialog over a scrim rather than expanding in place.
 *
 * The open state is mirrored to ?method=manual so the screen is linkable and
 * the browser Back button closes the dialog.
 *
 * Destinations are the prototype's own:
 *   espees-secure-payment-card 387:18311 -> 33:4      /application/fees
 *   manual-payment-collapsed   387:18323 -> 387:18447 ?method=manual
 *   screen-manual-payment...   387:19269 -> 253:7678  /application/documents
 */

/*
 * `sboId` is required for either method to do anything: Espees checkout and
 * the manual proof upload are both scoped to an application. Without it the
 * Espees button merely navigated to /application/fees and the upload panel
 * had nowhere to post.
 */
const props = defineProps<{
  sboId: number | string | null
  /** Fee schedule + gateway state; see SboFeePayload. */
  fees: FeePayload
  /**
   * No business exists yet — this is the add-business form, where pressing a
   * payment button is what saves the business and then pays for it. Both
   * methods hand their intent to the page instead of posting from here, and
   * `busy` is that page's form state.
   */
  defer?: boolean
  busy?: boolean
}>()

const emit = defineEmits<{
  (e: 'pay', value: { method: 'espees' } | { method: 'manual'; proof: File }): void
}>()

const page = usePage()
const feesError = computed(() => page.props.flash.feesError)

/*
 * Both methods are gated on a confirmed fee, because both endpoints are:
 * SboEspeesPaymentController@start bails without `calculated_fee`, and the
 * Livewire proof upload aborts 400. Before this the buttons were always live
 * and the failure only showed up as a redirect with a flash nothing rendered.
 */
const ready = computed(() => props.fees.confirmed && !props.fees.paid)
const espeesReady = computed(() => ready.value && props.fees.espees.available)

/** Deferred, there is no sboId yet and none is needed to press the button. */
const addressable = computed(() => props.defer || !!props.sboId)

const amount = computed(() => {
  if (props.fees.espees.testAccount) return '1 Espee'
  const fee = props.fees.calculatedFee
  return fee === null || fee === undefined
    ? t('payment_methods.your_registration_fee')
    : `${Number(fee).toLocaleString()} ${props.fees.currency ?? 'Espees'}`
})

const espees = useForm({})

/** Whichever request is in flight — ours, or the page's when deferred. */
const working = computed(() => (props.defer ? !!props.busy : espees.processing))

/*
 * The Espees hand-off, and its fallback.
 *
 * Primary path: post over Inertia. The server answers with 409 and an
 * X-Inertia-Location header (see SboEspeesPaymentController::toCheckout) and
 * the Inertia client turns that into a real navigation to payment.espees.org.
 *
 * Fallback: if that post fails outright — a dropped connection, a proxy that
 * eats the 409, anything that leaves the applicant staring at a button that
 * spun and stopped — submit the very same request as a native form, so the
 * BROWSER follows the redirect instead of an XHR.
 *
 * Safe to retry: the endpoint reuses any checkout already in flight for this
 * application and redirects to its existing payment_ref, so a second attempt
 * cannot open a second checkout or charge twice.
 *
 * Note the fallback is NOT a no-JS path — the app is client-rendered, so
 * without JS this button does not exist at all. It is error recovery only.
 */
const espeesForm = ref<HTMLFormElement | null>(null)

const csrfToken =
  document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

const espeesAction = computed(() =>
  props.sboId ? `/sbo/applications/${props.sboId}/payments/espees/start` : '',
)

/*
 * `reference` is drawn on the panel but `sbo_payment_proofs` has no column for
 * it, so it is not posted — the endpoint would reject the extra key on a
 * stricter validator and there is nowhere to keep it. See INTEGRATION.md.
 */
const proof = useForm<{ proof: File | null }>({ proof: null })

/*
 * `.submit()` on the element rather than requestSubmit(): it bypasses the
 * bound @submit.prevent and performs a genuine browser navigation, which is
 * the whole point — the browser follows the 302 that an XHR could not.
 */
function handOffNatively() {
  espeesForm.value?.submit()
}

function payWithEspees() {
  if (props.defer) {
    // The page owns the request: it posts the business details WITH this
    // intent, and the server starts the checkout once the business exists.
    if (!props.busy) emit('pay', { method: 'espees' })

    return
  }

  if (!props.sboId || espees.processing) return

  /*
   * Not `onError`: that fires for a validation response, which this endpoint
   * never returns — it flashes and redirects instead, so hooking it would be
   * a fallback that can never run.
   *
   * The two ways this visit fails WITHOUT navigating are a response Inertia
   * cannot read (`invalid` — say a proxy that dropped the 409 or its
   * X-Inertia-Location header) and a request that never completed
   * (`exception`). Both are cancelled, so the applicant lands on the checkout
   * instead of Inertia's error modal.
   */
  const listening = [
    inertiaRouter.on('invalid', (event) => {
      // Scoped to our own request: another visit failing while this one is in
      // flight must not fire the Espees hand-off.
      if (!String(event.detail.response.config.url ?? '').includes(espeesAction.value)) return

      event.preventDefault()
      handOffNatively()
    }),
    inertiaRouter.on('exception', (event) => {
      event.preventDefault()
      handOffNatively()
    }),
  ]

  espees.post(espeesAction.value, {
    onFinish: () => listening.forEach((stop) => stop()),
  })
}

/*
 * Merchant details come from the Fees admin page, per country.
 *
 * These were `const MERCHANT_CODE = 'MOCDII1'` and the literal "Espees Max",
 * so the manual-payment panel told every applicant to pay a merchant code
 * unrelated to the configured one — an administrator could set ESPMER01 and
 * applicants would still be sent to MOCDII1.
 */
const merchantCode = computed(() => props.fees.merchant?.code || '—')
const merchantApp = computed(() => props.fees.merchant?.app || 'the Espees app')

const route = useRoute()
const router = useRouter()

const open = computed(() => route.query.method === 'manual')

/*
 * The manual panel is a query-string state, so opening and closing it rewrites
 * the URL. vue-router took a `{ query }` object here; Inertia takes a string,
 * so the query is built explicitly. `preserveState` keeps the surrounding form
 * alive across the visit rather than remounting it.
 */
function setQuery(next: Record<string, string>) {
  const qs = new URLSearchParams(next).toString()
  router.push(qs ? `${route.path}?${qs}` : route.path)
}

function openManual() {
  setQuery({ ...route.query, method: 'manual' })
}

function closeManual() {
  const { method, ...rest } = route.query
  void method
  setQuery(rest)
}

/* Copy the merchant code, with a short confirmation on the button. */
const copied = ref(false)
let copyTimer: ReturnType<typeof setTimeout> | undefined

async function copyCode() {
  try {
    await navigator.clipboard.writeText(merchantCode.value)
  } catch {
    return
  }
  copied.value = true
  clearTimeout(copyTimer)
  copyTimer = setTimeout(() => (copied.value = false), 2000)
}

/* Proof of payment: the design shows the native "No file chosen" affordance. */
const fileName = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function onFile(e: Event) {
  const files = (e.target as HTMLInputElement).files
  const file = files && files.length ? files[0] : null

  fileName.value = file?.name ?? ''
  proof.proof = file
}

/*
 * Was: navigate to step 3 and upload nothing. Uploads to
 * POST /sbo/applications/{sbo}/payments/proof, which stores the file against
 * the SBO and stamps `payment_submitted_at` — then follows 387:19269 onward to
 * Step 3, but only once the file has actually landed.
 */
function submitProof() {
  if (!proof.proof) return

  if (props.defer) {
    if (!props.busy) emit('pay', { method: 'manual', proof: proof.proof })

    return
  }

  if (!props.sboId) return

  proof.post(`/sbo/applications/${props.sboId}/payments/proof`, {
    forceFormData: true,
    onSuccess: () => {
      proof.reset()
      fileName.value = ''
      if (fileInput.value) fileInput.value.value = ''

      /*
       * Close the dialog. The upload answers with back(), which keeps
       * ?method=manual in the URL — and `open` reads that query — so without
       * this the panel that has just finished its job stays over the page with
       * nothing left to do in it. The page behind shows the awaiting-approval
       * state; documents open only once an admin approves the payment.
       */
      closeManual()
    },
  })
}

function onEsc(e: KeyboardEvent) {
  if (e.key === 'Escape' && open.value) closeManual()
}

onMounted(() => document.addEventListener('keydown', onEsc))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onEsc)
  clearTimeout(copyTimer)
  document.body.style.removeProperty('overflow')
})

// The dialog owns the viewport while it is up.
watch(open, (isOpen) => {
  if (isOpen) document.body.style.setProperty('overflow', 'hidden')
  else document.body.style.removeProperty('overflow')
})
</script>

<template>
  <!-- espees-secure-payment-card 387:18311 -->
  <div class="flex flex-col gap-4 rounded-xl border border-[#0d9488] bg-white p-6">
    <div class="flex items-center gap-2.5">
      <img :src="iconShield" alt="" class="size-5 shrink-0" aria-hidden="true" />
      <p class="font-display text-base font-semibold text-[#0f172a]">{{ t('payment_methods.pay_securely_with_espees') }}</p>
    </div>
    <p class="text-xs text-[#475569]">
      {{ t('payment_methods.espees_explainer', { amount }) }}
    </p>

    <p
      v-if="props.fees.paid"
      class="rounded-lg border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-xs text-[#15803d]"
      role="status"
    >
      {{ t('payment_methods.this_registration_fee_is_already_paid') }}
    </p>
    <p
      v-else-if="!props.fees.confirmed"
      class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-xs text-[#b45309]"
    >
      {{ props.defer
        ? t('payment_methods.price_your_fee_above_first')
        : t('payment_methods.confirm_your_registration_fee_above_before') }}
    </p>
    <!-- The gateway is admin-configured per country; when it is not, saying so
         beats a button that posts and silently redirects back. -->
    <p
      v-else-if="!props.fees.espees.available"
      class="rounded-lg border border-[#e2e8f0] bg-[#f8fafc] px-4 py-3 text-xs text-[#475569]"
    >
      {{ props.fees.espees.reason }}
    </p>
    <p
      v-else-if="props.fees.espees.testMode"
      class="rounded-lg border border-[#e0f2fe] bg-[#f0f9ff] px-4 py-3 text-xs text-[#0369a1]"
    >
      {{ t('payment_methods.espees_is_in_test_mode_no_real_funds_move') }}
    </p>

    <p
      v-if="feesError"
      class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-xs text-danger"
      role="alert"
    >
      {{ feesError }}
    </p>

    <!-- A real form, not a bare button: @submit.prevent runs the Inertia post,
         and the same element is what the fallback submits natively when that
         post fails. See payWithEspees. -->
    <form
      ref="espeesForm"
      method="POST"
      :action="espeesAction"
      class="w-fit"
      @submit.prevent="payWithEspees"
    >
      <input type="hidden" name="_token" :value="csrfToken" />
      <button
        type="submit"
        :disabled="!addressable || !espeesReady || working"
        class="inline-flex w-fit items-center gap-2 rounded-lg bg-[#fbbf24] px-5 py-3 text-sm font-semibold text-[#0f172a] transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
      >
        <img :src="iconCreditCard" alt="" class="size-4" aria-hidden="true" />
        {{ working ? t('payment_methods.opening_checkout') : t('payment.pay_with_espees', { amount }) }}
      </button>
    </form>
  </div>

  <!-- manual-payment-collapsed 387:18323 -->
  <button
    type="button"
    class="flex items-center justify-between gap-3 rounded-xl border border-[#e2e8f0] bg-white p-5 text-left transition-colors hover:bg-surface-secondary"
    :aria-expanded="open"
    aria-controls="manual-payment-dialog"
    @click="openManual"
  >
    <span class="flex min-w-0 flex-1 items-center gap-3">
      <span class="shrink-0 rounded-md bg-[#f0fdf4] p-2" aria-hidden="true">
        <img :src="iconBuildingCollapsed" alt="" class="size-4" />
      </span>
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-[#0f172a]">{{ t('payment_methods.manual_payment') }}</span>
        <span class="block text-xs text-[#64748b]">
          {{ t('payment_methods.pay_in_app', { app: merchantApp }) }}
        </span>
      </span>
    </span>
    <img :src="iconChevronDown" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
  </button>

  <!-- ====================================== 387:18447 / 387:19269 =========
       Manual payment, drawn as a dialog over a scrim. -->
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/40 p-4"
      @click.self="closeManual"
    >
      <div
        id="manual-payment-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="manual-payment-title"
        class="my-auto flex w-full max-w-[628px] flex-col overflow-hidden rounded-2xl bg-[#f8fafc] shadow-[0px_4px_12px_rgba(0,0,0,0.03)]"
      >
        <!-- header 387:19270 -->
        <div
          class="flex items-center justify-between gap-4 border-b border-[#e2e8f0] bg-[#f8fafc] px-5 sm:px-8 py-4"
        >
          <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold text-[#0f766e] uppercase">{{ t('payment_methods.registration_payment') }}</p>
            <p id="manual-payment-title" class="font-display text-xl font-semibold text-[#0f172a]">
              {{ t('payment_methods.complete_the_fee_step_to_continue_your_app') }}
            </p>
          </div>
          <span
            class="flex shrink-0 items-center gap-1.5 rounded-md bg-[#f0fdf4] px-2.5 py-1 text-xs font-semibold text-[#15803d]"
          >
            <img :src="iconSavedCheck" alt="" class="size-3.5" aria-hidden="true" />
            {{ t('payment_methods.saved') }}
          </span>
        </div>

        <!-- content-body 387:19279 -->
        <div class="flex flex-col gap-5 p-4 sm:p-8">
          <!-- manual-header-card 387:19280 -->
          <div class="overflow-hidden rounded-xl border border-[#15803d]">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 bg-[#f0fdf4] p-5 text-left"
              @click="closeManual"
            >
              <span class="flex min-w-0 flex-1 items-center gap-3">
                <span class="shrink-0 rounded-md border border-[#15803d] p-2" aria-hidden="true">
                  <img :src="iconBuilding16" alt="" class="size-4" />
                </span>
                <span class="min-w-0 text-[#15803d]">
                  <span class="block font-display text-base font-semibold">{{ t('payment_methods.manual_payment') }}</span>
                  <span class="block text-xs opacity-80">
                    {{ t('payment_methods.pay_in_app', { app: merchantApp }) }}
                  </span>
                </span>
              </span>
              <img :src="iconChevronUp" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
            </button>

            <!-- manual-details-content 387:19293 -->
            <div class="flex flex-col gap-5 bg-white p-6">
              <div class="flex items-center gap-3">
                <span class="shrink-0 rounded-lg bg-[#f0fdf4] p-2.5" aria-hidden="true">
                  <img :src="iconBuilding20" alt="" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-2xs font-semibold text-[#15803d] uppercase">{{ t('payment_methods.registration_payment') }}</p>
                  <p class="font-display text-lg font-semibold text-[#0f172a]">{{ t('payment_methods.how_to_pay') }}</p>
                </div>
              </div>

              <p class="text-sm text-[#475569]">
                {{ t('payment_methods.transfer') }} <strong class="font-semibold text-[#0f172a]">{{ amount }}</strong> {{ t('payment_methods.using_the') }}
                <span class="font-semibold text-[#0d9488]">{{ t('payment_methods.named_app', { app: merchantApp }) }}</span>.
              </p>

              <p
                v-if="props.fees.proofSubmitted"
                class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-xs text-[#b45309]"
                role="status"
              >
                {{ t('payment_methods.a_proof_is_already_with_our_reviewers_uplo') }}
              </p>
              <p
                v-else-if="!props.fees.confirmed"
                class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-xs text-[#b45309]"
              >
                {{ props.defer
                  ? t('payment_methods.price_your_fee_above_first')
                  : t('payment_methods.confirm_your_registration_fee_before_uploa') }}
              </p>

              <!-- merchant-code-box 387:19303 -->
              <div class="flex flex-col gap-2">
                <p class="text-2xs font-semibold text-[#64748b] uppercase">{{ t('payment_methods.merchant_code') }}</p>
                <div class="flex w-fit overflow-hidden rounded-lg border border-[#e2e8f0]">
                  <span class="bg-[#f8fafc] px-4 py-3 font-display text-base font-semibold text-[#0f172a]">
                    {{ merchantCode }}
                  </span>
                  <button
                    type="button"
                    class="flex h-11 shrink-0 items-center gap-2 bg-[#007035] px-4 text-xs font-semibold text-white transition-opacity hover:opacity-90"
                    @click="copyCode"
                  >
                    <img :src="iconCopy" alt="" class="size-3.5" aria-hidden="true" />
                    {{ copied ? t('payment_methods.copied') : t('payment_methods.copy') }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- info-alert 387:19313 -->
          <div
            class="flex items-center gap-3 rounded-lg border border-[#e0f2fe] bg-[#f0f9ff] px-4 py-3"
          >
            <img :src="iconInfo" alt="" class="size-[18px] shrink-0" aria-hidden="true" />
            <p class="text-xs text-[#0369a1]">
              {{ t('payment_methods.facing_issues_with_your_payment_please_con') }}
              <strong class="font-semibold">{{ t('payment_methods.moc_itdept') }}</strong> {{ t('payment_methods.on_kingschat_for_assistance') }}
            </p>
          </div>

          <!-- proof-upload-form 387:19318 -->
          <form
            class="flex flex-col gap-5 rounded-xl border border-[#e2e8f0] bg-white p-6"
            @submit.prevent="submitProof"
          >
            <div class="flex flex-col gap-2">
              <label for="payment-reference" class="text-sm font-semibold text-[#0f172a]">
                {{ t('payment_methods.payment_reference') }} <span class="font-normal text-[#64748b]">{{ t('payment_methods.optional') }}</span>
              </label>
              <input
                id="payment-reference"
                type="text"
                :placeholder="t('payment_methods.transaction_id_reference')"
                class="rounded-lg border border-[#e2e8f0] bg-white px-4 py-3 text-sm text-[#0f172a] placeholder:text-[#64748b] focus:border-focus focus:outline-none"
              />
              <p class="text-xs text-[#64748b]">
                {{ t('payment_methods.for_your_own_records_the_reviewer_reads_th') }}
              </p>
            </div>

            <div class="flex flex-col gap-2">
              <p class="text-sm font-semibold text-[#0f172a]">
                {{ t('payment_methods.proof_of_payment') }}
                <span class="font-normal text-[#64748b]">{{ t('payment_methods.pdf_jpg_or_png_max_10_mb') }}</span>
              </p>
              <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-1.5"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <button
                    type="button"
                    class="shrink-0 rounded-md border border-[#e2e8f0] bg-white px-3.5 py-2 text-xs font-semibold text-[#475569] hover:bg-surface-secondary"
                    @click="fileInput?.click()"
                  >
                    {{ t('payment_methods.choose_file') }}
                  </button>
                  <span class="min-w-0 truncate text-xs text-[#64748b]">
                    {{ fileName || t('payment_methods.no_file_chosen') }}
                  </span>
                  <input
                    ref="fileInput"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    class="sr-only"
                    @change="onFile"
                  />
                </div>
                <button
                  type="submit"
                  :disabled="!addressable || !ready || !proof.proof || proof.processing || working"
                  class="shrink-0 rounded-md bg-[#007035] px-4 py-2 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {{ proof.processing || working ? t('documents.uploading') : t('payment_methods.submit_proof') }}
                </button>
              </div>
              <p v-if="proof.errors.proof" class="text-xs text-danger">{{ proof.errors.proof }}</p>
            </div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
</template>
