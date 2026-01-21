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
        /* $this->logger->debug('Bitrix API call', [
            'method' => $method,
            'params' => $params
        ]); */

        $ch = curl_init($this->webhook . $method);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);

        // Ошибка curl
        if ($response === false) {
            $this->logger->error('Curl error', [
                'error' => curl_error($ch)
            ]);
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        $data = json_decode($response, true);

        //$this->logger->debug('Bitrix API response', $data ?? []);

        return $data;
    }
}
