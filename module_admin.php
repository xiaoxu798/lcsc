<?php
declare(strict_types=1);

/**
 * 管理后台业务模块（v1.1.0 正式版基线）
 *
 * 封装网站设置、用户管理、邀请码、子用户权限等业务逻辑。
 * 所有数据操作通过此模块完成，前端仅做数据渲染展示。
 * 仅依赖 config.php 的全局函数与数据库连接。
 */

final class AdminManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private bool $isPrimary;

    /** 权限白名单：子用户可被授予的权限集合 */
    private const PERM_ALLOWLIST = [
        'can_edit', 'can_delete', 'can_import', 'can_manage_categories',
        'can_batch', 'can_export', 'can_scan', 'can_print',
    ];

    /** 网站设置可写字段白名单 */
    private const SETTING_FIELDS = [
        'site_title', 'register_mode', 'notice_content', 'notice_show_mode',
        'default_low_stock', 'theme_default', 'session_timeout',
        'version_check_url', 'version_changelog_url',
        'publish_version', 'publish_changelog_url', 'publish_download_url',
    ];

    /** 删除用户时需级联清理的业务表 */
    private const USER_DATA_TABLES = [
        'parts', 'stock_log', 'price_history', 'import_history',
        'import_errors', 'imported_files', 'categories',
        'notice_seen', 'scan_log', 'backup_log',
    ];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db        = $db;
        $this->uid       = $uid;
        $this->dataUid   = $dataUid;
        $this->isPrimary = isPrimaryAdmin();
    }

    // ════════════════════════════════════════════════════════════════
    //  网站设置
    // ════════════════════════════════════════════════════════════════

    /**
     * 保存网站设置（仅主管理员，不含 Logo 上传）
     * Logo 上传保留页面直提交（文件操作）
     */
    public function saveSettings(array $post): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }
        foreach (self::SETTING_FIELDS as $f) {
            if (isset($post[$f])) {
                setSetting($f, trim((string)$post[$f]));
            }
        }
        // 操作记录最低留存天数（最小7天，全系统统一）
        $retentionDays = max(7, (int)($post['retention_days'] ?? 30));
        setSetting('retention_days', (string)$retentionDays);
        // 版本自动检测开关
        setSetting('version_check_auto', isset($post['version_check_auto']) ? '1' : '0');

        $siteTitleVal = trim((string)($post['site_title'] ?? ''));
        $themeVal     = trim((string)($post['theme_default'] ?? ''));
        $regModeVal   = trim((string)($post['register_mode'] ?? ''));
        $thresholdVal = trim((string)($post['default_low_stock'] ?? ''));
        $timeoutVal   = trim((string)($post['session_timeout'] ?? ''));
        traceLog($this->uid, 'save_settings', 'setting', 0, "标题:{$siteTitleVal} 主题:{$themeVal} 注册:{$regModeVal} 阈值:{$thresholdVal} 超时:{$timeoutVal} 留存:{$retentionDays}天");

        return ['retention_days' => $retentionDays];
    }

    /**
     * 保存普通管理员全局配置（阈值/公告/版本检测）
     */
    public function saveUserConfig(array $post): array
    {
        $thresh = max(0, intval($post['default_low_stock'] ?? 10));
        setUserSetting($this->uid, 'default_low_stock', (string)$thresh);
        $subNotice     = trim((string)($post['sub_notice_content'] ?? ''));
        $subNoticeMode = trim((string)($post['sub_notice_mode'] ?? 'off'));
        setUserSetting($this->uid, 'sub_notice_content', $subNotice);
        setUserSetting($this->uid, 'sub_notice_mode', $subNoticeMode);
        setUserSetting($this->uid, 'version_check_auto', isset($post['version_check_auto']) ? '1' : '0');
        traceLog($this->uid, 'save_user_config', 'setting', $this->uid, "保存全局配置 阈值:{$thresh} 公告模式:{$subNoticeMode}");
        return ['threshold' => $thresh];
    }

    // ════════════════════════════════════════════════════════════════
    //  用户管理
    // ════════════════════════════════════════════════════════════════

    /**
     * 重置用户密码（主管理员可重置任何人，普通管理员仅可重置自己的子用户）
     * @return array{new_password:string}
     */
    public function resetUserPassword(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->canManageUser($targetId)) {
            throw new AdminException('无权限操作该用户', 403);
        }
        // 生成 8 位随机密码（字母+数字）
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $newPw = '';
        for ($i = 0; $i < 8; $i++) {
            $newPw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE users SET password_hash=?, must_change_pw=1 WHERE id=?")
                 ->execute([$hash, $targetId]);
        traceLog($this->uid, 'user_reset_pw', 'user', $targetId, "重置用户密码 uid:{$targetId}");
        return ['new_password' => $newPw];
    }

    /**
     * 删除用户（仅主管理员，不可删除自己）
     * 级联清理该用户名下所有业务数据
     */
    public function deleteUser(int $targetId): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }
        if ($targetId === $this->uid) {
            throw new AdminException('不能删除当前登录用户', 1);
        }
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        // 级联清理 part_categories 关联
        $parts = $this->db->prepare("SELECT id FROM parts WHERE user_id=?");
        $parts->execute([$targetId]);
        foreach ($parts->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")
                     ->execute([(int)$p['id']]);
        }
        // 级联清理各业务表
        foreach (self::USER_DATA_TABLES as $t) {
            $this->db->prepare("DELETE FROM `{$t}` WHERE user_id=?")
                     ->execute([$targetId]);
        }
        $this->db->prepare("DELETE FROM users WHERE id=?")
                 ->execute([$targetId]);
        $this->db->prepare("UPDATE invite_codes SET used_by=NULL, used_at=NULL WHERE used_by=?")
                 ->execute([$targetId]);
        traceLog($this->uid, 'user_delete', 'user', $targetId, "删除用户 uid:{$targetId}");
        return ['deleted' => true, 'user_id' => $targetId];
    }

    /**
     * 创建子用户（继承主用户数据）
     * @throws AdminException 用户名/密码不合法或用户名重复
     */
    public function createSubUser(array $post): array
    {
        $subName = trim((string)($post['sub_username'] ?? ''));
        $subPw   = (string)($post['sub_password'] ?? '');
        $perms   = $this->sanitizePerms($post['sub_perms'] ?? []);
        if (strlen($subName) < 3) {
            throw new AdminException('用户名长度至少 3 个字符', 1);
        }
        if (!isStrongPassword($subPw)) {
            throw new AdminException('密码至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种', 1);
        }
        $hash = password_hash($subPw, PASSWORD_DEFAULT);
        try {
            $this->db->prepare("INSERT INTO users (username, password_hash, role, parent_id, permissions) VALUES (?, ?, 'user', ?, ?)")
                     ->execute([$subName, $hash, $this->uid, $perms]);
        } catch (\PDOException $e) {
            throw new AdminException('用户名已存在', 1);
        }
        $newSubId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'create_sub_user', 'user', $newSubId, "创建子用户 username:{$subName}");
        return ['user_id' => $newSubId, 'username' => $subName];
    }

    /**
     * 更新子用户权限
     */
    public function updateSubUser(int $targetId, array $perms): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->isOwnSubUser($targetId)) {
            throw new AdminException('只能管理自己的子用户', 403);
        }
        $permJson = $this->sanitizePerms($perms);
        $this->db->prepare("UPDATE users SET permissions=? WHERE id=? AND parent_id=?")
                 ->execute([$permJson, $targetId, $this->uid]);
        traceLog($this->uid, 'update_sub_user', 'user', $targetId, "更新子用户权限 uid:{$targetId}");
        return ['user_id' => $targetId];
    }

    /**
     * 删除子用户
     */
    public function deleteSubUser(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->isOwnSubUser($targetId)) {
            throw new AdminException('只能管理自己的子用户', 403);
        }
        $this->db->prepare("DELETE FROM users WHERE id=? AND parent_id=?")
                 ->execute([$targetId, $this->uid]);
        traceLog($this->uid, 'delete_sub_user', 'user', $targetId, "删除子用户 uid:{$targetId}");
        return ['user_id' => $targetId];
    }

    // ════════════════════════════════════════════════════════════════
    //  邀请码管理
    // ════════════════════════════════════════════════════════════════

    /**
     * 生成邀请码（仅主管理员，单次最多 10 个）
     * @return array{codes:list<string>}
     */
    public function generateInvites(int $count): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }
        $count = min(10, max(1, $count));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $this->db->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?, ?)")
                     ->execute([$code, $this->uid]);
            $codes[] = $code;
        }
        traceLog($this->uid, 'gen_invite', 'invite_code', 0, "生成邀请码 数量:{$count}");
        return ['codes' => $codes];
    }

    /**
     * 删除邀请码（仅主管理员，仅未使用的邀请码可删除）
     */
    public function deleteInvite(int $inviteId): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }
        if ($inviteId <= 0) {
            throw new AdminException('邀请码无效', 1);
        }
        $chk = $this->db->prepare("SELECT id, code, used_by FROM invite_codes WHERE id=?");
        $chk->execute([$inviteId]);
        $iv = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$iv) {
            throw new AdminException('邀请码不存在', 1);
        }
        if ($iv['used_by']) {
            throw new AdminException('已使用的邀请码不可删除', 1);
        }
        $this->db->prepare("DELETE FROM invite_codes WHERE id=? AND used_by IS NULL")
                 ->execute([$inviteId]);
        traceLog($this->uid, 'delete_invite', 'invite_code', $inviteId, "删除邀请码 code:{$iv['code']}");
        return ['deleted' => true, 'code' => $iv['code']];
    }

    // ════════════════════════════════════════════════════════════════
    //  内部辅助方法
    // ════════════════════════════════════════════════════════════════

    /** 权限白名单过滤，返回 JSON 字符串 */
    private function sanitizePerms(array $raw): string
    {
        $clean = array_values(array_intersect($raw, self::PERM_ALLOWLIST));
        return json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** 检查是否可管理目标用户（主管理员或目标是自己的子用户） */
    private function canManageUser(int $targetId): bool
    {
        if ($this->isPrimary) return true;
        return $this->isOwnSubUser($targetId);
    }

    /** 检查目标用户是否为当前用户的子用户 */
    private function isOwnSubUser(int $targetId): bool
    {
        $sub = $this->db->prepare("SELECT id FROM users WHERE id=? AND parent_id=?");
        $sub->execute([$targetId, $this->uid]);
        return (bool)$sub->fetch();
    }
}

/**
 * 管理后台业务异常
 */
final class AdminException extends Exception
{
    public int $errCode;
    public function __construct(string $msg, int $code = 1)
    {
        parent::__construct($msg);
        $this->errCode = $code;
    }
}
