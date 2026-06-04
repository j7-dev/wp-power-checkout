# Jotai v2 -- Patterns, Testing & SSR

## Table of Contents
1. [Common Patterns](#1-common-patterns)
2. [Testing Patterns](#2-testing-patterns)
3. [SSR / Hydration](#3-ssr--hydration)
4. [Large Object Patterns](#4-large-object-patterns)
5. [atomFamily Details](#5-atomfamily-details)

---

## 1. Common Patterns

### 1.1 Derived Atom from Multiple Sources

```ts
import { atom } from 'jotai'

const firstNameAtom = atom('')
const lastNameAtom = atom('')
const fullNameAtom = atom((get) => `${get(firstNameAtom)} ${get(lastNameAtom)}`)
```

### 1.2 Action Atom (Write-Only with Side Effects)

```ts
const submitFormAtom = atom(null, async (get, set) => {
  const formData = get(formDataAtom)
  set(loadingAtom, true)
  try {
    await api.submit(formData)
    set(loadingAtom, false)
    set(successAtom, true)
  } catch (err) {
    set(loadingAtom, false)
    set(errorAtom, err)
  }
})

// Usage: const submit = useSetAtom(submitFormAtom); submit()
```

### 1.3 Atom with Reducer

```ts
import { atomWithReducer } from 'jotai/utils'

type Action = { type: 'INCREMENT' } | { type: 'DECREMENT' } | { type: 'RESET' }

const counterAtom = atomWithReducer(0, (state: number, action: Action) => {
  switch (action.type) {
    case 'INCREMENT': return state + 1
    case 'DECREMENT': return state - 1
    case 'RESET': return 0
  }
})
```

### 1.4 Store Usage Outside React

```ts
import { createStore, atom } from 'jotai'

const store = createStore()
const configAtom = atom({})

// Set initial values
store.set(configAtom, loadConfig())

// Subscribe to changes
store.sub(authAtom, () => {
  const auth = store.get(authAtom)
  if (!auth) redirectToLogin()
})

// Provide to React
<Provider store={store}><App /></Provider>
```

### 1.5 Conditional Derived Atom

```ts
const themeAtom = atom((get) => {
  const mode = get(modeAtom)
  if (mode === 'dark') return get(darkThemeAtom)
  return get(lightThemeAtom)
})
// Only subscribes to the actually-used branch
```

### 1.6 Async Atom with Suspense

```ts
import { Suspense } from 'react'
import { atom, useAtom } from 'jotai'

const userIdAtom = atom(1)
const userAtom = atom(async (get, { signal }) => {
  const userId = get(userIdAtom)
  const response = await fetch(
    `https://jsonplaceholder.typicode.com/users/${userId}`,
    { signal },
  )
  return response.json()
})

const UserName = () => {
  const [user] = useAtom(userAtom)
  return <div>User name: {user.name}</div>
}

const App = () => (
  <Suspense fallback="Loading...">
    <UserName />
  </Suspense>
)
```

### 1.7 Loadable Pattern (No Suspense)

```ts
import { loadable } from 'jotai/utils'

const asyncDataAtom = atom(async () => fetchData())
const loadableDataAtom = loadable(asyncDataAtom)

const Component = () => {
  const [value] = useAtom(loadableDataAtom)

  if (value.state === 'loading') return <Spinner />
  if (value.state === 'hasError') return <Error error={value.error} />
  return <Data data={value.data} />
}
```

### 1.8 Unwrap Pattern (Sync with Fallback)

```ts
import { unwrap } from 'jotai/utils'

const asyncCountAtom = atom(async (get) => {
  await delay(500)
  return get(countAtom)
})

// undefined while pending
const syncAtom = unwrap(asyncCountAtom)

// Custom fallback: 0 initially, then previous value on re-fetch
const syncAtom = unwrap(asyncCountAtom, (prev) => prev ?? 0)
```

---

## 2. Testing Patterns

### 2.1 Basic Component Test

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Counter } from './Counter'

test('should increment counter', async () => {
  render(<Counter />)

  const counter = screen.getByText('0')
  const button = screen.getByText('one up')

  await userEvent.click(button)

  expect(counter.textContent).toEqual('1')
})
```

### 2.2 Injecting Initial Values (Provider + useHydrateAtoms)

```tsx
import { Provider } from 'jotai'
import { useHydrateAtoms } from 'jotai/utils'
import type { Atom } from 'jotai'

const HydrateAtoms = ({
  initialValues,
  children,
}: {
  initialValues: Iterable<readonly [Atom<unknown>, unknown]>
  children: React.ReactNode
}) => {
  useHydrateAtoms(initialValues)
  return children
}

const TestProvider = ({
  initialValues,
  children,
}: {
  initialValues: Iterable<readonly [Atom<unknown>, unknown]>
  children: React.ReactNode
}) => (
  <Provider>
    <HydrateAtoms initialValues={initialValues}>{children}</HydrateAtoms>
  </Provider>
)

test('should not increment on max (100)', async () => {
  render(
    <TestProvider initialValues={[[countAtom, 100]]}>
      <Counter />
    </TestProvider>
  )

  const counter = screen.getByText('100')
  const button = screen.getByText('one up')
  await userEvent.click(button)
  expect(counter.textContent).toEqual('100')
})
```

### 2.3 Testing Custom Hooks

```tsx
import { renderHook, act } from '@testing-library/react-hooks'
import { useAtom } from 'jotai'

test('should increment with reducer', () => {
  const { result } = renderHook(() => useAtom(countAtom))

  act(() => {
    result.current[1]('INCREASE')
  })

  expect(result.current[0]).toBe(1)
})
```

### 2.4 Testing with Store Directly

```ts
import { createStore, atom } from 'jotai'

test('store-based test', () => {
  const store = createStore()
  const countAtom = atom(0)

  expect(store.get(countAtom)).toBe(0)

  store.set(countAtom, 5)
  expect(store.get(countAtom)).toBe(5)

  store.set(countAtom, (prev) => prev + 1)
  expect(store.get(countAtom)).toBe(6)
})
```

---

## 3. SSR / Hydration

### 3.1 useHydrateAtoms

```ts
import { useHydrateAtoms } from 'jotai/utils'

function useHydrateAtoms(
  values: Iterable<readonly [Atom<unknown>, unknown]>,
  options?: { store?: Store; dangerouslyForceHydrate?: boolean },
): void
```

- Atoms hydrate only ONCE per store
- Client-side only (use with `'use client'` directive)
- For TypeScript ES5 target, add `as const` to array

```ts
useHydrateAtoms([
  [countAtom, 42],
  [frameworkAtom, 'Next.js'],
] as const)
```

### 3.2 Next.js App Router Setup

```tsx
// components/providers.tsx
'use client'
import { Provider } from 'jotai'

export const Providers = ({ children }: { children: React.ReactNode }) => (
  <Provider>{children}</Provider>
)

// app/layout.tsx
import { Providers } from '../components/providers'

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  )
}
```

### 3.3 Next.js Pages Directory Setup

```tsx
// pages/_app.tsx
import { Provider } from 'jotai'

export default function App({ Component, pageProps }: AppProps) {
  return (
    <Provider>
      <Component {...pageProps} />
    </Provider>
  )
}
```

### 3.4 atomWithStorage SSR Caveat

Server renders with `initialValue` (no localStorage). Mismatch on hydration if stored value differs. Solutions:
1. Wrap dependent UI in `<ClientOnly>` component
2. Set `getOnInit: true` (causes brief flicker)

---

## 4. Large Object Patterns

### 4.1 focusAtom for Object Properties

```ts
import { atom } from 'jotai'
import { focusAtom } from 'jotai-optics'

const dataAtom = atom({ people: [...], films: [...], info: { tags: [...] } })

// Only re-renders when people changes, not when films changes
const peopleAtom = focusAtom(dataAtom, (optic) => optic.prop('people'))
```

### 4.2 splitAtom for Array Items

```ts
import { splitAtom } from 'jotai/utils'

const peopleAtomsAtom = splitAtom(peopleAtom)

const People = () => {
  const [peopleAtoms] = useAtom(peopleAtomsAtom)
  return (
    <div>
      {peopleAtoms.map((personAtom) => (
        <Person personAtom={personAtom} key={`${personAtom}`} />
      ))}
    </div>
  )
}
```

### 4.3 splitAtom with Dispatch

```tsx
const TodoList = () => {
  const [todoAtoms, dispatch] = useAtom(todoAtomsAtom)
  return (
    <ul>
      {todoAtoms.map((todoAtom) => (
        <TodoItem
          key={`${todoAtom}`}
          todoAtom={todoAtom}
          remove={() => dispatch({ type: 'remove', atom: todoAtom })}
        />
      ))}
    </ul>
  )
}
```

### 4.4 selectAtom for Read-Only Slices

```ts
import { selectAtom } from 'jotai/utils'

const infoAtom = atom((get) => get(dataAtom).info)
const tagsAtom = selectAtom(infoAtom, (info) => info.tags)
```

---

## 5. atomFamily Details

### 5.1 Signature

```ts
atomFamily<Param, AtomType extends Atom<unknown>>(
  initializeAtom: (param: Param) => AtomType,
  areEqual?: (a: Param, b: Param) => boolean,
): AtomFamily<Param, AtomType>
```

### 5.2 API Methods

```ts
const todoFamily = atomFamily((name: string) => atom(name))

todoFamily('foo')       // create or get cached
todoFamily('bar')

for (const param of todoFamily.getParams()) {
  console.log(param)   // 'foo', 'bar'
}

todoFamily.remove('foo')  // remove from cache

// Auto-cleanup: remove atoms older than 1 hour
todoFamily.setShouldRemove((createdAt, param) => {
  return Date.now() - createdAt > 60 * 60 * 1000
})

// Listen for create/remove events (unstable API)
const cleanup = todoFamily.unstable_listen((event) => {
  console.log(event.type, event.param, event.atom)
})
```

### 5.3 TypeScript

```ts
import type { PrimitiveAtom } from 'jotai'

// Type inferred from factory
const myFamily = atomFamily((id: number) => atom(id))

// Explicit types
const myFamily = atomFamily<number, PrimitiveAtom<number>>((id) => atom(id))
```

### 5.4 Deep Equality for Object Params

```ts
import deepEqual from 'fast-deep-equal'

const family = atomFamily(
  ({ id, name }: { id: number; name: string }) => atom({ name }),
  deepEqual,
)
```

### 5.5 Migration to jotai-family

```bash
npm install jotai-family
```

```ts
// Before
import { atomFamily } from 'jotai/utils'

// After (same API, additional features like atomTree)
import { atomFamily } from 'jotai-family'
```
