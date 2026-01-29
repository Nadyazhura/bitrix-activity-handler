<?php
/**
 * BitrixClient
 * ------------
 * Обёртка над REST API Bitrix24.
 * Отвечает ТОЛЬКО за HTTP-вызовы.
 */

class BitrixClient
{
    /** @var string URL вебхука */
    private string $webhook;

    /** @var Logger */
    private Logger $logger;

    public function __construct(string $webhook, Logger $logger)
    {
        // Убираем возможный слэш в конце
        $this->webhook = rtrim($webhook, '/') . '/';
        $this->logger  = $logger;
    }

    /**
     * Универсальный REST-вызов Bitrix
     *
     * @param string $method Например: crm.activity.get
     * @param array  $params Параметры запроса
     *
     * @return array|null
     */
    public function call(string $method, array $params = [])
    {
        $ch = curl_init($this->webhook . $method);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $this->logger->error('Curl error', [
                'method' => $method,
                'errno' => $errno,
                'error' => $error
            ]);
            curl_close($ch);
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $this->logger->error('Bitrix API HTTP error', [
                'method' => $method,
                'http_code' => $httpCode
            ]);
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode JSON from Bitrix', [
                'method' => $method,
                'json_error' => json_last_error_msg()
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Получение всех сущностей с учётом постраничной навигации
     *
     * @param string $method
     * @param array  $params
     * @return array
     */
    public function fetchAllEntities(string $method, array $params = []): array {
    $start = 0;
    $items = [];
    while ($start !== false) {
        $params['start'] = $start;
        $response = $this->call($method, $params);
        if (!$response || !isset($response['result'])) {
            break;
        }
        $items = array_merge($items, $response['result']);
        $start = $response['next'] ?? false;
    }
    return $items;
}
}
