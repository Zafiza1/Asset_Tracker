# Module Builder Foundation

The Module Builder is a future extensibility layer, not a way to bypass the
versioned Module Registry. It must never mutate core tables or grant itself
global access.

## Publication contract

A publishable custom module must eventually provide a versioned manifest with:

- field, form, table, relation, workflow and permission definitions;
- tenant-safe validation and authorization requirements;
- lifecycle and upgrade compatibility rules; and
- events that use the platform's normalized event contract.

Until that contract and its migration/rollback strategy are complete, the UI is
deliberately a design-only workspace. Custom Fields remain the supported
configuration mechanism for the current MVP.
