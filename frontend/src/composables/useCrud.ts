import { ref } from 'vue'
import http from '../api/http'

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number; per_page: number }
}

export function useCrud(endpoint: string) {
  const items = ref<any[]>([])
  const loading = ref(false)
  const total = ref(0)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const perPage = ref(15)

  async function fetch(params: Record<string, any> = {}) {
    loading.value = true
    try {
      const { data } = await http.get(endpoint, {
        params: { per_page: perPage.value, page: currentPage.value, ...params },
      })
      items.value = data.data ?? data
      if (data.meta) {
        total.value = data.meta.total
        lastPage.value = data.meta.last_page
        currentPage.value = data.meta.current_page
      }
    } finally {
      loading.value = false
    }
  }

  async function create(payload: Record<string, any>) {
    const { data } = await http.post(endpoint, payload)
    return data.data ?? data
  }

  async function update(id: number | string, payload: Record<string, any>) {
    const { data } = await http.put(`${endpoint}/${id}`, payload)
    return data.data ?? data
  }

  async function remove(id: number | string) {
    await http.delete(`${endpoint}/${id}`)
  }

  return { items, loading, total, currentPage, lastPage, perPage, fetch, create, update, remove }
}
