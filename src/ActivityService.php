<?php
/**
 * ActivityService
 * ----------------
 * Работа с CRM Activity (письма).
 */

class ActivityService
{
    private BitrixClient $bx;
    private Logger $logger;

    public function __construct(BitrixClient $bx, Logger $logger)
    {
        $this->bx = $bx;
        $this->logger = $logger;
    }

    /**
     * Получить activity по ID
     */
    public function get(int $id): ?array
    {
        return $this->bx
            ->call('crm.activity.get', ['id' => $id])['result']
            ?? null;
    }

    /**
     * Клонирование email-активности
     * 
     * @param int $activityId ID исходной активности
     * @param int $newOwnerId Новый ID сущности (лид или сделка)
     * @param int $ownerTypeId Тип сущности (1 — лид, 2 — сделка)
     * @return int ID новой активности
     */
    public function clone(int $activityId, int $newOwnerId, int $ownerTypeId): ?int
    {
        $this->logger->info('[START] Cloning email activity', [
            'activityId' => $activityId,
            'newOwnerId' => $newOwnerId,
            'ownerTypeId' => $ownerTypeId
        ]);

        // Получаем данные о существующей активности
        $activity = $this->bx->call('crm.activity.get', ['id' => $activityId]);

        // Если активность не найдена, логируем ошибку
        if (!isset($activity['result'])) {
            $this->logger->error('[ERROR] Activity not found', ['activityId' => $activityId]);
            return 0;
        }

        $activityData = $activity['result'];
        $email = $this->extractClientEmail($activityData);

        $communications = [
            [
                'VALUE'=>$email, 
                'VALUE_TYPE'=>"WORK", 
                'ENTITY_TYPE_ID'=>1
            ],
        ]; 

        // Подготовка данных для новой активности
        $newActivityFields = [
            'OWNER_TYPE_ID' => $ownerTypeId, // Тип сущности
            'OWNER_ID' => $newOwnerId,       // ID новой сущности
            'TYPE_ID' => $activityData['TYPE_ID'], // Тип активности (email)
            'SUBJECT' => $activityData['SUBJECT'], // Тема письма
            'DESCRIPTION' => $activityData['DESCRIPTION'], // Тело письма
            'SETTINGS' => $activityData['SETTINGS'], // Все настройки, включая email-мета-данные
            'COMMUNICATIONS' => $communications,
            'CREATED' => date('Y-m-d H:i:s'), // Время создания новой активности
            'START_TIME'=> $activityData['START_TIME'], // Если есть время начала
            'END_TIME' => $activityData['END_TIME'], // Если есть время окончания
            'PRIORITY' => $activityData['PRIORITY'], // Приоритет
            'DESCRIPTION_TYPE' => $activityData['DESCRIPTION_TYPE'], // Тип описания
            'DIRECTION' => $activityData['DIRECTION'], // Направление (входящее/исходящее)
            'COMPLETED' => $activityData['COMPLETED'] ?? 'N', // Новая активность всегда будет открытой
            'LOCATION' => $activityData['LOCATION'], // Если есть локация
            'AUTHOR_ID' => $activityData['AUTHOR_ID'] ?? null,
            'RESPONSIBLE_ID' => $activityData['RESPONSIBLE_ID'] ?? null,
            "SANITIZE_ON_VIEW" => $activityData['SANITIZE_ON_VIEW'] ?? 1,
        ];

        // Создаем новую активность через crm.activity.add
        $createResult = $this->bx->call('crm.activity.add', [
            'fields' => $newActivityFields
        ]);

        // Проверяем, если создание прошло успешно
        if (isset($createResult['result'])) {
            $newActivityId = $createResult['result'];
            $this->logger->info('[SUCCESS] New email activity cloned', [
                'newActivityId' => $newActivityId
            ]);
            return $newActivityId;
        } else {
            $this->logger->error('[ERROR] Failed to clone activity', [
                'error' => $createResult['error'] ?? 'Unknown error'
            ]);
            return null;
        }
    }

    public function delete(int $activityId): bool
    {
        $this->logger->info('[START] Deleting activity', ['activityId' => $activityId]);
        $result = $this->bx->call('crm.activity.delete', ['id' => $activityId]);
        if (isset($result['result'])) {
            $this->logger->info('[SUCCESS] Activity deleted', ['activityId' => $activityId]);
            return true;
        } else {
            $this->logger->error('[ERROR] Failed to delete activity', [
                'activityId' => $activityId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return false;
        }
    }

    /**
     * Перепривязать activity к сущности (лид или сделка)
     *
     * @param int $entityType 1 — лид, 2 — сделка
     * @param int $entityId
     */
    public function rebind(int $activityId, int $entityType, int $entityId, string $subject )
    {
        return $this->bx->call('crm.activity.update', [
            'id' => $activityId,
            'fields' => [
                'OWNER_TYPE_ID' => $entityType,
                'OWNER_ID'      => $entityId,
                'SUBJECT' => $subject,
                'COMPLETED' => 'N',
                'BINDINGS' => [
                    [
                        'OWNER_TYPE_ID' => $entityType,
                        'OWNER_ID'     => $entityId,
                    ]
                ]
            ]
        ]);
    }
    public static function extractClientEmail(array $activity): ?string
    {
        if (!empty($activity['SETTINGS']['EMAIL_META']['from'])) {
            return self::parse($activity['SETTINGS']['EMAIL_META']['from']);
        }
        return null;
    }

    private static function parse(string $value): ?string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return strtolower(trim($m[1]));
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL)
            ? strtolower($value)
            : null;
    }
}
