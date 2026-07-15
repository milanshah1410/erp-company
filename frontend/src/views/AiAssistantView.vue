<script setup lang="ts">
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'

/**
 * Chat-based ERP Search + Gujarati Voice Assistant (SRS §22). Typed or spoken
 * questions are sent to POST /ai/search, which routes them (in English or
 * Gujarati) to the right entity/metric and returns a spoken-friendly answer
 * plus structured rows. Voice uses the browser's Web Speech API — input via
 * SpeechRecognition (gu-IN / en-IN) and optional spoken replies via
 * SpeechSynthesis — so no server-side speech service is needed.
 */

const toast = useToast()

interface ResultRow { [k: string]: any }
interface Message {
  role: 'user' | 'assistant'
  text: string
  intent?: string
  type?: string
  results?: ResultRow[]
  suggestions?: string[]
}

const messages = ref<Message[]>([{
  role: 'assistant',
  text: 'Namaste! Ask me about your business — sales, stock, customers, payments. '
    + 'ગુજરાતીમાં પણ પૂછી શકો છો. Try the suggestions below or tap the mic.',
  type: 'help',
  suggestions: ["Today's sales", 'Low stock items', 'Outstanding payments', 'Top selling products'],
}])
const input = ref('')
const sending = ref(false)
const scroller = ref<HTMLElement | null>(null)

// Language + voice toggles.
const langOptions = [
  { label: 'ગુજરાતી', value: 'gu-IN' },
  { label: 'English', value: 'en-IN' },
]
const lang = ref<'gu-IN' | 'en-IN'>('gu-IN')
const speakReplies = ref(true)

// -- Web Speech API (feature-detected) --------------------------------------
const SpeechRecognitionCtor: any =
  (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition
const voiceSupported = !!SpeechRecognitionCtor
const ttsSupported = typeof window !== 'undefined' && 'speechSynthesis' in window
const listening = ref(false)
let recognition: any = null

function setupRecognition() {
  if (!voiceSupported) return
  recognition = new SpeechRecognitionCtor()
  recognition.continuous = false
  recognition.interimResults = false
  recognition.maxAlternatives = 1
  recognition.onresult = (event: any) => {
    const transcript = event.results?.[0]?.[0]?.transcript ?? ''
    if (transcript) {
      input.value = transcript
      send()
    }
  }
  recognition.onerror = (event: any) => {
    listening.value = false
    if (event.error !== 'aborted' && event.error !== 'no-speech') {
      toast.add({ severity: 'warn', summary: 'Voice input', detail: `Could not capture audio (${event.error}).`, life: 3500 })
    }
  }
  recognition.onend = () => { listening.value = false }
}

function toggleListen() {
  if (!voiceSupported || !recognition) {
    toast.add({ severity: 'warn', summary: 'Voice not supported', detail: 'This browser does not support speech input. Please type instead.', life: 4000 })
    return
  }
  if (listening.value) {
    recognition.stop()
    return
  }
  try {
    recognition.lang = lang.value
    recognition.start()
    listening.value = true
  } catch {
    listening.value = false
  }
}

function speak(text: string) {
  if (!speakReplies.value || !ttsSupported) return
  try {
    window.speechSynthesis.cancel()
    const utter = new SpeechSynthesisUtterance(text)
    utter.lang = lang.value
    window.speechSynthesis.speak(utter)
  } catch { /* best-effort */ }
}

// -- Chat --------------------------------------------------------------------
async function scrollToBottom() {
  await nextTick()
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

function ask(text: string) {
  input.value = text
  send()
}

async function send() {
  const query = input.value.trim()
  if (!query || sending.value) return
  messages.value.push({ role: 'user', text: query })
  input.value = ''
  sending.value = true
  await scrollToBottom()

  try {
    const { data } = await http.post('/ai/search', { query })
    const r = data.data
    messages.value.push({
      role: 'assistant',
      text: r.answer,
      intent: r.intent,
      type: r.type,
      results: r.results,
      suggestions: r.suggestions,
    })
    speak(r.answer)
  } catch (e: any) {
    messages.value.push({ role: 'assistant', text: 'Sorry, I could not process that. Please try rephrasing.' })
    toast.add({ severity: 'error', summary: 'Assistant error', detail: e?.response?.data?.message ?? 'Request failed.', life: 4000 })
  } finally {
    sending.value = false
    await scrollToBottom()
  }
}

function columnsOf(rows: ResultRow[]): string[] {
  return rows.length ? Object.keys(rows[0]) : []
}
function humanize(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

onMounted(setupRecognition)
onBeforeUnmount(() => {
  try { recognition?.abort() } catch { /* noop */ }
  if (ttsSupported) window.speechSynthesis.cancel()
})
</script>

<template>
  <div class="assistant">
    <div class="header">
      <div>
        <h1>AI Assistant</h1>
        <p class="subtitle">Chat &amp; voice search across your ERP</p>
      </div>
      <div class="head-controls">
        <SelectButton v-model="lang" :options="langOptions" optionLabel="label" optionValue="value" :allowEmpty="false" size="small" />
        <button class="tts-toggle" :class="{ on: speakReplies }" :disabled="!ttsSupported" @click="speakReplies = !speakReplies" title="Speak replies">
          <i :class="speakReplies && ttsSupported ? 'pi pi-volume-up' : 'pi pi-volume-off'" />
        </button>
      </div>
    </div>

    <div class="chat-card">
      <div ref="scroller" class="messages">
        <div v-for="(m, i) in messages" :key="i" class="msg" :class="m.role">
          <div class="avatar"><i :class="m.role === 'user' ? 'pi pi-user' : 'pi pi-sparkles'" /></div>
          <div class="bubble">
            <p class="answer">{{ m.text }}</p>

            <!-- structured results -->
            <div v-if="m.results && m.results.length" class="result-table-wrap">
              <table class="result-table">
                <thead>
                  <tr><th v-for="c in columnsOf(m.results)" :key="c">{{ humanize(c) }}</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in m.results" :key="ri">
                    <td v-for="c in columnsOf(m.results)" :key="c">{{ row[c] }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- suggestion chips -->
            <div v-if="m.suggestions && m.suggestions.length" class="chips">
              <button v-for="s in m.suggestions" :key="s" class="chip" @click="ask(s)">{{ s }}</button>
            </div>
          </div>
        </div>

        <div v-if="sending" class="msg assistant">
          <div class="avatar"><i class="pi pi-sparkles" /></div>
          <div class="bubble"><span class="typing"><span></span><span></span><span></span></span></div>
        </div>
      </div>

      <div class="composer">
        <button
          class="mic"
          :class="{ listening, unsupported: !voiceSupported }"
          @click="toggleListen"
          :title="voiceSupported ? 'Speak your question' : 'Voice input not supported in this browser'"
        >
          <i :class="listening ? 'pi pi-stop-circle' : 'pi pi-microphone'" />
        </button>
        <InputText
          v-model="input"
          class="field"
          :placeholder="lang === 'gu-IN' ? 'પ્રશ્ન લખો… દા.ત. આજનું વેચાણ' : 'Ask a question… e.g. today\'s sales'"
          @keyup.enter="send"
        />
        <Button icon="pi pi-send" :loading="sending" :disabled="!input.trim()" @click="send" />
      </div>
    </div>
    <p v-if="listening" class="listening-hint"><i class="pi pi-circle-fill" /> Listening… speak now</p>
  </div>
</template>

<style scoped>
.assistant h1 { font-size: 1.6rem; font-weight: 600; }
.subtitle { color: #6b7280; margin: 0.25rem 0 0; }
.header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.head-controls { display: flex; align-items: center; gap: 0.6rem; }
.tts-toggle {
  width: 38px; height: 38px; border-radius: 8px; border: 1px solid #e8e4da; background: #fff;
  color: #6b7280; cursor: pointer; transition: all 0.15s;
}
.tts-toggle.on { background: #e8a33d; border-color: #e8a33d; color: #fff; }
.tts-toggle:disabled { opacity: 0.4; cursor: not-allowed; }

.chat-card { background: #fff; border: 1px solid #e8e4da; border-radius: 12px; display: flex; flex-direction: column; height: calc(100vh - 230px); min-height: 420px; overflow: hidden; }
.messages { flex: 1; overflow-y: auto; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }

.msg { display: flex; gap: 0.7rem; max-width: 88%; }
.msg.user { flex-direction: row-reverse; align-self: flex-end; }
.avatar {
  width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; color: #fff; font-size: 0.9rem;
}
.msg.assistant .avatar { background: linear-gradient(135deg, #e8a33d, #c98730); }
.msg.user .avatar { background: linear-gradient(135deg, #3d637c, #2c4a5e); }
.bubble { background: #f7f5f0; border: 1px solid #eee7d9; border-radius: 12px; padding: 0.7rem 0.9rem; }
.msg.user .bubble { background: #eef3f6; border-color: #dbe6ec; }
.answer { margin: 0; line-height: 1.5; color: #1e2328; white-space: pre-line; }

.result-table-wrap { overflow-x: auto; margin-top: 0.7rem; }
.result-table { border-collapse: collapse; font-size: 0.82rem; width: 100%; }
.result-table th { text-align: left; color: #6b7280; font-weight: 600; padding: 0.35rem 0.6rem; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.result-table td { padding: 0.35rem 0.6rem; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }

.chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.7rem; }
.chip { border: 1px solid #e8a33d; background: #fff; color: #a5701c; border-radius: 999px; padding: 0.3rem 0.75rem; font-size: 0.8rem; cursor: pointer; transition: all 0.15s; }
.chip:hover { background: #e8a33d; color: #fff; }

.typing { display: inline-flex; gap: 4px; }
.typing span { width: 7px; height: 7px; border-radius: 50%; background: #c98730; opacity: 0.5; animation: blink 1.2s infinite both; }
.typing span:nth-child(2) { animation-delay: 0.2s; }
.typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes blink { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }

.composer { display: flex; align-items: center; gap: 0.6rem; padding: 0.8rem; border-top: 1px solid #eee7d9; background: #fbfaf8; }
.composer .field { flex: 1; }
.mic {
  width: 42px; height: 42px; border-radius: 50%; border: 1px solid #e8e4da; background: #fff; color: #6b7280;
  cursor: pointer; flex-shrink: 0; transition: all 0.15s; font-size: 1rem;
}
.mic:hover { border-color: #e8a33d; color: #e8a33d; }
.mic.listening { background: #c1502e; border-color: #c1502e; color: #fff; animation: pulse 1.2s infinite; }
.mic.unsupported { opacity: 0.5; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(193,80,46,0.45); } 70% { box-shadow: 0 0 0 10px rgba(193,80,46,0); } 100% { box-shadow: 0 0 0 0 rgba(193,80,46,0); } }
.listening-hint { color: #c1502e; font-size: 0.85rem; margin-top: 0.6rem; display: flex; align-items: center; gap: 0.4rem; }
.listening-hint i { font-size: 0.6rem; animation: blink 1s infinite; }
</style>
