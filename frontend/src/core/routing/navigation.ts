/**
 * Sidebar navigation. `permission` hides an entry the user cannot use;
 * `module` hides it unless that business module is enabled for the selected
 * project; `project: false` marks control-plane pages that work without a selected
 * project (everything else runs inside the selected project).
 */
export type NavItem = { to: string; label: string; permission?: string; module?: string; project?: false }
export type NavGroup = { title: string; items: NavItem[] }

export const navigation: NavGroup[] = [
  {
    title: 'Runtime',
    items: [
      { to: '/', label: 'Dashboard', permission: 'asset.view' },
      { to: '/assets', label: 'Assets', permission: 'asset.view' },
      { to: '/locations', label: 'Locations', permission: 'location.view' },
      { to: '/movements', label: 'Movements', permission: 'movement.view' },
      { to: '/devices', label: 'Devices', permission: 'device.view' },
      { to: '/integrations', label: 'Integrations', permission: 'integration.view' },
    ],
  },
  {
    title: 'Modules',
    items: [
      { to: '/customers', label: 'Customers', permission: 'customer.view', module: 'customer' },
      { to: '/deliveries', label: 'Deliveries', permission: 'delivery.view', module: 'delivery' },
      { to: '/maintenance', label: 'Maintenance', permission: 'maintenance.view', module: 'maintenance' },
    ],
  },
  {
    title: 'Project setup',
    items: [
      { to: '/modules', label: 'Modules', permission: 'module.view' },
      { to: '/builder', label: 'Builder', permission: 'asset.view' },
      { to: '/webhooks', label: 'Webhooks', permission: 'webhook.view' },
      { to: '/api-keys', label: 'API keys', permission: 'api-key.view' },
      { to: '/members', label: 'Members', permission: 'project.view' },
      { to: '/audit', label: 'Audit log', permission: 'audit.view' },
      { to: '/health', label: 'Health', permission: 'integration.view' },
    ],
  },
  {
    title: 'Platform',
    items: [
      { to: '/organizations', label: 'Organizations', project: false },
      { to: '/templates', label: 'Templates', project: false },
    ],
  },
]

export const projectFreePaths = navigation.flatMap(group => group.items).filter(item => item.project === false).map(item => item.to)
