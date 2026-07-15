import { ref } from 'vue'
import http from '../api/http'

const EXTENSIONS: Record<string, string> = { csv: 'csv', xlsx: 'xlsx', pdf: 'pdf' }

/**
 * Downloads a report endpoint as a file in the given export format.
 * endpoint/filenameBase may be plain strings or getters, so callers whose
 * endpoint depends on reactive state (e.g. a report-type dropdown) can
 * resolve it at export time rather than when the composable is set up.
 */
export function useReportExport(endpoint: string | (() => string), filenameBase: string | (() => string)) {
  const exporting = ref(false)

  async function exportAs(format: 'csv' | 'xlsx' | 'pdf', params: Record<string, unknown> = {}) {
    exporting.value = true
    try {
      const resolvedEndpoint = typeof endpoint === 'function' ? endpoint() : endpoint
      const resolvedFilename = typeof filenameBase === 'function' ? filenameBase() : filenameBase
      const response = await http.get(resolvedEndpoint, {
        params: { ...params, format },
        responseType: 'blob',
      })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = `${resolvedFilename}.${EXTENSIONS[format]}`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } finally {
      exporting.value = false
    }
  }

  return { exporting, exportAs }
}

/**
 * Downloads a government offline-tool JSON export (GالسTN upload schema). The
 * endpoint streams a .json file; the server sets the filename via
 * Content-Disposition, so we read it from the response when present.
 */
export async function downloadGovtJson(endpoint: string, fallbackName: string, params: Record<string, unknown> = {}) {
  const response = await http.get(endpoint, { params, responseType: 'blob' })
  const disposition = response.headers['content-disposition'] as string | undefined
  const match = disposition?.match(/filename="?([^"]+)"?/)
  const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/json' }))
  const link = document.createElement('a')
  link.href = url
  link.download = match?.[1] || `${fallbackName}.json`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
