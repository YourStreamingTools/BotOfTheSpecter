/// <reference types="vite/client" />

interface Window {
  Swal?: {
    fire: (opts: Record<string, unknown>) => Promise<{
      isConfirmed: boolean
      isDenied: boolean
      value?: { name: string; idea: string; detail: string }
    }>
    showValidationMessage: (msg: string) => void
  }
}
