// Config-driven master-data CRUD. Each resource declares its API endpoint,
// the table columns, and the form fields. A single generic CrudView renders
// all of them, so adding a master screen is just adding an entry here.

export type FieldType = 'text' | 'textarea' | 'number' | 'select' | 'checkbox'

export interface FieldConfig {
  key: string
  label: string
  type: FieldType
  required?: boolean
  options?: { label: string; value: string | number }[]
  optionsFrom?: string // endpoint to load select options from (id + name/short_name)
  optionLabel?: string // property to display for optionsFrom
  default?: unknown
  step?: string
}

export interface ColumnConfig {
  key: string
  label: string
  type?: 'text' | 'boolean' | 'currency'
}

export interface ResourceConfig {
  key: string
  title: string
  endpoint: string
  permission: string // module prefix, e.g. "products"
  idKey?: string
  columns: ColumnConfig[]
  fields: FieldConfig[]
}

export const resources: Record<string, ResourceConfig> = {
  products: {
    key: 'products',
    title: 'Products',
    endpoint: '/products',
    permission: 'products',
    columns: [
      { key: 'sku', label: 'SKU' },
      { key: 'name', label: 'Name' },
      { key: 'selling_price', label: 'Selling Price', type: 'currency' },
      { key: 'tax_percent', label: 'Tax %' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'sku', label: 'SKU', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'barcode', label: 'Barcode', type: 'text' },
      { key: 'category_id', label: 'Category', type: 'select', optionsFrom: '/categories', optionLabel: 'name' },
      { key: 'brand_id', label: 'Brand', type: 'select', optionsFrom: '/brands', optionLabel: 'name' },
      { key: 'unit_id', label: 'Unit', type: 'select', optionsFrom: '/units', optionLabel: 'short_name' },
      { key: 'hsn_code', label: 'HSN Code', type: 'text' },
      { key: 'cost_price', label: 'Cost Price', type: 'number', step: '0.01', default: 0 },
      { key: 'selling_price', label: 'Selling Price', type: 'number', step: '0.01', default: 0 },
      { key: 'tax_percent', label: 'Tax %', type: 'number', step: '0.01', default: 0 },
      { key: 'reorder_level', label: 'Reorder Level', type: 'number', step: '0.001', default: 0 },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  categories: {
    key: 'categories',
    title: 'Categories',
    endpoint: '/categories',
    permission: 'categories',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'slug', label: 'Slug' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'parent_id', label: 'Parent Category', type: 'select', optionsFrom: '/categories', optionLabel: 'name' },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  brands: {
    key: 'brands',
    title: 'Brands',
    endpoint: '/brands',
    permission: 'brands',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'slug', label: 'Slug' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  units: {
    key: 'units',
    title: 'Units',
    endpoint: '/units',
    permission: 'units',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'short_name', label: 'Short Name' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'short_name', label: 'Short Name', type: 'text', required: true },
      { key: 'conversion_factor', label: 'Conversion Factor', type: 'number', step: '0.000001', default: 1 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  warehouses: {
    key: 'warehouses',
    title: 'Warehouses',
    endpoint: '/warehouses',
    permission: 'warehouses',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      {
        key: 'type',
        label: 'Type',
        type: 'select',
        required: true,
        default: 'main',
        options: [
          { label: 'Main', value: 'main' },
          { label: 'Branch', value: 'branch' },
          { label: 'Transit', value: 'transit' },
          { label: 'Damaged', value: 'damaged' },
        ],
      },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'pincode', label: 'Pincode', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  suppliers: {
    key: 'suppliers',
    title: 'Suppliers',
    endpoint: '/suppliers',
    permission: 'suppliers',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'phone', label: 'Phone' },
      { key: 'credit_limit', label: 'Credit Limit', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'company_name', label: 'Company Name', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'gst_number', label: 'GST Number', type: 'text' },
      { key: 'credit_limit', label: 'Credit Limit', type: 'number', step: '0.01', default: 0 },
      { key: 'payment_terms_days', label: 'Payment Terms (days)', type: 'number', default: 0 },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  customers: {
    key: 'customers',
    title: 'Customers',
    endpoint: '/customers',
    permission: 'customers',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type' },
      { key: 'phone', label: 'Phone' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'company_name', label: 'Company Name', type: 'text' },
      {
        key: 'type',
        label: 'Type',
        type: 'select',
        required: true,
        default: 'retail',
        options: [
          { label: 'Retail', value: 'retail' },
          { label: 'Wholesale', value: 'wholesale' },
          { label: 'Distributor', value: 'distributor' },
        ],
      },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'gst_number', label: 'GST Number', type: 'text' },
      { key: 'credit_limit', label: 'Credit Limit', type: 'number', step: '0.01', default: 0 },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  companies: {
    key: 'companies',
    title: 'Companies',
    endpoint: '/companies',
    permission: 'companies',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'gstin', label: 'GSTIN' },
      { key: 'currency_code', label: 'Currency' },
      { key: 'branches_count', label: 'Branches' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'legal_name', label: 'Legal Name', type: 'text' },
      { key: 'gstin', label: 'GSTIN', type: 'text' },
      { key: 'pan', label: 'PAN', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'country', label: 'Country', type: 'text', default: 'India' },
      { key: 'pincode', label: 'Pincode', type: 'text' },
      {
        key: 'financial_year_start_month',
        label: 'Financial Year Start Month',
        type: 'select',
        default: 4,
        options: [
          { label: 'January', value: 1 }, { label: 'February', value: 2 }, { label: 'March', value: 3 },
          { label: 'April', value: 4 }, { label: 'May', value: 5 }, { label: 'June', value: 6 },
          { label: 'July', value: 7 }, { label: 'August', value: 8 }, { label: 'September', value: 9 },
          { label: 'October', value: 10 }, { label: 'November', value: 11 }, { label: 'December', value: 12 },
        ],
      },
      { key: 'currency_code', label: 'Currency Code', type: 'text', default: 'INR' },
      { key: 'currency_symbol', label: 'Currency Symbol', type: 'text', default: '₹' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  branches: {
    key: 'branches',
    title: 'Branches',
    endpoint: '/branches',
    permission: 'branches',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'company_name', label: 'Company' },
      { key: 'city', label: 'City' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'company_id', label: 'Company', type: 'select', required: true, optionsFrom: '/companies', optionLabel: 'name' },
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'country', label: 'Country', type: 'text', default: 'India' },
      { key: 'pincode', label: 'Pincode', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
}
