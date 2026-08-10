<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'

/**
 * Public, unauthenticated customer feedback page (retail base §6). Reached at
 * /feedback/:token from the link an SMS carried.
 *
 * Bare axios, like PayView: there is no logged-in user here and no bearer token
 * to send.
 *
 * Designed for a thumb on a phone in a car park, which drives every choice on
 * it: five large faces instead of a slider, chips instead of checkboxes, the
 * comment optional and last, and one button. A star rating that needs a precise
 * tap and a form that asks for an email address are how a shop gets a 4% response
 * rate.
 */
const route = useRoute()
const token = route.params.token as string

const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const info = ref<any>(null)
const done = ref(false)

const rating = ref<number | null>(null)
const comment = ref('')
const tags = ref<string[]>([])

/** Faces, not stars: unambiguous at a glance and in any language. */
const FACES = [
  { value: 1, icon: '😠', label: 'Bad' },
  { value: 2, icon: '🙁', label: 'Poor' },
  { value: 3, icon: '😐', label: 'Okay' },
  { value: 4, icon: '🙂', label: 'Good' },
  { value: 5, icon: '😄', label: 'Great' },
]

const tagOptions = computed<string[]>(() => info.value?.tag_options ?? [])

/**
 * Only ask what it was about once there is a rating, and word the prompt to
 * match: "what went wrong" and "what did we get right" are different questions,
 * and asking the first of a happy customer reads as an apology for nothing.
 */
const tagPrompt = computed(() =>
  rating.value !== null && rating.value <= 3 ? 'What let us down?' : 'What did we get right?',
)

function toggleTag(tag: string) {
  const i = tags.value.indexOf(tag)
  if (i >= 0) tags.value.splice(i, 1)
  else if (tags.value.length < 6) tags.value.push(tag)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/feedback/${token}`)
    info.value = data.data
    // Already answered — show the thank-you rather than a form that will be
    // refused when they tap it.
    if (info.value.answered) done.value = true
  } catch (e: any) {
    error.value =
      e.response?.status === 404
        ? 'This feedback link is invalid or no longer exists.'
        : e.response?.data?.message || 'Could not load this feedback link.'
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (rating.value === null) return
  saving.value = true
  error.value = ''
  try {
    await api.post(`/feedback/${token}`, {
      rating: rating.value,
      comment: comment.value || undefined,
      tags: tags.value.length ? tags.value : undefined,
    })
    done.value = true
  } catch (e: any) {
    // A 422 here means somebody already answered on this link — which is also a
    // thank-you, not an error to shout at a customer about.
    if (e.response?.status === 422) done.value = true
    else error.value = e.response?.data?.message || 'Could not send your feedback. Please try again.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="fb-page">
    <div class="fb-card">
      <div v-if="loading" class="fb-state">Loading…</div>

      <div v-else-if="error && !info" class="fb-state">
        <div class="fb-emoji">🔗</div>
        <p>{{ error }}</p>
      </div>

      <template v-else-if="done">
        <div class="fb-state">
          <div class="fb-emoji">🙏</div>
          <h1>Thank you</h1>
          <p>
            Your feedback has reached
            <strong>{{ info?.shop_name || 'the shop' }}</strong
            >. It is read by the people who run it.
          </p>
        </div>
      </template>

      <template v-else>
        <header class="fb-head">
          <div class="fb-shop">{{ info?.shop_name || 'Your feedback' }}</div>
          <h1>How did we do?</h1>
          <p v-if="info?.invoice_number" class="fb-sub">
            About bill {{ info.invoice_number }}<span v-if="info.invoice_date"> · {{ info.invoice_date }}</span>
          </p>
          <p v-else-if="info?.job_number" class="fb-sub">About job {{ info.job_number }}</p>
        </header>

        <div class="faces">
          <button
            v-for="f in FACES"
            :key="f.value"
            type="button"
            class="face"
            :class="{ on: rating === f.value }"
            @click="rating = f.value"
          >
            <span class="face-icon">{{ f.icon }}</span>
            <span class="face-label">{{ f.label }}</span>
          </button>
        </div>

        <template v-if="rating !== null">
          <div v-if="tagOptions.length" class="chips-block">
            <div class="chips-title">{{ tagPrompt }}</div>
            <div class="chips">
              <button
                v-for="t in tagOptions"
                :key="t"
                type="button"
                class="chip"
                :class="{ on: tags.includes(t) }"
                @click="toggleTag(t)"
              >
                {{ t }}
              </button>
            </div>
          </div>

          <div class="cmt">
            <label for="fb-comment">Anything else? (optional)</label>
            <Textarea id="fb-comment" v-model="comment" rows="3" autoResize :maxlength="2000" />
          </div>

          <p v-if="error" class="fb-err">{{ error }}</p>

          <Button
            class="fb-submit"
            label="Send feedback"
            icon="pi pi-send"
            :loading="saving"
            @click="submit"
          />
        </template>

        <p v-else class="fb-hint">Tap a face to begin.</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.fb-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
  background: #f6f4ef;
}
.fb-card {
  width: 100%;
  max-width: 32rem;
  background: #fff;
  border: 1px solid #e8e4da;
  border-radius: 16px;
  padding: 1.75rem 1.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.fb-state {
  text-align: center;
  color: #4b5563;
  padding: 1.5rem 0;
}
.fb-state h1 {
  font-size: 1.35rem;
  margin: 0.5rem 0;
  color: #111827;
}
.fb-emoji {
  font-size: 2.75rem;
  line-height: 1;
}
.fb-head {
  text-align: center;
  margin-bottom: 1.4rem;
}
.fb-shop {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #9ca3af;
}
.fb-head h1 {
  font-size: 1.45rem;
  margin: 0.25rem 0 0.2rem;
  color: #111827;
}
.fb-sub {
  font-size: 0.82rem;
  color: #6b7280;
  margin: 0;
}

/* Faces sized for a thumb, not a mouse pointer. */
.faces {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 0.4rem;
}
.face {
  background: none;
  border: 1px solid #e8e4da;
  border-radius: 12px;
  padding: 0.7rem 0.2rem;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  transition: border-color 0.12s, background 0.12s, transform 0.12s;
}
.face:hover {
  border-color: #d6cfbe;
}
.face.on {
  border-color: var(--safety-amber, #e8a33d);
  background: rgba(232, 163, 61, 0.14);
  transform: translateY(-1px);
}
.face-icon {
  font-size: 1.7rem;
  line-height: 1;
}
.face-label {
  font-size: 0.66rem;
  color: #6b7280;
}

.chips-block {
  margin-top: 1.4rem;
}
.chips-title {
  font-size: 0.85rem;
  font-weight: 600;
  color: #374151;
  margin-bottom: 0.5rem;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.chip {
  background: none;
  border: 1px solid #e8e4da;
  border-radius: 999px;
  padding: 0.4rem 0.8rem;
  font-size: 0.82rem;
  color: #374151;
  cursor: pointer;
}
.chip.on {
  border-color: var(--ink-blue, #2c4a5e);
  background: rgba(44, 74, 94, 0.1);
  font-weight: 600;
}

.cmt {
  margin-top: 1.3rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.cmt label {
  font-size: 0.85rem;
  color: #374151;
}
.cmt :deep(textarea) {
  width: 100%;
}

.fb-err {
  color: #b91c1c;
  font-size: 0.82rem;
  margin: 0.85rem 0 0;
}
.fb-submit {
  width: 100%;
  margin-top: 1.3rem;
}
.fb-hint {
  text-align: center;
  font-size: 0.8rem;
  color: #9ca3af;
  margin: 1.25rem 0 0;
}
</style>
