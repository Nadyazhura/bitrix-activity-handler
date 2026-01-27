<?php
/**
 * ContactService
 * --------------
 * Поиск контакта по email
 */

class ContactService
{
    private BitrixClient $bx;

    public function __construct(BitrixClient $bx)
    {
        $this->bx = $bx;
    }

    /**
     * Найти контакт по email
     */
    public function findByEmail(string $email): ?array
    {
        $res = $this->bx->call('crm.contact.list', [
            'filter' => [
                'EMAIL' => $email
            ],
            'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL']
        ]);

        $data = $res['result'] ?? [];
        return $data[0] ?? null;
    }
}
