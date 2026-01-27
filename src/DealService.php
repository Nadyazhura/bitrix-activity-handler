<?php
/**
 * DealService
 * -----------
 * Работа со сделками
 */

class DealService
{
    private BitrixClient $bx;
    private static array $cache = [];
    private const CACHE_TTL = 300; // 5 минут
    private Logger $log;

    public function __construct(BitrixClient $bx, Logger $log)
    {
        $this->bx = $bx;
        $this->log = $log;
    }

    /**
     * Получить сделки контакта
     */
    public function listByContact(int $contactId): array
    {
        return $this->bx->call('crm.deal.list', [
            'filter' => [
                'CONTACT_ID' => $contactId
            ],
            'select' => ['ID', 'TITLE']
        ])['result'] ?? [];
    }

    /**
     * Получить ВСЕ сделки (используется как fallback)
     */
    public function listAll(): array
    {
        $cacheKey = 'deals_all';
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            return self::$cache[$cacheKey]['data'];
        }

        $deals = $this->bx->call('crm.deal.list', [
            'select' => ['ID', 'TITLE']
        ])['result'] ?? [];

        self::$cache[$cacheKey] = ['data' => $deals, 'time' => time()];
        return $deals;
    }

    function createDealAndWait(string $title, int $timeout = 30, int $interval = 2): ?int 
    {        
        // Создаём сделку
        $newDealId = $this->bx->call('crm.deal.add', [
            'fields' => [
                'TITLE' => $title ?: 'Проект без темы',
                'SOURCE_ID' => 'EMAIL'
            ]
        ])['result'] ?? null;

        if (!$newDealId) {           
            return null;
        }

        // Ожидаем, пока сделка появится в системе 
        $maxAttempts = (int)ceil($timeout / $interval);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $getResult = $this->bx->call('crm.deal.get', ['id' => $newDealId]);

            if (!empty($getResult['result'])) {
                // Сделка найдена – можно вернуть его данные
                $this->log->info("Deal is created and found ID {$newDealId}",[]);   
                return $newDealId;
            }
            // Если не найдено, подождём и попробуем снова
            sleep($interval);
        }
        // Если вышли за пределы таймаута
        $this->log->info("Deal with ID: {$newDealId} creation timeout {$timeout}sec expired", []);          
        return null;
    }
}
