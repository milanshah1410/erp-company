// Config-driven master-data CRUD. Each resource declares its API endpoint,
// the table columns, and the form fields. A single generic CrudView renders
// all of them, so adding a master screen is just adding an entry here.

/**
 * `csv` is a list-valued field typed as comma-separated text — "XS, S, M, L".
 * It exists because a handful of resources store an ordered short list (a
 * garment size run) where the *order* is the point, and a tag widget that lets
 * the user drag chips around would be a lot of machinery for something a shop
 * types once. CrudView splits and joins it; the API sees a real array.
 */
export type FieldType = 'text' | 'textarea' | 'number' | 'select' | 'checkbox' | 'date' | 'time' | 'month' | 'csv'

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
  type?: 'text' | 'boolean' | 'currency' | 'date'
}

/**
 * Toolbar dropdown filter. `key` is sent verbatim as a query param to the list
 * endpoint (which must support it), so a filter only works once the controller
 * reads that param. Options come either from a static list or an endpoint.
 */
export interface FilterConfig {
  key: string
  label: string
  options?: { label: string; value: string | number }[]
  optionsFrom?: string
  optionLabel?: string
}

export interface ResourceConfig {
  key: string
  title: string
  endpoint: string
  permission: string // module prefix, e.g. "products"
  idKey?: string
  columns: ColumnConfig[]
  fields: FieldConfig[]
  filters?: FilterConfig[]
  /**
   * Params pinned to this screen: sent with every list/export request AND
   * merged into the payload of records created here. Lets one endpoint back two
   * masters — e.g. Overseas Suppliers is /suppliers with is_overseas = true.
   */
  fixedParams?: Record<string, string | number | boolean>
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
  brokers: {
    key: 'brokers',
    title: 'Brokers',
    endpoint: '/brokers',
    permission: 'brokers',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'mobile', label: 'Mobile' },
      { key: 'commission_percent', label: 'Commission %' },
      { key: 'tds_percent', label: 'TDS %' },
      { key: 'wallet_balance', label: 'Wallet', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'mobile', label: 'Mobile', type: 'text' },
      { key: 'gst_number', label: 'GST Number', type: 'text' },
      { key: 'pan_number', label: 'PAN Number', type: 'text' },
      { key: 'commission_percent', label: 'Default Commission %', type: 'number', step: '0.01', default: 0 },
      { key: 'fixed_commission', label: 'Fixed Commission', type: 'number', step: '0.01', default: 0 },
      { key: 'tds_percent', label: 'TDS % (on commission)', type: 'number', step: '0.01', default: 0 },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'address', label: 'Address', type: 'textarea' },
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
    filters: [
      { key: 'company_id', label: 'Company', optionsFrom: '/companies', optionLabel: 'name' },
    ],
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
  departments: {
    key: 'departments',
    title: 'Departments',
    endpoint: '/departments',
    permission: 'departments',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'employees_count', label: 'Employees' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  employees: {
    key: 'employees',
    title: 'Employees',
    endpoint: '/employees',
    permission: 'employees',
    columns: [
      { key: 'employee_code', label: 'Code' },
      { key: 'full_name', label: 'Name' },
      { key: 'designation', label: 'Designation' },
      { key: 'department_name', label: 'Department' },
      { key: 'employment_type', label: 'Type' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'employee_code', label: 'Employee Code', type: 'text', required: true },
      { key: 'first_name', label: 'First Name', type: 'text', required: true },
      { key: 'last_name', label: 'Last Name', type: 'text' },
      { key: 'department_id', label: 'Department', type: 'select', optionsFrom: '/departments', optionLabel: 'name' },
      { key: 'user_id', label: 'Login Account', type: 'select', optionsFrom: '/users', optionLabel: 'name' },
      { key: 'designation', label: 'Designation', type: 'text' },
      {
        key: 'employment_type',
        label: 'Employment Type',
        type: 'select',
        default: 'full-time',
        options: [
          { label: 'Full-time', value: 'full-time' },
          { label: 'Part-time', value: 'part-time' },
          { label: 'Contract', value: 'contract' },
          { label: 'Intern', value: 'intern' },
        ],
      },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'date_of_joining', label: 'Date of Joining', type: 'date' },
      { key: 'date_of_birth', label: 'Date of Birth', type: 'date' },
      {
        key: 'gender',
        label: 'Gender',
        type: 'select',
        options: [
          { label: 'Male', value: 'male' },
          { label: 'Female', value: 'female' },
          { label: 'Other', value: 'other' },
        ],
      },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  attendance: {
    key: 'attendance',
    title: 'Attendance',
    endpoint: '/attendance',
    permission: 'attendance',
    columns: [
      { key: 'date', label: 'Date', type: 'date' },
      { key: 'employee_name', label: 'Employee' },
      { key: 'status', label: 'Status' },
      { key: 'check_in', label: 'Check In' },
      { key: 'check_out', label: 'Check Out' },
    ],
    fields: [
      { key: 'employee_id', label: 'Employee', type: 'select', required: true, optionsFrom: '/employees', optionLabel: 'name' },
      { key: 'date', label: 'Date', type: 'date', required: true },
      {
        key: 'status',
        label: 'Status',
        type: 'select',
        default: 'present',
        options: [
          { label: 'Present', value: 'present' },
          { label: 'Absent', value: 'absent' },
          { label: 'Half Day', value: 'half-day' },
          { label: 'Leave', value: 'leave' },
          { label: 'Holiday', value: 'holiday' },
          { label: 'Week Off', value: 'week-off' },
        ],
      },
      { key: 'check_in', label: 'Check In', type: 'time' },
      { key: 'check_out', label: 'Check Out', type: 'time' },
      { key: 'remarks', label: 'Remarks', type: 'text' },
    ],
  },
  salaries: {
    key: 'salaries',
    title: 'Salaries',
    endpoint: '/salaries',
    permission: 'salaries',
    columns: [
      { key: 'month', label: 'Month', type: 'date' },
      { key: 'employee_name', label: 'Employee' },
      { key: 'basic', label: 'Basic', type: 'currency' },
      { key: 'allowances', label: 'Allowances', type: 'currency' },
      { key: 'deductions', label: 'Deductions', type: 'currency' },
      { key: 'net_salary', label: 'Net', type: 'currency' },
      { key: 'status', label: 'Status' },
    ],
    fields: [
      { key: 'employee_id', label: 'Employee', type: 'select', required: true, optionsFrom: '/employees', optionLabel: 'name' },
      { key: 'month', label: 'Month', type: 'month', required: true },
      { key: 'basic', label: 'Basic', type: 'number', step: '0.01', default: 0 },
      { key: 'allowances', label: 'Allowances', type: 'number', step: '0.01', default: 0 },
      { key: 'deductions', label: 'Deductions', type: 'number', step: '0.01', default: 0 },
      {
        key: 'status',
        label: 'Status',
        type: 'select',
        default: 'draft',
        options: [
          { label: 'Draft', value: 'draft' },
          { label: 'Paid', value: 'paid' },
        ],
      },
      { key: 'paid_on', label: 'Paid On', type: 'date' },
      { key: 'remarks', label: 'Remarks', type: 'text' },
    ],
  },
  'leave-types': {
    key: 'leave-types',
    title: 'Leave Types',
    endpoint: '/leave-types',
    permission: 'leave-types',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'annual_quota', label: 'Annual Quota' },
      { key: 'is_paid', label: 'Paid', type: 'boolean' },
      { key: 'carry_forward', label: 'Carry Fwd', type: 'boolean' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'annual_quota', label: 'Annual Quota (days)', type: 'number', step: '0.5', default: 0 },
      { key: 'is_paid', label: 'Paid Leave', type: 'checkbox', default: true },
      { key: 'carry_forward', label: 'Carry Forward', type: 'checkbox', default: false },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'salary-structures': {
    key: 'salary-structures',
    title: 'Salary Structures',
    endpoint: '/salary-structures',
    permission: 'salary-structures',
    columns: [
      { key: 'employee_name', label: 'Employee' },
      { key: 'effective_from', label: 'Effective From', type: 'date' },
      { key: 'basic', label: 'Basic', type: 'currency' },
      { key: 'gross', label: 'Gross', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'employee_id', label: 'Employee', type: 'select', required: true, optionsFrom: '/employees', optionLabel: 'name' },
      { key: 'effective_from', label: 'Effective From', type: 'date', required: true },
      { key: 'basic', label: 'Basic', type: 'number', step: '0.01', default: 0 },
      { key: 'hra', label: 'HRA', type: 'number', step: '0.01', default: 0 },
      { key: 'conveyance', label: 'Conveyance', type: 'number', step: '0.01', default: 0 },
      { key: 'medical', label: 'Medical', type: 'number', step: '0.01', default: 0 },
      { key: 'special_allowance', label: 'Special Allowance', type: 'number', step: '0.01', default: 0 },
      { key: 'other_allowances', label: 'Other Allowances', type: 'number', step: '0.01', default: 0 },
      { key: 'pf_applicable', label: 'PF Applicable', type: 'checkbox', default: true },
      { key: 'esi_applicable', label: 'ESI Applicable', type: 'checkbox', default: true },
      { key: 'pt_applicable', label: 'Professional Tax', type: 'checkbox', default: true },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'biometric-devices': {
    key: 'biometric-devices',
    title: 'Biometric Devices',
    endpoint: '/biometric-devices',
    permission: 'biometric-devices',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'location', label: 'Location' },
      { key: 'serial_no', label: 'Serial No' },
      { key: 'ip_address', label: 'IP Address' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'location', label: 'Location', type: 'text' },
      { key: 'serial_no', label: 'Serial No', type: 'text' },
      { key: 'ip_address', label: 'IP Address', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  tailors: {
    key: 'tailors',
    title: 'Tailors',
    endpoint: '/tailors',
    permission: 'tailors',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'mobile', label: 'Mobile' },
      { key: 'skill', label: 'Skill' },
      { key: 'outstanding_balance', label: 'Balance Due', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'contractor_id', label: 'Contractor', type: 'select', optionsFrom: '/contractors', optionLabel: 'name' },
      { key: 'mobile', label: 'Mobile', type: 'text' },
      { key: 'skill', label: 'Skill / Specialization', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  contractors: {
    key: 'contractors',
    title: 'Contractors',
    endpoint: '/contractors',
    permission: 'contractors',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'mobile', label: 'Mobile' },
      { key: 'gstin', label: 'GSTIN' },
      { key: 'tailors_count', label: 'Tailors' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'mobile', label: 'Mobile', type: 'text' },
      { key: 'gstin', label: 'GSTIN', type: 'text' },
      { key: 'pan', label: 'PAN', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'tailor-alterations': {
    key: 'tailor-alterations',
    title: 'Alterations',
    endpoint: '/tailor-alterations',
    permission: 'tailor-alterations',
    /**
     * One screen, two trades. The workroom raises an alteration against a
     * production work order; a garment showroom raises the identical document
     * against a customer and the bill they bought the piece on. The customer and
     * promise columns lead because that is what the retail case is worked by —
     * "whose is it and when did we say?" — and they simply read blank for the
     * workroom rows, which is the honest rendering of a nullable field.
     */
    columns: [
      { key: 'alteration_number', label: 'Alteration #' },
      { key: 'customer_name', label: 'Customer' },
      { key: 'product_name', label: 'Style / Product' },
      { key: 'variant_name', label: 'Size / Colour' },
      { key: 'qty', label: 'Qty' },
      { key: 'reason', label: 'Reason' },
      { key: 'tailor_name', label: 'Tailor' },
      { key: 'status', label: 'Status' },
      { key: 'charge', label: 'Charge', type: 'currency' },
      { key: 'promised_date', label: 'Promised', type: 'date' },
      { key: 'reported_date', label: 'Reported', type: 'date' },
    ],
    filters: [
      {
        key: 'status',
        label: 'Status',
        options: [
          { label: 'Pending', value: 'pending' },
          { label: 'In Progress', value: 'in_progress' },
          { label: 'Done', value: 'done' },
        ],
      },
    ],
    fields: [
      { key: 'customer_id', label: 'Customer', type: 'select', optionsFrom: '/customers', optionLabel: 'name' },
      { key: 'product_id', label: 'Style / Product', type: 'select', optionsFrom: '/products', optionLabel: 'name' },
      { key: 'tailor_id', label: 'Tailor', type: 'select', optionsFrom: '/tailors', optionLabel: 'name' },
      { key: 'qty', label: 'Qty', type: 'number', step: '0.01', default: 1, required: true },
      { key: 'reason', label: 'Reason', type: 'text', required: true },
      {
        key: 'status', label: 'Status', type: 'select', default: 'pending',
        options: [
          { label: 'Pending', value: 'pending' },
          { label: 'In Progress', value: 'in_progress' },
          { label: 'Done', value: 'done' },
        ],
      },
      { key: 'charge', label: 'Alteration Charge', type: 'number', step: '0.01', default: 0 },
      { key: 'reported_date', label: 'Reported Date', type: 'date' },
      // Kept next to the reported date and distinct from the completion date:
      // the gap between promised and completed is the only measure of whether
      // the shop keeps its word.
      { key: 'promised_date', label: 'Promised Date', type: 'date' },
      { key: 'notes', label: 'Notes', type: 'textarea' },
    ],
  },
  /*
  |----------------------------------------------------------------------------
  | Retail — garment & apparel (app/Modules/Industry/Retail)
  |----------------------------------------------------------------------------
  |
  | Four masters, all plain CRUD, so they are config here rather than four
  | near-identical views. The two screens that are not plain CRUD — the size ×
  | colour matrix and the trial room — have views of their own, because neither
  | is a list of records being edited one at a time.
  */
  'garment-fabrics': {
    key: 'garment-fabrics',
    title: 'Fabrics',
    endpoint: '/garment/fabrics',
    permission: 'garment-fabrics',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Fabric' },
      { key: 'composition', label: 'Composition' },
      { key: 'gsm', label: 'GSM' },
      { key: 'width_inch', label: 'Width (in)' },
      { key: 'rate', label: 'Rate', type: 'currency' },
      { key: 'styles_count', label: 'Styles' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Fabric Name', type: 'text', required: true },
      { key: 'composition', label: 'Composition', type: 'text' },
      { key: 'weave', label: 'Weave', type: 'text' },
      { key: 'gsm', label: 'GSM', type: 'number' },
      { key: 'width_inch', label: 'Width (inches)', type: 'number', step: '0.01' },
      { key: 'shrinkage_percent', label: 'Shrinkage %', type: 'number', step: '0.01' },
      { key: 'care', label: 'Care Instructions', type: 'text' },
      { key: 'rate', label: 'Rate per Metre', type: 'number', step: '0.01', default: 0 },
      { key: 'supplier_id', label: 'Supplier', type: 'select', optionsFrom: '/suppliers', optionLabel: 'name' },
      { key: 'notes', label: 'Notes', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'garment-size-sets': {
    key: 'garment-size-sets',
    title: 'Size Runs',
    endpoint: '/garment/size-sets',
    permission: 'garment-styles',
    columns: [
      { key: 'name', label: 'Size Run' },
      // The run itself, not a count: "XS · S · M · L" says more at a glance
      // than "7 sizes", and the order is the information.
      { key: 'summary', label: 'Sizes' },
      { key: 'size_count', label: 'Count' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'sizes', label: 'Sizes (in order)', type: 'csv', required: true },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  /**
   * The beauty counter's shade master.
   *
   * Runs on the generic CrudView like the garment colour list next to it, and
   * the extra fields are the whole difference between a colour and a shade: a
   * customer choosing a shade is matching it to a face, so finish, undertone and
   * depth are what they are really picking between. Depth sorts the card, which
   * is why it is a number and not another word.
   */
  'cosmetic-shades': {
    key: 'cosmetic-shades',
    title: 'Shade Master',
    endpoint: '/cosmetics/shades',
    permission: 'cosmetic-shades',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Shade' },
      { key: 'brand_name', label: 'Brand' },
      { key: 'family', label: 'Family' },
      { key: 'finish', label: 'Finish' },
      { key: 'undertone', label: 'Undertone' },
      { key: 'depth_level', label: 'Depth' },
      { key: 'hex', label: 'Swatch' },
      // A shade nothing is keyed to is a shade the shop does not actually carry
      // — the gap between a supplier's card and what is on the shelf.
      { key: 'variants_count', label: 'On cards' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    filters: [
      { key: 'brand_id', label: 'Brand', optionsFrom: '/brands', optionLabel: 'name' },
      {
        key: 'finish',
        label: 'Finish',
        options: [
          { label: 'Matte', value: 'matte' },
          { label: 'Satin', value: 'satin' },
          { label: 'Cream', value: 'cream' },
          { label: 'Gloss', value: 'gloss' },
          { label: 'Shimmer', value: 'shimmer' },
          { label: 'Metallic', value: 'metallic' },
          { label: 'Sheer', value: 'sheer' },
          { label: 'Other', value: 'other' },
        ],
      },
      {
        key: 'undertone',
        label: 'Undertone',
        options: [
          { label: 'Warm', value: 'warm' },
          { label: 'Cool', value: 'cool' },
          { label: 'Neutral', value: 'neutral' },
          { label: 'Olive', value: 'olive' },
          { label: 'Not recorded', value: 'unknown' },
        ],
      },
    ],
    fields: [
      // Short and required: it is what the customer says out loud and what the
      // shade card turns into the variant's SKU suffix.
      { key: 'code', label: 'Shade Code', type: 'text', required: true },
      { key: 'name', label: 'Shade Name', type: 'text', required: true },
      // Scoped uniqueness lives here: two houses both number a foundation "015".
      { key: 'brand_id', label: 'Brand', type: 'select', optionsFrom: '/brands', optionLabel: 'name' },
      { key: 'family', label: 'Family (Nude, Berry, Coral…)', type: 'text' },
      {
        key: 'finish',
        label: 'Finish',
        type: 'select',
        required: true,
        default: 'matte',
        options: [
          { label: 'Matte', value: 'matte' },
          { label: 'Satin', value: 'satin' },
          { label: 'Cream', value: 'cream' },
          { label: 'Gloss', value: 'gloss' },
          { label: 'Shimmer', value: 'shimmer' },
          { label: 'Metallic', value: 'metallic' },
          { label: 'Sheer', value: 'sheer' },
          { label: 'Other', value: 'other' },
        ],
      },
      {
        key: 'undertone',
        label: 'Undertone',
        type: 'select',
        required: true,
        default: 'unknown',
        options: [
          { label: 'Warm', value: 'warm' },
          { label: 'Cool', value: 'cool' },
          { label: 'Neutral', value: 'neutral' },
          { label: 'Olive', value: 'olive' },
          { label: 'Not recorded', value: 'unknown' },
        ],
      },
      // Base make-up only — 1 is the fairest, 20 the deepest, and it is what the
      // card is sorted on. Left blank on a lipstick wall, which has no ladder.
      { key: 'depth_level', label: 'Depth (1 fair – 20 deep)', type: 'number' },
      { key: 'hex', label: 'Swatch (#RRGGBB)', type: 'text' },
      { key: 'description', label: 'Notes', type: 'textarea' },
      { key: 'sort_order', label: 'Sort Order', type: 'number', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'garment-colours': {
    key: 'garment-colours',
    title: 'Colours',
    endpoint: '/garment/colours',
    permission: 'garment-styles',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Colour' },
      { key: 'hex', label: 'Swatch' },
      { key: 'sort_order', label: 'Order' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Colour Name', type: 'text', required: true },
      // Becomes the SKU suffix the matrix generates, which is why it is short
      // and required rather than derived from the name.
      { key: 'code', label: 'Short Code', type: 'text', required: true },
      { key: 'hex', label: 'Swatch (#RRGGBB)', type: 'text' },
      { key: 'sort_order', label: 'Sort Order', type: 'number', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'garment-styles': {
    key: 'garment-styles',
    title: 'Styles',
    endpoint: '/garment/styles',
    permission: 'garment-styles',
    columns: [
      { key: 'code', label: 'Style #' },
      { key: 'name', label: 'Style' },
      { key: 'brand_name', label: 'Brand' },
      { key: 'fabric_name', label: 'Fabric' },
      { key: 'gender', label: 'For' },
      { key: 'season', label: 'Season' },
      { key: 'mrp', label: 'MRP', type: 'currency' },
      // A style with no product behind it cannot be sold — the gap between
      // buying a range and having it on the floor.
      { key: 'products_count', label: 'Products' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    filters: [
      { key: 'brand_id', label: 'Brand', optionsFrom: '/brands', optionLabel: 'name' },
      { key: 'fabric_id', label: 'Fabric', optionsFrom: '/garment/fabrics', optionLabel: 'name' },
      {
        key: 'gender',
        label: 'For',
        options: [
          { label: "Men's", value: 'mens' },
          { label: "Women's", value: 'womens' },
          { label: 'Boys', value: 'boys' },
          { label: 'Girls', value: 'girls' },
          { label: 'Unisex', value: 'unisex' },
          { label: 'Infant', value: 'infant' },
        ],
      },
    ],
    fields: [
      { key: 'code', label: 'Style / Design No.', type: 'text', required: true },
      { key: 'name', label: 'Style Name', type: 'text', required: true },
      { key: 'category_id', label: 'Category', type: 'select', optionsFrom: '/categories', optionLabel: 'name' },
      { key: 'brand_id', label: 'Brand', type: 'select', optionsFrom: '/brands', optionLabel: 'name' },
      { key: 'fabric_id', label: 'Fabric', type: 'select', optionsFrom: '/garment/fabrics', optionLabel: 'name' },
      // Only the default grid — the matrix screen can override it per style.
      { key: 'size_set_id', label: 'Default Size Run', type: 'select', optionsFrom: '/garment/size-sets', optionLabel: 'name' },
      {
        key: 'gender', label: 'For', type: 'select',
        options: [
          { label: "Men's", value: 'mens' },
          { label: "Women's", value: 'womens' },
          { label: 'Boys', value: 'boys' },
          { label: 'Girls', value: 'girls' },
          { label: 'Unisex', value: 'unisex' },
          { label: 'Infant', value: 'infant' },
        ],
      },
      { key: 'season', label: 'Season', type: 'text' },
      { key: 'fit', label: 'Fit', type: 'text' },
      { key: 'sleeve', label: 'Sleeve', type: 'text' },
      { key: 'neck', label: 'Neck', type: 'text' },
      { key: 'pattern', label: 'Pattern', type: 'text' },
      // Priced at the style: every piece cut from it sells at one price in this
      // trade, and the matrix stamps these onto the variants it creates.
      { key: 'mrp', label: 'MRP', type: 'number', step: '0.01', default: 0 },
      { key: 'cost_price', label: 'Cost Price', type: 'number', step: '0.01', default: 0 },
      { key: 'description', label: 'Description', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'rate-cards': {
    key: 'rate-cards',
    title: 'Rate Cards',
    endpoint: '/rate-cards',
    permission: 'rate-cards',
    columns: [
      { key: 'product_name', label: 'Style / Product' },
      { key: 'standard_rate', label: 'Standard Rate', type: 'currency' },
      { key: 'effective_rate', label: 'Effective Rate', type: 'currency' },
      { key: 'operations_count', label: 'Operations' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'product_id', label: 'Style / Product', type: 'select', required: true, optionsFrom: '/products', optionLabel: 'name' },
      { key: 'standard_rate', label: 'Standard Rate (per piece)', type: 'number', step: '0.01', default: 0 },
      { key: 'notes', label: 'Notes', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'piece-rates': {
    key: 'piece-rates',
    title: 'Piece Rates',
    endpoint: '/piece-rates',
    permission: 'piece-rates',
    columns: [
      { key: 'product_name', label: 'Style / Product' },
      { key: 'operation', label: 'Operation' },
      { key: 'rate', label: 'Rate', type: 'currency' },
      { key: 'sequence', label: 'Seq' },
    ],
    fields: [
      { key: 'rate_card_id', label: 'Rate Card (Style)', type: 'select', required: true, optionsFrom: '/rate-cards', optionLabel: 'product_name' },
      { key: 'operation', label: 'Operation', type: 'text', required: true },
      { key: 'rate', label: 'Rate (per piece)', type: 'number', step: '0.01', default: 0 },
      { key: 'sequence', label: 'Sequence', type: 'number', step: '1', default: 0 },
    ],
  },
  'colour-printings': {
    key: 'colour-printings',
    title: 'Colour Printing',
    endpoint: '/colour-printings',
    permission: 'colour-printing',
    columns: [
      { key: 'printer_vendor_name', label: 'Printer Vendor' },
      { key: 'print_type', label: 'Print Type' },
      { key: 'screen_count', label: 'Screen Count' },
      { key: 'colour_count', label: 'Colour Count' },
      { key: 'cost', label: 'Cost', type: 'currency' },
      { key: 'status', label: 'Status' },
    ],
    fields: [
      { key: 'printer_vendor_id', label: 'Printer Vendor', type: 'select', required: true, optionsFrom: '/suppliers', optionLabel: 'name' },
      {
        key: 'print_type',
        label: 'Print Type',
        type: 'select',
        required: true,
        default: 'screen',
        options: [
          { label: 'Screen', value: 'screen' },
          { label: 'Digital (DTG)', value: 'digital' },
          { label: 'Sublimation', value: 'sublimation' },
          { label: 'DTF', value: 'dtf' },
          { label: 'Vinyl', value: 'vinyl' },
          { label: 'Block', value: 'block' },
          { label: 'Offset', value: 'offset' },
        ],
      },
      { key: 'screen_count', label: 'Screen Count', type: 'number', step: '1', default: 0 },
      { key: 'colour_count', label: 'Colour Count', type: 'number', step: '1', default: 0 },
      { key: 'cost', label: 'Cost', type: 'number', step: '0.01', default: 0 },
      {
        key: 'status',
        label: 'Status',
        type: 'select',
        required: true,
        default: 'pending',
        options: [
          { label: 'Pending', value: 'pending' },
          { label: 'In Progress', value: 'in_progress' },
          { label: 'Completed', value: 'completed' },
          { label: 'Cancelled', value: 'cancelled' },
        ],
      },
      { key: 'notes', label: 'Notes', type: 'textarea' },
    ],
  },
  'embroidery-vendors': {
    key: 'embroidery-vendors',
    title: 'Embroidery Vendors',
    endpoint: '/embroidery-vendors',
    permission: 'embroidery-vendors',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'mobile', label: 'Mobile' },
      { key: 'gst_number', label: 'GST No.' },
      { key: 'outstanding_balance', label: 'Balance Due', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'mobile', label: 'Mobile', type: 'text' },
      { key: 'gst_number', label: 'GST Number', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'expense-categories': {
    key: 'expense-categories',
    title: 'Expense Categories',
    endpoint: '/expense-categories',
    permission: 'expense-categories',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'expenses_count', label: 'Expenses' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  coupons: {
    key: 'coupons',
    title: 'Coupons',
    endpoint: '/coupons',
    permission: 'coupons',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'discount_type', label: 'Type' },
      { key: 'discount_value', label: 'Value' },
      { key: 'min_order_value', label: 'Min Order', type: 'currency' },
      { key: 'used_count', label: 'Used' },
      { key: 'valid_to', label: 'Valid To', type: 'date' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'description', label: 'Description', type: 'text' },
      {
        key: 'discount_type',
        label: 'Discount Type',
        type: 'select',
        required: true,
        default: 'percent',
        options: [
          { label: 'Percent (%)', value: 'percent' },
          { label: 'Flat (₹)', value: 'flat' },
        ],
      },
      { key: 'discount_value', label: 'Discount Value', type: 'number', step: '0.01', default: 0 },
      { key: 'max_discount', label: 'Max Discount (cap, % only)', type: 'number', step: '0.01' },
      { key: 'min_order_value', label: 'Min Order Value', type: 'number', step: '0.01', default: 0 },
      { key: 'usage_limit', label: 'Total Usage Limit', type: 'number', step: '1' },
      { key: 'usage_limit_per_customer', label: 'Per-customer Limit', type: 'number', step: '1' },
      { key: 'valid_from', label: 'Valid From', type: 'date' },
      { key: 'valid_to', label: 'Valid To', type: 'date' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'bank-accounts': {
    key: 'bank-accounts',
    title: 'Bank & Cash Accounts',
    endpoint: '/bank-accounts',
    permission: 'bank-accounts',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type' },
      { key: 'bank_name', label: 'Bank' },
      { key: 'account_number', label: 'A/C No.' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name', type: 'text', required: true },
      {
        key: 'type', label: 'Type', type: 'select', required: true, default: 'bank',
        options: [
          { label: 'Bank', value: 'bank' },
          { label: 'Cash', value: 'cash' },
        ],
      },
      { key: 'is_petty_cash', label: 'Petty Cash Float (cash accounts)', type: 'checkbox', default: false },
      { key: 'bank_name', label: 'Bank Name', type: 'text' },
      { key: 'account_number', label: 'Account Number', type: 'text' },
      { key: 'ifsc', label: 'IFSC', type: 'text' },
      { key: 'branch_name', label: 'Bank Branch', type: 'text' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'opening_balance_date', label: 'Opening Balance Date', type: 'date' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'tds-tcs-rates': {
    key: 'tds-tcs-rates',
    title: 'TDS / TCS Rates',
    endpoint: '/tds-tcs-rates',
    permission: 'tds-tcs-rates',
    columns: [
      { key: 'section', label: 'Section' },
      { key: 'nature', label: 'Nature' },
      { key: 'rate', label: 'Rate %' },
      { key: 'threshold', label: 'Threshold', type: 'currency' },
      { key: 'description', label: 'Description' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'section', label: 'Section (e.g. 194C, 206C(1H))', type: 'text', required: true },
      {
        key: 'nature', label: 'Nature', type: 'select', required: true, default: 'tds',
        options: [
          { label: 'TDS (deducted on purchases/expenses)', value: 'tds' },
          { label: 'TCS (collected on sales)', value: 'tcs' },
        ],
      },
      { key: 'rate', label: 'Rate %', type: 'number', step: '0.01', required: true, default: 0 },
      { key: 'threshold', label: 'Threshold Amount', type: 'number', step: '0.01' },
      { key: 'description', label: 'Description', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  salesmen: {
    key: 'salesmen',
    title: 'Salesmen',
    endpoint: '/salesmen',
    permission: 'salesmen',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'mobile', label: 'Mobile' },
      { key: 'territory', label: 'Territory' },
      { key: 'branch', label: 'Branch' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'mobile', label: 'Mobile', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'territory', label: 'Territory / Area', type: 'text' },
      { key: 'branch_id', label: 'Branch', type: 'select', optionsFrom: '/branches', optionLabel: 'name' },
      { key: 'employee_id', label: 'Employee', type: 'select', optionsFrom: '/employees', optionLabel: 'full_name' },
      { key: 'user_id', label: 'Login Account', type: 'select', optionsFrom: '/users', optionLabel: 'name' },
      { key: 'joining_date', label: 'Joining Date', type: 'date' },
      { key: 'default_incentive_percent', label: 'Default Incentive %', type: 'number', step: '0.01', default: 0 },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  vehicles: {
    key: 'vehicles',
    title: 'Vehicles',
    endpoint: '/vehicles',
    permission: 'vehicles',
    columns: [
      { key: 'registration_no', label: 'Reg. No' },
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type' },
      { key: 'make', label: 'Make' },
      { key: 'model', label: 'Model' },
      { key: 'fuel_type', label: 'Fuel' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'registration_no', label: 'Registration No.', type: 'text', required: true },
      { key: 'name', label: 'Name / Nickname', type: 'text' },
      {
        key: 'type', label: 'Type', type: 'select', required: true, default: 'other',
        options: [
          { label: 'Car', value: 'car' },
          { label: 'Bike', value: 'bike' },
          { label: 'Truck', value: 'truck' },
          { label: 'Tempo', value: 'tempo' },
          { label: 'Van', value: 'van' },
          { label: 'Other', value: 'other' },
        ],
      },
      { key: 'make', label: 'Make', type: 'text' },
      { key: 'model', label: 'Model', type: 'text' },
      {
        key: 'fuel_type', label: 'Fuel Type', type: 'select',
        options: [
          { label: 'Petrol', value: 'petrol' },
          { label: 'Diesel', value: 'diesel' },
          { label: 'CNG', value: 'cng' },
          { label: 'Electric', value: 'electric' },
          { label: 'Other', value: 'other' },
        ],
      },
      { key: 'capacity_kg', label: 'Capacity (kg)', type: 'number', step: '0.01' },
      { key: 'purchase_date', label: 'Purchase Date', type: 'date' },
      { key: 'insurance_expiry', label: 'Insurance Expiry', type: 'date' },
      { key: 'fitness_expiry', label: 'Fitness Expiry', type: 'date' },
      { key: 'permit_expiry', label: 'Permit Expiry', type: 'date' },
      { key: 'puc_expiry', label: 'PUC Expiry', type: 'date' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  drivers: {
    key: 'drivers',
    title: 'Drivers',
    endpoint: '/drivers',
    permission: 'drivers',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'phone', label: 'Phone' },
      { key: 'license_no', label: 'License No.' },
      { key: 'license_expiry', label: 'License Expiry', type: 'date' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'license_no', label: 'License No.', type: 'text' },
      { key: 'license_type', label: 'License Type', type: 'text' },
      { key: 'license_expiry', label: 'License Expiry', type: 'date' },
      { key: 'employee_id', label: 'Employee', type: 'select', optionsFrom: '/employees', optionLabel: 'full_name' },
      { key: 'joining_date', label: 'Joining Date', type: 'date' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'delivery-routes': {
    key: 'delivery-routes',
    title: 'Delivery Routes',
    endpoint: '/delivery-routes',
    permission: 'delivery-routes',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'area', label: 'Area' },
      { key: 'distance_km', label: 'Distance (km)' },
      { key: 'default_charge', label: 'Default Charge', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'area', label: 'Area / Zone', type: 'text' },
      { key: 'start_point', label: 'Start Point', type: 'text' },
      { key: 'end_point', label: 'End Point', type: 'text' },
      { key: 'distance_km', label: 'Distance (km)', type: 'number', step: '0.01' },
      { key: 'estimated_hours', label: 'Estimated Hours', type: 'number', step: '0.01' },
      { key: 'default_charge', label: 'Default Freight Charge', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'transport-charges': {
    key: 'transport-charges',
    title: 'Transport Charges',
    endpoint: '/transport-charges',
    permission: 'transport-charges',
    columns: [
      { key: 'charge_number', label: 'Charge #' },
      { key: 'charge_date', label: 'Date', type: 'date' },
      { key: 'charge_type', label: 'Type' },
      { key: 'transporter_name', label: 'Transporter' },
      { key: 'lr_number', label: 'LR No.' },
      { key: 'payment_mode', label: 'Payment' },
      { key: 'payer', label: 'Payer' },
      { key: 'amount', label: 'Amount', type: 'currency' },
    ],
    fields: [
      { key: 'charge_date', label: 'Charge Date', type: 'date', required: true, default: new Date().toISOString().slice(0, 10) },
      { key: 'dispatch_plan_id', label: 'Dispatch Plan', type: 'select', optionsFrom: '/dispatch-plans', optionLabel: 'dispatch_number' },
      { key: 'vehicle_id', label: 'Vehicle', type: 'select', optionsFrom: '/vehicles', optionLabel: 'registration_no' },
      { key: 'customer_id', label: 'Customer (if billed on)', type: 'select', optionsFrom: '/customers', optionLabel: 'name' },
      { key: 'transporter_name', label: 'Transporter Name', type: 'text' },
      { key: 'lr_number', label: 'LR / Consignment No.', type: 'text' },
      {
        key: 'charge_type', label: 'Charge Type', type: 'select', required: true, default: 'freight',
        options: [
          { label: 'Freight', value: 'freight' },
          { label: 'Loading', value: 'loading' },
          { label: 'Unloading', value: 'unloading' },
          { label: 'Toll', value: 'toll' },
          { label: 'Detention', value: 'detention' },
          { label: 'Other', value: 'other' },
        ],
      },
      {
        key: 'payment_mode', label: 'Payment Mode', type: 'select', required: true, default: 'paid',
        options: [
          { label: 'Paid', value: 'paid' },
          { label: 'To Pay', value: 'to_pay' },
          { label: 'To Be Billed', value: 'to_be_billed' },
        ],
      },
      {
        key: 'payer', label: 'Borne By', type: 'select', required: true, default: 'company',
        options: [
          { label: 'Company', value: 'company' },
          { label: 'Customer', value: 'customer' },
        ],
      },
      { key: 'amount', label: 'Amount', type: 'number', step: '0.01', required: true, default: 0 },
      { key: 'remarks', label: 'Remarks', type: 'textarea' },
    ],
  },
  assets: {
    key: 'assets',
    title: 'Assets',
    endpoint: '/assets',
    permission: 'assets',
    columns: [
      { key: 'asset_code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'category', label: 'Category' },
      { key: 'branch', label: 'Branch' },
      { key: 'location', label: 'Location' },
      { key: 'purchase_value', label: 'Value', type: 'currency' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'asset_code', label: 'Asset Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      {
        key: 'category', label: 'Category', type: 'select', required: true, default: 'other',
        options: [
          { label: 'Machine', value: 'machine' },
          { label: 'Equipment', value: 'equipment' },
          { label: 'Furniture', value: 'furniture' },
          { label: 'Electronic', value: 'electronic' },
          { label: 'Vehicle', value: 'vehicle' },
          { label: 'Building', value: 'building' },
          { label: 'Other', value: 'other' },
        ],
      },
      { key: 'branch_id', label: 'Branch', type: 'select', optionsFrom: '/branches', optionLabel: 'name' },
      { key: 'location', label: 'Location', type: 'text' },
      { key: 'purchase_date', label: 'Purchase Date', type: 'date' },
      { key: 'purchase_value', label: 'Purchase Value', type: 'number', step: '0.01' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  /**
   * The gift shop's wrapping master — what the shop offers to wrap things in.
   *
   * A generic CrudView resource rather than a hand-written screen, because it is
   * genuinely a flat master: four to six rows that change twice a year. The two
   * fields worth reading twice are the pair of products, and they mean opposite
   * things — `product_id` is the *service* the charge is billed as (so wrapping
   * reaches a bill through the ordinary sales spine), and `consumes_product_id`
   * is the *stock* a parcel eats. Both point at /products, which is why the
   * labels spell out which is which.
   *
   * The wrap desk itself is not here: a queue with promised times and a
   * two-way stock movement behind one of its buttons is not a master.
   */
  'gift-wrap-options': {
    key: 'gift-wrap-options',
    title: 'Wrapping Options',
    endpoint: '/gift/wrap-options',
    permission: 'gift-wraps',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Wrapping' },
      { key: 'kind', label: 'Kind' },
      { key: 'charge', label: 'Charge', type: 'currency' },
      { key: 'material', label: 'Material used' },
      { key: 'consume_quantity', label: 'Per parcel' },
      { key: 'turnaround_minutes', label: 'Ready in (min)' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Wrapping (as the counter says it)', type: 'text', required: true },
      {
        key: 'kind', label: 'Kind', type: 'select', default: 'paper',
        options: [
          { label: 'Paper', value: 'paper' },
          { label: 'Rigid box', value: 'box' },
          { label: 'Gift bag', value: 'bag' },
          { label: 'Hamper basket', value: 'basket' },
          { label: 'Ribbon / finishing', value: 'ribbon' },
          { label: 'Greeting card', value: 'card' },
          { label: 'Other', value: 'other' },
        ],
      },
      { key: 'charge', label: 'Charge', type: 'number', step: '0.01', default: 0 },
      {
        key: 'product_id', label: 'Billed as (service product — leave blank if free)',
        type: 'select', optionsFrom: '/products', optionLabel: 'name',
      },
      {
        key: 'consumes_product_id', label: 'Material taken off stock (paper, box, ribbon)',
        type: 'select', optionsFrom: '/products', optionLabel: 'name',
      },
      { key: 'consume_quantity', label: 'Material used per parcel', type: 'number', step: '0.001', default: 1 },
      { key: 'turnaround_minutes', label: 'Ready in (minutes)', type: 'number', step: '1' },
      { key: 'notes', label: 'Notes', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  /**
   * Books & Periodicals (app/Modules/Industry/BookStore). Three flat registers
   * on the generic CrudView — the fourth screen, Titles, has one of its own
   * because a title is a product and needs the ISBN lookup and the credits
   * editor beside it.
   */
  'book-publishers': {
    key: 'book-publishers',
    title: 'Publishers',
    endpoint: '/books/publishers',
    permission: 'book-catalogue',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Publisher' },
      { key: 'imprint', label: 'Imprint' },
      { key: 'trade_discount_percent', label: 'Trade discount %' },
      // Said in words by the API, because "returnable: yes" and "within 180
      // days" are different facts and a buyer needs both at a glance.
      { key: 'returns_terms', label: 'Terms' },
      { key: 'supplier', label: 'Bought from' },
      { key: 'titles_count', label: 'Titles' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Publishing house', type: 'text', required: true },
      { key: 'imprint', label: 'Imprint (e.g. Penguin Classics)', type: 'text' },
      {
        key: 'supplier_id', label: 'Bought from (distributor — leave blank if direct)',
        type: 'select', optionsFrom: '/suppliers', optionLabel: 'name',
      },
      { key: 'trade_discount_percent', label: 'Trade discount off printed price (%)', type: 'number', step: '0.01' },
      { key: 'is_returnable', label: 'Takes unsold copies back (sale or return)', type: 'checkbox', default: false },
      { key: 'return_window_days', label: 'Return window (days from invoice — blank if open ended)', type: 'number', step: '1' },
      { key: 'contact_person', label: 'Contact person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'website', label: 'Website', type: 'text' },
      { key: 'notes', label: 'Notes', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [
      {
        key: 'is_returnable',
        label: 'Terms',
        options: [
          { label: 'Sale or return', value: 1 },
          { label: 'Firm sale', value: 0 },
        ],
      },
    ],
  },
  'book-authors': {
    key: 'book-authors',
    title: 'Authors',
    endpoint: '/books/authors',
    permission: 'book-catalogue',
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'shelf_name', label: 'Shelved as' },
      { key: 'language', label: 'Writes in' },
      { key: 'nationality', label: 'Nationality' },
      { key: 'contributions_count', label: 'Credits' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'name', label: 'Name (as printed on the cover)', type: 'text', required: true },
      // Cannot be derived — particles, single names, and scripts with no
      // surname at all. Left blank the register falls back to the name.
      { key: 'sort_name', label: 'Shelved as (e.g. Meghani, Jhaverchand)', type: 'text' },
      { key: 'language', label: 'Writes mainly in', type: 'text' },
      { key: 'nationality', label: 'Nationality', type: 'text' },
      { key: 'website', label: 'Website', type: 'text' },
      { key: 'biography', label: 'Notes', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'book-languages': {
    key: 'book-languages',
    title: 'Book Languages',
    endpoint: '/books/languages',
    permission: 'book-catalogue',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'label', label: 'Language' },
      { key: 'native_name', label: 'On the shelf card' },
      { key: 'script', label: 'Script' },
      { key: 'sort_order', label: 'Order' },
      { key: 'titles_count', label: 'Titles' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code (gu, hi, en)', type: 'text', required: true },
      { key: 'label', label: 'Language', type: 'text', required: true },
      { key: 'native_name', label: 'In its own script (ગુજરાતી)', type: 'text' },
      { key: 'script', label: 'Script', type: 'text' },
      // The sections are laid out in the shop's own order, not alphabetically —
      // a shop puts its own language first.
      { key: 'sort_order', label: 'Shelf order', type: 'number', step: '1', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'hsn-codes': {
    key: 'hsn-codes',
    title: 'HSN / SAC Master',
    endpoint: '/hsn-codes',
    permission: 'hsn-codes',
    columns: [
      { key: 'code', label: 'HSN / SAC' },
      { key: 'description', label: 'Description' },
      { key: 'type', label: 'Type' },
      { key: 'gst_rate', label: 'GST %' },
      { key: 'cess_rate', label: 'Cess %' },
      { key: 'uqc', label: 'UQC' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'HSN / SAC Code', type: 'text', required: true },
      {
        key: 'type', label: 'Type', type: 'select', default: 'goods',
        options: [
          { label: 'Goods (HSN)', value: 'goods' },
          { label: 'Service (SAC)', value: 'service' },
        ],
      },
      { key: 'description', label: 'Description', type: 'text', required: true },
      { key: 'gst_rate', label: 'GST Rate %', type: 'number', step: '0.01', default: 0, required: true },
      { key: 'cess_rate', label: 'Cess Rate %', type: 'number', step: '0.01', default: 0 },
      { key: 'uqc', label: 'Unit Quantity Code (e.g. MTR, PCS)', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'gst-registrations': {
    key: 'gst-registrations',
    title: 'GST Registrations',
    endpoint: '/gst-registrations',
    permission: 'gst-registrations',
    columns: [
      { key: 'gstin', label: 'GSTIN' },
      { key: 'legal_name', label: 'Legal Name' },
      { key: 'state', label: 'State' },
      { key: 'registration_type', label: 'Type' },
      { key: 'is_primary', label: 'Primary', type: 'boolean' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'company_id', label: 'Company', type: 'select', optionsFrom: '/companies', optionLabel: 'name', required: true },
      { key: 'branch_id', label: 'Branch', type: 'select', optionsFrom: '/branches', optionLabel: 'name' },
      { key: 'gstin', label: 'GSTIN', type: 'text', required: true },
      { key: 'legal_name', label: 'Legal Name', type: 'text' },
      { key: 'trade_name', label: 'Trade Name', type: 'text' },
      {
        key: 'registration_type', label: 'Registration Type', type: 'select', default: 'regular',
        options: [
          { label: 'Regular', value: 'regular' },
          { label: 'Composition', value: 'composition' },
          { label: 'Casual', value: 'casual' },
          { label: 'SEZ', value: 'sez' },
          { label: 'Input Service Distributor', value: 'input_service_distributor' },
          { label: 'TDS', value: 'tds' },
          { label: 'TCS', value: 'tcs' },
          { label: 'Unregistered', value: 'unregistered' },
        ],
      },
      { key: 'address', label: 'Registered Address', type: 'textarea' },
      { key: 'effective_from', label: 'Effective From', type: 'date' },
      { key: 'is_primary', label: 'Primary (default on documents)', type: 'checkbox', default: false },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },

  // ---- Import & Export Logistics: masters ----
  countries: {
    key: 'countries',
    title: 'Countries',
    endpoint: '/countries',
    permission: 'countries',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'iso3', label: 'ISO3' },
      { key: 'name', label: 'Name' },
      { key: 'currency_code', label: 'Currency' },
      { key: 'dial_code', label: 'Dial Code' },
      { key: 'region', label: 'Region' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'ISO Code (2 letters)', type: 'text', required: true },
      { key: 'iso3', label: 'ISO Code (3 letters)', type: 'text' },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'currency_code', label: 'Currency Code', type: 'text' },
      { key: 'dial_code', label: 'Dial Code', type: 'text' },
      { key: 'region', label: 'Region', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  ports: {
    key: 'ports',
    title: 'Ports',
    endpoint: '/ports',
    permission: 'ports',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'type', label: 'Type' },
      { key: 'country', label: 'Country' },
      { key: 'city', label: 'City' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Port Code (UN/LOCODE)', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      {
        key: 'type', label: 'Type', type: 'select', required: true, default: 'sea',
        options: [
          { label: 'Sea Port', value: 'sea' },
          { label: 'Airport', value: 'air' },
          { label: 'ICD (Inland Container Depot)', value: 'icd' },
          { label: 'CFS (Container Freight Station)', value: 'cfs' },
        ],
      },
      { key: 'country_id', label: 'Country', type: 'select', optionsFrom: '/countries', optionLabel: 'name' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'state', label: 'State', type: 'text' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [
      {
        key: 'type', label: 'Types',
        options: [
          { label: 'Sea Port', value: 'sea' },
          { label: 'Airport', value: 'air' },
          { label: 'ICD', value: 'icd' },
          { label: 'CFS', value: 'cfs' },
        ],
      },
    ],
  },
  'shipping-lines': {
    key: 'shipping-lines',
    title: 'Shipping Lines',
    endpoint: '/shipping-lines',
    permission: 'shipping-lines',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'scac_code', label: 'SCAC' },
      { key: 'contact_person', label: 'Contact Person' },
      { key: 'phone', label: 'Phone' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'scac_code', label: 'SCAC Code', type: 'text' },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'website', label: 'Website', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  airlines: {
    key: 'airlines',
    title: 'Airlines',
    endpoint: '/airlines',
    permission: 'airlines',
    columns: [
      { key: 'code', label: 'IATA' },
      { key: 'icao_code', label: 'ICAO' },
      { key: 'name', label: 'Name' },
      { key: 'awb_prefix', label: 'AWB Prefix' },
      { key: 'phone', label: 'Phone' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'IATA Code', type: 'text', required: true },
      { key: 'icao_code', label: 'ICAO Code', type: 'text' },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'awb_prefix', label: 'AWB Prefix', type: 'text' },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'website', label: 'Website', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
  },
  'freight-forwarders': {
    key: 'freight-forwarders',
    title: 'Freight Forwarders',
    endpoint: '/freight-forwarders',
    permission: 'freight-forwarders',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'contact_person', label: 'Contact Person' },
      { key: 'phone', label: 'Phone' },
      { key: 'country', label: 'Country' },
      { key: 'city', label: 'City' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'gst_number', label: 'GSTIN', type: 'text' },
      { key: 'pan_number', label: 'PAN', type: 'text' },
      { key: 'country_id', label: 'Country', type: 'select', optionsFrom: '/countries', optionLabel: 'name' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [{ key: 'country_id', label: 'Countries', optionsFrom: '/countries', optionLabel: 'name' }],
  },
  'cha-agents': {
    key: 'cha-agents',
    title: 'CHA Agents',
    endpoint: '/cha-agents',
    permission: 'cha-agents',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'license_no', label: 'License No.' },
      { key: 'license_expiry', label: 'License Expiry', type: 'date' },
      { key: 'port', label: 'Port' },
      { key: 'phone', label: 'Phone' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'license_no', label: 'CHA License No.', type: 'text' },
      { key: 'license_expiry', label: 'License Expiry', type: 'date' },
      { key: 'port_id', label: 'Port', type: 'select', optionsFrom: '/ports', optionLabel: 'name' },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'gst_number', label: 'GSTIN', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [{ key: 'port_id', label: 'Ports', optionsFrom: '/ports', optionLabel: 'name' }],
  },
  transporters: {
    key: 'transporters',
    title: 'Transporters',
    endpoint: '/transporters',
    permission: 'transporters',
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'transport_mode', label: 'Mode' },
      { key: 'contact_person', label: 'Contact Person' },
      { key: 'phone', label: 'Phone' },
      { key: 'city', label: 'City' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      {
        key: 'transport_mode', label: 'Transport Mode', type: 'select', required: true, default: 'road',
        options: [
          { label: 'Road', value: 'road' },
          { label: 'Rail', value: 'rail' },
          { label: 'Multimodal', value: 'multimodal' },
        ],
      },
      { key: 'vehicle_types', label: 'Vehicle Types', type: 'text' },
      { key: 'contact_person', label: 'Contact Person', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'gst_number', label: 'GSTIN', type: 'text' },
      { key: 'pan_number', label: 'PAN', type: 'text' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [
      {
        key: 'transport_mode', label: 'Modes',
        options: [
          { label: 'Road', value: 'road' },
          { label: 'Rail', value: 'rail' },
          { label: 'Multimodal', value: 'multimodal' },
        ],
      },
    ],
  },
  // Overseas parties are the ordinary supplier / customer masters pinned to
  // is_overseas — same records, so import POs and export SOs keep working.
  'overseas-suppliers': {
    key: 'overseas-suppliers',
    title: 'Overseas Suppliers',
    endpoint: '/suppliers',
    permission: 'suppliers',
    fixedParams: { is_overseas: true },
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'country_name', label: 'Country' },
      { key: 'currency_code', label: 'Currency' },
      { key: 'incoterm', label: 'Incoterm' },
      { key: 'port_name', label: 'Port of Loading' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'company_name', label: 'Company Name', type: 'text' },
      { key: 'country_id', label: 'Country', type: 'select', optionsFrom: '/countries', optionLabel: 'name' },
      { key: 'currency_code', label: 'Currency Code', type: 'text' },
      {
        key: 'incoterm', label: 'Incoterm', type: 'select',
        options: [
          { label: 'EXW', value: 'EXW' },
          { label: 'FOB', value: 'FOB' },
          { label: 'FCA', value: 'FCA' },
          { label: 'CFR', value: 'CFR' },
          { label: 'CIF', value: 'CIF' },
          { label: 'CIP', value: 'CIP' },
          { label: 'DAP', value: 'DAP' },
          { label: 'DDP', value: 'DDP' },
        ],
      },
      { key: 'port_id', label: 'Port of Loading', type: 'select', optionsFrom: '/ports', optionLabel: 'name' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'swift_code', label: 'SWIFT Code', type: 'text' },
      { key: 'bank_details', label: 'Bank Details', type: 'text' },
      { key: 'payment_terms_days', label: 'Payment Terms (days)', type: 'number', default: 0 },
      { key: 'credit_limit', label: 'Credit Limit', type: 'number', step: '0.01', default: 0 },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [{ key: 'country_id', label: 'Countries', optionsFrom: '/countries', optionLabel: 'name' }],
  },
  'overseas-customers': {
    key: 'overseas-customers',
    title: 'Overseas Customers',
    endpoint: '/customers',
    permission: 'customers',
    fixedParams: { is_overseas: true },
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Name' },
      { key: 'country_name', label: 'Country' },
      { key: 'currency_code', label: 'Currency' },
      { key: 'incoterm', label: 'Incoterm' },
      { key: 'port_name', label: 'Port of Discharge' },
      { key: 'is_active', label: 'Active', type: 'boolean' },
    ],
    fields: [
      { key: 'code', label: 'Code', type: 'text', required: true },
      { key: 'name', label: 'Name', type: 'text', required: true },
      { key: 'company_name', label: 'Company Name', type: 'text' },
      {
        key: 'type', label: 'Type', type: 'select', required: true, default: 'wholesale',
        options: [
          { label: 'Retail', value: 'retail' },
          { label: 'Wholesale', value: 'wholesale' },
          { label: 'Distributor', value: 'distributor' },
        ],
      },
      { key: 'country_id', label: 'Country', type: 'select', optionsFrom: '/countries', optionLabel: 'name' },
      { key: 'currency_code', label: 'Currency Code', type: 'text' },
      {
        key: 'incoterm', label: 'Incoterm', type: 'select',
        options: [
          { label: 'EXW', value: 'EXW' },
          { label: 'FOB', value: 'FOB' },
          { label: 'FCA', value: 'FCA' },
          { label: 'CFR', value: 'CFR' },
          { label: 'CIF', value: 'CIF' },
          { label: 'CIP', value: 'CIP' },
          { label: 'DAP', value: 'DAP' },
          { label: 'DDP', value: 'DDP' },
        ],
      },
      { key: 'port_id', label: 'Port of Discharge', type: 'select', optionsFrom: '/ports', optionLabel: 'name' },
      { key: 'lc_terms', label: 'Letter of Credit Terms', type: 'text' },
      { key: 'iec_code', label: 'IEC Code', type: 'text' },
      { key: 'email', label: 'Email', type: 'text' },
      { key: 'phone', label: 'Phone', type: 'text' },
      { key: 'city', label: 'City', type: 'text' },
      { key: 'address', label: 'Address', type: 'textarea' },
      { key: 'credit_limit', label: 'Credit Limit', type: 'number', step: '0.01', default: 0 },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', step: '0.01', default: 0 },
      { key: 'is_active', label: 'Active', type: 'checkbox', default: true },
    ],
    filters: [{ key: 'country_id', label: 'Countries', optionsFrom: '/countries', optionLabel: 'name' }],
  },
}
