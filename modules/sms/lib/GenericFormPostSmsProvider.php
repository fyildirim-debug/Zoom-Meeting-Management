<?php
/**
 * Generic Form-POST SMS Provider
 *
 * Form-urlencoded POST kabul eden ve JSON dönen SMS sağlayıcıları için generic istemci.
 *
 * İstek formatı:
 *   POST <api_url>
 *   Content-Type: application/x-www-form-urlencoded
 *   Body:  api_key=...&telefonlar=...&mesaj=...&baslik=...
 *
 * Yanıt formatı (beklenen):
 *   { "success": bool, "basarili_adet": int, "message": "..." }
 *
 * api_url ve api_key admin panelinden yapılandırılır (modül ayarları).
 */

require_once __DIR__ . '/SmsProvider.php';

class GenericFormPostSmsProvider implements SmsProvider
{
    private string $apiUrl;
    private string $apiKey;
    private string $senderTitle;

    public function __construct(string $apiUrl, string $apiKey, string $senderTitle = '')
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->senderTitle = $senderTitle;
    }

    public function getId(): string
    {
        return 'generic_form_post';
    }

    public function send($phones, string $message): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API anahtarı yapılandırılmamış.',
                'response' => [],
                'success_count' => 0,
            ];
        }
        if (empty($this->apiUrl)) {
            return [
                'success' => false,
                'message' => 'API URL yapılandırılmamış.',
                'response' => [],
                'success_count' => 0,
            ];
        }

        $telephones = is_array($phones) ? implode(',', $phones) : (string)$phones;

        $postData = [
            'api_key'    => $this->apiKey,
            'telefonlar' => $telephones,
            'mesaj'      => $message,
            'baslik'     => $this->senderTitle,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_POSTREDIR      => 7, // 301/302/303 redirect'lerde POST'u koru
            CURLOPT_USERAGENT      => 'ZoomMeetingManagement/1.2 (+SMS-module)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $curlError,
                'response' => ['http_code' => $httpCode, 'effective_url' => $effectiveUrl, 'raw' => $curlError],
                'success_count' => 0,
            ];
        }

        $data = json_decode($rawResponse, true);
        if (!is_array($data)) {
            $snippet = substr(strip_tags($rawResponse), 0, 200);
            return [
                'success' => false,
                'message' => "Geçersiz API yanıtı (HTTP {$httpCode}). Sunucu JSON yerine şu içeriği döndürdü: " . trim($snippet),
                'response' => [
                    'http_code'     => $httpCode,
                    'effective_url' => $effectiveUrl,
                    'raw'           => substr($rawResponse, 0, 1000),
                ],
                'success_count' => 0,
            ];
        }

        $isSuccess = !empty($data['success']);
        return [
            'success' => $isSuccess,
            'message' => $data['message'] ?? ($isSuccess ? 'SMS gönderildi.' : 'SMS gönderilemedi.'),
            'response' => $data,
            'success_count' => (int)($data['basarili_adet'] ?? 0),
        ];
    }
}
