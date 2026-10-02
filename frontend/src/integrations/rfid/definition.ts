import type { IntegrationDefinition } from '../registry'

export const rfidIntegration: IntegrationDefinition = {
  type: 'rfid',
  name: 'RFID',
  description: 'RFID readers report tag reads through a gateway; reads become asset.detected events for the bound asset.',
  deviceTypes: ['rfid_tag', 'rfid_reader'],
  fields: [
    { key: 'endpoint', label: 'Gateway endpoint', type: 'url', required: true, placeholder: 'https://rfid-gateway.example.com/api' },
    { key: 'api_key', label: 'Gateway API key', type: 'password', required: true, help: 'Stored encrypted; never shown again.' },
    { key: 'poll_interval', label: 'Poll interval (seconds)', type: 'number', placeholder: '60' },
  ],
}
