<?php
declare(strict_types=1);
/**
 * 出入库管理模块（v1.1.0 正式版）
 *
 * 封装所有出入库业务逻辑：
 * - 手动出入库（入库/出库/调整/报损/修复）
 * - 扫码入库 / 扫码出库 / 撤销扫码
 * - 出入库记录删除 / 批量删除
 *
 * 设计原则：
 * - 方法返回纯数组，由入口文件决定输出格式
 * - 校验失败抛出 StockException，入口文件统一 catch
 * - scan_* 接口返回 {ok:true/false,...} 格式（扫码专用）
 * - stock/delete_log 接口返回 {code,msg,data} 格式（标准格式）
 */

/**
 * 出入库业务异常
 */
class StockException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message, $errCode);
        $this->errCode = $errCode;
    }
}

/**
 * 出入库管理器
 */
final class StockManager
{
    private PDO $db;
    private int $uid;       // 当前操作用户ID（用于日志记录）
    private int $dataUid;   // 数据所属用户ID（子用户继承父用户数据）

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid      = $uid;
        $this->dataUid = $dataUid;
    }

    // ──────────────────────────────────────────────────────────
    //  手动出入库（stock case）
    // ──────────────────────────────────────────────────────────

    /**
     * 手动出入库操作
     *
     * 支持类型：manual_in（入库）/ manual_out（出库）/ adjust（调整）/ damaged（报损）/ repair（修复）
     *
     * @param array $p POST 参数
     * @return array ['part_id', 'stock_before', 'stock_after', 'change', 'subtotal']
     * @throws StockException
     */
    public function stockChange(array $p): array
    {
        $id       = intval($p['id'] ?? 0);
        $type     = (string)($p['change_type'] ?? '');
        $qty      = intval($p['qty'] ?? 0);
        $rem      = trim((string)($p['remark'] ?? ''));
        $unitCost = round((float)($p['unit_cost'] ?? 0), 4);
        $isSample = !empty($p['is_sample']) ? 1 : 0;

        if ($id <= 0) throw new StockException('元件ID无效', 4);
        if ($qty < 0) throw new StockException('数量不能为负', 4);

        // 查询元件当前库存
        $row = $this->db->prepare("SELECT stock, damaged, platform_part_no FROM parts WHERE id=? AND user_id=?");
        $row->execute([$id, $this->dataUid]);
        $row = $row->fetch();
        if (!$row) throw new StockException('元件不存在', 3);

        $before  = (int)$row['stock'];
        $dBefore = (int)$row['damaged'];
        $after   = $before;
        $dAfter  = $dBefore;
        $change  = 0;

        if ($type === 'damaged') {
            // 报损：从良品 → 不良品
            $actual = min($before, $qty);
            $after  = $before - $actual;
            $dAfter = $dBefore + $actual;
            $change = -$actual;
            $this->db->prepare("UPDATE parts SET stock=?, damaged=? WHERE id=? AND user_id=?")
               ->execute([$after, $dAfter, $id, $this->dataUid]);
        } elseif ($type === 'repair') {
            // 修复：从不良品 → 良品
            $actual = min($dBefore, $qty);
            $after  = $before + $actual;
            $dAfter = $dBefore - $actual;
            $change = $actual;
            $this->db->prepare("UPDATE parts SET stock=?, damaged=? WHERE id=? AND user_id=?")
               ->execute([$after, $dAfter, $id, $this->dataUid]);
        } else {
            // 入库/出库/调整
            $after = match ($type) {
                'adjust'     => max(0, $qty),
                'manual_out' => max(0, $before - $qty),
                default      => $before + $qty, // manual_in / scan_in 等入库类型
            };
            $change = $after - $before;
            $this->db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
               ->execute([$after, $id, $this->dataUid]);
        }

        // 写入 stock_log（入库类操作记录采购成本、样品标记和含税小计）
        $stkSubtotal = ($change > 0 && $unitCost > 0 && !$isSample) ? round($change * $unitCost, 4) : 0;
        $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
                $this->uid, $id, $row['platform_part_no'], $type,
                $change, $before, $after, $unitCost, $isSample, $stkSubtotal, $rem
            ]);
        traceLog($this->uid, 'stock_change', 'part', $id, "出入库操作 id:{$id} 类型:{$type} 变动:{$change}");

        return [
            'part_id'      => $id,
            'user_id'      => $this->uid,
            'stock_before' => $before,
            'stock_after'  => $after,
            'change'       => $change,
            'subtotal'     => $stkSubtotal,
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  扫码入库（scan_in case）
    // ──────────────────────────────────────────────────────────

    /**
     * 扫码入库
     *
     * @param array $p POST 参数
     * @return array {ok:true, type, part_id, model, ...}（扫码专用格式）
     * @throws StockException
     */
    public function scanIn(array $p): array
    {
        $barcode    = $this->safeStr($p['barcode'] ?? '');
        $internalId = $this->safeInt($p['internal_id'] ?? 0);
        $platCode   = $this->safeStr($p['platform_code'] ?? '');
        $platId     = $this->findPlatformIdByCode($platCode);
        $qty        = max(1, $this->safeInt($p['qty'] ?? 1));
        $orderNo    = $this->safeStr($p['order_no'] ?? '');
        $unitCost   = round((float)($p['unit_cost'] ?? 0), 4);
        $isSample   = !empty($p['is_sample']) ? 1 : 0;

        // 内部二维码优先按 internal_id 匹配；否则按条码匹配
        if ($internalId > 0) {
            $part = $this->findPartByInternalId($internalId);
            if (!$part) throw new StockException('内部物料ID不存在: ' . $internalId, 2);
            $barcode = $part['platform_part_no'] ?: $part['customer_part_no'] ?: ('内部ID:' . $internalId);
        } else {
            if ($barcode === '') throw new StockException('条码不能为空', 2);
            $part = $this->findPartByBarcode($barcode, $platId);
            if (!$part) throw new StockException('未找到该元件: ' . $barcode, 2);
        }

        // 服务端防重复：同一用户同一元件5秒内不可重复扫码入库（适用内部二维码和立创采购码）
        $dupCheck = $this->db->prepare(
            "SELECT id FROM scan_log WHERE user_id=? AND part_id=? AND scan_type='in' AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1"
        );
        $dupCheck->execute([$this->uid, $part['id']]);
        if ($dupCheck->fetch()) {
            throw new StockException('5秒内请勿重复扫码，请稍后再试', 2);
        }

        $before = (int)$part['stock'];
        $after  = $before + $qty;

        $this->db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
           ->execute([$after, $part['id'], $this->dataUid]);

        $remark = $orderNo !== '' ? '扫码入库 订单:' . $orderNo : '扫码入库';
        $scanSubtotal = ($unitCost > 0 && !$isSample) ? round($qty * $unitCost, 4) : 0;

        $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'scan_in', $qty, $before, $after, $unitCost, $isSample, $scanSubtotal, $remark]);
        $this->db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'in', $qty, $before, $after, $remark]);
        $scanLogId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'scan_in', 'part', (int)$part['id'], "扫码入库 part_id:{$part['id']} qty:{$qty} barcode:{$barcode}");

        return [
            'ok'           => true,
            'type'         => 'scan_in',
            'part_id'      => $part['id'],
            'user_id'      => $this->uid,
            'model'        => $part['model'],
            'part_no'      => $barcode,
            'product_name' => $part['product_name'],
            'qty'          => $qty,
            'qty_before'   => $before,
            'qty_after'    => $after,
            'scan_log_id'  => $scanLogId,
            'remark'       => $remark,
            'created_at'   => date('Y-m-d H:i'),
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  扫码出库（scan_out case）
    // ──────────────────────────────────────────────────────────

    /**
     * 扫码出库
     *
     * @param array $p POST 参数
     * @return array {ok:true, type, part_id, model, ...}（扫码专用格式）
     * @throws StockException
     */
    public function scanOut(array $p): array
    {
        $barcode    = $this->safeStr($p['barcode'] ?? '');
        $internalId = $this->safeInt($p['internal_id'] ?? 0);
        $platCode   = $this->safeStr($p['platform_code'] ?? '');
        $platId     = $this->findPlatformIdByCode($platCode);
        $qty        = max(1, $this->safeInt($p['qty'] ?? 1));
        $orderNo    = $this->safeStr($p['order_no'] ?? '');

        // 内部二维码优先按 internal_id 匹配；否则按条码匹配
        if ($internalId > 0) {
            $part = $this->findPartByInternalId($internalId);
            if (!$part) throw new StockException('内部物料ID不存在: ' . $internalId, 2);
            $barcode = $part['platform_part_no'] ?: $part['customer_part_no'] ?: ('内部ID:' . $internalId);
        } else {
            if ($barcode === '') throw new StockException('条码不能为空', 2);
            $part = $this->findPartByBarcode($barcode, $platId);
            if (!$part) throw new StockException('未找到该元件: ' . $barcode, 2);
        }

        // 服务端防重复：同一用户同一元件5秒内不可重复扫码出库（适用内部二维码）
        $dupCheck = $this->db->prepare(
            "SELECT id FROM scan_log WHERE user_id=? AND part_id=? AND scan_type='out' AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1"
        );
        $dupCheck->execute([$this->uid, $part['id']]);
        if ($dupCheck->fetch()) {
            throw new StockException('5秒内请勿重复扫码，请稍后再试', 2);
        }

        $before = (int)$part['stock'];
        $after  = max(0, $before - $qty);
        $actual = $before - $after; // 实际出库数量
        if ($actual <= 0) throw new StockException('库存不足，无法出库', 2);

        $this->db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
           ->execute([$after, $part['id'], $this->dataUid]);

        $remark = $orderNo !== '' ? '扫码出库 订单:' . $orderNo : '扫码出库';
        // 写入 stock_log（出库不记录采购成本和样品标记，subtotal=0）
        $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'scan_out', -$actual, $before, $after, 0, 0, 0, $remark]);
        $this->db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'out', $actual, $before, $after, $remark]);
        $scanLogId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'scan_out', 'part', (int)$part['id'], "扫码出库 part_id:{$part['id']} qty:{$actual} barcode:{$barcode}");

        return [
            'ok'           => true,
            'type'         => 'scan_out',
            'part_id'      => $part['id'],
            'user_id'      => $this->uid,
            'model'        => $part['model'],
            'part_no'      => $barcode,
            'product_name' => $part['product_name'],
            'qty'          => $actual,
            'qty_before'   => $before,
            'qty_after'    => $after,
            'scan_log_id'  => $scanLogId,
            'remark'       => $remark,
            'created_at'   => date('Y-m-d H:i'),
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  撤销扫码（scan_undo case）
    // ──────────────────────────────────────────────────────────

    /**
     * 撤销扫码操作（入库撤销→出库，出库撤销→入库）
     *
     * @param int $scanLogId scan_log 表记录ID
     * @return array {ok:true, part_id, model, ...}（扫码专用格式）
     * @throws StockException
     */
    public function scanUndo(int $scanLogId): array
    {
        if ($scanLogId <= 0) throw new StockException('参数无效', 2);

        // 查找扫码记录，确保属于当前用户
        $sl = $this->db->prepare("SELECT * FROM scan_log WHERE id=? AND user_id=?");
        $sl->execute([$scanLogId, $this->uid]);
        $scan = $sl->fetch();
        if (!$scan) throw new StockException('记录不存在或无权操作', 2);

        // 防止重复撤销
        if (strpos($scan['remark'] ?? '', '[已撤销]') !== false) {
            throw new StockException('该记录已撤销，不可重复操作', 2);
        }

        // 查找元件
        $partStmt = $this->db->prepare("SELECT * FROM parts WHERE id=? AND user_id=?");
        $partStmt->execute([$scan['part_id'], $this->dataUid]);
        $part = $partStmt->fetch();
        if (!$part) throw new StockException('元件不存在', 2);

        $before = (int)$part['stock'];
        // 反向操作：入库撤销→出库，出库撤销→入库
        if ($scan['scan_type'] === 'in') {
            $after      = max(0, $before - (int)$scan['qty']);
            $actualUndo = $before - $after;
            $logType    = 'scan_undo_in';
            $logQty     = -$actualUndo;
            $remark     = '撤销扫码入库';
        } else {
            $after      = $before + (int)$scan['qty'];
            $actualUndo = (int)$scan['qty'];
            $logType    = 'scan_undo_out';
            $logQty     = $actualUndo;
            $remark     = '撤销扫码出库';
        }

        $this->db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
           ->execute([$after, $part['id'], $this->dataUid]);
        // 写入 stock_log（撤销操作不记录采购成本和样品标记，subtotal=0）
        $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $scan['platform_part_no'], $logType, $logQty, $before, $after, 0, 0, 0, $remark]);
        // 更新 scan_log 标记已撤销
        $this->db->prepare("UPDATE scan_log SET remark=CONCAT(remark,' [已撤销]') WHERE id=?")
           ->execute([$scanLogId]);
        traceLog($this->uid, 'scan_undo', 'scan_log', $scanLogId, "撤销扫码 scan_log_id:{$scanLogId} part_id:{$part['id']} type:{$logType}");

        return [
            'ok'         => true,
            'part_id'    => $part['id'],
            'user_id'    => $this->uid,
            'model'      => $part['model'],
            'part_no'    => $scan['platform_part_no'],
            'qty_before' => $before,
            'qty_after'  => $after,
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  出入库记录删除
    // ──────────────────────────────────────────────────────────

    /**
     * 删除单条出入库记录
     *
     * @param int $logId stock_log 表记录ID
     * @return array ['deleted' => true, 'log_id' => $logId]
     * @throws StockException
     */
    public function deleteLog(int $logId): array
    {
        if ($logId <= 0) throw new StockException('参数无效', 4);
        // 验证记录属于当前用户（通过 parts 表关联验证）并取创建时间用于留存检查
        $check = $this->db->prepare("SELECT l.id, l.create_time FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id=? AND p.user_id=?");
        $check->execute([$logId, $this->dataUid]);
        $row = $check->fetch();
        if (!$row) throw new StockException('记录不存在或无权操作', 3);
        // 留存期检查：未到期禁止删除
        if (!isRetentionExpired((string)$row['create_time'])) {
            $days = getRetentionDays();
            throw new StockException("记录未满 {$days} 天留存期，禁止删除", 5);
        }
        $this->db->prepare("DELETE FROM stock_log WHERE id=?")->execute([$logId]);
        traceLog($this->uid, 'delete_log', 'stock_log', $logId, "删除出入库记录 log_id:{$logId}");
        return ['deleted' => true, 'log_id' => $logId];
    }

    /**
     * 批量删除出入库记录
     *
     * @param array $logIds stock_log 表记录ID数组
     * @return array ['deleted' => count, 'skipped' => count]
     * @throws StockException
     */
    public function batchDeleteLogs(array $logIds): array
    {
        $logIds = $this->filterValidIds($logIds);
        if (empty($logIds)) throw new StockException('未选择有效记录', 4);
        $in = implode(',', array_fill(0, count($logIds), '?'));
        $valid = $this->db->prepare("SELECT l.id, l.create_time FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id IN ($in) AND p.user_id=?");
        $valid->execute([...$logIds, $this->dataUid]);
        $rows = $valid->fetchAll();
        if (empty($rows)) throw new StockException('无权删除这些记录', 3);
        // 留存期过滤：只删除已过期的记录
        $deletable = [];
        $skipped = 0;
        foreach ($rows as $r) {
            if (isRetentionExpired((string)$r['create_time'])) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }
        if (empty($deletable)) {
            $days = getRetentionDays();
            throw new StockException("所选记录均未满 {$days} 天留存期，禁止删除", 5);
        }
        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("DELETE FROM stock_log WHERE id IN ($inV)")->execute($deletable);
        traceLog($this->uid, 'batch_delete_logs', 'stock_log', 0, "批量删除出入库记录 count:" . count($deletable) . ($skipped > 0 ? " skipped:{$skipped}" : ''));
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }

    // ──────────────────────────────────────────────────────────
    //  内部辅助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 多字段匹配元件：优先按平台+编号查，其次按编号查，最后按客户料号查
     */
    private function findPartByBarcode(string $barcode, int $platId): ?array
    {
        $part = null;
        if ($platId > 0) {
            $stmt = $this->db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND platform_id=? AND user_id=?");
            $stmt->execute([$barcode, $platId, $this->dataUid]);
            $part = $stmt->fetch();
        }
        if (!$part) {
            $stmt = $this->db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND user_id=?");
            $stmt->execute([$barcode, $this->dataUid]);
            $part = $stmt->fetch();
        }
        if (!$part) {
            $stmt = $this->db->prepare("SELECT * FROM parts WHERE customer_part_no=? AND user_id=?");
            $stmt->execute([$barcode, $this->dataUid]);
            $part = $stmt->fetch();
        }
        return $part ?: null;
    }

    /**
     * 按 internal_id 匹配元件（全平台唯一，用于内部物料二维码扫码）
     */
    private function findPartByInternalId(int $internalId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM parts WHERE internal_id=? AND user_id=?");
        $stmt->execute([$internalId, $this->dataUid]);
        $part = $stmt->fetch();
        return $part ?: null;
    }

    /**
     * 根据平台代码查询平台ID（用于 findPartByBarcode 场景）
     * 平台代码在数据库重建后仍然稳定，比 platform_id 更可靠
     */
    private function findPlatformIdByCode(string $code): int
    {
        if ($code === '') return 0;
        $stmt = $this->db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=? LIMIT 1");
        $stmt->execute([$code, $this->dataUid]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : 0;
    }

    private function safeStr(mixed $v): string
    {
        return trim((string)($v ?? ''));
    }

    private function safeInt(mixed $v): int
    {
        return (int)($v ?? 0);
    }

    private function filterValidIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $out[] = $id;
        }
        return array_values(array_unique($out));
    }
}
