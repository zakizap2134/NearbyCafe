# Cavite State University
## College of Engineering and Information Technology
### Department of Computer Studies

---

# Project Plan
## VenueVue
### An Internet-Independent, Locally Networked, Offline-First Point-of-Sale and Recipe-Driven Inventory System for Nearby Café

**Academic Course:** COSC 75: Software Engineering II  
**Development Team:** CTRL + STAY  

**Industry Client:** NEARBY CAFÉ — Legaspi St., Intramuros, Manila (Adjacent to Lyceum of the Philippines University - LPU)  

```
                           +----------------------+
                           |                      |
                           |     NEARBY CAFÉ      |
                           |   EST. INTRAMUROS    |
                           |                      |
                           +----------------------+
```

**Prepared by:**  
- **Zaki Zapico** — Project Manager / Lead Developer  
- **John Vincent Libao** — Systems Analyst
- **Jenny Lyn Roquid** — Systems Analyst  
- **Rhea Mae Gregorio** — QA & Testing Specialist  

**Document Revision:** Revision 2.5 — Physical Shop Transition & Final Production Alignment  
**Revised:** November 3, 2026 (Baseline Draft: October 27, 2026)  
**Target Completion Date:** November 20, 2026  

---

## Abstract
This Project Plan establishes the scope, responsibilities, engineering methodology, architectural design, resource dependencies, schedule, and budget for **VenueVue: An Internet-Independent, Locally Networked, Offline-First Point-of-Sale and Recipe-Driven Inventory System for Nearby Café**. Nearby Café is an active, physical specialty coffee shop located on **Legaspi St., Intramuros, Manila**, situated immediately adjacent to **Lyceum of the Philippines University (LPU Manila)**. The operational baseline addresses acute challenges common to campus-adjacent beverage businesses: manual order recording, slow mental change calculation during student class breaks, post-shift sales reconciliation, and the absence of a synchronized mechanism connecting drink sales directly to raw ingredient depletion.

The delivery scope encompasses a high-speed cashier register supporting cash (with exact tender shortcuts) alongside reference-verified Philippine cashless mobile payments (`GCash`, `Maya`, `QR Ph / Card`), customer name and cup note callouts, a zero-dependency synthesized Web Audio register chime, and dynamic product variants linked to multi-ingredient bills of materials. Inventory tracking incorporates First-In, First-Out (FIFO) batch lot numbering (`LOT-...`, `DEL-...`), shelf-life and expiration monitoring, spoiled/opened waste recording, 1-click recipe scaling across standard cup sizes (12oz, 16oz, 22oz), and a 1-click supplier reorder list clipboard export formatted for Viber and Messenger. A strict role-based access control (RBAC) model restricts Baristas to the Register while reserving Catalogue, Stock Operations, Staff, Audit, and Reporting to the Owner. Financial reporting features BIR-compliant 12% VAT calculations, an automated end-of-day Z-Reading that explicitly isolates Physical Cash in Drawer from Digital E-Wallets, multi-section structured CSV reports, and native styled Microsoft Excel (`.xlsx`) workbooks generated without third-party Composer packages.

The primary local deployment functions 100% independently of WAN/Internet access over a localized Wi-Fi hotspot hosted on a shop computer, overcoming Intramuros' thick stone architecture and frequent broadband dropouts. An optional online deployment provides cloud-hosted PHP REST API and MariaDB access over HTTPS when remote administrative review is desired. The two modes maintain independent authoritative databases to prevent unsynchronized live stock overwrites. The project strictly maintains November 20, 2026 as its terminal delivery and handover target.

---

## Disclaimer
This document is prepared for **COSC 75: Software Engineering II** at Cavite State University - Don Severino delas Alas Campus, Indang, Cavite. Statements about the operational context reflect the validated VenueVue baseline for **Nearby Café on Legaspi St., Intramuros, Manila**. Third-party product descriptions are attributed to their official vendor documentation. Software verification evidence reflects automated integration test suites, local edge host rehearsals, and browser automation test fixtures that guide final client acceptance testing prior to handover.

This plan establishes the architecture-reconciled baseline across primary local LAN operation and optional online hosting, defining concrete verification stage gates leading to final client sign-off.

---

## Table of Contents
1. [The Problem Domain](#1-the-problem-domain)  
   1.1 [Statement of the Problem](#11-statement-of-the-problem)  
   1.2 [Background and Rationale](#12-background-and-rationale)  
   1.3 [Objectives](#13-objectives)  
   1.4 [Significance and Scope of the Project](#14-significance-and-scope-of-the-project)  
2. [Project Organization](#2-project-organization)  
   2.1 [External Interfaces](#21-external-interfaces)  
   2.2 [Internal Structure](#22-internal-structure)  
   2.3 [Roles and Responsibilities](#23-roles-and-responsibilities)  
   2.4 [Deliverables](#24-deliverables)  
3. [Review of Existing Alternatives](#3-review-of-existing-alternatives)  
4. [Approach to be Taken in This Subject](#4-approach-to-be-taken-in-this-subject)  
   4.1 [Theoretical Framework / Methods to be Used](#41-theoretical-framework--methods-to-be-used)  
   4.2 [Rationale for the Framework / Methods](#42-rationale-for-the-framework--methods)  
   4.3 [Technologies You Plan to Consider or Use](#43-technologies-you-plan-to-consider-or-use)  
5. [System Design](#5-system-design)  
   5.1 [Requirements](#51-requirements)  
   5.2 [Resources](#52-resources)  
   5.3 [System Overview](#53-system-overview)  
6. [Project Timeline](#6-project-timeline)  
   6.1 [Gantt Chart (Unmodified Baseline)](#61-gantt-chart)  
   6.2 [Work Breakdown Structure (Unmodified Baseline)](#62-work-breakdown-structure)  
7. [Budget Allocation](#7-budget-allocation)  
   7.1 [Estimated Costs](#71-estimated-costs)  
   7.2 [Total Projected Budget](#72-total-projected-budget)  
[References](#references)  

---

# 1. The Problem Domain

## 1.1 Statement of the Problem
Nearby Café is a specialty brick-and-mortar coffee shop operating on **Legaspi St., Intramuros, Manila**, positioned in the immediate vicinity of **Lyceum of the Philippines University (LPU Manila)** and surrounding university belt institutions. Its everyday operations are shaped by the rhythm of campus life: extreme customer surges during 15-minute class passing times, lunch intervals, and late-afternoon dismissals.

Under these high-volume conditions, manual paper ticketing, calculator change computation, unrecorded drink customizations, and manual post-shift cash balancing create significant operational friction. Furthermore, university students predominantly pay via mobile e-wallets (`GCash` and `Maya`), leading to severe end-of-day reconciliation discrepancies when physical drawer cash is commingled with digital receipts.

The central problem is the absence of an integrated, Internet-independent point-of-sale and recipe-driven inventory system that coordinates multi-tablet counter operations reliably without depending on commercial cloud services or uninterrupted WAN connectivity. Table 1 identifies the operational problems and VenueVue's implemented responses:

| Problem | Operational Impact on Nearby Café | Response Within VenueVue Project Scope |
| :--- | :--- | :--- |
| **Separate order and stock records** | Baristas sell beverages without real-time deduction of espresso beans, dairy, and syrups. | Atomic transaction engine: each confirmed sale deducts exact recipe quantities under row-level database locks. |
| **Commingled cash and mobile payments** | Physical cash in the drawer fails to balance against gross sales due to unrecorded GCash/Maya tenders. | Multi-tender checkout with reference number capture; Z-Reading strictly isolates **Physical Cash in Drawer** from **Online E-Wallets**. |
| **Slow rush-hour ordering queues** | Long counter lines during LPU class breaks result in lost sales and frustrated students. | High-speed React POS with drink category filter pills, keyboard shortcuts (`/`, `Esc`, `Alt+E`), and 1-click repeat orders. |
| **Miscalled student orders and drink notes** | Baristas confuse custom student requests (e.g., sweetness level, oat milk) during noisy rush hours. | Optional customer name / cup note field stored with the sale, displayed on the confirmation modal, and printed on receipts. |
| **Uncertain usable ingredient quantities** | Perishable dairy and specialty syrups spoil unnoticed; stockouts occur mid-shift. | FIFO batch lot tracking (`LOT-...`, `DEL-...`), expiration monitoring, waste logging, and 1-click supplier reorder clipboard export. |
| **Complex multi-size recipe setup** | Manually computing ingredient weights across 12oz, 16oz, and 22oz cups leads to recipe errors. | 1-Click Recipe Copy and Cup Scaling that automatically computes proportional ingredient quantities based on fluid ounce ratios. |
| **Vulnerability to Intramuros connectivity** | Cloud POS systems freeze during local broadband outages or within thick stone buildings. | 100% Internet-independent local edge LAN; client IndexedDB queue handles temporary Wi-Fi drops without sales interruption. |
| **Tedious post-shift financial consolidation** | Owner spends hours manually calculating 12% VAT, cashier totals, and sales summaries. | Automated BIR 12% VAT calculations, daily Z-Reading, multi-section structured CSV, and native styled Excel (`.xlsx`) export. |

*Table 1. Problems and Planned Responses for Nearby Café*

## 1.2 Background and Rationale
Nearby Café's physical shop on Legaspi St. serves students, faculty, and historic district visitors who require fast, high-quality beverage service. A single beverage represents multiple distinct ingredients across different units: an iced flavored latte consumes espresso beans (grams), fresh milk (millilitres), syrup (millilitres), and paper/plastic cups, lids, and straws (individual units). Representing stock as simple finished goods is impossible; explicit recipes per variant are mandatory to maintain inventory integrity.

Operating in historic Intramuros introduces significant infrastructural constraints. The district's historic stone architecture, thick masonry walls, and overhead telecommunications density frequently degrade cellular mobile data and cause broadband dropouts. Relying on an external cloud POS is an unacceptable operational risk: if the broadband connection drops during an LPU class break, order taking halts. 

To overcome this, VenueVue implements a **local edge architecture**: a shop computer acts as the authoritative host on the local Wi-Fi network, processing checkouts, recipe deductions, and reports locally without WAN access. An optional cloud deployment over HTTPS is provided for remote administrative review, operating on an independent database without dangerous automated live stock merges.

## 1.3 Objectives
The general objective is to provide a reliable, rapid, and locally self-sufficient sales and recipe-recording workflow for Nearby Café through VenueVue, backed by verifiable transaction integrity and clear recovery procedures. Table 2 details the evaluation methods and target performance baselines:

| Objective | Evaluation Method & Implemented Proof |
| :--- | :--- |
| **Correct multi-tender checkout** | Compare selected variants, quantities, customer notes, cash/GCash amounts, change, and stored receipt totals with expected results. |
| **Accurate recipe deductions & scaling** | Verify that confirmed sales deduct exact ingredient quantities (grams, ml, units) from FIFO batches, and verify 1-click scaling across 12oz, 16oz, and 22oz drinks. |
| **Reliable failure handling & idempotency** | Inject network disconnections and repeat UUIDv4 transaction keys; verify zero partial writes, zero duplicate sales, and zero double deductions. |
| **Recover pending orders** | Disconnect Wi-Fi during checkout, verify orders safely stored in client IndexedDB, reconnect, and verify seamless synchronization. |
| **Usable response time** | Maintain confirmation latency $\le 2.0\text{ seconds}$ (95th percentile) over local Wi-Fi (up to 3 concurrent clients) and $\le 3.0\text{ seconds}$ for optional online HTTPS checkout. |
| **Consistent financial reports & exports** | Reconcile receipts, daily Z-Readings, 12% VAT calculations, Physical Cash vs. E-Wallet splits, and native styled Excel (`.xlsx`) / CSV exports against stored database sales. |

*Table 2. Objectives and Evaluation Evidence*

## 1.4 Significance and Scope of the Project
Nearby Café benefits from organized counter queues, automated ingredient depletion, reliable cash drawer accountability, and instant financial reporting. Baristas receive an intuitive Register with fast search, payment chips, cup note prompts, and audible feedback. The Owner receives full visibility over menu variants, batch expiration dates, staff accounts, and BIR 12% VAT remittance.

The committed functional scope includes:
- **Two-Role Security**: Owner (full administrative access) and Barista (restricted exclusively to Register). Features auto-suggested usernames and strong password generators.
- **Catalogue & Variant Recipes**: Size and temperature variants (with 1-click combo pills), local product image uploads, and 1-click recipe cup-size scaling (12oz, 16oz, 22oz).
- **FIFO Batch Inventory**: Stock intake with auto-generated delivery and lot codes (`DEL-...`, `LOT-...`), expiration dates, waste recording, and real-time low-stock alerts.
- **Supplier Reorder Tool**: 1-click formatting and copying of low-stock items to clipboard for Viber and Messenger supplier chats.
- **Register Flow & Ergonomics**: Fast search (`/`), category filters, 1-click repeat order, exact cash (`Alt+E`), customer cup notes, and synthesized Web Audio chime.
- **Multi-Tender Payments**: Cash with auto-change alongside reference-verified digital payments (`GCash`, `Maya`, `QR Ph / Card`).
- **Z-Reading & Financial Reporting**: Daily Z-Reading isolating Physical Cash from E-Wallets, 12% VAT breakdown, multi-section structured CSV, and native styled Excel (`.xlsx`) workbooks.
- **Resilient Recovery & Backup**: IndexedDB offline queue, UUIDv4 transaction idempotency, and owner-triggered encrypted database backup/restore.

Out-of-scope boundaries: Third-party food delivery aggregators (GrabFood/Foodpanda), automated live two-way multi-store cloud synchronization, customer loyalty apps, and automated accounting/payroll filing.

---

# 2. Project Organization

## 2.1 External Interfaces
The Nearby Café Owner and designated counter baristas provide menu pricing, variant recipes, ingredient packaging units, opened-container use-by policies, counter operating scenarios, and acceptance feedback. In-person shop consultation and acceptance testing take place at **Nearby Café on Legaspi St., Intramuros, Manila**. The Nearby Café Owner serves as the primary client approver and final operational acceptance authority, while the COSC 75 instructor serves as the academic evaluator across scheduled review checkpoints through November 20, 2026. When optional online hosting is activated, the selected hosting provider supplies HTTPS/TLS, PHP, and MySQL server access.

## 2.2 Internal Structure
CTRL + STAY operates as a coordinated three-member engineering team. Zaki coordinates scope, integration, and delivery forecasts. Jenny maintains requirements and their relationship to the SRS and client workflows. Rhea reviews outcomes independently, maintains defect evidence, and checks documentation against observed behavior. Work is reviewed incrementally: an author does not independently verify their own code, ensuring objective validation.

## 2.3 Roles and Responsibilities
Table 3 establishes the responsibility allocation across the development team:

| Work Area | Lead Member | Supporting Review |
| :--- | :---: | :--- |
| **Requirements and SRS** | Jenny | Zaki (feasibility); Rhea (testability) |
| **Frontend, PHP API and Integration** | Zaki | Jenny (workflow); Rhea (verification) |
| **Database and Inventory Rules** | Zaki | Jenny (business rules); Rhea (integrity tests) |
| **Master-Data Requirements and Catalogue** | Jenny | Zaki (integration); Rhea (validation) |
| **Deployment and Recovery Procedures** | Zaki | Rhea (rehearsal); Jenny (instructions) |
| **Independent QA and Regression** | Rhea | Zaki (corrections); Jenny (traceability) |
| **Manuals, Training and Handover** | Rhea | Jenny (user guidance); Zaki (technical support) |

*Table 3. Responsibility Matrix*

## 2.4 Deliverables
Project delivery comprises the complete VenueVue application source and production build assets; the reconciled Project Plan and Software Requirements Specification (SRS); automated and manual test evidence and defect disposition records; Windows launcher, installation, and Owner guides; encrypted backup and restoration procedures; and the final acceptance and handover package scheduled for completion by November 20, 2026. Optional online deployment includes staging verification on the target HTTPS host prior to live cutover.

---

# 3. Review of Existing Alternatives

Official vendor documentation and field evaluations for commercial Point-of-Sale (POS) platforms were examined against Nearby Café's physical shop operations on Legaspi St., Intramuros. Five relevant commercial systems—**Lavu POS**, **digabloPos**, **Peddlr POS**, **Loyverse POS**, and **Toast POS**—were evaluated alongside **VenueVue (Nearby Café)**.

Table 4 profiles each alternative, followed by Table 4A which presents a comprehensive feature-by-feature comparison matrix delineating baseline shared functions, key operational differences, and unique engineering capabilities.

| POS System | Target Segment & Architecture | Verified Vendor Description & Key Capabilities | Operational Limitations for Nearby Café |
| :--- | :--- | :--- | :--- |
| **Lavu POS** | Hospitality & Bars (Cloud / Hybrid iPad) | Supports offline counter operation with later cloud synchronization; provides ingredient-level tracking and recipe deduction (Lavu, n.d.-a, n.d.-b). | Prohibitive commercial subscription fees (\–\+/month); requires Apple iOS hardware; relies on heavy cloud services ill-suited for Intramuros' chronic broadband dropouts. |
| **digabloPos** | Retail & Hospitality (Android / Cloud Sync) | Advertises offline mode with later server synchronization; emphasizes product and variant stock tracking (digabloPos, n.d.). | Lacks verified multi-unit raw ingredient bill of materials deduction (e.g. deducting grams and ml from one beverage); export features locked behind paid tiers. |
| **Peddlr POS** | Philippine Micro-Retail (Mobile-first Android/iOS) | Mobile-first Philippine POS supporting offline sales, product inventory, and basic sales reports (Peddlr Philippines, Inc., n.d.). | Focuses on retail unit inventory rather than recipe-driven ingredient depletion; lacks per-size recipe scaling and cash drawer vs. e-wallet breakdown. |
| **Loyverse POS** | Cafés & Quick Service (Mobile / Cloud-First) | Cloud-synced tablet POS offering basic cashiering, item variants, and sales dashboards (Loyverse, 2026). Free core app with optional paid modules. | Composite inventory and recipe tracking requires a paid add-on (\/store/month); cannot execute multi-terminal sales without internet; lacks 1-click cup recipe scaling and FIFO lot expiration management. |
| **Toast POS** | Enterprise Restaurants (Proprietary Cloud / LAN) | Full-service restaurant platform with kitchen display systems, mobile ordering, and extensive cloud reporting (Toast, Inc., 2026). | Extremely high recurring cost (\–\+/terminal/month plus transaction percentages); requires proprietary Toast hardware; offline mode is limited to payment buffering rather than complete local edge governance. |
| **VenueVue (Nearby Café)** | Independent Specialty Coffee (Local Edge Host / LAN) | Custom-engineered local-authoritative edge system: multi-unit recipe BOM (grams, ml, units), FIFO batch lots, 1-click cup scaling, cash/e-wallet Z-Reading, and Web Audio chime. | **Engineered Match:** 100% operational on local Wi-Fi without Internet, zero subscription fees, and tailored specifically for Nearby Café's Intramuros counter. |

*Table 4. Commercial Alternatives Compared with Project Needs*

---

### 3.1 Feature Comparison Matrix

Table 4A cross-references the core functional features, architectural attributes, and operational differentiators between VenueVue and existing commercial platforms:

| System Capability / Functional Feature | VenueVue (Nearby Café) | Lavu POS | digabloPos | Peddlr POS | Loyverse POS | Toast POS |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **1. Common Baseline Functions** | | | | | | |
| Counter Cashiering & Order Entry | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Product & Variant Catalog (Size / Temperature / SKU) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Multi-Payment Tendering (Cash & Reference Tracking) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Customer Receipt Generation & Printing | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Basic Sales History & Daily Gross Totals | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **2. Architectural & Infrastructure Differences** | | | | | | |
| 100% Offline Local LAN Edge Server (Zero WAN / Internet Required) | ✓ *(Primary)* | ✗ *(Cloud-first)* | ✗ *(Sync-first)* | ✗ *(Device-only)* | ✗ *(Cloud-first)* | ✗ *(Cloud-first)* |
| Zero Recurring Subscription / Maintenance Fees | ✓ *(₱0 / Free)* | ✗ *(\–\+/mo)* | ✗ *(Tiered)* | ✓ *(Freemium)* | ✗ *(\/mo Add-on)* | ✗ *(\–\+/mo)* |
| Commercial Hardware Independence (Windows PC + Any Tablet) | ✓ | ✗ *(Apple iOS only)* | ✗ *(Android only)* | ✗ *(Mobile only)* | ✓ *(iOS / Android)* | ✗ *(Proprietary)* |
| Database ACID Transactions under Row-Level Locks | ✓ *(InnoDB)* | ✓ *(Cloud DB)* | ✗ *(Local SQLite)* | ✗ *(Local SQLite)* | ✗ *(Cloud DB)* | ✓ *(Cloud DB)* |
| **3. Beverage & Coffee-Specific Differentiators** | | | | | | |
| Atomic Multi-Unit Bill of Materials (BOM) Deduction (g, mL, units) | ✓ | ✓ | ✗ *(Units only)* | ✗ *(Units only)* | ✗ *(Paid / Basic)* | ✓ *(Add-on)* |
| 1-Click Proportional Recipe Cup Scaling (12oz, 16oz, 22oz) | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| FIFO/FEFO Batch Lot Tracking with Expiration & Opened Container Priority | ✓ | ✗ *(Batch only)* | ✗ | ✗ | ✗ | ✗ *(Enterprise)* |
| End-of-Day Z-Reading Isolating Physical Cash from E-Wallets | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| 1-Click Supplier Procurement Reorder Clipboard Export (Viber/Messenger) | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Synthesized Zero-Dependency Web Audio Chime Feedback | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Strict Supervisory Void Authorization (Mandatory Owner Re-Auth) | ✓ | ✓ | ✗ | ✗ | ✗ *(Manager PIN)* | ✓ |

*Table 4A. System Capability & Feature-by-Feature Comparison Matrix (✓ = Supported / Native, ✗ = Unsupported / Unavailable / Cost-Gated)*

---

### 3.2 Analysis of Similarities and Differences

#### What Functions are the Same (Baseline Equivalencies)
All evaluated platforms share standard point-of-sale functionality:
1. **Order Ringing & Catalog Browsing:** Each system provides an interface for cashiers to tap drink products, configure basic size variations, and calculate invoice line items.
2. **Payment Acceptance:** All systems support basic cash tendering and allow recording of external payment methods (such as digital payments, credit cards, or mobile wallets).
3. **Transaction Logging & Receipts:** All systems maintain historical sales records and support printing paper receipts or generating customer invoices.

#### Key Differences & Limitations of Commercial Systems
1. **Internet & Cloud Dependency:** Platforms like **Lavu**, **Loyverse**, and **Toast** operate under a cloud-first architecture. While they provide emergency offline transaction caching, counter tablets cannot initialize new sessions, configure menus, manage staff, or perform multi-terminal database operations when internet connectivity fails. VenueVue overcomes this by hosting the entire Apache/PHP/MariaDB stack on a local shop laptop, providing 100% LAN autonomy.
2. **Subscription Costs for Small Businesses:** Commercial systems require ongoing monthly fees (\/month for Loyverse's inventory add-on, \–\/month for Lavu, and \–\/month for Toast). For an independent campus café operating with tight margins, recurring software overhead is economically unsustainable.
3. **Hardware Constraints:** Lavu mandates Apple iPads; Toast requires proprietary terminal hardware; Peddlr is restricted to individual Android/iOS smartphones. VenueVue operates on any existing Windows laptop paired with standard Android/iOS tablets or smartphones via modern web browsers.

#### Unique Features Engineered for Nearby Café
1. **Multi-Unit Atomic Recipe Deduction:** When a beverage variant is sold, VenueVue atomically deducts exact component quantities (espresso beans in grams, dairy and syrups in millilitres, and cups/lids in pieces) directly from active inventory batches under row-level database locks (SELECT ... FOR UPDATE).
2. **1-Click Proportional Recipe Scaling:** Allows managers to duplicate an existing recipe and automatically scale all liquid and coffee quantities based on fluid-ounce volume ratios (e.g. 16oz to 22oz), keeping unit pieces (cups and lids) fixed at 1 unit.
3. **FIFO/FEFO Expiration & Opened-Container Depletion:** The inventory engine prioritizes opened containers before sealed containers and selects batches with the earliest expiration date.
4. **Physical Cash vs. E-Wallet Z-Reading Split:** End-of-day financial reporting explicitly isolates cash in drawer from digital payments (GCash, Maya, QR Ph) to ensure counter cashiers balance their physical cash drawer without discrepancy.
5. **Viber/Messenger Supplier Reorder Tool:** Detects low-stock items and formats an immediate procurement message copied directly to the clipboard with 1 click.

---

# 4. Approach to be Taken in This Subject

## 4.1 Theoretical Framework / Methods to be Used
The team uses an **Agile and incremental software engineering approach**: clarify a counter workflow, implement a bounded increment, verify it using representative café data, obtain client feedback, and update the plan. Requirements connect each business problem to an observable outcome and acceptance scenario. Transaction integrity, role restrictions, and recoverability are treated as design constraints across increments rather than afterthought features.

Verification combines unit-level validation, API integration, concurrent transaction tests, and Playwright browser workflow checks. Disposable test databases and synthetic accounts isolate testing from live café records.

## 4.2 Rationale for the Framework / Methods
Incremental delivery allows the Nearby Café Owner and baristas to review usable counter workflows while the team resolves dependencies between catalogue variants, stock depletion, and checkout. Automated evidence reduces repetitive checking, while testing on actual Windows laptops and client tablets ensures real-world viability.

## 4.3 Technologies Considered and Finalized
Table 5 outlines the finalized technology stack:

| Technology | Implementation Role | Technical Rationale |
| :--- | :--- | :--- |
| **React 18** | Register & Management Single Page Application (SPA) | Reusable component hierarchy, rapid UI state transitions, and high-speed counter interactions. |
| **Vite 8** | Modern Frontend Toolchain | Optimized ES module bundling, fast development feedback, and lightweight production builds. |
| **Tailwind CSS / Custom CSS** | Responsive Styling System | Mobile-first layout optimized for counter tablets, high contrast badges, and café branding. |
| **React Router (HashRouter)** | Client-Side Navigation | Hash-based routing (`#/register`, `#/management`) enables seamless transitions over local LAN IPs. |
| **Web Audio API** | Synthesized Register Feedback | Synthesizes a crisp dual-tone café chime ($880\text{ Hz} \rightarrow 1318.5\text{ Hz}$) with zero external audio assets. |
| **IndexedDB API** | Client Offline Persistence | Resilient browser object storage holding drafts and pending orders during Wi-Fi disconnects. |
| **PHP 8.2 (Vanilla REST)** | Server Business Logic & Routing | High-performance, stateless request execution using PDO prepared statements on the host laptop. |
| **MariaDB 10.4+ / MySQL (InnoDB)** | Authoritative Relational Storage | Enforces ACID transactions, row-level `FOR UPDATE` locking, foreign keys, and audit logging. |
| **Native OpenXML ZipEngine** | Excel Workbook (`.xlsx`) Generator | Custom PHP engine creating styled, multi-section Excel workbooks without third-party Composer bloat. |

*Table 5. Selected Technology and Purpose*

The stack relies on native InnoDB transactions and browser IndexedDB persistence without requiring Redis, complex service-worker proxies, or heavy backend frameworks.

---

# 5. System Design

## 5.1 Requirements
Requirements express the operational behavior of VenueVue, verified across automated integration test suites and client acceptance testing with Nearby Café.

| Functional Area | Required Operational Behavior |
| :--- | :--- |
| **Access Control** | Strictly two roles: Owner/Admin and Barista. Enforce management permissions server-side; log user IDs on all sales and inventory edits. |
| **Catalogue & Scaling** | Active products with size, temperature, SKU, price, and explicit recipe rows. Support 1-click recipe copy and proportional scaling (12oz, 16oz, 22oz). |
| **Inventory & FIFO** | Maintain units (g, ml, units), low-stock limits, delivery/lot codes (`DEL-...`, `LOT-...`), expiration dates, waste logs, and 1-click supplier reorder clipboard export. |
| **Multi-Tender Checkout** | Validate cash, exact shortcuts (`Alt+E`), auto-change, and cashless tenders (`GCash`, `Maya`, `QR Ph`) with reference numbers and customer cup notes. |
| **Order Recovery** | Persist pending orders in browser IndexedDB with UUIDv4 keys; server idempotency via SHA-256 hashing prevents duplicate sales or deductions upon reconnection. |
| **Z-Reading & Reports** | Compute gross, net, and 12% VAT. Generate daily Z-Readings isolating Physical Cash in Drawer from E-Wallets; export multi-section CSV and styled `.xlsx` workbooks. |
| **Disaster Recovery** | Single-use Owner recovery hashes and manual encrypted database backup/restore with preflight schema validation. |

*Table 6. Functional Requirements and Acceptance Focus*

All menu prices are VAT-inclusive at 12%. The server extracts net and VAT ($\text{Net} = \text{intdiv}(\text{Gross} \times 100 + 56, 112) / 100$; $\text{VAT} = \text{Gross} - \text{Net}$) and applies rounding reconciliation so line items and sales totals agree perfectly.

## 5.2 Resources
Table 7 identifies the deployment dependencies for Nearby Café:

| Resource | Specification & Deployment Role |
| :--- | :--- |
| **Team & Stakeholders** | Four CTRL + STAY student developers (Zaki Zapico, John Vincent Libao, Jenny Lyn Roquid, Rhea Mae Gregorio), Nearby Café Owner, counter baristas, and the COSC 75 instructor. |
| **Local Host Machine** | Windows 10/11 (64-bit) laptop (Intel Core i5 / AMD Ryzen 5, 8 GB RAM, SSD) running XAMPP Apache 2.4, PHP 8.2.12+, and MariaDB 10.4.32+ (InnoDB). |
| **Client Devices & LAN** | Up to three concurrent browser clients (Android/iOS tablets or smartphones) over a dedicated 802.11ac/ax Wi-Fi router or host Windows Mobile Hotspot. |
| **Development Tools** | Node.js 20+, npm, Vite 8.3.5, PHP 8.2 CLI, MariaDB 10.4, Git/GitHub, and Playwright browser automation test fixtures. |
| **Optional Online Host** | Standard Linux cloud hosting supporting PHP 8.2+, MariaDB 10.4+ / MySQL 8.0, and HTTPS/TLS for remote administrative review. |
| **Backup Storage** | Host-local private directory plus external USB 3.0 flash drive for off-host encrypted database backups. |

*Table 7. Resources and Deployment Dependencies*

## 5.3 System Overview
The React browser interface provides separate Register and Management screens. PHP authorizes requests and applies business rules to one authoritative database. Table 8 details high-level component responsibilities:

| Component | Architecture & Data Responsibility |
| :--- | :--- |
| **React Browser Interface** | Renders Register and Management; captures sales, notes, tenders, and transmits authenticated requests. |
| **IndexedDB Offline Queue** | Retains drafts and pending orders with UUIDv4 keys; safely synchronizes with the host API upon reconnection. |
| **Web Audio Synthesizer** | Generates zero-dependency dual-tone cash register chime upon sale confirmation; stores mute preference in `localStorage`. |
| **PHP REST API Engine** | Validates inputs, authenticates tokens, manages row locks, computes 12% VAT, and builds OpenXML `.xlsx` / CSV exports. |
| **Authoritative MariaDB (InnoDB)** | Persists users, sales, recipe deductions, stock movements, batch lots, audit events, and recovery hashes. |
| **Private Server Storage** | Holds runtime configurations, product image uploads, and encrypted backup snapshots outside the web root. |

*Table 8. High-Level Architecture and Data Responsibilities*

---

# 6. Project Timeline

The project schedule spans August 3 to November 20, 2026 (110 calendar days across 16 academic weeks). Weeks 1–8 covered context gathering, alternatives review, scope definition, technology feasibility, and baseline planning; Weeks 9–16 cover incremental implementation, system integration, verification, client acceptance testing, and final handover.

At the late-October revision checkpoint, the complete VenueVue source code, Windows launcher, and 58-group automated integration and browser regression suite are implemented. Remaining schedule gates focus on native Windows host and multi-device LAN validation, optional online staging, client acceptance testing with Nearby Café, and final turnover by November 20, 2026.

| Window in 2026 | Output and Gate | Lead Member |
| :--- | :--- | :---: |
| **October 26–November 1** | Finalize VenueVue (Nearby Café) SRS traceability, validate live café menu/recipe data, and confirm local vs. optional online staging environment. | Jenny / Zaki |
| **November 2–8** | Run device/LAN or selected-host staging checks; correct blockers and repeat affected regression. | Rhea / Zaki |
| **November 9–15** | Conduct client acceptance, recovery/backup rehearsal and training; obtain recorded feedback. | Rhea / Jenny |
| **November 16–20** | Close acceptance defects, finalize manuals and release package; obtain handover decision. | Zaki / Team |

*Table 9. Remaining Work and Proposed Review Gates*

November 20, 2026 serves as the terminal handover target. Optional online staging is scheduled in parallel with local LAN device verification so that optional cloud provisioning never delays the primary local XAMPP handover for Nearby Café. Any scope or schedule adjustment requires documented review with the client and course instructor.

Key risks are an unverified hosting plan, delayed client review, incorrect recipe/unit data, unavailable devices, and recovery or restore failures. The response is to resolve dependencies early, retain local deployment support, validate recipes with the Owner, and rehearse recovery on disposable data. New features will not displace the final acceptance period.

---

## 6.1 Gantt Chart
*(Note: As established in the project baseline, the scheduled project calendar, timeline, and Gantt chart are maintained strictly unmodified).*

Figure 1 separates the original planned work periods from the remaining completion windows. Shading denotes scheduled effort allocation across the 16-week academic timeline culminating in the November 20, 2026 terminal handover and course presentation.

```
                          Original plan (blue) and proposed remaining work (muted red)
      Context and alternatives  [============== ]
          Scope and feasibility            [============= ]
              Planning and SRS                         [====== ]
   Foundation and implementation                                [===================== ]
        Integration verification                                                       [====== ]
         Acceptance and handover                                                               [=========== ]
  Scope and data confirmation                                                          [===== ]
   Environment tests and fixes                                                                [===== ]
 Client acceptance and training                                                                       [===== ]
          Final release decision                                                                             [=== ]
                                +------------+------------+------------+------------+------------+------------+---
                                03 Aug       17 Aug       31 Aug       14 Sep       28 Sep       12 Oct       26 Oct  09 Nov  23 Nov
                                                                                                 (Oct 24 Review)
```
*Figure 1. Planned Schedule and Proposed Remaining-Work Allocation (Unmodified Baseline)*

The dashed reference point marks the late October review milestone. The upper six bars consolidate the original weekly plan for readability. The lower four bars are proposed review windows, not claims of completed work. Their overlap is deliberate: they show the remaining delivery work against the historical baseline.

Each weekly review examines evidence, blocker ownership, remaining effort, and the next gate. The team agrees on acceptance scenarios before testing so a demonstration is judged against requirements rather than appearance alone.

---

## 6.2 Work Breakdown Structure
*(Note: As established in the project baseline, the work breakdown structure allocations, hours, and work packages are maintained strictly unmodified).*

Table 10 reconciles the work-package descriptions and closure evidence with the completed VenueVue architecture while preserving the approved **540-hour baseline effort allocation** (Zaki Zapico: 220 hours, Jenny Lyn Roquid: 170 hours, Rhea Mae Gregorio: 150 hours) across all ten work packages.

| WBS Code | Work Package Name | Lead and Closure Evidence | Zaki (h) | Jenny (h) | Rhea (h) | Total Package Effort (h) |
| :---: | :--- | :--- | :---: | :---: | :---: | :---: |
| **1.0** | Client Context and Scope | Jenny / John: approved assumptions, boundaries, and client identity. | 12 | 18 | 6 | **36** |
| **2.0** | Requirements and SRS | Jenny / John: reviewed requirements, use cases, and acceptance traceability. | 14 | 46 | 12 | **72** |
| **3.0** | Foundation and Deployment | Zaki: reproducible installation and device/host checks. | 36 | 10 | 8 | **54** |
| **4.0** | Access and Catalogue | Zaki / John / Jenny: roles, guarded setup, variants, and recipes verified. | 22 | 34 | 10 | **66** |
| **5.0** | POS and Inventory | Zaki: atomic sale, batch/opened stock, waste, and adjustment evidence. | 48 | 18 | 22 | **88** |
| **6.0** | Order and Account Recovery | Zaki: queue reconciliation, single-use recovery, and session evidence. | 30 | 10 | 18 | **58** |
| **7.0** | Reports and Backup | Zaki: stored VAT/CSV consistency and rehearsed restore. | 24 | 10 | 14 | **48** |
| **8.0** | Integrated Acceptance | Rhea: regression, environment checks, and defect disposition. | 16 | 10 | 38 | **64** |
| **9.0** | Training and Handover | Rhea: manuals, training record, and client decision. | 6 | 8 | 18 | **32** |
| **10.0** | Coordination and Forecasting | Zaki: schedule updates, unresolved decisions, and final package. | 12 | 6 | 4 | **22** |
| **Total** | **Full-Project Planned Effort** | **540 team hours (includes 24h correction reserve in WBS 8.0)** | **220** | **170** | **150** | **540 h** |

*Table 10. Work Packages and Completion Evidence (Unmodified Baseline)*

Package-level development includes its own immediate functional checks. Work Package 8.0 covers integrated system verification, regression testing, and client/environment acceptance, and includes the 24-hour correction reserve (Zaki: 8 hours, Jenny: 4 hours, Rhea: 12 hours) already built into the 540-hour total so testing effort is never double-counted.

The completed application source and automated verification suite establish implementation evidence for Work Packages 3.0–7.0 and the automated portions of Work Package 8.0. Work Packages 1.0–2.0 and 9.0–10.0 conclude through final SRS alignment, Nearby Café user acceptance testing, staff training, and handover documentation.

---

# 7. Budget Allocation

## 7.1 Estimated Costs
VenueVue is developed as an unpaid student course project. Nearby Café will not pay the student team for software development, project coordination, testing, documentation, training, or handover. Existing host computers, client tablets, local Wi-Fi equipment, and USB backup media are supplied from existing café and team hardware at ₱0 additional capital cost.

The client-side cash budget covers transportation for two representatives traveling between **Indang, Cavite and Legaspi St., Intramuros, Manila** via public bus, LRT, and jeepney transfers across four face-to-face consultation, verification, and acceptance visits at a budgeted allowance of ₱400 per person per round trip (₱200 each way).

| Item | Calculation Basis | Total Amount (PHP) |
| :--- | :--- | :---: |
| **Student-team labor / engineering services** | Unpaid academic course project | ₱0.00 |
| **Software licenses** | Zero-cost open-source stack (PHP, MariaDB, React, Vite) | ₱0.00 |
| **Local hosting service** | Existing host laptop; no hosting subscription | ₱0.00 |
| **Transportation for client visits** | 2 representatives $\times$ 4 consultation visits $\times$ ₱400 | ₱3,200.00 |
| **Transport contingency** | 10% $\times$ ₱3,200 | ₱320.00 |
| **Provisional Client Base Budget** | Transport allowance plus contingency | **₱3,520.00** |

*Table 11. Local Deployment Base Budget*

The four visits represent the complete project planning assumption. The budget does not assert that electricity, internet access, or equipment have no economic cost; no additional cash purchase for them is currently confirmed.

## 7.2 Total Projected Budget
**Total paid to student team = ₱0.00.** Total projected client base budget for local LAN deployment = **₱3,520.00 PHP** (₱3,200.00 transportation across four visits plus ₱320.00 10% contingency).

Optional online PHP/MySQL cloud hosting and custom domain registration are modular add-ons outside the ₱3,520.00 local deployment base budget. Standard Linux PHP 8.2+ / MySQL shared hosting with Let's Encrypt HTTPS/TLS is provisioned only if the Nearby Café Owner elects to activate the optional online deployment mode alongside or in place of the local XAMPP host.

If online deployment is selected, the revised projected client budget equals ₱3,520.00 plus approved hosting/domain charges for the agreed period. Recurring renewals are accounted for separately from initial outlay.

---

# References

- **digabloPos.** (n.d.). *Offline mode: Sell without interruption, even without internet*. Retrieved October 24, 2026, from `https://digablopos.com/en/features/offline-mode`
- **Lavu.** (n.d.-a). *Best restaurant POS with offline mode*. Retrieved October 24, 2026, from `https://lavu.com/restaurant-pos/offline-mode`
- **Lavu.** (n.d.-b). *Inventory: Link ingredients to menu items*. Retrieved October 24, 2026, from `https://support.lavu.com/en/knowledge/inventory-link-ingredients-to-menu-items`
- **Peddlr Philippines, Inc.** (n.d.). *POS*. Retrieved October 24, 2026, from `https://www.peddlr.io/features/pos`
- **MDN Web Docs.** (2026). *IndexedDB API: Working with Client-Side Offline Storage*. Mozilla Developer Network.
- **W3C.** (2024). *Web Audio API: High-Performance Client-Side Audio Synthesis*. World Wide Web Consortium.
- **Oracle Corporation.** (2026). *MySQL 8.4 Reference Manual: InnoDB Locking and Transaction Model (How to Minimize and Handle Deadlocks)*. Oracle Documentation.
- **ECMA International.** (2021). *ECMA-376: Office Open XML File Formats (SpreadsheetML and Zip Packaging)*.
