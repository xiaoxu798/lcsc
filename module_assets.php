<?php
declare(strict_types=1);
/**
 * 资产统计模块（v1.1.0 正式版）
 *
 * 封装所有资产统计业务逻辑：
 * - 统计卡片数据（累计入库总金额、本月新增资产、在库物料种类、本月新增物料）
 * - 图表数据（近12个月累计资产折线 + 月度入库/出库金额对比，单查询优化）
 * - 出入库流水查询（支持筛选/分页，JOIN + GROUP_CONCAT 消除 N+1）
 * - CSV 导出数据查询
 *
 * 设计原则：
 * - 所有金额计算在后端完成，前端仅负责展示
 * - 统计排除样品数据（is_sample=0）
 * - 资产核算基于入库采购成本（出库不扣减资产总额）
 * - 方法返回纯数组，由入口文件决定输出格式
 * - 校验失败抛出 AssetException，入口文件统一 catch
 */

/**
 * 资产统计业务异常
 */
class AssetException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message, $errCode);
        $this->errCode = $errCode;
    }
}

/**
 * 资产统计管理器
 */
final class AssetManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;

    /** 操作类型标签映射（供前端展示） */
    public const TYPE_LABELS = [
        'import'        => ['订单导入', '#4f8ef7'],
        'manual_in'     => ['手动入库', '#22c55e'],
        'manual_out'    => ['手动出库', '#ef4444'],
        'adjust'        => ['库存调整', '#f59e0b'],
        'scan_in'       => ['扫码入库', '#22c55e'],
        'scan_out'      => ['扫码出库', '#ef4444'],
        'damaged'       => ['报损',     '#8b5cf6'],
        'repair'        => ['修复',     '#8b5cf6'],
        'scan_undo_in'  => ['撤销入库', '#f59e0b'],
        'scan_undo_out' => ['撤销出库', '#f59e0b'],
        'bom_out'       => ['BOM出库',  '#ef4444'],
    ];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid      = $uid;
        $this->dataUid = $dataUid;
    }

    // ──────────────────────────────────────────────────────────
    //  统计卡片数据
    // ──────────────────────────────────────────────────────────

    /**
     * 获取4个统计卡片数据
     *
     * @return array {total_stock_cost, sample_count, month_in_amount, in_stock_types, month_new_parts, month_label}
     */
    public function getStats(): array
    {
        // 1. 累计入库总金额 = SUM(subtotal) 全系统非样品入库流水小计
        $totalCostStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(subtotal), 0) FROM stock_log
             WHERE user_id=? AND subtotal > 0 AND is_sample=0"
        );
        $totalCostStmt->execute([$this->dataUid]);
        $totalStockCost = (float)$totalCostStmt->fetchColumn();

        // 2. 样品物料数量（排除统计）
        $sampleStmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT part_id) FROM stock_log
             WHERE user_id=? AND is_sample=1"
        );
        $sampleStmt->execute([$this->dataUid]);
        $sampleCount = (int)$sampleStmt->fetchColumn();

        // 3. 本月新增资产 = 当前自然月内入库流水小计总和（排除样品）
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd   = date('Y-m-t 23:59:59');
        $monthStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN qty_change>0 AND is_sample=0 THEN subtotal ELSE 0 END), 0)
             FROM stock_log WHERE user_id=? AND create_time BETWEEN ? AND ?"
        );
        $monthStmt->execute([$this->dataUid, $monthStart, $monthEnd]);
        $monthInAmount = (float)$monthStmt->fetchColumn();

        // 4. 在库物料总种类 = COUNT(parts) WHERE stock>0
        $typeStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE user_id=? AND stock>0");
        $typeStmt->execute([$this->dataUid]);
        $inStockTypes = (int)$typeStmt->fetchColumn();

        // 5. 本月新增物料 = COUNT(parts) WHERE update_time 本月
        $newStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts WHERE user_id=? AND update_time BETWEEN ? AND ?"
        );
        $newStmt->execute([$this->dataUid, $monthStart, $monthEnd]);
        $monthNewParts = (int)$newStmt->fetchColumn();

        return [
            'total_stock_cost'  => round($totalStockCost, 2),
            'sample_count'      => $sampleCount,
            'month_in_amount'   => round($monthInAmount, 2),
            'in_stock_types'    => $inStockTypes,
            'month_new_parts'   => $monthNewParts,
            'month_label'       => date('Y年m月'),
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  图表数据（近12个月，单查询优化）
    // ──────────────────────────────────────────────────────────

    /**
     * 获取近12个月图表数据
     * - 折线图：月末累计入库总金额（基于 subtotal 直接累加，排除样品）
     * - 柱状图：月度入库金额 vs 出库金额对比
     *
     * 优化：原实现 24 个独立 SQL，现合并为 1 个 GROUP BY 查询 + PHP 累加
     *
     * @return array {line: {labels, values}, bar: {labels, in_values, out_values}}
     */
    public function getChartData(): array
    {
        // 计算12个月前的月初作为查询起点
        $startMonth = date('Y-m-01 00:00:00', strtotime('-11 months'));

        // 单查询获取近12个月每月的入库/出库/小计汇总
        $stmt = $this->db->prepare(
            "SELECT
                DATE_FORMAT(create_time, '%Y-%m') AS ym,
                COALESCE(SUM(CASE WHEN qty_change>0 AND is_sample=0 THEN subtotal ELSE 0 END), 0) AS in_amt,
                COALESCE(SUM(CASE WHEN qty_change<0 THEN qty_change*unit_cost ELSE 0 END), 0) AS out_amt,
                COALESCE(SUM(CASE WHEN is_sample=0 AND subtotal>0 THEN subtotal ELSE 0 END), 0) AS month_subtotal
             FROM stock_log
             WHERE user_id=? AND create_time >= ?
             GROUP BY ym
             ORDER BY ym"
        );
        $stmt->execute([$this->dataUid, $startMonth]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 构建月份映射，补齐缺失月份为0
        $monthMap = [];
        foreach ($rows as $r) {
            $monthMap[$r['ym']] = $r;
        }

        // 生成近12个月标签（从11个月前到当前月）
        $labels = [];
        $inValues = [];
        $outValues = [];
        $monthSubtotals = [];
        for ($i = 11; $i >= 0; $i--) {
            $mLabel = date('Y-m', strtotime("-$i month"));
            $labels[] = $mLabel;
            $data = $monthMap[$mLabel] ?? null;
            $inValues[]    = round((float)($data['in_amt'] ?? 0), 2);
            $outValues[]   = round(abs((float)($data['out_amt'] ?? 0)), 2);
            $monthSubtotals[] = (float)($data['month_subtotal'] ?? 0);
        }

        // 折线图需要累计值：每月末累计 = 历史所有入库小计之和
        // 先查询起始月之前的累计基数
        $baseStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(subtotal), 0) FROM stock_log
             WHERE user_id=? AND create_time < ? AND is_sample=0 AND subtotal>0"
        );
        $baseStmt->execute([$this->dataUid, $startMonth]);
        $cumulative = (float)$baseStmt->fetchColumn();

        // 逐月累加得到折线图数据
        $lineValues = [];
        foreach ($monthSubtotals as $ms) {
            $cumulative += $ms;
            $lineValues[] = round($cumulative, 2);
        }

        return [
            'line' => [
                'labels' => $labels,
                'values' => $lineValues,
            ],
            'bar' => [
                'labels'     => $labels,
                'in_values'  => $inValues,
                'out_values' => $outValues,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  出入库流水查询（支持筛选/分页，JOIN 优化消除 N+1）
    // ──────────────────────────────────────────────────────────

    /**
     * 出入库流水查询
     *
     * @param array $f 筛选参数 {keyword, cat_id, plat_id, date_from, date_to, page, per_page}
     * @return array {logs, total, page, total_pages, per_page, type_labels}
     */
    public function listLogs(array $f): array
    {
        // 解析筛选参数
        $keyword  = trim((string)($f['keyword'] ?? ''));
        $catId    = (int)($f['cat_id'] ?? 0);
        $platId   = (int)($f['plat_id'] ?? 0);
        $dateFrom = trim((string)($f['date_from'] ?? ''));
        $dateTo   = trim((string)($f['date_to'] ?? ''));

        // 构建 WHERE 条件
        $whereSql = "WHERE sl.user_id=?";
        $params = [$this->dataUid];
        if ($keyword !== '') {
            $whereSql .= " AND (p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ?)";
            $kw = '%' . $keyword . '%';
            array_push($params, $kw, $kw, $kw);
        }
        if ($platId > 0) {
            $whereSql .= " AND p.platform_id=?";
            $params[] = $platId;
        }
        if ($catId > 0) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=sl.part_id AND (c.id=? OR c.parent_id=?))";
            array_push($params, $catId, $catId);
        }
        if ($dateFrom !== '') {
            $whereSql .= " AND sl.create_time>=?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereSql .= " AND sl.create_time<=?";
            $params[] = $dateTo . ' 23:59:59';
        }

        // 分页参数
        $perPage = (int)($f['per_page'] ?? 30);
        if (!in_array($perPage, [15, 30, 50, 100], true)) $perPage = 30;
        $page = max(1, (int)($f['page'] ?? 1));

        // 总数查询
        $cntStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM stock_log sl LEFT JOIN parts p ON p.id=sl.part_id $whereSql"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // 流水查询（JOIN 平台 + GROUP_CONCAT 分类，消除 N+1）
        $sql = "SELECT sl.id, sl.part_id, sl.platform_part_no, sl.change_type, sl.qty_change,
                       sl.unit_cost, sl.is_sample, sl.subtotal, sl.create_time, sl.remark,
                       p.model, p.product_name, pl.name AS pname,
                       (SELECT GROUP_CONCAT(c.name SEPARATOR ',')
                        FROM part_categories pc JOIN categories c ON c.id=pc.category_id
                        WHERE pc.part_id=sl.part_id) AS cat_names
                FROM stock_log sl
                LEFT JOIN parts p ON p.id=sl.part_id
                LEFT JOIN platforms pl ON pl.id=p.platform_id
                $whereSql
                ORDER BY sl.create_time DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($logs as &$l) {
            $l['id']           = (int)$l['id'];
            $l['part_id']      = (int)$l['part_id'];
            $l['qty_change']   = (int)$l['qty_change'];
            $l['unit_cost']    = (float)$l['unit_cost'];
            $l['is_sample']    = (int)$l['is_sample'];
            $l['subtotal']     = (float)$l['subtotal'];
            $l['cat_names']    = $l['cat_names'] ?? '';
        }
        unset($l);

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'type_labels' => self::TYPE_LABELS,
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  CSV 导出数据查询
    // ──────────────────────────────────────────────────────────

    /**
     * 查询指定 ID 的流水记录（用于 CSV 导出，含分类拼接）
     *
     * @param array $ids 流水记录ID数组
     * @return array 流水记录列表（含 model/platform_part_no/product_name/pname/cat_names）
     * @throws AssetException 未选择记录或记录不存在
     */
    public function getLogsForExport(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) {
            throw new AssetException('未选择记录', 4);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT sl.id, sl.platform_part_no, sl.change_type, sl.qty_change,
                       sl.unit_cost, sl.is_sample, sl.subtotal, sl.create_time, sl.remark,
                       sl.part_id,
                       p.model, p.product_name, pl.name AS pname,
                       (SELECT GROUP_CONCAT(c.name SEPARATOR ',')
                        FROM part_categories pc JOIN categories c ON c.id=pc.category_id
                        WHERE pc.part_id=sl.part_id) AS cat_names
                FROM stock_log sl
                LEFT JOIN parts p ON p.id=sl.part_id
                LEFT JOIN platforms pl ON pl.id=p.platform_id
                WHERE sl.user_id=? AND sl.id IN ($in)
                ORDER BY sl.create_time DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->dataUid], $ids));
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($logs)) {
            throw new AssetException('所选记录不存在或无权访问', 404);
        }

        return $logs;
    }

    /**
     * 获取筛选下拉选项（一级分类 + 平台列表）
     *
     * @return array {categories, platforms}
     */
    public function getFilterOptions(): array
    {
        // 一级分类
        $catStmt = $this->db->prepare(
            "SELECT id, name FROM categories WHERE user_id=? AND parent_id IS NULL ORDER BY name"
        );
        $catStmt->execute([$this->dataUid]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // 平台列表
        $platStmt = $this->db->prepare(
            "SELECT id, name FROM platforms WHERE user_id=? ORDER BY is_default DESC, name"
        );
        $platStmt->execute([$this->dataUid]);
        $platforms = $platStmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化
        foreach ($categories as &$c) { $c['id'] = (int)$c['id']; }
        unset($c);
        foreach ($platforms as &$p) { $p['id'] = (int)$p['id']; }
        unset($p);

        return [
            'categories' => $categories,
            'platforms'  => $platforms,
        ];
    }
}
