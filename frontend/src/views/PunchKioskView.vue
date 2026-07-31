<script setup lang="ts">
/**
 * Web punch kiosk — a browser stand-in for a wall-mounted biometric reader.
 * Park a laptop or tablet at the gate: the employee scans the barcode on their
 * ID card (device camera) or types their code, and one tap records a punch.
 * Direction defaults to the opposite of their last punch today, so the same
 * action works on the way in and on the way out.
 *
 * Punches land in the same biometric_punches table a real device fills, so
 * "Sync to Attendance" on the Biometric Punches screen rolls them up unchanged.
 */
import { ref, computed, onMounted, nextTick } from 'vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'
import CameraScanButton from '../components/CameraScanButton.vue'

const toast = useToast()
const pt = usePt()

const code = ref('')
const codeInput = ref<any>(null)
const busy = ref(false)

const directionOptions = computed(() => [
  { label: pt('Auto'), value: null },
  { label: pt('In'), value: 'in' },
  { label: pt('Out'), value: 'out' },
])
const direction = ref<string | null>(null)

const devices = ref<{ label: string; value: number }[]>([])
const deviceId = ref<number | null>(null)

// Last result, shown large so someone standing at the gate can read it.
const lastPunch = ref<any>(null)
const lastError = ref('')
// Today's punches recorded from this kiosk, newest first.
const feed = ref<any[]>([])

function focusCode() {
  nextTick(() => codeInput.value?.$el?.focus())
}

async function submit(value?: string) {
  const entered = (value ?? code.value).trim()
  if (!entered || busy.value) return

  busy.value = true
  lastError.value = ''
  try {
    const { data } = await http.post('/biometric-punches/punch', {
      code: entered,
      direction: direction.value ?? undefined,
      biometric_device_id: deviceId.value ?? undefined,
    })
    lastPunch.value = { ...data.punch, duplicate: data.duplicate }
    if (data.duplicate) {
      toast.add({ severity: 'info', summary: pt('Already punched'), detail: data.message, life: 2500 })
    } else {
      feed.value.unshift(data.punch)
      if (feed.value.length > 12) feed.value.pop()
      toast.add({ severity: 'success', summary: pt('Punched'), detail: data.message, life: 2500 })
    }
  } catch (e: any) {
    lastPunch.value = null
    lastError.value = e.response?.data?.message || pt('Punch failed')
    toast.add({ severity: 'error', summary: pt('Error'), detail: lastError.value, life: 3500 })
  } finally {
    busy.value = false
    code.value = ''
    focusCode()
  }
}

onMounted(async () => {
  try {
    const { data } = await http.get('/biometric-devices', { params: { per_page: 200 } })
    devices.value = (data.data ?? data).map((d: any) => ({ label: `${d.code} — ${d.name}`, value: d.id }))
  } catch {
    // A kiosk works without a device master; the punch just records no device.
  }
  focusCode()
})

function timeOnly(stamp: string | null) {
  return stamp ? stamp.slice(11, 16) : '—'
}
</script>

<template>
  <div>
    <PageHeader :title="pt('Punch Kiosk')"
      :subtitle="pt('Scan the barcode on an ID card or type the employee code. Punches sync to attendance exactly like a biometric device.')" />

    <div class="kiosk">
      <div class="entry-card">
        <span class="code-input">
          <i class="pi pi-id-card" />
          <InputText ref="codeInput" v-model="code" :placeholder="pt('Employee code or device user id')"
            autofocus autocomplete="off" @keyup.enter="submit()" />
        </span>
        <div class="entry-actions">
          <Button :label="pt('Punch')" icon="pi pi-check" size="large" :loading="busy" :disabled="!code.trim()" @click="submit()" />
          <CameraScanButton continuous size="large" :label="pt('Scan Card')" @scan="submit" />
        </div>

        <div class="entry-options">
          <SelectButton v-model="direction" :options="directionOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
          <Select v-model="deviceId" :options="devices" optionLabel="label" optionValue="value" filter showClear
            :placeholder="pt('Record against device (optional)')" class="device-select" />
        </div>
      </div>

      <div class="result-card" :class="{ ok: lastPunch && !lastPunch.duplicate, dup: lastPunch?.duplicate, bad: !!lastError }">
        <template v-if="lastPunch">
          <i :class="lastPunch.duplicate ? 'pi pi-clock' : 'pi pi-check-circle'" class="result-icon" />
          <div class="result-name">{{ lastPunch.employee_name }}</div>
          <Tag :value="lastPunch.direction === 'in' ? pt('IN') : pt('OUT')" :severity="lastPunch.direction === 'in' ? 'success' : 'warn'" />
          <div class="result-time">{{ lastPunch.punched_at }}</div>
          <div v-if="lastPunch.duplicate" class="result-note">{{ pt('Counted as the same tap.') }}</div>
        </template>
        <template v-else-if="lastError">
          <i class="pi pi-times-circle result-icon" />
          <div class="result-name">{{ lastError }}</div>
        </template>
        <template v-else>
          <i class="pi pi-id-card result-icon idle" />
          <div class="result-idle">{{ pt('Waiting for a card…') }}</div>
        </template>
      </div>
    </div>

    <div v-if="feed.length" class="feed">
      <h3>{{ pt('Recorded this session') }}</h3>
      <div v-for="p in feed" :key="p.id" class="feed-row">
        <Tag :value="p.direction === 'in' ? pt('IN') : pt('OUT')" :severity="p.direction === 'in' ? 'success' : 'warn'" />
        <span class="feed-name">{{ p.employee_name }}</span>
        <span class="feed-time">{{ timeOnly(p.punched_at) }}</span>
      </div>
      <p class="feed-hint">
        {{ pt('Go to Biometric Punches and press "Sync to Attendance" to turn these into attendance days.') }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.kiosk {
  display: grid;
  grid-template-columns: minmax(320px, 1fr) minmax(280px, 380px);
  gap: 1.25rem;
  align-items: start;
}
.entry-card,
.result-card,
.feed {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 1.25rem;
}
.code-input {
  position: relative;
  display: flex;
  align-items: center;
}
.code-input i {
  position: absolute;
  left: 0.85rem;
  color: #9ca3af;
  font-size: 1.1rem;
}
.code-input :deep(input) {
  width: 100%;
  padding: 0.9rem 0.9rem 0.9rem 2.6rem;
  font-size: 1.15rem;
}
.entry-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.85rem;
}
.entry-options {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid #f3f4f6;
}
.device-select {
  flex: 1;
  min-width: 220px;
}
.result-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  text-align: center;
  min-height: 240px;
}
.result-card.ok { border-color: #86efac; background: #f0fdf4; }
.result-card.dup { border-color: #fcd34d; background: #fffbeb; }
.result-card.bad { border-color: #fca5a5; background: #fef2f2; }
.result-icon { font-size: 2.5rem; color: #16a34a; }
.result-card.dup .result-icon { color: #d97706; }
.result-card.bad .result-icon { color: #dc2626; }
.result-icon.idle { color: #cbd5e1; }
.result-name { font-size: 1.1rem; font-weight: 600; color: #111827; }
.result-time { font-size: 0.85rem; color: #6b7280; }
.result-note { font-size: 0.78rem; color: #92400e; }
.result-idle { color: #94a3b8; }
.feed {
  margin-top: 1.25rem;
  max-width: 640px;
}
.feed h3 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; }
.feed-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid #f3f4f6;
}
.feed-name { flex: 1; font-size: 0.9rem; }
.feed-time { font-size: 0.85rem; color: #6b7280; font-variant-numeric: tabular-nums; }
.feed-hint { margin-top: 0.75rem; font-size: 0.78rem; color: #6b7280; }

@media (max-width: 860px) {
  .kiosk { grid-template-columns: 1fr; }
}
</style>
