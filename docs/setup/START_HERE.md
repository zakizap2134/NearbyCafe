# Nearby Café — complete application with Windows launcher

**23 October 2026.** This package includes rebuild Stages 1–5, original Tasks 1–7, browser recovery Tasks 1–5, the Unicode password update, and the new Windows launcher/icons. Earlier patches are not required.

1. **1-Click Setup (Fastest):** Place the project at `C:\Projects\NearbyCafe` (with XAMPP installed at `C:\xampp`), open `C:\Projects\NearbyCafe\rebuild`, and double-click **`One-Click-Setup.bat`** to automatically configure `config\local.php`, initialize the database, register services, create `Desktop\NEARBY_CAFE_OWNER_SETUP_CODE.txt`, and launch `http://localhost:5174`.
2. **1-Click Uninstall / Reset:** To remove services or reset the database for a fresh test, double-click **`C:\Projects\NearbyCafe\rebuild\Uninstall-Nearby-Cafe.bat`**.
3. **Developer manual setup:** Follow **[INSTALLATION.md](INSTALLATION.md)** and **[WINDOWS_LAUNCHER_SETUP.md](WINDOWS_LAUNCHER_SETUP.md)**.
4. **Daily Operation:** After setup, Owner/Admin double-clicks **Nearby Café** (`Desktop\Nearby Café.lnk`) on the desktop for daily shop operations. See **[OWNER_START_USE_STOP.txt](../txt/OWNER_START_USE_STOP.txt)**.
5. Review **[LAUNCHER_VERIFICATION.md](../archive/rebuild-stages/LAUNCHER_VERIFICATION.md)** before client handover. Automated checks passed; native Windows/XAMPP, Chrome/Edge UI and physical-device acceptance remain to be completed on the actual laptop.

The included production frontend runs without Node/npm during ordinary shop use. XAMPP must remain installed because its PHP, Apache and MariaDB files are used. The launcher manages Windows services; opening the XAMPP control panel is unnecessary during daily use.

Existing accounts, configuration, sales, inventory and browser-local pending orders must be retained. No database migration is introduced by this update. Do not delete the Owner, reinstall an existing database, or clear browser site data to apply it.

`START-DEV.bat` remains available for developers using PHP + Vite. It is separate from `Start-Nearby-Cafe.bat` and must not run on the same port at the same time.

Additional guides: README.md (application workflows/development), PASSWORD_POLICY_UPDATE.md, OWNER-RECOVERY-GUIDE.txt, BACKUP_RESTORE.md. Older stage/task verification reports describe their historical releases; current launcher evidence is in LAUNCHER_VERIFICATION.md.
