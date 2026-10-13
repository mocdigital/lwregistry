<script setup lang="ts">
import { computed, ref } from 'vue'
import UiCountryFlags from '@/components/ui/UiCountryFlags.vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * The listing profile editor.
 *
 * A component rather than a page because it is mounted in two places — the
 * standalone /sbo/listings/{id}/edit screen and the About tab of /settings.
 * Two copies of four forms would drift, and the drift would be silent: the
 * sections would keep saving to the same endpoints while showing different
 * fields.
 *
 * The four sections map to the four blocks on the public profile that used to
 * be hard-coded and identical across every SBO. Each saves on its own — a
 * gallery upload is a file POST and the services list is a full replace, so
 * one "Save everything" button would either lose work or need a multipart
 * envelope around unrelated data.
 */
type LockedRow = { label: string; value: string | null }

const props = defineProps<{
  sbo: {
    id: number
    slug: string
    locked: LockedRow[]
    description: string | null
    website: string | null
    primary_contact_email: string | null
    phone: string | null
    staff_count: number | null
  }
  /** Cover photo and logo for the listing cards; null until uploaded. */
  branding: { logoUrl: string | null; coverUrl: string | null }
  /**
   * Every place the business trades from. The first is the primary — it feeds
   * the profile header and the map, and it is mirrored back onto the listing's
   * own columns on save.
   */
  addresses: {
    id: number
    label: string | null
    address: string | null
    city: string | null
    state: string | null
    country: string | null
    country_code: string | null
  }[]
  countries: string[]
  maxAddresses: number
  services: { id: number; title: string; body: string | null }[]
  hours: {
    day_of_week: number
    day: string
    set: boolean
    is_closed: boolean
    opens_at: string | null
    closes_at: string | null
  }[]
  gallery: {
    collection: string
    label: string
    images: { id: number; thumb: string; name: string }[]
  }[]
  maxImagesPerCollection: number
}>()

const lockedRows = computed(() => props.sbo.locked.filter((r) => r.value))


const profileForm = useForm({
  description: props.sbo.description ?? '',
  website: props.sbo.website ?? '',
  primary_contact_email: props.sbo.primary_contact_email ?? '',
  phone: props.sbo.phone ?? '',
  staff_count: props.sbo.staff_count,
})

function saveProfile() {
  profileForm.patch(`/sbo/listings/${props.sbo.id}/profile`, { preserveScroll: true })
}


/*
 * Addresses.
 *
 * A business with offices in three countries could record one of them, and the
 * card's flag stack was drawn from a fixed list of assets rather than anywhere
 * a business had said it trades. Order matters: row one is the primary.
 */
const addressesForm = useForm({
  addresses: props.addresses.map((a) => ({
    label: a.label ?? '',
    address: a.address ?? '',
    city: a.city ?? '',
    state: a.state ?? '',
    country: a.country ?? '',
  })),
})

function addAddress() {
  if (addressesForm.addresses.length >= props.maxAddresses) return

  addressesForm.addresses.push({ label: '', address: '', city: '', state: '', country: '' })
}

function removeAddress(i: number) {
  addressesForm.addresses.splice(i, 1)
}

function moveAddress(i: number, delta: number) {
  const to = i + delta
  if (to < 0 || to >= addressesForm.addresses.length) return
  const [row] = addressesForm.addresses.splice(i, 1)
  addressesForm.addresses.splice(to, 0, row)
}

function saveAddresses() {
  addressesForm.put(`/sbo/listings/${props.sbo.id}/addresses`, { preserveScroll: true })
}

/*
 * The flag preview, computed exactly as the card computes it: one per COUNTRY,
 * so three branches in Nigeria show one flag. Shown here so an owner can see
 * what adding an address does before they save it.
 */
const previewCodes = computed(() => {
  const byName = new Map(props.addresses.map((a) => [a.country ?? '', a.country_code ?? '']))
  const seen: string[] = []

  for (const row of addressesForm.addresses) {
    const code = byName.get(row.country) || ''
    if (code && !seen.includes(code)) seen.push(code)
  }

  return seen
})

const servicesForm = useForm({
  services: props.services.map((s) => ({ title: s.title, body: s.body ?? '' })),
})

function addService() {
  servicesForm.services.push({ title: '', body: '' })
}

function removeService(i: number) {
  servicesForm.services.splice(i, 1)
}

function moveService(i: number, by: number) {
  const to = i + by
  if (to < 0 || to >= servicesForm.services.length) return
  const [row] = servicesForm.services.splice(i, 1)
  servicesForm.services.splice(to, 0, row)
}

function saveServices() {
  servicesForm.put(`/sbo/listings/${props.sbo.id}/services`, { preserveScroll: true })
}


const hoursForm = useForm({
  hours: props.hours.map((h) => ({
    day_of_week: h.day_of_week,
    day: h.day,
    state: !h.set ? 'unset' : h.is_closed ? 'closed' : 'open',
    opens_at: h.opens_at ?? '09:00',
    closes_at: h.closes_at ?? '17:00',
  })),
})

function saveHours() {
  hoursForm
    .transform((data) => ({
      hours: data.hours
        .filter((h) => h.state !== 'unset')
        .map((h) => ({
          day_of_week: h.day_of_week,
          is_closed: h.state === 'closed',
          opens_at: h.state === 'closed' ? null : h.opens_at,
          closes_at: h.state === 'closed' ? null : h.closes_at,
        })),
    }))
    .put(`/sbo/listings/${props.sbo.id}/hours`, { preserveScroll: true })
}


/* ------------------------------------------------------------ branding --- */

/*
 * The cover photo and logo every listing card draws. Until these existed the
 * cards rotated three stock photos and three invented partner logos across the
 * whole registry, so no business could be told apart from any other.
 */
const brandingBusy = ref<string | null>(null)
const brandingError = ref<string | null>(null)

function uploadBranding(collection: string, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  brandingBusy.value = collection
  brandingError.value = null

  router.post(
    `/sbo/listings/${props.sbo.id}/branding`,
    { collection, image: file },
    {
      preserveScroll: true,
      forceFormData: true,
      onError: (errors) => {
        brandingError.value = errors.image ?? t('listing_profile_form.that_file_could_not_be_uploaded')
      },
      onFinish: () => {
        brandingBusy.value = null
        input.value = ''
      },
    },
  )
}

function removeBranding(collection: string) {
  router.delete(`/sbo/listings/${props.sbo.id}/branding/${collection}`, { preserveScroll: true })
}

/* ------------------------------------------------------------- gallery --- */

const uploadingTo = ref<string | null>(null)
const uploadError = ref<string | null>(null)

function uploadImage(collection: string, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  uploadingTo.value = collection
  uploadError.value = null

  router.post(
    `/sbo/listings/${props.sbo.id}/gallery`,
    { collection, image: file },
    {
      preserveScroll: true,
      forceFormData: true,
      onError: (errors) => {
        uploadError.value = errors.image ?? t('listing_profile_form.that_file_could_not_be_uploaded')
      },
      onFinish: () => {
        uploadingTo.value = null
        // Let the same file be picked again after a failure.
        input.value = ''
      },
    },
  )
}

function removeImage(mediaId: number) {
  if (!window.confirm(t('listing_profile_form.remove_this_photo_from_your_profile'))) return
  router.delete(`/sbo/listings/${props.sbo.id}/gallery/${mediaId}`, { preserveScroll: true })
}
</script>

<template>
  <div class="space-y-6">
      <!-- ==================================================== branding ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.cover_image_amp_logo') }}</h2>
        <p class="mt-1 text-xs text-ink-secondary">
          {{ t('listing_profile_form.these_are_what_your_business_looks_like_ev') }}
        </p>

        <p v-if="brandingError" class="mt-3 rounded-md border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-xs text-danger" role="alert">
          {{ brandingError }}
        </p>

        <!-- A live preview in the shape the card actually uses, so the owner
             can see the logo overlapping the cover before saving. -->
        <div class="mt-4 max-w-sm overflow-hidden rounded-lg border border-border">
          <div class="relative">
            <img
              v-if="props.branding.coverUrl"
              :src="props.branding.coverUrl"
              :alt="t('listing_profile_form.cover_image_preview')"
              class="h-32 w-full object-cover"
            />
            <div v-else class="grid h-32 w-full place-items-center bg-surface-tertiary text-2xs text-ink-tertiary">
              {{ t('listing_profile_form.no_cover_image') }}
            </div>

            <span class="absolute -bottom-6 left-4 grid size-12 place-items-center overflow-hidden rounded-full border-2 border-white bg-[#001529]">
              <img
                v-if="props.branding.logoUrl"
                :src="props.branding.logoUrl"
                :alt="t('listing_profile_form.logo_preview')"
                class="size-full object-cover"
              />
              <span v-else class="text-2xs font-semibold text-white">{{ t('listing_profile_form.logo') }}</span>
            </span>
          </div>
          <div class="px-4 pt-8 pb-4">
            <p class="font-display text-base font-semibold text-ink">{{ t('listing_profile_form.your_business_card') }}</p>
            <p class="text-2xs text-ink-tertiary">{{ t('listing_profile_form.preview') }}</p>
          </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
          <div>
            <h3 class="font-sans text-xs font-semibold text-ink">{{ t('listing_profile_form.cover_image') }}</h3>
            <p class="mt-1 text-2xs text-ink-tertiary">{{ t('listing_profile_form.wide_photo_at_least_640_times_320_jpg_png') }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <label class="cursor-pointer rounded-md border border-border px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-tertiary">
                {{ brandingBusy === 'cover' ? t('documents.uploading') : props.branding.coverUrl ? t('listing_profile_form.replace') : t('action.upload') }}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  class="sr-only"
                  :disabled="brandingBusy !== null"
                  @change="uploadBranding('cover', $event)"
                />
              </label>
              <button
                v-if="props.branding.coverUrl"
                type="button"
                class="text-2xs font-semibold text-danger hover:underline"
                @click="removeBranding('cover')"
              >
                {{ t('listing_profile_form.remove') }}
              </button>
            </div>
          </div>

          <div>
            <h3 class="font-sans text-xs font-semibold text-ink">{{ t('listing_profile_form.logo') }}</h3>
            <p class="mt-1 text-2xs text-ink-tertiary">{{ t('listing_profile_form.square_works_best_jpg_png_webp_or_svg_5_nb') }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <label class="cursor-pointer rounded-md border border-border px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-tertiary">
                {{ brandingBusy === 'logo' ? t('documents.uploading') : props.branding.logoUrl ? t('listing_profile_form.replace') : t('action.upload') }}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/svg+xml"
                  class="sr-only"
                  :disabled="brandingBusy !== null"
                  @change="uploadBranding('logo', $event)"
                />
              </label>
              <button
                v-if="props.branding.logoUrl"
                type="button"
                class="text-2xs font-semibold text-danger hover:underline"
                @click="removeBranding('logo')"
              >
                {{ t('listing_profile_form.remove') }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ===================================================== locked ===== -->
      <section v-if="lockedRows.length" class="rounded-lg border border-border bg-white p-5">
        <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.registry_details') }}</h2>
        <p class="mt-1 text-xs text-ink-secondary">
          {{ t('listing_profile_form.these_were_checked_when_your_registration') }}
        </p>
        <dl class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
          <div v-for="d in lockedRows" :key="d.label" class="flex flex-col gap-0.5 border-b border-border-subtle pb-3">
            <dt class="text-2xs font-semibold tracking-wide text-ink-secondary uppercase">{{ d.label }}</dt>
            <dd class="text-xs text-ink">{{ d.value }}</dd>
          </div>
        </dl>
      </section>

      <!-- ==================================================== profile ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.about_the_company') }}</h2>
        <form class="mt-4 space-y-4" @submit.prevent="saveProfile">
          <label class="block">
            <span class="text-xs font-semibold text-ink-secondary">{{ t('listing_profile_form.description') }}</span>
            <textarea
              v-model="profileForm.description"
              rows="5"
              maxlength="5000"
              class="mt-1 w-full rounded-md border border-border-input px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              :placeholder="t('listing_profile_form.what_your_business_does_who_it_serves_and')"
            />
            <span class="mt-1 block text-2xs text-ink-tertiary">
              {{ profileForm.description.length }} / 5000
            </span>
            <span v-if="profileForm.errors.description" class="mt-1 block text-2xs text-danger">
              {{ profileForm.errors.description }}
            </span>
          </label>

          <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
            <label class="block">
              <span class="text-xs font-semibold text-ink-secondary">{{ t('listing_profile_form.website') }}</span>
              <input
                v-model="profileForm.website"
                type="text"
                inputmode="url"
                :placeholder="t('listing_profile_form.example_com')"
                class="mt-1 w-full rounded-md border border-border-input px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              />
              <span v-if="profileForm.errors.website" class="mt-1 block text-2xs text-danger">
                {{ profileForm.errors.website }}
              </span>
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-ink-secondary">{{ t('listing_profile_form.contact_email') }}</span>
              <input
                v-model="profileForm.primary_contact_email"
                type="email"
                class="mt-1 w-full rounded-md border border-border-input px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              />
              <span v-if="profileForm.errors.primary_contact_email" class="mt-1 block text-2xs text-danger">
                {{ profileForm.errors.primary_contact_email }}
              </span>
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-ink-secondary">{{ t('listing_profile_form.phone') }}</span>
              <input
                v-model="profileForm.phone"
                type="tel"
                class="mt-1 w-full rounded-md border border-border-input px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              />
              <span v-if="profileForm.errors.phone" class="mt-1 block text-2xs text-danger">
                {{ profileForm.errors.phone }}
              </span>
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-ink-secondary">{{ t('listing_profile_form.company_size') }}</span>
              <input
                v-model.number="profileForm.staff_count"
                type="number"
                min="0"
                class="mt-1 w-full rounded-md border border-border-input px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
              />
              <span class="mt-1 block text-2xs text-ink-tertiary">
                {{ t('listing_profile_form.also_shown_as_the_core_staff_size_highligh') }}
              </span>
              <span v-if="profileForm.errors.staff_count" class="mt-1 block text-2xs text-danger">
                {{ profileForm.errors.staff_count }}
              </span>
            </label>
          </div>

          <button
            type="submit"
            class="btn-primary rounded-md px-6 py-2.5 text-xs font-semibold disabled:opacity-60"
            :data-active="profileForm.isDirty || undefined"
            :disabled="profileForm.processing"
          >
            {{ profileForm.processing ? t('common.saving') : t('listing_profile_form.save_details') }}
          </button>
        </form>
      </section>

      <!-- ================================================== addresses ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-display text-lg font-semibold text-ink">
              {{ t('listing_profile_form.addresses') }}
            </h2>
            <p class="mt-1 text-xs text-ink-secondary">
              {{ t('listing_profile_form.addresses_hint') }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-md border border-border px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-tertiary disabled:opacity-40"
            :disabled="addressesForm.addresses.length >= props.maxAddresses"
            @click="addAddress"
          >
            {{ t('listing_profile_form.add_an_address') }}
          </button>
        </div>

        <!-- What the card will fly. Recomputed as rows are added or removed, so
             the effect of a new country is visible before saving. -->
        <p v-if="previewCodes.length" class="mt-3 flex items-center gap-2 text-xs text-ink-secondary">
          {{ t('listing_profile_form.flags_shown_on_your_card') }}
          <UiCountryFlags :codes="previewCodes" :max="6" size="md" />
        </p>

        <p
          v-if="!addressesForm.addresses.length"
          class="mt-4 rounded-md bg-surface-secondary px-4 py-6 text-center text-xs text-ink-secondary"
        >
          {{ t('listing_profile_form.no_addresses_yet') }}
        </p>

        <ul v-else class="mt-4 space-y-3">
          <li
            v-for="(a, i) in addressesForm.addresses"
            :key="i"
            class="rounded-md border border-border-subtle bg-surface-secondary p-4"
          >
            <div class="flex items-start gap-3">
              <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <input
                    v-model="a.label"
                    type="text"
                    maxlength="80"
                    :placeholder="t('listing_profile_form.address_label')"
                    class="min-w-0 flex-1 rounded-md border border-border-input bg-white px-3 py-2 text-xs font-semibold text-ink focus:border-focus focus:outline-none"
                  />
                  <!-- Row one is the primary. Said plainly rather than left to
                       be inferred from the order. -->
                  <span
                    v-if="i === 0"
                    class="rounded-full bg-primary/10 px-2.5 py-1 text-2xs font-semibold text-primary"
                  >
                    {{ t('listing_profile_form.primary_address') }}
                  </span>
                </div>

                <input
                  v-model="a.address"
                  type="text"
                  maxlength="255"
                  :placeholder="t('listing_profile_form.street_address')"
                  class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
                />

                <div class="grid gap-2 sm:grid-cols-3 [&>*]:min-w-0">
                  <div>
                    <input
                      v-model="a.city"
                      type="text"
                      maxlength="120"
                      :placeholder="t('listing_profile_form.city')"
                      class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
                    />
                    <span v-if="addressesForm.errors[`addresses.${i}.city`]" class="mt-1 block text-2xs text-danger">
                      {{ addressesForm.errors[`addresses.${i}.city`] }}
                    </span>
                  </div>

                  <input
                    v-model="a.state"
                    type="text"
                    maxlength="120"
                    :placeholder="t('listing_profile_form.state_region')"
                    class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
                  />

                  <div>
                    <select
                      v-model="a.country"
                      class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
                    >
                      <option value="">{{ t('listing_profile_form.select_country') }}</option>
                      <option v-for="c in props.countries" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <span v-if="addressesForm.errors[`addresses.${i}.country`]" class="mt-1 block text-2xs text-danger">
                      {{ addressesForm.errors[`addresses.${i}.country`] }}
                    </span>
                  </div>
                </div>
              </div>

              <div class="flex shrink-0 flex-col gap-1">
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-ink disabled:opacity-40"
                  :disabled="i === 0"
                  :aria-label="t('listing_profile.move_up', { name: a.label || a.city || t('listing_profile_form.this_address') })"
                  @click="moveAddress(i, -1)"
                >
                  ↑
                </button>
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-ink disabled:opacity-40"
                  :disabled="i === addressesForm.addresses.length - 1"
                  :aria-label="t('listing_profile.move_down', { name: a.label || a.city || t('listing_profile_form.this_address') })"
                  @click="moveAddress(i, 1)"
                >
                  ↓
                </button>
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-danger"
                  :aria-label="t('listing_profile.remove_named', { name: a.label || a.city || t('listing_profile_form.this_address') })"
                  @click="removeAddress(i)"
                >
                  ✕
                </button>
              </div>
            </div>
          </li>
        </ul>

        <button
          type="button"
          class="btn-primary mt-4 rounded-md px-6 py-2.5 text-xs font-semibold disabled:opacity-60"
          :data-active="addressesForm.isDirty || undefined"
          :disabled="addressesForm.processing"
          @click="saveAddresses"
        >
          {{ addressesForm.processing ? t('common.saving') : t('listing_profile_form.save_addresses') }}
        </button>
      </section>

      <!-- =================================================== services ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.services') }}</h2>
            <p class="mt-1 text-xs text-ink-secondary">
              {{ t('listing_profile_form.listed_on_your_profile_in_this_order_and_c') }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-md border border-border px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-tertiary"
            @click="addService"
          >
            {{ t('listing_profile_form.add_a_service') }}
          </button>
        </div>

        <p v-if="!servicesForm.services.length" class="mt-4 rounded-md bg-surface-secondary px-4 py-6 text-center text-xs text-ink-secondary">
          {{ t('listing_profile_form.no_services_listed_yet_add_one_and_the_ser') }}
        </p>

        <ul v-else class="mt-4 space-y-3">
          <li
            v-for="(s, i) in servicesForm.services"
            :key="i"
            class="rounded-md border border-border-subtle bg-surface-secondary p-4"
          >
            <div class="flex items-start gap-3">
              <div class="min-w-0 flex-1 space-y-2">
                <input
                  v-model="s.title"
                  type="text"
                  maxlength="120"
                  :placeholder="t('listing_profile_form.service_name')"
                  class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs font-semibold text-ink focus:border-focus focus:outline-none"
                />
                <span v-if="servicesForm.errors[`services.${i}.title`]" class="block text-2xs text-danger">
                  {{ servicesForm.errors[`services.${i}.title`] }}
                </span>
                <textarea
                  v-model="s.body"
                  rows="2"
                  maxlength="1000"
                  :placeholder="t('listing_profile_form.what_this_service_covers_optional')"
                  class="w-full rounded-md border border-border-input bg-white px-3 py-2 text-xs text-ink focus:border-focus focus:outline-none"
                />
              </div>

              <div class="flex shrink-0 flex-col gap-1">
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-ink disabled:opacity-40"
                  :disabled="i === 0"
                  :aria-label="t('listing_profile.move_up', { name: s.title || t('listing_profile.a_service') })"
                  @click="moveService(i, -1)"
                >
                  ↑
                </button>
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-ink disabled:opacity-40"
                  :disabled="i === servicesForm.services.length - 1"
                  :aria-label="t('listing_profile.move_down', { name: s.title || t('listing_profile.a_service') })"
                  @click="moveService(i, 1)"
                >
                  ↓
                </button>
                <button
                  type="button"
                  class="rounded border border-border bg-white px-2 py-1 text-2xs text-danger"
                  :aria-label="t('listing_profile.remove_named', { name: s.title || t('listing_profile.a_service') })"
                  @click="removeService(i)"
                >
                  ✕
                </button>
              </div>
            </div>
          </li>
        </ul>

        <button
          type="button"
          class="btn-primary mt-4 rounded-md px-6 py-2.5 text-xs font-semibold disabled:opacity-60"
          :data-active="servicesForm.isDirty || undefined"
          :disabled="servicesForm.processing"
          @click="saveServices"
        >
          {{ servicesForm.processing ? t('common.saving') : t('listing_profile_form.save_services') }}
        </button>
      </section>

      <!-- ====================================================== hours ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.opening_hours') }}</h2>
        <p class="mt-1 text-xs text-ink-secondary">
          {{ t('listing_profile_form.days_left_as_not_specified_are_left_off_yo') }}
        </p>

        <ul class="mt-4 space-y-2">
          <li
            v-for="(h, i) in hoursForm.hours"
            :key="h.day_of_week"
            class="flex flex-wrap items-center gap-3 border-b border-border-subtle pb-2 last:border-b-0"
          >
            <span class="w-24 shrink-0 text-xs font-semibold text-ink">{{ h.day }}</span>

            <select
              v-model="h.state"
              class="max-w-full rounded-md border border-border-input px-2 py-1.5 text-xs text-ink focus:border-focus focus:outline-none"
              :aria-label="t('listing_profile.day_availability', { day: h.day })"
            >
              <option value="unset">{{ t('listing_profile_form.not_specified') }}</option>
              <option value="open">{{ t('listing_profile_form.open') }}</option>
              <option value="closed">{{ t('listing_profile_form.closed') }}</option>
            </select>

            <template v-if="h.state === 'open'">
              <input
                v-model="h.opens_at"
                type="time"
                class="rounded-md border border-border-input px-2 py-1.5 text-xs text-ink focus:border-focus focus:outline-none"
                :aria-label="t('listing_profile.day_opens', { day: h.day })"
              />
              <span class="text-xs text-ink-tertiary">{{ t('listing_profile_form.to') }}</span>
              <input
                v-model="h.closes_at"
                type="time"
                class="rounded-md border border-border-input px-2 py-1.5 text-xs text-ink focus:border-focus focus:outline-none"
                :aria-label="t('listing_profile.day_closes', { day: h.day })"
              />
            </template>

            <span v-if="hoursForm.errors[`hours.${i}.closes_at`]" class="text-2xs text-danger">
              {{ hoursForm.errors[`hours.${i}.closes_at`] }}
            </span>
            <span v-else-if="hoursForm.errors[`hours.${i}.opens_at`]" class="text-2xs text-danger">
              {{ hoursForm.errors[`hours.${i}.opens_at`] }}
            </span>
          </li>
        </ul>

        <button
          type="button"
          class="btn-primary mt-4 rounded-md px-6 py-2.5 text-xs font-semibold disabled:opacity-60"
          :data-active="hoursForm.isDirty || undefined"
          :disabled="hoursForm.processing"
          @click="saveHours"
        >
          {{ hoursForm.processing ? t('common.saving') : t('listing_profile_form.save_hours') }}
        </button>
      </section>

      <!-- ==================================================== gallery ===== -->
      <section class="rounded-lg border border-border bg-white p-5">
        <h2 class="font-display text-lg font-semibold text-ink">{{ t('listing_profile_form.media_gallery') }}</h2>
        <p class="mt-1 text-xs text-ink-secondary">
          {{ t('listing_profile.gallery_hint', { max: props.maxImagesPerCollection }) }}
        </p>

        <p v-if="uploadError" class="mt-3 rounded-md border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-xs text-danger" role="alert">
          {{ uploadError }}
        </p>

        <div class="mt-4 space-y-5">
          <div v-for="tab in props.gallery" :key="tab.collection">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h3 class="font-sans text-xs font-semibold text-ink">
                {{ tab.label }}
                <span class="font-normal text-ink-tertiary">
                  ({{ tab.images.length }}/{{ props.maxImagesPerCollection }})
                </span>
              </h3>

              <label
                class="cursor-pointer rounded-md border border-border px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-tertiary"
                :class="tab.images.length >= props.maxImagesPerCollection ? 'pointer-events-none opacity-40' : ''"
              >
                {{ uploadingTo === tab.collection ? t('documents.uploading') : t('listing_profile_form.add_photo') }}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  class="sr-only"
                  :disabled="uploadingTo !== null || tab.images.length >= props.maxImagesPerCollection"
                  @change="uploadImage(tab.collection, $event)"
                />
              </label>
            </div>

            <p v-if="!tab.images.length" class="mt-2 text-2xs text-ink-tertiary">
              {{ t('listing_profile_form.no_photos_in_this_tab_yet') }}
            </p>

            <ul v-else class="mt-2 grid gap-3 sm:grid-cols-3 lg:grid-cols-5 [&>*]:min-w-0">
              <li v-for="img in tab.images" :key="img.id" class="group relative">
                <img :src="img.thumb" :alt="img.name" class="h-24 w-full rounded-md object-cover" />
                <button
                  type="button"
                  class="absolute top-1 right-1 rounded bg-black/60 px-1.5 py-0.5 text-2xs text-white opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                  :aria-label="t('listing_profile.remove_named', { name: img.name })"
                  @click="removeImage(img.id)"
                >
                  ✕
                </button>
              </li>
            </ul>
          </div>
        </div>
      </section>
  </div>
</template>
