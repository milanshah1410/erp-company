// The fixed production pipeline, mirroring App\Models\ManufacturingOrder::STAGES
// on the backend. Keep the two in sync.
export interface StageDef {
  key: string
  name: string
}

export const MANUFACTURING_STAGES: StageDef[] = [
  { key: 'order_received', name: 'Order Received' },
  { key: 'cutting', name: 'Cutting' },
  { key: 'darji', name: 'Darji (Tailor)' },
  { key: 'embroidery', name: 'Embroidery' },
  { key: 'colour_printing', name: 'Colour Printing' },
  { key: 'screen_printing', name: 'Screen Printing' },
  { key: 'heat_transfer', name: 'Heat Transfer' },
  { key: 'quality_check', name: 'Quality Check' },
  { key: 'packing', name: 'Packing' },
  { key: 'ready_stock', name: 'Ready Stock' },
]

export const STAGE_STATUS_COLORS: Record<string, string> = {
  pending: 'secondary',
  in_progress: 'info',
  completed: 'success',
  skipped: 'warn',
}

export const STAGE_STATUS_OPTIONS = [
  { label: 'Pending', value: 'pending' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' },
  { label: 'Skipped', value: 'skipped' },
]
