# VenueVue (Nearby Café) — Documentation Map

This directory contains all operational, setup, academic, and historical documentation for **VenueVue** (*An Internet-Independent, Locally Networked, Offline-First Point-of-Sale and Recipe-Driven Inventory System for Nearby Café*).

Start from the [root README](../README.md) or the [application README](../rebuild/README.md).

---

## 1. Setup & Deployment (`docs/setup/`)

| Document | Purpose |
|---|---|
| [INSTALLATION.md](setup/INSTALLATION.md) | Fresh Windows XAMPP installation and safe upgrade workflow |
| [WINDOWS_LAUNCHER_SETUP.md](setup/WINDOWS_LAUNCHER_SETUP.md) | Detailed Windows service setup, LocalService ACLs, and LAN access |
| [OWNER_GUIDE.md](setup/OWNER_GUIDE.md) | Daily Start → Use → Stop guide, router AP isolation, DHCP reservation, and barista Wi-Fi triage |
| [TROUBLESHOOTING.md](setup/TROUBLESHOOTING.md) | Reference codes, service inspection, `File.Replace` fix, and network/outage checks |
| [START_HERE.md](setup/START_HERE.md) | Quick-start summary for the Windows launcher integration |

---

## 2. Operations, Recovery & Handover (`docs/operations/`)

| Document | Purpose |
|---|---|
| [BACKUP_RESTORE.md](operations/BACKUP_RESTORE.md) | Encrypted `.vvbackup` export/restore, post-outage checks, and QA chaos-test protocol |
| [RECOVERY.md](operations/RECOVERY.md) | Owner offline recovery codes and Barista password resets |
| [HANDOVER.md](operations/HANDOVER.md) | Client handover checklist and operational readiness |
| [DEMO_SCRIPT.md](operations/DEMO_SCRIPT.md) | Step-by-step live demonstration workflow |
| [ACCEPTANCE.md](operations/ACCEPTANCE.md) | User Acceptance Testing (UAT) sign-off checklist |

---

## 3. Academic & Integration Reports (`docs/academic/`)

| Document | Purpose |
|---|---|
| [VERIFICATION.md](academic/VERIFICATION.md) | Automated regression, launcher, and password-policy verification evidence |
| [INTEGRATION.md](academic/INTEGRATION.md) | Source reconciliation and publication privacy review |
| [PUBLISHING.md](academic/PUBLISHING.md) | Git bundle import, review, and pull-request publishing instructions |
| [PR_BODY.md](academic/PR_BODY.md) | Pull request summary template |
| [VENUEVUE PROJECT PLAN Rev 2.3.md](academic/VENUEVUE%20PROJECT%20PLAN%20Rev%202.3.md) | COSC 75 Software Engineering II Project Plan (Markdown source) |
| [VENUEVUE MASTER PROJECT PLAN AUDIT & REALIGNMENT.md](academic/VENUEVUE%20MASTER%20PROJECT%20PLAN%20AUDIT%20&%20REALIGNMENT.md) | Academic baseline audit and architectural alignment notes |

Formal PDF/DOCX deliverables remain in `Docu/` at the repository root. Raw verification outputs are stored in `docs/verification/`.

---

## 4. Historical Rebuild Archive (`docs/archive/rebuild-stages/`)

Historical milestone and stage reports retained for traceability:

- [STAGE1_RESULTS.md](archive/rebuild-stages/STAGE1_RESULTS.md) — Foundation & authentication
- [STAGE2_RESULTS.md](archive/rebuild-stages/STAGE2_RESULTS.md) — Catalogue, FEFO stock & VAT checkout
- [STAGE3_RESULTS.md](archive/rebuild-stages/STAGE3_RESULTS.md) — Offline queue & account recovery
- [STAGE4_RESULTS.md](archive/rebuild-stages/STAGE4_RESULTS.md) — Management, reports & encrypted backups
- [STAGE5_RESULTS.md](archive/rebuild-stages/STAGE5_RESULTS.md) — Nearby Café branding & handover
- [VERIFICATION_TASKS1-7.md](archive/rebuild-stages/VERIFICATION_TASKS1-7.md) & [TASK6_THEME.md](archive/rebuild-stages/TASK6_THEME.md) — Account & theme tasks
- [APPLY_BROWSER_RECOVERY_TASKS1-4.md](archive/rebuild-stages/APPLY_BROWSER_RECOVERY_TASKS1-4.md), [BROWSER_RECOVERY_TASK1.md](archive/rebuild-stages/BROWSER_RECOVERY_TASK1.md), [BROWSER_RECOVERY_TASK4.md](archive/rebuild-stages/BROWSER_RECOVERY_TASK4.md), [RECOVERY_TASK5_VERIFICATION.md](archive/rebuild-stages/RECOVERY_TASK5_VERIFICATION.md) — Browser recovery milestones
- [PASSWORD_POLICY_UPDATE.md](archive/rebuild-stages/PASSWORD_POLICY_UPDATE.md) & [PASSWORD_POLICY_VERIFICATION.md](archive/rebuild-stages/PASSWORD_POLICY_VERIFICATION.md) — Shared PHP/JS Unicode password policy
- [LAUNCHER_VERIFICATION.md](archive/rebuild-stages/LAUNCHER_VERIFICATION.md) & [HEADER_DESIGN_NOTES.md](archive/rebuild-stages/HEADER_DESIGN_NOTES.md) — Launcher & header verification notes
