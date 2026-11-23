<?php

namespace App\Support\Schema;

enum SchemaStatus: string
{
    /** No Kasmin tables yet – the installer has to run. */
    case NotInstalled = 'not_installed';

    /** Database and code match. */
    case Ok = 'ok';

    /** The code contains migrations that have not run yet – an upgrade is required. */
    case UpgradeRequired = 'upgrade_required';

    /** The database was upgraded by a newer Kasmin version – this code must not run against it. */
    case DatabaseNewer = 'database_newer';
}
