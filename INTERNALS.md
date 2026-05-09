# laravel-snip — internals tutorial

Owner-facing walkthrough. Read this when you come back to the package after a few months and need to remember how it actually works.

## 1. What the package is, in one sentence

`laravel-snip` is a `dd()`-style debugging tool that captures values, timings, and milestones during one HTTP request, then injects a small Vue 3 web component into the response HTML so authorised users (per a Horizon-style gate) can browse what was captured — without halting the response and without leaking data to regular visitors.

Three capture kinds, three tabs in the panel:

| Capture | API | Stored as | Use it for |
| --- | --- | --- | --- |
| **Snip** | `Snip::add($value, $label)` / `snip($value, $label)` | typed value tree (`SnipDumper` output) + size in bytes | "what does this variable look like?" |
| **Timing** | `Snip::start($label)` + `Snip::timing($label)` / `snip_time($label)` | `start_ms` + `duration_ms` | "how long did this block take?" |
| **Milestone** | `Snip::milestone($label)` / `snip_here($label)` | `time_ms` | "did execution reach this line?" |

## 2. High-level lifecycle

```
                  HTTP request hits Laravel
                            │
                  Service container resolves
                  SnipManager (singleton, request-scoped)
                            │
       Application code calls Snip::add / start / timing / milestone
                            │
              SnipManager appends to its in-memory arrays
                            │
                  Controller returns response
                            │
              Global middleware InjectSnip runs
                            │
        ┌───────────────────┴───────────────────┐
        │ shouldInject() = all of:              │
        │  - config('snip.enabled')             │
        │  - response is text/html              │
        │  - body contains </body>              │
        │  - at least one capture exists        │
        │  - Gate::allows('viewSnip')           │
        └───────────────────┬───────────────────┘
                            │ yes
                            ▼
   inject() splices a <laravel-snip data-payload="…">
   tag + <script src="/vendor/snip/snip.js?v=<mtime>">
   right before </body>.
                            │
              Browser loads the static bundle from
              public/vendor/snip/snip.js (no PHP route)
                            │
              Vue 3 web component <laravel-snip> mounts in
              its own Shadow DOM, parses data-payload JSON,
              renders the floating panel.
```

Nothing is persisted: every request begins with a brand-new `SnipManager`. No DB, no cache, no logs.

## 3. Repo layout

```
packages/rudolfbruder/laravel-snip/
├── composer.json                       # autoloads helpers, PSR-4, Laravel discovery
├── config/snip.php                     # publishable config
├── src/
│   ├── SnipServiceProvider.php         # boot wiring (gate, middleware, publishes)
│   ├── SnipApplicationServiceProvider  # Horizon-style base for consumers
│   ├── SnipManager.php                 # in-memory capture store
│   ├── helpers.php                     # snip() / snip_time() / snip_here()
│   ├── Facades/Snip.php                # facade → SnipManager
│   ├── Http/Middleware/InjectSnip.php  # HTML injection
│   └── Support/SnipDumper.php          # safe value serialiser
├── stubs/SnipServiceProvider.stub      # gets copied into consumer app
├── resources/js/                       # Vue source (built by Vite)
│   ├── main.ts                         # registers <laravel-snip> custom element
│   ├── SnipPanel.ce.vue                # the panel root (Shadow DOM)
│   ├── ValueTree.vue                   # recursive tree renderer for snip values
│   ├── TimingList.vue                  # timings tab body
│   ├── MilestoneList.vue               # milestones tab body
│   ├── types.ts                        # shared TS types mirroring PHP payload
│   └── utils.ts                        # shortenFile, durationColor, formatBytes
├── dist/snip.js                        # the committed pre-built bundle
├── vite.config.ts                      # builds dist/snip.js as IIFE w/ Vue baked in
├── package.json                        # dev-only: vue, vite, vue-tsc, typescript
├── tests/                              # Pest + Testbench
└── INTERNALS.md                        # this file
```

## 4. Backend, file by file

### `SnipServiceProvider`

Three responsibilities:

1. **Bind singletons**: `SnipDumper` and `SnipManager` are container-resolved per request. Because they're singletons, every facade / helper / direct `app(...)` call hits the same instance throughout one request lifecycle.
2. **Register the default gate** in `boot()`, but only if no consumer has already defined one (`Gate::has('viewSnip')`). The default allows access in `app()->environment('local')` so the panel works out of the box during development.
3. **Push `InjectSnip` onto the global middleware stack** via `Kernel::pushMiddleware`. This is the simplest way to see every HTTP response — no per-route registration needed.

It also publishes three vendor:publish tags:

- `snip-config` → `config/snip.php`.
- `snip-provider` → copies the stub into `app/Providers/SnipServiceProvider.php`.
- `snip-assets` → copies `dist/snip.js` into the consumer's `public/vendor/snip/snip.js`.

### `SnipApplicationServiceProvider`

Tiny abstract base. Mirrors `Laravel\Horizon\HorizonApplicationServiceProvider`. Consumers extend it and override `gate()`. Their `boot()` calls `parent::boot()` which dispatches to `gate()`. This keeps the consumer-side provider very small.

### `SnipManager`

Plain PHP class. Holds three arrays plus a hash for `Snip::start` marks:

```php
protected array $entries     = []; // snips
protected array $timings     = []; // timings
protected array $milestones  = []; // milestones
protected array $startMarks  = []; // label → microtime(true)
protected float $startedAt;        // LARAVEL_START
```

`add()` / `timing()` / `milestone()` all do the same dance: short-circuit if disabled, enforce a per-request cap, record caller `file:line` via `debug_backtrace`, append a typed array. `start()` stores a microtime mark used later by `timing()` to shift the origin for that label — without a mark, timings measure from `LARAVEL_START`.

`resolveCaller()` reads up the backtrace, skipping any frame inside the package itself or `helpers.php`, so the recorded location is always the user's code.

### `SnipDumper`

Walks an arbitrary PHP value into a JSON-safe typed tree. Hard rules:

- Limits applied from config: `max_depth`, `max_array_items`, `max_string_length`. Beyond them, the node becomes `{type: 'truncated', preview: '…'}`.
- Common credential keys are redacted via `redact_keys`.
- Circular references are short-circuited via `spl_object_id` set tracking.
- `match (true)` dispatches based on runtime type. **Order matters**: `Model` and Eloquent/Support `Collection` arms come *before* `is_object` because they extend `object` and would otherwise be swallowed by the generic reflection path.
- Eloquent models render attributes via `attributesToArray()` plus already-loaded relations via `$model->getRelations()` — never triggering lazy loads.
- `approximateSize()` is a separate helper that returns `strlen(serialize($value))` with a try/catch, used by `SnipManager` to attach a per-entry `bytes` field for the sidebar.

### `Http/Middleware/InjectSnip`

Two phases:

1. `shouldInject()` — cheap checks first (enabled flag, capture count, response type, content-type, `</body>` presence) and the gate check **last** (because the gate may hit auth/db). Any failure short-circuits and returns the original response untouched.
2. `inject()` — encodes the three capture arrays into a single JSON payload with the shape `{ "snips": [...], "timings": [...], "milestones": [...] }`, escapes both the payload and the asset URL through `e()`, and `substr_replace`s the `<laravel-snip data-payload="…">` + `<script src=…>` pair before the last `</body>`.

The asset URL appends `?v=<mtime>` resolved from the published `public/vendor/snip/snip.js` file, so that a fresh `vendor:publish --tag=snip-assets --force` automatically busts the browser cache the next time the page renders.

The middleware uses a single configurable guard (`config('snip.guard')`, env `SNIP_GUARD`) — defaults to the framework default. No multi-guard iteration; simpler is better.

### `helpers.php`

Three thin globals (`snip`, `snip_time`, `snip_here`) all delegate to the singleton `SnipManager`. They exist for ergonomic dev usage — projects that prefer the facade can ignore them.

### `Facades/Snip`

Standard Laravel facade resolving to `SnipManager`. The class itself is just `@method` annotations — the work happens via `Facade::__call`.

## 5. Frontend, file by file

The frontend builds with Vite into a **single IIFE bundle** (`dist/snip.js`) with the Vue runtime bundled in. That makes the consumer install zero-dep on the JS side: a simple `<script src=…>` tag works on any Laravel app, including Blade-only / Livewire / Inertia apps, with no `npm` step on the consumer side.

### `main.ts`

```ts
import { defineCustomElement } from 'vue';
import SnipPanel from './SnipPanel.ce.vue';

const SnipElement = defineCustomElement(SnipPanel);
window.customElements.define('laravel-snip', SnipElement);
```

`SnipPanel.ce.vue` (the `.ce.vue` extension matters — Vue auto-injects scoped styles into the Shadow DOM for that file) becomes a Web Component. Children of the panel are plain `.vue` files; Vue mounts them inside the same Shadow Root, which is why CSS isolation just works without each child being a custom element.

### `SnipPanel.ce.vue`

Reads `host.getAttribute('data-payload')`, JSON-parses it (with a v1 fallback for old bundles still in caches that received only the legacy snip array), splits into the three reactive refs, picks the most relevant default tab, and renders the toggle button + panel UI.

Two niceties:

- Cmd+K / Ctrl+K toggle is bound on `document` via a `keydown` listener registered in `onMounted` and removed in `onBeforeUnmount`. `preventDefault` + `stopPropagation` so the host page's own Cmd+K (or the browser address bar) doesn't fight us.
- The bottom-right pill auto-hides when there is no content — `v-if="hasContent"` guards the entire root.

### `ValueTree.vue`

Recursive component. Plain `.vue`, not `.ce.vue`, because Vue's auto-inferred name from `*.ce.vue` files breaks self-recursion (the dot becomes an invalid tag name). Each node renders its preview, optional caret, and on expand recurses into children. Type-color mapping is local; depth-based default-open keeps the top level expanded but deeper nodes collapsed.

### `TimingList.vue`

Flat list of timings, sorted by duration desc by default with a toggle to chronological. Each row has a horizontal bar scaled against the slowest entry. Color thresholds: green < 50 ms, amber < 200 ms, red ≥ 200 ms.

### `MilestoneList.vue`

Strictly chronological list. Each row prepends `#N`, then label, then `time_ms`, with a `Δ +X.X ms` chip showing the delta from the previous row — handy for spotting expensive blocks between two arbitrary milestones.

### `utils.ts`

`shortenFile()` strips long absolute paths down to the last three segments. `formatBytes()` does B / KB / MB / GB rounding. `durationColor()` is the threshold mapper used by `TimingList`.

## 6. Build pipeline (the JS side)

```
resources/js/*.{ts,vue}
        │
   npm run build  (= vite build, lib mode, IIFE)
        │
   dist/snip.js (~120 KB raw, ~44 KB gzip)
        │
   php artisan vendor:publish --tag=snip-assets --force
        │
   public/vendor/snip/snip.js  (in the consumer app)
        │
   Web server (nginx/Herd) serves it as a static file
   with whatever Cache-Control your app configures.
   The middleware appends ?v=<mtime> so cache-busting
   is automatic after every republish.
```

For day-to-day frontend hacking:

```
cd packages/rudolfbruder/laravel-snip
npm install         # once
npm run dev         # watches resources/js, rebuilds dist/snip.js on save
```

Then in the consumer app, repeat `php artisan vendor:publish --tag=snip-assets --force` whenever you want the freshly-built bundle copied over. (You can shortcut this by symlinking `public/vendor/snip` to `packages/rudolfbruder/laravel-snip/dist` during development.)

## 7. Backend dev workflow

PHP changes pick up live — no build step. Standard cycle:

```
# in the package
edit src/...
edit config/snip.php

# in the consumer (eshop)
composer dump-autoload   # only after adding new classes/files
php artisan optimize:clear
```

When working on the published provider stub, remember the consumer has its own copy in `app/Providers/SnipServiceProvider.php`. Editing the stub does not retroactively update existing consumer copies; they have to either edit theirs by hand or republish with `--force` (which loses their gate customisations).

## 8. Testing

Run from inside the package directory:

```
composer install
vendor/bin/pest --compact
```

The suite uses `orchestra/testbench`. Test covers:

- `SnipManager` happy paths and edge cases (limits, redaction, circular refs, no-op when disabled, memory measurement on/off, closure handling, clear/reset).
- `InjectSnip` middleware: gate denied / disabled / wrong content-type / no-body / payload shape / injection when only timings or only milestones are present.

## 9. Common hacking tasks — where to go

| Task | File(s) |
| --- | --- |
| Change default redacted keys | `config/snip.php` |
| Add a new capture kind (e.g. queries) | `SnipManager` (new array + methods) → middleware payload key → new types.ts type → new `*List.vue` → register in `SnipPanel.ce.vue` tab strip |
| Lift the per-request limits | `config/snip.php` `limits.*` keys |
| Render relations differently for Eloquent | `SnipDumper::dumpModel` |
| Skip the panel for guest users in production | Override `gate()` in the consumer's `App\Providers\SnipServiceProvider` |
| Move the bundle URL | `config/snip.php` `asset_path` + `vendor:publish --tag=snip-assets` mapping in the package's service provider |
| Change tab styling / colours | `resources/js/SnipPanel.ce.vue` `<style scoped>` block |
| Tweak per-row timing colours | `resources/js/utils.ts::durationColor` |

## 10. Trapdoors / things to remember when you come back

- **Eshop has it under `packages/rudolfbruder/laravel-snip` plus a path-repository entry in eshop's `composer.json`.** Eshop's `.gitignore` excludes the package folder; the package has its own git repo for separate distribution later.
- **The `snip-provider` publish tag must be re-run only on first install per consumer.** Consumers have their own gate definition in `app/Providers/SnipServiceProvider.php` — never use `--force` here unless you are okay clobbering their customisation.
- **The Vue toggle button uses Shadow DOM** — host-page CSS cannot leak in (good) and the panel cannot inherit host fonts (also good, but means we set `:host` defaults explicitly).
- **`?v=<mtime>` is the cache-bust mechanism**, not Vite manifest hashes. Telescope/Horizon/Debugbar all do roughly the same thing — there is no Laravel-built-in for single-file static packages.
- **`Snip::milestone` does not dedupe**. Calling it inside a loop floods the panel; cap is `limits.max_milestones_per_request` (1000 default). If you want dedupe, write `if (! $done) { Snip::milestone(...); $done = true; }` yourself.
- **`Snip::add` performance for fat models** is dominated by `serialize()` (memory size approximation). Set `SNIP_SHOW_MEMORY=false` if profiling shows snip overhead matters.
- **Production safety relies entirely on the gate.** If you accidentally `Gate::define('viewSnip', fn () => true)`, every visitor sees every snip. Test the gate after deploying.
