/// <reference types="vite/client" />

/*
 * `.vue` files have no types of their own as far as `tsc` is concerned, so
 * without this every `import Foo from './Foo.vue'` in a `.ts` file is an error.
 * vue-tsc understands the SFCs themselves; this covers the imports.
 */
declare module '*.vue' {
  import type { DefineComponent } from 'vue'

  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
  export default component
}

/**
 * Reverb connection details, shared from `HandleInertiaRequests` and read by
 * `useEcho`. Absent (null) when no Reverb key is configured, which is the
 * normal state in local development — see `config/reverb.php`.
 */
export type ReverbConfig = {
  key: string
  wsHost: string
  wsPort: number
  wssPort: number
  forceTLS: boolean
}
