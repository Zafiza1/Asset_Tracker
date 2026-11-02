/** Categorical slots 1–5 in fixed order (validated adjacent-pair CVD-safe on the light surface). */
export const CATEGORICAL = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'] as const

/** Reserved status colors; always shown with a text label and value, never color alone. */
export const STATUS_COLOR = { good: '#0ca30c', warning: '#fab219', critical: '#d03b3b' } as const
