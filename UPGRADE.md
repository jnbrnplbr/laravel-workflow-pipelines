# Upgrade Guide

## Current release

There are no previous tagged releases. Install the package following the host setup in [README.md](README.md).

## Schema changes

Published migrations are immutable. Every later schema change must add a new timestamp-free stub in `database/migrations/`; do not edit an existing migration. In the host application, publish new stubs with:

```sh
php artisan workflow-pipelines:install --migrations
php artisan migrate
```

The install command skips migration files whose migration name is already present. Review data-impacting changes and back up production data before applying migrations.

## Published frontend files

The package installer preserves existing host Vue files. Updates do not replace customizations. Compare package changes manually with the host's published copies, or use `php artisan workflow-pipelines:install --force` only when intentionally replacing config, frontend, or translation customizations. Migration files are never force-replaced.

## Compatibility policy

Minor and patch releases preserve the `Approvable` contract, public `WorkflowEngine` methods, configuration keys, table columns, and audit action names. Breaking changes are reserved for a major release and documented here before upgrade.
