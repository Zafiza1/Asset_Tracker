import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Button } from '../../components/ui/Button'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { Resource, Role } from '../../lib/api/types'
import { applyApiErrors } from '../../lib/forms'
import { usePermissionCatalog } from './api'
import { PermissionPicker } from './PermissionPicker'

interface FormValues {
  name: string
  description: string
}

/** Creates a role with its permissions without leaving the user form; reports the new role to the caller. */
export function RoleQuickCreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (role: Role) => void }) {
  const queryClient = useQueryClient()
  const catalog = usePermissionCatalog(open)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState, reset } = useForm<FormValues>({ defaultValues: { name: '', description: '' } })

  const close = () => {
    reset()
    setSelected(new Set())
    setFormError(null)
    onClose()
  }

  const save = useMutation({
    mutationFn: (v: FormValues) =>
      api.post<Resource<Role>>('/roles', { name: v.name, description: v.description || null, permissions: [...selected] }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      onCreated(res.data)
      close()
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  const submit = handleSubmit((v) => {
    setFormError(null)
    if (selected.size === 0) return setFormError('Pilih minimal satu permission.')
    save.mutate(v)
  })

  return (
    <Modal
      open={open}
      size="full"
      title="Buat role baru"
      onClose={close}
      footer={
        <>
          <Button variant="secondary" onClick={close}>
            Batal
          </Button>
          <Button loading={save.isPending} onClick={() => void submit()}>
            Simpan role
          </Button>
        </>
      }
    >
      {formError && <Alert tone="error">{formError}</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Role" error={formState.errors.name?.message} required>
          {(p) => <Input {...p} {...register('name', { required: 'Role wajib diisi.' })} />}
        </Field>
        <Field label="Deskripsi" error={formState.errors.description?.message}>
          {(p) => <Input {...p} {...register('description')} />}
        </Field>
      </div>
      <div>
        <p className="mb-2 text-sm font-medium text-slate-700">Permission ({selected.size})</p>
        {catalog.isLoading ? (
          <Spinner />
        ) : catalog.isError ? (
          <ErrorState error={catalog.error} />
        ) : (
          <div className="max-h-[50vh] overflow-y-auto pr-1">
            <PermissionPicker catalog={catalog.data!} selected={selected} onChange={setSelected} editable className="grid gap-3 md:grid-cols-2 lg:grid-cols-3" />
          </div>
        )}
      </div>
    </Modal>
  )
}
