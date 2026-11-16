# Module memory: Catalog & Contacts UI (qadyBK)

> One file per module/area. Keep short; link to skills, don't repeat them.
> Last reviewed: 2026-10-28.

## What it is
Full CRUD screens for the existing backend modules of دار القاضي:
- Products: `resources/js/views/catalog/ProductsView.vue` → `ProductController`, `ProductRepository`, `ProductService`.
- Product categories (2-level tree): `resources/js/views/catalog/ProductCategoriesView.vue` → `ProductCategoryController`, `ProductCategoryService`.
- Contacts (customers/suppliers): `resources/js/views/sales/ContactsView.vue` → `ContactController`, `ContactRepository`, `ContactService`.

Routes: `resources/js/router/routes.js` (`/products`, `/product-categories`, `/contacts`).
Sidebar: `resources/js/config/navigation.js` (Sales → Invoices, Customers & Suppliers; Catalog → Products, Product Categories, Inventory).
i18n keys: `products.*`, `productCategories.*`, `contacts.*` in `resources/js/locales/{ar,en}.js`.

## Confirmed rules
- Index endpoints return the standard envelope plus a top-level `stats` object used by the stat cards and filter chips: products `{total, active, inactive}`, contacts `{total, customers, suppliers, active, inactive}`. Keep `stats` outside `meta` so `meta` stays pagination-only.
- Category tree is strictly 2 levels (`parent_id` must reference a top-level row — `StoreProductCategoryRequest::categoryRules`). The UI flattens the tree into rows (`level 0/1`) for search/filtering and offers only top-level rows as parent options.
- `UpdateProductCategoryRequest` sets `parent_id` to `prohibited` for subcategories, and a top-level category that already has children must stay top-level. `ProductCategoriesView` locks the parent field in those cases and **omits `parent_id` from the PUT payload** (`saveRecord`), otherwise the parent would be nulled/prohibited.
- `StoreProductRequest` and `UpdateProductRequest` treat `barcode` as `nullable` (and unique when set); the legacy `products.sku` column and `ProductSkuGenerator` were completely removed across DB (`2026_11_16_400002_drop_sku_from_products_table.php`), backend, snapshots, and frontend, relying solely on `barcode`. `ProductRepository::paginate` eager-sums `warehouseStocks` (`warehouse_stocks_sum_quantity`) so `/products` displays both `purchase_price` and total warehouse stock alongside `barcode` (2026-11-16).
- Individual customer contacts (`type = individual` and `is_customer = true` with `is_supplier = false`) do not collect or retain `company_contact_person`, `tax_number`, `tax_registration_type`, or `commercial_register`: `ContactsView.vue` hides them in the form and `ContactService::normalizeIndividualCustomerData` nulls them on create/update (2026-11-16).
- Contact delete is single-record (`DELETE /contacts/{contact}`); products/categories accept comma-separated ids for bulk delete.
- `ProductCategoryService::delete` throws 422 `products.delete_has_children` when a category still has subcategories — surface `err.response.data.message` in the toast.
- `/users` crash (`Cannot read properties of undefined (reading 'length')`) came from `filteredUsers.length` in `UsersView.vue`; the pagination block now uses `totalRecords > 0`.

## Fixes applied
- 2026-10-28: added the three CRUD screens + sidebar entries; added `ProductRepository::stats()` and new `ContactRepository` (index query moved out of the controller); added `stats` to both index responses; feature tests `test_can_list_products_with_pagination_stats_and_category_filter` and `test_can_list_contacts_with_stats_and_role_filters` (37 tests / 163 assertions green).

## Open issues (need product decision or later work)
- Contact addresses (backend `ContactAddressController` + `/contacts/{contact}/addresses`) have no UI yet.
- Contacts screen has no bulk delete (backend has no bulk endpoint).
- `app/Http/Controllers/Api/ContactController.php` still imports `App\Models\Contact` for `show`/`destroy` model binding.
