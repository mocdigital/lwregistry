<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link as RouterLink, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import HeroVideo from '@/components/shell/HeroVideo.vue'
import MarketingRail from '@/components/shell/MarketingRail.vue'
import { useI18n } from '@/composables/useI18n'

const { t, tc } = useI18n()

/*
 * Manage Documents.
 *
 * ADDITION: no Figma frame — the profile's Quick Actions card promised "Upload
 * validation certificates" and linked to /dashboard, so the screen it named was
 * never drawn. Built from the profile dashboard's idiom.
 *
 * Nothing new is modelled. It reads the same compliance requirements the
 * application's document step uses and posts to the same endpoint, so a file
 * added here is the file the reviewer sees there. What it adds is a way back:
 * documents were only reachable from inside the application flow, so an owner
 * whose certificate expired after approval had nowhere to go.
 */

defineOptions({ layout: AppLayout })

type Document = {
  typeId: number
  name: string
  description: string | null
  required: boolean
  status: 'pending' | 'approved' | 'rejected' | null
  rejectionReason: string | null
  fileName: string | null
  uploadedAt: string | null
  downloadUrl: string | null
}

const props = defineProps<{
  sbos: { id: number; name: string; outstanding: number }[]
  selected: {
    id: number
    name: string
    documentsStatus: string | null
    verificationStatus: string | null
  } | null
  documents: Document[]
  uploadUrl: string
}>()

const statusTone: Record<string, string> = {
  approved: 'bg-[#d1fae5] text-[#065f46]',
  pending: 'bg-[#fef3c7] text-[#b45309]',
  rejected: 'bg-[#fee2e2] text-[#991b1b]',
}

const statusLabel: Record<string, string> = {
  approved: t('data.status.approved'),
  pending: t('application.in_review'),
  rejected: t('data.status.rejected'),
}

const outstanding = computed(() =>
  props.documents.filter((d) => d.required && d.status !== 'approved').length,
)

/* ------------------------------------------------------------- upload --- */

const form = useForm<{ sbo_id: number | null; compliance_document_type_id: number | null; document: File | null }>({
  sbo_id: props.selected?.id ?? null,
  compliance_document_type_id: null,
  document: null,
})

// One input per row, so picking a file for one document cannot land on another.
const inputs = ref<Record<number, HTMLInputElement | null>>({})
const uploadingTypeId = ref<number | null>(null)

function choose(typeId: number) {
  inputs.value[typeId]?.click()
}

function onPicked(event: Event, typeId: number) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !props.selected) return

  uploadingTypeId.value = typeId
  form.sbo_id = props.selected.id
  form.compliance_document_type_id = typeId
  form.document = file

  form.post(props.uploadUrl, {
    preserveScroll: true,
    onFinish: () => {
      uploadingTypeId.value = null
      form.reset()
      const input = inputs.value[typeId]
      // The same file cannot be picked twice otherwise.
      if (input) input.value = ''
    },
  })
}
</script>

<template>
  <section class="relative overflow-hidden bg-[#0b192c]">
    <HeroVideo overlay="bg-[#0b192c]/72" />

    <div class="relative flex flex-col gap-6 section-x pt-12 pb-14">
      <nav class="flex items-center gap-2 text-xs" :aria-label="t('documents.breadcrumb')">
        <RouterLink href="/profile" class="text-[#94a3b8] hover:text-white">
          {{ t('documents.profile_dashboard') }}
        </RouterLink>
        <span class="text-[#94a3b8]" aria-hidden="true">/</span>
        <span class="text-white">{{ t('documents.documents') }}</span>
      </nav>

      <div class="flex flex-col gap-2">
        <h1 class="font-serif text-3xl font-bold text-white">{{ t('documents.validation_documents') }}</h1>
        <p class="text-sm text-[#94a3b8]">
          The certificates the registry needs to verify
          {{ selected ? selected.name : t('documents.your_business') }}.
        </p>
      </div>

      <label v-if="sbos.length > 1" class="flex items-center gap-2 text-xs text-[#94a3b8]">
        <span class="sr-only">{{ t('documents.listing') }}</span>
        <select
          :value="selected?.id"
          class="max-w-full rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs text-white focus:outline-none"
          @change="
            $inertia.get('/account/documents', {
              sbo: ($event.target as HTMLSelectElement).value,
            })
          "
        >
          <option v-for="s in sbos" :key="s.id" :value="s.id" class="text-ink">
            {{ s.name }}<template v-if="s.outstanding"> {{ t('documents.outstanding_count', { count: s.outstanding }) }}</template>
          </option>
        </select>
      </label>
    </div>
  </section>

  <div class="bg-surface-secondary section-x py-8">
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_288px] [&>*]:min-w-0">
      <div class="flex min-w-0 flex-col gap-6">
        <div
          v-if="selected"
          class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-white p-5"
        >
          <p class="text-sm text-ink-secondary">
            <template v-if="outstanding === 0">
              {{ t('documents.every_required_document_has_been_approved') }}
            </template>
            <template v-else>
              <strong class="text-ink">{{ outstanding }}</strong>
              {{ tc('documents.required_outstanding', outstanding) }}
            </template>
          </p>
          <RouterLink
            href="/application/documents"
            class="text-xs font-semibold text-primary hover:underline"
          >
            {{ t('documents.view_application_progress') }}
          </RouterLink>
        </div>

        <ul v-if="documents.length" class="flex flex-col gap-4">
          <li
            v-for="d in documents"
            :key="d.typeId"
            class="flex flex-col gap-4 rounded-2xl border border-border bg-white p-6"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h2 class="font-display text-base font-semibold text-ink">{{ d.name }}</h2>
                  <span
                    v-if="d.required"
                    class="rounded-full bg-surface-tertiary px-2 py-0.5 text-2xs font-semibold text-ink-secondary uppercase"
                  >
                    {{ t('documents.required') }}
                  </span>
                </div>
                <p v-if="d.description" class="text-xs text-ink-secondary">
                  {{ d.description }}
                </p>
              </div>

              <span
                v-if="d.status"
                class="shrink-0 rounded-full px-2.5 py-1 text-2xs font-semibold"
                :class="statusTone[d.status]"
              >
                {{ statusLabel[d.status] }}
              </span>
              <span v-else class="shrink-0 text-2xs font-semibold text-ink-tertiary">
                {{ t('documents.not_uploaded') }}
              </span>
            </div>

            <!-- A rejection is only useful with its reason attached. -->
            <p
              v-if="d.status === 'rejected' && d.rejectionReason"
              class="rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-xs text-[#991b1b]"
            >
              {{ d.rejectionReason }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
              <a
                v-if="d.downloadUrl"
                :href="d.downloadUrl"
                class="min-w-0 truncate text-xs font-medium text-primary hover:underline"
              >
                {{ d.fileName }}
              </a>
              <span v-if="d.uploadedAt" class="text-xs text-ink-tertiary">
                {{ t('documents.uploaded_on', { date: d.uploadedAt }) }}
              </span>

              <input
                :ref="(el) => (inputs[d.typeId] = el as HTMLInputElement | null)"
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                class="hidden"
                @change="onPicked($event, d.typeId)"
              />
              <button
                type="button"
                :disabled="form.processing"
                class="ml-auto rounded-lg btn-primary px-4 py-2 text-xs font-semibold text-white disabled:opacity-60"
                @click="choose(d.typeId)"
              >
                <template v-if="uploadingTypeId === d.typeId">{{ t('documents.uploading') }}</template>
                <template v-else>{{ d.status ? t('listing_profile_form.replace') : t('action.upload') }}</template>
              </button>
            </div>

            <p v-if="d.status === 'rejected'" class="text-2xs text-ink-tertiary">
              {{ t('documents.uploading_again_puts_this_document_back_in') }}
            </p>
          </li>
        </ul>

        <p v-else class="rounded-2xl border border-border bg-white p-5 sm:p-8 text-sm text-ink-secondary">
          {{
            selected
              ? t('documents.no_documents_are_required_for_this_listing')
              : t('documents.register_a_business_to_upload_validation_d')
          }}
        </p>

        <p v-if="form.errors.document" class="text-xs text-danger">
          {{ form.errors.document }}
        </p>
      </div>

      <aside class="flex flex-col gap-5">
        <section class="rounded-2xl border border-border bg-white p-5">
          <h2 class="font-sans text-xs font-semibold text-ink">{{ t('documents.what_happens_next') }}</h2>
          <p class="mt-2 text-2xs text-ink-secondary">
            {{ t('documents.a_reviewer_checks_each_document_and_either') }}
          </p>
          <RouterLink href="/support" class="mt-3 block text-2xs font-semibold text-primary">
            {{ t('documents.contact_support') }}
          </RouterLink>
        </section>

        <MarketingRail />
      </aside>
    </div>
  </div>
</template>
