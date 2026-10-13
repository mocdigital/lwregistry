<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Quill from 'quill'
import 'quill/dist/quill.snow.css'
import { useI18n } from '@/composables/useI18n'

const { t } = useI18n()

/*
 * The rich-text field for a procurement description and its terms.
 *
 * Quill, in its Snow theme. BSD-3-Clause, one package, and its toolbar comes
 * assembled — which is most of why it is here rather than something that needs
 * a toolbar building around it.
 *
 * What is typed here is NOT what is trusted. App\Support\RichText sanitises on
 * the server before anything is stored, because an editor limits what a person
 * can TYPE and not what can be posted to the endpoint — and this text is
 * written by one business and read by every other.
 *
 * That boundary matters more than usual with this editor: quill@2.0.3 carries
 * a low-severity XSS advisory against its HTML EXPORT (GHSA-v3m3-f69x-jf25),
 * with no patched release. `quill.root.innerHTML` is read here rather than
 * `getSemanticHTML()`, which is the affected call, and the server sanitises
 * whatever comes out either way.
 */
const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    minHeight?: string
    /*
     * The editor is a contenteditable div, which is not a labelable control —
     * a wrapping <label> does not name it and, worse, hijacks toolbar clicks
     * (a click on it re-targets the editor and closes the just-opened format
     * dropdown). So the field is named here instead, for screen readers.
     */
    ariaLabel?: string
    /** A cap on the plain-text length; typing past it is prevented, with a counter. */
    maxLength?: number
  }>(),
  { placeholder: '', minHeight: '180px', ariaLabel: '', maxLength: 0 },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

// The plain-text length, for the counter and the cap.
const length = ref(0)

const host = ref<HTMLElement | null>(null)
let quill: Quill | null = null

/*
 * The toolbar. Deliberately short of what Quill can do: images, video, colours
 * and code blocks are all things the server strips, and offering a control
 * whose output does not survive saving is worse than not offering it.
 */
const toolbar = [
  [{ header: [3, 4, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  [{ indent: '-1' }, { indent: '+1' }],
  [{ align: [] }],
  ['blockquote', 'link'],
  ['clean'],
]

/** Quill leaves an empty document as "<p><br></p>", which is not content. */
function readValue(): string {
  const html = quill?.root.innerHTML ?? ''

  return '<p><br></p>' === html ? '' : html
}

onMounted(() => {
  if (!host.value) return

  quill = new Quill(host.value, {
    theme: 'snow',
    placeholder: props.placeholder,
    modules: {
      toolbar,
      /*
       * Paste as plain text is not enough — a pasted list should stay a list —
       * but Quill's clipboard already maps incoming markup onto the formats it
       * knows, so a page of Word's inline styles arrives as the handful of
       * things this toolbar can produce.
       */
      keyboard: { bindings: {} },
    },
  })

  if (props.ariaLabel) {
    quill.root.setAttribute('aria-label', props.ariaLabel)
  }

  if (props.modelValue) {
    quill.clipboard.dangerouslyPasteHTML(props.modelValue, 'silent')
  }

  quill.on('text-change', () => {
    // Quill counts a trailing newline in its length; the real text is one less.
    if (props.maxLength > 0 && quill) {
      const plain = quill.getLength() - 1

      if (plain > props.maxLength) {
        quill.deleteText(props.maxLength, plain - props.maxLength)
      }
    }

    length.value = quill ? Math.max(0, quill.getLength() - 1) : 0
    emit('update:modelValue', readValue())
  })

  length.value = Math.max(0, quill.getLength() - 1)
})

/*
 * Written in only when it differs from what the editor already holds. Pasting
 * on every keystroke would rebuild the document and move the caret to the end.
 */
watch(
  () => props.modelValue,
  (value) => {
    if (!quill || readValue() === value) return

    const selection = quill.getSelection()
    quill.clipboard.dangerouslyPasteHTML(value ?? '', 'silent')

    // Put the caret back where it was, if it was anywhere.
    if (selection) void nextTick(() => quill?.setSelection(selection))
  },
)

onBeforeUnmount(() => {
  quill = null
})
</script>

<template>
  <div>
    <div class="ui-quill overflow-hidden rounded-lg border border-[#e2e8f0] bg-white focus-within:border-focus">
      <div ref="host" :style="{ minHeight: props.minHeight }" />
    </div>
    <p
      v-if="props.maxLength > 0"
      class="mt-1 text-right text-2xs"
      :class="length >= props.maxLength ? 'text-danger' : 'text-[#94a3b8]'"
    >
      {{ length }} / {{ props.maxLength }}
    </p>
  </div>
</template>

<style scoped>
/*
 * Snow ships its own borders and font stack. These reconcile it with the rest
 * of the form: the field owns the outer border, so the theme's is removed, and
 * the type matches every other input on the page rather than Quill's default.
 */
.ui-quill :deep(.ql-toolbar.ql-snow) {
  border: 0;
  border-bottom: 1px solid #e2e8f0;
  padding: 0.5rem;
}

.ui-quill :deep(.ql-container.ql-snow) {
  border: 0;
  font-family: inherit;
  font-size: 1rem;
}

.ui-quill :deep(.ql-editor) {
  padding: 1rem;
  color: #0f172a;
}

.ui-quill :deep(.ql-editor.ql-blank::before) {
  color: #94a3b8;
  font-style: normal;
  left: 1rem;
  right: 1rem;
}

/*
 * Quill 2 writes a bulleted list as <ol><li data-list="bullet">, and draws the
 * marker itself through a pseudo-element. Under the CSS reset the numbers on a
 * genuinely ordered list disappear, so they are put back here.
 */
.ui-quill :deep(.ql-editor ol) {
  padding-left: 0;
}

.ui-quill :deep(.ql-editor blockquote) {
  border-left: 2px solid #e2e8f0;
  padding-left: 0.75rem;
  color: #475569;
}

.ui-quill :deep(.ql-editor a) {
  color: var(--color-primary);
  text-decoration: underline;
}
</style>
