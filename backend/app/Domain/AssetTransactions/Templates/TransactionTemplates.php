<?php

namespace App\Domain\AssetTransactions\Templates;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Flows\FlowActions;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;

/**
 * Ready-made transaction types (docs/factory/features/F-08 §4, D-40): static data in code, like
 * Dashboards\Catalog\SystemTemplates. Using one makes a draft organization type with its own
 * copy of the fields and flow; the template never changes with it and stays unlinked
 * (only source_template_key records where it came from).
 *
 * Approvers by role name a role template (SUPERVISOR, SUPER_ADMIN) instead of a role id; the
 * organization's role of that template is filled in when the type is made. Without one the step
 * keeps no role, and the flow check asks for an approver before publishing (F08-04).
 *
 * A template with a `pair` is made together with that template, which closes it ("Berpasangan"):
 * Kirim kalibrasi waits for Kembali dari kalibrasi. The closing template is not offered alone.
 *
 * The seven system types (F-07f) are the gallery's "Jenis sistem" group; they are copied with
 * POST /transaction-types/{id}/duplicate, not listed here.
 */
final class TransactionTemplates
{
    public const CATEGORIES = [
        'perpindahan' => 'Perpindahan',
        'peminjaman' => 'Peminjaman',
        'perawatan' => 'Perawatan',
        'penghapusan' => 'Penghapusan',
    ];

    /**
     * @return array<string, array{name: string, category: string, purpose: string, code: string, icon: string,
     *     multi_asset: bool, locks_assets: bool, core_type: string|null, pair: string|null, closes: string|null,
     *     fields: list<array<string, mixed>>, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, rules: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'mutasi_lokasi' => [
                'name' => 'Mutasi antar lokasi',
                'category' => 'perpindahan',
                'purpose' => 'Memindah aset ke lokasi lain dengan persetujuan, lalu penerima mengonfirmasi kedatangannya.',
                'code' => 'MUT', 'icon' => 'truck', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => null, 'closes' => null,
                'fields' => [
                    self::field('lokasi_tujuan', 'Lokasi tujuan', 'location', true),
                    self::field('alasan', 'Alasan', 'textarea', true, ['max_length' => 1000]),
                    self::field('penerima', 'Penerima', 'user', true),
                    self::field('no_bast', 'No. BAST', 'text', false, ['max_length' => 100]),
                    self::field('foto', 'Foto', 'attachment', false, ['accept' => ['image']]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diajukan', ['preconditions' => ['match' => 'all', 'assets' => 'all', 'rules' => [
                        ['subject' => 'asset.status', 'op' => 'not_in', 'value' => [AssetStatus::ON_LOAN, AssetStatus::OFF_SITE]],
                    ]], 'message' => 'Aset yang sedang dipinjamkan atau berada di luar organisasi tidak dapat dimutasi.']),
                    self::node('setuju_spv', 'approval', 'Persetujuan Supervisor lokasi', ['approver' => ['type' => 'location_supervisor'], 'min_approvals' => 1]),
                    self::node('terima', 'input', 'Dalam pengiriman', ['assignee' => ['type' => 'field', 'field' => 'penerima'], 'fields' => ['no_bast', 'foto']]),
                    self::node('pindah', 'action', 'Diterima', ['actions' => [
                        ['kind' => 'move_to_field_location', 'field' => 'lokasi_tujuan'],
                        ['kind' => 'set_status', 'status' => AssetStatus::IN_USE],
                    ]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                    self::node('ditolak', 'end', 'Ditolak', ['result' => 'rejected']),
                ], [
                    ['start', 'setuju_spv'], ['setuju_spv', 'terima', 'approved'], ['setuju_spv', 'ditolak', 'rejected'],
                    ['terima', 'pindah'], ['pindah', 'selesai'],
                ]),
                'rules' => [
                    self::rule('rusak', 'Larangan memindah aset rusak', 1, [['subject' => 'asset.condition', 'op' => 'eq', 'value' => 'RUSAK_BERAT']],
                        ['kind' => 'reject', 'message' => 'Aset rusak berat diperbaiki atau dihapuskan, bukan dipindah.']),
                    self::rule('nilai', 'Approval aset bernilai tinggi', 2, [['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10000000]],
                        ['kind' => 'approval', 'approver' => self::role(RoleTemplates::SUPER_ADMIN), 'min_approvals' => 1]),
                    self::rule('lintas', 'Persetujuan lintas departemen', 3, [['subject' => 'target.department_differs', 'op' => 'eq', 'value' => 'penerima']],
                        ['kind' => 'approval', 'approver' => ['type' => 'target_department_supervisor', 'field' => 'penerima'], 'min_approvals' => 1]),
                ],
            ],
            'kirim_kalibrasi' => [
                'name' => 'Kirim kalibrasi',
                'category' => 'perawatan',
                'purpose' => 'Aset dikirim ke vendor kalibrasi dan menunggu sampai dicatat kembali.',
                'code' => 'KAL', 'icon' => 'gauge', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => 'kembali_kalibrasi', 'closes' => null,
                'fields' => [
                    self::field('vendor', 'Vendor', 'counterparty', true),
                    self::field('no_po', 'No. PO', 'text', false, ['max_length' => 100]),
                    self::field('rencana_kembali', 'Rencana kembali', 'date', true, ['not_before_today' => true]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diajukan'),
                    self::node('setuju', 'approval', 'Persetujuan Supervisor lokasi', ['approver' => ['type' => 'location_supervisor'], 'min_approvals' => 1]),
                    self::node('kirim', 'action', 'Dikirim ke vendor', ['actions' => [['kind' => 'set_status', 'status' => AssetStatus::UNDER_MAINTENANCE]]]),
                    self::node('tunggu', 'wait', 'Menunggu kembali dari kalibrasi', ['until' => 'paired']),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                    self::node('ditolak', 'end', 'Ditolak', ['result' => 'rejected']),
                ], [
                    ['start', 'setuju'], ['setuju', 'kirim', 'approved'], ['setuju', 'ditolak', 'rejected'],
                    ['kirim', 'tunggu'], ['tunggu', 'selesai'],
                ]),
                'rules' => [],
            ],
            'kembali_kalibrasi' => [
                'name' => 'Kembali dari kalibrasi',
                'category' => 'perawatan',
                'purpose' => 'Mencatat aset yang kembali dari kalibrasi beserta sertifikat dan hasilnya.',
                'code' => 'KBL', 'icon' => 'gauge', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => null, 'closes' => 'kirim_kalibrasi',
                'fields' => [
                    self::field('tanggal_kembali', 'Tanggal kembali', 'date', true, ['not_after_today' => true]),
                    self::field('sertifikat', 'Sertifikat kalibrasi', 'attachment', true, ['accept' => ['pdf', 'image']]),
                    // Option values name an option of Kondisi Aset, so the result sets the asset's condition.
                    self::field('hasil', 'Hasil', 'select', true, ['options' => [
                        ['value' => 'BAIK', 'label' => 'Baik'],
                        ['value' => 'RUSAK_RINGAN', 'label' => 'Rusak Ringan'],
                        ['value' => 'RUSAK_BERAT', 'label' => 'Rusak Berat'],
                    ]]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diterima kembali'),
                    self::node('kondisi', 'action', 'Catat kondisi', ['actions' => [['kind' => 'set_condition', 'field' => 'hasil']]]),
                    self::node('cek_hasil', 'condition', 'Hasil baik?', ['match' => 'all', 'assets' => 'any', 'rules' => [
                        ['subject' => 'field.hasil', 'op' => 'eq', 'value' => 'BAIK'],
                    ]]),
                    self::node('aktif', 'action', 'Aset aktif kembali', ['actions' => [['kind' => 'set_status', 'status' => AssetStatus::IN_USE]]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                ], [
                    ['start', 'kondisi'], ['kondisi', 'cek_hasil'],
                    ['cek_hasil', 'aktif', 'yes'], ['cek_hasil', 'selesai', 'no'], ['aktif', 'selesai'],
                ]),
                'rules' => [],
            ],
            'perbaikan_vendor' => [
                'name' => 'Perbaikan ke vendor',
                'category' => 'perawatan',
                'purpose' => 'Aset rusak diperbaiki pihak luar; perbaikan mahal perlu persetujuan Super Admin.',
                'code' => 'PRV', 'icon' => 'wrench', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => null, 'closes' => null,
                'fields' => [
                    self::field('vendor', 'Vendor', 'counterparty', true),
                    self::field('keluhan', 'Keluhan', 'textarea', true, ['max_length' => 1000]),
                    self::field('estimasi_biaya', 'Estimasi biaya', 'money', false),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diajukan'),
                    self::node('setuju_spv', 'approval', 'Persetujuan Supervisor lokasi', ['approver' => ['type' => 'location_supervisor'], 'min_approvals' => 1]),
                    self::node('perbaiki', 'action', 'Dalam perbaikan', ['actions' => [['kind' => 'set_status', 'status' => AssetStatus::UNDER_MAINTENANCE]]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                    self::node('ditolak', 'end', 'Ditolak', ['result' => 'rejected']),
                ], [
                    ['start', 'setuju_spv'], ['setuju_spv', 'perbaiki', 'approved'], ['setuju_spv', 'ditolak', 'rejected'], ['perbaiki', 'selesai'],
                ]),
                'rules' => [
                    self::rule('biaya', 'Approval biaya perbaikan besar', 1, [['subject' => 'field.estimasi_biaya', 'op' => 'gt', 'value' => 5000000]],
                        ['kind' => 'approval', 'approver' => self::role(RoleTemplates::SUPER_ADMIN), 'min_approvals' => 1]),
                ],
            ],
            'peminjaman_karyawan' => [
                'name' => 'Peminjaman ke karyawan',
                'category' => 'peminjaman',
                'purpose' => 'Aset dipinjamkan sementara kepada seorang pengguna sampai tanggal kembali.',
                'code' => 'PJK', 'icon' => 'hand', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => null, 'closes' => null,
                'fields' => [
                    self::field('pemegang', 'Pemegang', 'user', true),
                    self::field('rencana_kembali', 'Rencana kembali', 'date', true, ['not_before_today' => true]),
                    self::field('foto', 'Foto', 'attachment', false, ['accept' => ['image']]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diajukan', ['preconditions' => ['match' => 'all', 'assets' => 'all', 'rules' => [
                        ['subject' => 'asset.status', 'op' => 'in', 'value' => [AssetStatus::IN_USE, AssetStatus::AVAILABLE]],
                    ]], 'message' => 'Hanya aset berstatus Aktif atau Tidak Aktif yang dapat dipinjamkan.']),
                    self::node('pinjam', 'action', 'Dipinjamkan', ['actions' => [
                        ['kind' => 'loan_to_field_user', 'user_field' => 'pemegang', 'due_field' => 'rencana_kembali'],
                    ]]),
                    self::node('kabari', 'action', 'Email ke pemegang', ['actions' => [
                        ['kind' => 'notify', 'to' => ['type' => 'field', 'field' => 'pemegang'], 'message' => 'Aset dipinjamkan kepada Anda. Harap dijaga dan dikembalikan sesuai rencana.'],
                    ]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                ], [
                    ['start', 'pinjam'], ['pinjam', 'kabari'], ['kabari', 'selesai'],
                ]),
                'rules' => [],
            ],
            // v0.3 (D-52, D-53, D-58): the lasting custodian changes hands; lending stays Peminjaman ke karyawan.
            'serah_terima_penanggung_jawab' => [
                'name' => 'Serah terima penanggung jawab',
                'category' => 'perpindahan',
                'purpose' => 'Penanggung jawab tetap aset berganti, misalnya karena karyawan pindah tugas; penerima mengonfirmasi serah terimanya.',
                'code' => 'STJ', 'icon' => 'handshake', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => null, 'pair' => null, 'closes' => null,
                'fields' => [
                    self::field('penanggung_jawab_baru', 'Penanggung jawab baru', 'user', true),
                    self::field('alasan', 'Alasan', 'textarea', true, ['max_length' => 1000]),
                    self::field('berita_acara', 'Berita acara', 'attachment', false, ['accept' => ['pdf', 'image']]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diajukan'),
                    self::node('setuju_lama', 'approval', 'Persetujuan penanggung jawab lama', ['approver' => ['type' => 'asset_custodian'], 'min_approvals' => 1]),
                    self::node('konfirmasi', 'input', 'Konfirmasi penerima', ['assignee' => ['type' => 'field', 'field' => 'penanggung_jawab_baru'], 'fields' => ['berita_acara']]),
                    self::node('kabari_lama', 'action', 'Email ke penanggung jawab lama', ['actions' => [
                        ['kind' => 'notify', 'to' => ['type' => 'asset_custodian'], 'message' => 'Aset yang Anda pegang telah diserahterimakan kepada penanggung jawab baru.'],
                    ]]),
                    self::node('ganti', 'action', 'Penanggung jawab diganti', ['actions' => [
                        ['kind' => 'set_custodian', 'user_field' => 'penanggung_jawab_baru', 'set_department' => true],
                    ]]),
                    self::node('kabari_baru', 'action', 'Email ke penanggung jawab baru', ['actions' => [
                        ['kind' => 'notify', 'to' => ['type' => 'field', 'field' => 'penanggung_jawab_baru'], 'message' => 'Anda kini penanggung jawab aset dalam transaksi ini.'],
                    ]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                    self::node('ditolak', 'end', 'Ditolak', ['result' => 'rejected']),
                ], [
                    ['start', 'setuju_lama'], ['setuju_lama', 'konfirmasi', 'approved'], ['setuju_lama', 'ditolak', 'rejected'],
                    ['konfirmasi', 'kabari_lama'], ['kabari_lama', 'ganti'], ['ganti', 'kabari_baru'], ['kabari_baru', 'selesai'],
                ]),
                'rules' => [
                    self::rule('lintas', 'Persetujuan lintas departemen', 1, [['subject' => 'target.department_differs', 'op' => 'eq', 'value' => 'penanggung_jawab_baru']],
                        ['kind' => 'approval', 'approver' => ['type' => 'target_department_supervisor', 'field' => 'penanggung_jawab_baru'], 'min_approvals' => 1]),
                ],
            ],
            // Disposal keeps its own rules (FlowGraph::ACTION_STATUSES has no Dihapuskan): the type
            // records a permanent Keluar as its core action, after two approvals; a rule refuses Sementara.
            'penghapusan_aset' => [
                'name' => 'Penghapusan aset',
                'category' => 'penghapusan',
                'purpose' => 'Usulan penghapusan aset dengan persetujuan Supervisor lalu Super Admin; aset keluar permanen.',
                'code' => 'HPS', 'icon' => 'trash', 'multi_asset' => true, 'locks_assets' => true,
                'core_type' => TransactionType::OUTGOING, 'pair' => null, 'closes' => null,
                'fields' => [
                    self::field('nilai_jual', 'Nilai jual', 'money', false),
                    self::field('berita_acara', 'Berita acara', 'attachment', false, ['accept' => ['pdf', 'image']]),
                ],
                'graph' => self::graph([
                    self::node('start', 'start', 'Diusulkan'),
                    self::node('setuju_spv', 'approval', 'Persetujuan Supervisor', ['approver' => self::role(RoleTemplates::SUPERVISOR), 'min_approvals' => 1]),
                    self::node('setuju_sa', 'approval', 'Persetujuan Super Admin', ['approver' => self::role(RoleTemplates::SUPER_ADMIN), 'min_approvals' => 1]),
                    self::node('inti', 'action', 'Dihapuskan', ['actions' => [['kind' => FlowActions::CORE]]]),
                    self::node('selesai', 'end', 'Selesai', ['result' => 'completed']),
                    self::node('ditolak', 'end', 'Ditolak', ['result' => 'rejected']),
                ], [
                    ['start', 'setuju_spv'], ['setuju_spv', 'setuju_sa', 'approved'], ['setuju_spv', 'ditolak', 'rejected'],
                    ['setuju_sa', 'inti', 'approved'], ['setuju_sa', 'ditolak', 'rejected'], ['inti', 'selesai'],
                ]),
                'rules' => [
                    self::rule('permanen', 'Penghapusan selalu permanen', 1, [['subject' => 'core.outgoing_nature', 'op' => 'eq', 'value' => OutgoingReason::TEMPORARY]],
                        ['kind' => 'reject', 'message' => 'Penghapusan aset bersifat permanen. Untuk aset yang akan kembali, pakai transaksi Keluar sementara.']),
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The template's graph for one organization: role templates become that organization's role
     * ids (run inside its tenant context); a missing role leaves the approver without an id.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function resolveRoles(array $graph): array
    {
        $roles = Role::query()->whereIn('template_code', [RoleTemplates::SUPERVISOR, RoleTemplates::SUPER_ADMIN])
            ->orderBy('created_at')->get(['id', 'template_code'])->unique('template_code')->pluck('id', 'template_code');
        $resolve = function (array $who) use ($roles): array {
            if (($who['type'] ?? null) !== 'role' || ! isset($who['template'])) {
                return $who;
            }

            return ['type' => 'role', 'id' => $roles[$who['template']] ?? null];
        };
        $graph['nodes'] = array_map(function (array $node) use ($resolve) {
            if (isset($node['config']['approver'])) {
                $node['config']['approver'] = $resolve($node['config']['approver']);
            }
            if (isset($node['config']['assignee'])) {
                $node['config']['assignee'] = $resolve($node['config']['assignee']);
            }

            return $node;
        }, $graph['nodes']);

        return $graph;
    }

    /**
     * The template's named rules (F-08g) with their approver roles resolved like resolveRoles().
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public static function resolveRuleRoles(array $rules): array
    {
        if ($rules === []) {
            return [];
        }
        $graph = self::resolveRoles(['nodes' => array_map(fn (array $r) => ['id' => $r['id'], 'config' => ['approver' => $r['outcome']['approver'] ?? []]], $rules), 'edges' => []]);

        return array_map(function (array $rule, array $node) {
            if (isset($rule['outcome']['approver'])) {
                $rule['outcome']['approver'] = $node['config']['approver'];
            }

            return $rule;
        }, $rules, $graph['nodes']);
    }

    /**
     * @param  list<array{subject: string, op: string, value?: mixed}>  $conditions
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private static function rule(string $id, string $name, int $priority, array $conditions, array $outcome): array
    {
        return ['id' => $id, 'name' => $name, 'description' => null, 'priority' => $priority, 'active' => true,
            'match' => 'all', 'assets' => 'any', 'conditions' => $conditions, 'outcome' => $outcome];
    }

    /** @return array{type: string, template: string} */
    private static function role(string $template): array
    {
        return ['type' => 'role', 'template' => $template];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type, bool $required, array $settings = []): array
    {
        return ['key' => $key, 'label' => $label, 'data_type' => $type, 'settings' => $settings, 'is_required' => $required];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, string $label, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'config' => $config];
    }

    /**
     * Nodes laid out left to right in the order given; edges as [source, target, outcome?].
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{0: string, 1: string, 2?: string}>  $edges
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function graph(array $nodes, array $edges): array
    {
        $x = 0;
        foreach ($nodes as $i => $node) {
            $end = $node['type'] === 'end' && ($node['config']['result'] ?? 'completed') !== 'completed';
            $nodes[$i]['position'] = ['x' => $end ? $x - 280 : $x, 'y' => $end ? 260 : 80];
            $x += $end ? 0 : 280;
        }

        return ['nodes' => $nodes, 'edges' => array_map(fn (array $e) => [
            'id' => "e_{$e[0]}_{$e[1]}", 'source' => $e[0], 'target' => $e[1], 'outcome' => $e[2] ?? null,
        ], $edges)];
    }
}
