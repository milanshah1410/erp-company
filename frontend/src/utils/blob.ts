/** Open a PDF blob response in a new browser tab so the user can view/print it. */
export function openPdfBlob(blob: Blob) {
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}
