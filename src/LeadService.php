<?php
/**
 * LeadService
 * -----------
 * Работа с лидами
 */

class LeadService
{
    private BitrixClient $bx;
    private Logger $log;
    private static array $cache = [];
    private const CACHE_TTL = 300; // 5 минут

    public function __construct(BitrixClient $bx, Logger $log)
    {
        $this->bx = $bx;
        $this->log = $log;
    }

    /**
     * Лиды контакта
     */
    public function listByContact(int $contactId): array
    {
        $res = $this->bx->fetchAllEntities('crm.lead.list', [
            'order'  => ['ID' => 'DESC'],
            'filter' => [
                'CONTACT_ID' => $contactId
            ],
            'select' => ['ID', 'TITLE','DATE_CREATE']
        ]);
        return $res ?? [];
    }

    public function listAll(): array
    {
        $cacheKey = 'leads_all';
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            return self::$cache[$cacheKey]['data'];
        }

        $res = $this->bx->fetchAllEntities('crm.lead.list', [
            'order'  => ['ID' => 'DESC'],
            'filter' => [ 'STATUS_SEMANTIC_ID'  => ['P'] ],  
            'select' => ['ID', 'TITLE']
        ]);

        $leads = $res;
        $count = is_array($leads) ? count($leads) : 0;

        self::$cache[$cacheKey] = ['data' => $leads, 'time' => time()];
        $this->log->info("Количество проверяемых лидов: {$count}", []);
        return $leads;
    }

    public function createLeadAndWait(string $title, ?int $contactId = null, int $timeout = 30, int $interval = 2): ?int 
    {        
        // Создаём лид
        $fields = [
            'TITLE' => $title ?: 'Проект без темы',
            'SOURCE_ID' => 'EMAIL'
        ];
        if ($contactId) {
            $fields['CONTACT_ID'] = $contactId;
        }
        $createRes = $this->bx->call('crm.lead.add', [
            'fields' => $fields
        ]);

        $newLeadId = $createRes['result'] ?? null;

        if (!$newLeadId) {           
            return null;
        }

        // Ожидаем, пока лид появится в системе 
        $maxAttempts = (int)ceil($timeout / $interval);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $getResult = $this->bx->call('crm.lead.get', ['id' => $newLeadId]);

            if (!empty($getResult['result'] ?? null)) {
                // Лид найден – можно вернуть его данные 
                return $newLeadId;
            }
            // Если не найдено, подождём и попробуем снова
            sleep($interval);
        }
        // Если вышли за пределы таймаута
        $this->log->info("Lead with ID: {$newLeadId} creation timeout {$timeout}sec expired", []);        
        return null;
    }
}
