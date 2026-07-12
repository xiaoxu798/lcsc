/**
 * scan_decoder.js — 条码/二维码解码算法
 *
 * 支持的格式：
 * 1. 立创平台二维码: {on:SO26070337572,pc:C114425,pm:TPS5450DDAR,qty:1,mc:,cc:1,pdi:223325538,hp:21}
 *    → 自动入库，提取订单号、商品编号、型号、数量
 * 2. 系统打印二维码: {src:lcsc_sys,pc:C114425,pm:TPS5450DDAR}
 *    → 使用当前扫码模式（入库/出库）
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
     * 解析立创平台二维码格式
     * 格式: {on:SO26070337572,pc:C114425,pm:TPS5450DDAR,qty:1,mc:,cc:1,pdi:223325538,hp:21}
     * @param {string} text
     * @return {object|null} 解析结果，无法解析返回null
     */
    function parseLCSCQrCode(text) {
        // 匹配 {key:value,key:value,...} 格式
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
     * 解析系统打印的二维码格式
     * 格式: {src:lcsc_sys,pc:C114425,pm:TPS5450DDAR,qty:1,stock:100}
     * qty=默认扫码数量, stock=打印时库存(参考)
     * @param {string} text
     * @return {object|null} 解析结果，无法解析返回null
     */
    function parseSystemQrCode(text) {
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

        // 必须有 src:lcsc_sys 标识才认定为系统二维码
        if (fields.src !== 'lcsc_sys') return null;

        return {
            type: 'system_qr',
            partNo: fields.pc || '',
            model: fields.pm || '',
            qty: parseInt(fields.qty, 10) || 1,
            stock: parseInt(fields.stock, 10) || 0,
            raw: text
        };
    }

    /**
     * 主解码函数
     * @param {string} rawText - 扫码枪/摄像头识别的原始文本
     * @return {{type:string, partNo:string, model:string, qty:number, orderNo:string, source:string, raw:string, autoAction:string|null}}
     *   autoAction: 'scan_in' | 'scan_out' | null — 自动建议的扫码动作
     */
    function decode(rawText) {
        if (!rawText || typeof rawText !== 'string') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText || '', autoAction: null };
        }

        var text = rawText.trim();
        if (text === '') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText, autoAction: null };
        }

        // 1. 尝试解析立创平台二维码
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
                autoAction: 'scan_in'  // 立创二维码 = 收货入库
            };
        }

        // 2. 尝试解析系统二维码
        var sysResult = parseSystemQrCode(text);
        if (sysResult) {
            return {
                type: 'system_qr',
                partNo: sysResult.partNo,
                model: sysResult.model,
                qty: sysResult.qty,
                stock: sysResult.stock,
                orderNo: '',
                source: 'system_qr',
                raw: rawText,
                autoAction: null  // 系统码使用当前模式
            };
        }

        // 3. 尝试从 URL 中提取
        if (text.indexOf('http') === 0 || text.indexOf('www.') === 0) {
            for (var i = 0; i < URL_PATTERNS.length; i++) {
                var m = text.match(URL_PATTERNS[i].regex);
                if (m && m[URL_PATTERNS[i].group]) {
                    return { type: 'url', partNo: m[URL_PATTERNS[i].group], model: '', qty: 1, orderNo: '', source: 'url', raw: rawText, autoAction: null };
                }
            }
            var pathMatch = text.match(/\/([A-Za-z0-9][A-Za-z0-9_-]{3,})(?:\.\w+)?(?:\?|$)/);
            if (pathMatch && pathMatch[1]) {
                return { type: 'url', partNo: pathMatch[1], model: '', qty: 1, orderNo: '', source: 'url-path', raw: rawText, autoAction: null };
            }
        }

        // 4. 检查是否包含 "编号:XXXX" 格式
        var labelMatch = text.match(/(?:商品编号|产品编号|料号|part|sku|code|编号|料号)\s*[:：]\s*([A-Za-z0-9_-]+)/i);
        if (labelMatch && labelMatch[1]) {
            return { type: 'label', partNo: labelMatch[1], model: '', qty: 1, orderNo: '', source: 'label', raw: rawText, autoAction: null };
        }

        // 5. 纯编号
        if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(text)) {
            return { type: 'direct', partNo: text, model: '', qty: 1, orderNo: '', source: 'direct', raw: rawText, autoAction: null };
        }

        // 6. 多行文本
        var lines = text.split(/[\r\n]+/);
        for (var k = 0; k < lines.length; k++) {
            var line = lines[k].trim();
            if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(line)) {
                return { type: 'multiline', partNo: line, model: '', qty: 1, orderNo: '', source: 'multiline', raw: rawText, autoAction: null };
            }
        }

        // 7. 兜底
        var cleaned = text.replace(/[\r\n\t]/g, ' ').replace(/\s+/g, ' ').trim();
        return { type: 'fallback', partNo: cleaned, model: '', qty: 1, orderNo: '', source: 'fallback', raw: rawText, autoAction: null };
    }

    /**
     * 验证编号是否有效
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
        parseLCSCQrCode: parseLCSCQrCode,
        parseSystemQrCode: parseSystemQrCode
    };
})();
