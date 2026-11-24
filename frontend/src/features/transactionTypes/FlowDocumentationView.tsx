import { CheckCircle2, CircleSlash } from 'lucide-react'
import type { FlowDocumentation } from './api'

/**
 * "Cara kerja transaksi ini" (F08-23): who records, the conditions, the form, the numbered steps,
 * what happens to the asset, who gets tasks and emails, and the named rules. Every sentence comes
 * from the server (FlowDescriber, D-41) and is shown as text, never as HTML (F-08 §6).
 */
export function FlowDocumentationView({ doc }: { doc: FlowDocumentation }) {
  return (
    <div className="space-y-4 text-sm text-slate-700">
      {doc.purpose && <p>{doc.purpose}</p>}
      <p className="rounded-lg bg-brand-50 px-3 py-2 text-brand-900">{doc.summary}</p>

      <Section title="Siapa yang mencatat">
        <p>{doc.who_records}</p>
        {doc.preconditions && <p className="mt-1">{doc.preconditions}</p>}
      </Section>

      {doc.form.length > 0 && (
        <Section title="Isian formulir">
          <ul className="list-disc space-y-0.5 pl-5">
            {doc.form.map((f) => (
              <li key={f.key}>
                {f.label} <span className="text-slate-500">({f.type}{f.required ? ', wajib' : ''}{f.asked_in ? `, diisi di langkah "${f.asked_in}"` : ''})</span>
              </li>
            ))}
          </ul>
        </Section>
      )}

      {doc.steps.length > 0 && (
        <Section title="Langkah">
          <ol className="space-y-1.5">
            {doc.steps.map((s) => (
              <li key={s.id} className="flex gap-2">
                <span className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold tabular-nums text-slate-700">{s.number}</span>
                <span>
                  <span className="font-semibold text-slate-900">{s.label}.</span> {s.sentence}
                </span>
              </li>
            ))}
          </ol>
        </Section>
      )}

      {doc.effects.length > 0 && (
        <Section title="Yang terjadi pada aset">
          <ul className="list-disc space-y-0.5 pl-5">
            {doc.effects.map((e) => (
              <li key={e}>{e}</li>
            ))}
          </ul>
        </Section>
      )}

      {doc.people.length > 0 && (
        <Section title="Tugas dan email">
          <ul className="list-disc space-y-0.5 pl-5">
            {doc.people.map((p) => (
              <li key={p}>{p}</li>
            ))}
          </ul>
        </Section>
      )}

      {doc.rules.length > 0 && (
        <Section title="Rules">
          <ul className="space-y-1.5">
            {doc.rules.map((r) => (
              <li key={r.id} className={r.applies ? '' : 'text-slate-500'}>
                <span className="flex items-start gap-1.5">
                  {r.applies ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-green-600" aria-hidden /> : <CircleSlash className="mt-0.5 size-4 shrink-0 text-slate-400" aria-hidden />}
                  <span>
                    <span className="font-semibold">{r.name}.</span> {r.sentence}
                    {r.note && <span className="block text-xs">{r.note}</span>}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        </Section>
      )}

      {doc.changes.length > 0 && (
        <Section title={`Perubahan dari versi ${doc.previous_version ?? 'sebelumnya'}`}>
          <ul className="list-disc space-y-0.5 pl-5">
            {doc.changes.map((c) => (
              <li key={c}>{c}</li>
            ))}
          </ul>
        </Section>
      )}
    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section>
      <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h3>
      {children}
    </section>
  )
}
