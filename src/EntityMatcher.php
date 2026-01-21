<?php
/**
 * EntityMatcher
 * -------------
 * Поиск лида или сделки по теме письма.
 */

class EntityMatcher
{
    /**
     * Если тема письма содержит TITLE сущности — считаем совпадением
     */
    public static function matchBySubject(string $subject, array $entities): ?array
    {
        $subject = mb_strtolower($subject);

        foreach ($entities as $entity) {
            $title = mb_strtolower($entity['TITLE'] ?? '');

            if ($title && mb_strpos($subject, $title) !== false) {
                return $entity;
            }
        }

        return null;
    }
}
