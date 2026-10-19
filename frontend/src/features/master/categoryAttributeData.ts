import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api/client'
import type { AttributeValue, CategoryAttribute } from '../../lib/api/types'
import { formatAttributeValue } from '../../lib/format'

/** Fields that apply to assets of a category: inherited ones first, then its own. */
export function useCategoryAttributes(categoryId: string | null | undefined) {
  return useQuery({
    queryKey: ['category-attributes', categoryId],
    queryFn: async () => (await api.get<{ data: CategoryAttribute[] }>(`/tenant-masters/categories/${categoryId}/attributes`)).data,
    enabled: !!categoryId,
    staleTime: 30_000,
  })
}

/** Category fields with their values, for detail views. */
export function attributeRows(fields: CategoryAttribute[], values: Record<string, AttributeValue>) {
  return fields.map((f) => ({ key: f.key, label: f.label, value: formatAttributeValue(values[f.key], f.options) }))
}
