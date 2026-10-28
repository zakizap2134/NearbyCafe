# Nearby Café — complete application with Windows launcher

**23 October 2026.** This package includes rebuild Stages 1–5, original Tasks 1–7, browser recovery Tasks 1–5, the Unicode password update, and the new Windows launcher/icons. Earlier patches are not required.

1. Developer: follow **[WINDOWS_LAUNCHER_SETUP.md](WINDOWS_LAUNCHER_SETUP.md)**. It covers both upgrading your existing `C:\Projects\VenueVue\rebuild` installation and a fresh installation.
2. After that one-time setup, Owner/Admin double-clicks **Nearby Café** on the desktop. See **[OWNER_START_USE_STOP.txt](../../rebuild/OWNER_START_USE_STOP.txt)**.
3. Review **[LAUNCHER_VERIFICATION.md](../archive/rebuild-stages/LAUNCHER_VERIFICATION.md)** before client handover. Automated checks passed; native Windows/XAMPP, Chrome/Edge UI and physical-device acceptance remain to be completed on the actual laptop.

The included production frontend runs without Node/npm during ordinary shop use. XAMPP must remain installed because its PHP, Apache and MariaDB files are used. The launcher manages Windows services; opening the XAMPP control panel is unnecessary during daily use.

Existing accounts, configuration, sales, inventory and browser-local pending orders must be retained. No database migration is introduced by this update. Do not delete the Owner, reinstall an existing database, or clear browser site data to apply it.

`START-DEV.bat` remains available for developers using PHP + Vite. It is separate from `Start-Nearby-Cafe.bat` and must not run on the same port at the same time.

Additional guides: README.md (application workflows/development), PASSWORD_POLICY_UPDATE.md, OWNER-RECOVERY-GUIDE.txt, BACKUP_RESTORE.md. Older stage/task verification reports describe their historical releases; current launcher evidence is in LAUNCHER_VERIFICATION.md.
