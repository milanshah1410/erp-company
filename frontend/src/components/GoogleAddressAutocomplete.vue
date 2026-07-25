<script setup lang="ts">
/**
 * Reusable Google Places address autocomplete input.
 *
 * All Google calls go through the backend proxy (/google/place-autocomplete,
 * /google/place-details) so the API key never reaches the browser — no Google
 * JS SDK is loaded at all. Suggestions are debounced, selected place details
 * are cached client-side, and an autocomplete session token groups the
 * suggestion + details calls for Google billing.
 */
import { ref, watch, onBeforeUnmount } from 'vue'
import InputText from 'primevue/inputtext'
import http from '../api/http'

export interface PlaceResult {
  place_id: string
  formatted_address: string
  country: string
  state: string
  city: string
  area: string
  postal_code: string
  latitude: number | null
  longitude: number | null
}

interface Suggestion {
  place_id: string
  description: string
  main_text: string
  secondary_text: string
}

const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    countryRestriction?: string
    disabled?: boolean
    readonly?: boolean
    invalid?: boolean
    inputId?: string
  }>(),
  {
    placeholder: 'Start typing an address...',
    countryRestriction: '',
    disabled: false,
    readonly: false,
    invalid: false,
    inputId: undefined,
  },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'place-selected', place: PlaceResult): void
}>()

// Module-level cache: selected place details survive re-opens of the modal.
const detailsCache: Map<string, PlaceResult> = (window as any).__gplaceCache ?? new Map()
;(window as any).__gplaceCache = detailsCache

const open = ref(false)
const loading = ref(false)
const detailsLoading = ref(false)
const suggestions = ref<Suggestion[]>([])
const highlighted = ref(-1)
const errorMessage = ref('')
const root = ref<HTMLElement | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSeq = 0
// Google Places billing session: one token for a burst of keystrokes + the
// place-details call that ends it.
let sessionToken: string | null = null

function newSessionToken(): string {
  if (!sessionToken) {
    sessionToken =
      typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`
  }
  return sessionToken
}

function onInput(e: Event) {
  const value = (e.target as HTMLInputElement).value
  emit('update:modelValue', value)
  errorMessage.value = ''
  if (debounceTimer) clearTimeout(debounceTimer)
  if (value.trim().length < 3) {
    suggestions.value = []
    open.value = false
    loading.value = false
    return
  }
  debounceTimer = setTimeout(() => fetchSuggestions(value.trim()), 350)
}

async function fetchSuggestions(input: string) {
  const seq = ++requestSeq
  loading.value = true
  open.value = true
  try {
    const { data } = await http.get('/google/place-autocomplete', {
      params: {
        input,
        ...(props.countryRestriction ? { country: props.countryRestriction } : {}),
        session_token: newSessionToken(),
      },
    })
    if (seq !== requestSeq) return // a newer request superseded this one
    suggestions.value = data.data ?? []
    highlighted.value = suggestions.value.length ? 0 : -1
    errorMessage.value = suggestions.value.length ? '' : 'No results found.'
  } catch (e: any) {
    if (seq !== requestSeq) return
    suggestions.value = []
    highlighted.value = -1
    errorMessage.value =
      e.response?.data?.message ||
      (e.request && !e.response ? 'No internet connection.' : 'Address lookup is unavailable right now.')
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

async function select(s: Suggestion) {
  open.value = false
  suggestions.value = []
  emit('update:modelValue', s.description)

  const cached = detailsCache.get(s.place_id)
  if (cached) {
    sessionToken = null
    emit('place-selected', cached)
    return
  }

  detailsLoading.value = true
  errorMessage.value = ''
  try {
    const { data } = await http.get('/google/place-details', {
      params: { place_id: s.place_id, ...(sessionToken ? { session_token: sessionToken } : {}) },
    })
    const place: PlaceResult = data.data
    detailsCache.set(s.place_id, place)
    emit('place-selected', place)
  } catch (e: any) {
    errorMessage.value =
      e.response?.data?.message ||
      (e.request && !e.response ? 'No internet connection.' : 'Could not load details for this address.')
    open.value = true // keep the panel visible so the error is seen
  } finally {
    detailsLoading.value = false
    sessionToken = null // details call closes the billing session
  }
}

function onKeydown(e: KeyboardEvent) {
  if (!open.value || !suggestions.value.length) {
    if (e.key === 'Escape') open.value = false
    return
  }
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    highlighted.value = (highlighted.value + 1) % suggestions.value.length
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlighted.value = (highlighted.value - 1 + suggestions.value.length) % suggestions.value.length
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (highlighted.value >= 0) select(suggestions.value[highlighted.value])
  } else if (e.key === 'Escape') {
    open.value = false
  }
}

function onDocumentClick(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
document.addEventListener('click', onDocumentClick)
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  if (debounceTimer) clearTimeout(debounceTimer)
})

// Parent cleared/replaced the value programmatically → close the panel.
watch(
  () => props.modelValue,
  () => {
    if (!props.modelValue) {
      open.value = false
      suggestions.value = []
    }
  },
)
</script>

<template>
  <div ref="root" class="gaa-root">
    <span class="gaa-input-wrap">
      <InputText
        :id="inputId"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :class="{ invalid: invalid }"
        autocomplete="off"
        @input="onInput"
        @keydown="onKeydown"
        @focus="suggestions.length && (open = true)"
      />
      <i v-if="loading || detailsLoading" class="pi pi-spinner pi-spin gaa-spinner" />
    </span>

    <div v-if="open" class="gaa-panel">
      <div v-if="loading" class="gaa-status">Searching...</div>
      <template v-else-if="suggestions.length">
        <div
          v-for="(s, i) in suggestions"
          :key="s.place_id"
          class="gaa-item"
          :class="{ active: i === highlighted }"
          @mousedown.prevent="select(s)"
          @mousemove="highlighted = i"
        >
          <i class="pi pi-map-marker" />
          <span class="gaa-item-text">
            <span class="gaa-main">{{ s.main_text }}</span>
            <span class="gaa-secondary">{{ s.secondary_text }}</span>
          </span>
        </div>
        <div class="gaa-attribution">powered by Google</div>
      </template>
      <div v-else-if="errorMessage" class="gaa-status gaa-error">{{ errorMessage }}</div>
    </div>
    <small v-if="!open && errorMessage" class="gaa-error gaa-error-line">{{ errorMessage }}</small>
  </div>
</template>

<style scoped>
.gaa-root {
  position: relative;
  width: 100%;
}
.gaa-input-wrap {
  position: relative;
  display: block;
}
.gaa-input-wrap :deep(.p-inputtext) {
  width: 100%;
  padding-right: 2rem;
}
.gaa-spinner {
  position: absolute;
  right: 0.65rem;
  top: 50%;
  transform: translateY(-50%);
  color: #6b7280;
  font-size: 0.85rem;
}
.gaa-panel {
  position: absolute;
  z-index: 1200;
  top: calc(100% + 2px);
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
  max-height: 260px;
  overflow-y: auto;
}
.gaa-item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.55rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
}
.gaa-item i {
  color: #9ca3af;
  margin-top: 2px;
  font-size: 0.8rem;
}
.gaa-item.active {
  background: #f3f4f6;
}
.gaa-item-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.gaa-main {
  font-weight: 500;
  color: #111827;
}
.gaa-secondary {
  color: #6b7280;
  font-size: 0.78rem;
}
.gaa-status {
  padding: 0.6rem 0.75rem;
  color: #6b7280;
  font-size: 0.85rem;
}
.gaa-error {
  color: #dc2626;
}
.gaa-error-line {
  font-size: 0.78rem;
}
.gaa-attribution {
  padding: 0.3rem 0.75rem;
  text-align: right;
  color: #9ca3af;
  font-size: 0.68rem;
  border-top: 1px solid #f3f4f6;
}
.invalid:deep(.p-inputtext),
:deep(.p-inputtext.invalid) {
  border-color: #dc2626 !important;
}
</style>
