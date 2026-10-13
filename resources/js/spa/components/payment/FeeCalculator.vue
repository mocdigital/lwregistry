<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'

// Fees are priced in Espees, so the calculator wears the Espees coin, not a $.
import espeesCoin from '@/assets/figma/application/espees-coin.webp'
import chevronDown from '@/assets/figma/application/chevron-down.svg'
import rateCheck from '@/assets/figma/application/rate-check.svg'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * registration-fee-payment-card 33:111 — the calculator half of the fees step.
 *
 * The card drew a single "Share Structure" select with three invented options
 * and a hardcoded "50 Espees". The real schedule is admin-managed and
 * country-scoped: picking a share structure opens three more inputs (capital
 * type, fee band, per-share value) and the amount comes from the band.
 *
 * Nothing here computes money. The fee is recomputed server-side on confirm
 * (SboFeeController) and the amount shown before that is the band's own
 * advertised fee, which is why it is labelled as an estimate until confirmed.
 *
 * Extracted rather than written twice: /application/fees and
 * /sbo/complete-payment-required are the same step drawn twice (33:4 and
 * 384:17211) and share one payload.
 */

export type FeeBand = {
  id: number
  label: string
  fee: number
  maxEquity: number | null
  previousMaxEquity: number | null
}

export type FeeStructure = {
  key: string
  label: string
  isShare: boolean
  capitalTypes: { key: string; label: string }[]
  bands: FeeBand[]
}

export type FeePayload = {
  sboId: number | null
  sboName?: string
  currency?: string
  /** Every structure carries its own bands, so changing one needs no round trip. */
  structures: FeeStructure[]
  selection?: {
    shareStructure: string | null
    shareCapitalType: string | null
    perShareValue: number | string | null
    issuedShares: number | null
  }
  flatFee?: number
  calculatedFee?: number | null
  feeBand?: string | null
  confirmed: boolean
  paid?: boolean
  proofSubmitted?: boolean
  espees: { available: boolean; testMode: boolean; testAccount: boolean; reason: string | null }
  /**
   * Who to pay manually, and in which app. From the Fees admin page — this was
   * a hardcoded 'MOCDII1' in PaymentMethods.vue, which contradicted whatever an
   * administrator had actually configured.
   */
  merchant?: { name: string; code: string; app: string }
}

/*
 * `preview` renders the calculator on the business-registration form, before a
 * business record exists: the applicant sees their fee as they pick country and
 * structure, but there is nothing to confirm yet — the fee is confirmed and
 * paid on the payment step once the business is created.
 */
const props = defineProps<{ fees: FeePayload; preview?: boolean }>()

/*
 * In preview mode the selection belongs to the page around it (the
 * add-business form submits it), so every change is emitted upward instead of
 * being posted from here.
 */
const emit = defineEmits<{
  (e: 'selection', value: {
    share_structure: string
    share_capital_type: string
    fee_band_id: number | null
    per_share_value: string | number
    /** The band's own advertised fee, for the payment card beside this one. */
    amount: number | null
    /** Whether the selection is complete enough to be paid for. */
    ready: boolean
  }): void
}>()

const page = usePage()
const success = computed(() => page.props.flash.feesSuccess)
const error = computed(() => page.props.flash.feesError)

const form = useForm({
  share_structure: props.fees.selection?.shareStructure ?? '',
  share_capital_type: props.fees.selection?.shareCapitalType ?? '',
  fee_band_id: null as number | null,
  per_share_value: (props.fees.selection?.perShareValue ?? '') as string | number,
})

const structure = computed(
  () => props.fees.structures.find((s) => s.key === form.share_structure) ?? null,
)

const isShare = computed(() => structure.value?.isShare ?? false)
const capitalTypes = computed(() => structure.value?.capitalTypes ?? [])
const bands = computed(() => structure.value?.bands ?? [])

const selectedBand = computed(() => bands.value.find((b) => b.id === form.fee_band_id) ?? null)

/*
 * A band is a range of *equity* (issued shares x per-share value), so a
 * per-share value can be too large to fit inside the band the owner picked.
 * The server rejects that (SboFeeCalculator::issuedSharesForBand returns
 * null); saying so here saves a round trip to find out.
 */
const perShareOverflows = computed(() => {
  const band = selectedBand.value
  const value = Number(form.per_share_value)

  if (!band || !Number.isFinite(value) || value <= 0 || band.maxEquity === null) return false

  const minShares = band.previousMaxEquity === null
    ? 1
    : Math.floor(band.previousMaxEquity / value) + 1

  return minShares * value > band.maxEquity
})

/** What the owner will be charged, before the server recomputes it. */
const estimate = computed(() => {
  if (!form.share_structure) return null
  return isShare.value ? (selectedBand.value?.fee ?? null) : (props.fees.flatFee ?? null)
})

const currency = computed(() => props.fees.currency ?? 'Espees')

const canConfirm = computed(() => {
  if (!form.share_structure || form.processing) return false
  if (!isShare.value) return true
  return (
    form.share_capital_type !== '' &&
    form.fee_band_id !== null &&
    Number(form.per_share_value) > 0 &&
    !perShareOverflows.value
  )
})

function onStructureChange() {
  // A band from the previous structure is not a valid band for this one.
  form.share_capital_type = ''
  form.fee_band_id = null
}

// Preview mode: hand the selection to the page so it can submit it.
watch(
  () => [form.share_structure, form.share_capital_type, form.fee_band_id, form.per_share_value],
  () => {
    if (!props.preview) return

    emit('selection', {
      share_structure: form.share_structure,
      share_capital_type: form.share_capital_type,
      fee_band_id: form.fee_band_id,
      per_share_value: form.per_share_value,
      amount: estimate.value,
      ready: canConfirm.value,
    })
  },
)

function confirm() {
  if (!canConfirm.value || !props.fees.sboId) return
  form.post(`/sbo/applications/${props.fees.sboId}/fees`, { preserveScroll: true })
}

const reset = useForm({})

function edit() {
  if (!props.fees.sboId) return
  reset.delete(`/sbo/applications/${props.fees.sboId}/fees`, { preserveScroll: true })
}
</script>

<template>
  <!--
    Self-contained card. No `flex-1`: this is dropped into a flex COLUMN on
    /application/fees and into a fixed 380px grid track on
    /sbo/complete-payment-required, and in a column `flex-1` grows the main
    axis — height — stretching the card to fill whatever sits beside it.

    Padding steps down at narrow widths: that 380px track leaves a p-9 card
    only ~300px of content, which the band labels overflow.
  -->
  <section
    class="flex min-w-0 flex-col gap-7 rounded-2xl border border-[#e2e8f0] bg-white p-6 drop-shadow-[0px_4px_8px_rgba(0,0,0,0.02)] sm:p-8"
  >
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <span class="grid shrink-0 place-items-center" aria-hidden="true">
          <img :src="espeesCoin" alt="" class="size-10" />
        </span>
        <div>
          <p class="font-display text-lg font-semibold text-[#1e293b]">{{ t('fee_calculator.registration_fee_calculator') }}</p>
          <p class="text-xs text-[#64748b]">
            {{ t('fee_calculator.select_company_share_structure_to_compute') }}
          </p>
        </div>
      </div>
      <span
        class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="
          props.fees.paid
            ? 'bg-[#d1fae5] text-[#007035]'
            : props.fees.confirmed
              ? 'bg-[#ccfbf1] text-[#0d9488]'
              : 'bg-[#fef3c7] text-[#b45309]'
        "
      >
        {{ props.fees.paid ? t('data.status.paid') : props.fees.confirmed ? t('fee_calculator.fee_confirmed') : t('dashboard.action_required') }}
      </span>
    </div>

    <hr class="border-[#e2e8f0]" />

    <p
      v-if="success"
      class="rounded-lg border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
      role="status"
    >
      {{ success }}
    </p>
    <p
      v-if="error"
      class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-sm text-danger"
      role="alert"
    >
      {{ error }}
    </p>

    <!-- Confirmed: the calculator collapses to what was agreed. -->
    <template v-if="props.fees.confirmed">
      <div
        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-[#0d9488] bg-[#f8fafc] p-5"
      >
        <div>
          <p class="text-xs text-[#64748b] uppercase">{{ t('fee_calculator.confirmed_fee') }}</p>
          <p class="font-serif text-3xl font-bold break-words text-[#0b192c]">
            <template v-if="props.fees.espees.testAccount">{{ t('fee_calculator.1_espee_test_account') }}</template>
            <template v-else>
              {{ Number(props.fees.calculatedFee ?? 0).toLocaleString() }} {{ currency }}
            </template>
          </p>
          <p v-if="props.fees.feeBand" class="text-xs text-[#64748b]">
            {{ t('fee.band', { band: props.fees.feeBand }) }}
          </p>
        </div>
        <p class="flex items-center gap-1.5 text-xs font-semibold text-[#0d9488]">
          <img :src="rateCheck" alt="" class="size-[18px]" aria-hidden="true" />
          {{ t('fee_calculator.official_rate_applied') }}
        </p>
      </div>

      <button
        v-if="!props.fees.paid && !props.fees.espees.testAccount"
        type="button"
        :disabled="reset.processing"
        class="w-fit text-xs font-semibold text-[#0d9488] underline disabled:opacity-60"
        @click="edit"
      >
        {{ t('fee_calculator.edit_fee_selection') }}
      </button>
    </template>

    <!-- Not confirmed: the calculator itself. -->
    <template v-else>
      <div class="flex flex-col gap-2">
        <label for="share-structure" class="text-sm font-semibold text-[#1e293b]">
          {{ t('fee_calculator.share_structure') }}
        </label>
        <div class="relative">
          <select
            id="share-structure"
            v-model="form.share_structure"
            class="w-full appearance-none rounded-[10px] border border-[#e2e8f0] bg-[#f8fafc] p-4 pr-12 text-base text-[#1e293b] focus:border-focus focus:outline-none"
            @change="onStructureChange"
          >
            <option value="">{{ t('fee_calculator.select_a_share_structure') }}</option>
            <option v-for="s in props.fees.structures" :key="s.key" :value="s.key">
              {{ s.label }}
            </option>
          </select>
          <img
            :src="chevronDown"
            alt=""
            class="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2"
            aria-hidden="true"
          />
        </div>
        <span v-if="form.errors.share_structure" class="text-xs text-danger">
          {{ form.errors.share_structure }}
        </span>
      </div>

      <!-- Share structures ask for three more inputs; flat-fee ones do not. -->
      <template v-if="isShare">
        <template v-if="bands.length">
          <div class="flex flex-col gap-2">
            <label for="capital-type" class="text-sm font-semibold text-[#1e293b]">
              {{ t('fee_calculator.share_capital_type') }}
            </label>
            <select
              id="capital-type"
              v-model="form.share_capital_type"
              class="w-full appearance-none rounded-[10px] border border-[#e2e8f0] bg-[#f8fafc] p-4 text-base text-[#1e293b] focus:border-focus focus:outline-none"
            >
              <option value="">{{ t('fee_calculator.select_a_capital_type') }}</option>
              <option v-for="c in capitalTypes" :key="c.key" :value="c.key">
                {{ c.label }}
              </option>
            </select>
            <span v-if="form.errors.share_capital_type" class="text-xs text-danger">
              {{ form.errors.share_capital_type }}
            </span>
          </div>

          <div class="flex flex-col gap-2">
            <label for="fee-band" class="text-sm font-semibold text-[#1e293b]">{{ t('fee_calculator.equity_band') }}</label>
            <select
              id="fee-band"
              v-model="form.fee_band_id"
              class="w-full appearance-none rounded-[10px] border border-[#e2e8f0] bg-[#f8fafc] p-4 text-base text-[#1e293b] focus:border-focus focus:outline-none"
            >
              <option :value="null">{{ t('fee_calculator.select_an_equity_band') }}</option>
              <option v-for="b in bands" :key="b.id" :value="b.id">
                {{ b.label }}
              </option>
            </select>
            <span v-if="form.errors.fee_band_id" class="text-xs text-danger">
              {{ form.errors.fee_band_id }}
            </span>
          </div>

          <div class="flex flex-col gap-2">
            <label for="per-share" class="text-sm font-semibold text-[#1e293b]">
              {{ t('fee.per_share_value', { currency }) }}
            </label>
            <input
              id="per-share"
              v-model="form.per_share_value"
              type="number"
              min="0.01"
              step="0.01"
              placeholder="e.g. 1.00"
              class="w-full rounded-[10px] border border-[#e2e8f0] bg-[#f8fafc] p-4 text-base text-[#1e293b] placeholder:text-[#94a3b8] focus:border-focus focus:outline-none"
            />
            <span v-if="form.errors.per_share_value" class="text-xs text-danger">
              {{ form.errors.per_share_value }}
            </span>
            <span v-else-if="perShareOverflows" class="text-xs text-danger">
              {{ t('fee_calculator.this_per_share_value_is_too_large_for_the') }}
            </span>
          </div>
        </template>

        <p v-else class="text-xs text-[#64748b]">
          {{ t('fee_calculator.no_fee_bands_are_configured_for_this_struc') }}
        </p>
      </template>

      <!-- calculation-box 33:129 -->
      <div
        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5"
        :class="estimate === null ? 'border-[#e2e8f0] bg-[#f8fafc]' : 'border-[#0d9488] bg-[#f8fafc]'"
      >
        <div>
          <p class="text-xs text-[#64748b] uppercase">{{ t('fee_calculator.calculated_fee') }}</p>
          <p class="font-serif text-3xl font-bold break-words text-[#0b192c]">
            <template v-if="estimate === null">—</template>
            <template v-else>{{ estimate.toLocaleString() }} {{ currency }}</template>
          </p>
        </div>
        <p
          v-if="estimate !== null"
          class="flex items-center gap-1.5 text-xs font-semibold text-[#0d9488]"
        >
          <img :src="rateCheck" alt="" class="size-[18px]" aria-hidden="true" />
          {{ t('fee_calculator.official_rate_applied') }}
        </p>
      </div>

      <p class="text-xs text-[#94a3b8]">
        {{ t('fee_calculator.the_amount_is_recalculated_on_the_server_w') }}
      </p>

      <!--
        On the registration form this is a preview: there is no business to
        confirm the fee against yet, so the confirm button is replaced by a note
        that payment comes after submitting.
      -->
      <p v-if="preview" class="text-xs font-medium text-[#64748b]">
        {{ t('fee_calculator.you_pay_this_after_submitting') }}
      </p>
      <button
        v-else
        type="button"
        :disabled="!canConfirm"
        class="btn-primary w-full rounded-[10px] px-6 py-3.5 text-base font-semibold disabled:opacity-50"
        @click="confirm"
      >
        {{ form.processing ? t('fee_calculator.confirming') : t('fee_calculator.confirm_fee') }}
      </button>
    </template>
  </section>
</template>
