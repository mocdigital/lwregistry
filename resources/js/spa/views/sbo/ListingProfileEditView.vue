<script setup lang="ts">
import { Link as RouterLink } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import ListingProfileForm from '@/components/sbo/ListingProfileForm.vue'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

defineOptions({ layout: AppLayout })

/*
 * Edit my listing — the standalone screen, reached from the pencil on
 * /account.
 *
 * The forms themselves live in ListingProfileForm so this page and the About
 * tab of /settings cannot drift apart. All this adds is the page chrome.
 */

const props = defineProps<{
  sbo: {
    id: number
    slug: string
    locked: { label: string; value: string | null }[]
    description: string | null
    website: string | null
    primary_contact_email: string | null
    phone: string | null
    staff_count: number | null
  }
  branding: { logoUrl: string | null; coverUrl: string | null }
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
</script>

<template>
  <div class="bg-surface-secondary">
    <div class="container-page space-y-6 py-8">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 class="font-serif text-3xl font-bold text-ink">{{ t('listing_profile_edit.edit_my_listing') }}</h1>
          <p class="mt-1 text-sm text-ink-secondary">
            {{ t('listing_profile_edit.everything_here_appears_on_your_public_pro') }}
          </p>
        </div>
        <RouterLink
          :href="`/business/${props.sbo.slug}`"
          class="rounded-md border border-border px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-tertiary"
        >
          {{ t('listing_profile_edit.view_public_profile') }}
        </RouterLink>
      </header>

      <p
        v-if="$page.props.flash?.status?.startsWith('listing-')"
        class="rounded-md border border-[#a7f3d0] bg-[#f0fdf4] px-4 py-3 text-xs font-semibold text-[#15803d]"
        role="status"
      >
        {{ t('listing_profile_edit.saved_your_public_profile_is_up_to_date') }}
      </p>

      <ListingProfileForm
        :sbo="props.sbo"
        :branding="props.branding"
        :addresses="props.addresses"
        :countries="props.countries"
        :max-addresses="props.maxAddresses"
        :services="props.services"
        :hours="props.hours"
        :gallery="props.gallery"
        :max-images-per-collection="props.maxImagesPerCollection"
      />
    </div>
  </div>
</template>
