import { ref } from 'vue'
import http from '../api/http'

// Client-side table export + print. Most list endpoints have no server-side
// export (only report controllers do), so we generate CSV in the browser from
// the same column definitions the table renders, and print via a hidden iframe.
// For "export everything" the caller can use fetchAll() to page through the API
// first, then hand the full row set to exportCsv/printRows.

export interface ExportColumn {
  key: string
  label: string
  // Optional value formatter (e.g. currency, date, boolean → Yes/No).
  format?: (value: unknown, row: Record<string, any>) => string
}

function resolve(row: Record<string, any>, key: string): unknown {
  // Supports dotted keys like "category.name".
  return key.split('.').reduce<any>((o, k) => (o == null ? o : o[k]), row)
}

function cell(row: Record<string, any>, col: ExportColumn): string {
  const raw = resolve(row, col.key)
  const val = col.format ? col.format(raw, row) : raw
  return val == null ? '' : String(val)
}

function csvEscape(s: string): string {
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

function triggerDownload(blob: Blob, filename: string) {
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

export function useTableExport() {
  const exporting = ref(false)

  /** Build and download a CSV (UTF-8 BOM so Excel keeps ₹ and accents). */
  function exportCsv(columns: ExportColumn[], rows: Record<string, any>[], filenameBase: string) {
    const header = columns.map((c) => csvEscape(c.label)).join(',')
    const body = rows.map((r) => columns.map((c) => csvEscape(cell(r, c))).join(',')).join('\r\n')
    const csv = '﻿' + header + '\r\n' + body
    triggerDownload(new Blob([csv], { type: 'text/csv;charset=utf-8;' }), `${filenameBase}.csv`)
  }

  /** Print a clean table in a hidden iframe (no popup-blocker issues). */
  function printRows(title: string, columns: ExportColumn[], rows: Record<string, any>[]) {
    const head = columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join('')
    const bodyRows = rows
      .map(
        (r) =>
          '<tr>' + columns.map((c) => `<td>${escapeHtml(cell(r, c))}</td>`).join('') + '</tr>',
      )
      .join('')
    const stamp = new Date().toLocaleString()
    const doc = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
      <style>
        * { font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
        th { background: #f2f2f2; }
        tr:nth-child(even) td { background: #fafafa; }
        @media print { .meta { color: #333; } }
      </style></head>
      <body>
        <h1>${escapeHtml(title)}</h1>
        <div class="meta">${rows.length} record(s) · ${escapeHtml(stamp)}</div>
        <table><thead><tr>${head}</tr></thead><tbody>${bodyRows}</tbody></table>
      </body></html>`

    const iframe = document.createElement('iframe')
    iframe.style.position = 'fixed'
    iframe.style.right = '0'
    iframe.style.bottom = '0'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'
    document.body.appendChild(iframe)
    const win = iframe.contentWindow
    if (!win) {
      iframe.remove()
      return
    }
    win.document.open()
    win.document.write(doc)
    win.document.close()
    // Give the iframe a tick to lay out before printing.
    setTimeout(() => {
      win.focus()
      win.print()
      setTimeout(() => iframe.remove(), 1000)
    }, 250)
  }

  /**
   * Page through a list endpoint and collect every row, so Export/Print cover
   * the whole result set (not just the current page). Respects the same query
   * params the list is using (search/filters). Capped to avoid runaway loops.
   */
  async function fetchAll(
    endpoint: string,
    params: Record<string, any> = {},
    maxRows = 10000,
  ): Promise<Record<string, any>[]> {
    exporting.value = true
    try {
      const all: Record<string, any>[] = []
      const perPage = 100
      let page = 1
      let lastPage = 1
      do {
        const { data } = await http.get(endpoint, { params: { ...params, per_page: perPage, page } })
        const rows = data.data ?? data
        if (!Array.isArray(rows)) break
        all.push(...rows)
        // Support both {meta:{last_page}} paginators and flat {total}/{last_page}
        // shapes; fall back to "stop when a short page comes back".
        const total = data.meta?.total ?? data.total
        lastPage =
          data.meta?.last_page ??
          data.last_page ??
          (typeof total === 'number' ? Math.ceil(total / perPage) : rows.length < perPage ? page : page + 1)
        page++
      } while (page <= lastPage && all.length < maxRows)
      return all
    } finally {
      exporting.value = false
    }
  }

  return { exporting, exportCsv, printRows, fetchAll }
}
