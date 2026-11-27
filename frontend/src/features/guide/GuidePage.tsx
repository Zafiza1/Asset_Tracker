import clsx from 'clsx'
import { ArrowDown, ArrowRight, Check, CheckCircle2, ChevronDown, Clock, CornerDownRight, Minus, Repeat, Save, ShieldCheck, Undo2, XCircle, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert } from '../../components/ui/Feedback'
import { useAuth } from '../../lib/auth/context'

/** The numbered steps of the main flow; the flow diagram and the step sections both read from here. */
const STEPS = [
  { title: 'Masuk ke sistem', who: 'Semua pengguna' },
  { title: 'Siapkan Master Data', who: 'Super Admin, Supervisor, Admin' },
  { title: 'Undang tim & atur akses', who: 'Super Admin, Supervisor' },
  { title: 'Daftarkan aset', who: 'Pengelola aset' },
  { title: 'Persetujuan', who: 'Super Admin, Supervisor' },
  { title: 'Pasang label barcode', who: 'Opsional' },
  { title: 'Kirim & jemput tabung', who: 'Admin, supir, kernet' },
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
      Perubahan yang Anda buat langsung berlaku. Periksa menu <MenuLink to="/notifikasi">Persetujuan</MenuLink> secara rutin: perubahan data dari pengguna lain dan tahapan transaksi yang menunggu keputusan Anda ada di tab Menunggu, dalam satu daftar.
    </Alert>
  ) : (
    <Alert tone="info" title="Perubahan Anda perlu disetujui">
      Perubahan data aset dan Master Data baru berlaku setelah disetujui Super Admin atau Supervisor. Lihat hasilnya (disetujui atau ditolak) di menu <MenuLink to="/notifikasi">Pengajuan Saya</MenuLink>.
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
              <strong>Sesudah langkah 8, alurnya berputar.</strong> Ada tabung yang dikirim atau dijemput: kembali ke langkah 7. Ada tabung baru: kembali ke langkah 4. Ada karyawan, kendaraan, atau customer baru: kembali ke langkah 2, dan bila karyawan itu perlu akun, lanjut ke langkah 3. Bila perubahan Anda perlu persetujuan, langkah 5 selalu mengikuti.
            </p>
          </div>
        </div>
      </Card>
    </section>
  )
}

function RolesSection() {
  const rows: { task: string; superAdmin: boolean; supervisor: boolean; admin: boolean }[] = [
    { task: 'Mengelola tabung, transaksi masuk/keluar, dan Master Data', superAdmin: true, supervisor: true, admin: true },
    { task: 'Melihat Dashboard dan Laporan Aset', superAdmin: true, supervisor: true, admin: true },
    { task: 'Menyetujui atau menolak pengajuan', superAdmin: true, supervisor: true, admin: false },
    { task: 'Perubahan sendiri langsung berlaku', superAdmin: true, supervisor: true, admin: false },
    { task: 'Mengatur role dan cakupan data pengguna', superAdmin: true, supervisor: true, admin: false },
    { task: 'Mengundang pengguna', superAdmin: true, supervisor: false, admin: false },
    { task: 'Mengaktifkan Modul Barcode, melihat Audit Trail', superAdmin: true, supervisor: true, admin: false },
  ]
  return (
    <section id="peran" className="scroll-mt-24">
      <Card title="Siapa mengerjakan apa">
        <div className="space-y-3 p-5">
          <p className="text-sm text-slate-600">Menu yang tampil di sidebar mengikuti role Anda. Tabel ini menunjukkan hak role bawaan; business unit dapat menguranginya atau membuat role sendiri.</p>
          <div className="relative overflow-x-auto overscroll-x-contain rounded-lg ring-1 ring-slate-200">
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
            Supir dan kernet memakai role <strong className="font-semibold text-slate-800">Supir / Kernet</strong>: hanya scan muat/bongkar dan lapor perjalanan di mobile. <strong className="font-semibold text-slate-800">Super Admin</strong> adalah orang yang pertama kali mendaftarkan business unit. Role buatan sendiri tidak dapat menyetujui pengajuan, sehingga perubahannya selalu melewati persetujuan.
          </p>
        </div>
      </Card>
    </section>
  )
}

function Step1() {
  return (
    <StepSection n={1} goal="Punya akun dan berada di business unit yang benar." next={[
      { when: 'Anda Super Admin, Supervisor, atau Admin', n: 2 },
      { when: 'Anda supir atau kernet', n: 7 },
    ]}>
      <div className="grid gap-3 md:grid-cols-3">
        <PathCard title="Mendaftarkan business unit baru">
          <NumberedList items={[
            <>Di halaman masuk, pilih <Label>Daftarkan business unit</Label>.</>,
            'Isi data business unit dan akun Anda.',
            <>Anda otomatis menjadi <Label>Super Admin</Label>.</>,
          ]} />
        </PathCard>
        <PathCard title="Diundang lewat email">
          <NumberedList items={[
            'Buka tautan undangan di email.',
            'Belum punya akun: nama terisi dari data karyawan, cukup buat password.',
            <>Sudah punya akun: masuk, lalu pilih <Label>Terima undangan</Label>.</>,
          ]} />
        </PathCard>
        <PathCard title="Sudah punya akun">
          <NumberedList items={[
            'Masuk dengan email dan password.',
            'Bila bergabung di beberapa business unit, pilih salah satu.',
            'Bila memakai password sementara, ganti dahulu.',
          ]} />
        </PathCard>
      </div>
      <Tip>Nama business unit yang aktif tampil di bagian atas halaman. Klik nama itu untuk berpindah antara PT SIG dan PT CDO; datanya terpisah, dan Anda hanya melihat business unit yang memberi Anda akses. Password dapat diganti lewat menu pengguna di pojok kanan atas, pilih <MenuLink to="/ubah-password">Ubah password</MenuLink>.</Tip>
    </StepSection>
  )
}

function Step2() {
  return (
    <StepSection n={2} goal="Kategori, divisi, karyawan, kendaraan, dan customer sudah siap dipilih." next={[
      { when: 'Anda Super Admin dan perlu memberi akun ke karyawan', n: 3 },
      { when: 'Data sudah siap', n: 4 },
    ]}>
      <p className="text-sm text-slate-700">Kerjakan berurutan, karena data di bawah memakai data di atasnya. Semua menu ini ada di sidebar bagian <Label>Master Data</Label>.</p>
      <NumberedList items={[
        <><MenuLink to="/master/kategori" permission="master.tenant.view">Kategori Aset</MenuLink>: kategori <Label>Aset Tabung</Label> sudah tersedia. Klik kolom <Label>Atribut</Label> untuk mengubah isian atau pilihan dropdown-nya, misalnya jenis gas.</>,
        <><MenuLink to="/master/divisi" permission="master.tenant.view">Divisi</MenuLink>: divisi adalah jabatan karyawan. Divisi bernama <Label>Supir</Label> dan <Label>Kernet</Label> otomatis menjadi kru pengiriman. Kode divisi dibuat sistem.</>,
        <><MenuLink to="/master/karyawan" permission="master.tenant.view">Karyawan</MenuLink>: daftarkan karyawan beserta divisinya. No SIM hanya diisi untuk supir dan kernet. Kode karyawan dibuat sistem.</>,
        <><MenuLink to="/master/kendaraan" permission="master.tenant.view">Kendaraan</MenuLink>: daftarkan truk dan pick up beserta <Label>Nomor Polisi</Label>. Status <Label>Tersedia</Label> atau <Label>Sedang Digunakan</Label> diatur sistem dari transaksi.</>,
        <><MenuLink to="/master/customer" permission="master.tenant.view">Customer</MenuLink>: daftarkan tujuan pengiriman dan tempat penjemputan tabung.</>,
      ]} />
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Menambah satu per satu atau sekaligus">
          <p>Klik tombol <Label>Tambah</Label> di tiap menu, lalu pilih <Label>Isi formulir</Label> atau <Label>Import dari Excel</Label>. Untuk mengubah banyak data sekaligus, pakai <Label>Multiple Edit</Label>.</p>
        </PathCard>
        <PathCard title="Data yang tidak dipakai lagi">
          <p>Kategori, karyawan, kendaraan, dan customer tidak dihapus atau diarsipkan, agar riwayat transaksi tetap utuh. Karyawan yang keluar atau customer yang tidak dilayani lagi cukup dinonaktifkan.</p>
        </PathCard>
      </div>
    </StepSection>
  )
}

function Step3() {
  return (
    <StepSection n={3} goal="Setiap anggota tim mendapat akun dengan hak akses yang sesuai tugasnya." next={[{ n: 4 }]}>
      <NumberedList items={[
        <>Super Admin membuka <MenuLink to="/pengguna/baru" permission="user.create">Pengguna → Undang pengguna</MenuLink>. Hanya Super Admin yang dapat mengundang.</>,
        <>Ketik nama atau kode di <Label>Nama karyawan</Label>, lalu pilih orangnya. Karyawan harus sudah terdaftar di langkah 2; jabatannya diambil dari divisinya.</>,
        'Isi email, lalu pilih business unit dan role-nya. Satu pengguna bisa diberi akses ke PT SIG, PT CDO, atau keduanya. Supir dan kernet diberi role Supir / Kernet.',
        <>Klik <Label>Kirim undangan</Label>. Pengguna baru mendapat akses setelah menerima undangan itu.</>,
        <>Pantau statusnya di tab <MenuLink to="/pengguna/undangan" permission="user.view">Undangan</MenuLink>. Bila kedaluwarsa, Super Admin memilih <Label>Kirim ulang</Label>; bila keliru, memilih <Label>Cabut</Label>.</>,
        <>Setelah pengguna bergabung, Super Admin atau Supervisor membuka <MenuLink to="/pengguna" permission="user.view">detail penggunanya</MenuLink> untuk mengubah role atau mempersempit <Label>Cakupan data</Label>.</>,
      ]} />
      <Tip>
        <strong className="font-semibold">Cakupan data</strong> menentukan aset mana yang terlihat. Pengguna yang diundang awalnya mencakup seluruh business unit. Bila dibatasi ke satu divisi, pengguna hanya melihat aset divisi itu.
      </Tip>
    </StepSection>
  )
}

function Step4() {
  return (
    <StepSection n={4} goal="Setiap tabung fisik tercatat dan punya nomor unik." next={[
      { when: 'Anda bukan penyetuju', n: 5 },
      { when: 'Anda Super Admin atau Supervisor, dan aset perlu label', n: 6 },
      { when: 'Anda Super Admin atau Supervisor, dan aset tidak perlu label', n: 7 },
    ]}>
      <NumberedList items={[
        <>Buka <MenuLink to="/aset" permission="asset.view">Data Aset</MenuLink>, lalu klik <Label>Tambah aset</Label> → <Label>Isi formulir</Label>.</>,
        <>Pilih kategori <Label>Aset Tabung</Label>, lalu isi <Label>Nama Produk</Label>, <Label>No Manufaktur</Label> (harus unik), jenis dan grade gas, volume, dan isian lain bertanda bintang. <Label>Business Unit</Label> terisi otomatis, dan status tabung diatur sistem dari transaksi.</>,
        <>Klik <Label>Simpan</Label>. <Label>No Reference</Label> dibuat oleh sistem, dan riwayat <Label>Penambahan aset</Label> tercatat dengan sendirinya.</>,
      ]} />
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Mendaftarkan banyak aset">
          <p>Klik <Label>Tambah aset</Label> → <Label>Import dari Excel</Label>, unduh template, isi di Excel, lalu unggah. Bila ada satu baris yang salah, tidak ada data yang disimpan. Perbaiki lalu unggah ulang.</p>
        </PathCard>
        <PathCard title="Mengubah banyak aset">
          <p>Klik <Label>Multiple Edit</Label>, unduh data, ubah di Excel, lalu unggah. Hanya baris yang berubah yang disimpan. File Multiple Edit dan template Import tidak bisa tertukar: file yang diunggah ke menu yang salah akan ditolak.</p>
        </PathCard>
      </div>
      <Tip>Kendaraan tidak didaftarkan di Data Aset, melainkan di <MenuLink to="/master/kendaraan" permission="master.tenant.view">Master Data › Kendaraan</MenuLink> (langkah 2). Bila aset baru Anda perlu disetujui, gambar belum ikut tersimpan; tambahkan lewat tombol <Label>Ubah</Label> setelah aset disetujui.</Tip>
    </StepSection>
  )
}

function Step5() {
  return (
    <StepSection n={5} goal="Perubahan data diperiksa oleh Super Admin atau Supervisor sebelum berlaku." next={[
      { when: 'Pengajuan aset baru disetujui dan aset perlu label', n: 6 },
      { when: 'Pengajuan lain disetujui', n: 7 },
    ]}>
      <p className="text-sm text-slate-700">Langkah ini berlaku untuk semua perubahan pada data aset dan Master Data, bukan hanya aset baru. Transaksi masuk dan keluar tidak memerlukan persetujuan.</p>
      <ApprovalFlow />
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Bila Anda mengajukan">
          <NumberedList items={[
            <>Setelah menyimpan, muncul pesan <Label>Menunggu persetujuan</Label>. Data belum berubah.</>,
            <>Lihat hasilnya di menu <MenuLink to="/notifikasi">Pengajuan Saya</MenuLink>.</>,
            <>Keliru? Buka pengajuannya dan pilih <Label>Batalkan pengajuan</Label> selama masih menunggu.</>,
            'Ditolak? Baca catatannya, perbaiki, lalu ajukan lagi dari menu asalnya.',
          ]} />
        </PathCard>
        <PathCard title="Bila Anda menyetujui">
          <NumberedList items={[
            <>Buka <MenuLink to="/notifikasi">Persetujuan</MenuLink>. Angka merah menunjukkan jumlah pengajuan yang menunggu.</>,
            'Klik pengajuan untuk membaca rinciannya.',
            <>Isi catatan bila perlu, lalu pilih <Label>Setujui</Label> atau <Label>Tolak</Label>.</>,
          ]} />
        </PathCard>
      </div>
      <Tip>Muncul pesan &ldquo;Pengajuan tidak dapat diterapkan&rdquo;? Artinya data sudah berubah sejak diajukan, misalnya aset yang sama sudah diubah orang lain. Pengajuan tetap menunggu dan dapat ditolak, lalu diajukan ulang.</Tip>
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
    <StepSection n={6} goal="Tabung dapat di-scan saat muat dan bongkar." next={[{ n: 7 }]}>
      <NumberedList items={[
        <>Super Admin atau Supervisor mengaktifkan <MenuLink to="/modul/barcode" permission="settings.manage">Modul Barcode</MenuLink>. Cukup sekali.</>,
        <>Di halaman yang sama, centang kategori aset yang perlu label pada daftar <Label>Kategori aset yang memakai Modul Barcode</Label>; subkategorinya ikut. Modul yang dipakai aset tampil sebagai <Label>Modul</Label> di bagian Informasi Dasar detail aset.</>,
        <>Di <MenuLink to="/aset" permission="asset.view">Data Aset</MenuLink>, klik <Label>Print</Label> pada aset tersebut, lalu cetak Barcode 1D, QR Code, atau keduanya.</>,
        'Tempel label pada aset fisik.',
      ]} />
      <PathCard title="Memindai dengan aplikasi mobile">
        <p>Masuk ke aplikasi Asset Tracker Scanner dengan alamat server, email, dan password yang sama dengan web. Di beranda, tekan <Label>Scan label aset</Label> dan arahkan kamera ke label: detail, posisi, dan riwayat tabung langsung tampil. Label yang sama dipakai untuk scan muat dan bongkar. Bila label rusak, ketik No Manufaktur-nya.</p>
      </PathCard>
    </StepSection>
  )
}

function Step7() {
  return (
    <StepSection n={7} goal="Setiap tabung terlacak dari muat, perjalanan, sampai bongkar." next={[
      { when: 'Transaksi selesai', n: 8 },
    ]}>
      <div className="grid gap-3 md:grid-cols-2">
        <PathCard title="Transaksi keluar (gudang ke customer)">
          <NumberedList items={[
            <>Web: buka <MenuLink to="/transaksi" permission="delivery.view">Transaksi</MenuLink>, klik <Label>Transaksi</Label> → <Label>Transaksi keluar</Label>. Pilih customer dan tabungnya; kendaraan boleh dipilih di sini atau nanti di mobile. Satu perjalanan bisa ke beberapa customer.</>,
            <>Mobile: buka <Label>Pengiriman Tabung</Label>, pilih transaksinya, lalu tekan <Label>Scan Muat</Label> dan scan tiap tabung di gudang. Hanya tabung yang dipilih di web yang diterima.</>,
            <>Mobile: isi <Label>Supir & kernet</Label>, lalu tekan <Label>Berangkat</Label>.</>,
            <>Mobile: selama di jalan tekan <Label>Lapor posisi</Label> atau <Label>Kendala</Label>. Posisi juga terkirim sendiri selama aplikasi terbuka.</>,
            <>Mobile: di customer tekan <Label>Tiba</Label>, scan <Label>Bongkar</Label>, lalu <Label>Selesai di customer</Label> dan isi nama penerima.</>,
            <>Mobile: setelah semua customer, tekan <Label>Selesaikan</Label>. Tabung yang tidak jadi diterima tercatat kembali ke gudang.</>,
          ]} />
        </PathCard>
        <PathCard title="Transaksi masuk (customer ke gudang)">
          <NumberedList items={[
            <>Web: klik <Label>Transaksi</Label> → <Label>Transaksi masuk</Label> dan pilih customer. Tabung yang tercatat di customer itu boleh dipilih. Bila dipilih, hanya tabung itu yang diterima saat scan; bila tidak, setiap tabung yang di-scan di customer itu ikut tercatat.</>,
            <>Mobile: isi <Label>Supir & kernet</Label>, lalu tekan <Label>Berangkat</Label>.</>,
            <>Mobile: di customer scan <Label>Muat</Label> tiap tabung, lalu <Label>Selesai di customer</Label>.</>,
            <>Mobile: di gudang tekan <Label>Tiba di gudang</Label>, scan <Label>Bongkar</Label>, lalu <Label>Selesaikan</Label>.</>,
            'Tabung yang dimuat tetapi tidak dibongkar ditandai Selisih dan wajib diberi keterangan.',
          ]} />
        </PathCard>
      </div>
      <ScanChecks />
      <Tip>Supir, kernet, kendaraan, posisi terakhir, dan tahap perjalanan tampil di <MenuLink to="/tracking" permission="delivery.view">Tracking</MenuLink>, diperbarui otomatis. Di mobile, detail transaksi menunjukkan tahapnya dan <Label>Langkah berikutnya</Label>.</Tip>
    </StepSection>
  )
}

/** What the phone shows after each scan, so the crew knows what a beep and a color mean. */
function ScanChecks() {
  const rows: { icon: LucideIcon; tone: keyof typeof FLOW_TONES; title: string; text: string }[] = [
    { icon: CheckCircle2, tone: 'green', title: 'Hijau: tercatat', text: 'Tabung termasuk transaksi dan dihitung. HP bergetar singkat.' },
    { icon: XCircle, tone: 'red', title: 'Merah: Tabung tidak sesuai', text: 'Tabung tidak dipilih di transaksi ini (atau untuk customer ini), tidak ada di muatan, atau kodenya tidak dikenal. Muncul peringatan yang harus ditutup, berisi apa yang harus dilakukan; tabung tidak dihitung.' },
    { icon: Clock, tone: 'amber', title: 'Kuning: Tabung sudah di-scan', text: 'Tabung ini sudah tercatat sebelumnya. Scan ulang tidak dihitung lagi; lanjutkan ke tabung berikutnya.' },
  ]
  return (
    <figure className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
      <figcaption className="mb-3 text-sm font-semibold text-slate-900">Arti hasil scan di mobile</figcaption>
      <div className="grid gap-2 md:grid-cols-3">
        {rows.map((r) => (
          <FlowBox key={r.title} icon={r.icon} tone={r.tone} title={r.title} text={r.text} />
        ))}
      </div>
    </figure>
  )
}

function Step8() {
  return (
    <StepSection n={8} goal="Kondisi aset terbaca sekilas, dan laporan siap dibagikan." next={[
      { when: 'Ada tabung yang dikirim atau dijemput', n: 7 },
      { when: 'Ada tabung baru', n: 4 },
      { when: 'Ada karyawan, kendaraan, atau customer baru', n: 2 },
    ]}>
      <div className="grid gap-3 md:grid-cols-3">
        <PathCard title={<MenuLink to="/dashboard-aset" permission="dashboard.view">Dashboard Aset</MenuLink>}>
          <p>Ringkasan jumlah, status, dan posisi tabung. Gunakan <Label>Atur tata letak</Label> untuk menyusun analisis yang ingin ditampilkan: seret widget ke posisi yang diinginkan. Kartu Beranda disusun dengan cara yang sama lewat <Label>Atur kartu</Label>. Isi widget menyesuaikan lebarnya: makin lebar, makin lengkap analisisnya.</p>
        </PathCard>
        <PathCard title={<MenuLink to="/laporan/template" permission="report.view">Template Laporan</MenuLink>}>
          <p>Susun sendiri format laporan dan data yang ingin dilaporkan: seret elemen ke kertas, pilih field, lalu <Label>Terbitkan</Label>. Template yang sudah terbit dapat dijalankan dan diunduh sebagai PDF atau Excel.</p>
        </PathCard>
        <PathCard title={<MenuLink to="/audit" permission="audit.view">Audit Trail</MenuLink>}>
          <p>Jejak aktivitas di business unit: siapa melakukan apa dan kapan. Gunakan saat perlu menelusuri suatu perubahan.</p>
        </PathCard>
      </div>
      <Tip>Tabung tidak pernah dihapus: nomor dan riwayatnya tetap tersimpan. Posisinya (di gudang, dalam perjalanan, di customer) dan statusnya mengikuti transaksi, sehingga tidak perlu diubah dengan tangan.</Tip>
    </StepSection>
  )
}

const PROBLEMS: { q: string; a: ReactNode }[] = [
  { q: 'Menu yang saya cari tidak ada, atau muncul "Akses ditolak".', a: 'Role Anda belum memiliki izin untuk menu itu. Hubungi Super Admin atau Supervisor business unit Anda.' },
  { q: 'Perubahan yang saya simpan belum terlihat.', a: <>Kemungkinan masih menunggu persetujuan. Periksa statusnya di menu <MenuLink to="/notifikasi">Pengajuan Saya</MenuLink>.</> },
  { q: 'Saya tidak menemukan aset tertentu.', a: 'Aset itu mungkin berada di luar cakupan data Anda, atau sudah nonaktif. Untuk melihat aset nonaktif, ubah filter di Data Aset menjadi "Aset nonaktif". Kendaraan ada di Master Data › Kendaraan, bukan di Data Aset.' },
  { q: 'Tabung tidak muncul saat membuat transaksi.', a: 'Untuk transaksi keluar, tabung harus berstatus Di Gudang dan tidak sedang dipakai transaksi lain. Untuk transaksi masuk, tabung harus tercatat berada di customer itu.' },
  { q: 'Saat scan muncul "Tabung tidak sesuai".', a: 'Tabung itu tidak dipilih di transaksi ini, atau dipilih untuk customer lain, sehingga tidak dihitung. Saat muat, keluarkan tabung itu dari muatan; saat bongkar, biarkan di kendaraan. Bila tabung memang harus ikut: transaksi keluar dapat diubah di web selama belum ada tabung yang di-scan muat; untuk transaksi masuk, buat transaksi masuk baru untuk tabung itu.' },
  { q: 'Saat scan muncul "Tabung sudah di-scan".', a: 'Tabung itu sudah tercatat. Scan ulang tidak menambah hitungan, jadi lanjutkan ke tabung berikutnya. Lihat sisa tabung yang belum di-scan dengan menekan Belum di layar scan.' },
  { q: 'Saya salah scan muat.', a: 'Di mobile, pilih Batal muat pada tabungnya di daftar Tabung selama kendaraan belum berangkat (transaksi keluar) atau customer belum diselesaikan (transaksi masuk).' },
  { q: 'Tidak bisa menekan Berangkat.', a: 'Pastikan kendaraan dan supir sudah diisi, minimal satu tabung sudah dimuat (transaksi keluar), dan kendaraan maupun krunya tidak sedang dalam perjalanan lain.' },
  { q: 'Posisi kendaraan tidak bergerak di Tracking.', a: 'Posisi terkirim selama aplikasi mobile terbuka dan izin lokasi aktif. Minta supir membuka transaksinya di aplikasi.' },
  { q: 'Tombol cetak barcode tidak muncul.', a: 'Pastikan Modul Barcode sudah aktif dan kategori aset itu (atau kategori induknya) sudah dicentang pada halaman Modul Barcode.' },
  { q: 'Tidak ada tombol Arsipkan di Kategori, Karyawan, Kendaraan, atau Customer.', a: 'Data itu tidak diarsipkan agar riwayat transaksi tetap utuh. Karyawan, customer, atau kategori yang tidak dipakai lagi cukup dinonaktifkan. Status kendaraan diatur sistem dari transaksi.' },
  { q: 'Supir atau kernet tidak muncul di mobile.', a: 'Pastikan karyawan itu masuk divisi Supir atau Kernet di Master Data › Karyawan, aktif, dan tidak sedang dalam perjalanan lain.' },
  { q: 'Import Excel gagal.', a: 'Baca daftar baris yang salah pada pesan error, perbaiki file, lalu unggah ulang. Selama masih ada baris yang salah, tidak ada data yang disimpan.' },
  { q: 'Saya lupa password.', a: 'Minta Super Admin atau Supervisor mengatur password sementara dari halaman detail pengguna. Anda akan diminta menggantinya saat masuk.' },
  { q: 'Tautan undangan tidak berlaku.', a: 'Undangan punya batas waktu dan hanya dapat dipakai sekali. Minta Super Admin memilih Kirim ulang. Bila diminta verifikasi email, selesaikan dahulu lalu buka kembali tautannya.' },
  { q: 'Saya tidak dapat mengundang pengguna.', a: 'Hanya Super Admin yang dapat mengundang, jadi mintalah Super Admin mengirim undangannya.' },
  { q: 'Nama karyawan tidak muncul saat mengundang.', a: 'Undangan memilih dari data karyawan. Daftarkan dahulu orangnya di Master Data › Karyawan, lalu ulangi.' },
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

/** A Ya/Tidak cell. Its tables sit in a `relative` scroll box: the absolutely positioned sr-only text would otherwise widen the page. */
function YesNo({ value }: { value: boolean }) {
  return (
    <td className="px-3 py-2 text-center">
      {value ? <Check className="mx-auto size-4 text-green-600" aria-hidden /> : <Minus className="mx-auto size-4 text-slate-300" aria-hidden />}
      <span className="sr-only">{value ? 'Ya' : 'Tidak'}</span>
    </td>
  )
}
