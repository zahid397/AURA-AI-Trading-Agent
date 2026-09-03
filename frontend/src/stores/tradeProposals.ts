import { ref } from 'vue'
import { defineStore } from 'pinia'
import * as proposalsService from '@/services/proposals'
import { useUiNotificationsStore } from './uiNotifications'
import type { Order, TradeProposal } from '@/types/api'

function makeIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return `idem-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export const useTradeProposalsStore = defineStore('tradeProposals', () => {
  const proposals = ref<TradeProposal[]>([])
  const selected = ref<TradeProposal | null>(null)
  const order = ref<Order | null>(null)

  const isLoading = ref(false)
  const isLoadingSelected = ref(false)
  const isSubmitting = ref(false)
  const error = ref<string | null>(null)

  async function loadProposals(): Promise<void> {
    const ui = useUiNotificationsStore()
    isLoading.value = true
    error.value = null
    try {
      const result = await proposalsService.fetchTradeProposals()
      proposals.value = result.data
      ui.setFallback('trade-proposals', result.fallbackReason ?? null)
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load trade proposals.'
    } finally {
      isLoading.value = false
    }
  }

  async function loadProposal(id: string): Promise<void> {
    isLoadingSelected.value = true
    order.value = null
    try {
      const result = await proposalsService.fetchTradeProposal(id)
      selected.value = result.data
      if (result.data.status === 'executed') {
        const orders = await proposalsService.fetchOrders()
        order.value = orders.data.find((o) => o.trade_proposal_id === id) ?? null
      }
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to load proposal.'
      selected.value = null
    } finally {
      isLoadingSelected.value = false
    }
  }

  async function approve(id: string, notes?: string): Promise<boolean> {
    const ui = useUiNotificationsStore()
    isSubmitting.value = true
    try {
      const updated = await proposalsService.approveProposal(id, notes)
      selected.value = updated
      const idx = proposals.value.findIndex((p) => p.id === id)
      if (idx !== -1) proposals.value.splice(idx, 1, updated)
      ui.success('Proposal approved.')
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to approve proposal.')
      return false
    } finally {
      isSubmitting.value = false
    }
  }

  async function reject(id: string, reason: string): Promise<boolean> {
    const ui = useUiNotificationsStore()
    isSubmitting.value = true
    try {
      const updated = await proposalsService.rejectProposal(id, reason)
      selected.value = updated
      const idx = proposals.value.findIndex((p) => p.id === id)
      if (idx !== -1) proposals.value.splice(idx, 1, updated)
      ui.info('Proposal rejected.')
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to reject proposal.')
      return false
    } finally {
      isSubmitting.value = false
    }
  }

  async function execute(id: string): Promise<boolean> {
    const ui = useUiNotificationsStore()
    isSubmitting.value = true
    try {
      const submittedOrder = await proposalsService.executeProposal(id, makeIdempotencyKey())
      order.value = submittedOrder
      if (selected.value) selected.value = { ...selected.value, status: 'executed' }
      ui.success('Order submitted to Alpaca paper trading.')
      return true
    } catch (err) {
      ui.error(err instanceof Error ? err.message : 'Failed to execute trade.')
      return false
    } finally {
      isSubmitting.value = false
    }
  }

  return { proposals, selected, order, isLoading, isLoadingSelected, isSubmitting, error, loadProposals, loadProposal, approve, reject, execute }
})
