import { afterEach, vi } from 'vitest'

// jsdom doesn't implement matchMedia -- provide a stub so components using
// `prefers-reduced-motion` / `prefers-color-scheme` checks don't crash.
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia
}

// ECharts renders to <canvas>; jsdom's canvas has no real 2D context.
// vue-echarts guards for a missing context internally in most versions, but
// stub getContext defensively so chart-bearing components don't throw during
// mount in unit tests.
if (!HTMLCanvasElement.prototype.getContext) {
  HTMLCanvasElement.prototype.getContext = (() => null) as typeof HTMLCanvasElement.prototype.getContext
}

if (typeof ResizeObserver === 'undefined') {
  class ResizeObserverStub {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }
  window.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
}

afterEach(() => {
  vi.restoreAllMocks()
})
