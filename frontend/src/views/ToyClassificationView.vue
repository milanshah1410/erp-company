<script setup lang="ts">
/**
 * How a toy shop labels its range: the age bands, and the safety classes.
 *
 * One component, two routes (/toys/age-grades and /toys/safety-classes), because
 * the two halves are one decision to whoever makes it — the person who says an
 * article has small parts is the person who says it is not for under-3s — and
 * splitting them would have meant two copies of the same table, the same
 * in-use guard and the same months-to-words helper.
 *
 * The screen is built around the mistake it exists to catch. An age band and a
 * safety class are each harmless on their own; read together they say whether a
 * choking hazard has been graded onto the toddler shelf. So the safety class
 * carries an age floor, and the counter check compares the two.
 *
 * One control here is not cosmetic: "Refuse the sale" on a safety class turns it
 * from a label into a gate — an article carrying it cannot be billed while its
 * certificate is missing or lapsed. Correct for mandatory-certification goods,
 * wrong for a shop still capturing its catalogue, which is why it is a per-class
 * decision and off by default.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import TableExportButtons from '../components/TableExportButtons.vue'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const pt = usePt()

const canCreate = computed(() => auth.can('toy-safety.create'))
const canEdit = computed(() => auth.can('toy-safety.edit'))
const canDelete = computed(() => auth.can('toy-safety.delete'))

/** Which half is open. Driven by the route so both menu rows land right. */
const tab = computed<'ages' | 'classes'>(() =>
  route.path.endsWith('/safety-classes') ? 'classes' : 'ages',
)

function goTo(next: 'ages' | 'classes') {
  router.push(next === 'classes' ? '/toys/safety-classes' : '/toys/age-grades')
}

// ---- Shared ---------------------------------------------------------------

/** 18 → "18 months"; 36 → "3 years". The same rule the backend prints with. */
function describeMonths(months: number | null) {
  if (months === null || months === undefined) return '—'
  if (months < 24) return `${months} ${months === 1 ? pt('month') : pt('months')}`
  const years = Math.floor(months / 12)
  const rest = months % 12
  const text = `${years} ${years === 1 ? pt('year') : pt('years')}`
  return rest ? `${text} ${rest} ${rest === 1 ? pt('month') : pt('months')}` : text
}

const errors = ref<Record<string, string[]>>({})

function reportSaveError(e: any, fallback: string) {
  errors.value = e.response?.data?.errors ?? {}
  toast.add({ severity: 'error', summary: e.response?.data?.message ?? pt(fallback), life: 3500 })
}

// ---- Age bands ------------------------------------------------------------

const grades = ref<any[]>([])
const gradesLoading = ref(false)

async function loadGrades() {
  gradesLoading.value = true
  try {
    const { data } = await http.get('/toys/age-grades')
    grades.value = data.data
  } finally {
    gradesLoading.value = false
  }
}

const gradeDialog = ref(false)
const gradeSaving = ref(false)
const gradeId = ref<number | null>(null)

const blankGrade = () => ({
  code: '',
  label: '',
  min_months: 0 as number | null,
  max_months: null as number | null,
  warning: '',
  requires_supervision: false,
  description: '',
  sort_order: 0 as number | null,
  is_active: true,
})

const gradeForm = ref(blankGrade())

function openGradeCreate() {
  gradeForm.value = blankGrade()
  gradeId.value = null
  errors.value = {}
  gradeDialog.value = true
}

function openGradeEdit(row: any) {
  errors.value = {}
  gradeId.value = row.id
  gradeForm.value = {
    code: row.code,
    label: row.label,
    min_months: row.min_months,
    max_months: row.max_months,
    warning: row.warning ?? '',
    requires_supervision: row.requires_supervision,
    description: row.description ?? '',
    sort_order: row.sort_order,
    is_active: row.is_active,
  }
  gradeDialog.value = true
}

async function saveGrade() {
  gradeSaving.value = true
  errors.value = {}
  try {
    if (gradeId.value) {
      await http.put(`/toys/age-grades/${gradeId.value}`, gradeForm.value)
    } else {
      await http.post('/toys/age-grades', gradeForm.value)
    }
    gradeDialog.value = false
    await loadGrades()
    toast.add({ severity: 'success', summary: pt('Age band saved'), life: 2500 })
  } catch (e: any) {
    reportSaveError(e, 'Could not save the age band')
  } finally {
    gradeSaving.value = false
  }
}

async function removeGrade(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this age band?'),
    text: pt('Only bands with no articles graded into them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/toys/age-grades/${row.id}`)
    await loadGrades()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// ---- Safety classes -------------------------------------------------------

const classes = ref<any[]>([])
const classesLoading = ref(false)
const standards = ref<Record<string, string>>({})
const hazards = ref<Record<string, string>>({})

const standardOptions = computed(() =>
  Object.entries(standards.value).map(([value, label]) => ({ label, value })),
)
const hazardOptions = computed(() =>
  Object.entries(hazards.value).map(([value, label]) => ({ label, value })),
)

async function loadClasses() {
  classesLoading.value = true
  try {
    const { data } = await http.get('/toys/safety-categories')
    classes.value = data.data
    standards.value = data.standards
    hazards.value = data.hazards
  } finally {
    classesLoading.value = false
  }
}

const classDialog = ref(false)
const classSaving = ref(false)
const classId = ref<number | null>(null)

const blankClass = () => ({
  code: '',
  label: '',
  standard: 'bis',
  hazards: [] as string[],
  warning_text: '',
  min_age_months: null as number | null,
  requires_certificate: true,
  blocks_sale_without_certificate: false,
  sort_order: 0 as number | null,
  is_active: true,
})

const classForm = ref(blankClass())

function openClassCreate() {
  classForm.value = blankClass()
  classId.value = null
  errors.value = {}
  classDialog.value = true
}

function openClassEdit(row: any) {
  errors.value = {}
  classId.value = row.id
  classForm.value = {
    code: row.code,
    label: row.label,
    standard: row.standard,
    hazards: [...(row.hazards ?? [])],
    warning_text: row.warning_text ?? '',
    min_age_months: row.min_age_months,
    requires_certificate: row.requires_certificate,
    blocks_sale_without_certificate: row.blocks_sale_without_certificate,
    sort_order: row.sort_order,
    is_active: row.is_active,
  }
  classDialog.value = true
}

async function saveClass() {
  classSaving.value = true
  errors.value = {}
  try {
    if (classId.value) {
      await http.put(`/toys/safety-categories/${classId.value}`, classForm.value)
    } else {
      await http.post('/toys/safety-categories', classForm.value)
    }
    classDialog.value = false
    await loadClasses()
    toast.add({ severity: 'success', summary: pt('Safety class saved'), life: 2500 })
  } catch (e: any) {
    reportSaveError(e, 'Could not save the safety class')
  } finally {
    classSaving.value = false
  }
}

async function removeClass(row: any) {
  const res = await Swal.fire({
    title: pt('Delete this safety class?'),
    text: pt('Only classes with no articles under them can be deleted.'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: pt('Delete'),
    cancelButtonText: pt('Cancel'),
    confirmButtonColor: '#b91c1c',
  })
  if (!res.isConfirmed) return

  try {
    await http.delete(`/toys/safety-categories/${row.id}`)
    await loadClasses()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: e.response?.data?.message, life: 4000 })
  }
}

// Both halves are small and both are read by the other's editor (the age floor
// on a class only means anything against the bands), so both load up front and
// the tab is only a view.
onMounted(() => {
  loadGrades()
  loadClasses()
})

watch(tab, () => {
  errors.value = {}
})
</script>

<template>
  <div>
    <PageHeader
      :title="pt('Toy Classification')"
      :subtitle="pt('Who each toy is for, and what is dangerous about it')"
    >
      <template #actions>
        <Button
          v-if="canCreate && tab === 'ages'"
          :label="pt('Add Age Band')"
          icon="pi pi-plus"
          @click="openGradeCreate"
        />
        <Button
          v-if="canCreate && tab === 'classes'"
          :label="pt('Add Safety Class')"
          icon="pi pi-plus"
          @click="openClassCreate"
        />
      </template>
    </PageHeader>

    <div class="tabs">
      <button :class="{ on: tab === 'ages' }" @click="goTo('ages')">
        <i class="pi pi-users" /> {{ pt('Age Bands') }}
      </button>
      <button :class="{ on: tab === 'classes' }" @click="goTo('classes')">
        <i class="pi pi-exclamation-triangle" /> {{ pt('Safety Classes') }}
      </button>
    </div>

    <!-- ================= Age bands ================= -->
    <template v-if="tab === 'ages'">
      <div class="toolbar">
        <TableExportButtons
          title="Toy Age Bands"
          filenameBase="toy-age-grades"
          :rows="grades"
          :columns="[
            { key: 'code', label: 'Code' },
            { key: 'label', label: 'Band' },
            { key: 'range', label: 'Range' },
            { key: 'warning', label: 'Warning' },
            { key: 'products_count', label: 'Articles' },
          ]"
        />
      </div>

      <DataTable
        :value="grades"
        :loading="gradesLoading"
        data-key="id"
        striped-rows
        responsive-layout="scroll"
      >
        <Column field="label" :header="pt('Band')" sortable>
          <template #body="{ data }">
            <div class="strong">{{ data.label }}</div>
            <small class="muted mono">{{ data.code }}</small>
          </template>
        </Column>

        <Column field="min_months" :header="pt('Range')" sortable>
          <template #body="{ data }">{{ data.range }}</template>
        </Column>

        <Column field="warning" :header="pt('What the label says')">
          <template #body="{ data }">
            <span v-if="data.warning">{{ data.warning }}</span>
            <span v-else class="muted">—</span>
            <div v-if="data.requires_supervision">
              <Tag :value="pt('Adult supervision')" severity="warn" />
            </div>
          </template>
        </Column>

        <Column field="products_count" :header="pt('Articles')" sortable />

        <Column field="is_active" :header="pt('Status')" sortable>
          <template #body="{ data }">
            <Tag
              :value="data.is_active ? pt('In use') : pt('Retired')"
              :severity="data.is_active ? 'success' : 'secondary'"
            />
          </template>
        </Column>

        <Column :header="pt('Actions')" style="width: 8rem">
          <template #body="{ data }">
            <Button
              v-if="canEdit"
              icon="pi pi-pencil"
              text
              rounded
              :title="pt('Edit')"
              @click="openGradeEdit(data)"
            />
            <Button
              v-if="canDelete && !data.products_count"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              :title="pt('Delete')"
              @click="removeGrade(data)"
            />
          </template>
        </Column>

        <template #empty>
          <div class="empty-state">
            {{ pt('No age bands yet. Until a toy is graded, nothing on the shelf edge or at the till can say who it is for.') }}
          </div>
        </template>
      </DataTable>
    </template>

    <!-- ================= Safety classes ================= -->
    <template v-else>
      <div class="toolbar">
        <TableExportButtons
          title="Toy Safety Classes"
          filenameBase="toy-safety-classes"
          :rows="classes"
          :columns="[
            { key: 'code', label: 'Code' },
            { key: 'label', label: 'Class' },
            { key: 'standard', label: 'Standard' },
            { key: 'min_age_months', label: 'Age floor (months)' },
            { key: 'products_count', label: 'Articles' },
          ]"
        />
      </div>

      <DataTable
        :value="classes"
        :loading="classesLoading"
        data-key="id"
        striped-rows
        responsive-layout="scroll"
      >
        <Column field="label" :header="pt('Class')" sortable>
          <template #body="{ data }">
            <div class="strong">{{ data.label }}</div>
            <small class="muted mono">{{ data.code }}</small>
          </template>
        </Column>

        <Column field="standard" :header="pt('Standard')" sortable>
          <template #body="{ data }">{{ data.standard_label }}</template>
        </Column>

        <Column field="hazards" :header="pt('Hazards')">
          <template #body="{ data }">
            <div v-if="data.hazard_labels.length" class="chips">
              <Tag
                v-for="h in data.hazard_labels"
                :key="h"
                :value="h"
                severity="warn"
              />
            </div>
            <span v-else class="muted">—</span>
          </template>
        </Column>

        <Column field="min_age_months" :header="pt('Not below')" sortable>
          <template #body="{ data }">
            <span v-if="data.min_age_months">{{ describeMonths(data.min_age_months) }}</span>
            <span v-else class="muted">—</span>
          </template>
        </Column>

        <Column field="blocks_sale_without_certificate" :header="pt('Uncertified')">
          <template #body="{ data }">
            <Tag
              v-if="!data.requires_certificate"
              :value="pt('No certificate needed')"
              severity="secondary"
            />
            <Tag
              v-else-if="data.blocks_sale_without_certificate"
              :value="pt('Refuses the sale')"
              severity="danger"
            />
            <Tag v-else :value="pt('Warns only')" severity="warn" />
          </template>
        </Column>

        <Column field="products_count" :header="pt('Articles')" sortable />

        <Column :header="pt('Actions')" style="width: 8rem">
          <template #body="{ data }">
            <Button
              v-if="canEdit"
              icon="pi pi-pencil"
              text
              rounded
              :title="pt('Edit')"
              @click="openClassEdit(data)"
            />
            <Button
              v-if="canDelete && !data.products_count"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              :title="pt('Delete')"
              @click="removeClass(data)"
            />
          </template>
        </Column>

        <template #empty>
          <div class="empty-state">
            {{ pt('No safety classes yet. This is where a shop records what is dangerous about a toy and which certificate covers it.') }}
          </div>
        </template>
      </DataTable>
    </template>

    <!-- Age band editor -->
    <Dialog
      v-model:visible="gradeDialog"
      :header="gradeId ? pt('Edit Age Band') : pt('Add Age Band')"
      modal
      :style="{ width: '680px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Code') }} *</label>
          <InputText v-model="gradeForm.code" class="w-full mono" placeholder="3PLUS" />
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Band') }} *</label>
          <InputText v-model="gradeForm.label" class="w-full" :placeholder="pt('3 years and over')" />
          <small v-if="errors.label" class="err">{{ errors.label[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Sort order') }}</label>
          <InputNumber v-model="gradeForm.sort_order" :min="0" class="w-full" />
        </div>

        <div class="fld">
          <label>{{ pt('From (months)') }} *</label>
          <InputNumber v-model="gradeForm.min_months" :min="0" :max="216" class="w-full" />
          <small class="hint">{{ describeMonths(gradeForm.min_months ?? 0) }}</small>
          <small v-if="errors.min_months" class="err">{{ errors.min_months[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('To (months)') }}</label>
          <InputNumber v-model="gradeForm.max_months" :min="0" :max="216" class="w-full" />
          <small class="hint">{{ pt('Leave blank for an open band such as "14+".') }}</small>
          <small v-if="errors.max_months" class="err">{{ errors.max_months[0] }}</small>
        </div>
        <div class="fld check">
          <Checkbox v-model="gradeForm.requires_supervision" binary input-id="grade-sup" />
          <label for="grade-sup">{{ pt('Needs adult supervision') }}</label>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Warning') }}</label>
          <InputText
            v-model="gradeForm.warning"
            class="w-full"
            :placeholder="pt('Not suitable for children under 3 years — small parts.')"
          />
          <small class="hint">{{ pt('Printed on the shelf label and shown at the till.') }}</small>
        </div>
        <div class="fld span-2">
          <label>{{ pt('Notes') }}</label>
          <Textarea v-model="gradeForm.description" rows="2" auto-resize class="w-full" />
        </div>
        <div class="fld check">
          <Checkbox v-model="gradeForm.is_active" binary input-id="grade-active" />
          <label for="grade-active">{{ pt('Offer this band when grading articles') }}</label>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="gradeDialog = false" />
        <Button :label="pt('Save')" :loading="gradeSaving" @click="saveGrade" />
      </template>
    </Dialog>

    <!-- Safety class editor -->
    <Dialog
      v-model:visible="classDialog"
      :header="classId ? pt('Edit Safety Class') : pt('Add Safety Class')"
      modal
      :style="{ width: '740px' }"
    >
      <div class="grid">
        <div class="fld">
          <label>{{ pt('Code') }} *</label>
          <InputText v-model="classForm.code" class="w-full mono" placeholder="TOY-SMALL" />
          <small v-if="errors.code" class="err">{{ errors.code[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Class') }} *</label>
          <InputText v-model="classForm.label" class="w-full" :placeholder="pt('Contains small parts')" />
          <small v-if="errors.label" class="err">{{ errors.label[0] }}</small>
        </div>
        <div class="fld">
          <label>{{ pt('Standard') }} *</label>
          <Select
            v-model="classForm.standard"
            :options="standardOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <div class="fld span-2">
          <label>{{ pt('Hazards') }}</label>
          <MultiSelect
            v-model="classForm.hazards"
            :options="hazardOptions"
            option-label="label"
            option-value="value"
            display="chip"
            filter
            class="w-full"
            :placeholder="pt('What is dangerous about it')"
          />
        </div>
        <div class="fld">
          <label>{{ pt('Not below (months)') }}</label>
          <InputNumber v-model="classForm.min_age_months" :min="0" :max="216" class="w-full" />
          <small class="hint">{{ pt('The till warns when an article in this class is graded below this age.') }}</small>
        </div>

        <div class="fld span-2">
          <label>{{ pt('Warning') }}</label>
          <InputText
            v-model="classForm.warning_text"
            class="w-full"
            :placeholder="pt('Choking hazard — small parts. Not for children under 3 years.')"
          />
          <small class="hint">{{ pt('Left blank, one is assembled from the age floor and the hazards.') }}</small>
        </div>

        <div class="fld check">
          <Checkbox v-model="classForm.requires_certificate" binary input-id="cls-cert" />
          <label for="cls-cert">{{ pt('Needs a safety certificate') }}</label>
        </div>
        <div class="fld check">
          <Checkbox
            v-model="classForm.blocks_sale_without_certificate"
            binary
            input-id="cls-block"
            :disabled="!classForm.requires_certificate"
          />
          <label for="cls-block">{{ pt('Refuse the sale when the certificate is missing or lapsed') }}</label>
        </div>
        <div class="fld check">
          <Checkbox v-model="classForm.is_active" binary input-id="cls-active" />
          <label for="cls-active">{{ pt('Offer this class when classifying articles') }}</label>
        </div>

        <div v-if="classForm.blocks_sale_without_certificate" class="fld span-2">
          <Message severity="warn" :closable="false">
            {{ pt('The till will stop any bill carrying an article of this class while its certificate is missing or expired. Correct for goods that legally need one — check the certificate register is up to date before switching it on.') }}
          </Message>
        </div>
      </div>

      <template #footer>
        <Button :label="pt('Cancel')" text @click="classDialog = false" />
        <Button :label="pt('Save')" :loading="classSaving" @click="saveClass" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 0.4rem; margin-bottom: 1rem; border-bottom: 1px solid var(--surface-border, #e5e7eb); }
.tabs button {
  background: none; border: none; border-bottom: 2px solid transparent;
  padding: 0.55rem 0.9rem; cursor: pointer; font-size: 0.85rem; color: #6b7280;
  display: flex; align-items: center; gap: 0.4rem;
}
.tabs button.on { color: var(--p-primary-color, #4f46e5); border-bottom-color: var(--p-primary-color, #4f46e5); font-weight: 600; }

.toolbar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; }
.span-2 { grid-column: 1 / -1; }
.fld { display: flex; flex-direction: column; gap: 0.3rem; }
.fld label { font-size: 0.78rem; color: #374151; }
.fld.check { flex-direction: row; align-items: center; gap: 0.5rem; }
.chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.muted { color: #6b7280; }
.strong { font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
.hint { font-size: 0.7rem; color: #6b7280; }
.err { color: #b91c1c; font-size: 0.72rem; }
.w-full { width: 100%; }
.empty-state { padding: 1.5rem; text-align: center; color: #6b7280; }
</style>
