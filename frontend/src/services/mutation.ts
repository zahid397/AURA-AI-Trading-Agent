import { API_MODE, simulateDelay } from './apiMode'

/**
 * Wraps a state-changing call (login, approve/reject a proposal, kill
 * switch, PATCH settings, ...). Unlike `withFixtureFallback`, this never
 * silently swaps in fixture data on a *live* failure -- a failed mutation
 * must surface as an error so the user knows the action didn't happen. In
 * fixture mode it simulates the mutation against the fixture object graph
 * after a delay so demo flows (approve/reject/execute/kill-switch) feel real.
 */
export async function runMutation<T>(liveCall: () => Promise<T>, fixtureCall: () => T | Promise<T>): Promise<T> {
  if (API_MODE === 'fixture') {
    await simulateDelay()
    return fixtureCall()
  }
  return liveCall()
}
