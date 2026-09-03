import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { Agent, AgentType } from '@/types/api'
import AgentsPage from './AgentsPage.vue'

// Only the agents service is mocked -- risk/settings services are left to resolve against
// the real fixture data in the background (their own async loadAll() calls in onMounted),
// which is harmless for these assertions and keeps the test close to real app wiring.
vi.mock('@/services/agents', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/agents')>()
  return { ...actual, fetchAgents: vi.fn() }
})

const { fetchAgents } = await import('@/services/agents')

function sampleAgent(type: AgentType): Agent {
  return {
    id: `agent-${type}`,
    type,
    name: 'Market Analyst',
    enabled: true,
    status: 'idle',
    config_version: 1,
    current_task: 'Scanning.',
    last_heartbeat_at: new Date().toISOString(),
    decisions_today: 3,
    avg_latency_ms: 1200,
  }
}

describe('AgentsPage loading/empty/error states', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders skeleton placeholders while the initial fetch is in flight', async () => {
    let resolveFetch: (v: Awaited<ReturnType<typeof fetchAgents>>) => void = () => {}
    vi.mocked(fetchAgents).mockReturnValue(
      new Promise((resolve) => {
        resolveFetch = resolve
      }),
    )

    const wrapper = mount(AgentsPage)
    await nextTick()

    // Skeleton.vue renders `role="presentation"` placeholder blocks matching the final
    // 5-card grid geometry. 'Market Analyst' etc. are static pipeline-diagram labels that
    // render regardless of fetch state, so assert on agent-card-only content instead
    // (the per-card "decisions today" copy only renders once real agent data has loaded).
    expect(wrapper.findAll('[role="presentation"]').length).toBeGreaterThan(0)
    expect(wrapper.text()).not.toContain('No agents configured')
    expect(wrapper.text()).not.toContain('decisions today')

    resolveFetch({ data: [], source: 'fixture' })
    await flushPromises()
  })

  it('renders an empty state with no misleading skeleton or error copy when zero agents come back', async () => {
    vi.mocked(fetchAgents).mockResolvedValue({ data: [], source: 'fixture' })

    const wrapper = mount(AgentsPage)
    await flushPromises()

    expect(wrapper.text()).toContain('No agents configured')
    expect(wrapper.text()).not.toContain('Retry')
  })

  it('renders inline retry with the error message when the fetch fails, and recovers on retry', async () => {
    vi.mocked(fetchAgents).mockRejectedValueOnce(new Error('API unreachable'))

    const wrapper = mount(AgentsPage)
    await flushPromises()

    expect(wrapper.text()).toContain('API unreachable')
    const retryButton = wrapper.findAll('button').find((b) => b.text().includes('Retry'))
    expect(retryButton).toBeTruthy()

    // Clicking retry re-runs loadAgents(); simulate the API recovering.
    vi.mocked(fetchAgents).mockResolvedValueOnce({ data: [sampleAgent('market_analyst')], source: 'fixture' })
    await retryButton!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('API unreachable')
    expect(wrapper.text()).toContain('Scanning.')
  })

  it('renders agent cards once data loads successfully', async () => {
    vi.mocked(fetchAgents).mockResolvedValue({ data: [sampleAgent('market_analyst')], source: 'fixture' })

    const wrapper = mount(AgentsPage)
    await flushPromises()

    expect(wrapper.text()).toContain('Scanning.')
    expect(wrapper.text()).toContain('Avg latency: 1,200ms')
    expect(wrapper.text()).not.toContain('No agents configured')
  })
})
