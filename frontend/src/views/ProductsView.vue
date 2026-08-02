<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useCrud } from '../composables/useCrud'
import { openPdfBlob } from '../utils/blob'
import { formatCurrency } from '../utils/currency'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import DetailDialog from '../components/DetailDialog.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import CameraScanButton from '../components/CameraScanButton.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import { useCustomFields } from '../composables/useCustomFields'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()
const crud = useCrud('/products')
const canScanBarcodes = computed(() => auth.can('barcodes.view'))

// Drug schedules under the Drugs and Cosmetics Rules. G carries a caution
// label but needs no prescription; H, H1 and X do.
const DRUG_SCHEDULES = [
  { label: 'Not a scheduled drug', value: 'none' },
  { label: 'Schedule G (caution label)', value: 'g' },
  { label: 'Schedule H (prescription)', value: 'h' },
  { label: 'Schedule H1 (prescription + register)', value: 'h1' },
  { label: 'Schedule X (licence + register)', value: 'x' },
]

// Jewellery. A metal switches the item to rate pricing (JewelleryPricingService).
const METALS = [
  { label: 'Gold', value: 'gold' },
  { label: 'Silver', value: 'silver' },
  { label: 'Platinum', value: 'platinum' },
]
const PURITIES = ['24K', '22K', '18K', '14K', '999', '925']
const MAKING_TYPES = [
  { label: '% of metal value', value: 'percent' },
  { label: 'Per gram', value: 'per_gram' },
  { label: 'Flat amount', value: 'flat' },
]

// The stone register. Only a diamond is graded on all four Cs, so the grade
// fields below appear for it alone — a ruby has a weight and an eye, not a
// clarity letter.
const STONE_TYPES = [
  { label: 'Diamond', value: 'diamond' },
  { label: 'Ruby', value: 'ruby' },
  { label: 'Emerald', value: 'emerald' },
  { label: 'Sapphire', value: 'sapphire' },
  { label: 'Pearl', value: 'pearl' },
  { label: 'Cubic Zirconia', value: 'cz' },
  { label: 'Semi-precious', value: 'semi_precious' },
  { label: 'Other', value: 'other' },
]
const STONE_RATE_TYPES = [
  { label: 'Per carat', value: 'per_carat' },
  { label: 'Per piece', value: 'per_piece' },
  { label: 'Flat amount', value: 'flat' },
]
// Suggestions, not a closed list: the labs do not agree on a vocabulary and a
// shop that writes 'SI-1' should not be corrected by its billing software.
const DIAMOND_SHAPES = [
  'Round Brilliant', 'Princess', 'Oval', 'Pear', 'Marquise',
  'Emerald', 'Cushion', 'Radiant', 'Asscher', 'Heart', 'Baguette',
]
const DIAMOND_COLOURS = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M']
const DIAMOND_CLARITIES = ['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'I1', 'I2', 'I3']
const DIAMOND_CUTS = ['Excellent', 'Very Good', 'Good', 'Fair', 'Poor']
const CERT_LABS = ['GIA', 'IGI', 'HRD', 'SGL', 'GII', 'AGS']

// Optical lens specification.
const LENS_TYPES = [
  { label: 'Single vision', value: 'single_vision' },
  { label: 'Bifocal', value: 'bifocal' },
  { label: 'Progressive', value: 'progressive' },
  { label: 'Zero power', value: 'zero_power' },
]
const LENS_MATERIALS = [
  { label: 'CR-39', value: 'cr39' },
  { label: 'Polycarbonate', value: 'polycarbonate' },
  { label: 'Trivex', value: 'trivex' },
  { label: 'High index', value: 'high_index' },
  { label: 'Glass', value: 'glass' },
]
const LENS_COATINGS = [
  { label: 'None', value: 'none' },
  { label: 'Anti-reflective', value: 'ar' },
  { label: 'Blue cut', value: 'blue_cut' },
  { label: 'Photochromic', value: 'photochromic' },
  { label: 'UV', value: 'uv' },
]

// Optical frames. The vocabulary a customer uses at the counter — "something
// small and metal" — plus the boxed marking stamped inside the temple.
const FRAME_SHAPES = [
  { label: 'Rectangle', value: 'rectangle' },
  { label: 'Square', value: 'square' },
  { label: 'Round', value: 'round' },
  { label: 'Oval', value: 'oval' },
  { label: 'Cat eye', value: 'cat_eye' },
  { label: 'Aviator', value: 'aviator' },
  { label: 'Wayfarer', value: 'wayfarer' },
  { label: 'Wrap', value: 'wrap' },
  { label: 'Geometric', value: 'geometric' },
]
const FRAME_RIMS = [
  { label: 'Full rim', value: 'full_rim' },
  { label: 'Half rim (supra)', value: 'half_rim' },
  { label: 'Rimless', value: 'rimless' },
]
const FRAME_MATERIALS = [
  { label: 'Acetate', value: 'acetate' },
  { label: 'Metal', value: 'metal' },
  { label: 'Titanium', value: 'titanium' },
  { label: 'TR90', value: 'tr90' },
  { label: 'Stainless steel', value: 'stainless_steel' },
  { label: 'Alloy', value: 'alloy' },
  { label: 'Wood', value: 'wood' },
  { label: 'Combination', value: 'combination' },
]
const FRAME_GENDERS = [
  { label: 'Men', value: 'men' },
  { label: 'Women', value: 'women' },
  { label: 'Unisex', value: 'unisex' },
  { label: 'Kids', value: 'kids' },
]

// Restaurant: firing a round splits the KOTs by station.
const KITCHEN_STATIONS = [
  { label: 'Kitchen', value: 'kitchen' },
  { label: 'Tandoor', value: 'tandoor' },
  { label: 'Chinese', value: 'chinese' },
  { label: 'Bar', value: 'bar' },
  { label: 'Dessert', value: 'dessert' },
]

// Agriculture. 'feed' is here as well as the three controlled inputs because a
// farm buys it as stock and the daily production log draws it down.
const AGRI_INPUT_TYPES = [
  { label: 'Seed', value: 'seed' },
  { label: 'Fertiliser', value: 'fertilizer' },
  { label: 'Pesticide', value: 'pesticide' },
  { label: 'Cattle / Poultry Feed', value: 'feed' },
  { label: 'Equipment', value: 'equipment' },
  { label: 'Other', value: 'other' },
]

const search = ref('')
const categoryFilter = ref<number | null>(null)
const activeFilter = ref<boolean | null>(null)
const activeOptions = computed(() => [
  { label: pt('Active'), value: true },
  { label: pt('Inactive'), value: false },
])

const detailVisible = ref(false)
const detailRecord = ref<any>(null)
function openDetail(row: any) { detailRecord.value = row; detailVisible.value = true }

const categories = ref<any[]>([])
const brands = ref<any[]>([])
const units = ref<any[]>([])
const suppliers = ref<any[]>([])
/**
 * Toys & Games. Both lists come from the plugin's own module-gated endpoints, so
 * a workspace without the vertical simply gets nothing back and the section
 * below never renders — which is why they are loaded separately from the four
 * core pickers above rather than beside them.
 */
const toyAgeGrades = ref<any[]>([])
const toySafetyClasses = ref<any[]>([])
const showToyFields = computed(() => toyAgeGrades.value.length > 0 || toySafetyClasses.value.length > 0)
/**
 * Books & Periodicals. Same module-gated loading as the toy lists above, with
 * one deliberate difference: this section appears as soon as the *endpoint*
 * answers, not once it returns rows.
 *
 * A shop cataloguing its very first title has no publishers and no languages
 * yet, so a section that waited for rows would hide the ISBN box from precisely
 * the shop trying to start its register. The endpoint 403s in a workspace
 * without the vertical, which is the question actually being asked.
 */
const bookPublishers = ref<any[]>([])
const bookLanguages = ref<any[]>([])
const showBookFields = ref(false)
/** Restated from the plugin's enum — see TitleController::BINDINGS. */
const bookBindings = [
  { label: 'Paperback', value: 'paperback' },
  { label: 'Hardcover', value: 'hardcover' },
  { label: 'Board book', value: 'board_book' },
  { label: 'Spiral bound', value: 'spiral' },
  { label: 'Leather bound', value: 'leather' },
  { label: 'Magazine / periodical', value: 'magazine' },
  { label: 'E-book', value: 'ebook' },
  { label: 'Audiobook', value: 'audiobook' },
  { label: 'Other', value: 'other' },
]

const canCreate = computed(() => auth.can('products.create'))
const canEdit = computed(() => auth.can('products.edit'))
const canDelete = computed(() => auth.can('products.delete'))
const canView = computed(() => auth.can('products.view'))

function money(v: any) {
  return formatCurrency(v)
}

function trackingTags(row: any): string[] {
  const tags: string[] = []
  if (row.track_batch) tags.push(pt('batch'))
  if (row.track_serial) tags.push(pt('serial'))
  if (row.track_expiry) tags.push(pt('expiry'))
  return tags
}

const sortField = ref<string | null>(null)
const sortOrder = ref<1 | -1 | null>(null)

function reload() {
  crud.fetch({
    ...(search.value ? { search: search.value } : {}),
    ...(categoryFilter.value ? { category_id: categoryFilter.value } : {}),
    ...(activeFilter.value !== null ? { is_active: activeFilter.value } : {}),
    ...(sortField.value && sortOrder.value
      ? { sort: sortField.value, direction: sortOrder.value === 1 ? 'asc' : 'desc' }
      : {}),
  })
}

function doSearch() {
  crud.currentPage.value = 1
  reload()
}

/** Camera scan filters the list by the decoded barcode. */
function onSearchScan(code: string) {
  search.value = code
  doSearch()
}

function onPage(e: any) {
  crud.currentPage.value = e.page + 1
  crud.perPage.value = e.rows
  reload()
}

function onSort(e: any) {
  sortField.value = e.sortField || null
  sortOrder.value = e.sortOrder || null
  crud.currentPage.value = 1
  reload()
}

// --- Create / Edit dialog ---
const dialogVisible = ref(false)
const editing = ref<any>(null)
const form = ref<Record<string, any>>({})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

function blankForm(): Record<string, any> {
  return {
    sku: null,
    name: null,
    barcode: null,
    rfid_tag: null,
    category_id: null,
    brand_id: null,
    unit_id: null,
    hsn_code: null,
    cost_price: 0,
    selling_price: 0,
    tax_percent: 0,
    min_stock_level: 0,
    max_stock_level: 0,
    reorder_level: 0,
    reorder_quantity: 0,
    preferred_supplier_id: null,
    description: null,
    track_batch: false,
    track_serial: false,
    track_expiry: false,
    /**
     * The warranty policy on the article — "six months on the sole". Null means
     * nobody has said; 0 means explicitly sold without cover, and the two are
     * kept apart because only the first is worth prompting about. Copied onto
     * each warranty card at issue, so changing it here never touches cover a
     * customer already holds.
     */
    warranty_months: null,
    warranty_terms: null,
    // Pharmacy: 'none' for anything that is not a scheduled medicine.
    drug_schedule: 'none',
    composition: null,
    manufacturer: null,
    // Jewellery: a null metal means "not an ornament" and the stored selling
    // price is used as normal.
    metal: null,
    purity: null,
    gross_weight: null,
    net_weight: null,
    stone_weight: null,
    stone_value: 0,
    making_charge_type: 'percent',
    making_charge_value: 0,
    wastage_percent: 0,
    // The BIS mark, where it was struck and what the centre charged for it.
    // The charge is a real line on a jewellery bill, so it is priced, not noted.
    huid: null,
    hallmark_centre: null,
    hallmarked_on: null,
    hallmark_charge: 0,
    // Optical: a null lens_type means "not a lens"; is_frame marks the other
    // half of a spectacle order.
    lens_type: null,
    lens_material: null,
    lens_index: null,
    lens_coating: null,
    min_sph: null,
    max_sph: null,
    max_cyl: null,
    is_frame: false,
    // The frame's own facts, shown only once `is_frame` is ticked. The three
    // millimetre figures are the boxed marking — 52▫18-140.
    frame_shape: null,
    frame_rim: null,
    frame_material: null,
    frame_gender: null,
    frame_colour: null,
    frame_eye_size: null,
    frame_bridge_size: null,
    frame_temple_length: null,
    frame_tray: null,
    // Restaurant: which station cooks it.
    kitchen_station: null,
    prep_minutes: null,
    // Agriculture: a null input type means "not a controlled agri input", and
    // the fields below stay hidden.
    agri_input_type: null,
    // Toys: null on both means "not graded", which is exactly what the counter
    // check reports — it never guesses an age band from a product name.
    toy_age_grade_id: null,
    toy_safety_category_id: null,
    /**
     * Books: the title's own facts. The ISBN is typed as it is printed, hyphens
     * and all — the server strips them, check-sums the number and copies it onto
     * the barcode, so nothing here has to be keyed twice or got right by hand.
     */
    book_isbn13: null,
    book_isbn10: null,
    book_publisher_id: null,
    book_language_id: null,
    book_binding: null,
    book_edition: null,
    book_pages: null,
    book_published_on: null,
    book_series: null,
    book_volume: null,
    book_printed_price: null,
    crop: null,
    variety: null,
    technical_name: null,
    nutrient_content: null,
    germination_percent: null,
    is_active: true,
    attributes: {},
    // Whatever this shop keeps that the columns above do not name — an ISBN,
    // an age band, a wattage. Defined in Administration → Custom Fields.
    custom_fields: {},
  }
}

// --- Category attributes (dynamic fields) ---
const attrDefs = ref<any[]>([])

async function loadAttrDefs(categoryId: any) {
  if (!categoryId) {
    attrDefs.value = []
    return
  }
  try {
    const { data } = await http.get(`/categories/${categoryId}/attributes`)
    attrDefs.value = data.data ?? data ?? []
  } catch {
    attrDefs.value = []
  }
}

watch(() => form.value.category_id, async (categoryId) => {
  await loadAttrDefs(categoryId)
  // Keep values whose definition still exists for the selected category, drop the rest.
  const names = new Set(attrDefs.value.map((d: any) => d.name))
  const attrs = form.value.attributes ?? {}
  for (const key of Object.keys(attrs)) {
    if (!names.has(key)) delete attrs[key]
  }
  form.value.attributes = attrs
})

function openCreate() {
  editing.value = null
  form.value = blankForm()
  errors.value = {}
  dialogVisible.value = true
}

function openEdit(row: any) {
  editing.value = row
  const f = blankForm()
  for (const key of Object.keys(f)) {
    f[key] = row[key] ?? f[key]
  }
  // Normalize numeric strings so InputNumber binds correctly.
  for (const key of ['cost_price', 'selling_price', 'tax_percent', 'min_stock_level', 'max_stock_level', 'reorder_level', 'reorder_quantity', 'book_printed_price', 'book_pages']) {
    f[key] = f[key] === null || f[key] === undefined ? null : Number(f[key])
  }
  // Copy so edits don't mutate the row until saved.
  f.attributes = { ...(row.attributes ?? {}) }
  form.value = f
  errors.value = {}
  dialogVisible.value = true
  loadAttrDefs(f.category_id)
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) {
      await crud.update(editing.value.id, form.value)
      toast.add({ severity: 'success', summary: pt('Product updated'), life: 2500 })
    } else {
      await crud.create(form.value)
      toast.add({ severity: 'success', summary: pt('Product created'), life: 2500 })
    }
    dialogVisible.value = false
    reload()
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this product?'),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await crud.remove(row.id)
    toast.add({ severity: 'success', summary: pt('Product deleted'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}

// --- Image upload (edit dialog only) ---
const uploadingImage = ref(false)
const editImageUrl = computed(() => editing.value?.image_url || null)

async function onImagePicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !editing.value) return
  const fd = new FormData()
  fd.append('image', file)
  uploadingImage.value = true
  try {
    const { data } = await http.post(`/products/${editing.value.id}/image`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const updated = data.data ?? data
    editing.value.image_url = updated.image_url
    editing.value.image = updated.image
    toast.add({ severity: 'success', summary: pt('Image uploaded'), life: 2500 })
    reload()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Image upload failed'), life: 4000 })
  } finally {
    uploadingImage.value = false
    input.value = ''
  }
}

// --- Variants dialog ---
const variantsVisible = ref(false)
const variantsProduct = ref<any>(null)
const variants = ref<any[]>([])
const variantsLoading = ref(false)

const variantFormVisible = ref(false)
const variantEditing = ref<any>(null)
const variantForm = ref<Record<string, any>>({})
const variantErrors = ref<Record<string, string[]>>({})
const variantSaving = ref(false)

function blankVariantForm(): Record<string, any> {
  return { sku: null, name: null, barcode: null, rfid_tag: null, cost_price: 0, selling_price: 0, is_active: true }
}

async function openVariants(row: any) {
  variantsProduct.value = row
  variantFormVisible.value = false
  variantsVisible.value = true
  await loadVariants()
}

async function loadVariants() {
  if (!variantsProduct.value) return
  variantsLoading.value = true
  try {
    const { data } = await http.get(`/products/${variantsProduct.value.id}/variants`)
    variants.value = data.data ?? data
  } catch (e: any) {
    variants.value = []
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Could not load variants'), life: 4000 })
  } finally {
    variantsLoading.value = false
  }
}

function openVariantCreate() {
  variantEditing.value = null
  variantForm.value = blankVariantForm()
  variantErrors.value = {}
  variantFormVisible.value = true
}

function openVariantEdit(v: any) {
  variantEditing.value = v
  variantForm.value = {
    sku: v.sku,
    name: v.name,
    barcode: v.barcode,
    rfid_tag: v.rfid_tag,
    cost_price: v.cost_price === null || v.cost_price === undefined ? null : Number(v.cost_price),
    selling_price: v.selling_price === null || v.selling_price === undefined ? null : Number(v.selling_price),
    is_active: !!v.is_active,
  }
  variantErrors.value = {}
  variantFormVisible.value = true
}

async function saveVariant() {
  variantSaving.value = true
  variantErrors.value = {}
  try {
    if (variantEditing.value) {
      await http.put(`/products/${variantsProduct.value.id}/variants/${variantEditing.value.id}`, variantForm.value)
      toast.add({ severity: 'success', summary: pt('Variant updated'), life: 2500 })
    } else {
      await http.post(`/products/${variantsProduct.value.id}/variants`, variantForm.value)
      toast.add({ severity: 'success', summary: pt('Variant created'), life: 2500 })
    }
    variantFormVisible.value = false
    await loadVariants()
  } catch (e: any) {
    if (e.response?.status === 422) {
      variantErrors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    variantSaving.value = false
  }
}

async function confirmDeleteVariant(v: any) {
  const res = await Swal.fire({
    title: pt('Delete this variant?'),
    text: pt('This action cannot be undone.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/products/${variantsProduct.value.id}/variants/${v.id}`)
    toast.add({ severity: 'success', summary: pt('Variant deleted'), life: 2500 })
    await loadVariants()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function variantError(key: string): string | null {
  return variantErrors.value[key]?.[0] ?? null
}

/**
 * --- Stone register dialog (jewellery) ---
 *
 * Its own screen rather than more fields on the product form: a studded piece
 * has as many stones as it has, and they can only be attached once the product
 * exists. Saving any line recomputes the product's stone value and weight on
 * the server, so the list is reloaded from the product afterwards rather than
 * patched locally.
 */
const stonesVisible = ref(false)
const stonesProduct = ref<any>(null)
const stones = ref<any[]>([])
const stonesLoading = ref(false)
const stoneTotals = ref<{ value: number; weight: number } | null>(null)

const stoneFormVisible = ref(false)
const stoneEditing = ref<any>(null)
const stoneForm = ref<Record<string, any>>({})
const stoneErrors = ref<Record<string, string[]>>({})
const stoneSaving = ref(false)

function blankStoneForm(): Record<string, any> {
  return {
    stone_type: 'diamond',
    shape: null,
    pieces: 1,
    carat_weight: null,
    cut_grade: null,
    colour_grade: null,
    clarity_grade: null,
    rate_type: 'per_carat',
    rate: 0,
    // Null means "take the arithmetic". Only a job lot needs a typed total.
    value: null,
    certificate_number: null,
    certifying_lab: null,
    certificate_date: null,
    setting_type: null,
    notes: null,
  }
}

// What the line comes to from its own rate, shown live so the shop can see what
// it is overruling before it types a total. Mirrors ProductStone::computedValue().
const stoneComputed = computed(() => {
  const f = stoneForm.value
  const rate = Number(f.rate ?? 0)
  if (f.rate_type === 'per_carat') return Math.round(Number(f.carat_weight ?? 0) * rate * 100) / 100
  if (f.rate_type === 'per_piece') return Math.round(Number(f.pieces ?? 0) * rate * 100) / 100
  return Math.round(rate * 100) / 100
})

async function openStones(row: any) {
  stonesProduct.value = row
  stoneFormVisible.value = false
  stonesVisible.value = true
  await loadStones()
}

async function loadStones() {
  if (!stonesProduct.value) return
  stonesLoading.value = true
  try {
    const [list, product] = await Promise.all([
      http.get(`/products/${stonesProduct.value.id}/stones`),
      http.get(`/products/${stonesProduct.value.id}`),
    ])
    stones.value = list.data.data ?? list.data
    const p = product.data.data ?? product.data
    stoneTotals.value = { value: Number(p.stone_value ?? 0), weight: Number(p.stone_weight ?? 0) }
    // Keep the row behind the dialog honest — the register has just rewritten
    // the two numbers the product list shows.
    Object.assign(stonesProduct.value, { stone_value: p.stone_value, stone_weight: p.stone_weight })
  } catch (e: any) {
    stones.value = []
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Could not load stones'), life: 4000 })
  } finally {
    stonesLoading.value = false
  }
}

function openStoneCreate() {
  stoneEditing.value = null
  stoneForm.value = blankStoneForm()
  stoneErrors.value = {}
  stoneFormVisible.value = true
}

function openStoneEdit(s: any) {
  stoneEditing.value = s
  const f = blankStoneForm()
  for (const key of Object.keys(f)) f[key] = s[key] ?? f[key]
  for (const key of ['pieces', 'carat_weight', 'rate', 'value']) {
    f[key] = s[key] === null || s[key] === undefined ? null : Number(s[key])
  }
  stoneForm.value = f
  stoneErrors.value = {}
  stoneFormVisible.value = true
}

async function saveStone() {
  stoneSaving.value = true
  stoneErrors.value = {}
  try {
    const url = `/products/${stonesProduct.value.id}/stones`
    if (stoneEditing.value) {
      await http.put(`${url}/${stoneEditing.value.id}`, stoneForm.value)
      toast.add({ severity: 'success', summary: pt('Stone updated'), life: 2500 })
    } else {
      await http.post(url, stoneForm.value)
      toast.add({ severity: 'success', summary: pt('Stone added'), life: 2500 })
    }
    stoneFormVisible.value = false
    await loadStones()
  } catch (e: any) {
    if (e.response?.status === 422) {
      stoneErrors.value = e.response.data.errors || {}
    } else {
      toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
    }
  } finally {
    stoneSaving.value = false
  }
}

async function confirmDeleteStone(s: any) {
  const res = await Swal.fire({
    title: pt('Remove this stone?'),
    text: pt('The item\'s stone value will be recalculated.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
  })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/products/${stonesProduct.value.id}/stones/${s.id}`)
    toast.add({ severity: 'success', summary: pt('Stone removed'), life: 2500 })
    await loadStones()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Delete failed'), life: 4000 })
  }
}

function stoneError(key: string): string | null {
  return stoneErrors.value[key]?.[0] ?? null
}

// --- Barcode preview / quick print dialog ---
const barcodeVisible = ref(false)
const barcodeProduct = ref<any>(null)
const barcodeImages = ref<{ code128: string | null; qr: string | null }>({ code128: null, qr: null })
const barcodeLoading = ref(false)
const barcodePrintQty = ref(1)
const barcodePrinting = ref(false)

async function openBarcode(row: any) {
  barcodeProduct.value = row
  barcodeImages.value = { code128: null, qr: null }
  barcodePrintQty.value = 1
  barcodeVisible.value = true
  if (!row.barcode) return
  barcodeLoading.value = true
  try {
    const [c128, qr] = await Promise.all([
      http.get(`/products/${row.id}/barcode-image`, { params: { type: 'code128' }, responseType: 'blob' }),
      http.get(`/products/${row.id}/barcode-image`, { params: { type: 'qr' }, responseType: 'blob' }),
    ])
    barcodeImages.value = {
      code128: URL.createObjectURL(c128.data),
      qr: URL.createObjectURL(qr.data),
    }
  } catch {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not render barcode images'), life: 4000 })
  } finally {
    barcodeLoading.value = false
  }
}

async function printBarcodeLabel() {
  if (!barcodeProduct.value) return
  barcodePrinting.value = true
  try {
    const { data } = await http.post(
      '/barcode/print-labels',
      { label_type: 'code128', items: [{ type: 'product', id: barcodeProduct.value.id, quantity: barcodePrintQty.value }] },
      { responseType: 'blob' },
    )
    openPdfBlob(data)
  } catch {
    toast.add({ severity: 'error', summary: pt('Error'), detail: pt('Could not generate label'), life: 4000 })
  } finally {
    barcodePrinting.value = false
  }
}

// Tenant-defined fields the admin asked to see as list columns. The panel
// inside the dialog loads the same (cached) schema for the form.
const { listColumns: customColumns, display: customDisplay, load: loadCustomFields } = useCustomFields('product')

onMounted(async () => {
  reload()
  loadCustomFields()
  try {
    const [c, b, u, s] = await Promise.all([
      http.get('/categories', { params: { per_page: 100 } }),
      http.get('/brands', { params: { per_page: 100 } }),
      http.get('/units', { params: { per_page: 100 } }),
      http.get('/suppliers', { params: { per_page: 100 } }),
    ])
    categories.value = (c.data.data ?? c.data).map((x: any) => ({ label: x.name, value: x.id }))
    brands.value = (b.data.data ?? b.data).map((x: any) => ({ label: x.name, value: x.id }))
    units.value = (u.data.data ?? u.data).map((x: any) => ({ label: x.short_name, value: x.id }))
    suppliers.value = (s.data.data ?? s.data).map((x: any) => ({ label: x.name, value: x.id }))
  } catch {
    // option lists stay empty; the dialog still works
  }

  /**
   * Separately, and deliberately swallowed: these two 403 for every workspace
   * that never bought Toys & Games, which is the normal case and not an error.
   * An empty list hides the section.
   */
  try {
    const [g, sc] = await Promise.all([
      http.get('/toys/age-grades', { params: { is_active: 1 } }),
      http.get('/toys/safety-categories', { params: { is_active: 1 } }),
    ])
    toyAgeGrades.value = (g.data.data ?? []).map((x: any) => ({ label: `${x.label} (${x.range})`, value: x.id }))
    toySafetyClasses.value = (sc.data.data ?? []).map((x: any) => ({ label: x.label, value: x.id }))
  } catch {
    // Not a toy shop, or no bands defined yet — the section stays hidden.
  }

  /**
   * Books, separately again and for the same reason: both endpoints 403 in a
   * workspace that never bought the vertical. Note the flag is set on success
   * rather than on there being rows — a shop with no publishers yet still needs
   * somewhere to type its first ISBN.
   */
  try {
    const [pub, lang] = await Promise.all([
      http.get('/books/publishers', { params: { per_page: 100, is_active: 1 } }),
      http.get('/books/languages', { params: { per_page: 100, is_active: 1 } }),
    ])
    bookPublishers.value = (pub.data.data ?? []).map((x: any) => ({ label: x.display_name, value: x.id }))
    bookLanguages.value = (lang.data.data ?? []).map((x: any) => ({ label: x.display, value: x.id }))
    showBookFields.value = true
  } catch {
    // Not a book shop — the section stays hidden.
  }
})
</script>

<template>
  <div class="products-page">
    <PageHeader :title="pt('Products')"
      :subtitle="pt('Your catalogue, pricing and stock-tracking setup in one place.')"
      :count="crud.total.value" :countLabel="pt('products')">
      <template #actions>
        <Button v-if="canCreate" :label="pt('New Product')" icon="pi pi-plus" class="add-btn" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="toolbar no-print">
      <span class="search">
        <i class="pi pi-search" />
        <InputText v-model="search" :placeholder="pt('Search products by name / SKU / barcode...')" @keyup.enter="doSearch" />
        <i v-if="search" class="pi pi-times clear-search" @click="search = ''; doSearch()" />
      </span>
      <Select v-model="categoryFilter" :options="categories" optionLabel="label" optionValue="value" :placeholder="pt('All categories')" showClear class="filter" @change="doSearch" />
      <Select v-model="activeFilter" :options="activeOptions" optionLabel="label" optionValue="value" :placeholder="pt('All status')" showClear class="filter" @change="doSearch" />
      <Button :label="pt('Search')" icon="pi pi-filter" @click="doSearch" />
      <CameraScanButton v-if="canScanBarcodes" @scan="onSearchScan" />
      <span class="toolbar-spacer" />
      <TableExportButtons :title="pt('Products')" endpoint="/products"
        :params="() => ({
          search: search || undefined,
          category_id: categoryFilter || undefined,
          is_active: activeFilter !== null ? activeFilter : undefined,
        })"
        :columns="[
          { key: 'sku', label: pt('SKU') },
          { key: 'name', label: pt('Name') },
          { key: 'category.name', label: pt('Category') },
          { key: 'selling_price', label: pt('Selling Price') },
          { key: 'is_active', label: pt('Active'), format: (v) => v ? pt('Yes') : pt('No') },
        ]" />
    </div>

    <DataTable :value="crud.items.value" :loading="crud.loading.value" lazy paginator :rows="crud.perPage.value"
      :totalRecords="crud.total.value" :rowsPerPageOptions="[15, 30, 50, 100]" dataKey="id" @page="onPage"
      removableSort :sortField="sortField ?? undefined" :sortOrder="sortOrder ?? undefined" @sort="onSort"
      class="p-datatable-sm" rowHover>
      <Column field="name" :header="pt('Product')" sortable style="min-width: 15rem">
        <template #body="{ data }">
          <div class="entity-cell">
            <img v-if="data.image_url" :src="data.image_url" :alt="data.name" class="thumb" />
            <span v-else class="thumb thumb-placeholder"><i class="pi pi-image" /></span>
            <div class="entity-meta">
              <span class="entity-name">{{ data.name }}</span>
              <span class="entity-hint" :class="{ mono: !data.brand?.name && data.barcode }">
                {{ data.brand?.name || data.barcode || '—' }}
              </span>
            </div>
          </div>
        </template>
      </Column>
      <Column field="sku" :header="pt('SKU')" sortable style="width: 10rem">
        <template #body="{ data }"><span class="code-chip">{{ data.sku }}</span></template>
      </Column>
      <Column :header="pt('Category')" style="width: 11rem">
        <template #body="{ data }">
          <span v-if="data.category?.name" class="chip">{{ data.category.name }}</span>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <Column field="selling_price" :header="pt('Selling Price')" sortable style="width: 10rem" bodyClass="num-cell" headerClass="num-head">
        <template #body="{ data }"><span class="amount">{{ money(data.selling_price) }}</span></template>
      </Column>
      <Column :header="pt('Tracking')" style="width: 12rem">
        <template #body="{ data }">
          <template v-if="trackingTags(data).length">
            <span v-for="t in trackingTags(data)" :key="t" class="track-pill">{{ t }}</span>
          </template>
          <span v-else class="muted">—</span>
        </template>
      </Column>
      <!-- Whatever this shop added and asked to see here. -->
      <Column v-for="field in customColumns" :key="field.key" :header="field.label">
        <template #body="{ data }">{{ customDisplay(field, data.custom_fields) }}</template>
      </Column>
      <Column :header="pt('Active')" style="width: 8rem">
        <template #body="{ data }"><StatusPill :active="!!data.is_active" /></template>
      </Column>
      <Column :header="pt('Actions')" style="width: 12rem" bodyClass="num-cell" headerClass="num-head">
        <template #body="{ data }">
          <div class="row-actions">
            <button class="icon-btn" :title="pt('View')" @click="openDetail(data)"><i class="pi pi-eye" /></button>
            <button v-if="canEdit" class="icon-btn edit" :title="pt('Edit')" @click="openEdit(data)"><i class="pi pi-pencil" /></button>
            <button v-if="canView" class="icon-btn" :title="pt('Variants')" @click="openVariants(data)"><i class="pi pi-sitemap" /></button>
            <!-- Only on an ornament: every other trade would read "Stones" as noise. -->
            <button v-if="canView && data.metal" class="icon-btn" :title="pt('Stones')" @click="openStones(data)"><i class="pi pi-star" /></button>
            <button v-if="canScanBarcodes" class="icon-btn" :title="pt('Barcode')" @click="openBarcode(data)"><i class="pi pi-qrcode" /></button>
            <button v-if="canDelete" class="icon-btn danger" :title="pt('Delete')" @click="confirmDelete(data)"><i class="pi pi-trash" /></button>
          </div>
        </template>
      </Column>
      <template #empty>
        <div class="empty-state">
          <i class="pi pi-inbox" />
          <p>{{ pt('No products found.') }}</p>
          <Button v-if="canCreate" :label="pt('New Product')" icon="pi pi-plus" text @click="openCreate" />
        </div>
      </template>
    </DataTable>

    <!-- Create / Edit dialog -->
    <Dialog v-model:visible="dialogVisible" modal :header="editing ? pt('Edit Product') : pt('New Product')" :style="{ width: '640px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>{{ pt('SKU') }} <span class="req">*</span></label>
          <InputText v-model="form.sku" :class="{ invalid: fieldError('sku') }" />
          <small v-if="fieldError('sku')" class="error-text">{{ fieldError('sku') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Barcode') }}</label>
          <div class="scan-field">
            <InputText v-model="form.barcode" :class="{ invalid: fieldError('barcode') }" />
            <CameraScanButton label="" text @scan="(c: string) => (form.barcode = c)" />
          </div>
          <small v-if="fieldError('barcode')" class="error-text">{{ fieldError('barcode') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('RFID Tag') }}</label>
          <InputText v-model="form.rfid_tag" :placeholder="pt('Scan or type EPC / tag ID')" :class="{ invalid: fieldError('rfid_tag') }" />
          <small v-if="fieldError('rfid_tag')" class="error-text">{{ fieldError('rfid_tag') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Category') }}</label>
          <Select v-model="form.category_id" :options="categories" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Category')" showClear :class="{ invalid: fieldError('category_id') }" />
          <small v-if="fieldError('category_id')" class="error-text">{{ fieldError('category_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Brand') }}</label>
          <Select v-model="form.brand_id" :options="brands" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Brand')" showClear :class="{ invalid: fieldError('brand_id') }" />
          <small v-if="fieldError('brand_id')" class="error-text">{{ fieldError('brand_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Unit') }}</label>
          <Select v-model="form.unit_id" :options="units" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Unit')" showClear :class="{ invalid: fieldError('unit_id') }" />
          <small v-if="fieldError('unit_id')" class="error-text">{{ fieldError('unit_id') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('HSN Code') }}</label>
          <InputText v-model="form.hsn_code" :class="{ invalid: fieldError('hsn_code') }" />
          <small v-if="fieldError('hsn_code')" class="error-text">{{ fieldError('hsn_code') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Cost Price') }}</label>
          <InputNumber v-model="form.cost_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('cost_price') }" />
          <small v-if="fieldError('cost_price')" class="error-text">{{ fieldError('cost_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Selling Price') }}</label>
          <InputNumber v-model="form.selling_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: fieldError('selling_price') }" />
          <small v-if="fieldError('selling_price')" class="error-text">{{ fieldError('selling_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Tax %') }}</label>
          <InputNumber v-model="form.tax_percent" :minFractionDigits="0" :maxFractionDigits="2" :class="{ invalid: fieldError('tax_percent') }" />
          <small v-if="fieldError('tax_percent')" class="error-text">{{ fieldError('tax_percent') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Min Stock Level') }}</label>
          <InputNumber v-model="form.min_stock_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('min_stock_level') }" />
          <small v-if="fieldError('min_stock_level')" class="error-text">{{ fieldError('min_stock_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Max Stock Level') }}</label>
          <InputNumber v-model="form.max_stock_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('max_stock_level') }" />
          <small v-if="fieldError('max_stock_level')" class="error-text">{{ fieldError('max_stock_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Reorder Level') }}</label>
          <InputNumber v-model="form.reorder_level" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('reorder_level') }" />
          <small v-if="fieldError('reorder_level')" class="error-text">{{ fieldError('reorder_level') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Reorder Quantity') }}</label>
          <InputNumber v-model="form.reorder_quantity" :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError('reorder_quantity') }" />
          <small v-if="fieldError('reorder_quantity')" class="error-text">{{ fieldError('reorder_quantity') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Preferred Supplier') }}</label>
          <Select v-model="form.preferred_supplier_id" :options="suppliers" optionLabel="label" optionValue="value"
            :placeholder="pt('Select Supplier')" showClear :class="{ invalid: fieldError('preferred_supplier_id') }" />
          <small v-if="fieldError('preferred_supplier_id')" class="error-text">{{ fieldError('preferred_supplier_id') }}</small>
        </div>
        <div class="form-field full">
          <label>{{ pt('Description') }}</label>
          <Textarea v-model="form.description" rows="3" :class="{ invalid: fieldError('description') }" />
          <small v-if="fieldError('description')" class="error-text">{{ fieldError('description') }}</small>
        </div>
        <div v-if="attrDefs.length > 0" class="form-field full attr-section">
          <label>{{ pt('Category Attributes') }}</label>
          <div class="attr-grid">
            <div v-for="def in attrDefs" :key="def.name" class="form-field">
              <label>{{ def.name }}{{ def.required ? ' *' : '' }}</label>
              <InputText v-if="def.type === 'text'" v-model="form.attributes[def.name]"
                :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <InputNumber v-else-if="def.type === 'number'" v-model="form.attributes[def.name]"
                :minFractionDigits="0" :maxFractionDigits="3" :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <Select v-else-if="def.type === 'select'" v-model="form.attributes[def.name]" :options="def.options"
                :placeholder="pt('Select')" showClear :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <InputText v-else v-model="form.attributes[def.name]"
                :class="{ invalid: fieldError(`attributes.${def.name}`) }" />
              <small v-if="fieldError(`attributes.${def.name}`)" class="error-text">{{ fieldError(`attributes.${def.name}`) }}</small>
            </div>
          </div>
        </div>

        <!-- Pharmacy. Shown to every trade because a general store can stock a
             scheduled medicine too; 'none' is the default and costs nothing. -->
        <div class="form-field">
          <label>{{ pt('Drug Schedule') }}</label>
          <Select
            v-model="form.drug_schedule"
            :options="DRUG_SCHEDULES"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
          <small class="muted">{{ pt('H, H1 and X cannot be sold without a prescription.') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Composition / Salt') }}</label>
          <InputText v-model="form.composition" class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Manufacturer') }}</label>
          <InputText v-model="form.manufacturer" class="w-full" />
        </div>

        <!-- Jewellery. Choosing a metal switches the item to rate pricing: its
             stored selling price stops being used entirely. -->
        <div class="form-field">
          <label>{{ pt('Metal') }}</label>
          <Select
            v-model="form.metal"
            :options="METALS"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="pt('Not a jewellery item')"
            class="w-full"
          />
        </div>
        <template v-if="form.metal">
          <div class="form-field">
            <label>{{ pt('Purity') }}</label>
            <Select v-model="form.purity" :options="PURITIES" editable class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Gross weight (g)') }}</label>
            <InputNumber v-model="form.gross_weight" :min="0" :maxFractionDigits="3" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Net weight (g)') }}</label>
            <InputNumber v-model="form.net_weight" :min="0" :maxFractionDigits="3" class="w-full" />
            <small class="muted">{{ pt('Metal only — this is what earns the rate.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Stone weight (g)') }}</label>
            <InputNumber v-model="form.stone_weight" :min="0" :maxFractionDigits="3" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Stone value') }}</label>
            <InputNumber v-model="form.stone_value" :min="0" :maxFractionDigits="2" class="w-full" />
            <!-- Two ways in, and the register wins when it is used: listing the
                 stones recomputes both numbers above. -->
            <small class="muted">
              {{ pt('A lump sum. Save the item and use Stones to enter them one by one — that recalculates this.') }}
            </small>
          </div>
          <div class="form-field">
            <label>{{ pt('Making charge type') }}</label>
            <Select
              v-model="form.making_charge_type"
              :options="MAKING_TYPES"
              optionLabel="label"
              optionValue="value"
              class="w-full"
            />
          </div>
          <div class="form-field">
            <label>{{ pt('Making charge') }}</label>
            <InputNumber v-model="form.making_charge_value" :min="0" :maxFractionDigits="2" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Wastage %') }}</label>
            <InputNumber v-model="form.wastage_percent" :min="0" :maxFractionDigits="3" suffix=" %" class="w-full" />
          </div>
          <!-- The hallmark. The HUID identifies this one article, so the same
               mark on two products is refused by the API as a mis-key. -->
          <div class="form-field">
            <label>{{ pt('HUID (hallmark)') }}</label>
            <InputText v-model="form.huid" :class="{ invalid: fieldError('huid') }" class="w-full" />
            <small v-if="fieldError('huid')" class="error-text">{{ fieldError('huid') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Hallmarking centre') }}</label>
            <InputText v-model="form.hallmark_centre" :placeholder="pt('BIS assaying centre')" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Hallmarked on') }}</label>
            <input type="date" v-model="form.hallmarked_on" class="date-input w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Hallmarking charge') }}</label>
            <InputNumber v-model="form.hallmark_charge" :min="0" :maxFractionDigits="2" class="w-full" />
            <small class="muted">{{ pt('Per piece, added to the bill.') }}</small>
          </div>
        </template>

        <!-- Optical. A lens type switches on the power range the blank can be
             ground within; a frame is the other half of a spectacle order. -->
        <div class="form-field">
          <label>{{ pt('Lens Type') }}</label>
          <Select
            v-model="form.lens_type"
            :options="LENS_TYPES"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="pt('Not a lens')"
            class="w-full"
          />
        </div>
        <template v-if="form.lens_type">
          <div class="form-field">
            <label>{{ pt('Lens Material') }}</label>
            <Select v-model="form.lens_material" :options="LENS_MATERIALS" optionLabel="label" optionValue="value" showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Index') }}</label>
            <InputNumber v-model="form.lens_index" :min="1" :max="2" :maxFractionDigits="2" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Coating') }}</label>
            <Select v-model="form.lens_coating" :options="LENS_COATINGS" optionLabel="label" optionValue="value" showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Min SPH') }}</label>
            <InputNumber v-model="form.min_sph" :step="0.25" :minFractionDigits="2" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Max SPH') }}</label>
            <InputNumber v-model="form.max_sph" :step="0.25" :minFractionDigits="2" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Max CYL') }}</label>
            <InputNumber v-model="form.max_cyl" :step="0.25" :minFractionDigits="2" class="w-full" />
            <small class="muted">{{ pt('An order outside this range is refused.') }}</small>
          </div>
        </template>

        <!-- Restaurant. The station decides which KOT a dish lands on when the
             round is fired. -->
        <div class="form-field">
          <label>{{ pt('Kitchen Station') }}</label>
          <Select
            v-model="form.kitchen_station"
            :options="KITCHEN_STATIONS"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="pt('Not a kitchen item')"
            class="w-full"
          />
        </div>
        <div v-if="form.kitchen_station" class="form-field">
          <label>{{ pt('Prep Minutes') }}</label>
          <InputNumber v-model="form.prep_minutes" :min="0" :max="240" class="w-full" />
        </div>

        <!-- Agriculture. The input type is what decides which licence the
             licence register expects to find behind this item, so it leads; the
             rest are what a farmer actually asks for at the counter. -->
        <div class="form-field">
          <label>{{ pt('Agri Input Type') }}</label>
          <Select
            v-model="form.agri_input_type"
            :options="AGRI_INPUT_TYPES"
            optionLabel="label"
            optionValue="value"
            showClear
            :placeholder="pt('Not an agri input')"
            class="w-full"
          />
        </div>
        <template v-if="form.agri_input_type">
          <div class="form-field">
            <label>{{ pt('Crop') }}</label>
            <InputText v-model="form.crop" class="w-full" :placeholder="pt('Cotton, wheat, groundnut')" />
          </div>
          <div class="form-field">
            <label>{{ pt('Variety / Grade') }}</label>
            <InputText v-model="form.variety" class="w-full" />
          </div>
          <div v-if="form.agri_input_type === 'pesticide'" class="form-field">
            <label>{{ pt('Technical Name') }}</label>
            <InputText v-model="form.technical_name" class="w-full" :placeholder="pt('Imidacloprid 17.8% SL')" />
            <small class="muted">{{ pt('The active ingredient — what a farmer and an inspector both ask for.') }}</small>
          </div>
          <div v-if="form.agri_input_type === 'fertilizer'" class="form-field">
            <label>{{ pt('Nutrient Content') }}</label>
            <InputText v-model="form.nutrient_content" class="w-full" :placeholder="pt('12:32:16')" />
          </div>
          <div v-if="form.agri_input_type === 'seed'" class="form-field">
            <label>{{ pt('Germination %') }}</label>
            <InputNumber v-model="form.germination_percent" :min="0" :max="100" :maxFractionDigits="2" class="w-full" />
            <small class="muted">{{ pt('As printed on the packet.') }}</small>
          </div>
        </template>

        <!-- Toys & Games. Two fields, and they are only worth anything together:
             the band says who the toy is for, the class says what is dangerous
             about it, and the till compares them. Hidden entirely unless the
             workspace has bands defined, because grading a spanner by age would
             be a strange thing to ask a hardware shop to do. -->
        <template v-if="showToyFields">
          <div class="form-field">
            <label>{{ pt('Age Band') }}</label>
            <Select
              v-model="form.toy_age_grade_id"
              :options="toyAgeGrades"
              optionLabel="label"
              optionValue="value"
              showClear
              filter
              :placeholder="pt('Not graded by age')"
              class="w-full"
            />
          </div>
          <div class="form-field">
            <label>{{ pt('Safety Class') }}</label>
            <Select
              v-model="form.toy_safety_category_id"
              :options="toySafetyClasses"
              optionLabel="label"
              optionValue="value"
              showClear
              filter
              :placeholder="pt('No specific hazard')"
              class="w-full"
            />
            <small class="muted">{{ pt('Decides the warning at the till — and, for a class that needs one, whether a lapsed certificate stops the sale.') }}</small>
          </div>
        </template>

        <!-- Books & Periodicals. The ISBN leads because it is the only field on
             this whole form the shop does not decide: it is assigned by the
             publisher, is the same in every shop on earth, and is also the
             barcode printed on the back cover — which is why typing it here is
             all that is needed to make the title scannable at the till. -->
        <template v-if="showBookFields">
          <div class="form-field">
            <label>{{ pt('ISBN-13') }}</label>
            <InputText v-model="form.book_isbn13" class="w-full" placeholder="978-93-5136-557-0" />
            <small class="muted">{{ pt('Type it as printed. Hyphens are stripped, the check digit is verified, and the number becomes the barcode.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('ISBN-10') }}</label>
            <InputText v-model="form.book_isbn10" class="w-full" placeholder="81-7992-122-7" />
            <small class="muted">{{ pt('Only for older titles. Given one, the 13-digit form is worked out from it.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Publisher') }}</label>
            <Select
              v-model="form.book_publisher_id"
              :options="bookPublishers"
              optionLabel="label"
              optionValue="value"
              showClear
              filter
              :placeholder="pt('No publisher')"
              class="w-full"
            />
          </div>
          <div class="form-field">
            <label>{{ pt('Language') }}</label>
            <Select
              v-model="form.book_language_id"
              :options="bookLanguages"
              optionLabel="label"
              optionValue="value"
              showClear
              :placeholder="pt('No language')"
              class="w-full"
            />
          </div>
          <div class="form-field">
            <label>{{ pt('Binding') }}</label>
            <Select
              v-model="form.book_binding"
              :options="bookBindings"
              optionLabel="label"
              optionValue="value"
              showClear
              :placeholder="pt('Not stated')"
              class="w-full"
            />
          </div>
          <div class="form-field">
            <label>{{ pt('Printed price') }}</label>
            <InputNumber v-model="form.book_printed_price" :min="0" :maxFractionDigits="2" class="w-full" />
            <!-- The customer can read this one off the cover, so any gap between
                 it and the selling price is a discount they can see. -->
            <small class="muted">{{ pt('The price on the cover. The selling price above is what this shop charges.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Edition') }}</label>
            <InputText v-model="form.book_edition" class="w-full" :placeholder="pt('3rd edition, revised')" />
          </div>
          <div class="form-field">
            <label>{{ pt('Pages') }}</label>
            <InputNumber v-model="form.book_pages" :min="1" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Published on') }}</label>
            <input type="date" v-model="form.book_published_on" class="date-input w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Series') }}</label>
            <InputText v-model="form.book_series" class="w-full" />
            <small class="muted">{{ pt('A customer who finished volume three asks for volume four by the series name.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Volume') }}</label>
            <InputText v-model="form.book_volume" class="w-full" placeholder="4" />
          </div>
        </template>

        <!-- Warranty policy. Set once on the article and copied onto every card
             issued against a sale of it, so a shop keys "6 months on the sole"
             here rather than on four hundred bills. -->
        <div class="form-field">
          <label>{{ pt('Warranty (months)') }}</label>
          <InputNumber v-model="form.warranty_months" :min="0" :max="600" class="w-full" />
          <small class="muted">{{ pt('Leave blank if the article carries no cover. 0 means sold without cover.') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Warranty terms') }}</label>
          <InputText
            v-model="form.warranty_terms"
            class="w-full"
            :placeholder="pt('Manufacturing defects only. Wear and tear excluded.')"
          />
        </div>

        <!-- The frame itself. Shown only once the article is marked a frame:
             shape, rim and material are what a customer asks for, and the three
             millimetre figures are the boxed marking stamped inside the temple. -->
        <template v-if="form.is_frame">
          <div class="form-field">
            <label>{{ pt('Frame Shape') }}</label>
            <Select v-model="form.frame_shape" :options="FRAME_SHAPES" optionLabel="label" optionValue="value" showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Rim') }}</label>
            <Select v-model="form.frame_rim" :options="FRAME_RIMS" optionLabel="label" optionValue="value" showClear class="w-full" />
            <small class="muted">{{ pt('A rimless frame cannot take every lens — the fitter needs this before glazing.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Frame Material') }}</label>
            <Select v-model="form.frame_material" :options="FRAME_MATERIALS" optionLabel="label" optionValue="value" showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Worn by') }}</label>
            <Select v-model="form.frame_gender" :options="FRAME_GENDERS" optionLabel="label" optionValue="value" showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Colour') }}</label>
            <InputText v-model="form.frame_colour" class="w-full" :placeholder="pt('Tortoise, matte black…')" />
          </div>
          <div class="form-field">
            <label>{{ pt('Tray') }}</label>
            <InputText v-model="form.frame_tray" class="w-full" :placeholder="pt('A-1')" />
            <small class="muted">{{ pt('Where it hangs. A shop with four hundred frames finds one by walking to it.') }}</small>
          </div>
          <div class="form-field">
            <label>{{ pt('Eye size (mm)') }}</label>
            <InputNumber v-model="form.frame_eye_size" :min="30" :max="80" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Bridge (mm)') }}</label>
            <InputNumber v-model="form.frame_bridge_size" :min="10" :max="30" class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Temple (mm)') }}</label>
            <InputNumber v-model="form.frame_temple_length" :min="100" :max="180" class="w-full" />
            <small class="muted">
              {{ pt('The boxed marking') }}:
              {{ (form.frame_eye_size ?? '—') + '▫' + (form.frame_bridge_size ?? '—') + (form.frame_temple_length ? '-' + form.frame_temple_length : '') }}
            </small>
          </div>
        </template>

        <div class="form-field full checkbox-group">
          <div class="checkbox-row">
            <Checkbox v-model="form.is_frame" binary inputId="is_frame" />
            <label for="is_frame">{{ pt('Spectacle Frame') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.track_batch" binary inputId="track_batch" />
            <label for="track_batch">{{ pt('Track Batch') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.track_serial" binary inputId="track_serial" />
            <label for="track_serial">{{ pt('Track Serial') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.track_expiry" binary inputId="track_expiry" />
            <label for="track_expiry">{{ pt('Track Expiry') }}</label>
          </div>
          <div class="checkbox-row">
            <Checkbox v-model="form.is_active" binary inputId="is_active" />
            <label for="is_active">{{ pt('Active') }}</label>
          </div>
        </div>

        <div v-if="editing" class="form-field full image-section">
          <label>{{ pt('Product Image') }}</label>
          <div class="image-row">
            <img v-if="editImageUrl" :src="editImageUrl" alt="Product image" class="preview" />
            <span v-else class="preview preview-placeholder"><i class="pi pi-image" /></span>
            <div class="image-controls">
              <input type="file" accept="image/*" :disabled="uploadingImage" @change="onImagePicked" />
              <small class="hint">{{ pt('JPG/PNG up to 2MB. Uploads immediately.') }}</small>
              <small v-if="uploadingImage" class="hint">{{ pt('Uploading...') }}</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Renders nothing until this tenant defines a field of its own. -->
      <CustomFieldsPanel entity="product" v-model="form.custom_fields" :errors="errors" />

      <template #footer>
        <Button :label="pt('Cancel')" text @click="dialogVisible = false" />
        <Button :label="editing ? pt('Update') : pt('Create')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <!-- Variants dialog -->
    <Dialog v-model:visible="variantsVisible" modal :header="pt('Variants — {name}', { name: variantsProduct?.name ?? '' })" :style="{ width: '720px' }">
      <div class="variants-toolbar">
        <Button v-if="canEdit" :label="pt('Add Variant')" icon="pi pi-plus" size="small" @click="openVariantCreate" />
      </div>

      <table class="variants-table">
        <thead>
          <tr><th>{{ pt('SKU') }}</th><th>{{ pt('Name') }}</th><th>{{ pt('Cost') }}</th><th>{{ pt('Price') }}</th><th>{{ pt('Active') }}</th><th style="width: 6rem"></th></tr>
        </thead>
        <tbody>
          <tr v-for="v in variants" :key="v.id">
            <td>{{ v.sku }}</td>
            <td>{{ v.name }}</td>
            <td>{{ money(v.cost_price) }}</td>
            <td>{{ money(v.selling_price) }}</td>
            <td><Tag :severity="v.is_active ? 'success' : 'danger'" :value="v.is_active ? pt('Yes') : pt('No')" /></td>
            <td class="actions-cell">
              <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openVariantEdit(v)" />
              <Button v-if="canDelete" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDeleteVariant(v)" />
            </td>
          </tr>
          <tr v-if="variantsLoading"><td colspan="6" class="empty-cell">{{ pt('Loading...') }}</td></tr>
          <tr v-else-if="!variants.length"><td colspan="6" class="empty-cell">{{ pt('No variants yet.') }}</td></tr>
        </tbody>
      </table>

      <template #footer>
        <Button :label="pt('Close')" text @click="variantsVisible = false" />
      </template>
    </Dialog>

    <!-- Variant create / edit sub-dialog -->
    <Dialog v-model:visible="variantFormVisible" modal :header="variantEditing ? pt('Edit Variant') : pt('Add Variant')" :style="{ width: '460px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>{{ pt('SKU') }} <span class="req">*</span></label>
          <InputText v-model="variantForm.sku" :class="{ invalid: variantError('sku') }" />
          <small v-if="variantError('sku')" class="error-text">{{ variantError('sku') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Name') }}</label>
          <InputText v-model="variantForm.name" :class="{ invalid: variantError('name') }" />
          <small v-if="variantError('name')" class="error-text">{{ variantError('name') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Barcode') }}</label>
          <div class="scan-field">
            <InputText v-model="variantForm.barcode" :class="{ invalid: variantError('barcode') }" />
            <CameraScanButton label="" text @scan="(c: string) => (variantForm.barcode = c)" />
          </div>
          <small v-if="variantError('barcode')" class="error-text">{{ variantError('barcode') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('RFID Tag') }}</label>
          <InputText v-model="variantForm.rfid_tag" :placeholder="pt('Scan or type EPC / tag ID')" :class="{ invalid: variantError('rfid_tag') }" />
          <small v-if="variantError('rfid_tag')" class="error-text">{{ variantError('rfid_tag') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Cost Price') }}</label>
          <InputNumber v-model="variantForm.cost_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: variantError('cost_price') }" />
          <small v-if="variantError('cost_price')" class="error-text">{{ variantError('cost_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Selling Price') }}</label>
          <InputNumber v-model="variantForm.selling_price" :minFractionDigits="2" :maxFractionDigits="2" :class="{ invalid: variantError('selling_price') }" />
          <small v-if="variantError('selling_price')" class="error-text">{{ variantError('selling_price') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Active') }}</label>
          <div class="checkbox-row">
            <Checkbox v-model="variantForm.is_active" binary inputId="variant_is_active" />
            <label for="variant_is_active">{{ pt('Enabled') }}</label>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="variantFormVisible = false" />
        <Button :label="variantEditing ? pt('Update') : pt('Create')" :loading="variantSaving" @click="saveVariant" />
      </template>
    </Dialog>

    <!-- Stone register (jewellery). The totals strip is the point of the screen:
         it is what the bill will actually charge for the stones. -->
    <Dialog v-model:visible="stonesVisible" modal :header="pt('Stones — {name}', { name: stonesProduct?.name ?? '' })" :style="{ width: '860px' }">
      <div class="variants-toolbar">
        <Button v-if="canEdit" :label="pt('Add Stone')" icon="pi pi-plus" size="small" @click="openStoneCreate" />
        <div v-if="stoneTotals" class="stone-totals">
          <span>{{ pt('Stone value') }}: <strong>{{ money(stoneTotals.value) }}</strong></span>
          <span>{{ pt('Stone weight') }}: <strong>{{ stoneTotals.weight }} g</strong></span>
        </div>
      </div>

      <table class="variants-table">
        <thead>
          <tr>
            <th>{{ pt('Stone') }}</th>
            <th class="num">{{ pt('Pcs') }}</th>
            <th class="num">{{ pt('Carats') }}</th>
            <th>{{ pt('Certificate') }}</th>
            <th class="num">{{ pt('Rate') }}</th>
            <th class="num">{{ pt('Value') }}</th>
            <th style="width: 6rem"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in stones" :key="s.id">
            <td>
              <div>{{ s.label }}</div>
              <small v-if="s.setting_type" class="muted">{{ s.setting_type }}</small>
            </td>
            <td class="num">{{ s.pieces }}</td>
            <td class="num">{{ s.carat_weight }}</td>
            <td>
              <template v-if="s.certificate_number">
                <span class="code-chip">{{ s.certifying_lab || pt('Cert') }} {{ s.certificate_number }}</span>
              </template>
              <span v-else class="muted">—</span>
            </td>
            <td class="num">{{ money(s.rate) }}</td>
            <td class="num">
              {{ money(s.value) }}
              <!-- The shop has overruled the arithmetic; say so rather than
                   leaving two numbers quietly apart. -->
              <small v-if="Math.abs(s.value - s.computed_value) > 0.01" class="muted calc-note">
                {{ pt('override, calc {amount}', { amount: money(s.computed_value) }) }}
              </small>
            </td>
            <td class="actions-cell">
              <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" @click="openStoneEdit(s)" />
              <Button v-if="canEdit" icon="pi pi-trash" text rounded severity="danger" size="small" @click="confirmDeleteStone(s)" />
            </td>
          </tr>
          <tr v-if="stonesLoading"><td colspan="7" class="empty-cell">{{ pt('Loading...') }}</td></tr>
          <tr v-else-if="!stones.length"><td colspan="7" class="empty-cell">{{ pt('No stones listed. The item is billed on its typed stone value.') }}</td></tr>
        </tbody>
      </table>

      <template #footer>
        <Button :label="pt('Close')" text @click="stonesVisible = false" />
      </template>
    </Dialog>

    <!-- Stone create / edit sub-dialog -->
    <Dialog v-model:visible="stoneFormVisible" modal :header="stoneEditing ? pt('Edit Stone') : pt('Add Stone')" :style="{ width: '560px' }">
      <div class="form-grid">
        <div class="form-field">
          <label>{{ pt('Stone') }} <span class="req">*</span></label>
          <Select v-model="stoneForm.stone_type" :options="STONE_TYPES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Shape') }}</label>
          <Select v-model="stoneForm.shape" :options="DIAMOND_SHAPES" editable showClear class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Pieces') }} <span class="req">*</span></label>
          <InputNumber v-model="stoneForm.pieces" :min="1" :class="{ invalid: stoneError('pieces') }" class="w-full" />
          <small v-if="stoneError('pieces')" class="error-text">{{ stoneError('pieces') }}</small>
        </div>
        <div class="form-field">
          <label>{{ pt('Carats (total)') }}</label>
          <InputNumber v-model="stoneForm.carat_weight" :min="0" :maxFractionDigits="3" class="w-full" />
          <small class="muted">{{ pt('For the whole line, not one stone.') }}</small>
        </div>

        <!-- The four Cs, for the one stone they mean anything for. -->
        <template v-if="stoneForm.stone_type === 'diamond'">
          <div class="form-field">
            <label>{{ pt('Colour') }}</label>
            <Select v-model="stoneForm.colour_grade" :options="DIAMOND_COLOURS" editable showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Clarity') }}</label>
            <Select v-model="stoneForm.clarity_grade" :options="DIAMOND_CLARITIES" editable showClear class="w-full" />
          </div>
          <div class="form-field">
            <label>{{ pt('Cut') }}</label>
            <Select v-model="stoneForm.cut_grade" :options="DIAMOND_CUTS" editable showClear class="w-full" />
          </div>
        </template>

        <div class="form-field">
          <label>{{ pt('Priced') }}</label>
          <Select v-model="stoneForm.rate_type" :options="STONE_RATE_TYPES" optionLabel="label" optionValue="value" class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Rate') }}</label>
          <InputNumber v-model="stoneForm.rate" :min="0" :maxFractionDigits="2" class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Value') }}</label>
          <InputNumber v-model="stoneForm.value" :min="0" :maxFractionDigits="2" :placeholder="String(stoneComputed)" class="w-full" />
          <small class="muted">{{ pt('Leave blank to use {amount} from the rate.', { amount: money(stoneComputed) }) }}</small>
        </div>

        <div class="form-field">
          <label>{{ pt('Certificate no.') }}</label>
          <InputText v-model="stoneForm.certificate_number" class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Certified by') }}</label>
          <Select v-model="stoneForm.certifying_lab" :options="CERT_LABS" editable showClear class="w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Certificate date') }}</label>
          <input type="date" v-model="stoneForm.certificate_date" class="date-input w-full" />
        </div>
        <div class="form-field">
          <label>{{ pt('Setting') }}</label>
          <InputText v-model="stoneForm.setting_type" :placeholder="pt('Prong, bezel, pave…')" class="w-full" />
        </div>
        <div class="form-field full">
          <label>{{ pt('Notes') }}</label>
          <InputText v-model="stoneForm.notes" class="w-full" />
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="stoneFormVisible = false" />
        <Button :label="stoneEditing ? pt('Update') : pt('Add')" :loading="stoneSaving" @click="saveStone" />
      </template>
    </Dialog>

    <!-- Barcode preview / quick print dialog -->
    <Dialog v-model:visible="barcodeVisible" modal :header="pt('Barcode — {name}', { name: barcodeProduct?.name ?? '' })" :style="{ width: '480px' }">
      <div v-if="!barcodeProduct?.barcode" class="barcode-empty">
        {{ pt('This product has no barcode value set.') }}
      </div>
      <div v-else class="barcode-body">
        <div v-if="barcodeLoading" class="barcode-loading">{{ pt('Loading...') }}</div>
        <div v-else class="barcode-images">
          <div class="barcode-image-box">
            <img v-if="barcodeImages.code128" :src="barcodeImages.code128" alt="CODE128 barcode" />
            <div class="barcode-value">{{ barcodeProduct.barcode }}</div>
          </div>
          <div class="barcode-image-box">
            <img v-if="barcodeImages.qr" :src="barcodeImages.qr" alt="QR code" class="qr-img" />
          </div>
        </div>
        <div class="barcode-print-row">
          <InputNumber v-model="barcodePrintQty" :min="1" :max="500" showButtons buttonLayout="horizontal" style="width: 9rem" />
          <Button :label="pt('Print Label')" icon="pi pi-print" :loading="barcodePrinting" @click="printBarcodeLabel" />
        </div>
      </div>
      <template #footer>
        <Button :label="pt('Close')" text @click="barcodeVisible = false" />
      </template>
    </Dialog>

    <!-- Read-only detail dialog -->
    <DetailDialog v-model:visible="detailVisible" :title="pt('Product Details')" :record="detailRecord" :fields="[
      { key: 'sku', label: pt('SKU') },
      { key: 'name', label: pt('Name') },
      { key: 'category.name', label: pt('Category') },
      { key: 'brand.name', label: pt('Brand') },
      { key: 'unit.short_name', label: pt('Unit') },
      { key: 'hsn_code', label: pt('HSN Code') },
      { key: 'barcode', label: pt('Barcode') },
      { key: 'cost_price', label: pt('Cost Price'), format: (v) => money(v) },
      { key: 'selling_price', label: pt('Selling Price'), format: (v) => money(v) },
      { key: 'tax_percent', label: pt('Tax %') },
      { key: 'reorder_level', label: pt('Reorder Level') },
      { key: 'is_active', label: pt('Active') },
      { key: 'description', label: pt('Description'), full: true },
    ]" />
  </div>
</template>

<style scoped>
/* Screen-specific bits only — the page head, toolbar card, table card and
   cell atoms (.entity-cell, .code-chip, .status-pill, .icon-btn…) all come
   from the shared listing design layer in src/styles/list-design.css. */
.filter {
  min-width: 170px;
}
.search :deep(input) {
  width: 320px;
  padding-right: 2rem;
}
.add-btn {
  font-weight: 600;
  box-shadow: 0 6px 16px rgba(232, 163, 61, 0.28);
}

@media (max-width: 640px) {
  .search :deep(input) {
    width: 100%;
  }
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  padding-top: 0.5rem;
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.form-field.full {
  grid-column: 1 / -1;
}
/* Text input + icon-only camera button on one row. */
.scan-field {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}
.scan-field :deep(input) {
  flex: 1;
  min-width: 0;
}
.form-field label {
  font-size: 0.85rem;
  font-weight: 500;
  color: #374151;
}
.form-field :deep(.p-inputtext),
.form-field :deep(.p-select),
.form-field :deep(.p-inputnumber) {
  width: 100%;
}
.checkbox-group {
  flex-direction: row;
  flex-wrap: wrap;
  gap: 1.5rem;
}
.checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.attr-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 1rem;
}
.attr-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}
.image-section {
  border-top: 1px solid #e5e7eb;
  padding-top: 1rem;
}
.image-row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
}
.preview {
  width: 120px;
  height: 120px;
  max-width: 120px;
  object-fit: cover;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.preview-placeholder {
  background: #f3f4f6;
  color: #9ca3af;
  font-size: 1.5rem;
}
.image-controls {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.hint {
  color: #6b7280;
  font-size: 0.78rem;
}
.variants-toolbar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  margin-bottom: 0.75rem;
}
/* Stone register: what the bill will charge for the stones, kept in view while
   the lines that produce it are edited. */
.stone-totals {
  margin-left: auto;
  display: flex;
  gap: 1.25rem;
  font-size: 0.85rem;
  color: #4b5563;
}
.calc-note {
  display: block;
  font-size: 0.75rem;
}
.date-input {
  padding: 0.55rem 0.7rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font: inherit;
}
.variants-table {
  width: 100%;
  border-collapse: collapse;
}
.variants-table th {
  text-align: left;
  font-size: 0.8rem;
  color: #6b7280;
  padding: 0.4rem;
  border-bottom: 1px solid #e5e7eb;
}
.variants-table td {
  padding: 0.35rem 0.4rem;
  vertical-align: middle;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.9rem;
}
.actions-cell {
  white-space: nowrap;
  text-align: right;
}
.empty-cell {
  text-align: center;
  color: #6b7280;
  padding: 1rem;
}
.req {
  color: #dc2626;
}
.error-text {
  color: #dc2626;
  font-size: 0.78rem;
}
.invalid :deep(.p-inputtext),
:deep(.invalid) {
  border-color: #dc2626 !important;
}
.barcode-empty {
  color: #6b7280;
  text-align: center;
  padding: 1.5rem 0;
}
.barcode-loading {
  color: #6b7280;
  text-align: center;
  padding: 1.5rem 0;
}
.barcode-images {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-around;
  align-items: center;
  gap: 1rem;
  padding: 1rem 0;
}
.barcode-image-box {
  text-align: center;
  max-width: 200px;
}
.barcode-image-box img {
  max-width: 100%;
  height: auto;
}
.barcode-value {
  font-size: 0.8rem;
  color: #374151;
  margin-top: 0.25rem;
  letter-spacing: 0.5px;
}
.qr-img {
  width: 120px;
  height: 120px;
}
.barcode-print-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  justify-content: center;
  border-top: 1px solid #f3f4f6;
  padding-top: 1rem;
}
</style>
