// Merged phrase dictionaries for the pt() helper (src/i18n/pt.ts).
// Each module file owns the phrases for one functional area so batches can be
// edited independently. Later files win on duplicate keys (harmless: the
// translation of the same English phrase should be identical anyway).
import type { PhraseFile } from './types'
import core from './core'
import resourceLabels from './resource-labels'
import sales from './sales'
import masters from './masters'
import inventory from './inventory'
import purchaseCrm from './purchase-crm'
import manufacturing from './manufacturing'
import financeBooks from './finance-books'
import financeExpense from './finance-expense'
import hrField from './hr-field'
import adminMisc from './admin-misc'
import devices from './devices'
import agriculture from './agriculture'
import distribution from './distribution'
import healthcare from './healthcare'
import computerIt from './computer-it'
import grocery from './grocery'
import gift from './gift'
import toys from './toys'
import books from './books'
import sports from './sports'
import shared from './shared'

const ALL: PhraseFile = {
  ...core,
  ...resourceLabels,
  ...sales,
  ...masters,
  ...inventory,
  ...purchaseCrm,
  ...manufacturing,
  ...financeBooks,
  ...financeExpense,
  ...hrField,
  ...adminMisc,
  ...devices,
  ...agriculture,
  ...distribution,
  ...healthcare,
  ...computerIt,
  ...grocery,
  ...gift,
  ...toys,
  ...books,
  ...sports,
  ...shared,
}

export const PHRASES: Record<string, Record<string, string>> = { hi: {}, gu: {} }
for (const [en, tr] of Object.entries(ALL)) {
  if (tr.hi) PHRASES.hi[en] = tr.hi
  if (tr.gu) PHRASES.gu[en] = tr.gu
}
