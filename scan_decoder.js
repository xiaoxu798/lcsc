/**
 * scan_decoder.js — 条码/二维码解码算法
 *
 * 支持的格式：
 * 1. 内部物料二维码: {id:123,pid:lcsc,model:LM358,qty:10,type:in}
 *    → id=internal_id 全平台唯一, pid=平台code(如lcsc/huaqiu) 平台标识, qty=操作数量, type=in入库/out出库
 *    → 自动切换入库/出库状态，自动填充操作数量，自动匹配物料绑定平台
 * 2. 立创平台二维码: {on:SO26070337572,pc:C114425,pm:TPS5450DDAR,qty:1,mc:,cc:1,pdi:223325538,hp:21}
 *    → 自动入库，提取订单号、商品编号、型号、数量
 * 3. URL链接 → 从URL中提取商品编号
 * 4. 纯编号 → 直接使用
 */

var ScanDecoder = (function() {

    // ── 已知平台的 URL 模式 ──
    var URL_PATTERNS = [
        { regex: /lcsc\.com\/product[\w-]*\/([A-Za-z0-9_-]+)\.html/i, group: 1 },
        { regex: /szlcsc\.com\/product[\w-]*\/([A-Za-z0-9_-]+)\.html/i, group: 1 },
        { regex: /huaqiu\.com\/product[\w-]*\/([A-Za-z0-9_-]+)/i, group: 1 },
        { regex: /yunhan\.com\/product[\w-]*\/([A-Za-z0-9_-]+)/i, group: 1 },
        { regex: /\/product[\w-]*\/([A-Za-z0-9][A-Za-z0-9_-]{2,})/i, group: 1 },
        { regex: /[?&](?:part|partNo|part_no|sku|code)=([A-Za-z0-9_-]+)/i, group: 1 },
    ];

    /**
     * 通用 KV 解析器：解析 {key:value,key:value,...} 格式
     * @param {string} text
     * @return {object|null} fields 对象，无法解析返回 null
     */
    function parseKvFormat(text) {
        var match = text.match(/^\{(.+)\}$/);
        if (!match) return null;
        var inner = match[1];
        var fields = {};
        var pairs = inner.split(',');
        for (var i = 0; i < pairs.length; i++) {
            var kv = pairs[i].split(':');
            if (kv.length >= 2) {
                var key = kv[0].trim();
                var val = kv.slice(1).join(':').trim();
                fields[key] = val;
            }
        }
        return fields;
    }

    /**
     * 解析内部物料二维码
     * 格式: {id:123,pid:lcsc,model:LM358,qty:10,type:in}
     * id=internal_id 全平台唯一, pid=平台code(如lcsc/huaqiu) 平台标识, qty=操作数量, type=in入库/out出库
     * @param {string} text
     * @return {object|null} 解析结果，无法解析返回null
     */
    function parseInternalQrCode(text) {
        var fields = parseKvFormat(text);
        if (!fields) return null;
        // 必须同时有 id（内部物料ID）和 type（操作类型）才认定为内部二维码
        if (!fields.id || !fields.type) return null;
        // type 必须是 in 或 out
        if (fields.type !== 'in' && fields.type !== 'out') return null;
        var internalId = parseInt(fields.id, 10);
        if (isNaN(internalId) || internalId <= 0) return null;
        return {
            type: 'internal_qr',
            internalId: internalId,
            platformCode: fields.pid || '',  // 平台代码（字符串，如 lcsc/huaqiu）
            partNo: '',
            model: fields.model || '',
            qty: parseInt(fields.qty, 10) || 1,
            scanType: fields.type,  // 'in' 或 'out'
            raw: text
        };
    }

    /**
     * 解析立创平台二维码格式
     * 格式: {on:SO26070337572,pc:C114425,pm:TPS5450DDAR,qty:1,mc:,cc:1,pdi:223325538,hp:21}
     * @param {string} text
     * @return {object|null} 解析结果，无法解析返回null
     */
    function parseLCSCQrCode(text) {
        var fields = parseKvFormat(text);
        if (!fields) return null;
        // 必须同时有 on（订单号）和 pc（商品编号）才认定为立创二维码
        if (!fields.on || !fields.pc) return null;
        return {
            type: 'lcsc_qr',
            orderNo: fields.on,
            partNo: fields.pc,
            model: fields.pm || '',
            qty: parseInt(fields.qty, 10) || 1,
            raw: text
        };
    }

    /**
     * 主解码函数
     * @param {string} rawText - 扫码枪/摄像头识别的原始文本
     * @return {object} 解码结果，包含 type/partNo/model/qty/orderNo/source/raw/autoAction/scanType/internalId/platformCode 等字段
     *   autoAction: 'scan_in' | 'scan_out' | null — 自动建议的扫码动作
     *   scanType: 'in' | 'out' | null — 内部二维码指定的操作类型
     *   internalId: number | 0 — 内部物料ID（仅 internal_qr 类型有效）
     *   platformCode: string | '' — 平台代码（仅 internal_qr 类型有效，如 lcsc/huaqiu）
     */
    function decode(rawText) {
        if (!rawText || typeof rawText !== 'string') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText || '', autoAction: null, scanType: null, internalId: 0, platformCode: '' };
        }

        var text = rawText.trim();
        if (text === '') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
        }

        // 1. 优先解析内部物料二维码
        var internalResult = parseInternalQrCode(text);
        if (internalResult) {
            return {
                type: 'internal_qr',
                partNo: '',
                internalId: internalResult.internalId,
                platformCode: internalResult.platformCode,
                model: internalResult.model,
                qty: internalResult.qty,
                scanType: internalResult.scanType,
                orderNo: '',
                source: 'internal_qr',
                raw: rawText,
                autoAction: internalResult.scanType === 'out' ? 'scan_out' : 'scan_in'
            };
        }

        // 2. 解析立创平台二维码（强制入库）
        var lcscResult = parseLCSCQrCode(text);
        if (lcscResult) {
            return {
                type: 'lcsc_qr',
                partNo: lcscResult.partNo,
                model: lcscResult.model,
                qty: lcscResult.qty,
                orderNo: lcscResult.orderNo,
                source: 'lcsc_qr',
                raw: rawText,
                autoAction: 'scan_in',  // 立创二维码 = 收货入库
                scanType: null,
                internalId: 0,
                platformCode: ''
            };
        }

        // 3. 尝试从 URL 中提取
        if (text.indexOf('http') === 0 || text.indexOf('www.') === 0) {
            for (var i = 0; i < URL_PATTERNS.length; i++) {
                var m = text.match(URL_PATTERNS[i].regex);
                if (m && m[URL_PATTERNS[i].group]) {
                    return { type: 'url', partNo: m[URL_PATTERNS[i].group], model: '', qty: 1, orderNo: '', source: 'url', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
                }
            }
            var pathMatch = text.match(/\/([A-Za-z0-9][A-Za-z0-9_-]{3,})(?:\.\w+)?(?:\?|$)/);
            if (pathMatch && pathMatch[1]) {
                return { type: 'url', partNo: pathMatch[1], model: '', qty: 1, orderNo: '', source: 'url-path', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
            }
        }

        // 4. 检查是否包含 "编号:XXXX" 格式
        var labelMatch = text.match(/(?:商品编号|产品编号|料号|part|sku|code|编号|料号)\s*[:：]\s*([A-Za-z0-9_-]+)/i);
        if (labelMatch && labelMatch[1]) {
            return { type: 'label', partNo: labelMatch[1], model: '', qty: 1, orderNo: '', source: 'label', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
        }

        // 5. 纯编号
        if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(text)) {
            return { type: 'direct', partNo: text, model: '', qty: 1, orderNo: '', source: 'direct', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
        }

        // 6. 多行文本
        var lines = text.split(/[\r\n]+/);
        for (var k = 0; k < lines.length; k++) {
            var line = lines[k].trim();
            if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(line)) {
                return { type: 'multiline', partNo: line, model: '', qty: 1, orderNo: '', source: 'multiline', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
            }
        }

        // 7. 兜底
        var cleaned = text.replace(/[\r\n\t]/g, ' ').replace(/\s+/g, ' ').trim();
        return { type: 'fallback', partNo: cleaned, model: '', qty: 1, orderNo: '', source: 'fallback', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '' };
    }

    /**
     * 验证扫码结果是否有效（含内部二维码、立创二维码、纯编号）
     */
    function isValidPartNo(partNo) {
        if (!partNo || partNo.length < 2 || partNo.length > 100) return false;
        if (/[\s\u4e00-\u9fa5]/.test(partNo)) return false;
        return true;
    }

    /**
     * 处理扫码结果：解码 + 填入输入框
     * @param {string} rawText - 识别的原始文本
     * @param {HTMLElement} inputEl - 目标输入框
     * @return {object} 解码结果
     */
    function process(rawText, inputEl) {
        var result = decode(rawText);
        var valid = isValidPartNo(result.partNo);
        if (valid && inputEl) {
            inputEl.value = result.partNo;
        }
        result.valid = valid;
        return result;
    }

    return {
        decode: decode,
        isValidPartNo: isValidPartNo,
        process: process,
        parseInternalQrCode: parseInternalQrCode,
        parseLCSCQrCode: parseLCSCQrCode
    };
})();
