# Nearby Café header review — 21 October 2026

The main workspace header/navigation is warm cream (#fff9f0). Its far-left logo area occupies approximately one eighth of desktop width (180px at 1440px), with a 144px minimum. Tablet uses 144px; mobile uses 128px rather than one eighth. The account row moves below on smaller screens.

The logo container matches the original image's dominant red (#fe2200). The original 2000×2000 image is unchanged and displayed with object-fit: contain: its entire square canvas is visible, without stretching or cropping. The header logo does not use the older wordmark-cropping CSS. Header navigation uses the existing muted red (#a93226). Recovery-page header uses the same full-image treatment.

Verified build and actual rendered header at 1440, 1024, 768, 390 and 320px. No horizontal overflow; 44px controls; Owner/Register/Management and Barista/Register navigation; sign-out and recovery navigation checked with synthetic data. Desktop and mobile screenshots were visually reviewed. These previews contain only demo identities.

This update changes presentation only. Authentication, API, checkout, inventory, VAT and database code are unchanged. The prior Task 5 security/integration report remains the baseline evidence; those backend suites were not rerun for this markup/CSS-only update. No migration, push or merge.

The complete ZIP includes compiled frontend assets and all prior application work. See START_HERE.md for installation/upgrade. The header-only patch targets c8c7baf; the cumulative recovery patch targets 9a43ca8. Client approval of the visual interpretation is still pending.
