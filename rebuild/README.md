# Nearby Café application

This folder is the complete runnable application. Start with the [root README](../README.md), [documentation map](../docs/README.md), [installation and upgrade guide](../docs/setup/INSTALLATION.md), [Owner guide](../docs/setup/OWNER_GUIDE.md) and [current verification report](../docs/academic/VERIFICATION.md).

- `src/`: React, React Router, Vite and Tailwind frontend.
- `app/`: private PHP business logic; `public/api/index.php`: API entry point.
- `database/`: four cumulative schema files; current schema version is 4.
- `tools/`: provisioning, preflight, migration and authorized recovery tools.
- `launcher/`: Windows service setup, Owner launcher and local icons.
- `static/`: favicon sources copied into `public/` by Vite.
- `storage/`: private runtime records, never publish its contents.

Use `npm ci` then `npm run build` here for a Git source checkout. Built delivery packages already include `public/index.html` and `public/assets`. Only `public/` should be exposed by a web server.

The detailed [Windows launcher guide](../docs/setup/WINDOWS_LAUNCHER_SETUP.md), [backup/restore guide](../docs/operations/BACKUP_RESTORE.md), [recovery guide](../docs/operations/RECOVERY.md) and [password policy](../docs/archive/rebuild-stages/PASSWORD_POLICY_UPDATE.md) remain useful references. Other stage/task documents in [docs/archive/rebuild-stages](../docs/README.md) describe earlier releases, not extra patches to install. Follow the root guides for this integrated release.
