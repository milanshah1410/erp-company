import Swal from 'sweetalert2'
import { useToast } from 'primevue/usetoast'
import http from '../api/http'

/**
 * Shared draft → pending → approved / rejected + mark-paid + attachment actions
 * for the Expenses sub-modules. Given the module's base endpoint (e.g.
 * `/vehicle-expenses`) and a reload callback, exposes the workflow operations
 * used by every sub-module view, so each view only owns its form and columns.
 */
export function useExpenseWorkflow(endpoint: string, reload: () => void, label = 'record') {
  const toast = useToast()

  async function post(url: string, okMsg: string, body: Record<string, any> = {}) {
    try {
      await http.post(url, body)
      toast.add({ severity: 'success', summary: okMsg, life: 2500 })
      reload()
    } catch (e: any) {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
    }
  }

  function submit(row: any) {
    return post(`${endpoint}/${row.id}/submit`, 'Submitted for approval')
  }

  function approve(row: any) {
    return post(`${endpoint}/${row.id}/approve`, 'Approved')
  }

  async function reject(row: any, ref: string) {
    const res = await Swal.fire({
      title: `Reject ${ref}?`,
      input: 'text',
      inputLabel: 'Reason (optional)',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Reject',
    })
    if (!res.isConfirmed) return
    return post(`${endpoint}/${row.id}/reject`, 'Rejected', { rejection_reason: res.value || null })
  }

  function togglePaid(row: any) {
    return post(`${endpoint}/${row.id}/mark-paid`, row.is_paid ? 'Marked unpaid' : 'Marked paid', { paid: !row.is_paid })
  }

  async function remove(row: any, ref: string) {
    const res = await Swal.fire({ title: `Delete ${ref}?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
    if (!res.isConfirmed) return
    try {
      await http.delete(`${endpoint}/${row.id}`)
      toast.add({ severity: 'success', summary: 'Deleted', life: 2500 })
      reload()
    } catch (e: any) {
      toast.add({ severity: 'error', summary: 'Error', detail: e.response?.data?.message || 'Failed', life: 4000 })
    }
  }

  async function uploadAttachment(id: number, file: File) {
    const fd = new FormData()
    fd.append('attachment', file)
    await http.post(`${endpoint}/${id}/attachment`, fd)
  }

  return { submit, approve, reject, togglePaid, remove, uploadAttachment, toast, label }
}
