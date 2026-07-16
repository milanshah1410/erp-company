<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import Swal from 'sweetalert2'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { formatCurrency } from '../utils/currency'
import TableExportButtons from '../components/TableExportButtons.vue'

const toast = useToast()
const auth = useAuthStore()

const month = ref<Date>(new Date())
const rows = ref<any[]>([])
const summary = ref<any>(null)
const loading = ref(false)
const generating = ref(false)
const paying = ref(false)

const canGenerate = computed(() => auth.can('payroll.create'))
const canPay = computed(() => auth.can('payroll.edit'))
const canDelete = computed(() => auth.can('payroll.delete'))

const draftCount = computed(() => rows.value.filter((r) => r.status === 'draft').length)

const slipVisible = ref(false)
const slip = ref<any>(null)

function money(v: any) { return formatCurrency(v) }
function monthString(d: Date): string { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01` }

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/payroll', { params: { month: monthString(month.value) } })
    rows.value = data.data ?? []
    summary.value = data.summary ?? null
  } finally {
    loading.value = false
  }
}

async function generate() {
  const res = await Swal.fire({
    title: `Generate payroll for ${month.value.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}?`,
    text: 'Draft payslips are (re)computed from each active salary structure. Already-paid payslips are left untouched.',
    icon: 'question', showCancelButton: true, confirmButtonText: 'Generate',
  })
  if (!res.isConfirmed) return
  generating.value = true
  try {
    const { data } = await http.post('/payroll/generate', { month: monthString(month.value) })
    toast.add({ severity: 'success', summary: 'Payroll generated', detail: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Generation failed', life: 4000 })
  } finally {
    generating.value = false
  }
}

async function pay(row: any) {
  try {
    await http.post(`/payroll/${row.id}/pay`)
    toast.add({ severity: 'success', summary: 'Paid', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  }
}

async function payAll() {
  const res = await Swal.fire({
    title: `Pay all ${draftCount.value} draft payslip(s)?`,
    text: 'This settles overtime, bonuses, advance recoveries and loan EMIs for the month.',
    icon: 'warning', showCancelButton: true, confirmButtonColor: '#16a34a', confirmButtonText: 'Pay All',
  })
  if (!res.isConfirmed) return
  paying.value = true
  try {
    const { data } = await http.post('/payroll/pay-all', { month: monthString(month.value) })
    toast.add({ severity: 'success', summary: 'Payroll paid', detail: data.message, life: 3500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
  } finally {
    paying.value = false
  }
}

async function removeSlip(row: any) {
  const res = await Swal.fire({ title: 'Delete this draft payslip?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' })
  if (!res.isConfirmed) return
  try {
    await http.delete(`/payroll/${row.id}`)
    toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
    await load()
  } catch (e: any) {
    toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Delete failed', life: 4000 })
  }
}

function openSlip(row: any) {
  slip.value = row
  slipVisible.value = true
}

onMounted(load)
</script>

<template>
  <div>
    <div class="header">
      <h1>Payroll Processing</h1>
      <div class="actions">
        <DatePicker v-model="month" view="month" dateFormat="MM yy" showIcon iconDisplay="input" @update:modelValue="load" />
        <Button v-if="canGenerate" label="Generate" icon="pi pi-cog" :loading="generating" @click="generate" />
        <Button v-if="canPay && draftCount > 0" label="Pay All" icon="pi pi-check-circle" severity="success" :loading="paying" @click="payAll" />
        <TableExportButtons title="Payroll" :rows="rows"
          :columns="[
            { key: 'employee_name', label: 'Employee' },
            { key: 'paid_days', label: 'Paid Days' },
            { key: 'gross', label: 'Gross' },
            { key: 'pf_employee', label: 'PF' },
            { key: 'esi_employee', label: 'ESI' },
            { key: 'professional_tax', label: 'PT' },
            { key: 'advance_deduction', label: 'Advance' },
            { key: 'loan_deduction', label: 'Loan' },
            { key: 'net_salary', label: 'Net' },
            { key: 'status', label: 'Status' },
          ]" />
      </div>
    </div>

    <div v-if="summary" class="tiles">
      <div class="tile"><span>Payslips</span><strong>{{ summary.count }}</strong></div>
      <div class="tile"><span>Gross</span><strong>{{ money(summary.gross) }}</strong></div>
      <div class="tile"><span>Net Payable</span><strong>{{ money(summary.net) }}</strong></div>
      <div class="tile"><span>PF (EE+ER)</span><strong>{{ money(summary.pf_employee + summary.pf_employer) }}</strong></div>
      <div class="tile"><span>ESI (EE+ER)</span><strong>{{ money(summary.esi_employee + summary.esi_employer) }}</strong></div>
      <div class="tile"><span>Prof. Tax</span><strong>{{ money(summary.professional_tax) }}</strong></div>
      <div class="tile"><span>Advance / Loan</span><strong>{{ money(summary.advance_deduction + summary.loan_deduction) }}</strong></div>
    </div>

    <DataTable :value="rows" :loading="loading" dataKey="id" class="p-datatable-sm" scrollable>
      <Column field="employee_name" header="Employee" frozen />
      <Column header="Paid Days"><template #body="{ data }">{{ data.paid_days }}/{{ data.working_days }}</template></Column>
      <Column header="Gross"><template #body="{ data }">{{ money(data.gross) }}</template></Column>
      <Column header="PF"><template #body="{ data }">{{ money(data.pf_employee) }}</template></Column>
      <Column header="ESI"><template #body="{ data }">{{ money(data.esi_employee) }}</template></Column>
      <Column header="PT"><template #body="{ data }">{{ money(data.professional_tax) }}</template></Column>
      <Column header="Advance"><template #body="{ data }">{{ money(data.advance_deduction) }}</template></Column>
      <Column header="Loan"><template #body="{ data }">{{ money(data.loan_deduction) }}</template></Column>
      <Column header="Net"><template #body="{ data }"><strong>{{ money(data.net_salary) }}</strong></template></Column>
      <Column header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="data.status === 'paid' ? 'success' : 'warn'" /></template></Column>
      <Column header="Actions" style="width: 12rem">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text rounded size="small" @click="openSlip(data)" />
          <Button v-if="canPay && data.status === 'draft'" label="Pay" icon="pi pi-check" text severity="success" size="small" @click="pay(data)" />
          <Button v-if="canDelete && data.status === 'draft'" icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeSlip(data)" />
        </template>
      </Column>
      <template #empty>No payslips for this month. Click Generate to create them.</template>
    </DataTable>

    <Dialog v-model:visible="slipVisible" modal :header="`Payslip — ${slip?.employee_name || ''}`" :style="{ width: '480px' }">
      <div v-if="slip" class="slip">
        <div class="slip-sec">
          <h3>Earnings</h3>
          <div class="line"><span>Basic</span><span>{{ money(slip.basic) }}</span></div>
          <div class="line"><span>HRA</span><span>{{ money(slip.hra) }}</span></div>
          <div class="line"><span>Conveyance</span><span>{{ money(slip.conveyance) }}</span></div>
          <div class="line"><span>Medical</span><span>{{ money(slip.medical) }}</span></div>
          <div class="line"><span>Special Allowance</span><span>{{ money(slip.special_allowance) }}</span></div>
          <div class="line"><span>Other</span><span>{{ money(slip.other_earnings) }}</span></div>
          <div class="line"><span>Overtime</span><span>{{ money(slip.overtime_amount) }}</span></div>
          <div class="line"><span>Bonus</span><span>{{ money(slip.bonus_amount) }}</span></div>
          <div class="line total"><span>Gross</span><span>{{ money(slip.gross) }}</span></div>
        </div>
        <div class="slip-sec">
          <h3>Deductions</h3>
          <div class="line"><span>PF (employee)</span><span>{{ money(slip.pf_employee) }}</span></div>
          <div class="line"><span>ESI (employee)</span><span>{{ money(slip.esi_employee) }}</span></div>
          <div class="line"><span>Professional Tax</span><span>{{ money(slip.professional_tax) }}</span></div>
          <div class="line"><span>TDS</span><span>{{ money(slip.tds) }}</span></div>
          <div class="line"><span>Advance Recovery</span><span>{{ money(slip.advance_deduction) }}</span></div>
          <div class="line"><span>Loan EMI</span><span>{{ money(slip.loan_deduction) }}</span></div>
          <div class="line total"><span>Total Deductions</span><span>{{ money(slip.deductions) }}</span></div>
        </div>
        <div class="line net"><span>Net Salary</span><span>{{ money(slip.net_salary) }}</span></div>
        <div class="line muted"><span>Employer PF / ESI</span><span>{{ money(slip.pf_employer) }} / {{ money(slip.esi_employer) }}</span></div>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.actions { display: flex; gap: 0.5rem; align-items: center; }
.tiles { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.tile { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.6rem 1rem; min-width: 120px; display: flex; flex-direction: column; }
.tile span { font-size: 0.72rem; color: #6b7280; }
.tile strong { font-size: 1.05rem; }
.slip { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.slip-sec h3 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.35rem; color: #374151; }
.line { display: flex; justify-content: space-between; padding: 0.2rem 0; font-size: 0.9rem; }
.line.total { border-top: 1px solid #e5e7eb; font-weight: 600; margin-top: 0.25rem; padding-top: 0.4rem; }
.line.net { border-top: 2px solid #111827; font-weight: 700; font-size: 1.05rem; padding-top: 0.5rem; }
.line.muted { color: #6b7280; font-size: 0.8rem; }
</style>
