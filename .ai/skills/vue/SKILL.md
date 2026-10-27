---
name: vue
description: Vue 3 / Pinia / PrimeVue / Bootstrap 5 / RTL rules for Plamx frontends (tasksApp, projectsApp, kpisApp...). Use for any frontend change.
---

# Vue in Plamx

## Verified stack (resources/js & blade)
Vue 3, Pinia (only state manager), Composition API with `<script setup>`, PrimeVue, Bootstrap 5.
There are two main ways Vue is mounted in this project:
1. **Vite + SFCs**: Full apps (like tasksApp, projectsApp) built with Vite and Vue single-file components.
2. **Blade + CDN**: Vue 3 is injected directly in Blade files using the global CDN (e.g. `vue.global.js`) for specific lightweight screens (like `progress_weight_review.blade.php`).

## How an app is mounted
**For Vite apps:**
- Blade file has a root element: `<div id="tasksApp"></div>`.
- Script is loaded with `@vite('resources/js/tasksApp/index.js')`.

**For Blade + CDN screens:**
- Vue 3 is imported via `<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>`.
- Logic is written inline in Blade inside `<script>` tags using `Vue.createApp(...)`.
- **CRITICAL:** Follow the existing mounting method of the screen you are modifying. Do NOT convert a CDN screen to Vite unless explicitly requested.

**Global Data:**
Backend data (translations, `authPermissions`, user data) is injected into the global `window` object with a
plain `<script>` in Blade BEFORE the main Vue script. When you need new backend data in Vue, extend that existing
injection; don't add a new fetch if the data is already in `window`. Never put secrets in `window`.

## Before changing anything
Open a sibling component/store in the SAME app and copy its structure, API layer, validation and loading/error states.

## Rules
- State: extend the existing Pinia store that owns the data; no new store for owned state, no other state library.
- Keep PrimeVue import style (e.g. `import Select from 'primevue/select'`). No other UI library, no Tailwind.
- Bootstrap 5 + Metronic/KeenIcons for layout/icons; reuse existing patterns.
- Never rename existing props, emits, events, component names, store actions, API methods
  (keep `project`, `completionCertificate`, etc.).
- Use the app's existing API layer; match backend JSON keys exactly (`message`, `entity`/`data`: check the endpoint).
- Permissions: use the injected `authPermissions` for UX only; the backend enforces.

## Data handling
- Handle null/undefined (e.g. `project_status_id`).
- Mirror siblings' loading, empty and error states; show backend 422 errors the way siblings do.
- `Late` task status may be computed by the backend; don't recompute it differently in the UI.

## RTL / i18n
- Arabic-first. Logical classes (`ms-*`, `me-*`, `text-start`), not `ml-*`/`mr-*`/`text-left`.
- Use the injected translations; no hard-coded user-facing strings if siblings use translations.

## Quality
- Small single-purpose components; extract only when reused or clearly too large.
- `:key` on every `v-for`; no `v-if` + `v-for` on one element.
- No `console.log`, no unused imports. Debounce search like siblings.

## Checklist
- [ ] Matches the app's existing patterns
- [ ] Existing props/emits/store API unchanged
- [ ] Null-safe and RTL-safe
- [ ] `npm run build` / lint run if available (say so if not)
