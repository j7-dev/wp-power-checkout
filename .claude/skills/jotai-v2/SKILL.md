---
name: jotai-v2
description: >
  Jotai v2 (^2.12.x) complete API reference for atomic React state management.
  Covers atom, useAtom, useAtomValue, useSetAtom, Provider, createStore, getDefaultStore,
  derived atoms (read-only, write-only, read-write), async atoms, atomWithStorage,
  atomWithDefault, selectAtom, splitAtom, focusAtom, atomFamily, loadable, unwrap,
  useHydrateAtoms, atomWithLazy, atomWithReset, RESET, useResetAtom, atomWithRefresh,
  TypeScript typing, performance patterns, and testing.
  Use this skill whenever code imports from 'jotai' or 'jotai/utils', or when
  working with Jotai atoms, stores, or providers in any React component.
---

# Jotai v2 API Reference

Atomic approach to global React state management. Core ~2kb, TypeScript oriented, React 18 compatible. State is globally accessible, derived state via dependency tracking, automatic re-render elimination.

Source: https://jotai.org/docs (v2)

**Extended references:**
- `references/patterns-and-testing.md` -- Common patterns, testing, SSR hydration

---

## 1. Core API

### 1.1 atom

```ts
import { atom } from 'jotai'
```

Creates an atom config (definition, not value). Configs are immutable; values live in stores.

**Signatures:**

```ts
// Primitive atom
function atom<Value>(initialValue: Value): PrimitiveAtom<Value>
// Read-only derived atom
function atom<Value>(read: (get: Getter) => Value): Atom<Value>
// Writable derived atom
function atom<Value, Args extends unknown[], Result>(
  read: (get: Getter) => Value,
  write: (get: Getter, set: Setter, ...args: Args) => Result,
): WritableAtom<Value, Args, Result>
// Write-only derived atom
function atom<Value, Args extends unknown[], Result>(
  read: Value,
  write: (get: Getter, set: Setter, ...args: Args) => Result,
): WritableAtom<Value, Args, Result>
```

**Primitive atoms:**
```ts
const countAtom = atom(0)
const msgAtom = atom('hello')
const objAtom = atom({ id: 12, name: 'item' })
const arrAtom = atom<string[]>([])
const nullableAtom = atom<number | null>(0)
```

**Derived atoms (three patterns):**
```ts
// Read-only: recomputes when deps change
const doubleAtom = atom((get) => get(countAtom) * 2)

// Write-only: convention is `null` for first arg
const writeOnlyAtom = atom(null, (get, set, discount: number) => {
  set(priceAtom, get(priceAtom) - discount)
})

// Read-write
const readWriteAtom = atom(
  (get) => get(priceAtom) * 2,
  (get, set, newPrice: number) => set(priceAtom, newPrice / 2),
)
```

**Key behaviors:**
- `get` in read: reactive, tracks dependencies
- `get` in write: reads value, NOT tracked
- `set` in write: invokes target atom's write. Accepts updater: `set(atom, (prev) => prev + 1)`

**In render -- use useMemo for stable reference:**
```ts
const valueAtom = useMemo(() => atom({ value }), [value])
```

**onMount property:**
```ts
const anAtom = atom(1)
anAtom.onMount = (setAtom) => {
  setAtom((c) => c + 1) // called on first subscribe (useAtom/useAtomValue)
  return () => { /* onUnmount */ }
}
// NOT called with useSetAtom (no subscription)
```

**Async read with abort signal:**
```ts
const userAtom = atom(async (get, { signal }) => {
  const res = await fetch(`/api/users/${get(userIdAtom)}`, { signal })
  return res.json()
})
```

### 1.2 useAtom
```ts
import { useAtom } from 'jotai'

function useAtom<Value, Update>(
  atom: WritableAtom<Value, Update>,
  options?: { store?: Store },
): [Value, SetAtom<Update>]

const [count, setCount] = useAtom(countAtom)
setCount(10)                  // direct value
setCount((prev) => prev + 1)  // updater function
```

Never create atoms inline -- causes infinite loop:
```ts
// BAD:  useAtom(atom(0))
// GOOD: const stableAtom = atom(0); useAtom(stableAtom)
```

### 1.3 useAtomValue
```ts
import { useAtomValue } from 'jotai'
const count = useAtomValue(countAtom) // read-only, no setter returned
```

### 1.4 useSetAtom
```ts
import { useSetAtom } from 'jotai'
const setCount = useSetAtom(countAtom) // write-only, no subscription, no re-render
```

### 1.5 Provider
```ts
import { Provider } from 'jotai'
// Signature: React.FC<{ store?: Store }>
```

Provides isolated state per subtree. Without Provider, uses default global store (provider-less mode). Use to: (1) isolate subtree state, (2) accept initial values, (3) clear all atoms via remount.

```tsx
const myStore = createStore()
<Provider store={myStore}><App /></Provider>
```

### 1.6 createStore / getDefaultStore / useStore
```ts
import { createStore, getDefaultStore, useStore } from 'jotai'

const store = createStore()
store.get(countAtom)                    // read
store.set(countAtom, 1)                 // write
const unsub = store.sub(countAtom, () => { /* on change */ })

const defaultStore = getDefaultStore()  // global provider-less store

// Inside component:
const store = useStore()                // get store from nearest Provider
```

---

## 2. Utilities (jotai/utils)

### 2.1 atomWithStorage
```ts
import { atomWithStorage, createJSONStorage, RESET } from 'jotai/utils'

const darkModeAtom = atomWithStorage('darkMode', false)
```

**Params:** `key` (string), `initialValue`, `storage?` (custom impl), `options?` `{ getOnInit?: boolean }`

Default: localStorage, JSON serialization, cross-tab sync. Set `getOnInit: true` to use stored value on init.

```ts
setText(RESET) // delete from storage, revert to initialValue
```

**Custom storage:**
```ts
const storage = createJSONStorage(() => sessionStorage, { reviver, replacer })
const myAtom = atomWithStorage('key', defaultValue, storage)
```

**Validation with Zod:**
```ts
import { unstable_withStorageValidator as withStorageValidator } from 'jotai/utils'
const isValid = (v: unknown) => schema.safeParse(v).success
const atom = atomWithStorage('key', 0, withStorageValidator(isValid)(createJSONStorage()))
```

### 2.2 atomWithDefault
```ts
import { atomWithDefault } from 'jotai/utils'
const count2 = atomWithDefault((get) => get(count1Atom) * 2)
// Resettable. Once set() overrides, getter no longer used. Reset with RESET/useResetAtom.
```

### 2.3 atomWithReset / RESET / useResetAtom
```ts
import { atomWithReset, useResetAtom, RESET } from 'jotai/utils'

const dollarsAtom = atomWithReset(0)
// set(dollarsAtom, RESET) or useResetAtom(dollarsAtom)() to reset
```

### 2.4 atomWithRefresh
```ts
import { atomWithRefresh } from 'jotai/utils'
const postsAtom = atomWithRefresh((get) =>
  fetch('/api/posts').then((r) => r.json()),
)
// const [posts, refresh] = useAtom(postsAtom); refresh() to re-fetch
```

### 2.5 atomWithLazy
```ts
import { atomWithLazy } from 'jotai/utils'
const expensiveAtom = atomWithLazy(computeExpensiveValue)
// initializer NOT called until first read in a store
```

### 2.6 selectAtom
```ts
import { selectAtom } from 'jotai/utils'

function selectAtom<Value, Slice>(
  anAtom: Atom<Value>,
  selector: (v: Value, prevSlice?: Slice) => Slice,
  equalityFn?: (a: Slice, b: Slice) => boolean, // default: Object.is
): Atom<Slice>
```

Read-only derived atom from a slice. Escape hatch -- prefer plain derived atoms.

```ts
const nameAtom = selectAtom(personAtom, (p) => p.name)
// With deep equality:
const birthAtom = selectAtom(personAtom, (p) => p.birth, deepEquals)
```

Selector MUST be stable (define outside component or use useCallback).

### 2.7 splitAtom
```ts
import { splitAtom } from 'jotai/utils'

type SplitAtom = <Item, Key>(
  arrayAtom: PrimitiveAtom<Array<Item>>,
  keyExtractor?: (item: Item) => Key,
): Atom<Array<PrimitiveAtom<Item>>>
```

Splits array atom into per-item atoms. Write provides dispatch: `{ type: 'remove', atom }`, `{ type: 'insert', value, before? }`, `{ type: 'move', atom, before }`.

```ts
const todosAtom = atom([{ task: 'A', done: false }])
const todoAtomsAtom = splitAtom(todosAtom)

const [todoAtoms, dispatch] = useAtom(todoAtomsAtom)
dispatch({ type: 'remove', atom: todoAtoms[0] })
```

### 2.8 focusAtom (jotai-optics)
```ts
// npm install optics-ts jotai-optics
import { focusAtom } from 'jotai-optics'

const objectAtom = atom({ a: 5, b: 10 })
const aAtom = focusAtom(objectAtom, (optic) => optic.prop('a'))
// Read-WRITE: setting aAtom updates objectAtom.a
```

### 2.9 atomFamily (deprecated, migrate to jotai-family)
```ts
// import { atomFamily } from 'jotai/utils'  -- will be removed in v3
// Migration: npm install jotai-family; import { atomFamily } from 'jotai-family'

const todoFamily = atomFamily((id: number) => atom({ id, text: '', done: false }))
todoFamily(1) // creates or returns cached atom

// API: .getParams(), .remove(param), .setShouldRemove(fn)
// Memory leak warning: cached indefinitely unless removed
```

### 2.10 loadable
```ts
import { loadable } from 'jotai/utils'

const loadableAtom = loadable(asyncAtom)
// Returns: { state: 'loading' } | { state: 'hasData', data } | { state: 'hasError', error }
// No <Suspense> needed
```

### 2.11 unwrap
```ts
import { unwrap } from 'jotai/utils'

const syncAtom = unwrap(asyncAtom)                    // undefined while pending
const syncAtom = unwrap(asyncAtom, (prev) => prev ?? 0) // custom fallback
// Unlike loadable: errors are thrown, not caught
```

### 2.12 useHydrateAtoms
```ts
import { useHydrateAtoms } from 'jotai/utils'

function useHydrateAtoms(
  values: Iterable<readonly [Atom<unknown>, unknown]>,
  options?: { store?: Store; dangerouslyForceHydrate?: boolean },
): void

// SSR hydration. Atoms hydrate only once per store.
useHydrateAtoms([[countAtom, 42], [nameAtom, 'Jotai']])
```

---

## 3. Async Atoms

Async read functions suspend by default. Wrap in `<Suspense>`. Use `signal` for abort on dependency change. Use `loadable()` or `unwrap()` to avoid Suspense.

```tsx
<Suspense fallback="Loading...">
  <UserName /> {/* uses async atom */}
</Suspense>
```

---

## 4. TypeScript

Requires TS 3.8+, `strictNullChecks: true`.

```ts
// Inference (preferred)
const numAtom = atom(0)       // PrimitiveAtom<number>
const derived = atom((get) => get(numAtom) * 2) // Atom<number>

// Explicit: atom<Value, Args[], Result>
const writeOnly = atom<null, [string], void>(null, (_g, set, v) => set(strAtom, v))
const readWrite = atom<number, [number], void>(
  (get) => get(numAtom),
  (_g, set, v) => set(numAtom, v),
)

// Utility types
import { ExtractAtomValue, type PrimitiveAtom } from 'jotai'
type User = ExtractAtomValue<typeof userAtom>
```

---

## 5. Performance

1. **Granular atoms**: split state into small atoms; each triggers re-render only in subscribers
2. **useSetAtom**: write-only, no subscription = no re-render
3. **selectAtom**: subscribe to slice of large object
4. **splitAtom**: per-item atoms in lists avoid full list re-render
5. **Heavy computation outside render**: do in write atoms, not derived read
6. **React 18**: components are idempotent, extra renders normal; keep renders cheap

---

## 6. Import Map

| Import | Source |
|--------|--------|
| `atom, useAtom, useAtomValue, useSetAtom` | `'jotai'` |
| `Provider, useStore, createStore, getDefaultStore` | `'jotai'` |
| `atomWithStorage, createJSONStorage, RESET` | `'jotai/utils'` |
| `atomWithDefault, atomWithReset, useResetAtom` | `'jotai/utils'` |
| `atomWithRefresh, atomWithLazy` | `'jotai/utils'` |
| `selectAtom, splitAtom, loadable, unwrap` | `'jotai/utils'` |
| `useHydrateAtoms` | `'jotai/utils'` |
| `atomFamily` | `'jotai/utils'` (deprecated) / `'jotai-family'` |
| `focusAtom` | `'jotai-optics'` (+ `optics-ts`) |
| `ExtractAtomValue, PrimitiveAtom` | `'jotai'` (types) |

---

## 7. Deprecations

| API | Status | Migration |
|-----|--------|-----------|
| `atomFamily` in `jotai/utils` | Deprecated, removed in v3 | `npm install jotai-family` then import from `'jotai-family'` |
| `selectAtom` | Escape hatch | Prefer plain derived atoms |
