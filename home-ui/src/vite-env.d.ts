/// <reference types="vite/client" />

declare module '*.html?raw' {
  const src: string
  export default src
}
