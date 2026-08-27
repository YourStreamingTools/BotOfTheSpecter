/// <reference types="vite/client" />

interface Window {
  marked?: { parse: (src: string) => string }
  DOMPurify?: { sanitize: (html: string) => string }
  hljs?: { highlightElement: (el: Element) => void }
}
