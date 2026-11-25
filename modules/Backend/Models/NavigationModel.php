<?php

namespace Modules\Backend\Models;

use CodeIgniter\Model;

class NavigationModel extends Model
{
    protected $table         = 'menus';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['title', 'slug', 'url', 'icon', 'description', 'parent_id', 'position', 'section', 'sequence', 'is_active'];
    protected $useTimestamps = true;

    public function getAllGroups()
    {
        $db = \Config\Database::connect();
        return $db->table('auth_groups')->get()->getResultArray();
    }

    // ═══════════════════════════════════════════════════════════════════
    // READ: Daftar menu + groups (dengan section opsional)
    // Menggabungkan getMenusWithGroupsByPosition + getMenusWithGroupsByPositionAndSection
    // ═══════════════════════════════════════════════════════════════════
    public function getMenusWithGroups(string $position, ?string $section = null): array
    {
        $builder = $this->where('position', $position)
            ->orderBy('sequence', 'ASC')
            ->orderBy('id', 'ASC');

        if ($section !== null) {
            $builder->where('section', $section);
        }

        $menus = $builder->findAll();

        if ($menus === []) {
            return [];
        }

        $menuIds = array_map('intval', array_column($menus, 'id'));
        $relations = $this->db->table('menu_groups')
            ->whereIn('menu_id', $menuIds)
            ->get()
            ->getResultArray();

        $groupedRelations = [];
        foreach ($relations as $relation) {
            $groupName = trim((string) ($relation['group_name'] ?? ''));
            if ($groupName !== '') {
                $groupedRelations[(int) $relation['menu_id']][$groupName] = $groupName;
            }
        }

        foreach ($menus as &$menu) {
            $menu['selected_groups'] = array_values($groupedRelations[(int) $menu['id']] ?? []);
        }
        unset($menu);

        return $menus;
    }

    // ═══════════════════════════════════════════════════════════════════
    // READ: Detail satu menu + groups (menggantikan getMenuForEdit + getMenuWithGroups)
    // ═══════════════════════════════════════════════════════════════════
    public function getMenuWithGroups(int $id): ?array
    {
        $menu = $this->find($id);
        if (!$menu) {
            return null;
        }

        $assignedGroups = $this->db->table('menu_groups')
            ->where('menu_id', $id)
            ->get()
            ->getResultArray();

        // ✅ Konsisten: kembalikan group_name (bukan group_id)
        $menu['selected_groups'] = array_values(array_filter(
            array_column($assignedGroups, 'group_name')
        ));

        return $menu;
    }

    // ═══════════════════════════════════════════════════════════════════
    // WRITE: Simpan menu + groups (satu method, pakai group_name)
    // Menggabungkan saveMenuWithGroups + syncGroups
    // ═══════════════════════════════════════════════════════════════════
    public function saveMenuWithGroups(int $id, array $data, ?array $groups = null): bool
    {
        $db = $this->db;
        $db->transBegin();

        try {
            if ($id > 0) {
                $this->update($id, $data);
            } else {
                $id = (int) $this->insert($data, true);
                if (!$id) {
                    throw new \RuntimeException('Gagal menyimpan menu: ' . implode(', ', $this->errors()));
                }
            }

            // Sync groups (pakai group_name)
            if ($groups !== null) {
                $this->syncGroups($id, $groups);
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi simpan menu gagal.');
            }

            if ($db->transCommit() === false) {
                throw new \RuntimeException('Transaksi simpan menu gagal di-commit.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // WRITE: Sync groups saja (internal helper, dipakai saveMenuWithGroups)
    // ═══════════════════════════════════════════════════════════════════
    public function syncGroups(int $menuId, array $groups): void
    {
        if ($menuId < 1) {
            throw new \InvalidArgumentException('ID menu tidak valid.');
        }

        $groupNames = [];
        foreach ($groups as $group) {
            if (!is_string($group)) {
                throw new \InvalidArgumentException('Nama group akses tidak valid.');
            }
            $groupName = trim($group);
            if ($groupName !== '') {
                $groupNames[$groupName] = $groupName;
            }
        }

        $builder = $this->db->table('menu_groups');

        if ($builder->where('menu_id', $menuId)->delete() === false) {
            throw new \RuntimeException('Gagal menghapus relasi group menu.');
        }

        if ($groupNames === []) {
            return;
        }

        $batch = [];
        foreach ($groupNames as $groupName) {
            $batch[] = ['menu_id' => $menuId, 'group_name' => $groupName];
        }

        if ($this->db->table('menu_groups')->insertBatch($batch) === false) {
            throw new \RuntimeException('Gagal menyimpan relasi group menu.');
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // WRITE: Simpan hierarki (drag & drop) — TIDAK BERUBAH
    // ═══════════════════════════════════════════════════════════════════
    public function saveHierarchy(array $items): void
    {
        if ($items === [] || !array_is_list($items)) {
            throw new \InvalidArgumentException('Struktur menu tidak valid atau kosong.');
        }

        $rows = [];
        $seen = [];

        $walk = function (array $nodes, int $parentId = 0, int $depth = 0) use (&$walk, &$rows, &$seen): void {
            if ($depth > 20 || !array_is_list($nodes)) {
                throw new \InvalidArgumentException('Kedalaman atau format hierarki menu tidak valid.');
            }

            foreach ($nodes as $index => $node) {
                if (!is_array($node) || !isset($node['id']) || filter_var($node['id'], FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('ID menu pada hierarki tidak valid.');
                }

                $id = (int) $node['id'];

                if ($id < 1 || isset($seen[$id])) {
                    throw new \InvalidArgumentException('Menu duplikat atau ID menu tidak valid.');
                }

                $children = $node['children'] ?? [];

                if (!is_array($children) || !array_is_list($children)) {
                    throw new \InvalidArgumentException('Daftar submenu tidak valid.');
                }

                $seen[$id] = true;
                $rows[] = ['id' => $id, 'parent_id' => $parentId, 'sequence' => $index + 1];

                $walk($children, $id, $depth + 1);
            }
        };

        $walk($items);
        $ids = array_column($rows, 'id');

        $db = $this->db;
        $db->transBegin();

        try {
            $existing = $db->table($this->table)
                ->select('id, position, section')
                ->whereIn('id', $ids)
                ->get()
                ->getResultArray();

            if (count($existing) !== count($ids)) {
                throw new \InvalidArgumentException('Sebagian menu tidak ditemukan.');
            }

            $position = (string) $existing[0]['position'];
            $section  = (string) $existing[0]['section'];

            foreach ($existing as $menu) {
                if ($menu['position'] !== $position || $menu['section'] !== $section) {
                    throw new \InvalidArgumentException('Hierarki tidak boleh mencampur posisi atau section menu.');
                }
            }

            $scopeRows = $db->table($this->table)
                ->select('id')
                ->where('position', $position)
                ->where('section', $section)
                ->get()
                ->getResultArray();

            $scopeIds = array_map('intval', array_column($scopeRows, 'id'));
            $submittedIds = $ids;

            sort($scopeIds);
            sort($submittedIds);

            if ($scopeIds !== $submittedIds) {
                throw new \InvalidArgumentException('Struktur menu tidak lengkap. Muat ulang sebelum menyimpan.');
            }

            foreach ($rows as $row) {
                $updated = $db->table($this->table)
                    ->where('id', $row['id'])
                    ->where('position', $position)
                    ->where('section', $section)
                    ->update(['parent_id' => $row['parent_id'], 'sequence' => $row['sequence']]);

                if ($updated === false) {
                    throw new \RuntimeException('Gagal memperbarui hierarki menu.');
                }
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi hierarki menu gagal.');
            }

            if ($db->transCommit() === false) {
                throw new \RuntimeException('Transaksi hierarki menu gagal di-commit.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // READ: Navigasi runtime (sidebar/topbar) dengan hierarki + permissions
    // TIDAK BERUBAH
    // ═══════════════════════════════════════════════════════════════════
    public function getNavigationForGroups(array $userGroups, string $section = 'frontend'): array
    {
        $positions = ['sidebar', 'apps', 'quick_links', 'topbar'];
        $result = array_fill_keys($positions, []);

        $userGroups = array_values(array_unique(array_filter(
            array_map(static fn ($group) => trim((string) $group), $userGroups),
            static fn ($group) => $group !== ''
        )));

        if ($userGroups === []) {
            return $result;
        }

        $menus = $this->distinct()->select('menus.*')
            ->join('menu_groups', 'menu_groups.menu_id = menus.id')
            ->where('menus.section', $section)
            ->where('menus.is_active', 1)
            ->whereIn('menu_groups.group_name', $userGroups)
            ->groupBy('menus.id')
            ->orderBy('menus.position', 'ASC')
            ->orderBy('menus.sequence', 'ASC')
            ->findAll();

        if ($menus === []) {
            return $result;
        }

        $permissionRows = $this->db->table('route_registry_menus rrm')
            ->select('rrm.url, rrp.permission')
            ->join('route_registry_permissions rrp', 'rrp.route_id = rrm.route_id')
            ->get()
            ->getResultArray();

        $permissionsByUrl = [];
        foreach ($permissionRows as $row) {
            $key = strtolower(trim((string) $row['url'], '/'));
            if ($key !== '') {
                $permissionsByUrl[$key][] = (string) $row['permission'];
            }
        }

        $visibleIds = array_fill_keys(array_map(static fn ($menu) => (int) $menu['id'], $menus), true);

        $byPosition = [];
        foreach ($menus as $menu) {
            $position = (string) ($menu['position'] ?? '');
            if (!array_key_exists($position, $result)) {
                continue;
            }

            $id = (int) $menu['id'];
            $parentId = (int) ($menu['parent_id'] ?? 0);
            if ($parentId <= 0 || !isset($visibleIds[$parentId])) {
                $parentId = 0;
            }

            $url = trim((string) ($menu['url'] ?? ''));
            $urlKey = strtolower(trim($url, '/'));
            $path = $url === '' ? '' : '/' . ltrim($url, '/');

            $byPosition[$position][$parentId][] = [
                'id'            => $id,
                'parent_id'     => $parentId ?: null,
                'title'         => (string) $menu['title'],
                'slug'          => (string) ($menu['slug'] ?? ''),
                'url'           => $url,
                'path'          => $path,
                'name'          => (string) (($menu['slug'] ?? '') ?: 'menu-' . $id),
                'component_key' => $menu['component_key'] ?? null,
                'icon'          => $menu['icon'] ?? null,
                'description'   => $menu['description'] ?? null,
                'section'       => $section,
                'permissions'   => array_values(array_unique($permissionsByUrl[$urlKey] ?? [])),
            ];
        }

        $buildTree = function (array $siblings, int $parentId) use (&$buildTree): array {
            $tree = [];
            foreach ($siblings[$parentId] ?? [] as $item) {
                $item['children'] = $buildTree($siblings, (int) $item['id']);
                $tree[] = $item;
            }
            return $tree;
        };

        foreach ($positions as $position) {
            $result[$position] = $buildTree($byPosition[$position] ?? [], 0);
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    // READ: Preview sinkronisasi Route Registry — TIDAK BERUBAH
    // ═══════════════════════════════════════════════════════════════════
    public function getSyncMenuPreview(): array
    {
        $menus = $this->db->table('menus')
            ->select('id, title, slug, url, position, section')
            ->orderBy('position')
            ->orderBy('sequence')
            ->get()
            ->getResultArray();

        $candidates = $this->db->table('route_registry_menus')
            ->select('id, route_id, title, url')
            ->orderBy('title')
            ->get()
            ->getResultArray();

        $groups = array_values(array_filter(
            array_map(
                static fn (array $group): string => (string) ($group['name'] ?? ''),
                $this->db->table('auth_groups')->select('name')->orderBy('name')->get()->getResultArray()
            ),
            static fn (string $name): bool => $name !== ''
        ));

        $existingUrls = [];
        foreach ($menus as $menu) {
            $url = trim((string) ($menu['url'] ?? ''), '/');
            if ($url !== '') {
                $existingUrls[strtolower($url)] = true;
            }
        }

        foreach ($candidates as &$candidate) {
            $url = trim((string) ($candidate['url'] ?? ''), '/');
            $candidate['exists'] = $url !== '' && isset($existingUrls[strtolower($url)]);
        }
        unset($candidate);

        return ['menus' => $menus, 'candidates' => $candidates, 'groups' => $groups];
    }

    // ═══════════════════════════════════════════════════════════════════
    // WRITE: Sinkronisasi kandidat Route Registry — TIDAK BERUBAH
    // ═══════════════════════════════════════════════════════════════════
    public function syncMenu(array $items, string $section = 'backend'): array
    {
        if (!array_is_list($items) || $items === []) {
            throw new \InvalidArgumentException('Pilih minimal satu kandidat menu dengan format yang valid.');
        }

        if (!in_array($section, ['backend', 'frontend'], true)) {
            throw new \InvalidArgumentException('Section menu tidak valid.');
        }

        $allowedPositions = ['sidebar', 'topbar', 'down', 'quick_links', 'apps'];
        $inserted = [];
        $skipped  = [];

        $db = $this->db;
        $db->transBegin();

        try {
            $existingUrls = [];
            foreach ($db->table('menus')->select('url')->get()->getResultArray() as $row) {
                $url = strtolower(trim((string) ($row['url'] ?? ''), '/'));
                if ($url !== '') {
                    $existingUrls[$url] = true;
                }
            }

            $validGroupNames = array_column(
                $db->table('auth_groups')->select('name')->get()->getResultArray(),
                'name'
            );

            $sequences = [];
            $seenCandidates = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Format kandidat menu tidak valid.');
                }

                $routeMenuId = filter_var($item['route_menu_id'] ?? null, FILTER_VALIDATE_INT);
                $position    = (string) ($item['position'] ?? 'sidebar');
                $groupNamesInput = $item['groups'] ?? [];

                if ($routeMenuId === false || (int) $routeMenuId < 1 || isset($seenCandidates[(int) $routeMenuId])) {
                    throw new \InvalidArgumentException('ID kandidat menu tidak valid atau duplikat.');
                }

                $routeMenuId = (int) $routeMenuId;
                $seenCandidates[$routeMenuId] = true;

                if (!in_array($position, $allowedPositions, true)) {
                    throw new \InvalidArgumentException('Position menu tidak valid.');
                }

                if (!is_array($groupNamesInput) || !array_is_list($groupNamesInput)) {
                    throw new \InvalidArgumentException('Format grup akses tidak valid.');
                }

                $groupNames = array_values(array_unique(array_filter(
                    array_map(
                        static function ($name): string {
                            if (!is_string($name)) {
                                throw new \InvalidArgumentException('Nama grup akses tidak valid.');
                            }
                            return trim($name);
                        },
                        $groupNamesInput
                    ),
                    static fn (string $name): bool => $name !== ''
                )));

                foreach ($groupNames as $groupName) {
                    if (!in_array($groupName, $validGroupNames, true)) {
                        throw new \InvalidArgumentException("Grup akses '{$groupName}' tidak valid.");
                    }
                }

                $candidate = $db->table('route_registry_menus')
                    ->where('id', $routeMenuId)
                    ->get()
                    ->getRowArray();

                if (!$candidate) {
                    throw new \RuntimeException("Kandidat menu ID {$routeMenuId} tidak ditemukan.");
                }

                $title       = trim((string) $candidate['title']);
                $url         = trim((string) ($candidate['url'] ?? ''));
                $description = trim((string) ($candidate['description'] ?? ''));

                if ($title === '' || $url === '') {
                    throw new \RuntimeException("Judul atau URL kandidat ID {$routeMenuId} kosong.");
                }

                $urlKey = strtolower(trim($url, '/'));

                if (isset($existingUrls[$urlKey])) {
                    $skipped[] = ['title' => $title, 'url' => $url, 'reason' => 'URL sudah terdaftar.'];
                    continue;
                }

                $baseSlug = url_title($title, '-', true);
                if ($baseSlug === '') {
                    $baseSlug = 'menu-' . $routeMenuId;
                }

                $slug = $baseSlug;
                $suffix = 2;
                while ($db->table('menus')->where('slug', $slug)->countAllResults() > 0) {
                    $slug = $baseSlug . '-' . $suffix++;
                }

                $sequenceKey = $section . ':' . $position;
                if (!isset($sequences[$sequenceKey])) {
                    $row = $db->table('menus')
                        ->selectMax('sequence', 'max_sequence')
                        ->where('section', $section)
                        ->where('position', $position)
                        ->get()
                        ->getRowArray();
                    $sequences[$sequenceKey] = (int) ($row['max_sequence'] ?? 0);
                }
                $sequences[$sequenceKey]++;

                $menuData = [
                    'title'       => $title,
                    'slug'        => $slug,
                    'url'         => $url,
                    'icon'        => 'bi bi-circle',
                    'description' => $description,
                    'parent_id'   => 0,
                    'position'    => $position,
                    'section'     => $section,
                    'sequence'    => $sequences[$sequenceKey],
                    'is_active'   => 1,
                ];

                $menuId = $this->insert($menuData, true);
                if (!$menuId) {
                    throw new \RuntimeException('Gagal menyimpan menu: ' . implode(', ', $this->errors()));
                }

                if (!empty($groupNames)) {
                    $relations = [];
                    foreach ($groupNames as $groupName) {
                        $relations[] = ['menu_id' => (int) $menuId, 'group_name' => $groupName];
                    }
                    $db->table('menu_groups')->insertBatch($relations);
                }

                $existingUrls[$urlKey] = true;
                $inserted[] = ['id' => (int) $menuId, 'title' => $title, 'url' => $url];
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi sinkronisasi menu gagal.');
            }

            if ($db->transCommit() === false) {
                throw new \RuntimeException('Transaksi sinkronisasi menu gagal di-commit.');
            }

            return ['inserted' => $inserted, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
