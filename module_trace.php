<?php
declare(strict_types=1);
/**
 * 操作溯源日志模块（v1.1.0 正式版）
 *
 * 封装全系统操作溯源日志（trace_log 表）的查询/导出/删除业务逻辑：
 * - 列表查询（支持起止日期筛选 + 分页，默认每页100条）
 * - 日期范围导出 CSV 数据查询
 * - 单条/批量删除（受留存时效限制，未满留存期禁止删除）
 *
 * 设计原则：
 * - 方法返回纯数组，由入口文件决定输出格式
 * - 校验失败抛出 TraceException，入口文件统一 catch
 * - 权限范围：主管理员查看全系统日志；普通管理员查看自身及数据归属用户的日志
 * - 列表默认仅展示最新 100 条（首页），完整数据存数据库，通过分页访问
 */

/**
 * 溯源日志业务异常
 */
class TraceException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message);
        $this->errCode = $errCode;
    }
}

/**
 * 溯源日志管理器
 */
final class TraceManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private bool $isPrimary;

    /** 默认每页条数（满足"列表默认仅展示最新100条"需求） */
    public const DEFAULT_PER_PAGE = 100;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
        $this->isPrimary = isPrimaryAdmin();
    }

    /**
     * 构建权限范围 WHERE 子句
     * - 主管理员：全系统日志（无 user_id 限制）
     * - 普通管理员：仅查看自身及数据归属用户的日志
     *
     * @return array{0:string, 1:list<int>} [whereSql片段(不含WHERE关键字), 参数]
     */
    private function buildScopeWhere(): array
    {
        if ($this->isPrimary) {
            return ['', []];
        }
        // 普通管理员：自身 + 数据归属用户（含子用户）
        $subStmt = $this->db->prepare("SELECT id FROM users WHERE parent_id=?");
        $subStmt->execute([$this->uid]);
        $subIds = array_map('intval', array_column($subStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $userIds = array_values(array_unique(array_merge([$this->uid, $this->dataUid], $subIds)));
        if (count($userIds) === 1) {
            return ['user_id = ?', $userIds];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        return ["user_id IN ($in)", $userIds];
    }

    // ──────────────────────────────────────────────────────────
    //  溯源日志列表查询（支持起止日期筛选 + 分页）
    // ──────────────────────────────────────────────────────────

    /**
     * 溯源日志列表查询
     *
     * @param array $f 查询参数 {date_from, date_to, page, per_page}
     * @return array {logs, total, page, total_pages, per_page, date_from, date_to}
     */
    public function listLogs(array $f): array
    {
        $dateFrom = trim((string)($f['date_from'] ?? ''));
        $dateTo   = trim((string)($f['date_to'] ?? ''));
        $perPage  = (int)($f['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage  = max(10, min(500, $perPage));
        $page     = max(1, (int)($f['page'] ?? 1));

        // 构建权限范围 + 日期筛选
        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereParts = [];
        $params = $scopeParams;
        if ($scopeWhere !== '') $whereParts[] = $scopeWhere;
        if ($dateFrom !== '') {
            $whereParts[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereParts[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereSql = '';
        if (!empty($whereParts)) $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        // 总数查询
        $cntStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM trace_log t $whereSql"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // 列表查询（LEFT JOIN users 获取操作用户名）
        $sql = "SELECT t.id, t.user_id, t.action, t.target_type, t.target_id, t.detail, t.ip, t.created_at,
                       u.username
                FROM trace_log t LEFT JOIN users u ON u.id = t.user_id
                $whereSql
                ORDER BY t.created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($logs as &$l) {
            $l['id']         = (int)$l['id'];
            $l['user_id']    = (int)$l['user_id'];
            $l['target_id']  = (int)$l['target_id'];
            $l['username']   = $l['username'] ?? '';
            $l['detail']     = $l['detail'] ?? '';
            $l['ip']         = $l['ip'] ?? '';
            $l['action']     = $l['action'] ?? '';
            $l['target_type']= $l['target_type'] ?? '';
        }
        unset($l);

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  日期范围导出数据查询
    // ──────────────────────────────────────────────────────────

    /**
     * 查询指定日期范围的溯源日志（用于 CSV 导出）
     *
     * @param string $dateFrom 起始日期（Y-m-d，空字符串表示不限制）
     * @param string $dateTo   结束日期（Y-m-d，空字符串表示不限制）
     * @return array 日志列表
     */
    public function getLogsForExport(string $dateFrom, string $dateTo): array
    {
        $dateFrom = trim($dateFrom);
        $dateTo   = trim($dateTo);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereParts = [];
        $params = $scopeParams;
        if ($scopeWhere !== '') $whereParts[] = $scopeWhere;
        if ($dateFrom !== '') {
            $whereParts[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereParts[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereSql = '';
        if (!empty($whereParts)) $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        $sql = "SELECT t.id, t.user_id, t.action, t.target_type, t.target_id, t.detail, t.ip, t.created_at,
                       u.username
                FROM trace_log t LEFT JOIN users u ON u.id = t.user_id
                $whereSql
                ORDER BY t.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($logs as &$l) {
            $l['id']         = (int)$l['id'];
            $l['user_id']    = (int)$l['user_id'];
            $l['target_id']  = (int)$l['target_id'];
            $l['username']   = $l['username'] ?? '';
        }
        unset($l);

        return $logs;
    }

    // ──────────────────────────────────────────────────────────
    //  删除接口（受留存时效限制）
    // ──────────────────────────────────────────────────────────

    /**
     * 删除单条溯源日志（受留存时效限制，未满留存期禁止删除）
     *
     * @param int $logId 日志ID
     * @return array
     * @throws TraceException 无权操作或未满留存期
     */
    public function deleteLog(int $logId): array
    {
        if ($logId <= 0) throw new TraceException('参数无效', 4);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereSql = 'WHERE t.id=?';
        $params = [$logId];
        if ($scopeWhere !== '') {
            $whereSql .= " AND $scopeWhere";
            $params = array_merge([$logId], $scopeParams);
        }

        $stmt = $this->db->prepare("SELECT t.id, t.created_at FROM trace_log t $whereSql");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new TraceException('记录不存在或无权操作', 3);

        if (!isRetentionExpired((string)$row['created_at'])) {
            $days = getRetentionDays();
            throw new TraceException("溯源日志未满 {$days} 天留存期，禁止删除", 5);
        }

        $this->db->prepare("DELETE FROM trace_log WHERE id=?")->execute([$logId]);
        return ['deleted' => true, 'log_id' => $logId];
    }

    /**
     * 批量删除溯源日志（受留存时效限制，未满留存期的自动跳过）
     *
     * @param array $logIds 日志ID数组
     * @return array
     * @throws TraceException 未选择有效记录或均未满留存期
     */
    public function batchDeleteLogs(array $logIds): array
    {
        $logIds = array_values(array_filter(array_map('intval', $logIds), fn($v) => $v > 0));
        if (empty($logIds)) throw new TraceException('未选择有效记录', 4);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $in = implode(',', array_fill(0, count($logIds), '?'));
        $whereSql = "t.id IN ($in)";
        $params = $logIds;
        if ($scopeWhere !== '') {
            $whereSql .= " AND $scopeWhere";
            $params = array_merge($logIds, $scopeParams);
        }

        $stmt = $this->db->prepare("SELECT t.id, t.created_at FROM trace_log t WHERE $whereSql");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) throw new TraceException('无权删除这些记录', 3);

        $deletable = [];
        $skipped = 0;
        foreach ($rows as $r) {
            if (isRetentionExpired((string)$r['created_at'])) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }

        if (empty($deletable)) {
            $days = getRetentionDays();
            throw new TraceException("所选记录均未满 {$days} 天留存期，禁止删除", 5);
        }

        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("DELETE FROM trace_log WHERE id IN ($inV)")->execute($deletable);
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }
}
