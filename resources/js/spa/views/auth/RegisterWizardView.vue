<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, useId, watch } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import { useRoute, useRouter } from '@/composables/useRoute'
import AuthBrandPanel from '@/components/shell/AuthBrandPanel.vue'
import UiCountryFlags from '@/components/ui/UiCountryFlags.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import stepCheck from '@/assets/figma/icons/step-check.svg'
import UiPasswordField from '@/components/ui/UiPasswordField.vue'
import FeeCalculator, { type FeePayload } from '@/components/payment/FeeCalculator.vue'
import PaymentMethods from '@/components/payment/PaymentMethods.vue'
import { useI18n } from '@/composables/useI18n'
import { useAppUrl } from '@/composables/useAppUrl'

const { t } = useI18n()
const appUrl = useAppUrl()

/*
 * Registration wizard.
 *
 * THE DESKTOP AND MOBILE DESIGNS DISAGREE ABOUT THIS FLOW. Recorded here
 * because the code cannot satisfy both, and picking one silently would bury
 * the conflict.
 *
 * Desktop (page "Page 1") — a 3-node stepper:
 *   registration 1  492:47443  Owner Info  (Contact & Representative, and the
 *                              only frame carrying church zone / assembly /
 *                              pastor fields)
 *   registration 2  492:47590  Account     (password pair + Code of Conduct)
 *   Business Info has NO desktop frame. A stale "STEP 2 OF 5" label sits on
 *   registration 1 — inherited from the mobile flow below.
 *
 * Mobile (page "mobile") — a genuinely different 5-step flow:
 *   wizard-step-1-mobile  422:38615  Business Identity (entity name, reg
 *                                    number, vetting doc upload)
 *   wizard-step-2-mobile  422:34140  Contact & Representative (stakeholder,
 *                                    email, job title, phone, alternate)
 *   wizard-step-3-mobile  422:34229  Location & Operations (supply chain role,
 *                                    address, city, state, postal, country)
 *   wizard-step-4-mobile  422:34432  Document Verification (CAC/CoI, TIN,
 *                                    proof of address)
 *   wizard-step-5-mobile  422:34319  Review & Submit
 *   It has no church-zone step and no password step.
 *
 * The other three frames named wizard-step-1-mobile (422:35047, 422:35101,
 * 422:35154) are password enter/confirm/reset screens, not wizard steps.
 *
 * What the backend actually needs is a third shape again: POST /register
 * (RegisteredUserController) wants business identity, owner identity, church
 * zone attribution and a password. See INTEGRATION.md.
 *
 * Until that is settled this screen keeps the 3-step desktop stepper, with
 * step 1's fields provisional and flagged in the UI.
 *
 * Stepper spec, from 492:47487 (row 560x28, nodes 28px):
 *   complete  circle #14b8a6 + check icon,  label #475569
 *   current   circle #0d9488 + number white, label #0d9488
 *   upcoming  circle #ffffff + number #475569, label #475569
 *   16px connector line between nodes
 */

const props = withDefaults(defineProps<{
  categories: string[]
  countries: string[]
  zones: string[]
  /** Ministries an SBO can be affiliated with — shown only when entity is SBO. */
  ministries?: string[]
  legalStructures: string[]
  /** Dial code per country name, so the phone prefix is not fixed to +234. */
  dialCodes?: Record<string, string>
  /** ISO code per country name — the flag beside the prefix. */
  countryIsoCodes?: Record<string, string>
  /** The reverse, so typing a dial code picks the country back out. */
  countriesByDialCode?: Record<string, string>
  /**
   * 'register' creates the account and its first SBO. 'listing' adds another
   * SBO to an account that already exists, so it collects business details
   * only — no owner identity, no password, no Code of Conduct, all of which
   * were agreed when the account was created.
   */
  mode?: 'register' | 'listing'
  /*
   * The payment step of adding a business: once the business is created the
   * form is submitted, the record exists, and the same page shows the fee
   * confirmation and the payment methods for it — no jump to a separate screen.
   */
  payStep?: boolean
  sbo?: { id: number; name: string; entityType: string }
  payFees?: FeePayload
}>(), { mode: 'register' })

const isListing = computed(() => props.mode === 'listing')

/*
 * Phone prefix. It was a hardcoded "+234" beside a Nigerian flag, on a registry
 * that lists businesses in every country the `countries` table holds — so an
 * owner in Accra was shown a Nigerian dial code and their number was then
 * rejected. It follows whichever country is selected on the same step.
 */
function dialCodeFor(country: string): string {
  return props.dialCodes?.[country] ?? ''
}

/** Which country select a given phone field takes its prefix from. */
function countryFieldFor(field: string): 'bizCountry' | 'ownerCountry' {
  return field === 'bizPhone' ? 'bizCountry' : 'ownerCountry'
}

/*
 * The dial code is editable, and the two directions stay in step.
 *
 * Picking a country sets the code and the flag. Typing a code selects the
 * country back — somebody who knows they are +233 should not have to hunt for
 * Ghana in a list of 250. Held per field rather than derived, because once it
 * is typeable it has its own state: a half-typed "+23" is not any country yet
 * and must not be overwritten mid-keystroke.
 */
const dialCodes = reactive<Record<string, string>>({})

function prefixFor(field: string): string {
  return dialCodes[field] ?? dialCodeFor(activeForm.value[countryFieldFor(field)] as string)
}

/** `+233` -> `GH`, for the flag. Empty while the code is incomplete. */
function isoForPrefix(field: string): string[] {
  const country = props.countriesByDialCode?.[prefixFor(field)]
  const iso = country ? props.countryIsoCodes?.[country] : undefined

  return iso ? [iso] : []
}

function onDialCodeChange(field: string, code: string) {
  dialCodes[field] = code

  const country = props.countriesByDialCode?.[code]

  if (country) {
    ;(activeForm.value as any)[countryFieldFor(field)] = country
  }

  // The dial code is part of what makes the number valid, so re-check it.
  revalidateIfTouched(field)
}

/*
 * Every code, numerically rather than alphabetically: +1, +7, +20 … is the
 * order a dialling code list is read in, and sorting "+1" next to "+10" as
 * text puts Nigeria between Egypt and Greece.
 */
/* ------------------------------------------------------------- logo upload ---
 *
 * Held on the form as a File; Inertia switches the request to multipart on its
 * own once one is present, so nothing else about the submit changes.
 *
 * The preview is an object URL rather than a data URL — a 5MB base64 string in
 * component state is a needless copy of a file we already have a handle to —
 * and it is revoked when it is replaced.
 */
const logoPreview = ref<string | null>(null)
const logoName = ref<string | null>(null)
const logoId = useId()
const logoKey = ref(0)
const logoDragging = ref(false)

function setLogo(file: File | null) {
  if (logoPreview.value) URL.revokeObjectURL(logoPreview.value)

  ;(activeForm.value as any).bizLogo = file
  logoName.value = file?.name ?? null
  logoPreview.value = file && file.type !== 'image/svg+xml' ? URL.createObjectURL(file) : null
}

function onLogoDrop(event: DragEvent) {
  logoDragging.value = false
  setLogo(event.dataTransfer?.files?.[0] ?? null)
}

function clearLogo() {
  setLogo(null)
  // Recreate the input: a file input's value cannot be cleared from a binding,
  // and without this, re-picking the same file fires no change event.
  logoKey.value += 1
}

onBeforeUnmount(() => {
  if (logoPreview.value) URL.revokeObjectURL(logoPreview.value)
})

const dialCodeOptions = computed(() =>
  Object.keys(props.countriesByDialCode ?? {}).sort(
    (a, b) => Number(a.slice(1)) - Number(b.slice(1)),
  ),
)

/*
 * States for the country that is selected.
 *
 * Fetched rather than shipped with the page: `states` holds 5,250 rows across
 * 250 countries, and sending all of them so one can be used would put ~130KB
 * on a form nobody has started yet.
 *
 * A country with no subdivisions in the dataset — a city-state, a small
 * territory — falls back to the free-text box rather than showing an empty
 * dropdown, which is worse than no dropdown.
 */
const statesFor = reactive<Record<string, string[]>>({})
const statesLoading = ref(false)

async function loadStates(country: string) {
  if (!country || statesFor[country]) return

  statesLoading.value = true

  try {
    const response = await fetch(`/states?country=${encodeURIComponent(country)}`, {
      headers: { Accept: 'application/json' },
    })

    statesFor[country] = response.ok ? ((await response.json()).states ?? []) : []
  } catch {
    // Offline or refused: the free-text box is the fallback, so an empty list
    // is the right answer rather than a blocked form.
    statesFor[country] = []
  } finally {
    statesLoading.value = false
  }
}

/*
 * The Registration Fee Calculator shown on this form. The fee is country-scoped
 * and no business exists yet, so it is priced from a preview endpoint as soon as
 * a country is chosen. The applicant's selection is submitted with the form, so
 * the fee is already confirmed when the payment methods appear below.
 */
const emptyFeePayload = (): FeePayload => ({
  sboId: null,
  structures: [],
  confirmed: false,
  espees: { available: false, testMode: false, testAccount: false, reason: null },
})
const feePayload = ref<FeePayload>(emptyFeePayload())

async function loadFeePreview(country: string) {
  if (!country) {
    feePayload.value = emptyFeePayload()
    return
  }

  try {
    const response = await fetch(`/fees/preview?country=${encodeURIComponent(country)}`, {
      headers: { Accept: 'application/json' },
    })

    feePayload.value = response.ok ? await response.json() : emptyFeePayload()
  } catch {
    feePayload.value = emptyFeePayload()
  }
}

/**
 * What the calculator has priced so far: the amount, and whether the selection
 * is complete. The payment card below it is live as soon as this is `ready`.
 */
const feeChoice = reactive({ amount: null as number | null, ready: false })

/** Bumped to remount the calculator, so a country change starts it clean. */
const feeCalculatorKey = ref(0)

function resetFeeChoice() {
  feeChoice.amount = null
  feeChoice.ready = false
  listingForm.bizShareStructure = ''
  listingForm.bizShareCapitalType = ''
  listingForm.bizFeeBandId = null
  listingForm.bizPerShareValue = ''
  feeCalculatorKey.value += 1
}

/** The calculator hands its selection up; the form carries it to the server. */
function onFeeSelection(sel: {
  share_structure: string
  share_capital_type: string
  fee_band_id: number | null
  per_share_value: string | number
  amount: number | null
  ready: boolean
}) {
  listingForm.bizShareStructure = sel.share_structure
  listingForm.bizShareCapitalType = sel.share_capital_type
  listingForm.bizFeeBandId = sel.fee_band_id
  listingForm.bizPerShareValue = sel.per_share_value
  feeChoice.amount = sel.amount
  feeChoice.ready = sel.ready
}

/*
 * The fee payload the inline payment card reads. It is the country preview with
 * the applicant's own selection folded in: `confirmed` here means "priced and
 * complete", which is exactly what the payment buttons need to know. The
 * calculator itself keeps reading the raw preview, so it stays editable.
 */
const inlineFees = computed<FeePayload>(() => ({
  ...feePayload.value,
  confirmed: feeChoice.ready,
  calculatedFee: feeChoice.amount,
  paid: false,
  proofSubmitted: false,
}))

/*
 * Option sources for the selects, which previously rendered nothing but their
 * placeholder. Keyed by field so the markup stays a single loop; the server
 * validates against these same lists, so a dropdown cannot offer a value the
 * rules would reject.
 */
const optionsFor = computed<Record<string, string[]>>(() => ({
  // Keyed on the field's own label in English, which is what the field
  // definitions below carry — not on the translated label, which changes.
  'Legal Structure': props.legalStructures,
  // Keyed on the field's rendered label (register_wizard.ministry), not the
  // word "Ministry" — the generic select looks options up by f.label.
  'Select Ministry': props.ministries ?? [],
  'Primary Category': props.categories,
  Country: props.countries,
  'Church Zone': props.zones,
}))

/*
 * Form state. Only the account step is wired: steps 1 and 2 are still the
 * label-only arrays below, because step 1 has no Figma frame and its
 * provisional fields do not match what POST /register requires.
 */
/*
 * The business step. Shared verbatim by both modes because it is the same
 * question either way — registering an account with its first SBO, or adding
 * another SBO to an account that exists.
 */
const businessDefaults = {
  bizName: '', bizEntityType: '', bizMinistry: '', bizTradingName: '', bizRegistrationNumber: '', bizLegalStructure: '',
  bizNature: '', bizCountry: '', bizState: '', bizAddress: '', bizEmail: '',
  bizPhone: '', bizWebsite: '', bizYearEstablished: '', bizEmployees: '',
  bizPremiseType: '', bizDescription: '',
  // The Registration Fee Calculator's selection, submitted with the business.
  bizShareStructure: '', bizShareCapitalType: '',
  bizFeeBandId: null as number | null, bizPerShareValue: '' as string | number,
  // Null until a file is chosen; Inertia sends multipart once it is not.
  bizLogo: null as File | null,
}

/*
 * `payWith` is the button that was pressed. Nothing here is saved until one of
 * them is: the business record and its payment are created by the same request,
 * so abandoning the form leaves nothing behind, and abandoning an Espees
 * checkout leaves a draft to come back and pay for.
 */
const listingForm = useForm({
  ...businessDefaults,
  agreeCode: false,
  payWith: '' as '' | 'espees' | 'manual',
  proof: null as File | null,
})

const activeForm = computed<any>(() => (isListing.value ? listingForm : form))

function submitListing() {
  listingForm.post('/sbo/listings')
}

const form = useForm({
  /*
   * Step 1. These were missing entirely: the business fields were drawn as
   * labels with no `name`, so nothing typed on the first step ever reached the
   * form — every completed registration failed validation on eight business
   * fields the user had actually filled in, plus two the wizard never asked
   * for. Public sign-up did not work at all.
   */
  ...businessDefaults,

  // Step 2 (492:47443) — names match POST /register exactly.
  ownerSurname: '',
  ownerFirstName: '',
  ownerCountry: '',
  ownerKingschat: '',
  ownerResidentialAddress: '',
  ownerPhone: '',
  ownerEmail: '',
  ownerChurchZone: '',
  ownerLocalAssembly: '',
  ownerYearsAsMember: '',
  ownerZonalPastor: '',
  ownerLocalPastor: '',
  password: '',
  passwordConfirmation: '',
  agreeTerms: false,
  agreeCode: false,
})

/*
 * Strength meter for 492:47666. Scored against the same rules the server
 * enforces (Password::min(8)->letters()->mixedCase()->numbers()), so a full
 * bar means the password will actually be accepted rather than merely looking
 * strong.
 */
const strength = computed(() => {
  const v = form.password
  const score =
    (v.length >= 8 ? 1 : 0) +
    (/[a-z]/.test(v) && /[A-Z]/.test(v) ? 1 : 0) +
    (/\d/.test(v) ? 1 : 0) +
    (v.length >= 12 ? 1 : 0)
  return { score, label: ['', t('register_wizard.weak_password'), t('register_wizard.fair_password'), t('register_wizard.strong_password'), t('register_wizard.very_strong_password')][score] }
})

// The confirm field's meter tracks whether it matches, not its own entropy.
const confirmScore = computed(() =>
  form.passwordConfirmation && form.passwordConfirmation === form.password ? 4 : 0,
)

const route = useRoute()
const router = useRouter()

const TOTAL = 3
const step = computed(() => {
  const n = Number(route.params.step ?? 1)
  return n >= 1 && n <= TOTAL ? n : 1
})

const stepMeta = [
  { n: 1, label: t('register_wizard.business_info') },
  { n: 2, label: t('register_wizard.owner_info') },
  { n: 3, label: t('register_wizard.account') },
]

/** Named above the row on mobile, where the per-step labels are hidden. */
const currentStepLabel = computed(
  () => stepMeta.find((s) => s.n === step.value)?.label ?? '',
)

/*
 * `name` is the key on `activeForm`; step 1 has none because it has no desktop
 * frame yet and its fields are not reconciled with what POST /register wants.
 * Typed explicitly so the three arrays share one shape — otherwise TS infers
 * three different literal types and `f.name` is an error on the step-1 branch.
 */
type WizardField = {
  name: string
  label: string
  required: boolean
  placeholder: string
  /**
   * Desktop column span. Two short fields share a row; anything that needs the
   * width (addresses, descriptions) takes the full one. Single column below md.
   */
  half?: boolean
  type?: 'select' | 'textarea' | 'tel' | 'file'
  /** A non-input row: renders the inline Registration Fee Calculator instead. */
  calculator?: boolean
}

/*
 * Step 1 — Business Info.
 *
 * Every field is bound now. It previously had none: seven labels with no
 * `name`, so the step collected nothing and the five business fields the
 * endpoint requires but the step never drew (address, state, phone, email,
 * country) had nowhere to come from either.
 *
 * There is still no desktop frame for this step, so the pairing follows the two
 * the design brief named — Business Type + Registration Number, Primary
 * Category + Year Founded — and applies the same treatment to the rest.
 */
const businessFields: WizardField[] = [
  { name: 'bizName', label: t('register_wizard.registered_business_name'), required: true, placeholder: t('register_wizard.e_g_ditabol_homes_nigeria_limited') },
  { name: 'bizEntityType', label: t('register_wizard.business_or_entity_type'), required: true, placeholder: '', half: false },
  // Shown only when the entity type is SBO (see the label's v-show below).
  { name: 'bizMinistry', label: t('register_wizard.ministry'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  { name: 'bizLegalStructure', label: t('register_wizard.legal_structure'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  /*
   * Country sits high because the registration fee is priced from it: the
   * calculator directly below reads it, and it also chooses the phone prefix,
   * the flag, and the state list further down.
   */
  { name: 'bizCountry', label: t('register_wizard.country'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  // The Registration Fee Calculator, priced from the country above. Its
  // selection is submitted with the form, so the fee is confirmed on save.
  { name: '__feeCalculator', label: '', required: false, placeholder: '', calculator: true },
  { name: 'bizRegistrationNumber', label: t('register_wizard.registration_number'), required: true, placeholder: 'e.g. RC-482913', half: true },
  { name: 'bizNature', label: t('register_wizard.primary_category'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  { name: 'bizYearEstablished', label: t('register_wizard.year_founded'), required: true, placeholder: 'e.g. 2011', half: true },
  { name: 'bizTradingName', label: t('register_wizard.trading_name'), required: false, placeholder: '', half: true },
  { name: 'bizEmployees', label: t('register_wizard.number_of_employees'), required: false, placeholder: 'e.g. 12', half: true },
  { name: 'bizEmail', label: t('register_wizard.business_email_address'), required: true, placeholder: t('register_wizard.e_g_name_company_com'), half: true },
  { name: 'bizPhone', label: t('register_wizard.business_phone_number'), required: true, type: 'tel', placeholder: '8031234567', half: true },
  { name: 'bizState', label: t('register_wizard.state_region'), required: true, placeholder: t('register_wizard.select_a_country_first'), half: true },
  { name: 'bizAddress', label: t('register_wizard.business_address'), required: true, placeholder: t('register_wizard.street_address') },
  { name: 'bizWebsite', label: t('register_wizard.website'), required: false, placeholder: 'example.com', half: true },
  { name: 'bizPremiseType', label: t('register_wizard.premises'), required: false, placeholder: '', half: true },
  { name: 'bizDescription', label: t('register_wizard.business_description'), required: true, type: 'textarea', placeholder: t('register_wizard.what_does_your_business_do') },
  /*
   * Last, and optional. A logo is what tells one card from another in the
   * directory, so it is worth asking for at registration rather than only in
   * the profile editor most owners never open — but blocking an application on
   * a file somebody has to go and find would cost more registrations than it
   * gains logos.
   */
  { name: 'bizLogo', label: t('register_wizard.business_logo'), required: false, placeholder: '', type: 'file' },
]

/*
 * The two business/entity types, shown as an explicit pick (no default) so a
 * COB owner never sails past a pre-selected "SBO". Value is the code stored on
 * the record; the caption is the full name.
 */
const entityOptions = [
  { value: 'SBO', caption: t('register_wizard.sbo_full_name') },
  { value: 'COB', caption: t('register_wizard.cob_full_name') },
]

/*
 * Step 2 — designed, 492:47443, in the order the frame draws them.
 *
 * `ownerLocalAssembly` is marked required here because the server requires it
 * (RegisteredUserController::rules). It was optional in the UI and required on
 * submit, which reads as an unexplained rejection.
 */
const contactFields: WizardField[] = [
  { name: 'ownerSurname', label: t('register_wizard.surname'), required: true, placeholder: '', half: true },
  { name: 'ownerFirstName', label: t('register_wizard.firstname'), required: true, placeholder: '', half: true },
  { name: 'ownerCountry', label: t('register_wizard.country'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  { name: 'ownerKingschat', label: t('register_wizard.kingschat_handle'), required: false, placeholder: '', half: true },
  { name: 'ownerEmail', label: t('register_wizard.email_address'), required: true, placeholder: t('register_wizard.e_g_name_example_com'), half: true },
  { name: 'ownerPhone', label: t('register_wizard.contact_phone_number'), required: true, type: 'tel', placeholder: '08031234567', half: true },
  // ownerCountry is already above, beside the name.
  { name: 'ownerResidentialAddress', label: t('register_wizard.residential_address'), required: true, placeholder: '' },
  { name: 'ownerChurchZone', label: t('register_wizard.church_zone'), required: true, type: 'select', placeholder: t('register_wizard.select_an_option'), half: true },
  { name: 'ownerLocalAssembly', label: t('register_wizard.local_assembly'), required: true, placeholder: '', half: true },
  { name: 'ownerYearsAsMember', label: t('register_wizard.total_number_of_years_as_a_member'), required: false, placeholder: '', half: true },
  { name: 'ownerZonalPastor', label: t('register_wizard.name_of_zonal_pastor'), required: false, placeholder: '', half: true },
  { name: 'ownerLocalPastor', label: t('register_wizard.name_of_local_pastor'), required: false, placeholder: '' },
]

// The listing mode collects the business step and nothing else.
/*
 * Changing the country invalidates the state under it, and moves the dial code
 * with it. A Lagos state left selected under "Ghana" is worse than a blank.
 *
 * Declared HERE, below `activeForm` and the forms it returns, rather than
 * beside loadStates above. `immediate: true` runs the getter during setup, and
 * a `const` is in its temporal dead zone until its own line — reading
 * `activeForm` from further up threw before the component ever mounted, which
 * is why selecting a country did nothing at all.
 */
watch(
  () => [activeForm.value.bizCountry, activeForm.value.ownerCountry] as const,
  ([biz, owner], previous) => {
    const [wasBiz, wasOwner] = previous ?? ['', '']

    if (biz && biz !== wasBiz) {
      void loadStates(biz as string)
      void loadFeePreview(biz as string)

      // A band belongs to one country's schedule, so a fee priced for the old
      // country is not a fee here — clear it rather than let it be paid.
      if (wasBiz) resetFeeChoice()

      if (wasBiz) (activeForm.value as any).bizState = ''
      dialCodes.bizPhone = dialCodeFor(biz as string)
      // The prefix moved with the country; re-check the number. Guarded on
      // `previous` so it never runs on the immediate setup pass, before the
      // validation state below exists.
      if (previous) revalidateIfTouched('bizPhone')
    }

    if (owner && owner !== wasOwner) {
      dialCodes.ownerPhone = dialCodeFor(owner as string)
      if (previous) revalidateIfTouched('ownerPhone')
    }
  },
  { immediate: true },
)

// A COB carries no ministry, so clear any choice when switching away from SBO —
// the field is hidden then, and the server rejects a ministry on a COB anyway.
watch(
  () => (activeForm.value as any).bizEntityType,
  (entity) => {
    if (entity !== 'SBO') (activeForm.value as any).bizMinistry = ''
  },
)

const fields = computed(() =>
  isListing.value || step.value === 1 ? businessFields : contactFields,
)

/* ------------------------------------------------------------- validation ---
 *
 * Each field checks itself before the form is submitted: on blur, and again on
 * every keystroke once it has been touched — so an error appears when the user
 * leaves a field wrong and clears the moment they put it right, rather than
 * waiting for a round trip to the server. Advancing a step validates the whole
 * step at once. The rules mirror RegisteredUserController so the two never
 * disagree; the server stays the authority, this is only the earlier warning.
 */
const clientErrors = reactive<Record<string, string>>({})
const touched = reactive<Record<string, boolean>>({})

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const websiteRe = /^(https?:\/\/)?([\w-]+\.)+[\w-]{2,}(\/\S*)?$/i

/** bizMinistry is only asked for — and only required — while the entity is SBO. */
function ministryHidden(): boolean {
  return (activeForm.value as any).bizEntityType !== 'SBO'
}

/** The rule for one field, or null when it is satisfied. */
function fieldRuleError(f: WizardField, raw: unknown): string | null {
  const value = typeof raw === 'string' ? raw.trim() : raw
  const empty = value === '' || value == null || value === false

  const required = f.required && !(f.name === 'bizMinistry' && ministryHidden())
  if (required && empty) return t('register_wizard.validation.required')
  if (empty) return null // optional and blank — nothing else to check

  const s = String(value)
  switch (f.name) {
    case 'bizEmail':
    case 'ownerEmail':
      return emailRe.test(s) ? null : t('register_wizard.validation.email')
    case 'bizWebsite':
      return websiteRe.test(s) ? null : t('register_wizard.validation.website')
    case 'bizPhone':
    case 'ownerPhone': {
      // Validate the whole international number, not just the local part: the
      // dial code must be chosen, and the code + number together must be a
      // plausible E.164 number (up to 15 digits). The server (libphonenumber)
      // is the real check; this catches the obvious mistakes first.
      const code = prefixFor(f.name).replace(/\D/g, '')
      if (!code) return t('register_wizard.validation.phone_code')

      const local = s.replace(/\D/g, '').replace(/^0+/, '') // drop a national trunk 0
      const full = code + local

      return local.length >= 5 && full.length >= 8 && full.length <= 15
        ? null
        : t('register_wizard.validation.phone')
    }
    case 'bizYearEstablished': {
      const n = Number(s)
      return Number.isInteger(n) && n >= 1800 && n <= new Date().getFullYear()
        ? null
        : t('register_wizard.validation.year')
    }
    case 'bizEmployees':
    case 'ownerYearsAsMember': {
      const n = Number(s)
      return Number.isInteger(n) && n >= 0 ? null : t('register_wizard.validation.number')
    }
    default:
      return null
  }
}

function validateField(f: WizardField): boolean {
  const err = fieldRuleError(f, (activeForm.value as any)[f.name])
  if (err) clientErrors[f.name] = err
  else delete clientErrors[f.name]

  return !err
}

/** Input handler: set the value, then re-check the field once it has been touched. */
function setField(f: WizardField, value: string) {
  ;(activeForm.value as any)[f.name] = value
  if (touched[f.name]) validateField(f)
}

function blurField(f: WizardField) {
  touched[f.name] = true
  validateField(f)
}

/** Re-check a field by name if it has been touched — e.g. its phone dial code
 *  changed, which alters whether the number is valid. */
function revalidateIfTouched(name: string) {
  if (!touched[name]) return
  const f = businessFields.find((x) => x.name === name) ?? contactFields.find((x) => x.name === name)
  if (f) validateField(f)
}

// Switching to COB hides the ministry field; drop any error it was showing.
watch(ministryHidden, (hidden) => {
  if (hidden) delete clientErrors.bizMinistry
})

/** Shown under a field: the client rule first, else whatever the server said. */
function fieldError(name: string): string | undefined {
  return clientErrors[name] ?? (activeForm.value.errors as any)[name]
}

/** Validate every field on the step, surfacing all errors at once. */
function validateFields(list: WizardField[]): boolean {
  let ok = true

  for (const f of list) {
    if (f.calculator) continue
    if (f.name === 'bizMinistry' && ministryHidden()) continue
    touched[f.name] = true
    if (!validateField(f)) ok = false
  }

  return ok
}

/*
 * The account step's passwords. The two agreements are gated by the submit
 * button itself (see canSubmit), so they need no message here — this checks the
 * password meets the same rule the server enforces, and that the pair matches.
 */
function validateAccount(): boolean {
  const pw = String(form.password ?? '')
  const strong = pw.length >= 8 && /[a-z]/.test(pw) && /[A-Z]/.test(pw) && /[0-9]/.test(pw)
  const matches = form.passwordConfirmation === form.password

  if (strong) delete clientErrors.password
  else clientErrors.password = t('register_wizard.validation.password_rules')

  if (matches) delete clientErrors.passwordConfirmation
  else clientErrors.passwordConfirmation = t('register_wizard.validation.password_match')

  return strong && matches
}

// Once a password error is showing, re-check as the pair is edited so it clears
// the moment it is put right, rather than only on the next submit.
watch(
  () => [form.password, form.passwordConfirmation],
  () => {
    if (clientErrors.password || clientErrors.passwordConfirmation) validateAccount()
  },
)

/** Advance only when the current step is clean. */
function goNext() {
  /*
   * In listing mode the wrapper is a div holding the payment methods, whose own
   * <form> submit bubbles up to it. That is not a step advance, and following it
   * would walk the applicant into the registration wizard.
   */
  if (isListing.value) return

  if (validateFields(fields.value)) go(step.value + 1)
}

/** Listing mode has the one business step; check it before posting. */
// The business-setup submit is ready once the fields, the Code of Conduct read
// and its agreement are all satisfied.
const canSubmitListing = computed(() => conductRead.value && listingForm.agreeCode)

/*
 * A payment button on this same screen. It saves the business first — that is
 * the whole request — so the business fields and the Code of Conduct have to be
 * good before the payment can run, and a failure on either leaves nothing saved.
 */
function payForListing(choice: { method: 'espees' } | { method: 'manual'; proof: File }) {
  if (!validateFields(fields.value) || !canSubmitListing.value || listingForm.processing) return

  listingForm.payWith = choice.method
  listingForm.proof = 'manual' === choice.method ? choice.proof : null

  submitListing()
}

/*
 * Documents open on an APPROVED payment, not a submitted one — the same rule
 * the server enforces. Before the business is saved there is nothing to have
 * approved, so this is simply shut.
 */
const paymentApproved = computed(() => !!props.payFees?.paid)

/** Account step: check the passwords and agreements before the real submit. */
function submitChecked() {
  if (validateAccount()) submit()
}

/*
 * Code of Conduct gate: Submit Application stays inactive until the reader has
 * actually scrolled the Code to the bottom AND ticked the agreement. The panel
 * is also expandable so the full text can be read without the inner scroller.
 */
const conductRead = ref(false)
const conductExpanded = ref(false)

function onConductScroll(e: Event) {
  const el = e.target as HTMLElement
  // 8px tolerance for sub-pixel scroll heights
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 8) conductRead.value = true
}

function expandConduct() {
  conductExpanded.value = true
  // Expanding reveals the whole text, so it counts as read.
  conductRead.value = true
}

// Both agreements are required by POST /register, not just the Code.
const canSubmit = computed(() => conductRead.value && form.agreeCode && form.agreeTerms)

const conductText = `A Code of Conduct for the Loveworld Kingdom Businesses

THE PREAMBLE

We are a kingdom with a definite message for the world, and that message must be visible in the marketplace as much as in the assembly. The gold, silver and precious stones of a believer's work are tested by fire, and the world is watching what we build.

As a registered Loveworld Kingdom Business Owner, you therefore agree to transact within this network as an ambassador of the Kingdom — in a manner consistent with Kingdom values, with professional integrity that will not shame the Name we bear, and in full submission to the laws of the territory in which you operate. We render unto God what is God's, and unto Caesar what is Caesar's.

This Code is binding upon every verified listing on the Registry.

1. Integrity in Trade — Let Your Yea Be Yea
You shall represent your products, services, pricing and capacity as they truly are. You shall not inflate your certifications, stretch a delivery timeline, or magnify the scale of your operations to win favour within the network. In this Registry, we do not sell a promise we cannot perform; we deal in truth, because truth is our commission.

2. Fair Dealing — Render to All Their Due
You shall honour every agreed term with partners, suppliers and buyers introduced through the Registry, and fulfil your obligations without excuse. Where differences arise, you shall first bring the matter to the Registry's resolution process, that disputes may be settled in wisdom and not in the sight of men — before any escalation outside the network.

3. Confidentiality — A Faithful Spirit Concealeth the Matter
Commercial information shared through bids, RFPs and private network channels is held in trust. It is confidential; it shall not be redistributed, published, or used to solicit beyond the terms of the engagement that delivered it. We are a people who can be trusted with secrets.

4. Compliance — Fear God, Honour the Authority
You shall maintain valid business registration, tax standing and all sector-specific licences required by your jurisdiction, and shall furnish evidence of these upon request during periodic verification. We are not a law to ourselves; good order is part of our worship, and we give clean access to those who verify in good faith.

5. Financial Conduct — Just Balances
You shall not use this network to facilitate money laundering, unlicensed lending, pyramid or multi-level recruitment schemes, or any transaction prohibited by applicable law. Deceitful weights and false balances have no place in the economy of the Redeemed. Our increase must be clean, sustainable, and free of exploitation.

6. Respect and Non-Discrimination — One New Company
In this network there is neither Jew nor Greek, bond nor free, male nor female: we are all one in Christ Jesus. You shall treat every member of the network with dignity and honour regardless of nationality, ethnicity, gender or assembly, and you shall not use this platform to harass, defame, slander or discriminate. We are lights in the earth, not instruments of offence.

7. The Digital Seal — Authority Is Delegated, Never Owned
The Digital Seal remains the sole property of Loveworld Registry. It is a seal of verified standing, not a decoration to be traded upon. It may be displayed only for as long as your verification is current, and it must be removed immediately upon suspension, lapse or withdrawal — the same hour the ground changes.

8. Periodic Review — The Fire Shall Try Every Man's Work
Your listing remains subject to review. Verified status may be suspended where conduct, documentation, or client outcomes fall short of this Code. Our culture regards anything short of perfection as unacceptable, and so status here is never permanent by habit — it is sustained by continuing excellence.

THE AFFIRMATION

By submitting your application, you confirm before God and this network that:
- you have read this Code in full and understood it;
- every statement supplied in your application is accurate, complete and not misleading;
- you accept that verified status is granted, maintained, suspended or withdrawn at the sole discretion of Loveworld Registry.

Whatever you do, do it heartily, as unto the Lord and not unto men — knowing that of the Lord you shall receive the reward of the inheritance. This is the doctrine we stand upon.`

/*
 * Real submission. This used to navigate to /payment-required without creating
 * anything, so the flow only looked like it had worked. post-login decides
 * where a new SBO manager lands, so no destination is hardcoded here.
 *
 * A rejection on step 1 or 2 would otherwise leave the user on step 3 with the
 * errors out of sight, so the wizard walks back to the first step that has one.
 */
const businessFieldNames = businessFields.map((f) => f.name)
const contactFieldNames = contactFields.map((f) => f.name)

function submit() {
  form.post(appUrl('/register'), {
    onError: (errors) => {
      form.reset('password', 'passwordConfirmation')

      const keys = Object.keys(errors)
      if (keys.some((k) => businessFieldNames.includes(k))) go(1)
      else if (keys.some((k) => contactFieldNames.includes(k))) go(2)
    },
  })
}

function go(to: number) {
  // String path, not a vue-router location object. `useRouter` preserves state,
  // so the fields filled on an earlier step survive the visit.
  router.push(appUrl(`/register/${to}`))
}
</script>

<template>
  <div class="flex min-h-dvh items-start">
    <AuthBrandPanel />

    <section class="flex min-h-dvh flex-1 justify-center bg-surface-secondary px-5 py-8 sm:py-12">
      <div class="w-full min-w-0 max-w-[560px] md:max-w-[720px]">
        <RouterLink :href="appUrl('/')" class="lg:hidden">
          <img :src="logo" alt="LW Registry" class="logo-adaptive mb-8 h-9 w-auto object-contain" />
        </RouterLink>

        <!-- ================================== stepper 492:47487 ===========
             Registration only. Adding a business is a single dedicated page, so
             the 3-step registration stepper does not belong on it. -->
        <!-- Below md the row is circles only, so this says where you are. -->
        <p v-if="!isListing" class="mb-2 text-xs font-semibold text-[#0d9488] md:hidden">
          {{ t('register_wizard.step_of', { step, total: stepMeta.length, label: currentStepLabel }) }}
        </p>
        <ol v-if="!isListing" class="flex h-7 items-center" :aria-label="t('register_wizard.registration_progress')">
          <li v-for="(s, i) in stepMeta" :key="s.n" class="flex items-center">
            <button
              type="button"
              class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold transition-colors"
              :class="s.n < step ? 'bg-[#14b8a6] text-white'
                : s.n === step ? 'bg-[#0d9488] text-white'
                : 'border border-[#e2e8f0] bg-white text-[#475569]'"
              :aria-current="s.n === step ? 'step' : undefined"
              @click="go(s.n)"
            >
              <img v-if="s.n < step" :src="stepCheck" alt="" class="size-3" aria-hidden="true" />
              <template v-else>{{ s.n }}</template>
            </button>

            <span
              class="ml-2 hidden text-xs whitespace-nowrap md:inline"
              :class="s.n === step ? 'text-[#0d9488]' : 'text-[#475569]'"
            >
              {{ s.label }}
            </span>

            <span
              v-if="i < stepMeta.length - 1"
              class="mx-2 h-px w-4 shrink-0 bg-[#e2e8f0] md:mx-3"
              aria-hidden="true"
            />
          </li>
        </ol>

        <!-- ============================== payment step ==================== -->
        <!--
          The business is created; the same page now confirms the fee and takes
          payment for it. Espees leaves to its gateway and returns; the manual
          method uploads proof here.
        -->
        <section v-if="payStep && payFees && sbo" class="mt-8 flex flex-col gap-7">
          <div>
            <p class="text-xs font-semibold tracking-wide text-[#0d9488] uppercase">
              {{ t('register_wizard.add_your_business') }}
            </p>
            <h1 class="mt-1 font-serif text-3xl font-bold text-[#0f172a]">
              {{ t('register_wizard.pay_your_registration_fee') }}
            </h1>
            <p class="mt-2 text-sm text-[#64748b]">
              {{ t('register_wizard.business_saved_now_pay', { business: sbo.name }) }}
            </p>
          </div>
          <!--
            Said on the page, not only inside the payment dialog: once the
            proof is uploaded the dialog closes, and without this the applicant
            is looking at a screen that has stopped asking them for anything and
            has not told them why.
          -->
          <p
            v-if="payFees.paid"
            class="rounded-lg border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-sm text-[#15803d]"
            role="status"
          >
            {{ t('register_wizard.payment_approved_documents_are_open') }}
          </p>
          <p
            v-else-if="payFees.proofSubmitted"
            class="rounded-lg border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-sm text-[#b45309]"
            role="status"
          >
            {{ t('register_wizard.payment_submitted_awaiting_approval') }}
          </p>

          <FeeCalculator :fees="payFees" />
          <PaymentMethods :sbo-id="sbo.id" :fees="payFees" />

          <div class="flex gap-3">
            <RouterLink
              :href="appUrl('/account')"
              class="flex-1 rounded-md border border-[#e2e8f0] bg-white py-3.5 text-center text-base font-semibold text-ink hover:bg-surface-secondary"
            >
              {{ t('register_wizard.cancel') }}
            </RouterLink>
            <RouterLink
              v-if="paymentApproved"
              :href="appUrl('/application/documents')"
              class="flex-1 rounded-md btn-primary py-3.5 text-center text-base font-semibold"
            >
              {{ t('register_wizard.continue_to_documents') }}
            </RouterLink>
            <button
              v-else
              type="button"
              disabled
              class="flex-1 rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
            >
              {{ t('register_wizard.continue_to_documents') }}
            </button>
          </div>

          <p v-if="!paymentApproved" class="-mt-3 text-center text-xs text-[#64748b]">
            {{ t('register_wizard.documents_open_once_payment_is_approved') }}
          </p>
        </section>

        <!-- ============================== steps 1-2: field forms ========== -->
        <!--
          A plain div in listing mode, not a form: the payment methods below
          carry their own <form> (the Espees hand-off needs a real one for its
          native fallback), and nesting forms is invalid. Nothing is submitted
          from here in that mode — the payment buttons are the submit.
        -->
        <component
          :is="isListing ? 'div' : 'form'"
          v-if="!payStep && (isListing || step <= 2)"
          class="mt-8"
          @submit.prevent="goNext()"
        >
          <h1 class="font-serif text-3xl font-bold text-[#0f172a]">
            {{ isListing
              ? t('register_wizard.add_your_business')
              : (step === 2 ? t('register_wizard.contact_representative') : t('register_wizard.business_info')) }}
          </h1>
          <p class="mt-2 text-base leading-relaxed text-[#475569]">
            {{ isListing
              ? t('register_wizard.add_business_subtitle')
              : (step === 2
                ? t('register_wizard.specify_the_principal_stakeholders_and_alt')
                : t('register_wizard.tell_us_about_the_business_you_are_registe')) }}
          </p>

          <!--
            Two fields to a row on desktop, one below md. `half` on the field
            decides; anything without it spans both columns, which is what
            addresses and the description need.
          -->
          <div class="mt-7 grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
            <template v-for="f in fields" :key="f.name">
            <!-- Registration Fee Calculator, priced from the country above. -->
            <div v-if="f.calculator" class="md:col-span-2">
              <FeeCalculator
                v-if="(activeForm as any).bizCountry"
                :key="feeCalculatorKey"
                :fees="feePayload"
                preview
                @selection="onFeeSelection"
              />
              <p
                v-else
                class="rounded-xl border border-dashed border-[#e2e8f0] bg-[#f8fafc] px-4 py-4 text-sm text-[#64748b]"
              >
                {{ t('fee_calculator.select_your_country_to_see_the_fee') }}
              </p>
            </div>
            <label
              v-else
              v-show="f.name !== 'bizMinistry' || (activeForm as any).bizEntityType === 'SBO'"
              class="flex flex-col gap-2"
              :class="f.half ? 'md:col-span-1' : 'md:col-span-2'"
            >
              <span class="text-sm font-semibold text-[#0f172a]">
                {{ f.label }}<span v-if="f.required" class="text-danger" aria-hidden="true">*</span>
              </span>
              <!--
                Business/entity type: an explicit pick, not a dropdown with a
                pre-selected default — a COB owner used to leave it on "SBO"
                without noticing. Nothing is selected until they choose.
              -->
              <template v-if="f.name === 'bizEntityType'">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <button
                    v-for="opt in entityOptions"
                    :key="opt.value"
                    type="button"
                    :aria-pressed="(activeForm as any)[f.name] === opt.value"
                    class="flex items-start gap-3 rounded-md border bg-white px-4 py-3 text-left transition focus:outline-none"
                    :class="(activeForm as any)[f.name] === opt.value
                      ? 'border-focus ring-1 ring-focus'
                      : (fieldError(f.name) ? 'border-danger' : 'border-[#e2e8f0] hover:border-focus')"
                    @click="setField(f, opt.value); blurField(f)"
                  >
                    <span
                      class="mt-0.5 grid size-5 shrink-0 place-items-center rounded border-2"
                      :class="(activeForm as any)[f.name] === opt.value ? 'border-focus bg-focus text-white' : 'border-[#cbd5e1]'"
                      aria-hidden="true"
                    >
                      <svg v-if="(activeForm as any)[f.name] === opt.value" viewBox="0 0 20 20" fill="currentColor" class="size-3.5">
                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.3 3.29 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                      </svg>
                    </span>
                    <span class="flex flex-col">
                      <span class="text-sm font-semibold text-[#0f172a]">{{ opt.value }}</span>
                      <span class="text-xs text-[#64748b]">{{ opt.caption }}</span>
                    </span>
                  </button>
                </div>
                <span v-if="fieldError(f.name)" class="text-xs text-danger">{{ fieldError(f.name) }}</span>
              </template>
              <!-- Is the business physical, virtual, or both. -->
              <select
                v-else-if="f.name === 'bizPremiseType'"
                :value="(activeForm as any)[f.name]"
                class="max-w-full rounded-md border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-focus focus:outline-none"
                @change="(activeForm as any)[f.name] = ($event.target as HTMLSelectElement).value"
              >
                <option value="">{{ t('register_wizard.select_an_option') }}</option>
                <option value="physical">{{ t('register_wizard.physical') }}</option>
                <option value="virtual">{{ t('register_wizard.virtual') }}</option>
                <option value="both">{{ t('register_wizard.both') }}</option>
              </select>
              <select
                v-else-if="f.type === 'select'"
                :value="(activeForm as any)[f.name]"
                class="max-w-full rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none"
                :class="fieldError(f.name) ? 'border-danger focus:border-danger' : 'border-[#e2e8f0] focus:border-focus'"
                @change="setField(f, ($event.target as HTMLSelectElement).value)"
                @blur="blurField(f)"
              >
                <option value="">{{ f.placeholder }}</option>
                <option v-for="o in optionsFor[f.label] ?? []" :key="o" :value="o">{{ o }}</option>
              </select>
              <!--
                State depends on the country above it, so it cannot be a plain
                entry in `optionsFor` — the list arrives after a fetch. Falls
                back to the free-text box for a country the dataset has no
                subdivisions for, and while nothing is selected yet.
              -->
              <select
                v-else-if="f.name === 'bizState' && (statesFor[activeForm.bizCountry as string]?.length ?? 0) > 0"
                :value="(activeForm as any)[f.name]"
                class="max-w-full rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none"
                :class="fieldError(f.name) ? 'border-danger focus:border-danger' : 'border-[#e2e8f0] focus:border-focus'"
                @change="setField(f, ($event.target as HTMLSelectElement).value)"
                @blur="blurField(f)"
              >
                <option value="">{{ t('register_wizard.select_an_option') }}</option>
                <option
                  v-for="o in statesFor[activeForm.bizCountry as string]"
                  :key="o"
                  :value="o"
                >
                  {{ o }}
                </option>
              </select>
              <textarea
                v-else-if="f.type === 'textarea'"
                :value="(activeForm as any)[f.name]"
                rows="4"
                :placeholder="f.placeholder"
                class="rounded-md border bg-white px-4 py-3 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
                :class="fieldError(f.name) ? 'border-danger focus:border-danger' : 'border-[#e2e8f0] focus:border-focus'"
                @blur="blurField(f)"
                @input="setField(f, ($event.target as HTMLTextAreaElement).value)"
              />
              <!--
                Logo dropzone, matching the vetting-document control: dashed
                teal border on a tinted field, the action in the middle, the
                accepted formats under it. Once a file is chosen the same box
                becomes its preview, so the control stays in one place instead
                of the field being replaced by a filename.
              -->
              <label
                v-else-if="f.type === 'file'"
                :for="logoId"
                class="block cursor-pointer rounded-lg border-2 border-dashed border-primary/40 bg-primary/5 transition-colors"
                :class="logoDragging && 'border-primary bg-primary/10'"
                @dragover.prevent="logoDragging = true"
                @dragleave.prevent="logoDragging = false"
                @drop.prevent="onLogoDrop"
              >
                <!--
                  A <label> around the input, not a ref and a .click().
                  A `ref` inside this v-for collects into an ARRAY — Vue's
                  documented behaviour — so `logoInput.click()` was calling a
                  method arrays do not have, and the picker only ever opened by
                  accident. A label needs no JavaScript at all, and it keeps
                  keyboard activation for free.

                  `:key` is bumped on clear so Vue recreates the input empty;
                  a file input's value cannot be reassigned from a binding.
                -->
                <input
                  :id="logoId"
                  :key="logoKey"
                  type="file"
                  accept=".jpg,.jpeg,.png,.webp,.svg"
                  class="sr-only"
                  @change="setLogo(($event.target as HTMLInputElement).files?.[0] ?? null)"
                />

                <span v-if="logoName" class="flex flex-wrap items-center gap-4 p-5">
                  <img
                    v-if="logoPreview"
                    :src="logoPreview"
                    alt=""
                    class="size-16 shrink-0 rounded-md border border-border bg-white object-contain"
                  />
                  <!-- An SVG is not previewed: it is scripting-capable, and
                       rendering an untrusted one into this page to show a
                       thumbnail is not worth it. -->
                  <span
                    v-else
                    class="grid size-16 shrink-0 place-items-center rounded-md border border-border bg-white text-2xs font-semibold text-ink-tertiary"
                    aria-hidden="true"
                  >SVG</span>

                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-ink">{{ logoName }}</span>
                    <span class="mt-0.5 block text-2xs text-ink-secondary">
                      {{ t('register_wizard.logo_formats') }}
                    </span>
                  </span>

                  <span class="flex shrink-0 gap-2">
                    <span
                      class="rounded-md border border-border bg-white px-3 py-2 text-2xs font-semibold text-ink hover:bg-surface-secondary"
                    >
                      {{ t('listing_profile_form.replace') }}
                    </span>
                    <!--
                      Remove must NOT reopen the picker, and it is inside the
                      label — so it stops the click before the label sees it.
                    -->
                    <button
                      type="button"
                      class="rounded-md border border-border bg-white px-3 py-2 text-2xs font-semibold text-danger hover:bg-surface-secondary"
                      @click.prevent.stop="clearLogo"
                    >
                      {{ t('action.remove') }}
                    </button>
                  </span>
                </span>

                <span v-else class="flex w-full flex-col items-center gap-2 px-5 py-9 text-center">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="size-7 text-primary"
                    aria-hidden="true"
                  >
                    <path d="M12 15V3m0 0L8 7m4-4 4 4" />
                    <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                  </svg>
                  <span class="text-base font-semibold text-primary">
                    {{ t('register_wizard.browse_upload_logo') }}
                  </span>
                  <span class="text-sm text-ink-secondary">{{ t('register_wizard.logo_formats') }}</span>
                </span>
              </label>

              <!-- Phone with country prefix — drawn on step 2 (492:47443).
                   The flag follows the dial code and the dial code follows the
                   country select, in both directions: it was a fixed Nigerian
                   flag and "+234" on a registry that spans 250 countries. -->
              <span
                v-else-if="f.type === 'tel'"
                class="flex items-center gap-2 rounded-md border bg-white px-4 py-3"
                :class="fieldError(f.name) ? 'border-danger focus-within:border-danger' : 'border-[#e2e8f0] focus-within:border-focus'"
              >
                <UiCountryFlags :codes="isoForPrefix(f.name)" :max="1" size="md" class="shrink-0" />
                <!-- A list, not a text box: there are 205 codes and no way to
                     tell a mistyped one from a real one. Picking a code also
                     sets the country select above, so the two never disagree. -->
                <select
                  :value="prefixFor(f.name)"
                  :aria-label="t('register_wizard.country_dialling_code')"
                  class="w-[4.5rem] shrink-0 appearance-none border-r border-[#e2e8f0] bg-transparent pr-2 text-base text-ink focus:outline-none"
                  @change="onDialCodeChange(f.name, ($event.target as HTMLSelectElement).value)"
                >
                  <option value="">+—</option>
                  <option v-for="code in dialCodeOptions" :key="code" :value="code">{{ code }}</option>
                </select>
                <input
                  :value="(activeForm as any)[f.name]"
                  type="tel"
                  :placeholder="f.placeholder"
                  class="min-w-0 flex-1 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none"
                  @blur="blurField(f)"
                  @input="setField(f, ($event.target as HTMLInputElement).value)"
                />
              </span>
              <input
                v-else
                :value="(activeForm as any)[f.name]"
                :placeholder="
                  f.name === 'bizState' && statesLoading
                    ? t('register_wizard.loading_states')
                    : f.placeholder
                "
                :disabled="f.name === 'bizState' && statesLoading"
                class="rounded-md border bg-white px-4 py-3 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none disabled:opacity-60"
                :class="fieldError(f.name) ? 'border-danger focus:border-danger' : 'border-[#e2e8f0] focus:border-focus'"
                @blur="blurField(f)"
                @input="setField(f, ($event.target as HTMLInputElement).value)"
              />
              <span v-if="fieldError(f.name)" class="text-xs text-danger">
                {{ fieldError(f.name) }}
              </span>
            </label>
            </template>
          </div>

          <!-- Code of Conduct — business setup only. Must be read and agreed
               before the business can be created. -->
          <section v-if="isListing" class="mt-7 rounded-lg border border-border bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-sans text-sm font-semibold">{{ t('register_wizard.kingdom_business_code_of_conduct') }}</h2>
                <p class="mt-1 text-2xs text-ink-secondary">
                  {{ t('register_wizard.please_read_the_code_below_you_must_read_a') }}
                </p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded-md border border-border-input px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-secondary"
                :aria-expanded="conductExpanded"
                @click="conductExpanded ? (conductExpanded = false) : expandConduct()"
              >
                {{ conductExpanded ? t('register_wizard.collapse') : t('register_wizard.expand') }}
              </button>
            </div>

            <div
              class="mt-3 overflow-y-auto rounded-md bg-surface-secondary p-3 text-2xs leading-5 whitespace-pre-line text-ink-secondary"
              :class="conductExpanded ? 'max-h-none' : 'max-h-48'"
              tabindex="0"
              role="region"
              :aria-label="t('register_wizard.kingdom_business_code_of_conduct')"
              @scroll="onConductScroll"
            >{{ conductText }}</div>

            <p v-if="!conductRead" class="mt-2 text-2xs text-warning">
              {{ t('register_wizard.scroll_to_the_end_of_the_code_or_expand_it') }}
            </p>

            <label class="mt-3 flex items-start gap-2" :class="!conductRead && 'opacity-50'">
              <input
                v-model="listingForm.agreeCode"
                type="checkbox"
                :disabled="!conductRead"
                class="mt-0.5 size-4 accent-[#007035] disabled:cursor-not-allowed"
              />
              <span class="text-2xs text-ink-secondary">
                {{ t('register_wizard.i_have_read_and_agree_to_the_kingdom_busin') }}
              </span>
            </label>
          </section>

          <!-- ============================ payment, in place ================
            Not a later step and not behind a Save button: the fee priced above
            is paid right here, and pressing one of these is what saves the
            business. See payForListing / SboWorkspaceController@storeListing.
          -->
          <section v-if="isListing" class="mt-8 flex flex-col gap-4">
            <div>
              <h2 class="font-serif text-xl font-bold text-[#0f172a]">
                {{ t('register_wizard.pay_your_registration_fee') }}
              </h2>
              <p class="mt-1 text-sm text-[#64748b]">
                {{ t('register_wizard.paying_saves_your_business') }}
              </p>
            </div>

            <p
              v-if="listingForm.errors.payWith || listingForm.errors.proof || listingForm.errors.bizShareStructure"
              class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-xs text-danger"
              role="alert"
            >
              {{ listingForm.errors.payWith || listingForm.errors.proof || listingForm.errors.bizShareStructure }}
            </p>

            <PaymentMethods
              :sbo-id="null"
              :fees="inlineFees"
              defer
              :busy="listingForm.processing"
              @pay="payForListing"
            />
          </section>

          <div class="mt-8 flex gap-3">
            <RouterLink
              v-if="isListing"
              :href="appUrl('/account')"
              class="flex-1 rounded-md border border-[#e2e8f0] bg-white py-3.5 text-center text-base font-semibold text-ink hover:bg-surface-secondary"
            >
              {{ t('register_wizard.cancel') }}
            </RouterLink>
            <RouterLink
              v-else-if="step === 1"
              :href="appUrl('/login')"
              class="flex-1 rounded-md border border-[#e2e8f0] bg-white py-3.5 text-center text-base font-semibold text-ink hover:bg-surface-secondary"
            >
              {{ t('register_wizard.back') }}
            </RouterLink>
            <button
              v-else
              type="button"
              class="flex-1 rounded-md border border-[#e2e8f0] bg-white py-3.5 text-base font-semibold text-ink hover:bg-surface-secondary"
              @click="go(step - 1)"
            >
              {{ t('register_wizard.back') }}
            </button>
            <!--
              Documents, once the payment above has been APPROVED. It is a
              signpost, not a submit: nothing is saved by pressing it, and it
              stays shut until an administrator has cleared the payment.
            -->
            <RouterLink
              v-if="isListing && paymentApproved"
              :href="appUrl('/application/documents')"
              class="flex-1 rounded-md btn-primary py-3.5 text-center text-base font-semibold"
            >
              {{ t('register_wizard.continue_to_documents') }}
            </RouterLink>
            <button
              v-else-if="isListing"
              type="button"
              disabled
              class="flex-1 rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
              :title="t('register_wizard.documents_open_once_payment_is_approved')"
            >
              {{ t('register_wizard.continue_to_documents') }}
            </button>
            <button
              v-else
              type="submit"
              class="flex-1 rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60"
            >
              {{ t('register_wizard.next_step') }}
            </button>
          </div>

          <p v-if="isListing && !paymentApproved" class="mt-3 text-center text-xs text-[#64748b]">
            {{ t('register_wizard.documents_open_once_payment_is_approved') }}
          </p>
        </component>

        <!-- ==================== step 3: account + conduct 492:47590 ======= -->
        <form v-else-if="!isListing" class="mt-8" @submit.prevent="submitChecked">
          <!--
            492:47654 lays the two fields side by side, each with its own
            4-segment strength meter below (492:47666 / 492:47684) — not the
            stacked pair with a text-only hint that was here before.
          -->
          <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
            <div class="flex flex-col gap-2">
              <UiPasswordField v-model="form.password" label="Password" autocomplete="new-password" />
              <!-- strength-bars 492:47667: 4px tall, 4px gap, 2px radius. -->
              <div class="flex gap-1" aria-hidden="true">
                <span
                  v-for="n in 4"
                  :key="n"
                  class="h-1 flex-1 rounded-[2px]"
                  :class="n <= strength.score ? 'bg-[#007035]' : 'bg-[#e2e8f0]'"
                />
              </div>
              <p v-if="form.password" class="text-xs font-medium text-[#007035]">
                {{ strength.label }}
              </p>
              <p v-if="fieldError('password')" class="text-xs text-danger">{{ fieldError('password') }}</p>
            </div>

            <div class="flex flex-col gap-2">
              <UiPasswordField
                v-model="form.passwordConfirmation"
                label="Confirm Password"
                autocomplete="new-password"
              />
              <div class="flex gap-1" aria-hidden="true">
                <span
                  v-for="n in 4"
                  :key="n"
                  class="h-1 flex-1 rounded-[2px]"
                  :class="n <= confirmScore ? 'bg-[#007035]' : 'bg-[#e2e8f0]'"
                />
              </div>
              <p v-if="fieldError('passwordConfirmation')" class="text-xs text-danger">
                {{ fieldError('passwordConfirmation') }}
              </p>
            </div>
          </div>

          <!-- checkbox 492:47690 — sits above the Code block, link #1b63ca. -->
          <label class="mt-7 flex items-center gap-2.5">
            <input v-model="form.agreeTerms" type="checkbox" class="size-[18px] accent-[#007035]" />
            <span class="text-sm text-[#475569]">
              {{ t('register_wizard.i_agree_to_the') }}
                                                                                                                                      
              <span class="text-[#1b63ca]">{{ t('register_wizard.terms_amp_privacy_policy') }}</span>
            </span>
          </label>
          <p v-if="fieldError('agreeTerms')" class="mt-1 text-xs text-danger">{{ fieldError('agreeTerms') }}</p>

          <section class="mt-7 rounded-lg border border-border bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-sans text-sm font-semibold">{{ t('register_wizard.kingdom_business_code_of_conduct') }}</h2>
                <p class="mt-1 text-2xs text-ink-secondary">
                  {{ t('register_wizard.please_read_the_code_below_you_must_read_a') }}
                </p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded-md border border-border-input px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-secondary"
                :aria-expanded="conductExpanded"
                @click="conductExpanded ? (conductExpanded = false) : expandConduct()"
              >
                {{ conductExpanded ? t('register_wizard.collapse') : t('register_wizard.expand') }}
              </button>
            </div>

            <div
              class="mt-3 overflow-y-auto rounded-md bg-surface-secondary p-3 text-2xs leading-5 whitespace-pre-line text-ink-secondary"
              :class="conductExpanded ? 'max-h-none' : 'max-h-48'"
              tabindex="0"
              role="region"
              :aria-label="t('register_wizard.kingdom_business_code_of_conduct')"
              @scroll="onConductScroll"
            >{{ conductText }}</div>

            <p v-if="!conductRead" class="mt-2 text-2xs text-warning">
              {{ t('register_wizard.scroll_to_the_end_of_the_code_or_expand_it') }}
            </p>

            <label class="mt-3 flex items-start gap-2" :class="!conductRead && 'opacity-50'">
              <input
                v-model="form.agreeCode"
                type="checkbox"
                :disabled="!conductRead"
                class="mt-0.5 size-4 accent-[#007035] disabled:cursor-not-allowed"
              />
              <span class="text-2xs text-ink-secondary">
                {{ t('register_wizard.i_have_read_and_agree_to_the_kingdom_busin') }}
              </span>
            </label>
          </section>

          <div class="mt-8 flex gap-3">
            <button
              type="button"
              class="flex-1 rounded-md border border-[#e2e8f0] bg-white py-3.5 text-base font-semibold text-ink hover:bg-surface-secondary"
              @click="go(2)"
            >
              {{ t('register_wizard.back') }}
            </button>
            <!-- Rests inactive; only turns active once the Code is read and agreed.
                 Submitting creates the account and hands straight over to the
                 registration fee, which is step 1 of the application flow. -->
            <button
              type="submit"
              class="btn-primary flex-1 rounded-md py-3.5 text-base font-semibold"
              :class="canSubmit && 'is-active'"
              :disabled="!canSubmit || form.processing"
            >
              {{ form.processing ? t('register_wizard.submitting') : t('register_wizard.submit_application') }}
            </button>
          </div>
        </form>
      </div>
    </section>
  </div>
</template>
