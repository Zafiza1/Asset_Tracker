import type { IntegrationDefinition } from '../registry'

export const gpsIntegration: IntegrationDefinition = {
  type: 'gps',
  name: 'GPS',
  description: 'GPS trackers report positions through a provider; positions become asset.location.updated events for the bound asset.',
  deviceTypes: ['gps_tracker'],
  fields: [
    { key: 'endpoint', label: 'Provider endpoint', type: 'url', required: true, placeholder: 'https://gps-provider.example.com/api' },
    { key: 'api_key', label: 'Provider API key', type: 'password', required: true, help: 'Stored encrypted; never shown again.' },
    { key: 'poll_interval', label: 'Poll interval (seconds, min 10)', type: 'number', placeholder: '60' },
  ],
}
