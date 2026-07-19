<?php
declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  第三方 API 中转分层（External API Relay Layer）                    ║
 * ║  ─────────────────────────────────────────────────────────────────  ║
 * ║  用途：                                                              ║
 * ║    1. 提供外部商城 / 第三方数据源的统一 HTTP 请求客户端；            ║
 * ║    2. 提供数据映射隔离机制：外部返回 → 字段筛选 → 本地结构；        ║
 * ║    3. 仅搭建空白通用模板，不编写任何具体商城对接业务；              ║
 * ║    4. 与内部业务 API（api.php / action.php / detail_ajax.php）      ║
 * ║       完全隔离，互不依赖、互不调用。                                 ║
 * ║                                                                      ║
 * ║  设计原则：                                                          ║
 * ║    - 外部数据与本地数据库完全分离，本层不直接写本地库；              ║
 * ║    - 通过 DataMapper 筛选所需字段，外部多余参数直接丢弃；           ║
 * ║    - 绝不修改现有本地数据表结构；                                    ║
 * ║    - 后续新增商城适配仅在本层新增 Adapter 子类，不影响库存核心逻辑。║
 * ║                                                                      ║
 * ║  使用方式：                                                          ║
 * ║    require_once 'external_api.php';                                  ║
 * ║    $client  = new ExternalApiClient(['timeout' => 10]);             ║
 * ║    $resp    = $client->get('https://example.com/api', ['k'=>'v']);  ║
 * ║    $mapped  = ExternalDataMapper::map($resp, $fieldMap);            ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// 仅在未被包含时使用自身配置；不依赖 config.php，保证与内部业务隔离。
if (!defined('EXTERNAL_API_LOADED')) {
    define('EXTERNAL_API_LOADED', true);

    /**
     * 第三方 HTTP 请求客户端
     * 基于 cURL 实现的通用 HTTP GET/POST 封装，仅用于外部 API 调用。
     * 不与本地数据库发生任何交互，纯网络层工具。
     */
    final class ExternalApiClient
    {
        /** @var array<string,mixed> 默认配置 */
        private array $options;

        /**
         * @param array<string,mixed> $options {
         *     timeout:        int   连接+请求总超时（秒），默认 15
         *     connect_timeout:int   连接超时（秒），默认 5
         *     user_agent:     string UA 标识
         *     verify_ssl:     bool   是否校验 SSL 证书，默认 true
         *     max_redirects: int    最大重定向次数，默认 3
         * }
         */
        public function __construct(array $options = [])
        {
            $this->options = array_merge([
                'timeout'        => 15,
                'connect_timeout'=> 5,
                'user_agent'     => 'LCSC-ExternalApi/1.0',
                'verify_ssl'     => true,
                'max_redirects'  => 3,
            ], $options);
        }

        /**
         * 发起 GET 请求
         * @param string                $url     完整 URL（必须 http(s)://）
         * @param array<string,string>  $query   查询参数
         * @param array<string,string>  $headers 额外请求头
         * @return ExternalApiResponse
         * @throws RuntimeException 当 URL 非法或 cURL 失败时抛出
         */
        public function get(string $url, array $query = [], array $headers = []): ExternalApiResponse
        {
            $fullUrl = $this->buildUrl($url, $query);
            return $this->request('GET', $fullUrl, null, $headers);
        }

        /**
         * 发起 POST 请求
         * @param string                $url     完整 URL
         * @param array<string,mixed>|string $body 请求体（数组会以 form-urlencoded 发送）
         * @param array<string,string>  $headers
         * @return ExternalApiResponse
         */
        public function post(string $url, $body = [], array $headers = []): ExternalApiResponse
        {
            return $this->request('POST', $url, $body, $headers);
        }

        /**
         * 统一请求入口
         * @param string $method GET|POST
         * @param string $url
         * @param array<string,mixed>|string|null $body
         * @param array<string,string> $headers
         */
        private function request(string $method, string $url, $body, array $headers): ExternalApiResponse
        {
            if (!preg_match('#^https?://#i', $url)) {
                throw new RuntimeException('ExternalApiClient: URL 必须以 http(s):// 开头');
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => (int)$this->options['max_redirects'],
                CURLOPT_TIMEOUT        => (int)$this->options['timeout'],
                CURLOPT_CONNECTTIMEOUT => (int)$this->options['connect_timeout'],
                CURLOPT_USERAGENT      => (string)$this->options['user_agent'],
                CURLOPT_SSL_VERIFYPEER => (bool)$this->options['verify_ssl'],
                CURLOPT_SSL_VERIFYHOST => (bool)$this->options['verify_ssl'] ? 2 : 0,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);

            // 请求头组装
            $reqHeaders = ['Accept: application/json'];
            foreach ($headers as $k => $v) {
                $reqHeaders[] = $k . ': ' . $v;
            }

            if ($method === 'POST') {
                if (is_array($body)) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                    $reqHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
                } elseif (is_string($body) && $body !== '') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

            $raw      = curl_exec($ch);
            $errno    = curl_errno($ch);
            $errmsg   = curl_error($ch);
            $info     = curl_getinfo($ch);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new RuntimeException('ExternalApiClient 请求失败: ' . $errmsg . ' (errno=' . $errno . ')');
            }

            $headerSize = (int)$info['header_size'];
            $respHeaders = substr((string)$raw, 0, $headerSize);
            $respBody    = substr((string)$raw, $headerSize);

            return new ExternalApiResponse(
                (int)($info['http_code'] ?? 0),
                $respBody,
                $respHeaders,
                (string)($info['url'] ?? $url)
            );
        }

        /**
         * 拼接 URL 与查询参数
         */
        private function buildUrl(string $url, array $query): string
        {
            if (empty($query)) return $url;
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            return $url . $sep . http_build_query($query);
        }
    }

    /**
     * 外部 API 响应封装
     * 仅作为数据载体，不直接对接本地数据库。
     */
    final class ExternalApiResponse
    {
        public readonly int $statusCode;
        public readonly string $body;
        public readonly string $rawHeaders;
        public readonly string $finalUrl;

        public function __construct(int $statusCode, string $body, string $rawHeaders, string $finalUrl)
        {
            $this->statusCode = $statusCode;
            $this->body       = $body;
            $this->rawHeaders = $rawHeaders;
            $this->finalUrl   = $finalUrl;
        }

        /** 是否 HTTP 2xx 成功 */
        public function isOk(): bool
        {
            return $this->statusCode >= 200 && $this->statusCode < 300;
        }

        /** 尝试解析为 JSON，失败返回 null */
        public function json(): ?array
        {
            $data = json_decode($this->body, true);
            return is_array($data) ? $data : null;
        }
    }

    /**
     * 数据映射隔离器
     * 将外部 API 返回的数据按字段映射规则筛选为本地业务所需结构。
     * - 外部多余参数直接丢弃，绝不写入本地库；
     * - 本层不操作数据库，仅返回映射后的纯数组；
     * - 调用方（库存核心逻辑）拿到映射结果后自行决定是否入库。
     *
     * 字段映射规则示例：
     *   $map = [
     *       'part_no'   => 'partNumber',   // 外部 partNumber → 本地 part_no
     *       'brand'     => 'manufacturer',
     *       'stock_qty' => function($raw) { return (int)($raw['quantity'] ?? 0); },
     *   ];
     */
    final class ExternalDataMapper
    {
        /**
         * 按映射规则转换外部数据
         * @param array<string,mixed>      $raw  外部原始数据
         * @param array<string,string|callable> $map 字段映射：本地字段名 => 外部字段名 或 闭包
         * @return array<string,mixed> 映射后的本地结构数据
         */
        public static function map(array $raw, array $map): array
        {
            $result = [];
            foreach ($map as $localField => $source) {
                if (is_callable($source)) {
                    $result[$localField] = $source($raw);
                } elseif (is_string($source) && array_key_exists($source, $raw)) {
                    $result[$localField] = $raw[$source];
                } else {
                    $result[$localField] = null;
                }
            }
            return $result;
        }

        /**
         * 批量映射（列表数据）
         * @param array<int,array<string,mixed>> $list 外部列表
         * @param array<string,string|callable>  $map  字段映射
         * @return array<int,array<string,mixed>>
         */
        public static function mapList(array $list, array $map): array
        {
            $out = [];
            foreach ($list as $item) {
                if (!is_array($item)) continue;
                $out[] = self::map($item, $map);
            }
            return $out;
        }
    }

    /**
     * 外部平台适配器抽象模板
     * 后续新增商城适配仅需继承本类并实现抽象方法，集中管理外部差异。
     * 子类实例化后由调用方按需使用，本基类不持有任何状态。
     */
    abstract class ExternalPlatformAdapter
    {
        protected ExternalApiClient $client;

        public function __construct(?ExternalApiClient $client = null)
        {
            $this->client = $client ?? new ExternalApiClient();
        }

        /** 平台唯一标识（与本地 platforms.code 对应，但本层不读取本地库） */
        abstract public function platformCode(): string;

        /** 平台展示名 */
        abstract public function platformName(): string;

        /**
         * 按外部编号查询物料详情（空白模板）
         * 子类实现具体外部接口调用，并返回经 DataMapper 映射后的本地结构数组。
         * @param string $partNo 外部平台编号
         * @return array<string,mixed>|null 映射后的物料数据，无结果返回 null
         */
        abstract public function queryPart(string $partNo): ?array;

        /**
         * 字段映射定义（子类返回 本地字段 => 外部字段/闭包 的映射表）
         * @return array<string,string|callable>
         */
        abstract protected function fieldMap(): array;
    }
}
