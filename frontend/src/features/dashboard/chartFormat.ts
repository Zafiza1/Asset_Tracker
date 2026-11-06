const monthShort = new Intl.DateTimeFormat('id-ID', { month: 'short' })
const monthLong = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' })
const toDate = (month: string) => new Date(`${month}-01T00:00:00`)

/** Month labels from a YYYY-MM string. */
export const monthShortLabel = (month: string) => monthShort.format(toDate(month))
export const monthLongLabel = (month: string) => monthLong.format(toDate(month))

export const formatCount = (value: number) => value.toLocaleString('id-ID')

export const sum = (values: number[]) => values.reduce((total, value) => total + value, 0)
