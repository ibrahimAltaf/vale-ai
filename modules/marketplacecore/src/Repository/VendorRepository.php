<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;

final class VendorRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function all(): array
    {
        return $this->db->all(
            'SELECT v.*, s.`id_marketplace_shipping_rule` AS shipping_rule_id, s.`name` AS shipping_name,
                    s.`base_cents`, s.`free_threshold_cents`, s.`surcharge_cents_per_kg`, s.`postcode_prefixes`
             FROM ' . Connection::table('marketplace_vendor') . ' v
             LEFT JOIN ' . Connection::table('marketplace_shipping_rule') . ' s
               ON s.`id_marketplace_shipping_rule` = (
                    SELECT r.`id_marketplace_shipping_rule`
                    FROM ' . Connection::table('marketplace_shipping_rule') . ' r
                    WHERE r.`id_marketplace_vendor` = v.`id_marketplace_vendor` AND r.`active` = 1
                    ORDER BY (r.`postcode_prefixes` = \'\') DESC, r.`id_marketplace_shipping_rule` ASC
                    LIMIT 1
               )
             ORDER BY v.`code` ASC'
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT * FROM ' . Connection::table('marketplace_vendor')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $id
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM ' . Connection::table('marketplace_vendor')
            . ' WHERE `email` = ' . Connection::quote($email)
        );
    }

    /**
     * @return array<int, string>
     */
    public function commissionPercents(): array
    {
        $map = [];
        foreach ($this->all() as $vendor) {
            $map[(int) $vendor['id_marketplace_vendor']] = number_format((float) $vendor['commission_percent'], 2, '.', '');
        }

        return $map;
    }

    public function saveProfile(
        string $code,
        string $name,
        string $slug,
        string $email,
        string $passwordHash,
        string $commission,
        string $description,
        string $contactName,
        string $phone,
    ): int {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $existing = $this->db->one(
            'SELECT `id_marketplace_vendor` FROM ' . Connection::table('marketplace_vendor')
            . ' WHERE `code` = ' . Connection::quote($code)
        );
        if ($existing) {
            $id = (int) $existing['id_marketplace_vendor'];
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_vendor')
                . ' SET `name` = ' . Connection::quote($name)
                . ', `slug` = ' . Connection::quote($slug)
                . ', `email` = ' . Connection::quote($email)
                . ', `password_hash` = ' . Connection::quote($passwordHash)
                . ', `commission_percent` = ' . Connection::quote($commission)
                . ', `description` = ' . Connection::quote($description)
                . ', `contact_name` = ' . Connection::quote($contactName)
                . ', `phone` = ' . Connection::quote($phone)
                . ', `status` = ' . Connection::quote('active')
                . ', `date_upd` = ' . $now
                . ' WHERE `id_marketplace_vendor` = ' . $id
            );

            return $id;
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_vendor')
            . ' (`code`, `name`, `slug`, `status`, `commission_percent`, `email`, `password_hash`, `description`, `contact_name`, `phone`, `date_add`, `date_upd`) VALUES ('
            . Connection::quote($code) . ', ' . Connection::quote($name) . ', ' . Connection::quote($slug) . ', '
            . Connection::quote('active') . ', ' . Connection::quote($commission) . ', ' . Connection::quote($email) . ', '
            . Connection::quote($passwordHash) . ', ' . Connection::quote($description) . ', ' . Connection::quote($contactName) . ', '
            . Connection::quote($phone) . ', ' . $now . ', ' . $now . ')'
        );

        return $this->db->lastId();
    }

    public function updateCommercials(int $id, string $status, string $commission): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_vendor')
            . ' SET `status` = ' . Connection::quote($status)
            . ', `commission_percent` = ' . Connection::quote($commission)
            . ', `date_upd` = ' . Connection::quote(date('Y-m-d H:i:s'))
            . ' WHERE `id_marketplace_vendor` = ' . (int) $id
        );
    }

    public function saveShipping(int $vendorId, string $name, int $baseCents, ?int $thresholdCents, int $surchargeCents, string $prefixes): void
    {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $existing = $this->db->one(
            'SELECT `id_marketplace_shipping_rule` FROM ' . Connection::table('marketplace_shipping_rule')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
            . ' AND `name` = ' . Connection::quote($name)
        );
        $threshold = $thresholdCents === null ? 'NULL' : (string) (int) $thresholdCents;
        if ($existing) {
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_shipping_rule')
                . ' SET `name` = ' . Connection::quote($name)
                . ', `base_cents` = ' . (int) $baseCents
                . ', `free_threshold_cents` = ' . $threshold
                . ', `surcharge_cents_per_kg` = ' . (int) $surchargeCents
                . ', `postcode_prefixes` = ' . Connection::quote($prefixes)
                . ', `date_upd` = ' . $now
                . ' WHERE `id_marketplace_shipping_rule` = ' . (int) $existing['id_marketplace_shipping_rule']
            );

            return;
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_shipping_rule')
            . ' (`id_marketplace_vendor`, `name`, `base_cents`, `free_threshold_cents`, `surcharge_cents_per_kg`, `postcode_prefixes`, `active`, `date_add`, `date_upd`) VALUES ('
            . (int) $vendorId . ', ' . Connection::quote($name) . ', ' . (int) $baseCents . ', ' . $threshold . ', '
            . (int) $surchargeCents . ', ' . Connection::quote($prefixes) . ', 1, ' . $now . ', ' . $now . ')'
        );
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    public function rulesGrouped(): array
    {
        $grouped = [];
        foreach ($this->db->all(
            'SELECT * FROM ' . Connection::table('marketplace_shipping_rule')
            . ' WHERE `active` = 1 ORDER BY `id_marketplace_shipping_rule` ASC'
        ) as $rule) {
            $grouped[(int) $rule['id_marketplace_vendor']][] = $rule;
        }

        return $grouped;
    }

    public function setPassword(int $id, string $passwordHash): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_vendor')
            . ' SET `password_hash` = ' . Connection::quote($passwordHash)
            . ', `date_upd` = ' . Connection::quote(date('Y-m-d H:i:s'))
            . ' WHERE `id_marketplace_vendor` = ' . (int) $id
        );
        $this->db->exec(
            'DELETE FROM ' . Connection::table('marketplace_password_reset')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $id
        );
    }

    public function createPasswordReset(int $vendorId): string
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $this->db->exec(
            'DELETE FROM ' . Connection::table('marketplace_password_reset')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
        );
        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_password_reset')
            . ' (`id_marketplace_vendor`, `token_hash`, `expires_at`, `date_add`) VALUES ('
            . (int) $vendorId . ', ' . Connection::quote(hash('sha256', $token)) . ', '
            . Connection::quote(date('Y-m-d H:i:s', time() + 3600)) . ', ' . Connection::quote($now) . ')'
        );

        return $token;
    }

    public function findPasswordReset(string $token): ?array
    {
        return $this->db->one(
            'SELECT r.`id_marketplace_vendor`, v.`email`, v.`name`
             FROM ' . Connection::table('marketplace_password_reset') . ' r
             INNER JOIN ' . Connection::table('marketplace_vendor') . ' v
               ON v.`id_marketplace_vendor` = r.`id_marketplace_vendor`
             WHERE r.`token_hash` = ' . Connection::quote(hash('sha256', $token))
             . ' AND r.`expires_at` >= ' . Connection::quote(date('Y-m-d H:i:s'))
        );
    }
}
