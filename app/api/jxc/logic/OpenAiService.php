<?php

namespace app\api\jxc\logic;

use think\facade\Log;

/**
 * OpenAI 服务类
 * 封装 OpenAI Chat Completion API 调用，提供降级能力
 */
class OpenAiService
{
    /**
     * 判断 OpenAI 是否可用（api_key 非空）
     */
    public static function isAvailable(): bool
    {
        $apiKey = (string)config('openai.api_key');
        return $apiKey !== '';
    }

    /**
     * 调用 OpenAI Chat Completion API
     *
     * @param string $systemPrompt 系统提示词
     * @param string $userContent  用户输入内容
     * @return array|null 成功返回解析后的 JSON 数组，失败返回 null
     */
    public static function chatCompletion(string $systemPrompt, string $userContent): ?array
    {
        $apiKey      = (string)config('openai.api_key');
        $model       = (string)config('openai.model');
        $maxTokens   = (int)config('openai.max_tokens');
        $temperature = (float)config('openai.temperature');
        $timeout     = (int)config('openai.timeout');
        $baseUrl     = rtrim((string)config('openai.base_url'), '/');

        if ($apiKey === '') {
            return null;
        }

        $url = $baseUrl . '/chat/completions';

        $payload = json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userContent],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ], JSON_UNESCAPED_UNICODE);

        // 脱敏日志：只显示 api_key 前 8 位
        $maskedKey = strlen($apiKey) > 8 ? substr($apiKey, 0, 8) . '****' : '****';

        try {
            if (function_exists('curl_init')) {
                $responseBody = self::curlPost($url, $payload, $apiKey, $timeout);
            } else {
                $responseBody = self::streamPost($url, $payload, $apiKey, $timeout);
            }

            if ($responseBody === null) {
                Log::warning('[OpenAiService] API 请求返回空响应', [
                    'key_prefix' => $maskedKey,
                    'url'        => $url,
                ]);
                return null;
            }

            $data = json_decode($responseBody, true);
            if (!is_array($data)) {
                Log::warning('[OpenAiService] API 响应 JSON 解析失败', [
                    'key_prefix' => $maskedKey,
                    'raw'        => substr($responseBody, 0, 200),
                ]);
                return null;
            }

            // 检查 API 错误
            if (isset($data['error'])) {
                Log::warning('[OpenAiService] API 返回错误', [
                    'key_prefix' => $maskedKey,
                    'error'      => $data['error']['message'] ?? json_encode($data['error']),
                ]);
                return null;
            }

            return $data;

        } catch (\Throwable $e) {
            Log::error('[OpenAiService] 请求异常', [
                'key_prefix' => $maskedKey,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return null;
        }
    }

    /**
     * 解析粘贴的商品文本
     * 调用 OpenAI 结构化抽取：商品名称、数量、单位、价格
     *
     * @param string $text 粘贴的文本
     * @return array|null 成功返回 goods 数组，失败返回 null
     */
    public static function parseGoodsText(string $text): ?array
    {
        $systemPrompt = <<<'PROMPT'
你是一个进销存系统的商品信息解析助手。请从用户粘贴的文本中提取商品信息。
对于每个商品，提取：name（商品名称）、number（数量，数字）、unit（单位，如"箱"、"件"、"瓶"）、price（单价，数字）。
如果某个字段无法识别，设为 null。
返回 JSON 数组格式，例如：[{"name":"可口可乐","number":10,"unit":"箱","price":48.5}]
只返回 JSON 数组，不要返回其他内容。
PROMPT;

        $response = self::chatCompletion($systemPrompt, $text);
        if ($response === null) {
            return null;
        }

        try {
            $content = $response['choices'][0]['message']['content'] ?? '';
            $content = trim($content);

            // 去掉可能包裹的 markdown 代码块
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/i', '', $content);
            $content = trim($content);

            $goods = json_decode($content, true);
            if (!is_array($goods)) {
                Log::warning('[OpenAiService] parseGoodsText: AI 返回内容无法解析为数组', [
                    'raw' => substr($content, 0, 300),
                ]);
                return null;
            }

            // 标准化每条商品记录
            $result = [];
            foreach ($goods as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $result[] = [
                    'name'   => isset($item['name'])   ? (string)$item['name']   : null,
                    'number' => isset($item['number']) ? (float)$item['number']  : null,
                    'unit'   => isset($item['unit'])   ? (string)$item['unit']   : null,
                    'price'  => isset($item['price'])  ? (float)$item['price']   : null,
                ];
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('[OpenAiService] parseGoodsText 解析异常', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================
    // 私有 HTTP 辅助方法
    // =========================================================

    /**
     * 使用 cURL 发起 POST 请求
     */
    private static function curlPost(string $url, string $payload, string $apiKey, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('cURL 错误 [' . $errno . ']: ' . $error);
        }

        return $response === false ? null : (string)$response;
    }

    /**
     * 使用 file_get_contents + stream_context 发起 POST 请求（无 cURL 时降级使用）
     */
    private static function streamPost(string $url, string $payload, string $apiKey, int $timeout): ?string
    {
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ]),
                'content'       => $payload,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        $context  = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }
}
