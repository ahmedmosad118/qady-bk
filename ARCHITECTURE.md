# QADY PROJECT (دار القاضي) — Architecture & System Design

## 1. Domain Overview
"دار القاضي" is an ERP and publishing house/bookstore management platform.
Core domains:
- **Identity & Access Management:** Users, Roles, Permissions, Auth (Laravel Sanctum).
- **Catalog & Inventory (المنتجات والكتب):** Books (`Product`), Categories (`ProductCategory` hierarchical 2-level), SKUs, Barcodes, Pricing.
- **Sales & Operations:** Invoicing, Orders, Stock movements (forthcoming).

---

## 2. SOLID Architectural Layers

```
Routes (routes/api.php)
   │
   ▼
FormRequest (app/Http/Requests/)
   │  - Validates inputs strictly (unique, types, foreign keys)
   │  - Handles route authorization
   │
   ▼
Controller (app/Http/Controllers/Api/)
   │  - Thin: takes validated data, calls Service, wraps response
   │  - No business logic, no raw DB queries
   │
   ▼
Service Layer (app/Services/)
   │  - Core domain logic (SKU generation, pricing calculations, status transitions)
   │  - Enforces DB::transaction on multi-step persistence
   │  - Dispatches domain events/notifications
   │
   ▼
Repository / Query Scope (app/Repositories/ & app/Models/)
   │  - Abstract query building, relationships, eager loading
   │  - Pagination and server-side filtering
   │
   ▼
Eloquent Models & Database (app/Models/, database/migrations/)
   │  - Fillable guards, casts, relationships, soft deletes
   │
   ▼
API Envelopes (Clean JSON Responses)
      {
        "success": true,
        "message": "...",
        "data": { ... },
        "meta": { "current_page": 1, "total": 100 }
      }
```

---

## 3. CRUD Architecture: Evaluation & Path Forward

### Analysis of the Base `CRUDController`
The codebase currently contains `App\Core\CRUD\CRUDController` + `InputMaker` + `Field`.
While created with good intentions (to provide rapid scaffolding for admin tables), it suffers from classic architectural pitfalls:

1. **SRP Violation (Single Responsibility):**
   A single base class is handling HTTP dispatching, validation generation, DB transactions, query filtering, sorting, pagination, and UI schema definition.
2. **DIP Violation (Dependency Inversion):**
   The controller directly talks to the Eloquent model (`$this->model::query()`), bypassing the Service and Repository layers entirely.
3. **OCP Violation (Open/Closed):**
   Adding custom business rules (e.g. stock reservation, barcode validation against external systems, event dispatching) requires hacking generic `beforeSave`/`afterSave` hooks or overriding methods completely.
4. **Production Readiness:**
   In an enterprise production app, business logic should never live inside controllers.

### The Production-Grade Strategy:
- **For Backend:** Adopt Thin Controllers + Dedicated Services + Repositories/Scopes.
- **For Dynamic UI / Form generation:** If dynamic forms are required by the frontend, decouple `InputMaker` into a standalone **Schema Definition Service** (e.g. `UserFormSchema`, `ProductFormSchema`) that produces UI schema metadata without entangling controller HTTP and database logic.
- **For Users Module:** Refactor `UserController` to follow the Service pattern, removing the monolithic `CRUDController` dependency while keeping frontend compatibility.

---

## 4. Frontend Architecture (Vue 3 + Vite + Tailwind CSS)

- **Entry & Routing:** `resources/js/app.js`, `resources/js/router/`.
- **State:** Pinia stores (`resources/js/stores/`).
- **Services:** API client (`resources/js/services/api.js`) with Axios interceptors for auth tokens.
- **Views & Components:**
  - `resources/js/views/`: Page-level components.
  - `resources/js/components/`: Modular reusable components.
- **Server-driven Pagination:** UI must rely on backend pagination params (`page`, `per_page`, `search`) and never attempt to download all pages into memory for client-side filtering.
