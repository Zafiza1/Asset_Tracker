/**
 * The Jenis Transaksi menu (types, flows and rules) is closed while its design is being researched:
 * it shows "Segera hadir". Set to true to open it for everyone. Recording transactions and flows
 * already published are not affected. The e2e server opens it with VITE_TRANSACTION_TYPES=1.
 */
const OPEN = false

export const transactionTypesOpen = OPEN || import.meta.env.VITE_TRANSACTION_TYPES === '1'
