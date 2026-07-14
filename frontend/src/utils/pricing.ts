/**
 * Client-side mirror of the backend App\Services\DocumentPricingService.
 *
 * Keeps the on-screen totals preview identical to what the server will store:
 *   subtotal (gross) -> per-line discount -> overall invoice discount
 *   -> per-line GST on the discounted value -> round to nearest rupee -> grand total.
 *
 * The overall discount is distributed across lines in proportion to each line's
 * net value so per-line GST is correct even with mixed tax rates.
 */

export type DiscountType = 'percent' | 'flat' | null

export interface PricingLineInput {
  quantity: number | string | null
  unit_price: number | string | null
  tax_percent?: number | string | null
  discount_type?: DiscountType
  discount_value?: number | string | null
}

export interface PricedLine {
  quantity: number
  unit_price: number
  tax_percent: number
  discount_type: DiscountType
  discount_value: number
  discount_amount: number
  tax_amount: number
  line_total: number
}

export interface PricingHeader {
  subtotal: number
  item_discount_total: number
  discount_type: DiscountType
  discount_value: number
  discount_amount: number
  taxable: number
  tax_amount: number
  round_off: number
  total: number
}

export interface PricingResult {
  lines: PricedLine[]
  header: PricingHeader
}

const num = (v: unknown): number => Number(v ?? 0) || 0
const r2 = (v: number): number => Math.round((v + Number.EPSILON) * 100) / 100

/** Resolve a discount input (percent of / flat off a base), capped at the base. */
function discountAmount(type: DiscountType, value: number, base: number): number {
  if (value <= 0 || base <= 0) return 0
  if (type === 'percent') return Math.min(base, (base * value) / 100)
  if (type === 'flat') return Math.min(base, value)
  return 0
}

export function priceDocument(
  items: PricingLineInput[],
  overallType: DiscountType = null,
  overallValue: number | string | null = 0,
): PricingResult {
  const overallVal = num(overallValue)

  const staged = items.map((item) => {
    const qty = num(item.quantity)
    const price = num(item.unit_price)
    const base = qty * price
    const dType = (item.discount_type ?? null) as DiscountType
    const dValue = num(item.discount_value)
    const disc = r2(discountAmount(dType, dValue, base))
    return {
      qty,
      price,
      tax_percent: num(item.tax_percent),
      discount_type: disc > 0 ? dType : null,
      discount_value: dValue,
      discount_amount: disc,
      net: base - disc,
      base,
    }
  })

  const subtotal = staged.reduce((s, l) => s + l.base, 0)
  const itemDiscountTotal = staged.reduce((s, l) => s + l.discount_amount, 0)
  const lineNetSubtotal = staged.reduce((s, l) => s + l.net, 0)

  const overallDiscount = r2(discountAmount(overallType, overallVal, lineNetSubtotal))

  let taxTotal = 0
  let allocated = 0
  const lastIdx = staged.length - 1
  const lines: PricedLine[] = staged.map((line, idx) => {
    let share: number
    if (idx === lastIdx) share = r2(overallDiscount - allocated)
    else if (lineNetSubtotal > 0) share = r2(overallDiscount * (line.net / lineNetSubtotal))
    else share = 0
    allocated = r2(allocated + share)

    const taxable = line.net - share
    const lineTax = r2((taxable * line.tax_percent) / 100)
    taxTotal += lineTax

    return {
      quantity: line.qty,
      unit_price: line.price,
      tax_percent: line.tax_percent,
      discount_type: line.discount_type,
      discount_value: line.discount_value,
      discount_amount: line.discount_amount,
      tax_amount: lineTax,
      line_total: r2(taxable + lineTax),
    }
  })

  const taxableTotal = r2(lineNetSubtotal - overallDiscount)
  const preRound = taxableTotal + r2(taxTotal)
  const total = Math.round(preRound)
  const roundOff = r2(total - preRound)

  return {
    lines,
    header: {
      subtotal: r2(subtotal),
      item_discount_total: r2(itemDiscountTotal),
      discount_type: overallDiscount > 0 ? overallType : null,
      discount_value: r2(overallVal),
      discount_amount: overallDiscount,
      taxable: taxableTotal,
      tax_amount: r2(taxTotal),
      round_off: roundOff,
      total: r2(total),
    },
  }
}
