# QADY ERP (دار القاضي) — Project Memory

> Durable global facts and reference patterns. Full protocols live in `.ai/skills/` and `AGENTS.md`.
> Last reviewed: 2026-11-21.

## Stack (Verified)
- **Backend**: Laravel 13, PHP 8.3+, MySQL (`kady` in local `.env`), SQLite `:memory:` in `phpunit.xml`, Sanctum SPA token auth, `spatie/laravel-permission`, `spatie/laravel-activitylog`.
- **API Responses**: Direct `response()->json(['success' => true, 'data' => ..., 'meta' => ...])`. `app/Http/Resources` does NOT exist.
- **Frontend**: Vue 3 (`<script setup>`) + Vite + Pinia + Tailwind CSS + Lucide icons, RTL/LTR dynamic (`lang/ar/`, `lang/en/`). Do NOT render top `StatCard` counter rows above list/tree pages (removed across screens per user preference, 2026-11-19).
- **Permanent Frontend Rules (i18n, RTL/LTR, Readability)**:
  1. **Zero Hardcoded UI Strings**: Never hardcode Arabic (or untranslated English) strings in `.vue` templates/scripts, stores, utils, or services. All user-visible text must go through `vue-i18n` (`$t`, `t`, `i18n.global.t`) with strict key parity in `resources/js/locales/ar.js` and `resources/js/locales/en.js`.
  2. **Dynamic RTL/LTR & Zero Hardcoded `dir`**: Never hardcode `dir="rtl"` or `dir="ltr"` on table cells (`<td>`, `<div>`, `<span>`, `<button>`), dates, document/invoice numbers, barcodes, phones, or form inputs (`dir="ltr"` inside a `text-start` container flips `text-align: start` to `left` in RTL and misaligns columns from their `<th>` headers). Only root layout/teleported overlay containers may bind `:dir="locale === 'ar' ? 'rtl' : 'ltr'"`. Use logical Tailwind directional utilities (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`, `border-s`, `border-e`) instead of physical (`ml-`, `mr-`, `pl-`, `pr-`, `left-`, `right-`, `text-left`, `text-right`), and ensure every table column's `<th>` and `<td>` share matching alignment (`text-start`, `text-center`, or `text-end`). Never hardcode `'ar-EG'` outside `utils/date.js` / locale-aware helpers.
  3. **Document/Invoice Numbers Contrast**: Render clickable invoice, order, shift, expense, and document numbers in high-contrast `text-[var(--text)] hover:text-[var(--crimson)]` (never plain red `text-[var(--crimson)]`).
  4. **Print Isolation (`@media print`)**: Vite bundles all SFC `<style>` blocks into the global CSS (`public/build/assets/app-*.css`). Never write unconditional `body > *:not(#target) { display: none !important }` inside `@media print`; always guard with `body:has(#target)` (e.g., `body:has(#barcode-print-root) > *:not(#barcode-print-root)` and `body:has([data-app-drawer]) > #app`). Always reset `:root, .dark` CSS tokens and `html, body` (`height: auto !important; overflow: visible !important`) in `@media print` (`resources/css/app.css`, `BarcodePrintSheet.vue`).

## Reference Service Architecture (Gold Standard)
- **Unified Domain Service Facade**: Controllers inject ONE service (`PosSaleService`), which internally orchestrates focused collaborators:
  - `App\Support\Decimal` (`90 lines`): Pure BCMath helpers (`MONEY_SCALE = 2`, `QUANTITY_SCALE = 3`, `CALCULATION_SCALE = 8`). Never use `float` for money/quantity calculations.
  - `App\Services\Pos\PosSaleCalculator` (`334 lines`): Pure domain math (item consolidation, line totals, discounts, remainder-absorbing invoice discount allocation) with zero DB queries.
  - `App\Services\Pos\PosSaleQueryService` (`75 lines`) & `PosProductCatalogService` (`68 lines`): Read-only pagination, single-subquery category filtering, and barcode lookups.
  - `App\Services\Pos\PosDefaultWarehouseResolver` (`29 lines`): Single default warehouse resolution (`Warehouse::default()`).
  - `App\Services\Pos\PosSaleService` (`411 lines`): Atomic write orchestration, bulk `whereIn` product loading, bulk `insert()` for sale & inventory items, and in-memory relation hydration (`hydrateSale`).
  - `App\Services\Pos\PosShiftService`: Single `GROUP BY type` SQL query (`sumTransactionsByType`) for shift cash calculations.
- **Strict Backed Enums**: All domain types/statuses use backed Enums in `app/Enums/` (`PaymentMethodType`, `DiscountType`, `InventoryDocumentType`, `InventoryDocumentStatus`, etc.). Never branch on Arabic display names (`'نقدي'`, `'فيزا'`) or hardcoded IDs (`1`).
