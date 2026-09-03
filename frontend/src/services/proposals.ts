import type { Order, ProposalStatus, Trade, TradeProposal } from '@/types/api'
import { isoDaysFromAnchor } from '@/dev/fixtures/demo'
import { ORDERS, SPY_ORDER, TRADES } from '@/dev/fixtures/orders'
import { TRADE_PROPOSALS } from '@/dev/fixtures/proposals'
import { type ServiceResult, withFixtureFallback } from './apiMode'
import { get, post } from './http'
import { runMutation } from './mutation'

export function fetchTradeProposals(status?: ProposalStatus): Promise<ServiceResult<TradeProposal[]>> {
  return withFixtureFallback(
    () => get<TradeProposal[]>('/trade-proposals', { params: status ? { status } : {} }),
    () => (status ? TRADE_PROPOSALS.filter((p) => p.status === status) : TRADE_PROPOSALS),
  )
}

export function fetchTradeProposal(id: string): Promise<ServiceResult<TradeProposal>> {
  return withFixtureFallback(
    () => get<TradeProposal>(`/trade-proposals/${id}`),
    () => {
      const proposal = TRADE_PROPOSALS.find((p) => p.id === id) ?? TRADE_PROPOSALS[0]
      if (!proposal) throw new Error('No fixture proposal available')
      return proposal
    },
  )
}

export function approveProposal(id: string, notes?: string): Promise<TradeProposal> {
  return runMutation(
    () => post<TradeProposal>(`/trade-proposals/${id}/approve`, { notes }),
    () => {
      const proposal = TRADE_PROPOSALS.find((p) => p.id === id)
      if (!proposal) throw new Error(`Unknown proposal ${id}`)
      proposal.status = 'approved'
      proposal.approved_at = isoDaysFromAnchor(0)
      return { ...proposal }
    },
  )
}

export function rejectProposal(id: string, reason: string): Promise<TradeProposal> {
  return runMutation(
    () => post<TradeProposal>(`/trade-proposals/${id}/reject`, { reason }),
    () => {
      const proposal = TRADE_PROPOSALS.find((p) => p.id === id)
      if (!proposal) throw new Error(`Unknown proposal ${id}`)
      proposal.status = 'rejected'
      proposal.rejected_reason = reason
      return { ...proposal }
    },
  )
}

export function executeProposal(id: string, idempotencyKey: string): Promise<Order> {
  return runMutation(
    // The backend reads this from the Idempotency-Key header (see
    // ExecuteTradeProposalRequest::IDEMPOTENCY_HEADER), not a body field.
    () => post<Order>(`/trade-proposals/${id}/execute`, undefined, { headers: { 'Idempotency-Key': idempotencyKey } }),
    () => {
      const proposal = TRADE_PROPOSALS.find((p) => p.id === id)
      if (proposal) proposal.status = 'executed'
      return { ...SPY_ORDER, idempotency_key: idempotencyKey, submitted_at: isoDaysFromAnchor(0) }
    },
  )
}

export function fetchOrders(): Promise<ServiceResult<Order[]>> {
  return withFixtureFallback(
    () => get<Order[]>('/orders'),
    () => ORDERS,
  )
}

export function fetchOrder(id: string): Promise<ServiceResult<Order>> {
  return withFixtureFallback(
    () => get<Order>(`/orders/${id}`),
    () => {
      const order = ORDERS.find((o) => o.id === id) ?? ORDERS[0]
      if (!order) throw new Error('No fixture order available')
      return order
    },
  )
}

export function cancelOrder(id: string): Promise<Order> {
  return runMutation(
    () => post<Order>(`/orders/${id}/cancel`),
    () => {
      const order = ORDERS.find((o) => o.id === id)
      if (!order) throw new Error(`Unknown order ${id}`)
      order.status = 'cancelled'
      return { ...order }
    },
  )
}

export function fetchTrades(): Promise<ServiceResult<Trade[]>> {
  return withFixtureFallback(
    () => get<Trade[]>('/trades'),
    () => TRADES,
  )
}
