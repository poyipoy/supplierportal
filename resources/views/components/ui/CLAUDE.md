# resources/views/components/ui (`x-ui.*`)

Shared design-system components used by every role and both cores. Reuse one before writing new markup; changing props or markup here changes every page that uses it.

- Styling: only `tw:`-prefixed Tailwind utilities (preflight is off, so unprefixed classes do nothing). Colors are M3-style token classes such as `tw:bg-primary`, `tw:bg-surface-container`, and `tw:text-on-surface`, mapped to `--md-*` CSS variables in `tailwind.config.js` / `resources/css/app.css`. No raw hex.
- `icon`: `<x-ui.icon name="..." size="xs|sm|md|lg">` renders Lucide through blade-lucide-icons. It is the class component `App\View\Components\Ui\Icon`, which renders HTML without a Blade view per icon; `icon.blade.php` only serves templates compiled before the class existed. Legacy Bootstrap names (`bi-*`) are mapped through `Icon::ALIASES`, and unknown names fall back to `circle-help`. Never write `<x-lucide-*>` or `bi bi-*` directly; add an alias there instead. The output is locked byte-for-byte by `tests/Feature/UiIconComponentTest.php`; a new alias needs a fixture entry.
- `button`: `variant` is `primary|secondary|outline|ghost|danger`; `loading` shows the submit spinner.
- `status-chip`: `tone` is `neutral|info|success|warning|error` (`primary` → info, `danger` → error). Get the tone and label from `App\Support\StatusHelper` (e.g. `localInvoiceTone()`), not from the view.
- `date-picker` / `date-range-picker`: mandatory for all dates; never write a native `<input type="date">` on a page. Inside loops or modals, give each instance a unique `id` suffix. `allowedDaysOfWeek` restricts selectable days (Local physical delivery = Wednesday); the backend still validates.
- `file-upload`: renders its own errors from the `adasi:form-errors` event that `resources/js/async-form-submit.js` dispatches. Forms with uploads must use `data-async-submit` (see AGENTS.md, "Berkas tidak boleh hilang").
- `data-table`: table shell (title/toolbar/filters/pagination slots, loading/empty/error states), not DataTables itself. Pages that use the DataTables JS library must declare `@section('uses-datatables', true)`.
- All user-visible text uses `__('domain.key')`, present in both `lang/en` and `lang/id`.
- The explicit sources in `resources/css/app.css` include PHP-generated HTML and public JS. Keep full utility names literal; use `@source inline()` for genuinely dynamic utilities. Application classes such as `ui-data-action` remain ordinary CSS.
- Dark mode: use tokens (`--ui-card-bg`, `--ui-menu-bg`, `--ui-dialog-bg`); no `#fff`/`#000`.

After changes: `npm.cmd run build`, `php artisan view:cache`, and tests such as `RenderedComponentTest`, `CalendarComponentTest`, `FrontendAssetLoadingTest`, `node --test tests/js/calendar.test.mjs`. Visual rules: `ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/REDESIGN-PHASE2-GLOBAL-CONTRACT.md`.
