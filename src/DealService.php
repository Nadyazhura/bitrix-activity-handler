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

    public function __construct(BitrixClient $bx)
    {
        $this->bx = $bx;
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
     * Найти сделки, где фигурирует email клиента
     * (через активности)
     */
    public function listByEmail(string $email): array
    {
        $result = [];

        $deals = $this->listAll();

        foreach ($deals as $deal) {
            if ($this->dealHasEmail($deal['ID'], $email)) {
                $result[] = $deal;
            }
        }

        return $result;
    }

    /**
     * Проверяем, есть ли email в активностях сделки
     */
    private function dealHasEmail(int $dealId, string $email): bool
    {
       /*  $acts = $this->bx->call('crm.activity.list', [
            'filter' => [
                'OWNER_TYPE_ID' => 2,
                'OWNER_ID' => $dealId,
                'TYPE_ID' => 'EMAIL'
            ],
            'select' => ['COMMUNICATIONS']
        ])['result'] ?? [];

        foreach ($acts as $act) {
            foreach ($act['COMMUNICATIONS'] ?? [] as $comm) {
                if (strcasecmp($comm['VALUE'], $email) === 0) {
                    return true;
                }
            }
        }

        return false; */
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

    /**
     * Создание сделки
     */
    /* public function create(string $title): ?int
    {
        return $this->bx->call('crm.deal.add', [
            'fields' => [
                'TITLE' => $title ?: 'Проект без темы',
                'SOURCE_ID' => 'EMAIL'
            ]
        ])['result'] ?? null;
    } */

    function createDealAndWait(string $title, int $timeout = 30, int $interval = 2): ?int 
    {        
        // 1️⃣ Создаём сделку
        $newDealId = $this->bx->call('crm.deal.add', [
            'fields' => [
                'TITLE' => $title ?: 'Проект без темы',
                'SOURCE_ID' => 'EMAIL'
            ]
        ])['result'] ?? null;

        if (!$newDealId) {           
            $this->log->error('Failed to create deal', ['title' => $title]);
            return null;
        }
        $this->log->info('Deal Creation OK ', ['title' => $title, 'newDealId' => $newDealId]);

        // 2️⃣ Ожидаем, пока сделка появится в системе 
        $maxAttempts = (int)ceil($timeout / $interval);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $getResult = $this->bx->call('crm.lead.get', ['id' => $newDealId]);

            $this->log->debug("attempt to get deal {$attempt}", [!empty($getResult['result'])]);

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
    }
}
