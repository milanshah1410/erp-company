<script setup lang="ts">
// My Profile — every signed-in user can maintain their own record here, which is
// what lets the 3-step signup wizard stay minimal: name, phone, photo and
// password are all completable after the first login. Company-level details
// (GSTIN, address, financial year) live on the Companies screen and are linked
// from the footer card when the user may edit them.
import { ref, computed, onMounted } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'
import { useAuthStore } from '../stores/auth'
import { usePt } from '../i18n/pt'
import PageHeader from '../components/PageHeader.vue'

const toast = useToast()
const auth = useAuthStore()
const pt = usePt()

interface Profile {
  id: number
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  roles: string[]
  email_verified: boolean
  current_company: { id: number; name: string } | null
  current_branch: { id: number; name: string } | null
}

const profile = ref<Profile | null>(null)
const loading = ref(true)
const form = ref({ name: '', email: '', phone: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const pwd = ref({ current_password: '', password: '', password_confirmation: '' })
const pwdErrors = ref<Record<string, string[]>>({})
const pwdSaving = ref(false)

const uploading = ref(false)
const canEditCompany = computed(() => auth.can('companies.edit'))

const initials = computed(() =>
  (form.value.name || auth.user?.name || '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join(''),
)

/** True once the fields differ from what the server holds. */
const dirty = computed(() =>
  !!profile.value &&
  (form.value.name !== profile.value.name ||
    form.value.email !== profile.value.email ||
    (form.value.phone || '') !== (profile.value.phone || '')),
)

const incomplete = computed(() => !!profile.value && !profile.value.phone)

function apply(data: Profile) {
  profile.value = data
  form.value = { name: data.name, email: data.email, phone: data.phone ?? '' }
  // Keep the top bar / layout in sync with the edited identity.
  auth.fetchUser()
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/profile')
    apply(data.data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Could not load your profile'), life: 4000 })
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await http.put('/profile', form.value)
    apply(data.data)
    toast.add({ severity: 'success', summary: pt('Profile updated'), life: 2500 })
  } catch (e: any) {
    if (e.response?.status === 422) errors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  pwdSaving.value = true
  pwdErrors.value = {}
  try {
    await http.put('/profile/password', pwd.value)
    pwd.value = { current_password: '', password: '', password_confirmation: '' }
    toast.add({ severity: 'success', summary: pt('Password changed'), life: 2500 })
  } catch (e: any) {
    if (e.response?.status === 422) pwdErrors.value = e.response.data.errors || {}
    else toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
  } finally {
    pwdSaving.value = false
  }
}

async function onPhotoPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('avatar', file)
  uploading.value = true
  try {
    const { data } = await http.post('/profile/avatar', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    apply(data.data)
    toast.add({ severity: 'success', summary: pt('Photo updated'), life: 2500 })
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Photo upload failed'), life: 4000 })
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function removePhoto() {
  uploading.value = true
  try {
    const { data } = await http.delete('/profile/avatar')
    apply(data.data)
  } catch (e: any) {
    toast.add({ severity: 'error', summary: pt('Error'), detail: e.response?.data?.message || pt('Request failed'), life: 4000 })
  } finally {
    uploading.value = false
  }
}

function fieldError(key: string): string | null {
  return errors.value[key]?.[0] ?? null
}
function pwdError(key: string): string | null {
  return pwdErrors.value[key]?.[0] ?? null
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader :title="pt('My Profile')" :subtitle="pt('Your name, contact details, photo and password.')" />

    <div v-if="incomplete" class="nudge">
      <i class="pi pi-info-circle" />
      <span>{{ pt('Finish setting up your profile — add a phone number and a photo so your team recognises you.') }}</span>
    </div>

    <div class="profile-grid">
      <!-- Identity -->
      <section class="card">
        <h2>{{ pt('Personal details') }}</h2>

        <div class="photo-row">
          <img v-if="profile?.avatar_url" :src="profile.avatar_url" :alt="profile.name" class="avatar" />
          <span v-else class="avatar avatar-fallback">{{ initials }}</span>
          <div class="photo-actions">
            <label class="file-btn">
              <input type="file" accept="image/*" hidden :disabled="uploading" @change="onPhotoPicked" />
              {{ uploading ? pt('Uploading...') : (profile?.avatar_url ? pt('Change photo') : pt('Upload photo')) }}
            </label>
            <button v-if="profile?.avatar_url" type="button" class="link-btn" :disabled="uploading" @click="removePhoto">
              {{ pt('Remove') }}
            </button>
            <small class="muted">{{ pt('JPG/PNG up to 2MB.') }}</small>
          </div>
        </div>

        <div class="field">
          <label>{{ pt('Full name') }} <span class="req">*</span></label>
          <InputText v-model="form.name" :class="{ invalid: fieldError('name') }" />
          <small v-if="fieldError('name')" class="error-text">{{ fieldError('name') }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Email') }} <span class="req">*</span></label>
          <InputText v-model="form.email" :class="{ invalid: fieldError('email') }" />
          <small v-if="fieldError('email')" class="error-text">{{ fieldError('email') }}</small>
          <small v-else class="muted">{{ pt('This is your login. Changing it needs re-verification.') }}</small>
        </div>

        <div class="field">
          <label>{{ pt('Phone') }}</label>
          <InputText v-model="form.phone" placeholder="+91…" :class="{ invalid: fieldError('phone') }" />
          <small v-if="fieldError('phone')" class="error-text">{{ fieldError('phone') }}</small>
        </div>

        <div class="card-foot">
          <Button :label="pt('Save changes')" icon="pi pi-check" :loading="saving" :disabled="!dirty" @click="save" />
        </div>
      </section>

      <!-- Password + context -->
      <div class="side">
        <section class="card">
          <h2>{{ pt('Change password') }}</h2>

          <div class="field">
            <label>{{ pt('Current password') }} <span class="req">*</span></label>
            <Password v-model="pwd.current_password" toggleMask :feedback="false" :class="{ invalid: pwdError('current_password') }" />
            <small v-if="pwdError('current_password')" class="error-text">{{ pwdError('current_password') }}</small>
          </div>

          <div class="field">
            <label>{{ pt('New password') }} <span class="req">*</span></label>
            <Password v-model="pwd.password" toggleMask :class="{ invalid: pwdError('password') }" />
            <small v-if="pwdError('password')" class="error-text">{{ pwdError('password') }}</small>
            <small v-else class="muted">{{ pt('Minimum 8 characters.') }}</small>
          </div>

          <div class="field">
            <label>{{ pt('Confirm new password') }} <span class="req">*</span></label>
            <Password v-model="pwd.password_confirmation" toggleMask :feedback="false" />
          </div>

          <div class="card-foot">
            <Button
              :label="pt('Change password')"
              icon="pi pi-lock"
              severity="secondary"
              :loading="pwdSaving"
              :disabled="!pwd.current_password || pwd.password.length < 8"
              @click="changePassword"
            />
          </div>
        </section>

        <section class="card">
          <h2>{{ pt('Your access') }}</h2>
          <div class="rows">
            <div class="row"><span>{{ pt('Role') }}</span><b>{{ profile?.roles?.join(', ') || '—' }}</b></div>
            <div class="row"><span>{{ pt('Company') }}</span><b>{{ profile?.current_company?.name || '—' }}</b></div>
            <div class="row"><span>{{ pt('Branch') }}</span><b>{{ profile?.current_branch?.name || '—' }}</b></div>
            <div class="row">
              <span>{{ pt('Email verified') }}</span>
              <b>
                <span class="status-pill" :class="profile?.email_verified ? 'is-on' : 'is-warn'">
                  <i class="dot" />{{ profile?.email_verified ? pt('Yes') : pt('Pending') }}
                </span>
              </b>
            </div>
          </div>
          <p v-if="canEditCompany" class="muted foot-note">
            {{ pt('Company details (GSTIN, address, financial year) live under') }}
            <RouterLink to="/companies">{{ pt('Companies') }}</RouterLink>.
          </p>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nudge {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
  padding: 0.7rem 0.9rem;
  border-radius: 12px;
  font-size: 0.85rem;
  color: #8a5a12;
  background: color-mix(in srgb, var(--safety-amber, #e8a33d) 14%, transparent);
  border: 1px solid color-mix(in srgb, var(--safety-amber, #e8a33d) 35%, transparent);
}
.profile-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 1.1rem;
  align-items: start;
}
.side {
  display: grid;
  gap: 1.1rem;
}
.card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e3ded3);
  border-radius: 16px;
  padding: 1.1rem 1.2rem 0.9rem;
  box-shadow: var(--card-shadow, 0 4px 18px rgba(30, 35, 40, 0.05));
}
.card h2 {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 0.9rem;
}
.photo-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding-bottom: 1rem;
  margin-bottom: 0.4rem;
  border-bottom: 1px dashed var(--border, #e3ded3);
}
.avatar {
  width: 72px;
  height: 72px;
  flex: 0 0 72px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid var(--border, #e3ded3);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.avatar-fallback {
  font-size: 1.4rem;
  font-weight: 700;
  color: #23292f;
  background: linear-gradient(135deg, var(--safety-amber-light, #f2c274), var(--safety-amber, #e8a33d));
  border-color: transparent;
}
.photo-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
}
.file-btn {
  display: inline-block;
  border: 1px solid var(--border, #d8d3c8);
  border-radius: 9px;
  padding: 0.45rem 0.85rem;
  font-size: 0.83rem;
  font-weight: 600;
  color: var(--ink-blue, #2c4a5e);
  cursor: pointer;
}
.file-btn:hover {
  border-color: var(--safety-amber, #e8a33d);
}
.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--alert-rust, #c1502e);
  cursor: pointer;
  text-decoration: underline;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin-top: 0.85rem;
}
.field label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--graphite, #374151);
}
.field :deep(.p-inputtext),
.field :deep(.p-password) {
  width: 100%;
}
.req {
  color: var(--alert-rust, #c1502e);
}
.muted {
  color: var(--muted, #6f7680);
  font-size: 0.76rem;
}
.error-text {
  color: var(--alert-rust, #c1502e);
  font-size: 0.76rem;
}
.invalid :deep(.p-inputtext) {
  border-color: var(--alert-rust, #c1502e) !important;
}
.card-foot {
  display: flex;
  justify-content: flex-end;
  margin-top: 1.1rem;
  padding-top: 0.8rem;
  border-top: 1px solid var(--border, #e3ded3);
}
.rows {
  display: grid;
  gap: 0.1rem;
}
.rows .row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.45rem 0;
  font-size: 0.85rem;
  border-bottom: 1px solid var(--border, #f0ede6);
}
.rows .row:last-child {
  border-bottom: none;
}
.rows .row span {
  color: var(--muted, #6f7680);
}
.foot-note {
  margin: 0.8rem 0 0;
}
.foot-note a {
  color: var(--ink-blue, #2c4a5e);
  font-weight: 600;
}

@media (max-width: 900px) {
  .profile-grid {
    grid-template-columns: 1fr;
  }
}
</style>
