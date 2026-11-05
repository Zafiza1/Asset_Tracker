import clsx from 'clsx'
import { ArrowDown, ArrowRight, Check, CheckCircle2, ChevronDown, Clock, CornerDownRight, Minus, Repeat, Save, ShieldCheck, Undo2, XCircle, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert } from '../../components/ui/Feedback'
import { useAuth } from '../../lib/auth/context'
import { assetStatusLabel, assetStatusTone, type AssetStatus } from '../assets/api'
import { allowedTypes, manualTypes, transactionTypeLabel } from '../transactions/api'

/** The numbered steps of the main flow; the flow diagram and the step sections both read from here. */
const STEPS = [
  { title: 'Masuk ke sistem', who: 'Semua pengguna' },
  { title: 'Undang tim & atur akses', who: 'Super Admin, Supervisor' },
  { title: 'Siapkan Master Data', who: 'Super Admin, Supervisor, Admin' },
  { title: 'Daftarkan aset', who: 'Pengelola aset' },
  { title: 'Persetujuan', who: 'Super Admin, Supervisor' },
  { title: 'Pasang label barcode', who: 'Opsional' },
  { title: 'Catat transaksi aset', who: 'Pengelola aset' },
  { title: 'Pantau & buat laporan', who: 'Pimpinan, pengelola aset' },
] as const

const PHASES = [
  { title: 'A. Persiapan', note: 'Cukup sekali di awal', steps: [1, 2, 3] },
  { title: 'B. Pendataan aset', note: 'Setiap ada aset baru', steps: [4, 5, 6] },
  { title: 'C. Operasional & pemantauan', note: 'Berulang setiap hari', steps: [7, 8] },
]

const CONTENTS = [
  { id: 'alur', label: 'Alur keseluruhan' },
  { id: 'peran', label: 'Siapa mengerjakan apa' },
  ...STEPS.map((s, i) => ({ id: stepId(i + 1), label: `${i + 1}. ${s.title}` })),
  { id: 'kendala', label: 'Kendala yang sering terjadi' },
]

function stepId(n: number) {
  return `langkah-${n}`
}

/**
 * User guide (SOP): the whole flow from first login to reports, with what to do at each step and
 * where to go next. Open to every organization member; links to pages the reader's role cannot
 * open are shown as plain text, so the guide never leads to "Akses ditolak".
 */
export function GuidePage() {
  return (
    <>
      <PageHeader title="Panduan Penggunaan" description="Standar operasional Asset Tracker: apa yang dikerjakan, siapa yang mengerjakan, dan ke mana setelahnya." />
      <div className="grid gap-6 xl:grid-cols-[15rem_minmax(0,1fr)]">
        <TableOfContents />
        <div className="min-w-0 max-w-5xl space-y-6">
          <PersonalNote />
          <MainFlow />
          <RolesSection />
          <Step1 />
          <Step2 />
          <Step3 />
          <Step4 />
          <Step5 />
          <Step6 />
          <Step7 />
          <Step8 />
          <Troubleshooting />
        </div>
      </div>
    </>
  )
}

function TableOfContents() {
  return (
    <nav aria-label="Daftar isi" className="xl:sticky xl:top-20 xl:self-start">
      <p className="mb-2 text-sm font-semibold text-slate-900">Daftar isi</p>
      <ul className="flex flex-wrap gap-1.5 xl:flex-col xl:gap-0.5">
        {CONTENTS.map((c) => (
          <li key={c.id}>
            <a href={`#${c.id}`} className="block rounded-lg px-2.5 py-1.5 text-sm text-slate-600 ring-1 ring-slate-200 hover:bg-brand-50 hover:text-brand-800 xl:ring-0">
              {c.label}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}

/** How the rules apply to the reader: the one difference that changes daily work is approval. */
function PersonalNote() {
  const { can } = useAuth()
  return can('approval.manage') ? (
    <Alert tone="info" title="Anda termasuk penyetuju">
      Perubahan yang Anda buat langsung berlaku. Periksa menu <MenuLink to="/notifikasi">Notifikasi</MenuLink> secara rutin, karena perubahan pengguna lain menunggu keputusan Anda di sana.
    </Alert>
  ) : (
    <Alert tone="info" title="Perubahan Anda perlu disetujui">
      Perubahan data aset, transaksi, dan Master Data baru berlaku setelah disetujui Super Admin atau Supervisor. Ikuti statusnya di menu <MenuLink to="/notifikasi">Notifikasi</MenuLink>.
    </Alert>
  )
}

function MainFlow() {
  return (
    <section id="alur" className="scroll-mt-24">
      <Card title="Alur dari awal sampai akhir">
        <div className="space-y-3 p-5">
          <p className="text-sm text-slate-600">Ikuti urutan ini dari atas ke bawah. Klik salah satu langkah untuk membaca caranya.</p>
          {PHASES.map((phase, i) => (
            <div key={phase.title}>
              {i > 0 && <ArrowDown className="mx-auto mb-3 size-5 text-slate-400" aria-hidden />}
              <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3">
                  <h3 className="text-sm font-semibold text-slate-900">{phase.title}</h3>
                  <p className="text-xs text-slate-500">{phase.note}</p>
                </div>
                <ol className="flex flex-col gap-2 lg:flex-row">
                  {phase.steps.map((n, j) => (
                    <li key={n} className="flex flex-col items-center gap-2 lg:flex-1 lg:flex-row">
                      {j > 0 && (
                        <>
                          <ArrowDown className="size-4 shrink-0 text-slate-400 lg:hidden" aria-hidden />
                          <ArrowRight className="hidden size-4 shrink-0 text-slate-400 lg:block" aria-hidden />
                        </>
                      )}
                      <a href={`#${stepId(n)}`} className="flex w-full items-start gap-3 self-stretch rounded-lg bg-white p-3 shadow-card ring-1 ring-slate-200 transition-colors hover:bg-brand-50 hover:ring-brand-200">
                        <StepNumber n={n} />
                        <span className="min-w-0">
                          <span className="block text-sm font-semibold text-slate-900">{STEPS[n - 1].title}</span>
                          <span className="mt-0.5 block text-xs text-slate-500">{STEPS[n - 1].who}</span>
                        </span>
                      </a>
                    </li>
                  ))}
                </ol>
              </div>
            </div>
          ))}
          <div className="flex gap-3 rounded-lg bg-brand-50 p-3 text-sm text-brand-900 ring-1 ring-brand-100">
            <Repeat className="mt-0.5 size-4 shrink-0" aria-hidden />
            <p>
              <strong>Sesudah langkah 8, alurnya berputar.</strong> Ada aktivitas pada aset: kembali ke langkah 7. Ada aset baru: kembali ke langkah 4. Ada kategori atau lokasi baru: kembali ke langkah 3. Bila perubahan Anda perlu persetujuan, langkah 5 selalu mengikuti.
            </p>
          </div>
        </div>
      </Card>
    </section>
  )
}

function RolesSection() {
  const rows: { task: string; superAdmin: boolean; supervisor: boolean; admin: boolean }[] = [
    { task: 'Mengelola aset, transaksi, dan Master Data', superAdmin: true, supervisor: true, admin: true },
    { task: 'Melihat Dashboard dan Laporan Aset', superAdmin: true, supervisor: true, admin: true },
    { task: 'Menyetujui atau menolak pengajuan', superAdmin: true, supervisor: true, admin: false },
    { task: 'Perubahan sendiri langsung berlaku', superAdmin: true, supervisor: true, admin: false },
    { task: 'Mengatur role dan cakupan data pengguna', superAdmin: true, supervisor: true, admin: false },
    { task: 'Mengundang pengguna (perlu kode lisensi)', superAdmin: true, supervisor: false, admin: false },
    { task: 'Mengaktifkan Modul Barcode, melihat Audit Trail', superAdmin: true, supervisor: true, admin: false },
    { task: 'Mengelola lisensi', superAdmin: true, supervisor: false, admin: false },
  ]
  return (
    <section id="peran" className="scroll-mt-24">
      <Card title="Siapa mengerjakan apa">
        <div className="space-y-3 p-5">
          <p className="text-sm text-slate-600">Menu yang tampil di sidebar mengikuti role Anda. Tabel ini menunjukkan hak role bawaan; organisasi dapat menguranginya atau membuat role sendiri.</p>
          <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
            <table className="w-full min-w-[34rem] text-sm">
              <thead className="bg-slate-50 text-left text-slate-700">
                <tr>
                  <th scope="col" className="px-3 py-2 font-semibold">Tugas</th>
                  <th scope="col" className="px-3 py-2 text-center font-semibold">Super Admin</th>
                  <th scope="col" className="px-3 py-2 text-center font-semibold">Supervisor</th>
                  <th scope="col" className="px-3 py-2 text-center font-semibold">Admin</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r) => (
                  <tr key={r.task}>
                    <th scope="row" className="px-3 py-2 text-left font-normal text-slate-800">{r.task}</th>
                    <YesNo value={r.superAdmin} />
                    <YesNo value={r.supervisor} />
                    <YesNo value={r.admin} />
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-sm text-slate-600">
            <strong className="font-semibold text-slate-800">Super Admin</strong> adalah orang yang pertama kali mendaftarkan organisasi. Role buatan sendiri tidak dapat menyetujui pengajuan, sehingga perubahannya selalu melewati persetujuan.
          </p>
        </div>
      </Card>
    </section>
  )
}

function Step1() {
  return (
    <StepSection n={1} goal="Punya akun dan berada di organisasi yang benar." next={[
      { when: 'Anda Super Admin atau Supervisor', n: 2 },
      { when: 'Anda diberi tugas mengelola data aset', n: 3 },
    ]}>
      <div className="grid gap-3 md:grid-cols-3">
        <PathCard title="Mendaftarkan organisasi baru">
          <NumberedList items={[
            <>Di halaman masuk, pilih <Label>Daftarkan organisasi</Label>.</>,
            'Isi data organisasi dan akun Anda.',
            <>Anda otomatis menjadi <Label>Super Admin</Label>.</>,
          ]} />
        </PathCard>
        <PathCard title="Diundang lewat email">
          <NumberedList items={[
            'Buka tautan undangan di email.',
            'Belum punya akun: isi nama, password, dan kode lisensi yang tertera di email undangan.',
            <>Sudah punya akun: masuk, lalu pilih <Label>Terima undangan</Label>.</>,
          ]} />
        </PathCard>
        <PathCard title="Sudah punya akun">
          <NumberedList items={[
            'Masuk dengan email dan password.',
            'Bila bergabung di beberapa organisasi, pilih salah satu.',
            'Bila memakai password sementara, ganti dahulu.',
          ]} />
        </PathCard>
      </div>
      <Tip>Nama organisasi yang aktif tampil di bagian atas halaman. Klik nama itu untuk berpindah organisasi. Password dapat diganti di menu <MenuLink to="/ubah-password">Ubah Password</MenuLink>.</Tip>
    </StepSection>
  )
}

function Step2() {
  return (
    <StepSection n={2} goal="Setiap anggota tim mendapat akun dengan hak akses yang sesuai tugasnya." next={[{ n: 3 }]}>
      <NumberedList items={[
        <>Buka <MenuLink to="/pengguna/baru" permission="user.create">Pengguna → Undang pengguna</MenuLink>.</>,
        'Isi email, pilih kode lisensi, lalu pilih role. Satu pengguna memakai satu kode lisensi, dan daftar kodenya hanya terbuka bagi Super Admin.',
        <>Klik <Label>Kirim undangan</Label>. Pengguna baru mendapat akses setelah menerima undangan itu.</>,
        <>Pantau statusnya di tab <MenuLink to="/pengguna/undangan" permission="user.view">Undangan</MenuLink>. Bila kedaluwarsa, pilih <Label>Kirim ulang</Label>; bila keliru, pilih <Label>Cabut</Label>.</>,
        <>Setelah pengguna bergabung, buka <MenuLink to="/pengguna" permission="user.view">detail penggunanya</MenuLink> untuk mengubah role atau mempersempit <Label>Cakupan data</Label>.</>,
      ]} />
      <Tip>
        <strong className="font-semibold">Cakupan data</strong> menentukan aset mana yang terlihat. Pengguna yang diundang awalnya mencakup seluruh organisasi. Bila dibatasi ke satu lokasi, pengguna melihat aset di lokasi itu dan sub-lokasinya.
      </Tip>
    </StepSection>
  )
}

function Step3() {
  return (
    <StepSection n={3} goal="Pilihan kategori dan lokasi sudah tersedia saat aset didaftarkan." next={[{ n: 4 }]}>
      <NumberedList items={[
        <>Buka <MenuLink to="/master/kategori" permission="master.tenant.view">Kategori Aset</MenuLink> dan buat kategori utama, misalnya Perangkat IT atau Kendaraan. Isi <Label>Kategori induk</Label> untuk membuat subkategori.</>,
        <>Klik kolom <Label>Atribut</Label> pada kategori untuk mengatur isian tambahan. Atribut bawaan seperti Merek, Model, dan Kondisi sudah tersedia; tambahkan yang lain dengan <Label>Tambah atribut kustom</Label>, misalnya Nomor Polisi.</>,
        <>Buka <MenuLink to="/master/lokasi" permission="master.tenant.view">Lokasi</MenuLink> dan buat tempat penyimpanan aset. Isi <Label>Lokasi induk</Label> untuk membuat susunan seperti Gedung → Lantai → Ruang.</>,
      ]} />
      <Tip>
        Kategori wajib ada sebelum aset didaftarkan. Lokasi boleh menyusul, tetapi aset tanpa lokasi hanya terlihat oleh pengguna dengan cakupan seluruh organisasi. Bila datanya banyak, gunakan <Label>Import</Label> dengan template Excel.
      </Tip>
    </StepSection>
  )
}

function Step4() {
  return (
    <StepSection n={4} goal="Setiap aset fisik tercatat dan punya kode unik." next={[
      { when: 'Anda bukan penyetuju', n: 5 },
      { when: 'Anda Super Admin atau Supervisor, dan aset perlu label', n: 6 },
      { when: 'Anda Super Admin atau Supervisor, dan aset tidak perlu label', n: 7 },
    ]}>
      <NumberedList items={[
        <>Buka <MenuLink to="/aset" permission="asset.view">Pendataan Aset</MenuLink>, lalu klik <Label>Tambah aset</Label>.</>,
        <>Isi <Label>Nama aset</Label>, <Label>No Seri</Label> (harus unik), dan <Label>Kategori</Label>. Isian atribut tampil sesuai kategori yang dipilih.</>,
        <>Pilih <Label>Lokasi</Label> dan <Label>Status</Label> awal, lalu lengkapi tanggal dan nilai perolehan bila ada.</>,
        <>Klik <Label>Simpan</Label>. Kode aset dibuat otomatis dan riwayat <Label>Penambahan aset</Label> tercatat dengan sendirinya.</>,
      ]} />
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Mendaftarkan banyak aset">
          <p>Klik <Label>Import</Label>, unduh template, isi di Excel, lalu unggah. Bila ada satu baris yang salah, tidak ada data yang disimpan. Perbaiki lalu unggah ulang.</p>
        </PathCard>
        <PathCard title="Mengubah banyak aset">
          <p>Klik <Label>Multiple Edit</Label>, unduh data, ubah di Excel, lalu unggah. Hanya baris yang berubah yang disimpan.</p>
        </PathCard>
      </div>
      <Tip>Bila aset baru Anda perlu disetujui, gambar belum ikut tersimpan. Tambahkan gambar lewat tombol <Label>Ubah</Label> setelah aset disetujui.</Tip>
    </StepSection>
  )
}

function Step5() {
  return (
    <StepSection n={5} goal="Perubahan data diperiksa oleh Super Admin atau Supervisor sebelum berlaku." next={[
      { when: 'Pengajuan aset baru disetujui dan aset perlu label', n: 6 },
      { when: 'Pengajuan lain disetujui', n: 7 },
    ]}>
      <p className="text-sm text-slate-700">Langkah ini berlaku untuk semua perubahan pada transaksi, aset, dan Master Data, bukan hanya aset baru.</p>
      <ApprovalFlow />
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Bila Anda mengajukan">
          <NumberedList items={[
            <>Setelah menyimpan, muncul pesan <Label>Menunggu persetujuan</Label>. Data belum berubah.</>,
            <>Ikuti statusnya di <MenuLink to="/notifikasi">Notifikasi</MenuLink>.</>,
            <>Keliru? Buka pengajuannya dan pilih <Label>Batalkan pengajuan</Label> selama masih menunggu.</>,
            'Ditolak? Baca catatannya, perbaiki, lalu ajukan lagi dari menu asalnya.',
          ]} />
        </PathCard>
        <PathCard title="Bila Anda menyetujui">
          <NumberedList items={[
            <>Buka <MenuLink to="/notifikasi">Notifikasi</MenuLink>. Angka merah menunjukkan jumlah pengajuan yang menunggu.</>,
            'Klik pengajuan untuk membaca rinciannya.',
            <>Isi catatan bila perlu, lalu pilih <Label>Setujui</Label> atau <Label>Tolak</Label>.</>,
          ]} />
        </PathCard>
      </div>
      <Tip>Muncul pesan &ldquo;Pengajuan tidak dapat diterapkan&rdquo;? Artinya data sudah berubah sejak diajukan, misalnya aset yang sama sudah dipindahkan. Pengajuan tetap menunggu dan dapat ditolak, lalu diajukan ulang.</Tip>
    </StepSection>
  )
}

/** Save → who is saving → the three ways a submitted change can end. */
function ApprovalFlow() {
  return (
    <figure className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
      <figcaption className="sr-only">Alur persetujuan perubahan data</figcaption>
      <div className="flex flex-col items-center gap-2">
        <FlowBox icon={Save} title="Anda menyimpan perubahan" />
        <ArrowDown className="size-4 text-slate-400" aria-hidden />
        <FlowBox icon={ShieldCheck} title="Apakah Anda Super Admin atau Supervisor?" />
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <div>
          <p className="mb-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">Ya</p>
          <FlowBox icon={CheckCircle2} tone="green" title="Langsung berlaku" text="Tidak perlu persetujuan." />
        </div>
        <div>
          <p className="mb-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">Tidak</p>
          <FlowBox icon={Clock} tone="amber" title="Pengajuan berstatus Menunggu" text="Isian yang salah langsung ditolak sistem, sehingga dapat Anda perbaiki saat itu juga." />
          <ArrowDown className="mx-auto my-2 size-4 text-slate-400" aria-hidden />
          <div className="grid gap-2 sm:grid-cols-3">
            <FlowBox icon={CheckCircle2} tone="green" title="Disetujui" text="Perubahan diterapkan." />
            <FlowBox icon={XCircle} tone="red" title="Ditolak" text="Tidak ada yang berubah." />
            <FlowBox icon={Undo2} title="Dibatalkan" text="Ditarik oleh pengaju." />
          </div>
        </div>
      </div>
    </figure>
  )
}

const FLOW_TONES = {
  slate: 'bg-white ring-slate-200 [&_svg]:text-slate-500',
  green: 'bg-green-50 ring-green-200 [&_svg]:text-green-600',
  amber: 'bg-amber-50 ring-amber-200 [&_svg]:text-amber-600',
  red: 'bg-red-50 ring-red-200 [&_svg]:text-red-600',
}

function FlowBox({ icon: Icon, title, text, tone = 'slate' }: { icon: LucideIcon; title: string; text?: string; tone?: keyof typeof FLOW_TONES }) {
  return (
    <div className={clsx('flex w-full gap-2.5 rounded-lg p-3 text-sm ring-1', FLOW_TONES[tone], !text && 'justify-center sm:w-auto')}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div className="min-w-0">
        <p className="font-semibold text-slate-900">{title}</p>
        {text && <p className="mt-0.5 text-slate-600">{text}</p>}
      </div>
    </div>
  )
}

function Step6() {
  return (
    <StepSection n={6} goal="Aset dapat dikenali cepat dengan memindai label. Lewati langkah ini bila organisasi tidak memakai barcode." next={[{ n: 7 }]}>
      <NumberedList items={[
        <>Super Admin atau Supervisor mengaktifkan <MenuLink to="/modul/barcode" permission="settings.manage">Modul Barcode</MenuLink>. Cukup sekali.</>,
        <>Pada setiap aset yang perlu label, klik <Label>Ubah</Label> lalu atur isian <Label>Barcode</Label> menjadi <Label>Ya</Label>.</>,
        <>Di <MenuLink to="/aset" permission="asset.view">Pendataan Aset</MenuLink>, klik <Label>Print</Label> pada aset tersebut, lalu cetak Barcode 1D, QR Code, atau keduanya.</>,
        'Tempel label pada aset fisik.',
      ]} />
      <PathCard title="Memindai dengan aplikasi mobile">
        <p>Masuk ke aplikasi Asset Tracker Scanner dengan email dan password yang sama, lalu tekan <Label>Scan Aset</Label> dan arahkan kamera ke label. Detail dan riwayat aset langsung tampil. Bila label rusak, ketik nomor asetnya. Aplikasi ini hanya untuk melihat data; pencatatan tetap dilakukan di web.</p>
      </PathCard>
    </StepSection>
  )
}

const TRANSACTION_GUIDE = [
  { type: 'transfer', when: 'Aset pindah tempat', fill: 'Lokasi tujuan', effect: 'Lokasi aset berubah' },
  { type: 'status_change', when: 'Kondisi pemakaian berubah, misalnya masuk perbaikan', fill: 'Status baru', effect: 'Status aset berubah' },
  { type: 'loan', when: 'Aset dipinjamkan', fill: 'Nama peminjam, rencana tanggal kembali', effect: 'Status menjadi Dipinjam' },
  { type: 'return', when: 'Aset pinjaman kembali', fill: 'Lokasi pengembalian bila berbeda', effect: 'Status menjadi Tersedia' },
  { type: 'note', when: 'Kegiatan lain, misalnya servis rutin', fill: 'Keterangan aktivitas', effect: 'Hanya menambah riwayat' },
] as const

function Step7() {
  const statuses = Object.keys(assetStatusLabel) as AssetStatus[]
  return (
    <StepSection n={7} goal="Setiap perpindahan, peminjaman, dan perubahan aset punya riwayat yang lengkap." next={[
      { when: 'Anda bukan penyetuju', n: 5 },
      { when: 'Transaksi sudah berlaku', n: 8 },
    ]}>
      <NumberedList items={[
        <>Buka <MenuLink to="/transaksi" permission="transaction.view">Transaksi Aset</MenuLink>, lalu klik <Label>Catat transaksi</Label>. Untuk satu aset, Anda juga dapat memulai dari halaman detail asetnya.</>,
        'Pilih jenis transaksi, lalu isi data yang diminta.',
        'Isi tanggal transaksi. Tanggal boleh mundur, tetapi tidak boleh di masa depan.',
        'Centang aset yang terlibat. Satu transaksi dapat mencakup banyak aset.',
        <>Klik <Label>Simpan transaksi</Label>.</>,
      ]} />

      <h3 className="pt-1 text-sm font-semibold text-slate-900">Jenis transaksi yang dipilih</h3>
      <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
        <table className="w-full min-w-[40rem] text-sm">
          <thead className="bg-slate-50 text-left text-slate-700">
            <tr>
              <th scope="col" className="px-3 py-2 font-semibold">Jenis</th>
              <th scope="col" className="px-3 py-2 font-semibold">Gunakan saat</th>
              <th scope="col" className="px-3 py-2 font-semibold">Yang diisi</th>
              <th scope="col" className="px-3 py-2 font-semibold">Hasilnya</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 text-slate-700">
            {TRANSACTION_GUIDE.map((t) => (
              <tr key={t.type}>
                <th scope="row" className="whitespace-nowrap px-3 py-2 text-left font-medium text-slate-900">{transactionTypeLabel[t.type]}</th>
                <td className="px-3 py-2">{t.when}</td>
                <td className="px-3 py-2">{t.fill}</td>
                <td className="px-3 py-2">{t.effect}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h3 className="pt-1 text-sm font-semibold text-slate-900">Transaksi yang dapat dipilih menurut status aset</h3>
      {/* Read from the same rules as the transaction form, so this table cannot drift from it. */}
      <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
        <table className="w-full min-w-[40rem] text-sm">
          <thead className="bg-slate-50 text-slate-700">
            <tr>
              <th scope="col" className="px-3 py-2 text-left font-semibold">Status aset</th>
              {manualTypes.map((t) => (
                <th key={t} scope="col" className="px-3 py-2 text-center font-semibold">{transactionTypeLabel[t]}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {statuses.map((s) => (
              <tr key={s}>
                <th scope="row" className="px-3 py-2 text-left font-normal">
                  <Badge tone={assetStatusTone[s]}>{assetStatusLabel[s]}</Badge>
                </th>
                {manualTypes.map((t) => (
                  <YesNo key={t} value={allowedTypes(s).includes(t)} />
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Tip>
        <strong className="font-semibold">Lokasi dan status hanya berubah lewat transaksi</strong>, tidak lewat tombol Ubah, agar riwayatnya tercatat. Transaksi yang sudah berlaku tidak dapat diubah atau dihapus; bila keliru, perbaiki dengan transaksi baru.
      </Tip>
    </StepSection>
  )
}

function Step8() {
  return (
    <StepSection n={8} goal="Kondisi aset terbaca sekilas, dan laporan siap dibagikan." next={[
      { when: 'Ada aktivitas baru pada aset', n: 7 },
      { when: 'Ada aset baru', n: 4 },
      { when: 'Ada kategori atau lokasi baru', n: 3 },
    ]}>
      <div className="grid gap-3 md:grid-cols-3">
        <PathCard title={<MenuLink to="/" permission="report.view">Dashboard</MenuLink>}>
          <p>Ringkasan jumlah, nilai, status, dan lokasi aset. Gunakan <Label>Atur tata letak</Label> untuk menyusun analisis yang ingin ditampilkan.</p>
        </PathCard>
        <PathCard title={<MenuLink to="/laporan" permission="report.view">Laporan Aset</MenuLink>}>
          <p>Saring aset yang diperlukan, lalu unduh dengan <Label>Export Excel</Label> atau <Label>Export PDF</Label>. Isi file mengikuti filter yang dipilih.</p>
        </PathCard>
        <PathCard title={<MenuLink to="/audit" permission="audit.view">Audit Trail</MenuLink>}>
          <p>Jejak aktivitas di organisasi: siapa melakukan apa dan kapan. Gunakan saat perlu menelusuri suatu perubahan.</p>
        </PathCard>
      </div>
      <Tip>Aset yang tidak dipakai lagi cukup dinonaktifkan lewat tombol <Label>Nonaktifkan</Label> di detail aset. Aset tidak terhapus: nomor dan riwayatnya tetap tersimpan, dan aset dapat diaktifkan kembali.</Tip>
    </StepSection>
  )
}

const PROBLEMS: { q: string; a: ReactNode }[] = [
  { q: 'Menu yang saya cari tidak ada, atau muncul "Akses ditolak".', a: 'Role Anda belum memiliki izin untuk menu itu. Hubungi Super Admin atau Supervisor organisasi Anda.' },
  { q: 'Perubahan yang saya simpan belum terlihat.', a: <>Kemungkinan masih menunggu persetujuan. Periksa statusnya di <MenuLink to="/notifikasi">Notifikasi</MenuLink>.</> },
  { q: 'Saya tidak menemukan aset tertentu.', a: 'Aset itu mungkin berada di luar cakupan data Anda, atau sudah nonaktif. Untuk melihat aset nonaktif, ubah filter di Pendataan Aset menjadi "Aset nonaktif".' },
  { q: 'Aset tidak dapat dicentang saat mencatat transaksi.', a: 'Status aset tidak mengizinkan jenis transaksi itu, misalnya aset yang sedang dipinjam tidak dapat dipindahkan. Lihat tabel status pada langkah 7.' },
  { q: 'Saya salah mencatat transaksi.', a: 'Transaksi tidak dapat diubah atau dihapus. Catat transaksi baru untuk mengembalikan data ke keadaan yang benar. Bila transaksi masih menunggu persetujuan, batalkan saja pengajuannya.' },
  { q: 'Tombol cetak barcode tidak muncul.', a: 'Pastikan Modul Barcode sudah aktif dan isian Barcode pada aset itu bernilai Ya.' },
  { q: 'Kategori atau lokasi tidak dapat diarsipkan.', a: 'Kategori atau lokasi itu masih dipakai aset aktif. Pindahkan atau ubah asetnya terlebih dahulu.' },
  { q: 'Import Excel gagal.', a: 'Baca daftar baris yang salah pada pesan error, perbaiki file, lalu unggah ulang. Selama masih ada baris yang salah, tidak ada data yang disimpan.' },
  { q: 'Saya lupa password.', a: 'Minta Super Admin atau Supervisor mengatur password sementara dari halaman detail pengguna. Anda akan diminta menggantinya saat masuk.' },
  { q: 'Tautan undangan tidak berlaku.', a: 'Undangan punya batas waktu dan hanya dapat dipakai sekali. Minta yang mengundang memilih Kirim ulang. Bila diminta verifikasi email, selesaikan dahulu lalu buka kembali tautannya.' },
  { q: 'Pilihan kode lisensi kosong saat mengundang.', a: 'Daftar kode lisensi hanya terbuka bagi Super Admin, jadi minta Super Admin yang mengirim undangan. Bila Super Admin pun melihatnya kosong, periksa sisa lisensi di Pengaturan Lisensi, lalu cabut undangan yang tidak diperlukan atau hubungi pengelola aplikasi untuk menambah lisensi.' },
]

function Troubleshooting() {
  return (
    <section id="kendala" className="scroll-mt-24">
      <Card title="Kendala yang sering terjadi">
        <div className="divide-y divide-slate-100">
          {PROBLEMS.map((p) => (
            <details key={p.q} className="group">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-sm font-medium text-slate-900 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                {p.q}
                <ChevronDown className="size-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" aria-hidden />
              </summary>
              <p className="px-5 pb-4 text-sm text-slate-600">{p.a}</p>
            </details>
          ))}
        </div>
      </Card>
    </section>
  )
}

/** One numbered step: what it is for, who does it, how, and where to go afterwards. */
function StepSection({ n, goal, next, children }: { n: number; goal: string; next: { n: number; when?: string }[]; children: ReactNode }) {
  const step = STEPS[n - 1]
  return (
    <section id={stepId(n)} aria-labelledby={`${stepId(n)}-title`} className="scroll-mt-24 rounded-xl bg-white shadow-card ring-1 ring-slate-200/80">
      <header className="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
        <StepNumber n={n} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 id={`${stepId(n)}-title`} className="text-base font-semibold text-slate-900">{step.title}</h2>
            <Badge tone="slate">{step.who}</Badge>
          </div>
          <p className="mt-1 text-sm text-slate-600">{goal}</p>
        </div>
      </header>
      <div className="space-y-4 p-5">{children}</div>
      <footer className="rounded-b-xl border-t border-slate-100 bg-slate-50 px-5 py-3">
        <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Langkah berikutnya</p>
        <ul className="space-y-1">
          {next.map((item) => (
            <li key={item.n} className="flex items-start gap-2 text-sm text-slate-700">
              <CornerDownRight className="mt-0.5 size-4 shrink-0 text-slate-400" aria-hidden />
              <span>
                {item.when && `${item.when}: `}
                <a href={`#${stepId(item.n)}`} className="font-semibold text-brand-700 hover:underline">
                  Langkah {item.n}, {STEPS[item.n - 1].title}
                </a>
              </span>
            </li>
          ))}
        </ul>
      </footer>
    </section>
  )
}

function StepNumber({ n }: { n: number }) {
  return (
    <span className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-700 text-xs font-semibold text-white" aria-hidden>
      {n}
    </span>
  )
}

function NumberedList({ items }: { items: ReactNode[] }) {
  return (
    <ol className="list-decimal space-y-1.5 pl-5 text-sm text-slate-700 marker:font-semibold marker:text-slate-500">
      {items.map((item, i) => (
        <li key={i}>{item}</li>
      ))}
    </ol>
  )
}

function PathCard({ title, children }: { title: ReactNode; children: ReactNode }) {
  return (
    <div className="rounded-lg p-4 text-sm text-slate-700 ring-1 ring-slate-200">
      <h3 className="mb-2 font-semibold text-slate-900">{title}</h3>
      {children}
    </div>
  )
}

function Tip({ children }: { children: ReactNode }) {
  return <p className="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-950 ring-1 ring-amber-200">{children}</p>
}

/** The name of a button or field on screen. */
function Label({ children }: { children: ReactNode }) {
  return <span className="font-semibold text-slate-900">{children}</span>
}

/** A link to a page, or plain text when the reader's role cannot open that page. */
function MenuLink({ to, permission, children }: { to: string; permission?: string; children: ReactNode }) {
  const { can } = useAuth()
  if (permission && !can(permission)) return <span className="font-semibold">{children}</span>
  return (
    <Link to={to} className="font-semibold text-brand-700 underline decoration-brand-200 underline-offset-2 hover:decoration-brand-700">
      {children}
    </Link>
  )
}

function YesNo({ value }: { value: boolean }) {
  return (
    <td className="px-3 py-2 text-center">
      {value ? <Check className="mx-auto size-4 text-green-600" aria-hidden /> : <Minus className="mx-auto size-4 text-slate-300" aria-hidden />}
      <span className="sr-only">{value ? 'Ya' : 'Tidak'}</span>
    </td>
  )
}
