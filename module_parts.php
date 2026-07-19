<?php
declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  物料管理模块（PartManager）                                         ║
 * ║  ─────────────────────────────────────────────────────────────────  ║
 * ║  职责：                                                              ║
 * ║    封装元件相关的全部业务逻辑：列表查询、详情、增删改、批量操作。    ║
 * ║  架构：                                                              ║
 * ║    - 本模块只负责数据处理，不输出 JSON / HTML（由入口文件负责）；    ║
 * ║    - 查询方法返回 array，修改方法返回 array 并通过 PartException      ║
 * ║      抛出校验错误，由入口文件决定 JSON 响应或重定向；                ║
 * ║    - 不持有全局状态，每次请求新建实例。                              ║
 * ║  依赖：config.php（getDB / getDataUserId / traceLog 等）            ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/config.php';

/**
 * 物料业务异常：携带错误码与消息，供入口文件区分 JSON 响应或重定向。
 */
final class PartException extends RuntimeException
{
    public function __construct(string $message, public int $errCode = 1)
    {
        parent::__construct($message);
    }
}

/**
 * 物料管理器：所有元件相关业务逻辑的唯一入口。
 */
final class PartManager
{
    private PDO $db;
    private int $uid;         // 操作用户 ID（用于日志记录）
    private int $dataUid;     // 数据归属用户 ID（子用户继承父用户）
    private int $globalThr;   // 全局低库存阈值

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db        = $db;
        $this->uid       = $uid;
        $this->dataUid   = $dataUid;
        $this->globalThr = getGlobalThreshold();
    }

    // ════════════════════════════════════════════════════════════════
    //  查询接口（GET）
    // ════════════════════════════════════════════════════════════════

    /**
     * 物料列表查询（支持搜索/筛选/分类/平台/库位/分页/排序）
     * @param array $p 查询参数（q,page,per_page,filter,cat,plat,loc,sort,dir）
     * @return array {parts, total, page, per_page, total_page, stats}
     */
    public function listParts(array $p): array
    {
        $q       = trim((string)($p['q'] ?? ''));
        $page    = max(1, intval($p['page'] ?? 1));
        $perPage = intval($p['per_page'] ?? $_COOKIE['per_page_index'] ?? 25);
        $perPage = max(10, min(50, $perPage));
        $filter  = (string)($p['filter'] ?? '');
        if (!in_array($filter, ['low', 'zero'], true)) $filter = '';
        $catParam = trim((string)($p['cat'] ?? ''));
        $noCat    = ($catParam === '-1');
        $catIds   = [];
        if ($catParam !== '' && $catParam !== '0' && !$noCat) {
            $catIds = array_filter(array_map('intval', explode(',', $catParam)), fn($v) => $v > 0);
        }
        $platId    = intval($p['plat'] ?? 0);
        $locFilter = trim((string)($p['loc'] ?? ''));
        $sortBy    = (string)($p['sort'] ?? 'update_time');
        $sortDir   = (($p['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

        $where = ["p.user_id=?"]; $params = [$this->dataUid];
        if ($q !== '') {
            $keywords = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            $synonyms = [
                '电阻'=>['resistor','电阻'], '电容'=>['capacitor','电容'], '电感'=>['inductor','电感'],
                '二极管'=>['diode','二极管'], '三极管'=>['transistor','三极管'], 'mos管'=>['mosfet','mos'],
                '芯片'=>['ic','chip','芯片'], '连接器'=>['connector','连接器'], '继电器'=>['relay','继电器'],
            ];
            foreach ($keywords as $kw) {
                $syns = [$kw]; $kwLower = mb_strtolower($kw, 'UTF-8');
                foreach ($synonyms as $cn => $enList) { $allForms = array_merge([$cn], $enList); foreach ($allForms as $form) { if (mb_strtolower($form,'UTF-8') === $kwLower) { $syns = $enList; break 2; } } }
                $kwClauses = [];
                foreach ($syns as $syn) { $like = "%$syn%"; $kwClauses[] = "(p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ? OR p.brand LIKE ? OR p.customer_part_no LIKE ?)"; array_push($params, $like, $like, $like, $like, $like); }
                $where[] = '(' . implode(' OR ', $kwClauses) . ')';
            }
        }
        if ($filter === 'low')  $where[] = "p.stock>0 AND p.stock<=COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$this->globalThr)";
        if ($filter === 'zero') $where[] = "p.stock=0";
        if ($platId > 0) { $where[] = "p.platform_id=?"; $params[] = $platId; }
        if ($locFilter !== '') { $where[] = "p.location=?"; $params[] = $locFilter; }

        $joinCat = '';
        if (!empty($catIds)) {
            $expandedCatIds = []; $topCatIds = []; $subCatIds = [];
            foreach ($catIds as $cid) {
                $chkStmt = $this->db->prepare("SELECT parent_id FROM categories WHERE id=? AND user_id=?");
                $chkStmt->execute([$cid, $this->dataUid]);
                $row = $chkStmt->fetch();
                if ($row && $row['parent_id'] === null) { $topCatIds[] = $cid; } else { $subCatIds[] = $cid; }
            }
            $expandedCatIds = $subCatIds;
            if (!empty($topCatIds)) {
                $topIn = implode(',', array_fill(0, count($topCatIds), '?'));
                $subStmt = $this->db->prepare("SELECT id FROM categories WHERE parent_id IN ($topIn) AND user_id=?");
                $subStmt->execute([...$topCatIds, $this->dataUid]);
                foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    if (!in_array($sid, $expandedCatIds)) $expandedCatIds[] = $sid;
                }
            }
            if (count($expandedCatIds) > 1) {
                $catPlaceholders = implode(',', array_fill(0, count($expandedCatIds), '?'));
                $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id IN ($catPlaceholders)";
                foreach ($expandedCatIds as $cid) array_unshift($params, $cid);
            } elseif (count($expandedCatIds) === 1) {
                $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id=?";
                array_unshift($params, $expandedCatIds[0]);
            }
            if (empty($expandedCatIds) && !empty($topCatIds)) {
                $topIn = implode(',', array_fill(0, count($topCatIds), '?'));
                $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id IN ($topIn)";
                foreach ($topCatIds as $cid) array_unshift($params, $cid);
            }
        } elseif ($noCat) {
            $joinCat = "LEFT JOIN part_categories pc2 ON pc2.part_id=p.id";
            $where[] = "pc2.part_id IS NULL";
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $cntStmt = $this->db->prepare("SELECT COUNT(DISTINCT p.id) FROM parts p $joinCat $whereSql");
        $cntStmt->execute($params); $total = (int)$cntStmt->fetchColumn();
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;

        $allowedSort = ['update_time','stock','model','platform_part_no','product_name','location'];
        $orderCol = in_array($sortBy, $allowedSort, true) ? $sortBy : 'update_time';
        $rowsStmt = $this->db->prepare("SELECT p.id,p.model,p.platform_part_no,p.product_name,p.product_type,p.package,p.brand,p.stock,p.damaged,p.location,p.customer_part_no,p.internal_id,p.update_time,p.platform_id,p.is_incomplete,pl.name AS pname,pl.url_template,pl.platform_type,COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$this->globalThr) AS eff_threshold FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id $joinCat $whereSql ORDER BY p.$orderCol $sortDir LIMIT $perPage OFFSET $offset");
        $rowsStmt->execute($params);
        $rows = $rowsStmt->fetchAll();

        $pids = array_column($rows, 'id');
        $catMap = [];
        if ($pids) {
            $in = implode(',', array_fill(0, count($pids), '?'));
            $cRes = $this->db->prepare("SELECT pc.part_id,c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id IN ($in)");
            $cRes->execute($pids);
            foreach ($cRes->fetchAll() as $c) $catMap[$c['part_id']][] = $c['name'];
        }

        $partsList = [];
        foreach ($rows as $r) {
            $partsList[] = [
                'id' => $r['id'], 'model' => $r['model'], 'ppn' => $r['platform_part_no'],
                'product_name' => $r['product_name'], 'product_type' => $r['product_type'],
                'package' => $r['package'], 'brand' => $r['brand'], 'stock' => (int)$r['stock'],
                'damaged' => (int)$r['damaged'], 'location' => $r['location'],
                'customer_part_no' => $r['customer_part_no'], 'internal_id' => (int)$r['internal_id'],
                'pname' => $r['pname'], 'platform_id' => (int)$r['platform_id'],
                'url_template' => $r['url_template'], 'platform_type' => $r['platform_type'],
                'eff_threshold' => $r['eff_threshold'],
                'is_incomplete' => (int)($r['is_incomplete'] ?? 0),
                'cats' => $catMap[$r['id']] ?? [],
                'update_time' => substr($r['update_time'], 0, 16),
            ];
        }

        $stats = $this->getStats();

        return [
            'parts' => $partsList,
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_page' => $totalPage,
            'stats' => $stats,
        ];
    }

    /**
     * 统计数据（总数 / 总库存 / 不良品 / 零库存 / 低库存 / 累计资产）
     */
    public function getStats(): array
    {
        $stats = $this->db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(stock),0) AS total_stock,COALESCE(SUM(damaged),0) AS total_damaged,SUM(CASE WHEN stock=0 THEN 1 ELSE 0 END) AS zero_count,SUM(CASE WHEN stock>0 AND stock<=COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),?) THEN 1 ELSE 0 END) AS low_count FROM parts p WHERE p.user_id=?");
        $stats->execute([$this->globalThr, $this->dataUid]); $s = $stats->fetch();
        $totalAsset = $this->db->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM stock_log WHERE user_id=? AND is_sample=0 AND subtotal>0");
        $totalAsset->execute([$this->dataUid]);
        return [
            'total'         => (int)$s['total'],
            'total_stock'   => (int)$s['total_stock'],
            'total_damaged' => (int)$s['total_damaged'],
            'zero_count'    => (int)$s['zero_count'],
            'low_count'     => (int)$s['low_count'],
            'total_asset'   => (float)$totalAsset->fetchColumn(),
        ];
    }

    /**
     * 分类列表（二级分类，按元件数降序，最多50条）
     */
    public function getCategories(): array
    {
        $allCats = $this->db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NOT NULL GROUP BY c.id ORDER BY cnt DESC LIMIT 50");
        $allCats->execute([$this->dataUid]);
        return ['categories' => $allCats->fetchAll()];
    }

    /**
     * 分类列表分页查询（AJAX 局部刷新用）
     *
     * 对齐 categories.php 主表格的分页查询逻辑：
     * - 查询全部二级分类（含元件数、阈值、parent_id），按元件数降序、名称升序
     * - 内存分页后返回当前页数据
     * - 附带一级大类映射（用于所属大类列显示）+ 库位分布（仅当前页分类）
     *
     * 用于 categories.php 主表格翻页 / 每页条数切换的 AJAX 局部刷新，
     * 不影响批量操作面板（面板数据由 PHP 直出，写操作后通过 catReload 整页刷新）。
     *
     * @return array{items:array, total:int, page:int, per_page:int, total_page:int, top_cats:array}
     */
    public function listCategoriesPaged(array $params): array
    {
        $perPage = intval($params['per_page'] ?? ($_COOKIE['per_page_categories'] ?? 25));
        $perPage = max(10, min(50, $perPage));
        $page    = max(1, intval($params['page'] ?? 1));

        // 全部二级分类（与 categories.php 顶部查询保持一致）
        $catsStmt = $this->db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NOT NULL GROUP BY c.id ORDER BY cnt DESC,c.name ASC");
        $catsStmt->execute([$this->dataUid]);
        $allCats = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 一级大类映射（用于所属大类列显示）
        $topStmt = $this->db->prepare("SELECT id,name FROM categories WHERE user_id=? AND parent_id IS NULL ORDER BY name ASC");
        $topStmt->execute([$this->dataUid]);
        $topCats = $topStmt->fetchAll(PDO::FETCH_ASSOC);
        $topCatMap = [];
        foreach ($topCats as $tc) { $topCatMap[(int)$tc['id']] = $tc['name']; }

        // 内存分页
        $total = count($allCats);
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;
        $pageCats = array_slice($allCats, $offset, $perPage);

        // 库位分布（仅查询当前页分类，避免拉取全表）
        $catLocations = [];
        if ($pageCats) {
            $catIds = array_column($pageCats, 'id');
            $inCats = implode(',', array_fill(0, count($catIds), '?'));
            $locStmt = $this->db->prepare("SELECT pc.category_id, p.location, COUNT(*) as loc_cnt FROM part_categories pc INNER JOIN parts p ON p.id=pc.part_id WHERE pc.category_id IN ($inCats) AND p.user_id=? AND p.location IS NOT NULL AND p.location<>'' GROUP BY pc.category_id, p.location ORDER BY pc.category_id, loc_cnt DESC");
            $locStmt->execute([...$catIds, $this->dataUid]);
            foreach ($locStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $cid = (int)$lr['category_id'];
                if (!isset($catLocations[$cid])) $catLocations[$cid] = [];
                $catLocations[$cid][] = [
                    'location' => (string)$lr['location'],
                    'loc_cnt'  => (int)$lr['loc_cnt'],
                ];
            }
        }

        // 类型规范化（前端无需再做类型转换）
        $items = array_map(function($c) use ($topCatMap, $catLocations) {
            $pid = (int)$c['parent_id'];
            return [
                'id'                  => (int)$c['id'],
                'name'                => (string)$c['name'],
                'parent_id'           => $pid,
                'parent_name'         => ($pid > 0 && isset($topCatMap[$pid])) ? (string)$topCatMap[$pid] : null,
                'low_stock_threshold' => $c['low_stock_threshold'] !== null ? (string)$c['low_stock_threshold'] : null,
                'cnt'                 => (int)$c['cnt'],
                'locations'           => $catLocations[(int)$c['id']] ?? [],
            ];
        }, $pageCats);

        // 全部二级分类（含 parent_id 和 cnt，供前端刷新功能面板复选框列表 / 下拉选项）
        // 与 categories.php 顶部 $cats 查询保持一致
        $allCatsList = array_map(function($c) use ($topCatMap) {
            $pid = (int)$c['parent_id'];
            return [
                'id'          => (int)$c['id'],
                'name'        => (string)$c['name'],
                'parent_id'   => $pid,
                'parent_name' => ($pid > 0 && isset($topCatMap[$pid])) ? (string)$topCatMap[$pid] : null,
                'cnt'         => (int)$c['cnt'],
            ];
        }, $allCats);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_page'  => $totalPage,
            'top_cats'    => $topCats,
            'all_cats'    => $allCatsList,
        ];
    }

    /**
     * 物料详情摘要（累计资产 + 最新采购单价）
     */
    public function getPartDetail(int $pid): array
    {
        if ($pid <= 0) throw new PartException('参数错误', 4);
        return $this->getCostSummary($pid);
    }

    /**
     * 编辑弹窗完整字段查询（统一数据源）
     *
     * 用于新增/编辑/批量操作后，编辑弹窗拉取最新物料完整字段回填表单，
     * 确保所有操作入口读写同一张物料数据表，杜绝多入口数据割裂。
     *
     * @return array {part, platform_type, linked_part, cat_ids, cats, alt_parts}
     */
    public function getPartEditData(int $id): array
    {
        if ($id <= 0) throw new PartException('参数错误', 4);

        $stmt = $this->db->prepare("SELECT p.id,p.platform_id,p.platform_part_no,p.customer_part_no,p.model,p.brand,p.product_name,p.product_type,p.package,p.location,p.low_stock_threshold,p.remark,p.purchase_url,p.linked_part_id,p.alternatives,p.stock,p.damaged,p.internal_id,pl.platform_type FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id=? AND p.user_id=?");
        $stmt->execute([$id, $this->dataUid]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new PartException('元件不存在', 404);

        // 关联标准物料（用于搜索框回显）
        $linkedPart = null;
        if (!empty($part['linked_part_id'])) {
            $lpStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name FROM parts WHERE id=? AND user_id=?");
            $lpStmt->execute([$part['linked_part_id'], $this->dataUid]);
            $linkedPart = $lpStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 分类ID列表（用于分类下拉框回显选中状态）
        $catStmt = $this->db->prepare("SELECT c.id, c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
        $catStmt->execute([$id]);
        $catRows = $catStmt->fetchAll();
        $catIds = array_map(fn($r) => (int)$r['id'], $catRows);
        $cats   = array_map(fn($r) => $r['name'], $catRows);

        // 替代料列表（用于替代料板块回显）
        $altParts = [];
        if (!empty($part['alternatives'])) {
            $altIds = array_filter(array_map('intval', explode(',', $part['alternatives'])));
            if (!empty($altIds)) {
                $in = implode(',', array_fill(0, count($altIds), '?'));
                $altStmt = $this->db->prepare("SELECT id, internal_id, model, platform_part_no, product_name, stock FROM parts WHERE id IN ($in) AND user_id=?");
                $altStmt->execute([...$altIds, $this->dataUid]);
                $altParts = $altStmt->fetchAll();
            }
        }

        return [
            'part'         => $part,
            'platform_type'=> $part['platform_type'] ?? 'standard',
            'linked_part'  => $linkedPart,
            'cat_ids'      => $catIds,
            'cats'         => $cats,
            'alt_parts'    => $altParts,
        ];
    }

    /**
     * 成本摘要（累计资产 = SUM(subtotal) 排除样品；最新采购含税单价）
     */
    public function getCostSummary(int $pid): array
    {
        if ($pid <= 0) throw new PartException('参数错误', 4);
        $tStmt = $this->db->prepare("SELECT COALESCE(SUM(subtotal), 0) AS total FROM stock_log WHERE part_id=? AND is_sample=0 AND subtotal>0");
        $tStmt->execute([$pid]);
        $totalAsset = (float)$tStmt->fetchColumn();
        $lStmt = $this->db->prepare("SELECT unit_cost FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time DESC LIMIT 1");
        $lStmt->execute([$pid]);
        $row = $lStmt->fetch();
        return [
            'total_asset' => $totalAsset,
            'latest_cost' => $row ? (float)$row['unit_cost'] : 0,
        ];
    }

    /**
     * 替代料编号批量查询（按 part_id 列表）
     * @param int[] $ids
     */
    public function altLookup(array $ids): array
    {
        $result = [];
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, stock, purchase_url FROM parts WHERE id IN ($in) AND user_id=?");
            $stmt->execute([...$ids, $this->dataUid]);
            $found = [];
            foreach ($stmt->fetchAll() as $p) $found[$p['id']] = $p;
            foreach ($ids as $pid) {
                if (isset($found[$pid])) {
                    $p = $found[$pid];
                    $result[] = ['id' => (int)$pid, 'internal_id' => (int)$p['internal_id'], 'no' => $p['platform_part_no'], 'name' => $p['model'] ?: $p['product_name'], 'stock' => (int)$p['stock'], 'purchase_url' => $p['purchase_url'] ?? '', 'found' => true];
                } else {
                    $result[] = ['id' => (int)$pid, 'internal_id' => 0, 'no' => '', 'name' => '', 'stock' => 0, 'purchase_url' => '', 'found' => false];
                }
            }
        }
        return ['items' => $result];
    }

    /**
     * 替代料搜索（按关键词模糊匹配型号/编号/内部ID）
     */
    public function altSearch(string $q): array
    {
        $result = [];
        $q = trim($q);
        if ($q !== '') {
            // 支持 #数字 精确匹配 internal_id（如 #123 匹配 internal_id=123 的物料）
            if (preg_match('/^#(\d+)$/', $q, $m)) {
                $intId = (int)$m[1];
                $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, brand, package, stock, location, remark, purchase_url FROM parts WHERE user_id=? AND internal_id=? ORDER BY model LIMIT 20");
                $stmt->execute([$this->dataUid, $intId]);
            } else {
                $like = '%' . $q . '%';
                $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, brand, package, stock, location, remark, purchase_url FROM parts WHERE user_id=? AND (model LIKE ? OR platform_part_no LIKE ? OR internal_id LIKE ? OR product_name LIKE ? OR remark LIKE ?) ORDER BY model LIMIT 20");
                $stmt->execute([$this->dataUid, $like, $like, $like, $like, $like]);
            }
            foreach ($stmt->fetchAll() as $p) {
                $result[] = [
                    'id'              => (int)$p['id'],
                    'internal_id'     => (int)$p['internal_id'],
                    'no'              => (string)$p['platform_part_no'],
                    'model'           => (string)$p['model'],
                    'product_name'    => (string)$p['product_name'],
                    'brand'           => (string)$p['brand'],
                    'package'         => (string)$p['package'],
                    'stock'           => (int)$p['stock'],
                    'location'        => (string)$p['location'],
                    'remark'          => (string)$p['remark'],
                    'purchase_url'    => (string)($p['purchase_url'] ?? ''),
                ];
            }
        }
        return ['items' => $result];
    }

    /**
     * BOM 替代料绑定弹窗专用：全物料分页模糊搜索
     * 匹配字段（无优先级差异）：internal_id、model、product_name、remark、platform_part_no
     * 强制分页限制单次返回条数，规避万级物料加载卡顿。
     *
     * @param string $q      关键词
     * @param int    $page   页码（>=1）
     * @param int    $perPage 每页条数（默认 15，最大 50）
     * @return array{list:array, page:int, per_page:int, total:int, total_page:int, has_more:bool}
     */
    public function searchPartsPaged(string $q, int $page = 1, int $perPage = 15): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        $where = ["user_id=?", "is_incomplete=0"];
        $params = [$this->dataUid];

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $q . '%';
            // 支持 #数字 精确匹配 internal_id
            if (preg_match('/^#(\d+)$/', $q, $m)) {
                $where[] = "(internal_id=? OR model LIKE ? OR product_name LIKE ? OR remark LIKE ? OR platform_part_no LIKE ?)";
                array_push($params, (int)$m[1], $like, $like, $like, $like);
            } else {
                // 纯数字关键词额外匹配 internal_id 精确（非优先，仅作为 OR 条件之一）
                if (ctype_digit($q)) {
                    $where[] = "(model LIKE ? OR product_name LIKE ? OR remark LIKE ? OR platform_part_no LIKE ? OR internal_id=?)";
                    array_push($params, $like, $like, $like, $like, (int)$q);
                } else {
                    $where[] = "(model LIKE ? OR product_name LIKE ? OR remark LIKE ? OR platform_part_no LIKE ?)";
                    array_push($params, $like, $like, $like, $like);
                }
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts $whereSql");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;

        $rowsStmt = $this->db->prepare("SELECT id, internal_id, platform_id, platform_part_no, customer_part_no, model, product_name, brand, package, product_type, stock, location, remark FROM parts $whereSql ORDER BY update_time DESC LIMIT $perPage OFFSET $offset");
        $rowsStmt->execute($params);
        $rows = $rowsStmt->fetchAll();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'              => (int)$r['id'],
                'internal_id'     => (int)$r['internal_id'],
                'platform_id'     => (int)$r['platform_id'],
                'platform_part_no'=> (string)$r['platform_part_no'],
                'customer_part_no'=> (string)$r['customer_part_no'],
                'model'           => (string)$r['model'],
                'product_name'    => (string)$r['product_name'],
                'brand'           => (string)$r['brand'],
                'package'         => (string)$r['package'],
                'product_type'    => (string)$r['product_type'],
                'stock'           => (int)$r['stock'],
                'location'        => (string)$r['location'],
                'remark'          => (string)$r['remark'],
            ];
        }
        return [
            'list'        => $list,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_page'  => $totalPage,
            'has_more'    => $page < $totalPage,
        ];
    }

    /**
     * 替代料差异化推荐：基于「分类/封装/电气参数/型号」四维加权打分模型。
     * 权重优先级：分类40% ＞ 封装35% ＞ 电气参数20% ＞ 型号5%
     *
     * 实现规则：
     *   1. 残缺物料（封装/分类为空）直接返回 hint，不参与打分；
     *   2. 前置过滤候选集：仅同一级分类下非残缺物料，≤100 条，禁止全库遍历；
     *   3. 四维打分（详见 scoreCategory/scorePackage/scoreElectricalParams/scoreModel）；
     *   4. 综合总分降序排列，≥30 分参与顶部推荐，＜30 分后置；
     *   5. 同分二次排序：电气参数 → 封装 → 分类。
     *
     * @return array{list:array, count:int, hint:string}
     */
    public function suggestAlternatives(string $model, string $package, string $category, int $excludeId = 0, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $model = trim($model);
        $package = trim($package);
        $category = trim($category);

        // 残缺物料检测：封装或分类为空 → 关闭自动匹配
        if ($package === '' || $category === '') {
            return ['list' => [], 'count' => 0, 'hint' => '物料信息不全，无法自动推荐，请手动搜索'];
        }

        // 解析当前物料的 L1/L2 分类层级
        $curL1 = null; $curL2 = null;
        if ($excludeId > 0) {
            $curCats = $this->getPartCategoriesWithHierarchy($excludeId);
            foreach ($curCats as $c) {
                if ($c['parent_id'] > 0) {
                    if ($curL2 === null) { $curL2 = $c; $curL1 = $curCats[$c['parent_id']] ?? null; }
                } elseif ($c['name'] === $category && $curL1 === null) {
                    $curL1 = $c;
                }
            }
        }
        // 退化：按 category 名查询 categories 表
        if ($curL1 === null) {
            $findStmt = $this->db->prepare("SELECT id, parent_id, name FROM categories WHERE user_id=? AND name=? LIMIT 1");
            $findStmt->execute([$this->dataUid, $category]);
            $catRow = $findStmt->fetch(PDO::FETCH_ASSOC);
            if ($catRow) {
                if ((int)$catRow['parent_id'] > 0) {
                    $curL2 = ['id' => (int)$catRow['id'], 'parent_id' => (int)$catRow['parent_id'], 'name' => $catRow['name']];
                    $pStmt = $this->db->prepare("SELECT id, parent_id, name FROM categories WHERE id=? AND user_id=? LIMIT 1");
                    $pStmt->execute([(int)$catRow['parent_id'], $this->dataUid]);
                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) $curL1 = ['id' => (int)$pRow['id'], 'parent_id' => 0, 'name' => $pRow['name']];
                } else {
                    $curL1 = ['id' => (int)$catRow['id'], 'parent_id' => 0, 'name' => $catRow['name']];
                }
            }
        }
        if ($curL1 === null) {
            return ['list' => [], 'count' => 0, 'hint' => '物料信息不全，无法自动推荐，请手动搜索'];
        }

        // 前置过滤候选集：同一级分类（含其下属 L2 子分类）下的非残缺物料，≤100 条
        $candidates = $this->getCandidatesByL1Category($curL1['id'], $excludeId, 100);
        if (empty($candidates)) {
            return ['list' => [], 'count' => 0, 'hint' => ''];
        }

        // 批量获取候选物料的分类层级（避免 N+1）
        $candCatsMap = $this->batchGetCategoriesWithHierarchy(array_column($candidates, 'id'));

        // 预处理当前物料字段（步骤1：全字段文本统一净化）
        $curNormPkg    = $this->normalizeText($package);
        $curPkgType    = $this->detectPackageType($package);
        $curPkgDigits  = $this->extractPackageDigits($package);
        $curNormModel  = $this->normalizeText($model);
        $curParams     = $this->extractElectricalParams($model . ' ' . $package);

        // 四维打分
        $scored = [];
        foreach ($candidates as $r) {
            $pid = (int)$r['id'];
            $candCats = $candCatsMap[$pid] ?? [];
            $candL1 = null; $candL2 = null;
            foreach ($candCats as $c) {
                if ($c['parent_id'] > 0) {
                    if ($candL2 === null) {
                        $candL2 = $c;
                        $candL1 = $candCats[$c['parent_id']] ?? null;
                    }
                } elseif ($candL1 === null) {
                    $candL1 = $c;
                }
            }

            $sCat  = $this->scoreCategory($curL1, $curL2, $candL1, $candL2);
            $sPkg  = $this->scorePackage($package, $curNormPkg, $curPkgType, $curPkgDigits, (string)$r['package']);
            $candParams = $this->extractElectricalParams(((string)$r['model']) . ' ' . ((string)$r['product_name']) . ' ' . ((string)$r['package']));
            $sParam = $this->scoreElectricalParams($curParams, $candParams);
            $sModel = $this->scoreModel($curNormModel, $this->normalizeText((string)$r['model']));

            // 综合总分 = 分类×40% + 封装×35% + 电气参数×20% + 型号×5%
            $total = $sCat * 0.40 + $sPkg * 0.35 + $sParam * 0.20 + $sModel * 0.05;
            $scored[] = [
                'row'    => $r,
                'total'  => $total,
                'sCat'   => $sCat,
                'sPkg'   => $sPkg,
                'sParam' => $sParam,
                'sModel' => $sModel,
            ];
        }

        // 排序：综合总分降序 → 同分按电气参数 → 封装 → 分类
        usort($scored, function ($a, $b) {
            if (abs($b['total'] - $a['total']) > 0.001) return $b['total'] <=> $a['total'];
            if ($b['sParam'] !== $a['sParam']) return $b['sParam'] <=> $a['sParam'];
            if ($b['sPkg']   !== $a['sPkg'])   return $b['sPkg']   <=> $a['sPkg'];
            return $b['sCat'] <=> $a['sCat'];
        });

        // 阈值过滤：≥30 分参与顶部推荐区，＜30 分后置
        $recommended = []; $others = [];
        foreach ($scored as $s) {
            if ($s['total'] >= 30) $recommended[] = $s;
            else $others[] = $s;
        }
        $final = array_merge($recommended, $others);
        $top = array_slice($final, 0, $limit);

        $list = [];
        foreach ($top as $s) {
            $r = $s['row'];
            $list[] = [
                'id'              => (int)$r['id'],
                'internal_id'     => (int)$r['internal_id'],
                'platform_id'     => (int)$r['platform_id'],
                'platform_part_no'=> (string)$r['platform_part_no'],
                'customer_part_no'=> (string)$r['customer_part_no'],
                'model'           => (string)$r['model'],
                'product_name'    => (string)$r['product_name'],
                'brand'           => (string)$r['brand'],
                'package'         => (string)$r['package'],
                'product_type'    => (string)$r['product_type'],
                'stock'           => (int)$r['stock'],
                'location'        => (string)$r['location'],
                'remark'          => (string)$r['remark'],
                'recommended'     => ($s['total'] >= 30) ? 1 : 0,
            ];
        }
        return ['list' => $list, 'count' => count($list), 'hint' => ''];
    }

    // ════════════════════════════════════════════════════════════════
    //  替代料匹配引擎私有辅助方法（步骤1预处理 + 步骤2前置过滤 + 步骤3四维打分）
    // ════════════════════════════════════════════════════════════════

    /**
     * 步骤1：文本统一预处理。
     * 清除无意义符号（空格/-/_///()[]{}等）+ 英文字母统一转大写。
     */
    private function normalizeText(string $s): string
    {
        if ($s === '') return '';
        $s = preg_replace('/[\s\-_\/\(\)\[\]\{\}<>.,;:|\\\\\'"]+/', '', $s);
        return mb_strtoupper($s, 'UTF-8');
    }

    /**
     * 步骤1：从文本中正则提取结构化电气参数（数字+单位组合）。
     * 支持：10uF、100nF、47pF、100kΩ、10kΩ、1MΩ、2.2uH、10uH、50V、16MHz 等。
     * 返回规范化参数标识数组（已去重）。
     */
    private function extractElectricalParams(string $s): array
    {
        if ($s === '') return [];
        $s = mb_strtoupper($s, 'UTF-8');
        // 统一微号符号（μ/Μ/µ → U）
        $s = str_replace(['μ', 'Μ', 'µ'], 'U', $s);
        // 匹配数字（可带小数）+ 可选前缀(u/n/p/m/k/M/G) + 单位(F/H/OHM/R/V/W/A/HZ)
        preg_match_all('/(\d+(?:\.\d+)?)\s*([UMNPKMG]?)(F|H|OHM|R|V|W|A|HZ)\b/', $s, $m, PREG_SET_ORDER);
        $params = [];
        foreach ($m as $match) {
            $val = $match[1];
            $prefix = $match[2];
            $unit = $match[3];
            if ($unit === 'R') $unit = 'OHM'; // R 视为电阻 OHM
            $params[] = $val . $prefix . $unit;
        }
        return array_values(array_unique($params));
    }

    /**
     * 步骤1：封装字段自动分流判断。
     * 返回 'smd_size'（贴片尺寸类）或 'irregular'（无尺寸异型封装）。
     * 贴片类识别：4位标准尺寸数字（0402/0603/0805等）或带数字的标准封装（SOT23/SOP8/SOIC8/QFN32/TO220等）。
     */
    private function detectPackageType(string $pkg): string
    {
        if ($pkg === '') return 'unknown';
        $norm = $this->normalizeText($pkg);
        if ($norm === '') return 'irregular';
        // 4 位标准贴片尺寸（0402/0603/0805/1206 等）
        if (preg_match('/\d{4}/', $norm)) return 'smd_size';
        // 标准封装型号 + 数字（SOT/SOP/SOIC/QFN/QFP/BGA/TSSOP/MSOP/DFN/TSOP/LQFP/TQFP/TO 等）
        if (preg_match('/(SOT|SOP|SOIC|QFN|QFP|BGA|TSSOP|MSOP|DFN|TSOP|LQFP|TQFP|TO)\d+/', $norm)) return 'smd_size';
        return 'irregular';
    }

    /**
     * 步骤1：提取贴片封装的核心尺寸数字。
     * 0402 → "0402"；SOT-23 → "23"；SOP8 → "8"；HLH_C0402 → "0402"。
     */
    private function extractPackageDigits(string $pkg): string
    {
        if ($pkg === '') return '';
        $norm = $this->normalizeText($pkg);
        if ($norm === '') return '';
        // 优先匹配 4 位标准尺寸
        if (preg_match('/(\d{4})/', $norm, $m)) return $m[1];
        // 次选字母封装后的数字
        if (preg_match('/[A-Z]+(\d+)/', $norm, $m)) return $m[1];
        // 退化匹配首个数字串
        if (preg_match('/(\d+)/', $norm, $m)) return $m[1];
        return '';
    }

    /**
     * 步骤3-分支B：简单分词，按字母/数字边界切分。
     */
    private function tokenize(string $s): array
    {
        if ($s === '') return [];
        preg_match_all('/[A-Z]+|\d+/', $s, $m);
        return $m[0] ?: [];
    }

    /**
     * 步骤3-分支B：TokenSetRatio 分词交集相似度（Jaccard 变体）。
     * 返回 0.0~1.0 的浮点数。
     */
    private function tokenSetRatio(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $setA = $this->tokenize($a);
        $setB = $this->tokenize($b);
        if (empty($setA) || empty($setB)) {
            // 退化：直接字符级 similar_text
            similar_text($a, $b, $pct);
            return $pct / 100.0;
        }
        $intersect = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));
        return $union > 0 ? ($intersect / $union) : 0.0;
    }

    /**
     * 步骤3-维度4：PartialRatio 局部相似度。
     * 取短串在长串中最优匹配位置的相似度（0.0~1.0）。
     */
    private function partialRatio(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $short = (mb_strlen($a) <= mb_strlen($b)) ? $a : $b;
        $long  = (mb_strlen($a) <= mb_strlen($b)) ? $b : $a;
        $shortLen = mb_strlen($short);
        $longLen  = mb_strlen($long);
        if ($shortLen === 0 || $shortLen > $longLen) return 0.0;
        $best = 0.0;
        for ($i = 0; $i <= $longLen - $shortLen; $i++) {
            $sub = mb_substr($long, $i, $shortLen);
            similar_text($short, $sub, $pct);
            if ($pct > $best) $best = $pct;
            if ($best >= 100.0) break;
        }
        return $best / 100.0;
    }

    /**
     * 步骤3-维度4：型号字符串相似度（Levenshtein + PartialRatio 综合，0~60 分）。
     */
    private function scoreModel(string $a, string $b): int
    {
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 60;
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) return 0;
        $dist = levenshtein($a, $b);
        $levSim = 1.0 - ($dist / $maxLen);
        $partial = $this->partialRatio($a, $b);
        $sim = max($levSim, $partial);
        return (int)round($sim * 60);
    }

    /**
     * 步骤2：获取物料关联的所有分类（含 L1/L2 层级）。
     * @return array<int, array{id:int, parent_id:int, name:string}> 以 category_id 为键
     */
    private function getPartCategoriesWithHierarchy(int $partId): array
    {
        if ($partId <= 0) return [];
        $stmt = $this->db->prepare("SELECT c.id, c.parent_id, c.name FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
        $stmt->execute([$partId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            $result[$cid] = [
                'id'        => $cid,
                'parent_id' => (int)($row['parent_id'] ?? 0),
                'name'      => (string)$row['name'],
            ];
        }
        return $result;
    }

    /**
     * 步骤2：批量获取多个物料的分类层级（避免 N+1 查询）。
     * @return array<int, array<int, array{id:int, parent_id:int, name:string}>>
     */
    private function batchGetCategoriesWithHierarchy(array $partIds): array
    {
        $result = [];
        $partIds = array_filter(array_map('intval', $partIds), fn($v) => $v > 0);
        if (empty($partIds)) return $result;
        $ph = implode(',', array_fill(0, count($partIds), '?'));
        $sql = "SELECT pc.part_id, c.id, c.parent_id, c.name FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id IN ($ph)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($partIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['part_id'];
            if (!isset($result[$pid])) $result[$pid] = [];
            $cid = (int)$row['id'];
            $result[$pid][$cid] = [
                'id'        => $cid,
                'parent_id' => (int)($row['parent_id'] ?? 0),
                'name'      => (string)$row['name'],
            ];
        }
        return $result;
    }

    /**
     * 步骤2：按 L1 分类前置过滤候选物料（性能约束 ≤100 条）。
     * 仅查询同一级分类（含其下属 L2 子分类）下的所有非残缺物料。
     */
    private function getCandidatesByL1Category(int $l1CatId, int $excludeId, int $limit): array
    {
        if ($l1CatId <= 0) return [];
        $limit = max(1, min(100, $limit));
        $sql = "SELECT p.id, p.internal_id, p.platform_id, p.platform_part_no, p.customer_part_no, p.model, p.product_name, p.brand, p.package, p.product_type, p.stock, p.location, p.remark "
             . "FROM parts p INNER JOIN part_categories pc ON pc.part_id=p.id "
             . "INNER JOIN categories c ON c.id=pc.category_id "
             . "WHERE p.user_id=? AND p.is_incomplete=0 AND (c.id=? OR c.parent_id=?)";
        $params = [$this->dataUid, $l1CatId, $l1CatId];
        if ($excludeId > 0) {
            $sql .= " AND p.id<>?";
            $params[] = $excludeId;
        }
        $sql .= " GROUP BY p.id LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 步骤3-维度1：物料分类打分（权重 40%，满分 100）。
     * - L2 完全一致：100 分
     * - L1 相同、L2 近似（同品类细分）：60 分
     * - L1 不同：0 分
     */
    private function scoreCategory(?array $curL1, ?array $curL2, ?array $candL1, ?array $candL2): int
    {
        if ($curL1 === null || $candL1 === null) return 0;
        if ($curL1['id'] !== $candL1['id']) return 0; // L1 不同
        if ($curL2 !== null && $candL2 !== null && $curL2['id'] === $candL2['id']) return 100; // L2 完全一致
        return 60; // L1 相同、L2 近似或未指定
    }

    /**
     * 步骤3-维度2：封装打分（权重 35%，满分 100，双分支兼容所有封装格式）。
     * 分支A（贴片类）：尺寸数字完全吻合 100 分；带前后缀 90 分；不匹配 0 分。
     * 分支B（无尺寸异型）：核心关键字完全重合 90 分；重合度≥60% 动态 40~70 分；无重合 0 分。
     */
    private function scorePackage(string $curPkg, string $curNormPkg, string $curPkgType, string $curPkgDigits, string $candPkg): int
    {
        if ($curPkg === '' || $candPkg === '') return 0;
        $candNorm = $this->normalizeText($candPkg);
        if ($curNormPkg === '' || $candNorm === '') return 0;

        if ($curPkgType === 'smd_size') {
            // 分支A：贴片类封装
            $candDigits = $this->extractPackageDigits($candPkg);
            if ($candDigits === '' || $curPkgDigits === '') return 0;
            if ($candDigits !== $curPkgDigits) return 0; // 尺寸数字不匹配
            if ($curNormPkg === $candNorm) return 100; // 完全吻合
            return 90; // 核心尺寸一致，带厂商自定义前后缀
        }

        // 分支B：无尺寸异型封装（TokenSetRatio 分词交集）
        $ratio = $this->tokenSetRatio($curNormPkg, $candNorm);
        if ($ratio >= 1.0) return 90; // 核心关键字完全重合
        if ($ratio >= 0.6) {
            // 60%~100% → 40~70 分
            return (int)round(40 + ($ratio - 0.6) / 0.4 * 30);
        }
        return 0;
    }

    /**
     * 步骤3-维度3：电气参数打分（权重 20%，满分 100）。
     * - 全部电气参数一致：90 分
     * - 任意核心参数不匹配：0 分
     * - 当前物料无电气参数：中性 50 分（不参与强筛选）
     */
    private function scoreElectricalParams(array $curParams, array $candParams): int
    {
        if (empty($curParams)) return 50; // 无参数可比，中性分
        if (empty($candParams)) return 0;
        foreach ($curParams as $p) {
            if (!in_array($p, $candParams, true)) return 0;
        }
        return 90;
    }

    /**
     * 物料完整详情数据（供 detail_ajax.php 渲染抽屉使用）
     * 返回所有数据，HTML 渲染由入口文件完成（保持 UI 不变）。
     * @throws PartException 物料不存在
     */
    public function getPartFullData(int $id): array
    {
        if ($id <= 0) throw new PartException('参数错误', 4);

        $partStmt = $this->db->prepare("SELECT p.*,pl.name AS pname,pl.url_template,COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$this->globalThr) AS eff_threshold FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id=? AND p.user_id=?");
        $partStmt->execute([$id, $this->dataUid]);
        $part = $partStmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new PartException('元件不存在', 404);

        // 平台类型
        $platType = 'standard';
        try {
            $ptStmt = $this->db->prepare("SELECT platform_type FROM platforms WHERE id=?");
            $ptStmt->execute([$part['platform_id']]);
            $ptRow = $ptStmt->fetch();
            if ($ptRow && in_array($ptRow['platform_type'] ?? '', ['standard','loose'], true)) {
                $platType = $ptRow['platform_type'];
            }
        } catch (Throwable) {}

        // 关联标准物料
        $linkedPart = null;
        if (!empty($part['linked_part_id'])) {
            $lpStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name FROM parts WHERE id=? AND user_id=?");
            $lpStmt->execute([$part['linked_part_id'], $this->dataUid]);
            $linkedPart = $lpStmt->fetch(PDO::FETCH_ASSOC);
        }

        // 散料采购渠道
        $bulkStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name, stock, purchase_url FROM parts WHERE linked_part_id=? AND user_id=?");
        $bulkStmt->execute([$id, $this->dataUid]);
        $bulkParts = $bulkStmt->fetchAll();

        // 替代料
        $altParts = [];
        if (!empty($part['alternatives'])) {
            $altIds = array_filter(array_map('intval', explode(',', $part['alternatives'])));
            if (!empty($altIds)) {
                $in = implode(',', array_fill(0, count($altIds), '?'));
                $altStmt = $this->db->prepare("SELECT id, platform_id, internal_id, model, platform_part_no, product_name, stock, purchase_url FROM parts WHERE id IN ($in) AND user_id=?");
                $altStmt->execute([...$altIds, $this->dataUid]);
                $altParts = $altStmt->fetchAll();
            }
        }

        // 分类
        $catsStmt = $this->db->prepare("SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
        $catsStmt->execute([$id]);
        $cats = array_column($catsStmt->fetchAll(), 'name');

        // 采购历史（数据源：stock_log）
        $pricesStmt = $this->db->prepare("SELECT unit_cost AS unit_price, qty_change AS qty, order_time, create_time, remark FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time ASC");
        $pricesStmt->execute([$id]);
        $prices = $pricesStmt->fetchAll();

        // 库存历史
        $stockHistStmt = $this->db->prepare("SELECT qty_after,create_time FROM stock_log WHERE part_id=? ORDER BY create_time ASC");
        $stockHistStmt->execute([$id]);
        $stockHist = $stockHistStmt->fetchAll();

        // 成本折线数据
        $costHistStmt = $this->db->prepare("SELECT qty_after,unit_cost,create_time,is_sample FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time ASC");
        $costHistStmt->execute([$id]);
        $costHist = $costHistStmt->fetchAll();

        // 出入库记录（最近5条）
        $logLimit = 5;
        $logStmt = $this->db->prepare("SELECT * FROM stock_log WHERE part_id=? ORDER BY create_time DESC LIMIT ?");
        $logStmt->execute([$id, $logLimit]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM stock_log WHERE part_id=?");
        $cntStmt->execute([$id]); $logTotal = (int)$cntStmt->fetchColumn();

        // 资产成本
        $cost = $this->getCostSummary($id);

        // 图表数据
        $stockData = count($stockHist) >= 2 ? [
            'labels' => array_map(fn($r) => substr($r['create_time'], 0, 10), $stockHist),
            'values' => array_map(fn($r) => (int)$r['qty_after'], $stockHist),
        ] : null;
        $priceData = count($prices) >= 2 ? [
            'labels' => array_map(fn($r) => substr(($r['order_time'] ?? null) ?: ($r['create_time'] ?? ''), 0, 10), $prices),
            'values' => array_map(fn($r) => (float)$r['unit_price'], $prices),
        ] : null;
        $costData = count($costHist) >= 2 ? [
            'labels'     => array_map(fn($r) => substr($r['create_time'], 0, 10), $costHist),
            'qty_values' => array_map(fn($r) => (int)$r['qty_after'], $costHist),
            'cost_values'=> array_map(fn($r) => (float)$r['unit_cost'], $costHist),
        ] : null;

        return [
            'part'        => $part,
            'platType'    => $platType,
            'linkedPart'  => $linkedPart,
            'bulkParts'   => $bulkParts,
            'altParts'    => $altParts,
            'cats'        => $cats,
            'prices'      => $prices,
            'stockHist'   => $stockHist,
            'costHist'    => $costHist,
            'logs'        => $logs,
            'logTotal'    => $logTotal,
            'totalAsset'  => $cost['total_asset'],
            'latestCost'  => $cost['latest_cost'],
            'globalThr'   => $this->globalThr,
            'stockData'   => $stockData,
            'priceData'   => $priceData,
            'costData'    => $costData,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  修改接口（POST）
    // ════════════════════════════════════════════════════════════════

    /**
     * 添加元件
     * @return array {part_id}
     * @throws PartException 校验失败
     */
    public function addPart(array $p): array
    {
        $ppn       = trim((string)($p['platform_part_no'] ?? ''));
        $platId    = intval($p['platform_id'] ?? 1);
        $stock     = intval($p['stock'] ?? 0);
        $thrRaw    = trim((string)($p['low_stock_threshold'] ?? ''));
        $thrVal    = $thrRaw === '' ? null : max(0, (int)$thrRaw);
        $purchaseUrl = trim((string)($p['purchase_url'] ?? ''));
        $linkedPartIdRaw = trim((string)($p['linked_part_id'] ?? ''));
        $linkedPartId = $linkedPartIdRaw !== '' ? intval($linkedPartIdRaw) : null;
        $unitCost  = round((float)($p['unit_cost'] ?? 0), 4);
        $isSample  = !empty($p['is_sample']) ? 1 : 0;

        // 平台类型联动校验
        $currentPlatType = $this->getPlatType($platId);
        if ($currentPlatType === 'standard') {
            $purchaseUrl = '';
            $linkedPartId = null;
            if ($ppn === '') throw new PartException('标准商城平台必须填写商品编号', 2);
        } else {
            if ($purchaseUrl === '') throw new PartException('散货渠道平台必须填写采购链接', 2);
            if (!preg_match('#^https?://#i', $purchaseUrl)) throw new PartException('采购链接必须以 http:// 或 https:// 开头', 2);
        }

        // 验证 linked_part_id
        if ($linkedPartId !== null && $linkedPartId > 0) {
            $linkCheck = $this->db->prepare("SELECT id FROM parts WHERE id=? AND user_id=?");
            $linkCheck->execute([$linkedPartId, $this->dataUid]);
            if (!$linkCheck->fetch()) $linkedPartId = null;
        } else {
            $linkedPartId = null;
        }

        $ppnInsert = $ppn !== '' ? $ppn : null;

        // 生成全平台唯一的 internal_id（带重试机制，防止并发冲突）
        $retry = 0;
        $newId = 0;
        $nextInternalId = 0; // 预初始化（循环必定执行，此处仅为消除静态分析误报）
        while ($retry < 3) {
            $maxIdStmt = $this->db->prepare("SELECT COALESCE(MAX(internal_id),0) FROM parts WHERE user_id=?");
            $maxIdStmt->execute([$this->dataUid]);
            $nextInternalId = (int)$maxIdStmt->fetchColumn() + 1;
            try {
                $this->db->prepare("INSERT INTO parts (user_id,platform_id,internal_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,stock,low_stock_threshold,location,remark,purchase_url,linked_part_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$this->dataUid,$platId,$nextInternalId,$ppnInsert,trim((string)($p['customer_part_no']??'')),trim((string)($p['model']??'')),
                     trim((string)($p['product_name']??'')),trim((string)($p['product_type']??'')),trim((string)($p['package']??'')),
                     trim((string)($p['brand']??'')),$stock,$thrVal,
                     trim((string)($p['location']??'')),trim((string)($p['remark']??'')),$purchaseUrl,$linkedPartId]);
                $newId = (int)$this->db->lastInsertId();
                break;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate entry') !== false) {
                    // 区分错误类型：internal_id 重复 → 重试；platform_part_no 重复 → 报错
                    if (strpos($msg, 'internal_id') !== false || strpos($msg, 'uq_user_internal') !== false || strpos($msg, 'uq_user_platform_internal') !== false) {
                        $retry++;
                        continue;
                    }
                    throw new PartException('该平台下商品编号已存在', 2);
                }
                throw $e;
            }
        }
        if ($newId === 0) throw new PartException('内部ID生成失败，请重试', 3);
        $pid = $newId;

        // 散货渠道：商品编号缺失时用 #内部ID内部 格式作为编号显示（便于区分自动生成编号）
        $finalPpn = $ppn;
        if ($ppn === '' && $nextInternalId > 0) {
            $finalPpn = '#' . $nextInternalId . '内部';
            $this->db->prepare("UPDATE parts SET platform_part_no=? WHERE id=? AND user_id=?")
               ->execute([$finalPpn, $pid, $this->dataUid]);
        }
        if ($stock > 0) {
            $subtotal = $unitCost > 0 ? round($stock * $unitCost, 4) : 0;
            $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$this->uid,$pid,$finalPpn,'manual_in',$stock,0,$stock,$unitCost,$isSample,$subtotal,'初始入库']);
        }
        $ptype = trim((string)($p['product_type'] ?? ''));
        if ($ptype) linkCategories($pid, $this->dataUid, parseCategories($ptype));
        traceLog($this->uid, 'add_part', 'part', $pid, "添加元件 id:{$pid} 编号:{$ppn} 平台类型:{$currentPlatType}");
        return ['part_id' => $pid];
    }

    /**
     * 编辑元件
     * @return array {part_id}
     * @throws PartException 校验失败
     */
    public function editPart(array $p): array
    {
        $id    = intval($p['id'] ?? 0);
        $ptype = trim((string)($p['product_type'] ?? ''));
        $alts  = trim((string)($p['alternatives'] ?? ''));
        $thrRaw = trim((string)($p['low_stock_threshold'] ?? ''));
        $thrVal = $thrRaw === '' ? null : max(0, (int)$thrRaw);
        $purchaseUrl = trim((string)($p['purchase_url'] ?? ''));
        $linkedPartIdRaw = trim((string)($p['linked_part_id'] ?? ''));
        $linkedPartId = $linkedPartIdRaw !== '' ? intval($linkedPartIdRaw) : null;
        $editPpn = trim((string)($p['platform_part_no'] ?? ''));

        // 查询当前物料所属平台
        $curPlatStmt = $this->db->prepare("SELECT platform_id FROM parts WHERE id=? AND user_id=?");
        $curPlatStmt->execute([$id, $this->dataUid]);
        $curPlatRow = $curPlatStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curPlatRow) throw new PartException('元件不存在或无权编辑', 3);

        $currentPlatType = $this->getPlatType((int)$curPlatRow['platform_id']);
        if ($currentPlatType === 'standard') {
            $purchaseUrl = '';
            $linkedPartId = null;
            if ($editPpn === '') throw new PartException('标准商城平台必须填写商品编号', 2);
        } else {
            if ($purchaseUrl === '') throw new PartException('散货渠道平台必须填写采购链接', 2);
            if (!preg_match('#^https?://#i', $purchaseUrl)) throw new PartException('采购链接必须以 http:// 或 https:// 开头', 2);
        }

        // 验证 linked_part_id
        if ($linkedPartId !== null && $linkedPartId > 0) {
            $linkCheck = $this->db->prepare("SELECT id FROM parts WHERE id=? AND user_id=?");
            $linkCheck->execute([$linkedPartId, $this->dataUid]);
            if (!$linkCheck->fetch()) $linkedPartId = null;
        } else {
            $linkedPartId = null;
        }

        // 查询旧数据（用于双向互绑差异计算）
        $oldStmt = $this->db->prepare("SELECT id, alternatives FROM parts WHERE id=? AND user_id=?");
        $oldStmt->execute([$id, $this->dataUid]);
        $oldPart = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $oldAlts = array_filter(array_map('trim', explode(',', (string)($oldPart['alternatives'] ?? ''))));
        $newAlts = array_filter(array_map('trim', explode(',', $alts)));

        $editPpnInsert = $editPpn !== '' ? $editPpn : null;
        $this->db->prepare("UPDATE parts SET platform_part_no=?,customer_part_no=?,model=?,product_name=?,product_type=?,package=?,brand=?,low_stock_threshold=?,location=?,remark=?,alternatives=?,purchase_url=?,linked_part_id=? WHERE id=? AND user_id=?")
           ->execute([$editPpnInsert,trim((string)($p['customer_part_no']??'')),
             trim((string)($p['model']??'')),trim((string)($p['product_name']??'')),$ptype,trim((string)($p['package']??'')),
             trim((string)($p['brand']??'')),$thrVal,
             trim((string)($p['location']??'')),trim((string)($p['remark']??'')),$alts,$purchaseUrl,$linkedPartId,$id,$this->dataUid]);
        $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);
        // 商品类型即所属二级分类：通过 product_type 文本解析并关联到 categories 表
        if ($ptype) linkCategories($id, $this->dataUid, parseCategories($ptype));

        // 替代料双向互绑
        $syncId = (string)$id;
        $addedAlts = array_diff($newAlts, $oldAlts);
        $removedAlts = array_diff($oldAlts, $newAlts);
        foreach ($addedAlts as $altId) {
            if ($altId === '' || $altId === $syncId) continue;
            $altStmt = $this->db->prepare("SELECT id, alternatives FROM parts WHERE id=? AND user_id=? LIMIT 1");
            $altStmt->execute([intval($altId), $this->dataUid]);
            $altPart = $altStmt->fetch(PDO::FETCH_ASSOC);
            if ($altPart) {
                $altList = array_filter(array_map('trim', explode(',', (string)$altPart['alternatives'])));
                if (!in_array($syncId, $altList)) {
                    $altList[] = $syncId;
                    $this->db->prepare("UPDATE parts SET alternatives=? WHERE id=? AND user_id=?")
                       ->execute([implode(',', $altList), $altPart['id'], $this->dataUid]);
                }
            }
        }
        foreach ($removedAlts as $altId) {
            if ($altId === '') continue;
            $altStmt = $this->db->prepare("SELECT id, alternatives FROM parts WHERE id=? AND user_id=? LIMIT 1");
            $altStmt->execute([intval($altId), $this->dataUid]);
            $altPart = $altStmt->fetch(PDO::FETCH_ASSOC);
            if ($altPart) {
                $altList = array_filter(array_map('trim', explode(',', (string)$altPart['alternatives'])));
                $altList = array_values(array_diff($altList, [$syncId]));
                $this->db->prepare("UPDATE parts SET alternatives=? WHERE id=? AND user_id=?")
                   ->execute([implode(',', $altList), $altPart['id'], $this->dataUid]);
            }
        }

        traceLog($this->uid, 'edit_part', 'part', $id, "编辑元件 id:{$id}");
        return ['part_id' => $id];
    }

    /**
     * 补全残缺物料信息并解除灰色锁定（BOM 未匹配物料专用编辑接口）。
     * 必须手动选择归属平台，补全后 is_incomplete=0，转为正常可编辑物料。
     * @param array $p 字段：id, platform_id, platform_part_no, model, product_name, product_type, package, brand, location, customer_part_no, remark, low_stock_threshold
     * @throws PartException 物料不存在/非残缺/平台必选/编号冲突
     */
    public function completeIncompletePart(array $p): array
    {
        $id        = intval($p['id'] ?? 0);
        $platformId = intval($p['platform_id'] ?? 0);
        $ptype     = trim((string)($p['product_type'] ?? ''));
        $thrRaw    = trim((string)($p['low_stock_threshold'] ?? ''));
        $thrVal    = $thrRaw === '' ? null : max(0, (int)$thrRaw);
        $editPpn   = trim((string)($p['platform_part_no'] ?? ''));

        if ($id <= 0) throw new PartException('参数错误：缺少物料ID', 2);
        if ($platformId <= 0) throw new PartException('必须手动选择物料归属平台', 2);
        if ($ptype === '') throw new PartException('商品类型（分类）必填', 2);

        // 查询当前物料，确认是残缺物料
        $curStmt = $this->db->prepare("SELECT id, is_incomplete, platform_id, platform_part_no FROM parts WHERE id=? AND user_id=?");
        $curStmt->execute([$id, $this->dataUid]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new PartException('元件不存在或无权编辑', 3);
        if ((int)$cur['is_incomplete'] !== 1) throw new PartException('该物料非残缺物料，无需补全', 3);

        // 验证平台有效性
        $platStmt = $this->db->prepare("SELECT id, platform_type FROM platforms WHERE id=? AND (user_id=? OR user_id=0) LIMIT 1");
        $platStmt->execute([$platformId, $this->dataUid]);
        $plat = $platStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plat) throw new PartException('所选平台不存在', 2);
        $platType = $plat['platform_type'] ?? 'standard';

        // 标准平台必须填写商品编号；散货平台编号可空
        if ($platType === 'standard' && $editPpn === '') {
            throw new PartException('标准商城平台必须填写商品编号', 2);
        }

        // 编号唯一性校验（同 user_id + platform_id 下编号唯一）
        if ($editPpn !== '') {
            $dupStmt = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? AND id<>? LIMIT 1");
            $dupStmt->execute([$this->dataUid, $platformId, $editPpn, $id]);
            if ($dupStmt->fetch()) {
                throw new PartException('该平台下已存在相同编号的物料', 2);
            }
        }

        $editPpnInsert = $editPpn !== '' ? $editPpn : null;
        $this->db->prepare("UPDATE parts SET platform_id=?,platform_part_no=?,customer_part_no=?,model=?,product_name=?,product_type=?,package=?,brand=?,low_stock_threshold=?,location=?,remark=?,is_incomplete=0 WHERE id=? AND user_id=?")
           ->execute([
               $platformId, $editPpnInsert, trim((string)($p['customer_part_no'] ?? '')),
               trim((string)($p['model'] ?? '')), trim((string)($p['product_name'] ?? '')),
               $ptype, trim((string)($p['package'] ?? '')), trim((string)($p['brand'] ?? '')),
               $thrVal, trim((string)($p['location'] ?? '')), trim((string)($p['remark'] ?? '')),
               $id, $this->dataUid
           ]);

        // 重建分类关联
        $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);
        linkCategories($id, $this->dataUid, parseCategories($ptype));

        traceLog($this->uid, 'complete_incomplete_part', 'part', $id, "补全残缺物料 id:{$id}, platform_id:{$platformId}");
        return ['part_id' => $id];
    }

    /**
     * 批量补全残缺物料（BOM 未匹配物料一键补全专用）。
     *
     * 必填字段校验（缺一不可，否则跳过该条）：
     *   型号(model)、封装(package)、分类(product_type)、商品名称(product_name)
     * 允许为空字段：品牌(brand)、商品编号(platform_part_no)、核心电气参数(parameters)
     * 注：parameters 不参与匹配与必填校验，BOM 文件通常无此列
     *
     * 自动填充规则：
     *   - 商品编号为空且为标准平台：用 #内部ID内部 格式自动生成全局唯一编号
     *   - 品牌为空：直接空值入库
     *   - 其余字段直接复用 BOM 导入解析出来的完整信息
     *
     * @param array $p 字段：ids[], platform_id
     * @return array{completed:int, skipped:int}
     * @throws PartException 未选择物料 / 平台必选 / 全部跳过
     */
    public function batchCompleteIncomplete(array $p): array
    {
        $ids        = array_filter(array_map('intval', $p['item_ids'] ?? []), fn($v) => $v > 0);
        $platformId = intval($p['platform_id'] ?? 0);

        if (empty($ids)) throw new PartException('未选择物料', 2);
        if ($platformId <= 0) throw new PartException('必须手动选择物料归属平台', 2);

        // 验证平台有效性
        $platStmt = $this->db->prepare("SELECT id, platform_type FROM platforms WHERE id=? AND (user_id=? OR user_id=0) LIMIT 1");
        $platStmt->execute([$platformId, $this->dataUid]);
        $plat = $platStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plat) throw new PartException('所选平台不存在', 2);
        $platType = $plat['platform_type'] ?? 'standard';

        // 批量查询物料当前信息
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, internal_id, platform_part_no, model, product_name, product_type, package, brand, parameters, is_incomplete FROM parts WHERE user_id=? AND id IN ($in)");
        $stmt->execute(array_merge([$this->dataUid], $ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $completed = 0; $skipped = 0;
        $updStmt = $this->db->prepare("UPDATE parts SET platform_id=?, platform_part_no=?, is_incomplete=0, update_time=NOW() WHERE id=? AND user_id=?");
        $dupStmt = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? AND id<>? LIMIT 1");
        $delCatStmt = $this->db->prepare("DELETE FROM part_categories WHERE part_id=?");

        foreach ($rows as $cur) {
            // 仅处理残缺物料
            if ((int)$cur['is_incomplete'] !== 1) { $skipped++; continue; }

            // 必填字段校验：型号 / 封装 / 分类 / 商品名称
            // 核心电气参数(parameters)不参与匹配与必填校验，BOM 文件通常无此列
            $model  = trim((string)$cur['model']);
            $pkg    = trim((string)$cur['package']);
            $ptype  = trim((string)$cur['product_type']);
            $pname  = trim((string)$cur['product_name']);
            if ($model === '' || $pkg === '' || $ptype === '' || $pname === '') {
                $skipped++;
                continue;
            }

            // 商品编号处理：为空时标准平台用 #内部ID内部 格式自动生成
            $ppn = trim((string)$cur['platform_part_no']);
            if ($ppn === '' && $platType === 'standard') {
                $ppn = '#' . (int)$cur['internal_id'] . '内部';
            }
            $ppnInsert = $ppn !== '' ? $ppn : null;

            // 唯一性校验
            if ($ppn !== '') {
                $dupStmt->execute([$this->dataUid, $platformId, $ppn, $cur['id']]);
                if ($dupStmt->fetch()) { $skipped++; continue; }
            }

            // 执行补全：仅更新 platform_id / platform_part_no / is_incomplete 三个关键字段
            // 其余字段（model/package/product_type/product_name/brand/parameters 等）保持 BOM 导入时已写入的值
            $updStmt->execute([$platformId, $ppnInsert, $cur['id'], $this->dataUid]);

            // 重建分类关联（确保 L1/L2 层级正确）
            $delCatStmt->execute([$cur['id']]);
            linkCategories((int)$cur['id'], $this->dataUid, parseCategories($ptype));

            $completed++;
        }

        if ($completed === 0) {
            throw new PartException('选中的物料均不满足补全条件（必填字段缺失或编号冲突）', 2);
        }

        traceLog($this->uid, 'batch_complete_incomplete', 'part', 0, "批量补全残缺物料 {$completed} 条, platform_id:{$platformId}, skipped:{$skipped}");
        return ['completed' => $completed, 'skipped' => $skipped];
    }

    /**
     * 删除元件（受留存时效限制，未满留存期禁止删除）
     * @throws PartException 物料不存在或未满留存期
     */
    public function deletePart(int $id): array
    {
        $check = $this->db->prepare("SELECT id, create_time FROM parts WHERE id=? AND user_id=?");
        $check->execute([$id, $this->dataUid]);
        $row = $check->fetch();
        if (!$row) throw new PartException('元件不存在', 3);
        if (!isRetentionExpired((string)$row['create_time'])) {
            $days = getRetentionDays();
            throw new PartException("物料未满 {$days} 天留存期，禁止删除", 5);
        }
        cleanupAlternativesReverseLinks([$id], $this->dataUid);
        $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);
        $this->db->prepare("DELETE FROM stock_log WHERE part_id=? AND user_id=?")->execute([$id, $this->dataUid]);
        $this->db->prepare("DELETE FROM price_history WHERE part_id=? AND user_id=?")->execute([$id, $this->dataUid]);
        $this->db->prepare("DELETE FROM parts WHERE id=? AND user_id=?")->execute([$id, $this->dataUid]);
        traceLog($this->uid, 'delete_part', 'part', $id, "删除元件 id:{$id}");
        return ['part_id' => $id];
    }

    /**
     * 批量删除（受留存时效限制，未满留存期的元件自动跳过）
     * @param int[] $ids
     * @throws PartException 无有效元件或均未满留存期
     */
    public function batchDelete(array $ids): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        // 留存时效过滤：仅删除已过留存期的元件
        $in = implode(',', array_fill(0, count($validIds), '?'));
        $chk = $this->db->prepare("SELECT id, create_time FROM parts WHERE id IN ($in) AND user_id=?");
        $chk->execute([...$validIds, $this->dataUid]);
        $deletable = [];
        $skipped = 0;
        foreach ($chk->fetchAll() as $r) {
            if (isRetentionExpired((string)$r['create_time'])) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }
        if (empty($deletable)) {
            $days = getRetentionDays();
            throw new PartException("所选物料均未满 {$days} 天留存期，禁止删除", 5);
        }
        cleanupAlternativesReverseLinks($deletable, $this->dataUid);
        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("DELETE FROM part_categories WHERE part_id IN ($inV)")->execute($deletable);
        $this->db->prepare("DELETE FROM stock_log WHERE part_id IN ($inV) AND user_id=?")->execute([...$deletable, $this->dataUid]);
        $this->db->prepare("DELETE FROM price_history WHERE part_id IN ($inV) AND user_id=?")->execute([...$deletable, $this->dataUid]);
        $this->db->prepare("DELETE FROM parts WHERE id IN ($inV) AND user_id=?")->execute([...$deletable, $this->dataUid]);
        traceLog($this->uid, 'batch_delete_parts', 'part', 0, "批量删除元件 count:" . count($deletable) . ($skipped > 0 ? " skipped:{$skipped}" : ''));
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }

    /**
     * 批量设置分类
     * @throws PartException 请选择分类
     */
    public function batchSetCategory(array $ids, int $catId, string $newCat): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        if ($newCat !== '') {
            $catId = getOrCreateCategory($this->dataUid, $newCat);
        }
        if ($catId <= 0) throw new PartException('请选择分类', 2);
        foreach ($validIds as $pid) {
            // 先清空原有分类关联（更新而非递增，一个物料只能所属一种分类）
            $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$pid]);
            $this->db->prepare("INSERT IGNORE INTO part_categories (part_id, category_id) VALUES (?, ?)")
               ->execute([$pid, $catId]);
        }
        traceLog($this->uid, 'batch_set_category', 'part', 0, "批量设置分类 count:" . count($validIds) . " cat_id:{$catId}");
        return ['updated' => count($validIds)];
    }

    /**
     * 批量设置库位
     */
    public function batchSetLocation(array $ids, string $location): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        $inV = implode(',', array_fill(0, count($validIds), '?'));
        $this->db->prepare("UPDATE parts SET location=? WHERE id IN ($inV) AND user_id=?")
           ->execute([$location, ...$validIds, $this->dataUid]);
        traceLog($this->uid, 'batch_set_location', 'part', 0, "批量设置库位 count:" . count($validIds) . " location:{$location}");
        return ['updated' => count($validIds)];
    }

    /**
     * 批量设置备注
     */
    public function batchSetRemark(array $ids, string $remark): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        $inV = implode(',', array_fill(0, count($validIds), '?'));
        $this->db->prepare("UPDATE parts SET remark=? WHERE id IN ($inV) AND user_id=?")
           ->execute([$remark, ...$validIds, $this->dataUid]);
        traceLog($this->uid, 'batch_set_remark', 'part', 0, "批量设置备注 count:" . count($validIds));
        return ['updated' => count($validIds)];
    }

    // ════════════════════════════════════════════════════════════════
    //  内部辅助方法
    // ════════════════════════════════════════════════════════════════

    /** 读取平台类型（standard / loose），不存在返回 standard */
    private function getPlatType(int $platId): string
    {
        $stmt = $this->db->prepare("SELECT platform_type FROM platforms WHERE id=? AND user_id=?");
        $stmt->execute([$platId, $this->dataUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && in_array($row['platform_type'] ?? '', ['standard','loose'], true))
            ? $row['platform_type'] : 'standard';
    }

    /** 过滤出属于当前数据用户的元件 ID */
    private function filterValidIds(array $ids): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $valid = $this->db->prepare("SELECT id FROM parts WHERE id IN ($in) AND user_id=?");
        $valid->execute([...$ids, $this->dataUid]);
        return array_column($valid->fetchAll(), 'id');
    }
}
