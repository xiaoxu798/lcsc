<?php
declare(strict_types=1);
/**
 * v1.1.0 正式版 API 接口（GET 查询入口）
 * 统一返回格式：{code, msg, data}
 * 业务逻辑由 Manager 模块处理，本文件仅负责鉴权 / CSRF / 分发。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/module_parts.php';
require_once __DIR__ . '/module_platform.php';
require_once __DIR__ . '/module_assets.php';
require_once __DIR__ . '/module_logs.php';
require_once __DIR__ . '/module_trace.php';
require_once __DIR__ . '/module_bom.php';
initDB();
apiBootstrap(); // 统一缓冲区清理 + JSON头 + 全局异常捕获
$user = ajaxRequireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$pm   = new PartManager($db, $uid, $dataUid);
$plm  = new PlatformManager($db, $uid, $dataUid);
$am   = new AssetManager($db, $uid, $dataUid);
$lm   = new LogManager($db, $uid, $dataUid);
$tm   = new TraceManager($db, $uid, $dataUid);
$bom  = new BomManager($db, $uid, $dataUid);

$api  = $_GET['api'] ?? $_POST['api'] ?? '';
// 写操作需要CSRF校验（api.php 仅处理查询，但保留防御性校验）
$writeApis = ['add','edit','delete','batch_delete','batch_set_category','batch_set_location','batch_set_remark','stock','scan_in','scan_out'];
if (in_array($api, $writeApis, true)) {
    verifyCsrfSafe();
}

try {
    switch ($api) {

        // ── 物料列表查询（支持筛选/分页/排序）──
        case 'parts':
            jsonResponse($pm->listParts($_GET));

        // ── 统计数据 ──
        case 'stats':
            jsonResponse($pm->getStats());

        // ── 分类列表 ──
        case 'categories':
            jsonResponse($pm->getCategories());

        // ── 分类列表分页查询（支持 page/per_page，AJAX 局部刷新用）──
        case 'categories_paged':
            jsonResponse($pm->listCategoriesPaged($_GET));

        // ── 平台列表（含 code 字段，供二维码 pid 标识）──
        case 'platforms':
            jsonResponse($plm->listPlatforms());

        // ── 单个平台完整详情（含元件数量统计）──
        case 'platform_detail':
            jsonResponse($plm->getPlatform(intval($_GET['platform_id'] ?? 0)));

        // ── 单个平台的类型属性（供前端弹窗异步查询）──
        case 'platform_type':
            jsonResponse($plm->getPlatformType(intval($_GET['platform_id'] ?? 0)));

        // ── 物料详情摘要（累计资产 + 最新采购单价）──
        case 'detail':
            jsonResponse($pm->getPartDetail(intval($_GET['part_id'] ?? 0)));

        // ── 编辑弹窗完整字段（统一数据源：新增/编辑/批量操作后回填表单）──
        case 'edit_detail':
            jsonResponse($pm->getPartEditData(intval($_GET['part_id'] ?? 0)));

        // ── BOM 替代料绑定弹窗：全物料分页模糊搜索 ──
        case 'parts_search':
            jsonResponse($pm->searchPartsPaged(
                (string)($_GET['q'] ?? ''),
                intval($_GET['page'] ?? 1),
                15
            ));

        // ── BOM 项目列表（首页批量加入 BOM 选择器用）──
        case 'bom_projects':
            jsonResponse(['projects' => $bom->listProjects()]);

        // ── BOM 物料明细列表查询（支持状态筛选 + 分页，AJAX 局部刷新用）──
        case 'bom_items':
            jsonResponse($bom->listItems(intval($_GET['id'] ?? 0), $_GET));

        // ── 替代料差异化推荐：基于物料型号/封装/分类信息近似匹配，置顶展示 ──
        case 'alt_suggest':
            jsonResponse($pm->suggestAlternatives(
                (string)($_GET['model'] ?? ''),
                (string)($_GET['package'] ?? ''),
                (string)($_GET['category'] ?? ''),
                intval($_GET['exclude'] ?? 0),
                10
            ));

        // ════════════════════════════════════════════════
        //  资产统计模块
        // ════════════════════════════════════════════════

        // ── 资产统计卡片数据（4个卡片）──
        case 'asset_stats':
            jsonResponse($am->getStats());

        // ── 资产图表数据（近12个月折线+柱状）──
        case 'asset_charts':
            jsonResponse($am->getChartData());

        // ── 出入库流水查询（支持筛选/分页）──
        case 'asset_logs':
            jsonResponse($am->listLogs($_GET));

        // ── 筛选下拉选项（一级分类 + 平台列表）──
        case 'asset_filter_options':
            jsonResponse($am->getFilterOptions());

        // ════════════════════════════════════════════════
        //  操作日志模块
        // ════════════════════════════════════════════════

        // ── 出入库记录列表查询（支持搜索/分页）──
        case 'logs':
            jsonResponse($lm->listLogs($_GET));

        // ════════════════════════════════════════════════
        //  溯源日志模块
        // ════════════════════════════════════════════════

        // ── 溯源日志列表查询（支持起止日期筛选/分页，默认每页100条）──
        case 'trace_logs':
            jsonResponse($tm->listLogs($_GET));

        // ── 备份记录列表（备份成功后 AJAX 无感刷新用）──
        case 'backup_logs':
            $stmt = $db->prepare("SELECT bl.id, bl.user_id, bl.file_name, bl.file_size, bl.action, bl.created_at, u.username FROM backup_log bl LEFT JOIN users u ON u.id = bl.user_id WHERE bl.user_id = ? ORDER BY bl.created_at DESC LIMIT 50");
            $stmt->execute([$dataUid]);
            $logs = array_map(function($r){
                return [
                    'id'         => (int)$r['id'],
                    'user_id'    => (int)$r['user_id'],
                    'username'   => (string)($r['username'] ?? '?'),
                    'file_name'  => (string)($r['file_name'] ?? '—'),
                    'file_size'  => (int)($r['file_size'] ?? 0),
                    'action'     => (string)$r['action'],
                    'created_at' => substr((string)$r['created_at'], 0, 16),
                ];
            }, $stmt->fetchAll());
            jsonResponse(['logs' => $logs]);

        default:
            jsonError('未知API接口', 404);
    }
} catch (PartException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (PlatformException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (AssetException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (LogException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (TraceException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (BomException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (\Throwable $e) {
    error_log('api.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('服务器内部错误，请稍后重试', 1);
}
