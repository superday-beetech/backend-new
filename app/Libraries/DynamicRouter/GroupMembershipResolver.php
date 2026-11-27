<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

interface GroupMembershipResolver
{
    /**
     * @param list<string> $groups
     */
    public function userHasAnyGroup(int $userId, array $groups): bool;
}
