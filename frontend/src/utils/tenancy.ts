// Central vs tenant context detection, driven purely by the browser hostname.
//
//   localhost / 127.0.0.1 / yourapp.com   -> central (marketing + signup)
//   acme.localhost / acme.yourapp.com      -> tenant  "acme"
//
// The bare central domains (no subdomain) render the SaaS signup surface;
// anything with a leading label renders the tenant application shell.

const CENTRAL_HOSTS = ['localhost', '127.0.0.1']

/** The tenant subdomain for the current host, or null when on a central host. */
export function getSubdomain(): string | null {
  const host = window.location.hostname

  if (CENTRAL_HOSTS.includes(host)) return null

  // e.g. "acme.localhost" -> "acme"; "acme.yourapp.com" -> "acme"
  const parts = host.split('.')

  // *.localhost  ->  ["acme", "localhost"]
  if (parts.length >= 2 && parts[parts.length - 1] === 'localhost') {
    return parts.slice(0, -1).join('.')
  }

  // A real apex domain like "yourapp.com" (2 labels) is central; a 3+ label
  // host like "acme.yourapp.com" has a tenant label.
  if (parts.length >= 3) {
    return parts[0]
  }

  return null
}

export function isCentral(): boolean {
  return getSubdomain() === null
}

/** Build the URL to a tenant's workspace (used after provisioning). */
export function tenantUrl(subdomain: string): string {
  const { protocol, port } = window.location
  const base = import.meta.env.VITE_TENANT_DOMAIN || 'localhost'
  const portSuffix = port ? `:${port}` : ''
  return `${protocol}//${subdomain}.${base}${portSuffix}`
}
