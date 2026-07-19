<?php
declare(strict_types=1);

/**
 * BOM 业务模块（V1 全新基线）
 *
 * 封装 BOM 项目与物料项的所有数据操作（增删改查、批量删除、一键出库、替代料出库）。
 * 文件导入（import_bom）因涉及文件上传保留在 bom_manager.php 页面直提交。
 * CSV 导出（export_bom）保留在 bom_manager.php GET 直下载。
 * 本模块仅处理可标准化的写操作，由 action.php 统一调用。
 */

final class BomManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
    }

    /**
     * 根据编号/型号匹配库存元件（全平台匹配，不按平台过滤）
     * 匹配优先级：编号精确 → 型号精确 → 编号精确（跨平台已合并到第一步）
     */
    public function matchPart(string $partNo, string $model): ?array
    {
        if ($partNo !== '') {
            $stmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives,is_incomplete FROM parts WHERE user_id=? AND platform_part_no=? LIMIT 1");
            $stmt->execute([$this->dataUid, $partNo]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        if ($model !== '') {
            $stmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives,is_incomplete FROM parts WHERE user_id=? AND model=? LIMIT 1");
            $stmt->execute([$this->dataUid, $model]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        return null;
    }

    /**
     * 校验 BOM 项目归属并返回项目行
     */
    public function loadOwnedProject(int $pid): array
    {
        $stmt = $this->db->prepare("SELECT id,user_id,name,description,plat_id,created_at,updated_at FROM bom_projects WHERE id=? AND user_id=?");
        $stmt->execute([$pid, $this->dataUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BomException('BOM 项目不存在或无权访问');
        return $row;
    }

    /**
     * 列出当前用户所有 BOM 项目（首页批量加入 BOM 选择器用）
     * @return array<int, array{id:int,name:string,item_count:int,updated_at:string}>
     */
    public function listProjects(): array
    {
        $stmt = $this->db->prepare("SELECT bp.id,bp.name,bp.updated_at,
            (SELECT COUNT(*) FROM bom_items bi WHERE bi.project_id=bp.id) AS item_count
            FROM bom_projects bp
            WHERE bp.user_id=?
            ORDER BY bp.updated_at DESC");
        $stmt->execute([$this->dataUid]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'item_count' => (int)$r['item_count'],
            'updated_at' => (string)$r['updated_at'],
        ], $list);
    }

    /**
     * 查询指定 BOM 项目的物料明细列表（支持状态筛选 + 分页 + 库存预校验）
     *
     * 对齐项目通用列表API规范：先按 status 过滤数据集，再对过滤后的数据做分页与 total 统计。
     * summary 始终保持全量统计（卡片显示三种状态的完整数量），分页 total 跟随筛选结果。
     *
     * @param int   $projectId BOM 项目 ID
     * @param array $params    查询参数（filter, page, per_page）
     * @return array{items:array, total:int, page:int, per_page:int, total_page:int, summary:array}
     */
    public function listItems(int $projectId, array $params): array
    {
        $project = $this->loadOwnedProject($projectId);

        // 状态筛选白名单（与 bom_manager.php 顶部 filter 校验保持一致）
        $filter = (string)($params['filter'] ?? '');
        if (!in_array($filter, ['ok', 'insufficient', 'not_found'], true)) $filter = '';

        $perPage = intval($params['per_page'] ?? ($_COOKIE['per_page_bom'] ?? 25));
        $perPage = max(10, min(50, $perPage));
        $page    = max(1, intval($params['page'] ?? 1));

        // 拉取全部 BOM 物料行（含 part_id / model / platform_part_no / qty / sort_order）
        $is = $this->db->prepare("SELECT id,part_id,platform_part_no,model,qty,sort_order FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
        $is->execute([$projectId]);
        $rawItems = $is->fetchAll(PDO::FETCH_ASSOC);

        // 预编译 parts 表查询（含残缺物料标识 is_incomplete，用于状态分类）
        $partStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives,is_incomplete,platform_id,location,customer_part_no,low_stock_threshold,remark FROM parts WHERE id=? AND user_id=?");

        $summary = ['ok' => 0, 'insufficient' => 0, 'not_found' => 0];
        $items = [];
        foreach ($rawItems as $it) {
            $partNo = (string)$it['platform_part_no'];
            $model  = (string)$it['model'];
            $qty    = (int)$it['qty'];
            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([$it['part_id'], $this->dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$part) $part = $this->matchPart($partNo, $model);

            $row = $it;
            $row['part'] = $part;
            // 残缺物料（is_incomplete=1）统一归类为未匹配，与页面渲染逻辑保持一致
            if ($part && (int)($part['is_incomplete'] ?? 0) === 1) {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                $row['alt_parts'] = [];
            } elseif ($part) {
                $stock = (int)$part['stock'];
                $row['stock'] = $stock;
                if ($stock >= $qty) {
                    $row['status'] = 'ok';
                    $row['gap'] = 0;
                    $summary['ok']++;
                } else {
                    $row['status'] = 'insufficient';
                    $row['gap'] = $qty - $stock;
                    $summary['insufficient']++;
                }
                $alts = array_filter(array_map('trim', explode(',', (string)($part['alternatives'] ?? ''))));
                $row['alternatives'] = $alts;
                $row['alt_parts'] = [];
                if (!empty($alts)) {
                    $altIds = array_filter(array_map('intval', $alts));
                    if (!empty($altIds)) {
                        $in = implode(',', array_fill(0, count($altIds), '?'));
                        $altPartStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name FROM parts WHERE id IN ($in) AND user_id=?");
                        $altPartStmt->execute([...$altIds, $this->dataUid]);
                        $row['alt_parts'] = $altPartStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            } else {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                // 未匹配时：搜索 alternatives 字段包含本物料 part_id 的元件（反向查找替代料）
                $altParts = [];
                if (!empty($part)) {
                    $altStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name FROM parts WHERE user_id=? AND alternatives LIKE ? LIMIT 5");
                    $altStmt->execute([$this->dataUid, '%' . $part['id'] . '%']);
                    $altParts = $altStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $row['alt_parts'] = $altParts;
            }
            $items[] = $row;
        }

        // 后端先按 status 过滤数据集，再对过滤后的数据做分页与 total 统计
        if ($filter !== '') {
            $items = array_values(array_filter($items, fn($row) => $row['status'] === $filter));
        }
        $total = count($items);
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        return [
            'items'       => $pageItems,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_page'  => $totalPage,
            'summary'     => $summary,
            'project'     => $project,
        ];
    }

    /**
     * 创建 BOM 项目
     * @return array{project_id:int}
     */
    public function createProject(array $post): array
    {
        $name   = trim((string)($post['name'] ?? ''));
        $desc   = trim((string)($post['description'] ?? ''));
        $platId = max(1, (int)($post['plat_id'] ?? 1));
        if ($name === '') {
            throw new BomException('项目名称不能为空');
        }
        // 平台归属校验
        $chk = $this->db->prepare("SELECT id FROM platforms WHERE id=? AND user_id=?");
        $chk->execute([$platId, $this->dataUid]);
        if (!$chk->fetch()) $platId = 1;

        $this->db->prepare("INSERT INTO bom_projects (user_id,name,description,plat_id) VALUES (?,?,?,?)")
                 ->execute([$this->dataUid, $name, $desc, $platId]);
        $newId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'bom_project_create', 'bom_project', $newId, '创建BOM项目:' . $name);
        return ['project_id' => $newId, 'name' => $name];
    }

    /**
     * 更新 BOM 项目（名称/描述）
     */
    public function updateProject(array $post): array
    {
        $pid  = (int)($post['project_id'] ?? 0);
        $pr   = $this->loadOwnedProject($pid);
        $name = trim((string)($post['name'] ?? ''));
        $desc = trim((string)($post['description'] ?? ''));
        if ($name === '') {
            throw new BomException('项目名称不能为空');
        }
        $this->db->prepare("UPDATE bom_projects SET name=?,description=? WHERE id=? AND user_id=?")
                 ->execute([$name, $desc, $pid, $this->dataUid]);
        traceLog($this->uid, 'bom_project_update', 'bom_project', $pid, '编辑BOM项目:' . $name);
        return ['project_id' => $pid, 'name' => $name];
    }

    /**
     * 删除 BOM 项目（级联删除物料项）
     */
    public function deleteProject(int $pid): array
    {
        $this->loadOwnedProject($pid);
        $this->db->prepare("DELETE FROM bom_items WHERE project_id=?")->execute([$pid]);
        $this->db->prepare("DELETE FROM bom_projects WHERE id=? AND user_id=?")->execute([$pid, $this->dataUid]);
        traceLog($this->uid, 'bom_project_delete', 'bom_project', $pid, '删除BOM项目ID:' . $pid);
        return ['project_id' => $pid];
    }

    /**
     * 添加单个物料到 BOM 项目
     */
    public function addItem(array $post): array
    {
        $pid     = (int)($post['project_id'] ?? 0);
        $pr      = $this->loadOwnedProject($pid);
        $partNo  = trim((string)($post['platform_part_no'] ?? ''));
        $model   = trim((string)($post['model'] ?? ''));
        $qty     = max(1, (int)($post['qty'] ?? 1));
        if ($partNo === '' && $model === '') {
            throw new BomException('编号和型号不能同时为空');
        }
        $matchedPart  = $this->matchPart($partNo, $model);
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();
        $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$pid, $matchedPart['id'] ?? null, $partNo, $model, $qty, $matchedPart ? 1 : 0, $sortOrder]);
        return ['project_id' => $pid, 'matched' => $matchedPart ? 1 : 0];
    }

    /**
     * 通过 part_id 直接添加库内已有物料到 BOM（不允许新建元件）。
     * 用于 BOM 页面「手动添加物料」弹窗：仅可选择库内已有物料。
     */
    public function addItemByPartId(array $post): array
    {
        $pid    = (int)($post['project_id'] ?? 0);
        $pr     = $this->loadOwnedProject($pid);
        $partId = (int)($post['part_id'] ?? 0);
        $qty    = max(1, (int)($post['qty'] ?? 1));
        if ($partId <= 0) {
            throw new BomException('请从库内选择一个物料');
        }
        // 验证 part 归属当前用户且非残缺物料
        $pStmt = $this->db->prepare("SELECT id, platform_part_no, model, is_incomplete FROM parts WHERE id=? AND user_id=? LIMIT 1");
        $pStmt->execute([$partId, $this->dataUid]);
        $part = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            throw new BomException('所选物料不存在或无权访问');
        }
        if ((int)$part['is_incomplete'] === 1) {
            throw new BomException('残缺物料无法直接添加到 BOM，请先补全信息');
        }
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();
        $partNo = (string)$part['platform_part_no'];
        $model  = (string)$part['model'];
        $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$pid, $partId, $partNo, $model, $qty, 1, $sortOrder]);
        return ['project_id' => $pid, 'matched' => 1, 'part_id' => $partId];
    }

    /**
     * 批量添加库内已有物料到 BOM 项目（首页批量加入 BOM 用）
     * - 仅添加 is_incomplete=0 的正常物料，残缺物料自动跳过
     * - 已存在于该项目（part_id 相同）的物料自动跳过，避免重复
     * @return array{project_id:int, added:int, skipped:int, incomplete:int}
     */
    public function batchAddByPartIds(array $post): array
    {
        $pid    = (int)($post['project_id'] ?? 0);
        $pr     = $this->loadOwnedProject($pid);
        $ids    = $post['part_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids    = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        $qty    = max(1, (int)($post['qty'] ?? 1));
        if (empty($ids)) {
            throw new BomException('未选择任何物料');
        }
        // 读取项目下已存在的 part_id 集合（避免重复添加）
        $exStmt = $this->db->prepare("SELECT part_id FROM bom_items WHERE project_id=? AND part_id IS NOT NULL");
        $exStmt->execute([$pid]);
        $exSet = array_flip(array_map('intval', array_column($exStmt->fetchAll(PDO::FETCH_ASSOC), 'part_id')));

        // 读取当前 sort_order 起点
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();

        // 批量查询物料（一次性获取所有选中物料，过滤残缺物料）
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $qStmt = $this->db->prepare("SELECT id,platform_part_no,model,is_incomplete FROM parts WHERE id IN ($placeholders) AND user_id=?");
        $qStmt->execute([...$ids, $this->dataUid]);
        $parts = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $ins = $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order) VALUES (?,?,?,?,?,?,?)");
        $added = 0; $skipped = 0; $incomplete = 0;
        foreach ($parts as $p) {
            $partId = (int)$p['id'];
            if ((int)$p['is_incomplete'] === 1) { $incomplete++; continue; }
            if (isset($exSet[$partId])) { $skipped++; continue; }
            $ins->execute([$pid, $partId, (string)$p['platform_part_no'], (string)$p['model'], $qty, 1, $sortOrder++]);
            $added++;
        }
        // 未查询到的物料（已被删除或无权限）也算跳过
        $notFound = count($ids) - count($parts);
        $skipped += $notFound;
        traceLog($this->uid, 'bom_batch_add', 'bom_item', $pid, "批量添加BOM物料: added={$added} skipped={$skipped} incomplete={$incomplete} pid={$pid}");
        return ['project_id' => $pid, 'added' => $added, 'skipped' => $skipped, 'incomplete' => $incomplete];
    }

    /**
     * 删除单个 BOM 物料项
     */
    public function deleteItem(int $itemId): array
    {
        $this->db->prepare("DELETE bi FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id=? AND bp.user_id=?")
                 ->execute([$itemId, $this->dataUid]);
        return ['item_id' => $itemId];
    }

    /**
     * 批量删除 BOM 物料项
     */
    public function batchDeleteItems(array $post): array
    {
        $pid = (int)($post['project_id'] ?? 0);
        $ids = $post['item_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if (empty($ids)) {
            throw new BomException('未选择任何物料');
        }
        $this->loadOwnedProject($pid);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$this->dataUid]);
        $this->db->prepare("DELETE bi FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id IN ($placeholders) AND bp.user_id=?")
                 ->execute($params);
        traceLog($this->uid, 'bom_batch_delete', 'bom_item', 0, '批量删除BOM物料:' . count($ids) . '条');
        return ['deleted_count' => count($ids)];
    }

    /**
     * BOM 一键出库（事务性扣减，出库所有库存充足项）
     * @return array{stats:array, message:string}
     */
    public function bomCheckout(int $pid): array
    {
        $pr     = $this->loadOwnedProject($pid);
        $itemStmt = $this->db->prepare("SELECT id,part_id,platform_part_no,model,qty FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
        $itemStmt->execute([$pid]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['matched' => 0, 'not_found' => 0, 'insufficient' => 0, 'total_qty' => 0, 'total_rows' => 0];
        $this->db->beginTransaction();
        try {
            foreach ($items as $it) {
                $stats['total_rows']++;
                $partNo = (string)$it['platform_part_no'];
                $model  = (string)$it['model'];
                $qty    = (int)$it['qty'];
                $existing = null;
                if ((int)$it['part_id'] > 0) {
                    $stmt = $this->db->prepare("SELECT id,stock,model,platform_part_no FROM parts WHERE id=? AND user_id=?");
                    $stmt->execute([$it['part_id'], $this->dataUid]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existing) $existing = $this->matchPart($partNo, $model);
                if (!$existing) { $stats['not_found']++; continue; }
                if ((int)$existing['stock'] < $qty) { $stats['insufficient']++; continue; }
                $newStock = (int)$existing['stock'] - $qty;
                $this->db->prepare("UPDATE parts SET stock=?,update_time=NOW() WHERE id=? AND user_id=?")
                         ->execute([$newStock, $existing['id'], $this->dataUid]);
                $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                         ->execute([$this->uid, $existing['id'], $existing['platform_part_no'], 'bom_out', $qty, (int)$existing['stock'], $newStock, 0, 0, 0, 'BOM出库:' . $pr['name']]);
                $stats['matched']++;
                $stats['total_qty'] += $qty;
            }
            $this->db->commit();
            $this->db->prepare("INSERT INTO bom_exports (user_id,file_name,total_rows,matched,not_found,insufficient,total_qty) VALUES (?,?,?,?,?,?,?)")
                     ->execute([$this->uid, 'BOM:' . $pr['name'], $stats['total_rows'], $stats['matched'], $stats['not_found'], $stats['insufficient'], $stats['total_qty']]);
            traceLog($this->uid, 'bom_checkout', 'bom_project', $pid, 'BOM出库:' . $pr['name'] . ' 成功' . $stats['matched'] . '件 总量:' . $stats['total_qty']);
            $message = '出库完成：成功 ' . $stats['matched'] . ' 件，未匹配 ' . $stats['not_found'] . ' 件，库存不足 ' . $stats['insufficient'] . ' 件';
            return ['stats' => $stats, 'message' => $message];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 使用替代料出库（单条 BOM 项切换为替代料扣减）
     */
    public function useAlternative(array $post): array
    {
        $pid        = (int)($post['project_id'] ?? 0);
        $itemId     = (int)($post['item_id'] ?? 0);
        $altPartId  = (int)($post['alt_part_id'] ?? 0);
        $pr         = $this->loadOwnedProject($pid);

        $itStmt = $this->db->prepare("SELECT id,part_id,qty FROM bom_items WHERE id=? AND project_id=?");
        $itStmt->execute([$itemId, $pid]);
        $item = $itStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new BomException('BOM物料项不存在');

        $apStmt = $this->db->prepare("SELECT id,stock,platform_part_no,model FROM parts WHERE id=? AND user_id=?");
        $apStmt->execute([$altPartId, $this->dataUid]);
        $altPart = $apStmt->fetch(PDO::FETCH_ASSOC);
        if (!$altPart) throw new BomException('替代料不存在');

        $qty = (int)$item['qty'];
        if ((int)$altPart['stock'] < $qty) {
            throw new BomException('替代料库存不足（' . $altPart['stock'] . ' < ' . $qty . '）');
        }
        $newStock = (int)$altPart['stock'] - $qty;
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE parts SET stock=?,update_time=NOW() WHERE id=? AND user_id=?")
                     ->execute([$newStock, $altPart['id'], $this->dataUid]);
            $this->db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,unit_cost,is_sample,subtotal,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                     ->execute([$this->uid, $altPart['id'], $altPart['platform_part_no'], 'bom_out', $qty, (int)$altPart['stock'], $newStock, 0, 0, 0, 'BOM替代料出库:' . $pr['name']]);
            $this->db->prepare("UPDATE bom_items SET part_id=?, matched=1 WHERE id=?")
                     ->execute([$altPart['id'], $itemId]);
            $this->db->commit();
            traceLog($this->uid, 'bom_use_alt', 'part', (int)$altPart['id'], 'BOM替代料出库:' . $pr['name'] . ' part_id:' . $altPart['id'] . ' qty:' . $qty);
            return [
                'message'  => '替代料出库成功：' . $altPart['platform_part_no'] . ' 扣减 ' . $qty . ' 件',
                'part_id'  => (int)$altPart['id'],
                'new_stock' => $newStock,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * 替换 BOM 物料行（与 useAlternative 双功能分离）。
     *
     * 业务差异：
     *   - useAlternative（绑定）：扣减替代料库存，建立关联
     *   - replaceWithAlternative（替换）：仅将 BOM 物料行参数覆盖为库存标准物料
     *     不入库、不新增物料、不改变库存池
     *
     * 适用场景：非标外购 / 临时打样 / 不想录入库存 / 库内已有标准物料。
     * 替换后本条 BOM 直接引用标准库存物料，废弃原残缺数据。
     */
    public function replaceWithAlternative(array $post): array
    {
        $pid       = (int)($post['project_id'] ?? 0);
        $itemId    = (int)($post['item_id'] ?? 0);
        $altPartId = (int)($post['alt_part_id'] ?? 0);
        $pr        = $this->loadOwnedProject($pid);

        $itStmt = $this->db->prepare("SELECT id,part_id,qty FROM bom_items WHERE id=? AND project_id=?");
        $itStmt->execute([$itemId, $pid]);
        $item = $itStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new BomException('BOM物料项不存在');

        $apStmt = $this->db->prepare("SELECT id,platform_part_no,model FROM parts WHERE id=? AND user_id=?");
        $apStmt->execute([$altPartId, $this->dataUid]);
        $altPart = $apStmt->fetch(PDO::FETCH_ASSOC);
        if (!$altPart) throw new BomException('替换目标物料不存在');

        // 仅更新 bom_items 的引用与冗余字段，库存池不动，stock_log 不写
        $this->db->prepare("UPDATE bom_items SET part_id=?, platform_part_no=?, model=?, matched=1 WHERE id=?")
                 ->execute([
                     (int)$altPart['id'],
                     (string)$altPart['platform_part_no'],
                     (string)$altPart['model'],
                     $itemId,
                 ]);

        traceLog($this->uid, 'bom_replace_alt', 'part', (int)$altPart['id'], 'BOM替换为库存标准物料:' . $pr['name'] . ' item_id:' . $itemId . ' part_id:' . $altPart['id']);
        return [
            'message'      => '已替换为库存标准物料：' . $altPart['platform_part_no'],
            'part_id'      => (int)$altPart['id'],
            'item_id'      => $itemId,
        ];
    }
}

/**
 * BOM 业务异常
 */
final class BomException extends Exception
{
    public int $errCode;
    public function __construct(string $msg, int $code = 1)
    {
        parent::__construct($msg);
        $this->errCode = $code;
    }
}
