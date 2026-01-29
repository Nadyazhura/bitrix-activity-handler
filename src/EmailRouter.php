<?php
/**
 * EmailRouter
 * -----------
 * Финальная бизнес-логика маршрутизации писем
 */

class EmailRouter
{
    private Logger $log;
    private ActivityService $activity;
    private DealService $deals;
    private LeadService $leads;
    private ContactService $contacts;

    public function __construct(
        Logger $log,
        ActivityService $activity,
        DealService $deals,
        LeadService $leads,
        ContactService $contacts
    ) {
        $this->log = $log;
        $this->activity = $activity;
        $this->deals = $deals;
        $this->leads = $leads;
        $this->contacts = $contacts;
    }

    /**
     * Основной метод обработки email-активности
     */
    public function handle(int $activityId): void
    {
        try {
            $activity = $this->activity->get($activityId);

            // Проверка типа активности
            if (!$activity || $activity['TYPE_ID'] !== '4') {
                $this->log->info('Skip non-mail activity', ['activityId' => $activityId]);
                return;
            }

            $subject = $activity['SUBJECT'] ?? '';
            $email   = $activity['SETTINGS']['EMAIL_META']['from'] ?? '';
            $this->log->info('Обработка активности', ['activityId' => $activityId, 'тема' => $subject, 'от' => $email]);

            // Ищем контакт
            /*$this->log->info('Ищем контакт с email ', [$email]);
            $contact = $this->getValidContact($email);

            // Ищем сделки и лиды контакта, подходящие по теме.
            // Если не нашли - создаем лид, клонируем в него активность
            if ($contact) {
                $this->handleEntitiesByContact($activity, $subject, $contact);
                return;
            }else{
                $this->log->info('Контакт не найден', []);
            }*/

            // Ищем по всем лидам
            $this->log->info('Ищем по всем лидам', []);
            if ($this->handleEntitiesByAll($activity, $subject, 'lead')) return;

            // Ищем по всем сделкам
            $this->log->info('Ищем по всем сделкам', []);
            if ($this->handleEntitiesByAll($activity, $subject, 'deal')) return;

            // Если ничего не найдено — создаем новый лид
            $this->log->info('Не найдено ни лидов, ни сделок с такой темой, Создаем новый лид', ['subject' => $subject]);
            $newLeadId = $this->leads->createLeadAndWait($subject);
            
            if (!$newLeadId) {
                $this->log->info('Не удалось создать лид', ['subject' => $subject]);
                return;
            }

            $this->log->info('Новый лид создан', ['leadId' => $newLeadId]);
            $this->cloneAndDeleteActivity($activity['ID'], $newLeadId, 1, 'lead');

        } catch (Exception $e) {
            $this->log->error('Exception in EmailRouter::handle', [
                'activityId' => $activityId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Найти контакт по email, вернуть null если не найден или ошибка
     */
    private function getValidContact(?string $email): ?array
    {
        try {
            if (!$email) {
                $this->log->info('Не указана почта, пропускаем', ['email' => $email]);
                return null;
            }
            // Извлекаем чистый email
            $cleanEmail = $this->extractEmail($email);
            return $this->contacts->findByEmail($cleanEmail);
        } catch (Exception $e) {
            $this->log->error('Exception in getValidContact', [
                'email' => $email,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Извлечь email из строки (поддерживает формат "Имя <email>")
     */
    private function extractEmail(string $email): string
    {
        // Ищем email в угловых скобках
        if (preg_match('/<([^>]+)>/', $email, $matches)) {
            return $matches[1];
        }
        // Если скобок нет, проверяем, является ли вся строка email
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        // Иначе возвращаем как есть (хотя это может быть ошибкой)
        return $email;
    }

    private function handleEntitiesByContact(array $activity, string $subject, array $contact): void
    {
        try {
            $this->log->info('Контакт найден. Ищем лиды и сделки у контакта', $contact);

            // Сделки контакта
            $dealsList = $this->deals->listByContact($contact['ID']);
            if (is_array($dealsList)) {
                $deal = EntityMatcher::matchBySubject($subject, $dealsList);
                if ($deal) {
                    $this->log->info('Сделка найдена', [$deal['ID']]);
                    $this->cloneIfNotBound($activity, $deal['ID'], 2, 'deal');
                    return;
                }
            }

            // Лиды контакта
            $leadsList = $this->leads->listByContact($contact['ID']);
            if (is_array($leadsList)) {
                $lead = EntityMatcher::matchBySubject($subject, $leadsList);
                if ($lead) {
                    $this->log->info('Лид найден', [$lead['ID']]);
                    $this->cloneIfNotBound($activity, $lead['ID'], 1, 'lead');
                    return;
                }
            }

            // Если совпадений по контакту нет — создаем новый лид для контакта
            $this->log->info('У этого контакта не найдено ни лидов, ни сделок с такой темой', ['contactId' => $contact['ID'], 'subject' => $subject]);
            $newLeadId = $this->leads->createLeadAndWait($subject, $contact['ID']); // Предполагаем, что метод принимает contactId

            if (!$newLeadId) {
                $this->log->info('Не удалось создать лид для контакта', ['contactId' => $contact['ID'], 'subject' => $subject]);
                return;
            }

            $this->log->info('Новый лид создан для контакта', ['leadId' => $newLeadId, 'contactId' => $contact['ID']]);
            $this->cloneAndDeleteActivity($activity['ID'], $newLeadId, 1, 'lead');
            return;
        } catch (Exception $e) {
            $this->log->error('Exception in handleEntitiesByContact', [
                'activityId' => $activity['ID'],
                'subject' => $subject,
                'contactId' => $contact['ID'],
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Обработка поиска по всем лидам или сделкам
     */
    private function handleEntitiesByAll(array $activity, string $subject, string $entityType): bool
    {
        try {
            $list = $entityType === 'lead' ? $this->leads->listAll() : $this->deals->listAll();
            $ownerTypeId = $entityType === 'lead' ? 1 : 2;

            $entity = EntityMatcher::matchBySubject($subject, $list);
            if ($entity) {
                $this->cloneIfNotBound($activity, $entity['ID'], $ownerTypeId, $entityType);
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->log->error('Exception in handleEntitiesByAll', [
                'activityId' => $activity['ID'],
                'subject' => $subject,
                'entityType' => $entityType,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Клонировать активность к новой сущности, если она еще не привязана к ней
     */
    private function cloneIfNotBound(array $activity, int $newOwnerId, int $ownerTypeId, string $entityType): void
    {
        try {
            $this->log->info("Клонировать если еще не прикреплен", [$activity['OWNER_ID'], $newOwnerId]);

            if ((int)$activity['OWNER_ID'] === $newOwnerId) {
                $this->log->info("Активность уже прикреплена к сущности $entityType", [
                    'activityId' => $activity['ID'],
                    $entityType.'Id' => $newOwnerId
                ]);
                return;
            }

            $this->cloneAndDeleteActivity($activity['ID'], $newOwnerId, $ownerTypeId, $entityType);
        } catch (Exception $e) {
            $this->log->error('Exception in cloneIfNotBound', [
                'activityId' => $activity['ID'],
                'newOwnerId' => $newOwnerId,
                'entityType' => $entityType,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Клонировать активность к новой сущности и удалить старую
     */
    private function cloneAndDeleteActivity(int $activityId, int $newOwnerId, int $ownerTypeId, string $entityType): void
    {
        try {
            $this->log->info("Клонирование активности в тип $entityType", ['activityId' => $activityId, 'newOwnerId' => $newOwnerId]);
            $newId = $this->activity->clone($activityId, $newOwnerId, $ownerTypeId);

            if ($newId) {
                $this->log->info('Активность успешно клонирована', ['newActivityId' => $newId, 'newOwnerId' => $newOwnerId]);
                $this->activity->delete($activityId);
            } else {
                $this->log->error('Не удалось клонировать активность', ['activityId' => $activityId, 'newOwnerId' => $newOwnerId]);
            }
        } catch (Exception $e) {
            $this->log->error('Exception in cloneAndDeleteActivity', [
                'activityId' => $activityId,
                'newOwnerId' => $newOwnerId,
                'entityType' => $entityType,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
