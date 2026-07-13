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
