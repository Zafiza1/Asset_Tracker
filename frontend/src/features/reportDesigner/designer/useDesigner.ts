import { useCallback, useMemo, useRef, useState } from 'react'
import { uid, type DesignDocument, type DesignElement, type PageConfig } from '../design'

/**
 * Designer document state: the working copy, selection, zoom, and an undo/redo
 * history of serialized snapshots (capped). Mutations that should be undoable go
 * through `commit`; live gestures (dragging, typing) use the transient setters and
 * `commit` once at the end so one gesture = one history entry.
 */
export function useDesigner(initial: DesignDocument) {
  const [doc, setDocState] = useState<DesignDocument>(initial)
  const docRef = useRef(doc)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [zoom, setZoom] = useState(1)
  const [past, setPast] = useState<string[]>([])
  const [future, setFuture] = useState<string[]>([])
  const [baseline, setBaseline] = useState(() => JSON.stringify(initial))

  const setDoc = useCallback((next: DesignDocument) => {
    docRef.current = next
    setDocState(next)
  }, [])

  const serialize = (d: DesignDocument) => JSON.stringify(d)

  /** Commit a new document as one undoable step. */
  const commit = useCallback(
    (next: DesignDocument) => {
      setPast((p) => [...p, serialize(docRef.current)].slice(-60))
      setFuture([])
      setDoc(next)
    },
    [setDoc],
  )

  const undo = useCallback(() => {
    const prev = past[past.length - 1]
    if (prev === undefined) return
    setFuture((f) => [serialize(docRef.current), ...f].slice(0, 60))
    setPast((p) => p.slice(0, -1))
    setDoc(JSON.parse(prev) as DesignDocument)
  }, [past, setDoc])

  const redo = useCallback(() => {
    const next = future[0]
    if (next === undefined) return
    setPast((p) => [...p, serialize(docRef.current)].slice(-60))
    setFuture((f) => f.slice(1))
    setDoc(JSON.parse(next) as DesignDocument)
  }, [future, setDoc])

  /** Replace the document without adding history (loading, external reset). */
  const reset = useCallback(
    (next: DesignDocument) => {
      setPast([])
      setFuture([])
      setBaseline(serialize(next))
      setDoc(next)
    },
    [setDoc],
  )

  const markSaved = useCallback(() => {
    setBaseline(serialize(docRef.current))
  }, [])

  const canUndo = past.length > 0
  const canRedo = future.length > 0
  const dirty = serialize(doc) !== baseline
  const selected = useMemo(() => doc.elements.find((e) => e.id === selectedId) ?? null, [doc.elements, selectedId])

  /** Map an element updater over the document; commit or apply transiently. */
  const mutateElement = useCallback(
    (id: string, updater: (el: DesignElement) => DesignElement, transient = false) => {
      const apply = (d: DesignDocument): DesignDocument => ({
        ...d,
        elements: d.elements.map((el) => (el.id === id ? updater(el) : el)),
      })
      const next = apply(docRef.current)
      if (transient) {
        setDoc(next)
      } else {
        commit(next)
      }
    },
    [commit, setDoc],
  )

  /** Shallow-merge style/props/geometry onto an element. */
  const patchElement = useCallback(
    (id: string, patch: Partial<Pick<DesignElement, 'x' | 'y' | 'w' | 'h' | 'z'>> & { style?: DesignElement['style']; props?: DesignElement['props'] }, transient = false) => {
      mutateElement(
        id,
        (el) => ({
          ...el,
          ...patch,
          style: patch.style ? { ...el.style, ...patch.style } : el.style,
          props: patch.props ? { ...el.props, ...patch.props } : el.props,
        }),
        transient,
      )
    },
    [mutateElement],
  )

  const addElement = useCallback(
    (el: DesignElement, transient = false) => {
      const next: DesignDocument = { ...docRef.current, elements: [...docRef.current.elements, el] }
      if (transient) {
        setDoc(next)
      } else {
        commit(next)
        setSelectedId(el.id)
      }
    },
    [commit, setDoc],
  )

  const removeElement = useCallback(
    (id: string) => {
      commit({ ...docRef.current, elements: docRef.current.elements.filter((e) => e.id !== id) })
      setSelectedId((cur) => (cur === id ? null : cur))
    },
    [commit],
  )

  const duplicateElement = useCallback(
    (id: string) => {
      const el = docRef.current.elements.find((e) => e.id === id)
      if (!el) return
      const copy: DesignElement = { ...el, id: uid(), x: el.x + 5, y: el.y + 5, z: el.z + 1, style: { ...el.style }, props: { ...el.props, columns: el.props.columns?.map((c) => ({ ...c })) } }
      const next: DesignDocument = { ...docRef.current, elements: [...docRef.current.elements, copy] }
      commit(next)
      setSelectedId(copy.id)
    },
    [commit],
  )

  const reorder = useCallback(
    (direction: 'front' | 'back') => {
      if (!selectedId) return
      const els = [...docRef.current.elements]
      const index = els.findIndex((e) => e.id === selectedId)
      if (index < 0) return
      const [el] = els.splice(index, 1)
      if (direction === 'front') {
        els.push(el)
      } else {
        els.unshift(el)
      }
      commit({ ...docRef.current, elements: els.map((e, i) => ({ ...e, z: i + 1 })) })
    },
    [commit, selectedId],
  )

  const updatePage = useCallback(
    (patch: Partial<PageConfig>, transient = false) => {
      const next: DesignDocument = { ...docRef.current, page: { ...docRef.current.page, ...patch } }
      if (transient) {
        setDoc(next)
      } else {
        commit(next)
      }
    },
    [commit, setDoc],
  )

  /** Re-base z values so array order is the paint order after loading external JSON. */
  const normalized = useMemo(
    () => ({ ...doc, elements: doc.elements.map((e, i) => ({ ...e, z: i + 1 })) }),
    [doc],
  )

  return {
    doc: normalized,
    selected,
    selectedId,
    select: setSelectedId,
    zoom,
    setZoom,
    dirty,
    canUndo,
    canRedo,
    undo,
    redo,
    reset,
    markSaved,
    addElement,
    patchElement,
    mutateElement,
    removeElement,
    duplicateElement,
    reorder,
    updatePage,
    commit,
    /** Current document for imperative reads (e.g. building the save payload). */
    current: docRef,
  }
}

export type DesignerState = ReturnType<typeof useDesigner>
