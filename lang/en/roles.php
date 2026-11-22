<?php

declare(strict_types=1);

return [
    'created' => 'Role created successfully.',
    'updated' => 'Role updated successfully.',
    'deleted' => 'Role deleted successfully.',
    'cannot_modify_super_admin' => 'The Super Admin role cannot be modified.',
    'cannot_rename_system_role' => 'System roles cannot be renamed.',
    'cannot_delete_system_role' => 'System roles cannot be deleted.',
    'cannot_delete_role_with_users' => 'This role cannot be deleted while users are still assigned to it.',
    'cannot_remove_last_super_admin' => 'At least one Super Admin user must remain in the system.',
    'only_super_admin_can_assign_super_admin' => 'Only a Super Admin can create or assign a Super Admin role.',
];
