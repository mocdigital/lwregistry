<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import AuthBrandPanel from '@/components/shell/AuthBrandPanel.vue'
import UiCountryFlags from '@/components/ui/UiCountryFlags.vue'
import UiPasswordField from '@/components/ui/UiPasswordField.vue'
import logo from '@/assets/figma/raw/logo-lw-registry-wide.png'
import { useI18n } from '@/composables/useI18n'
import { useAppUrl } from '@/composables/useAppUrl'

/*
 * Registration — a single screen that creates a MEMBER ACCOUNT: the person,
 * their contact and Loveworld-member details, and an email + password. It no
 * longer creates a business; after verifying their email the member adds one or
 * more businesses from the workspace, where the business details and the Code of
 * Conduct are collected. The member details here are copied onto each business
 * at that point, so they are entered once.
 */

const { t } = useI18n()
const appUrl = useAppUrl()

const props = defineProps<{
  zones: string[]
  countries: string[]
  dialCodes?: Record<string, string>
  countryIsoCodes?: Record<string, string>
  countriesByDialCode?: Record<string, string>
}>()

const form = useForm({
  ownerFirstName: '',
  ownerSurname: '',
  ownerCountry: '',
  ownerPhone: '',
  ownerKingschat: '',
  ownerResidentialAddress: '',
  ownerChurchZone: '',
  ownerLocalAssembly: '',
  ownerYearsAsMember: '',
  ownerZonalPastor: '',
  ownerLocalPastor: '',
  email: '',
  password: '',
  passwordConfirmation: '',
  agreeTerms: false,
})

/* ----------------------------------------------------------- phone prefix ---
 * The dial code follows the country the member picks, and is editable so
 * someone who knows their code can type it and have the country selected back.
 */
const dialCode = reactive<{ value: string }>({ value: '' })

function dialCodeFor(country: string): string {
  return props.dialCodes?.[country] ?? ''
}

const prefix = computed(() => dialCode.value || dialCodeFor(form.ownerCountry))

const flagCodes = computed<string[]>(() => {
  const country = props.countriesByDialCode?.[prefix.value]
  const iso = country ? props.countryIsoCodes?.[country] : undefined

  return iso ? [iso] : []
})

const dialCodeOptions = computed(() =>
  Object.keys(props.countriesByDialCode ?? {}).sort(
    (a, b) => Number(a.slice(1)) - Number(b.slice(1)),
  ),
)

function onDialCodeChange(code: string) {
  dialCode.value = code
  const country = props.countriesByDialCode?.[code]
  if (country) form.ownerCountry = country
  if (touched.ownerPhone) validate('ownerPhone')
}

watch(
  () => form.ownerCountry,
  (country) => {
    if (country) dialCode.value = dialCodeFor(country)
    if (touched.ownerPhone) validate('ownerPhone')
  },
)

/* ------------------------------------------------------------- validation ---
 * Each field checks itself on blur, and again on input once it has been
 * touched. Rules mirror RegisteredUserController; the server stays authority.
 */
const clientErrors = reactive<Record<string, string>>({})
const touched = reactive<Record<string, boolean>>({})

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const REQUIRED = [
  'ownerFirstName',
  'ownerSurname',
  'ownerCountry',
  'ownerPhone',
  'ownerResidentialAddress',
  'ownerChurchZone',
  'ownerLocalAssembly',
  'email',
  'password',
  'passwordConfirmation',
]

function ruleError(field: string): string | null {
  const raw = (form as any)[field]
  const value = typeof raw === 'string' ? raw.trim() : raw

  if (REQUIRED.includes(field) && (value === '' || value == null)) {
    return t('register_wizard.validation.required')
  }
  if (value === '' || value == null) return null

  const s = String(value)
  switch (field) {
    case 'email':
      return emailRe.test(s) ? null : t('register_wizard.validation.email')
    case 'ownerPhone': {
      const code = prefix.value.replace(/\D/g, '')
      if (!code) return t('register_wizard.validation.phone_code')
      const local = s.replace(/\D/g, '').replace(/^0+/, '')
      const full = code + local
      return local.length >= 5 && full.length >= 8 && full.length <= 15
        ? null
        : t('register_wizard.validation.phone')
    }
    case 'ownerYearsAsMember':
      return /^\d+$/.test(s) ? null : t('register_wizard.validation.number')
    case 'password': {
      const strong = s.length >= 8 && /[a-z]/.test(s) && /[A-Z]/.test(s) && /[0-9]/.test(s)
      return strong ? null : t('register_wizard.validation.password_rules')
    }
    case 'passwordConfirmation':
      return s === form.password ? null : t('register_wizard.validation.password_match')
    default:
      return null
  }
}

function validate(field: string): boolean {
  const err = ruleError(field)
  if (err) clientErrors[field] = err
  else delete clientErrors[field]

  return !err
}

function setField(field: string, value: string) {
  ;(form as any)[field] = value
  if (touched[field]) validate(field)
}

function blur(field: string) {
  touched[field] = true
  validate(field)
}

function fieldError(field: string): string | undefined {
  return clientErrors[field] ?? (form.errors as any)[field]
}

const fieldClass = (field: string) =>
  fieldError(field)
    ? 'border-danger focus:border-danger'
    : 'border-[#e2e8f0] focus:border-focus'

function submit() {
  let ok = true
  for (const f of [...REQUIRED, 'ownerYearsAsMember']) {
    touched[f] = true
    if (!validate(f)) ok = false
  }
  if (!form.agreeTerms) {
    clientErrors.agreeTerms = t('register_wizard.validation.agree')
    ok = false
  } else {
    delete clientErrors.agreeTerms
  }
  if (!ok) return

  form.post(appUrl('/register'), {
    onError: () => form.reset('password', 'passwordConfirmation'),
  })
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

        <h1 class="font-serif text-3xl font-bold text-[#0f172a]">
          {{ t('register_wizard.create_your_account') }}
        </h1>
        <p class="mt-2 text-base leading-relaxed text-[#475569]">
          {{ t('register_wizard.register_as_member_add_business_later') }}
        </p>

        <form class="mt-8 flex flex-col gap-8" @submit.prevent="submit">
          <!-- Your details -->
          <div>
            <h2 class="font-display text-lg font-semibold text-[#0f172a]">
              {{ t('register_wizard.your_details') }}
            </h2>
            <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.firstname') }}<span class="text-danger">*</span></span>
                <input :value="form.ownerFirstName" class="rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerFirstName')" @input="setField('ownerFirstName', ($event.target as HTMLInputElement).value)" @blur="blur('ownerFirstName')" />
                <span v-if="fieldError('ownerFirstName')" class="text-xs text-danger">{{ fieldError('ownerFirstName') }}</span>
              </label>
              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.surname') }}<span class="text-danger">*</span></span>
                <input :value="form.ownerSurname" class="rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerSurname')" @input="setField('ownerSurname', ($event.target as HTMLInputElement).value)" @blur="blur('ownerSurname')" />
                <span v-if="fieldError('ownerSurname')" class="text-xs text-danger">{{ fieldError('ownerSurname') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.country') }}<span class="text-danger">*</span></span>
                <select :value="form.ownerCountry" class="max-w-full rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerCountry')" @change="setField('ownerCountry', ($event.target as HTMLSelectElement).value)" @blur="blur('ownerCountry')">
                  <option value="">{{ t('register_wizard.select_an_option') }}</option>
                  <option v-for="c in props.countries" :key="c" :value="c">{{ c }}</option>
                </select>
                <span v-if="fieldError('ownerCountry')" class="text-xs text-danger">{{ fieldError('ownerCountry') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.contact_phone_number') }}<span class="text-danger">*</span></span>
                <span class="flex items-center gap-2 rounded-md border bg-white px-4 py-3 focus-within:border-focus" :class="fieldClass('ownerPhone')">
                  <UiCountryFlags :codes="flagCodes" :max="1" size="md" class="shrink-0" />
                  <select :value="prefix" :aria-label="t('register_wizard.country_dialling_code')" class="w-[4.5rem] shrink-0 appearance-none border-r border-[#e2e8f0] bg-transparent pr-2 text-base text-ink focus:outline-none" @change="onDialCodeChange(($event.target as HTMLSelectElement).value)">
                    <option value="">+—</option>
                    <option v-for="code in dialCodeOptions" :key="code" :value="code">{{ code }}</option>
                  </select>
                  <input :value="form.ownerPhone" type="tel" placeholder="8031234567" class="min-w-0 flex-1 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none" @input="setField('ownerPhone', ($event.target as HTMLInputElement).value)" @blur="blur('ownerPhone')" />
                </span>
                <span v-if="fieldError('ownerPhone')" class="text-xs text-danger">{{ fieldError('ownerPhone') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.kingschat_handle') }}</span>
                <input :value="form.ownerKingschat" class="rounded-md border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-focus focus:outline-none" @input="form.ownerKingschat = ($event.target as HTMLInputElement).value" />
              </label>

              <label class="flex flex-col gap-2 md:col-span-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.residential_address') }}<span class="text-danger">*</span></span>
                <input :value="form.ownerResidentialAddress" class="rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerResidentialAddress')" @input="setField('ownerResidentialAddress', ($event.target as HTMLInputElement).value)" @blur="blur('ownerResidentialAddress')" />
                <span v-if="fieldError('ownerResidentialAddress')" class="text-xs text-danger">{{ fieldError('ownerResidentialAddress') }}</span>
              </label>
            </div>
          </div>

          <!-- Church -->
          <div>
            <h2 class="font-display text-lg font-semibold text-[#0f172a]">
              {{ t('register_wizard.church_membership') }}
            </h2>
            <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.church_zone') }}<span class="text-danger">*</span></span>
                <select :value="form.ownerChurchZone" class="max-w-full rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerChurchZone')" @change="setField('ownerChurchZone', ($event.target as HTMLSelectElement).value)" @blur="blur('ownerChurchZone')">
                  <option value="">{{ t('register_wizard.select_an_option') }}</option>
                  <option v-for="z in props.zones" :key="z" :value="z">{{ z }}</option>
                </select>
                <span v-if="fieldError('ownerChurchZone')" class="text-xs text-danger">{{ fieldError('ownerChurchZone') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.local_assembly') }}<span class="text-danger">*</span></span>
                <input :value="form.ownerLocalAssembly" class="rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerLocalAssembly')" @input="setField('ownerLocalAssembly', ($event.target as HTMLInputElement).value)" @blur="blur('ownerLocalAssembly')" />
                <span v-if="fieldError('ownerLocalAssembly')" class="text-xs text-danger">{{ fieldError('ownerLocalAssembly') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.total_number_of_years_as_a_member') }}</span>
                <input :value="form.ownerYearsAsMember" inputmode="numeric" class="rounded-md border bg-white px-4 py-3 text-base text-ink focus:outline-none" :class="fieldClass('ownerYearsAsMember')" @input="setField('ownerYearsAsMember', ($event.target as HTMLInputElement).value)" @blur="blur('ownerYearsAsMember')" />
                <span v-if="fieldError('ownerYearsAsMember')" class="text-xs text-danger">{{ fieldError('ownerYearsAsMember') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.name_of_zonal_pastor') }}</span>
                <input :value="form.ownerZonalPastor" class="rounded-md border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-focus focus:outline-none" @input="form.ownerZonalPastor = ($event.target as HTMLInputElement).value" />
              </label>

              <label class="flex flex-col gap-2 md:col-span-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.name_of_local_pastor') }}</span>
                <input :value="form.ownerLocalPastor" class="rounded-md border border-[#e2e8f0] bg-white px-4 py-3 text-base text-ink focus:border-focus focus:outline-none" @input="form.ownerLocalPastor = ($event.target as HTMLInputElement).value" />
              </label>
            </div>
          </div>

          <!-- Account -->
          <div>
            <h2 class="font-display text-lg font-semibold text-[#0f172a]">
              {{ t('register_wizard.account') }}
            </h2>
            <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
              <label class="flex flex-col gap-2 md:col-span-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.email_address') }}<span class="text-danger">*</span></span>
                <input :value="form.email" type="email" autocomplete="email" placeholder="e.g. name@example.com" class="rounded-md border bg-white px-4 py-3 text-base text-ink placeholder:text-[#94a3b8] focus:outline-none" :class="fieldClass('email')" @input="setField('email', ($event.target as HTMLInputElement).value)" @blur="blur('email')" />
                <span v-if="fieldError('email')" class="text-xs text-danger">{{ fieldError('email') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.password') }}<span class="text-danger">*</span></span>
                <UiPasswordField v-model="form.password" autocomplete="new-password" @blur="blur('password')" />
                <span v-if="fieldError('password')" class="text-xs text-danger">{{ fieldError('password') }}</span>
              </label>

              <label class="flex flex-col gap-2">
                <span class="text-sm font-semibold text-[#0f172a]">{{ t('register_wizard.confirm_password') }}<span class="text-danger">*</span></span>
                <UiPasswordField v-model="form.passwordConfirmation" autocomplete="new-password" @blur="blur('passwordConfirmation')" />
                <span v-if="fieldError('passwordConfirmation')" class="text-xs text-danger">{{ fieldError('passwordConfirmation') }}</span>
              </label>
            </div>

            <label class="mt-5 flex items-start gap-3">
              <input v-model="form.agreeTerms" type="checkbox" class="mt-0.5 size-[18px] accent-[#007035]" @change="delete clientErrors.agreeTerms" />
              <span class="text-sm text-[#475569]">{{ t('register_wizard.i_agree_terms_privacy') }}</span>
            </label>
            <p v-if="clientErrors.agreeTerms || (form.errors as any).agreeTerms" class="mt-1 text-xs text-danger">
              {{ clientErrors.agreeTerms ?? (form.errors as any).agreeTerms }}
            </p>
          </div>

          <div class="flex flex-col gap-3">
            <button type="submit" :disabled="form.processing" class="rounded-md btn-primary py-3.5 text-base font-semibold disabled:opacity-60">
              {{ form.processing ? t('register_wizard.creating') : t('register_wizard.create_account') }}
            </button>
            <p class="text-center text-sm text-[#475569]">
              {{ t('register_wizard.already_have_an_account') }}
              <RouterLink :href="appUrl('/login')" class="font-semibold text-primary hover:underline">{{ t('register_wizard.sign_in') }}</RouterLink>
            </p>
          </div>
        </form>
      </div>
    </section>
  </div>
</template>
