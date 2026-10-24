// Types for the generated captioner (engine.gen.js).
export type CaptionEngine = {
  start: () => Promise<void>; stop: () => void; setLanguage: () => void; applyFont: () => void; repaint: () => void
  saved: (s: { target_language: string; profanity_filter: number; action_tags_enabled: number }) => void
  setCorrections: (rows: { enabled: number }[]) => void; isRunning: () => boolean; destroy: () => void
}
export function createCaptionEngine(cfg: unknown, ui: unknown): CaptionEngine | null
