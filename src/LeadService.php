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
        return $this->bx->call('crm.lead.list', [
            'filter' => [
                'CONTACT_ID' => $contactId
            ],
            'select' => ['ID', 'TITLE']
        ])['result'] ?? [];
    }

    public function listAll(): array
    {
        $cacheKey = 'leads_all';
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            return self::$cache[$cacheKey]['data'];
        }

        $leads = $this->bx->call('crm.lead.list', [
            'select' => ['ID', 'TITLE']
        ])['result'] ?? [];

        self::$cache[$cacheKey] = ['data' => $leads, 'time' => time()];
        return $leads;
    }

    function createLeadAndWait(string $title, ?int $contactId = null, int $timeout = 30, int $interval = 2): ?int 
    {        
        // Создаём лид
        $fields = [
            'TITLE' => $title ?: 'Проект без темы',
            'SOURCE_ID' => 'EMAIL'
        ];
        if ($contactId) {
            $fields['CONTACT_ID'] = $contactId;
        }
        $newLeadId = $this->bx->call('crm.lead.add', [
            'fields' => $fields
        ])['result'] ?? null;

        if (!$newLeadId) {           
            return null;
        }

        // Ожидаем, пока лид появится в системе 
        $maxAttempts = (int)ceil($timeout / $interval);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $getResult = $this->bx->call('crm.lead.get', ['id' => $newLeadId]);

            if (!empty($getResult['result'])) {
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
