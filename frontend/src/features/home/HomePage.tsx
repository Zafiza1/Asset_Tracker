import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  Bell,
  CalendarClock,
  CalendarX,
  CheckCircle2,
  ClipboardList,
  Clock,
  Eye,
  EyeOff,
  FileText,
  History,
  KeyRound,
  ListChecks,
  Mail,
  SlidersHorizontal,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge, StatusBadge } from '../../components/ui/Badge'
import type { BadgeTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { ErrorState, Skeleton } from '../../components/ui/Feedback'
import { IconButton } from '../../components/ui/IconButton'
import { toast } from '../../components/ui/toast'
import { downloadFile } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { auditActionLabel, formatDate, scopeTypeLabel } from '../../lib/format'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { ago, arrangeCards, HOME_CARDS_QUERY_KEY, homeApi, type HomeCard, type HomeCardKey, type HomeCardsResponse, type HomePreferences } from './api'

const cardTitle: Record<HomeCardKey, string> = {
  approvals_to_decide: 'Menunggu keputusan saya',
  transaction_tasks: 'Langkah transaksi menunggu saya',
  my_requests: 'Pengajuan saya',
  overdue_outgoing: 'Keluar sementara lewat batas',
  overdue_loans: 'Peminjaman lewat batas',
  due_soon: 'Jatuh tempo 7 hari',
  date_reminders: 'Pengingat tanggal aset',
  incomplete_assets: 'Data aset belum lengkap',
  license: 'Lisensi & kursi',
  invitations: 'Undangan menunggu',
  users: 'Pengguna & role',
  my_reports: 'Laporan terakhir saya',
  recent_activity: 'Aktivitas terbaru',
}

const cardIcon: Record<HomeCardKey, LucideIcon> = {
  approvals_to_decide: Bell,
  transaction_tasks: ListChecks,
  my_requests: ClipboardList,
  overdue_outgoing: AlertTriangle,
  overdue_loans: CalendarX,
  due_soon: Clock,
  date_reminders: CalendarClock,
  incomplete_assets: ClipboardList,
  license: KeyRound,
  invitations: Mail,
  users: Users,
  my_reports: FileText,
  recent_activity: History,
}

const requestStatusLabel: Record<string, string> = { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak', cancelled: 'Dibatalkan' }
const exportFormatLabel: Record<string, string> = { pdf: 'PDF', xlsx: 'Excel' }

/**
 * Beranda (docs/factory/features/F-06 §3): what the signed-in member has to look at or do.
 * The server picks the cards by permission and data scope; the member may reorder and hide
 * them on wide screens (D-19, D-34). Refreshed every minute while the tab is visible (D-20).
 */
export function HomePage() {
  const { profile } = useAuth()
  const query = useQuery({
    queryKey: HOME_CARDS_QUERY_KEY,
    queryFn: homeApi.cards,
    refetchInterval: (q) => (q.state.data?.meta.refresh_seconds ?? 60) * 1000,
  })
  const canArrange = useCanUseEditor()
  const [draft, setDraft] = useState<HomePreferences | null>(null)

  if (!profile) return null
  const saved = query.data?.meta.preferences ?? { order: [], hidden: [] }
  const preferences = draft ?? saved
  const arranging = draft !== null && canArrange
  const cards = arrangeCards(query.data?.data ?? [], preferences.order)
  const shown = arranging ? cards : cards.filter((c) => !preferences.hidden.includes(c.key))

  const move = (key: HomeCardKey, step: -1 | 1) => {
    const keys = cards.map((c) => c.key)
    const from = keys.indexOf(key)
    const to = from + step
    if (to < 0 || to >= keys.length) return
    ;[keys[from], keys[to]] = [keys[to], keys[from]]
    setDraft({ ...preferences, order: keys })
  }
  const toggle = (key: HomeCardKey) =>
    setDraft({ ...preferences, hidden: preferences.hidden.includes(key) ? preferences.hidden.filter((k) => k !== key) : [...preferences.hidden, key] })

  return (
    <>
      <PageHeader
        title={`${greeting()}, ${profile.user.name}`}
        description={[profile.organization?.name, scopeText(profile.data_scope)].filter(Boolean).join(' · ') || undefined}
        meta={query.dataUpdatedAt ? <span>Diperbarui {new Date(query.dataUpdatedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span> : undefined}
        actions={
          canArrange && query.data ? (
            arranging ? (
              <ArrangeActions draft={preferences} onCancel={() => setDraft(null)} onReset={() => setDraft({ order: [], hidden: [] })} onSaved={() => setDraft(null)} />
            ) : (
              <Button variant="ghost" size="sm" onClick={() => setDraft(saved)}>
                <SlidersHorizontal className="size-4" aria-hidden /> Atur kartu
              </Button>
            )
          ) : undefined
        }
      />

      {arranging && (
        <p className="mb-4 rounded-lg bg-brand-50 px-4 py-2.5 text-sm text-brand-800 ring-1 ring-brand-100">
          Ubah urutan dengan tombol panah dan sembunyikan kartu yang tidak Anda perlukan. Susunan ini hanya berlaku untuk Anda.
        </p>
      )}

      {query.isPending ? (
        <CardsSkeleton />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : shown.length === 0 ? (
        <p className="rounded-xl bg-white p-6 text-sm text-slate-600 ring-1 ring-slate-200">
          {cards.length === 0 ? 'Belum ada kartu untuk akun Anda.' : 'Semua kartu Anda sembunyikan. Pilih Atur kartu (di layar lebar) untuk menampilkannya lagi.'}
        </p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shown.map((card, index) => (
            <HomeCardView
              key={card.key}
              card={card}
              arrange={
                arranging
                  ? {
                      hidden: preferences.hidden.includes(card.key),
                      first: index === 0,
                      last: index === shown.length - 1,
                      onUp: () => move(card.key, -1),
                      onDown: () => move(card.key, 1),
                      onToggle: () => toggle(card.key),
                    }
                  : undefined
              }
            />
          ))}
        </div>
      )}
    </>
  )
}

function ArrangeActions({ draft, onCancel, onReset, onSaved }: { draft: HomePreferences; onCancel: () => void; onReset: () => void; onSaved: () => void }) {
  const client = useQueryClient()
  const save = useMutation({
    mutationFn: () => homeApi.savePreferences(draft),
    onSuccess: (response) => {
      client.setQueryData<HomeCardsResponse>(HOME_CARDS_QUERY_KEY, (old) => (old ? { ...old, meta: { ...old.meta, preferences: response.data } } : old))
      toast.success('Susunan kartu disimpan')
      onSaved()
    },
    onError: (error) => toast.error('Susunan kartu gagal disimpan', error instanceof Error ? error.message : undefined),
  })
  return (
    <>
      <Button variant="ghost" size="sm" onClick={onReset} disabled={save.isPending}>
        Kembalikan bawaan
      </Button>
      <Button variant="secondary" size="sm" onClick={onCancel} disabled={save.isPending}>
        Batal
      </Button>
      <Button size="sm" onClick={() => save.mutate()} loading={save.isPending}>
        Simpan susunan
      </Button>
    </>
  )
}

interface ArrangeControls {
  hidden: boolean
  first: boolean
  last: boolean
  onUp: () => void
  onDown: () => void
  onToggle: () => void
}

function HomeCardView({ card, arrange }: { card: HomeCard; arrange?: ArrangeControls }) {
  const content = cardContent(card)
  const Icon = cardIcon[card.key]
  const title = cardTitle[card.key]
  const accent = content.tone === 'red' ? 'border-l-red-500' : content.tone === 'amber' ? 'border-l-amber-500' : 'border-l-transparent'

  return (
    <section
      aria-label={title}
      className={clsx('flex flex-col rounded-xl border-l-4 bg-white shadow-card ring-1 ring-slate-200/80', accent, arrange?.hidden && 'opacity-50')}
    >
      <header className="flex items-center justify-between gap-2 px-4 pb-2 pt-3.5">
        <h2 className="flex min-w-0 items-start gap-2 text-[0.9375rem] font-semibold leading-snug text-slate-900">
          <Icon className="mt-0.5 size-4 shrink-0 text-slate-500" aria-hidden />
          <span className="min-w-0">{title}</span>
        </h2>
        {content.badge}
      </header>
      <div className="flex-1 px-4 pb-3 text-sm">{content.empty ? <Calm>{content.empty}</Calm> : content.body}</div>
      {arrange ? (
        <footer className="flex items-center justify-between gap-2 border-t border-slate-100 px-3 py-2">
          <span className="text-xs text-slate-500">{arrange.hidden ? 'Disembunyikan' : 'Ditampilkan'}</span>
          <span className="flex items-center gap-1">
            <IconButton label={`Naikkan ${title}`} onClick={arrange.onUp} disabled={arrange.first}>
              <ArrowUp className="size-4" aria-hidden />
            </IconButton>
            <IconButton label={`Turunkan ${title}`} onClick={arrange.onDown} disabled={arrange.last}>
              <ArrowDown className="size-4" aria-hidden />
            </IconButton>
            <Button variant="ghost" size="sm" onClick={arrange.onToggle} aria-label={`${arrange.hidden ? 'Tampilkan' : 'Sembunyikan'} ${title}`}>
              {arrange.hidden ? <Eye className="size-4" aria-hidden /> : <EyeOff className="size-4" aria-hidden />}
              {arrange.hidden ? 'Tampilkan' : 'Sembunyikan'}
            </Button>
          </span>
        </footer>
      ) : (
        content.link && (
          <footer className="border-t border-slate-100 px-4 py-2.5">
            <Link to={content.link.to} className="text-sm font-semibold text-brand-700 hover:underline">
              {content.link.label} →
            </Link>
          </footer>
        )
      )}
    </section>
  )
}

interface CardContent {
  badge?: ReactNode
  /** Left accent of an urgent (red) or attention (amber) card. */
  tone?: BadgeTone
  /** Shown instead of the body when nothing is waiting, so the user knows nothing was missed. */
  empty?: string
  body?: ReactNode
  link?: { to: string; label: string }
}

function cardContent(card: HomeCard): CardContent {
  switch (card.key) {
    case 'approvals_to_decide':
      return {
        badge: count(card.count, 'red'),
        tone: card.count > 0 ? 'red' : undefined,
        empty: card.count === 0 ? 'Tidak ada yang menunggu keputusan Anda.' : undefined,
        body: (
          <Rows>
            {card.items.map((a) => (
              <Row key={a.id} end={<Muted>{ago(a.created_at)}</Muted>}>
                {a.summary}
                {a.requested_by && <Sub>oleh {a.requested_by}</Sub>}
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/notifikasi?status=pending', label: 'Buka Persetujuan' },
      }
    case 'transaction_tasks':
      return {
        badge: count(card.count, card.overdue > 0 ? 'red' : 'amber'),
        tone: card.overdue > 0 ? 'red' : card.count > 0 ? 'amber' : undefined,
        empty: card.count === 0 ? 'Tidak ada langkah yang menunggu Anda.' : undefined,
        body: (
          <Rows>
            {card.items.map((t, i) => (
              <Row
                key={`${t.run_id}-${i}`}
                to={`/transaksi/berjalan/${t.run_id}`}
                end={t.overdue ? <Badge tone="red">Lewat batas</Badge> : t.due_date ? <Muted>Batas {formatDate(t.due_date)}</Muted> : undefined}
              >
                <span className="font-mono text-xs font-semibold">{t.transaction_number}</span>
                {t.step && <> · {t.step}</>}
                <Sub>{[t.type, t.started_by && `dimulai ${t.started_by}`].filter(Boolean).join(' · ')}</Sub>
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/transaksi/berjalan', label: 'Lihat semua tugas' },
      }
    case 'my_requests': {
      const total = Object.values(card.counts).reduce((a, b) => a + b, 0)
      return {
        badge: <Muted>{card.days} hari</Muted>,
        tone: card.counts.rejected > 0 ? 'amber' : undefined,
        empty: total === 0 ? `Belum ada pengajuan dalam ${card.days} hari.` : undefined,
        body: (
          <>
            <div className="mb-2 flex flex-wrap gap-1.5">
              {card.counts.pending > 0 && <Badge tone="amber">{card.counts.pending} menunggu</Badge>}
              {card.counts.approved > 0 && <Badge tone="green">{card.counts.approved} disetujui</Badge>}
              {card.counts.rejected > 0 && <Badge tone="red">{card.counts.rejected} ditolak</Badge>}
            </div>
            <Rows>
              {card.items.map((r) => (
                <Row key={r.id}>
                  <StatusBadge status={r.status}>{requestStatusLabel[r.status] ?? r.status}</StatusBadge> {r.summary}
                  {r.status === 'rejected' && r.decision_note && (
                    <Sub>
                      “{r.decision_note}”{r.decided_by && ` · ${r.decided_by}`}
                    </Sub>
                  )}
                </Row>
              ))}
            </Rows>
          </>
        ),
        link: { to: '/notifikasi?status=', label: 'Buka pengajuan' },
      }
    }
    case 'overdue_outgoing':
      return {
        badge: count(card.count, 'red'),
        tone: card.count > 0 ? 'red' : undefined,
        empty: card.count === 0 ? 'Semua aset kembali tepat waktu.' : undefined,
        body: (
          <Rows>
            {card.items.map((t) => (
              <Row key={`${t.transaction_number}-${t.asset.id}`} to={`/aset/${t.asset.id}`} end={<Badge tone="red">{t.days_late} hari</Badge>}>
                {t.asset.name}
                {t.counterparty_name && <Sub>{t.counterparty_name}</Sub>}
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/transaksi?not_returned=1', label: 'Lihat aset belum kembali' },
      }
    case 'overdue_loans':
      return {
        badge: count(card.count, 'red'),
        tone: card.count > 0 ? 'red' : undefined,
        empty: card.count === 0 ? 'Semua aset pinjaman kembali tepat waktu.' : undefined,
        body: (
          <Rows>
            {card.items.map((l) => (
              <Row key={l.asset.id} to={`/aset/${l.asset.id}`} end={<Badge tone="red">{l.days_late} hari</Badge>}>
                {l.asset.name}
                {l.borrower_name && <Sub>dipinjam {l.borrower_name}</Sub>}
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/aset?status=on_loan', label: 'Lihat aset dipinjam' },
      }
    case 'due_soon':
      return {
        badge: count(card.count, 'amber'),
        tone: card.count > 0 ? 'amber' : undefined,
        empty: card.count === 0 ? `Tidak ada yang jatuh tempo dalam ${card.days} hari.` : undefined,
        body: (
          <Rows>
            {card.items.map((d) => (
              <Row key={`${d.kind}-${d.asset.id}`} to={`/aset/${d.asset.id}`} end={<Muted>{formatDate(d.due_date)}</Muted>}>
                {d.asset.name}
                {d.party && <Sub>{d.kind === 'loan' ? `dipinjam ${d.party}` : d.party}</Sub>}
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/transaksi', label: 'Lihat transaksi' },
      }
    case 'date_reminders':
      return {
        badge: <Muted>{card.days} hari</Muted>,
        empty: card.count === 0 ? `Tidak ada pengingat ${card.days} hari ke depan.` : undefined,
        body: (
          <Rows>
            {card.items.map((r) => (
              <Row key={`${r.asset.id}-${r.label}`} to={`/aset/${r.asset.id}`} end={<Muted>{formatDate(r.due_date)}</Muted>}>
                {r.asset.name}
                <Sub>{r.label}</Sub>
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/aset', label: 'Lihat aset' },
      }
    case 'incomplete_assets':
      return {
        badge: count(card.count, 'slate'),
        empty: card.count === 0 ? 'Data semua aset lengkap.' : undefined,
        body: (
          <Rows>
            <Figure label="Tanpa nomor seri" value={card.counts.no_serial_number} />
            <Figure label="Atribut wajib kategori kosong" value={card.counts.missing_required_attribute} />
            <Figure label="Tanpa lokasi" value={card.counts.no_location} />
          </Rows>
        ),
        link: { to: '/aset', label: 'Perbaiki di Data Aset' },
      }
    case 'license': {
      const ending = card.days_left !== null && card.days_left <= 30
      return {
        badge: !card.active ? (
          <Badge tone="red">Tidak aktif</Badge>
        ) : card.over_limit ? (
          <Badge tone="red">Melebihi kursi</Badge>
        ) : ending ? (
          <Badge tone="amber">Berakhir {card.days_left} hari</Badge>
        ) : undefined,
        tone: !card.active || card.over_limit ? 'red' : ending ? 'amber' : undefined,
        body: (
          <>
            <p className="mb-1 pt-1">
              <span className="text-2xl font-semibold tabular-nums text-slate-900">{card.used}</span>
              <span className="text-slate-500"> {card.capacity === null ? 'kursi terpakai' : `dari ${card.capacity} kursi terpakai`}</span>
            </p>
            <Rows>
              <Figure label="Berlaku sampai" value={card.expires_at ? formatDate(card.expires_at) : '—'} />
            </Rows>
          </>
        ),
        link: { to: '/pengaturan', label: 'Kelola lisensi' },
      }
    }
    case 'invitations':
      return {
        badge: count(card.pending, 'slate'),
        empty: card.pending + card.expired === 0 ? 'Tidak ada undangan yang menunggu.' : undefined,
        body: (
          <Rows>
            <Figure label="Belum diterima" value={card.pending} />
            <Figure label="Kedaluwarsa" value={card.expired} />
          </Rows>
        ),
        link: { to: '/pengguna/undangan', label: 'Buka Undangan' },
      }
    case 'users':
      return {
        badge: <Muted>{card.days} hari</Muted>,
        body: (
          <Rows>
            <Figure label="Pengguna baru" value={card.new} />
            <Figure label="Pengguna nonaktif" value={card.deactivated} />
          </Rows>
        ),
        link: { to: '/pengguna', label: 'Buka Pengguna' },
      }
    case 'my_reports':
      return {
        empty: card.items.length === 0 ? 'Belum ada laporan yang Anda jalankan.' : undefined,
        body: (
          <Rows>
            {card.items.map((e) => (
              <Row key={e.id} end={e.downloadable ? <DownloadButton id={e.id} name={`${e.export_number}.${e.format}`} /> : <Muted>Kedaluwarsa</Muted>}>
                <span className="font-mono text-xs font-semibold">{e.export_number}</span>
                <Sub>{[e.template, exportFormatLabel[e.format] ?? e.format].filter(Boolean).join(' · ')}</Sub>
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/laporan/template', label: 'Jalankan laporan' },
      }
    case 'recent_activity':
      return {
        badge: <Muted>24 jam</Muted>,
        empty: card.count === 0 ? 'Belum ada aktivitas dalam 24 jam terakhir.' : undefined,
        body: (
          <Rows>
            {card.items.map((l, i) => (
              <Row key={`${l.created_at}-${i}`} end={<Muted>{ago(l.created_at)}</Muted>}>
                {auditActionLabel(l.action)}
                {l.actor && <Sub>{l.actor}</Sub>}
              </Row>
            ))}
          </Rows>
        ),
        link: { to: '/audit', label: 'Buka Audit Trail' },
      }
  }
}

function DownloadButton({ id, name }: { id: string; name: string }) {
  const [busy, setBusy] = useState(false)
  const download = async () => {
    setBusy(true)
    try {
      await downloadFile(`/report-exports/${id}/download`, name)
    } catch (error) {
      toast.error('Laporan gagal diunduh', error instanceof Error ? error.message : undefined)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Button variant="ghost" size="sm" onClick={download} loading={busy} aria-label={`Unduh ${name}`}>
      Unduh
    </Button>
  )
}

function Rows({ children }: { children: ReactNode }) {
  return <ul className="divide-y divide-slate-100">{children}</ul>
}

function Row({ to, end, children }: { to?: string; end?: ReactNode; children: ReactNode }) {
  const inner = (
    <>
      <span className="min-w-0 text-slate-800">{children}</span>
      {end && <span className="shrink-0">{end}</span>}
    </>
  )
  return (
    <li>
      {to ? (
        <Link to={to} className="-mx-2 flex items-start justify-between gap-3 rounded-md px-2 py-2 hover:bg-slate-50">
          {inner}
        </Link>
      ) : (
        <div className="flex items-start justify-between gap-3 py-2">{inner}</div>
      )}
    </li>
  )
}

function Figure({ label, value }: { label: string; value: ReactNode }) {
  return (
    <li className="flex items-center justify-between gap-3 py-2">
      <span className="text-slate-700">{label}</span>
      <span className="font-semibold tabular-nums text-slate-900">{value}</span>
    </li>
  )
}

function Sub({ children }: { children: ReactNode }) {
  return <span className="block text-xs text-slate-500">{children}</span>
}

function Muted({ children }: { children: ReactNode }) {
  return <span className="whitespace-nowrap text-xs text-slate-500">{children}</span>
}

function Calm({ children }: { children: ReactNode }) {
  return (
    <p className="flex items-center gap-2 py-2 text-slate-600">
      <CheckCircle2 className="size-4 shrink-0 text-green-600" aria-hidden />
      {children}
    </p>
  )
}

function count(value: number, tone: BadgeTone) {
  return value > 0 ? <Badge tone={tone}>{value}</Badge> : undefined
}

function CardsSkeleton() {
  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" role="status" aria-label="Memuat kartu Beranda">
      {[0, 1, 2, 3, 4, 5].map((i) => (
        <div key={i} className="space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-3 w-5/6" />
          <Skeleton className="h-3 w-2/3" />
        </div>
      ))}
    </div>
  )
}

function greeting(now = new Date()): string {
  const hour = now.getHours()
  if (hour < 11) return 'Selamat pagi'
  if (hour < 15) return 'Selamat siang'
  if (hour < 18) return 'Selamat sore'
  return 'Selamat malam'
}

function scopeText(scope: { organization_wide: boolean; location_ids: string[] } | null): string | null {
  if (!scope) return null
  if (scope.organization_wide) return scopeTypeLabel.organization
  return scope.location_ids.length ? `${scope.location_ids.length} lokasi` : null
}
