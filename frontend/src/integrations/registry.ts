import { gpsIntegration } from './gps/definition'
import { rfidIntegration } from './rfid/definition'

/**
 * UI description of an integration type. The backend adapter (implementing
 * IntegrationContract) owns behaviour; this only drives forms and labels, so
 * a new integration needs one definition here and none in Core pages.
 */
export type ConfigField = { key: string; label: string; type?: 'text' | 'url' | 'password' | 'number'; required?: boolean; placeholder?: string; help?: string }
export type IntegrationDefinition = { type: string; name: string; description: string; deviceTypes: string[]; fields: ConfigField[] }

const definitions: IntegrationDefinition[] = [rfidIntegration, gpsIntegration]

const generic = (type: string): IntegrationDefinition => ({
  type, name: type.toUpperCase(), description: 'External integration.', deviceTypes: [],
  fields: [
    { key: 'endpoint', label: 'Endpoint URL', type: 'url', required: true },
    { key: 'api_key', label: 'API key', type: 'password', required: true },
  ],
})

export const integrationDefinition = (type: string) => definitions.find(d => d.type === type) ?? generic(type)
