<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\Database\BaseConnection;

final class DbGroupMembershipResolver implements GroupMembershipResolver
{
    public function __construct(
        private readonly ?BaseConnection $db = null,
    ) {
    }

    public function userHasAnyGroup(int $userId, array $groups): bool
    {
        $groups = array_values(array_filter(
            $groups,
            static fn ($g): bool => is_string($g) && trim($g) !== ''
        ));

        if ($groups === []) {
            return false;
        }

        $db = $this->db ?? db_connect();

        return $db->table('auth_groups_users')
            ->where('user_id', $userId)
            ->whereIn('group', $groups)
            ->countAllResults() > 0;
    }
}
