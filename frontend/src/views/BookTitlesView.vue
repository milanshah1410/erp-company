<script setup lang="ts">
/**
 * The catalogue, read the way a bookshop reads it.
 *
 * There is no titles table behind this screen and there should not be — a title
 * is a product, and making it anything else would cost the vertical stock,
 * purchasing, pricing, the till and the label printer. What this screen adds is
 * the *index*: the same rows found by the things a bookshop actually searches on
 * (a number off the back cover, a house, a language, somebody on the front)
 * rather than by SKU.
 *
 * Two controls here do the work of the whole vertical:
 *
 *  - **The ISBN box.** Type or scan a number in any form — with hyphens, without,
 *    ten digits or thirteen — and it says whether the shop has the book. It
 *    answers when the shop does *not*, too, because the useful reply includes
 *    whether the number was even valid: half the time it was misread and the book
 *    is on the shelf.
 *  - **Credits.** Who is on the cover, and in what role. A translated novel has
 *    an author and a translator; a picture book has one person twice. Everything
 *    else on this screen is edited on the Products screen like any other article
 *    — only the credits belong to this module.
 *
 * The one thing worth noticing in the table is the small warning beside an ISBN:
 * it means the article's barcode is no longer the ISBN, so the scanner at the
 * till and this catalogue have quietly stopped agreeing. Nothing looks wrong on
 * the shelf, and the first symptom is a customer being told a book is out of
 * stock while it is in their hand.
 */
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

const canEdit = computed(() => auth.can('book-catalogue.edit'))

// ---- The catalogue --------------------------------------------------------

const rows = ref<any[]>([])
const total = ref(0)
const first = ref(0)
const perPage = 25
const loading = ref(false)

const search = ref('')
const publisherId = ref<number | null>(null)
const languageId = ref<number | null>(null)
const authorId = ref<number | null>(null)
const binding = ref<string | null>(null)

/** Sent by the list endpoint so the words here and on the server never drift. */
const bindings = ref<Record<string, string>>({})
const roles = ref<Record<string, string>>({})

const bindingOptions = computed(() =>
  Object.entries(bindings.value).map(([value, label]) => ({ label, value })),
)
const roleOptions = computed(() =>
  Object.entries(roles.value).map(([value, label]) => ({ label, value })),
)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/books/titles', {
      params: {
        page,
        per_page: perPage,
        search: search.value || undefined,
        publisher_id: publisherId.value ?? undefined,
        language_id: languageId.value ?? undefined,
        author_id: authorId.value ?? undefined,
        binding: binding.value ?? undefined,
      },
    })
    rows.value = data.data ?? []
    total.value = data.total ?? rows.value.length
    bindings.value = data.bindings ?? bindings.value
    roles.value = data.roles ?? roles.value
  } finally {
    loading.value = false
  }
}

/**
 * Server-side, not a client paginator over the first hundred rows: a shop of any
 * age has thousands of titles, and a screen that silently showed the first page
 * as though it were everything would be worse than one that made the shop search.
 */
function onPage(event: { first: number; page: number }) {
  first.value = event.first
  load(event.page + 1)
}

function applyFilters() {
  first.value = 0
  load(1)
}

function resetFilters() {
  search.value = ''
  publisherId.value = null
  languageId.value = null
  authorId.value = null
  binding.value = null
  applyFilters()
}

// ---- Pickers --------------------------------------------------------------

const publishers = ref<any[]>([])
const languages = ref<any[]>([])
const authors = ref<any[]>([])

async function loadPickers() {
  const [p, l, a] = await Promise.all([
    http.get('/books/publishers', { params: { per_page: 100, is_active: 1 } }),
    http.get('/books/languages', { params: { per_page: 100, is_active: 1 } }),
    http.get('/books/authors', { params: { per_page: 100, is_active: 1 } }),
  ])
  publishers.value = (p.data.data ?? []).map((x: any) => ({ label: x.display_name, value: x.id }))
  languages.value = (l.data.data ?? []).map((x: any) => ({ label: x.display, value: x.id }))
  authors.value = (a.data.data ?? []).map((x: any) => ({ label: x.shelf_name, value: x.id }))
}

// ---- The ISBN box ---------------------------------------------------------

const lookupTerm = ref('')
const lookingUp = ref(false)
const lookupResult = ref<any | null>(null)

async function lookup() {
  const term = lookupTerm.value.trim()
  if (!term) {
    lookupResult.value = null
    return
  }

  lookingUp.value = true
  try {
    const { data } = await http.get('/books/lookup', { params: { isbn: term } })
    lookupResult.value = data
  } finally {
    lookingUp.value = false
  }
}

/** Drop the found title into the list below, so the row can be worked on. */
function showFound() {
  if (!lookupResult.value?.isbn13) return
  search.value = lookupResult.value.isbn13
  applyFilters()
}

// ---- Credits --------------------------------------------------------------

const creditsDialog = ref(false)
const creditsSaving = ref(false)
const creditsFor = ref<any | null>(null)
const creditRows = ref<{ book_author_id: number | null; role: string }[]>([])
const errors = ref<Record<string, string[]>>({})

function openCredits(row: any) {
  errors.value = {}
  creditsFor.value = row
  creditRows.value = (row.contributors ?? []).map((c: any) => ({
    book_author_id: c.book_author_id,
    role: c.role,
  }))
  creditsDialog.value = true
}

function addCredit() {
  creditRows.value.push({ book_author_id: null, role: 'author' })
}

function removeCredit(index: number) {
  creditRows.value.splice(index, 1)
}

async function saveCredits() {
  if (!creditsFor.value) return

  creditsSaving.value = true
  errors.value = {}
  try {
    /**
     * Sent as the whole set, in the order shown — which is the billing on the
     * cover. Rows with nobody chosen are dropped rather than refused: an empty
     * row is somebody having pressed Add and changed their mind, not a mistake
     * worth stopping a save for.
     */
    const payload = creditRows.value
      .filter((c) => c.book_author_id)
      .map((c, index) => ({ ...c, sort_order: index }))

    const { data } = await http.put(
      `/books/titles/${creditsFor.value.id}/contributors`,
      { contributors: payload },
    )

    // Patch the row in place rather than reloading the page — the shop is often
    // working down a carton and losing its position is the whole cost here.
    const index = rows.value.findIndex((r) => r.id === data.data.id)
    if (index >= 0) rows.value[index] = data.data

    creditsDialog.value = false
    toast.add({ severity: 'success', summary: pt('Credits saved'), life: 2500 })
  } catch (e: any) {
    errors.value = e.response?.data?.errors ?? {}
    toast.add({
      severity: 'error',
      summary: e.response?.data?.message ?? pt('Could not save the credits'),
      life: 4000,
    })
  } finally {
    creditsSaving.value = false
  }
}

/** "Dhruv Bhatt (Author), Harshad Trivedi (Translator)" */
function creditLine(row: any): string {
  return (row.contributors ?? [])
    .map((c: any) => `${c.name} (${c.role_label})`)
    .join(', ')
}

function money(value: number | null) {
  return value === null || value === undefined ? '—' : `₹${Number(value).toFixed(2)}`
}

onMounted(() => {
  load()
  loadPickers()
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Book Titles')"
      :subtitle="pt('The catalogue by ISBN, house, language and the people on the cover')"
    />

    <!-- ================= The ISBN box ================= -->
    <div class="lookup card">
      <div class="lookup-row">
        <span class="p-input-icon-left lookup-input">
          <InputText
            v-model="lookupTerm"
            class="w-full mono"
            :placeholder="pt('Scan or type an ISBN — 978-93-5136-557-0')"
            @keyup.enter="lookup"
          />
        </span>
        <Button icon="pi pi-search" :label="pt('Look up')" :loading="lookingUp" @click="lookup" />
        <Button
          v-if="lookupResult"
          icon="pi pi-times"
          text
          :label="pt('Clear')"
          @click="() => { lookupResult = null; lookupTerm = '' }"
        />
      </div>

      <!-- Not a valid ISBN at all: the number was misread, and saying so is more
           useful than "not found" — the book is very often on the shelf. -->
      <Message v-if="lookupResult && !lookupResult.valid" severity="warn" :closable="false">
        {{ lookupResult.message }}
      </Message>

      <!-- Valid, and in the catalogue. -->
      <div v-else-if="lookupResult?.data" class="found">
        <div>
          <div class="strong">{{ lookupResult.data.name }}</div>
          <small class="muted">
            {{ creditLine(lookupResult.data) || pt('No credits recorded') }}
          </small>
          <div class="found-meta">
            <span class="mono">{{ lookupResult.data.isbn13 }}</span>
            <span v-if="lookupResult.data.publisher">· {{ lookupResult.data.publisher }}</span>
            <span v-if="lookupResult.data.language">· {{ lookupResult.data.language }}</span>
            <span v-if="lookupResult.data.binding_label">· {{ lookupResult.data.binding_label }}</span>
          </div>
        </div>
        <div class="found-right">
          <Tag
            :value="`${pt('In stock')}: ${lookupResult.data.stock_quantity}`"
            :severity="lookupResult.data.stock_quantity > 0 ? 'success' : 'danger'"
          />
          <div class="strong">{{ money(lookupResult.data.selling_price) }}</div>
          <Button text :label="pt('Show in list')" icon="pi pi-arrow-down" @click="showFound" />
        </div>
      </div>

      <!-- Valid, but nobody has catalogued it. The normalised forms come back
           so receiving can paste the right one onto a new article. -->
      <Message v-else-if="lookupResult?.valid" severity="info" :closable="false">
        {{ lookupResult.message }}
        <span class="mono">
          ISBN-13 {{ lookupResult.isbn13 }}<span v-if="lookupResult.isbn10"> · ISBN-10 {{ lookupResult.isbn10 }}</span>
        </span>
      </Message>
    </div>

    <!-- ================= Filters ================= -->
    <div class="toolbar">
      <InputText
        v-model="search"
        :placeholder="pt('Title, ISBN, series or SKU')"
        class="grow"
        @keyup.enter="applyFilters"
      />
      <Select
        v-model="publisherId"
        :options="publishers"
        option-label="label"
        option-value="value"
        show-clear
        filter
        :placeholder="pt('Publisher')"
        @change="applyFilters"
      />
      <Select
        v-model="languageId"
        :options="languages"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Language')"
        @change="applyFilters"
      />
      <Select
        v-model="authorId"
        :options="authors"
        option-label="label"
        option-value="value"
        show-clear
        filter
        :placeholder="pt('Author or translator')"
        @change="applyFilters"
      />
      <Select
        v-model="binding"
        :options="bindingOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="pt('Binding')"
        @change="applyFilters"
      />
      <Button icon="pi pi-search" :label="pt('Search')" @click="applyFilters" />
      <Button icon="pi pi-filter-slash" text :label="pt('Reset')" @click="resetFilters" />
      <TableExportButtons
        title="Book Titles"
        filenameBase="book-titles"
        :rows="rows"
        :columns="[
          { key: 'isbn13', label: 'ISBN' },
          { key: 'name', label: 'Title' },
          { key: 'publisher', label: 'Publisher' },
          { key: 'language', label: 'Language' },
          { key: 'binding_label', label: 'Binding' },
          { key: 'printed_price', label: 'Printed price' },
          { key: 'selling_price', label: 'Selling price' },
          { key: 'stock_quantity', label: 'Stock' },
        ]"
      />
    </div>

    <DataTable
      :value="rows"
      :loading="loading"
      data-key="id"
      lazy
      paginator
      :rows="perPage"
      :first="first"
      :total-records="total"
      striped-rows
      responsive-layout="scroll"
      @page="onPage"
    >
      <Column field="name" :header="pt('Title')" style="min-width: 16rem">
        <template #body="{ data }">
          <div class="strong">{{ data.name }}</div>
          <small class="muted">
            <span v-if="data.series">{{ data.series }}<span v-if="data.volume"> #{{ data.volume }}</span> · </span>
            <span v-if="data.edition">{{ data.edition }} · </span>
            <span class="mono">{{ data.sku }}</span>
          </small>
        </template>
      </Column>

      <Column field="isbn13" :header="pt('ISBN')">
        <template #body="{ data }">
          <div class="mono">{{ data.isbn13 ?? '—' }}</div>
          <!--
            The barcode is no longer the ISBN. Worth a line of its own because
            nothing on the shelf shows it: the scanner simply stops finding the
            book, and the shop concludes it is out of stock.
          -->
          <small v-if="data.isbn13 && !data.barcode_is_isbn" class="warn">
            <i class="pi pi-exclamation-triangle" />
            {{ pt('Barcode is') }} {{ data.barcode }} — {{ pt('the scanner will not find this') }}
          </small>
        </template>
      </Column>

      <Column field="contributors" :header="pt('Credits')" style="min-width: 14rem">
        <template #body="{ data }">
          <div v-if="data.contributors.length" class="chips">
            <Tag
              v-for="c in data.contributors"
              :key="c.id"
              :value="`${c.name} · ${c.role_label}`"
              severity="secondary"
            />
          </div>
          <span v-else class="muted">—</span>
        </template>
      </Column>

      <Column field="publisher" :header="pt('Publisher')">
        <template #body="{ data }">{{ data.publisher ?? '—' }}</template>
      </Column>

      <Column field="language" :header="pt('Language')">
        <template #body="{ data }">
          <span v-if="data.language">{{ data.language }}</span>
          <span v-else class="muted">—</span>
          <div v-if="data.binding_label">
            <small class="muted">{{ data.binding_label }}</small>
          </div>
        </template>
      </Column>

      <Column field="selling_price" :header="pt('Price')">
        <template #body="{ data }">
          <div class="strong">{{ money(data.selling_price) }}</div>
          <!--
            The printed price is on the cover where the customer can read it, so
            any gap between the two is a discount they can see whether or not the
            shop meant to offer one.
          -->
          <small v-if="data.printed_price" class="muted">
            {{ pt('Printed') }} {{ money(data.printed_price) }}
            <span v-if="data.discount_off_printed">({{ data.discount_off_printed }}%)</span>
          </small>
        </template>
      </Column>

      <Column field="stock_quantity" :header="pt('Stock')" style="width: 6rem">
        <template #body="{ data }">
          <Tag
            :value="String(data.stock_quantity)"
            :severity="data.stock_quantity > 0 ? 'success' : 'danger'"
          />
        </template>
      </Column>

      <Column :header="pt('Actions')" style="width: 7rem">
        <template #body="{ data }">
          <Button
            v-if="canEdit"
            icon="pi pi-user-edit"
            text
            rounded
            :title="pt('Credits')"
            @click="openCredits(data)"
          />
        </template>
      </Column>

      <template #empty>
        <div class="empty-state">
          {{ pt('No titles yet. A book becomes a title as soon as it is given an ISBN, a publisher or a language on the Products screen.') }}
        </div>
      </template>
    </DataTable>

    <!-- ================= Credits editor ================= -->
    <Dialog
      v-model:visible="creditsDialog"
      :header="pt('Credits')"
      modal
      :style="{ width: '640px' }"
    >
      <div v-if="creditsFor" class="credits-head">
        <div class="strong">{{ creditsFor.name }}</div>
        <small class="muted mono">{{ creditsFor.isbn13 ?? creditsFor.sku }}</small>
      </div>

      <div class="credit-list">
        <div v-for="(row, index) in creditRows" :key="index" class="credit-row">
          <Select
            v-model="row.book_author_id"
            :options="authors"
            option-label="label"
            option-value="value"
            filter
            class="grow"
            :placeholder="pt('Person')"
          />
          <Select
            v-model="row.role"
            :options="roleOptions"
            option-label="label"
            option-value="value"
            class="role"
          />
          <Button icon="pi pi-trash" text rounded severity="danger" @click="removeCredit(index)" />
        </div>

        <div v-if="!creditRows.length" class="muted pad">
          {{ pt('Nobody credited yet.') }}
        </div>
      </div>

      <Button icon="pi pi-plus" text :label="pt('Add a credit')" @click="addCredit" />

      <small class="hint block">
        {{ pt('The order here is the billing on the cover. One person may appear twice in two different roles — an author who also illustrated the book, for instance.') }}
      </small>

      <Message v-if="Object.keys(errors).length" severity="error" :closable="false">
        {{ Object.values(errors)[0][0] }}
      </Message>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="creditsDialog = false" />
        <Button :label="pt('Save')" :loading="creditsSaving" @click="saveCredits" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.card { border: 1px solid var(--surface-border, #e5e7eb); border-radius: 8px; padding: 0.9rem; margin-bottom: 1rem; }
.lookup-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.6rem; }
.lookup-input { flex: 1 1 320px; }
.found { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: flex-start; }
.found-meta { font-size: 0.75rem; color: #6b7280; display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.2rem; }
.found-right { display: flex; flex-direction: column; align-items: flex-end; gap: 0.3rem; }

.toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grow { flex: 1 1 220px; }

.credits-head { margin-bottom: 0.75rem; }
.credit-list { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.5rem; }
.credit-row { display: flex; gap: 0.5rem; align-items: center; }
.credit-row .role { width: 12rem; }
.pad { padding: 0.5rem 0; }

.chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.block { display: block; margin-top: 0.5rem; }
.warn { color: #b45309; display: block; margin-top: 0.15rem; font-size: 0.7rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
