<?php
declare(strict_types=1);
/**
 * 平台管理模块（v1.1.0 正式版）
 *
 * 封装所有平台管理业务逻辑：
 * - 平台列表查询（含 code 字段，供二维码 pid 标识使用）
 * - 单个平台详情查询
 * - 平台类型查询（供前端弹窗异步读取，区分 standard/loose）
 * - 添加/编辑/删除平台（含级联清理 stock_log/price_history/scan_log/part_categories/parts）
 * - 设置默认平台
 * - 批量更新URL模板
 *
 * 设计原则：
 * - 方法返回纯数组，由入口文件决定输出格式
 * - 校验失败抛出 PlatformException，入口文件统一 catch
 * - 所有数据库操作使用参数化查询，杜绝 SQL 注入
 * - 平台归属：使用 dataUid（子用户继承父用户平台）
 */

/**
 * 平台管理业务异常
 */
class PlatformException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message, $errCode);
        $this->errCode = $errCode;
    }
}

/**
 * 平台管理器
 */
final class PlatformManager
{
    private PDO $db;
    private int $uid;       // 当前操作用户ID（用于日志记录）
    private int $dataUid;   // 数据所属用户ID（子用户继承父用户数据）

    /** 允许的平台类型白名单 */
    private const ALLOWED_TYPES = ['standard', 'loose'];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid      = $uid;
        $this->dataUid = $dataUid;
    }

    // ──────────────────────────────────────────────────────────
    //  查询方法
    // ──────────────────────────────────────────────────────────

    /**
     * 平台列表（含 code 字段，供前端下拉和二维码 pid 标识使用）
     *
     * @return array {platforms: [...]}
     */
    public function listPlatforms(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, code, name, url_template, is_default, platform_type
             FROM platforms WHERE user_id=? ORDER BY id"
        );
        $stmt->execute([$this->dataUid]);
        $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($platforms as &$p) {
            $p['id']             = (int)$p['id'];
            $p['is_default']     = (int)$p['is_default'];
            $p['platform_type']  = in_array($p['platform_type'] ?? '', self::ALLOWED_TYPES, true)
                ? $p['platform_type'] : 'standard';
        }
        unset($p);

        return ['platforms' => $platforms];
    }

    /**
     * 单个平台完整详情
     *
     * @param int $id 平台ID
     * @return array {platform: {...}}
     * @throws PlatformException 平台不存在
     */
    public function getPlatform(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);

        $stmt = $this->db->prepare(
            "SELECT id, code, name, url_template, is_default, platform_type
             FROM platforms WHERE id=? AND user_id=?"
        );
        $stmt->execute([$id, $this->dataUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformException('平台不存在或无权访问', 404);

        $row['id']             = (int)$row['id'];
        $row['is_default']     = (int)$row['is_default'];
        $row['platform_type']  = in_array($row['platform_type'] ?? '', self::ALLOWED_TYPES, true)
            ? $row['platform_type'] : 'standard';

        // 统计该平台下元件数量
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE platform_id=? AND user_id=?");
        $cntStmt->execute([$id, $this->dataUid]);
        $row['parts_count'] = (int)$cntStmt->fetchColumn();

        return ['platform' => $row];
    }

    /**
     * 单个平台的类型属性（供前端弹窗异步查询，区分 standard/loose）
     *
     * @param int $id 平台ID
     * @return array {platform_id, code, name, platform_type, url_template}
     * @throws PlatformException 平台不存在
     */
    public function getPlatformType(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：platform_id 必填', 4);

        $stmt = $this->db->prepare(
            "SELECT id, code, name, platform_type, url_template
             FROM platforms WHERE id=? AND user_id=?"
        );
        $stmt->execute([$id, $this->dataUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformException('平台不存在或无权访问', 404);

        $ptype = in_array($row['platform_type'] ?? '', self::ALLOWED_TYPES, true)
            ? $row['platform_type'] : 'standard';

        return [
            'platform_id'   => (int)$row['id'],
            'code'          => $row['code'] ?? '',
            'name'          => $row['name'] ?? '',
            'platform_type' => $ptype,
            'url_template'  => $row['url_template'] ?? '',
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  写操作
    // ──────────────────────────────────────────────────────────

    /**
     * 添加平台
     *
     * @param array $p {plat_code, plat_name, plat_url, platform_type}
     * @return array {platform_id, code, name}
     * @throws PlatformException 参数错误或代码重复
     */
    public function addPlatform(array $p): array
    {
        $code  = $this->safeStr($p['plat_code'] ?? '');
        $name  = $this->safeStr($p['plat_name'] ?? '');
        $url   = $this->safeStr($p['plat_url'] ?? '');
        $ptype = $this->normalizeType($p['platform_type'] ?? 'standard');

        // 参数校验
        if ($code === '' || $name === '') {
            throw new PlatformException('平台代码和名称不能为空', 4);
        }
        $this->assertUrlValid($url);

        // 代码唯一性校验
        if ($this->codeExists($code)) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        // 插入平台
        try {
            $this->db->prepare(
                "INSERT INTO platforms (user_id, code, name, url_template, platform_type) VALUES (?, ?, ?, ?, ?)"
            )->execute([$this->dataUid, $code, $name, $url, $ptype]);
        } catch (\Throwable $e) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        $newId = (int)$this->db->lastInsertId();

        // 默认平台自动设置：首个平台自动设为默认；若无默认平台则补设
        $this->ensureDefaultExists($newId);

        traceLog($this->uid, 'add_platform', 'platform', $newId, "添加平台 code:{$code} name:{$name}");

        return [
            'platform_id' => $newId,
            'code'        => $code,
            'name'        => $name,
        ];
    }

    /**
     * 编辑平台
     *
     * @param array $p {plat_id, plat_code, plat_name, plat_url, platform_type}
     * @return array {platform_id, code, name}
     * @throws PlatformException 参数错误或代码重复
     */
    public function editPlatform(array $p): array
    {
        $id    = (int)($p['plat_id'] ?? 0);
        $code  = $this->safeStr($p['plat_code'] ?? '');
        $name  = $this->safeStr($p['plat_name'] ?? '');
        $url   = $this->safeStr($p['plat_url'] ?? '');
        $ptype = $this->normalizeType($p['platform_type'] ?? 'standard');

        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);
        if ($code === '' || $name === '') {
            throw new PlatformException('平台代码和名称不能为空', 4);
        }
        $this->assertUrlValid($url);

        // 验证平台归属
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        // 代码唯一性校验（排除自身）
        if ($this->codeExists($code, $id)) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        try {
            $this->db->prepare(
                "UPDATE platforms SET code=?, name=?, url_template=?, platform_type=? WHERE id=? AND user_id=?"
            )->execute([$code, $name, $url, $ptype, $id, $this->dataUid]);
        } catch (\Throwable $e) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        traceLog($this->uid, 'edit_platform', 'platform', $id, "编辑平台 id:{$id} code:{$code}");

        return [
            'platform_id' => $id,
            'code'        => $code,
            'name'        => $name,
        ];
    }

    /**
     * 删除平台（含级联清理元件相关数据）
     *
     * @param int $id 平台ID
     * @return array {platform_id, deleted_parts}
     * @throws PlatformException 平台不存在或为最后一个
     */
    public function deletePlatform(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);

        // 至少保留 1 个平台
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
        $totalStmt->execute([$this->dataUid]);
        $totalPlatforms = (int)$totalStmt->fetchColumn();
        if ($totalPlatforms <= 1) {
            throw new PlatformException('至少保留一个平台，无法删除最后一个平台', 4);
        }

        // 验证平台归属
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        // 统计删除前该平台下的元件数
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE platform_id=? AND user_id=?");
        $cntStmt->execute([$id, $this->dataUid]);
        $delCount = (int)$cntStmt->fetchColumn();

        try {
            $this->db->beginTransaction();

            // 收集该平台下所有元件ID
            $partsStmt = $this->db->prepare("SELECT id FROM parts WHERE platform_id=? AND user_id=?");
            $partsStmt->execute([$id, $this->dataUid]);
            $partIds = array_column($partsStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

            if (!empty($partIds)) {
                $in = implode(',', array_fill(0, count($partIds), '?'));

                // 级联清理：stock_log / price_history / scan_log / part_categories
                $this->db->prepare("DELETE FROM stock_log WHERE part_id IN ($in) AND user_id=?")
                    ->execute([...$partIds, $this->dataUid]);
                $this->db->prepare("DELETE FROM price_history WHERE part_id IN ($in)")
                    ->execute($partIds);
                $this->db->prepare("DELETE FROM scan_log WHERE part_id IN ($in) AND user_id=?")
                    ->execute([...$partIds, $this->uid]);
                $this->db->prepare("DELETE FROM part_categories WHERE part_id IN ($in)")
                    ->execute($partIds);
            }

            // 删除该平台下所有元件
            $this->db->prepare("DELETE FROM parts WHERE platform_id=? AND user_id=?")
                ->execute([$id, $this->dataUid]);

            // 删除平台
            $delPlat = $this->db->prepare("DELETE FROM platforms WHERE id=? AND user_id=?");
            $delPlat->execute([$id, $this->dataUid]);
            if ($delPlat->rowCount() === 0) {
                $this->db->rollBack();
                throw new PlatformException('平台删除失败，请检查日志后重试', 4);
            }

            // 确保至少有一个默认平台
            $this->ensureDefaultExists();

            $this->db->commit();
        } catch (PlatformException $e) {
            try { $this->db->rollBack(); } catch (\Throwable $re) {}
            throw $e;
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $re) {}
            error_log('PlatformManager::deletePlatform error: ' . $e->getMessage());
            throw new PlatformException('平台删除失败，请检查日志后重试', 4);
        }

        traceLog($this->uid, 'delete_platform', 'platform', $id, "删除平台 id:{$id} 删除元件数:{$delCount}");

        return [
            'platform_id'    => $id,
            'deleted_parts'  => $delCount,
        ];
    }

    /**
     * 设为默认平台
     *
     * @param int $id 平台ID
     * @return array {platform_id}
     * @throws PlatformException 平台不存在
     */
    public function setDefault(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        // 清除所有默认标记，再设置当前为默认
        $this->db->prepare("UPDATE platforms SET is_default=0 WHERE user_id=?")
            ->execute([$this->dataUid]);
        $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")
            ->execute([$id, $this->dataUid]);

        traceLog($this->uid, 'set_default_platform', 'platform', $id, "设置默认平台 id:{$id}");

        return ['platform_id' => $id];
    }

    // ──────────────────────────────────────────────────────────
    //  私有辅助方法
    // ──────────────────────────────────────────────────────────

    /** 安全字符串过滤 */
    private function safeStr(mixed $v): string
    {
        return trim((string)$v);
    }

    /** 平台类型归一化 */
    private function normalizeType(string $type): string
    {
        return in_array($type, self::ALLOWED_TYPES, true) ? $type : 'standard';
    }

    /** URL 模板校验：非空时必须 http(s):// 开头 */
    private function assertUrlValid(string $url): void
    {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new PlatformException('URL 模板必须以 http:// 或 https:// 开头', 4);
        }
    }

    /** 检查平台代码是否已存在（编辑时排除自身） */
    private function codeExists(string $code, int $excludeId = 0): bool
    {
        if ($excludeId > 0) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND code=? AND id<>?");
            $stmt->execute([$this->dataUid, $code, $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND code=?");
            $stmt->execute([$this->dataUid, $code]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    /** 验证平台归属当前用户 */
    private function platformBelongsToUser(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM platforms WHERE id=? AND user_id=?");
        $stmt->execute([$id, $this->dataUid]);
        return (bool)$stmt->fetch();
    }

    /**
     * 确保至少有一个默认平台
     * - 传入 $newId 时：若当前无默认平台，则将其设为默认
     * - 不传 $newId 时：若无默认平台，则将第一个平台设为默认
     */
    private function ensureDefaultExists(int $newId = 0): void
    {
        $defStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND is_default=1");
        $defStmt->execute([$this->dataUid]);
        $hasDefault = (int)$defStmt->fetchColumn();

        if ($hasDefault === 0) {
            if ($newId > 0) {
                $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")
                    ->execute([$newId, $this->dataUid]);
            } else {
                $this->db->prepare("UPDATE platforms SET is_default=1 WHERE user_id=? ORDER BY id LIMIT 1")
                    ->execute([$this->dataUid]);
            }
        } elseif ($newId > 0) {
            // 首个平台自动设为默认：若这是第一个平台（total=1），强制设为默认
            $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
            $totalStmt->execute([$this->dataUid]);
            $total = (int)$totalStmt->fetchColumn();
            if ($total === 1) {
                $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")
                    ->execute([$newId, $this->dataUid]);
            }
        }
    }
}
