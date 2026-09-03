<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { CheckCircle2, Circle, CircleDot, Clock3, ShieldAlert, XCircle } from 'lucide-vue-next'
import Badge from '@/components/common/Badge.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ErrorRetry from '@/components/common/ErrorRetry.vue'
import Skeleton from '@/components/common/Skeleton.vue'
import { useTradeProposalsStore } from '@/stores/tradeProposals'
import { formatCurrency, formatDateTime, formatPercent, occSymbolLabel, truncateMiddle } from '@/utils/format'

const props = defineProps<{ proposalId?: string }>()
const proposals = useTradeProposalsStore()

async function load(id?: string): Promise<void> {
  let targetId = id
  if (!targetId) {
    if (proposals.proposals.length === 0) await proposals.loadProposals()
    targetId = proposals.proposals.find((p) => p.status === 'pending')?.id ?? proposals.proposals[0]?.id
  }
  if (targetId) await proposals.loadProposal(targetId)
}

onMounted(() => void load(props.proposalId))
watch(
  () => props.proposalId,
  (next) => void load(next),
)

const proposal = computed(() => proposals.selected)

const STEPS = ['Proposal', 'Risk Review', 'Approval', 'Broker Submission', 'Fill / Monitoring']

const currentStepIndex = computed(() => {
  const p = proposal.value
  if (!p) return 0
  if (p.status === 'executed') return proposals.order?.status === 'filled' ? 4 : 3
  if (p.status === 'rejected') return 1
  if (p.status === 'approved') return 2
  if (p.status === 'pending') return p.risk_evaluation ? 2 : 1
  return 0
})

const isRejectedAtRisk = computed(() => proposal.value?.risk_evaluation?.result === 'rejected')
const isExpired = computed(() => (proposal.value ? new Date(proposal.value.expires_at).getTime() < Date.now() : false))
const canApprove = computed(() => proposal.value?.status === 'pending' && !isRejectedAtRisk.value)
const canExecute = computed(() => proposal.value?.status === 'approved' && !isExpired.value)

const idempotencyKey = ref('')
watch(
  proposal,
  (p) => {
    if (p) idempotencyKey.value = `idem-${p.id}-${Date.now().toString(36)}`
  },
  { immediate: true },
)

const approveDialogOpen = ref(false)
const rejectDialogOpen = ref(false)
const executeDialogOpen = ref(false)
const rejectReason = ref('')

async function doApprove(): Promise<void> {
  if (!proposal.value) return
  await proposals.approve(proposal.value.id)
  approveDialogOpen.value = false
}
async function doReject(): Promise<void> {
  if (!proposal.value || !rejectReason.value.trim()) return
  await proposals.reject(proposal.value.id, rejectReason.value.trim())
  rejectDialogOpen.value = false
  rejectReason.value = ''
}
async function doExecute(): Promise<void> {
  if (!proposal.value) return
  await proposals.execute(proposal.value.id)
  executeDialogOpen.value = false
}

function approvalLabel(): string {
  const p = proposal.value
  if (!p?.approved_at) return 'Not yet approved'
  if (!p.approved_by) return `Auto-approved by policy at ${formatDateTime(p.approved_at)} (no human click — autonomy mode permitted execution)`
  return `Approved by ${p.approved_by} at ${formatDateTime(p.approved_at)}`
}
</script>

<template>
  <div class="space-y-5">
    <div v-if="proposals.isLoadingSelected && !proposal" class="space-y-3">
      <Skeleton height="3rem" />
      <Skeleton height="16rem" />
    </div>
    <ErrorRetry v-else-if="proposals.error" :message="proposals.error" @retry="() => load(props.proposalId)" />
    <EmptyState v-else-if="!proposal" title="No trade proposal" description="There are no trade proposals to review right now." />

    <template v-else>
      <!-- Stepper -->
      <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
        <ol class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-0">
          <template v-for="(step, i) in STEPS" :key="step">
            <li class="flex items-center gap-2 sm:flex-1">
              <component
                :is="i < currentStepIndex ? CheckCircle2 : i === currentStepIndex ? CircleDot : Circle"
                :size="18"
                :class="
                  proposal.status === 'rejected' && i === 1
                    ? 'text-danger'
                    : i <= currentStepIndex
                      ? 'text-brand-600'
                      : 'text-slate-300'
                "
              />
              <span class="text-xs font-medium" :class="i <= currentStepIndex ? 'text-ink-950' : 'text-slate-600'">{{ step }}</span>
            </li>
            <li v-if="i < STEPS.length - 1" class="hidden h-px flex-1 bg-border sm:block" aria-hidden="true" />
          </template>
        </ol>
      </div>

      <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
          <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
              <h2 class="text-sm font-semibold text-ink-950">Trade Request</h2>
              <Badge :tone="proposal.status === 'executed' ? 'success' : proposal.status === 'rejected' ? 'danger' : proposal.status === 'approved' ? 'info' : 'warning'" class="capitalize">
                {{ proposal.status }}
              </Badge>
            </div>
            <p class="text-sm font-medium capitalize text-ink-950">{{ proposal.strategy?.symbol ?? '--' }} — {{ proposal.strategy?.strategy_type?.replaceAll('_', ' ') ?? '--' }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ proposal.strategy.thesis }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
              <div><dt class="text-xs text-slate-600">Confidence</dt><dd class="tabular-nums font-semibold text-ink-950">{{ formatPercent(proposal.strategy.confidence) }}</dd></div>
              <div><dt class="text-xs text-slate-600">Net {{ proposal.strategy.net_debit ? 'debit' : 'credit' }}</dt><dd class="tabular-nums font-semibold text-ink-950">{{ formatCurrency(proposal.strategy.net_debit ?? proposal.strategy.net_credit) }}</dd></div>
              <div><dt class="text-xs text-slate-600">Max loss</dt><dd class="tabular-nums font-semibold text-danger">-{{ formatCurrency(proposal.strategy.max_loss) }}</dd></div>
              <div><dt class="text-xs text-slate-600">Expires</dt><dd class="tabular-nums font-semibold" :class="isExpired ? 'text-danger' : 'text-ink-950'">{{ formatDateTime(proposal.expires_at) }}</dd></div>
            </dl>
          </div>

          <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-ink-950">Order Preview <span class="font-normal text-slate-600">(order_class: mleg)</span></h2>
            <div class="scroll-x">
              <table class="w-full min-w-[560px] text-sm">
                <thead>
                  <tr class="border-b border-border text-left text-xs text-slate-600">
                    <th class="py-2 font-medium">Side</th>
                    <th class="py-2 font-medium">Contract</th>
                    <th class="py-2 font-medium">Intent</th>
                    <th class="py-2 font-medium">Qty</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="leg in proposal.strategy.legs ?? []" :key="leg.id" class="border-b border-border last:border-0">
                    <td class="py-2"><Badge :tone="leg.action === 'buy' ? 'success' : 'danger'" class="capitalize">{{ leg.action }}</Badge></td>
                    <td class="py-2 text-ink-950" :title="leg.occ_symbol">{{ truncateMiddle(occSymbolLabel(leg.occ_symbol), 28) }}</td>
                    <td class="py-2 text-slate-600">{{ leg.position_intent?.replaceAll('_', ' ') ?? '--' }}</td>
                    <td class="tabular-nums py-2">{{ leg.ratio_qty }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-ink-950"><ShieldAlert :size="15" /> Risk Gate Checklist</h2>
            <ul v-if="proposal.risk_evaluation" class="space-y-1.5">
              <li v-for="rule in proposal.risk_evaluation.rule_results" :key="rule.id" class="flex items-start gap-2 text-sm">
                <component :is="rule.passed ? CheckCircle2 : XCircle" :size="15" class="mt-0.5 shrink-0" :class="rule.passed ? 'text-success' : 'text-danger'" />
                <div>
                  <p class="text-ink-950">{{ rule.description }} — <span class="tabular-nums">{{ rule.observed_value }}</span> vs limit <span class="tabular-nums">{{ rule.limit_value }}</span></p>
                  <p class="text-xs text-slate-600">{{ rule.explanation }}</p>
                </div>
              </li>
            </ul>
            <p v-else class="text-sm text-slate-600">Risk evaluation pending.</p>
          </div>
        </div>

        <div class="space-y-5">
          <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-ink-950">Approval</h2>
            <p class="text-sm text-slate-600">{{ approvalLabel() }}</p>
            <div v-if="proposal.status === 'rejected'" class="mt-2 rounded-control border border-danger/20 bg-danger-50 p-2.5 text-xs text-danger">
              {{ proposal.rejected_reason }}
            </div>
            <div class="mt-3 flex flex-col gap-2">
              <button
                v-if="canApprove"
                type="button"
                class="rounded-control bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700"
                @click="approveDialogOpen = true"
              >
                Approve
              </button>
              <button
                v-if="canApprove"
                type="button"
                class="rounded-control border border-danger/30 bg-danger-50 px-3 py-1.5 text-sm font-medium text-danger hover:bg-danger/10"
                @click="rejectDialogOpen = true"
              >
                Reject
              </button>
              <button
                type="button"
                class="rounded-control bg-success px-3 py-1.5 text-sm font-medium text-white hover:bg-success/90 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!canExecute"
                :title="!canExecute ? 'Proposal must be approved and not expired before execution.' : undefined"
                @click="executeDialogOpen = true"
              >
                Execute Trade
              </button>
            </div>
          </div>

          <div class="rounded-card border border-border bg-surface p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-ink-950">Order Status</h2>
            <div v-if="proposals.order" class="space-y-1.5 text-sm">
              <div class="flex items-center justify-between">
                <span class="text-slate-600">Status</span>
                <Badge :tone="proposals.order.status === 'filled' ? 'success' : proposals.order.status === 'rejected' ? 'danger' : 'info'" class="capitalize">{{ proposals.order.status }}</Badge>
              </div>
              <div class="flex items-center justify-between"><span class="text-slate-600">Broker order ID</span><span class="tabular-nums font-medium text-ink-950">{{ proposals.order.broker_order_id ?? '--' }}</span></div>
              <div class="flex items-center justify-between"><span class="text-slate-600">Submitted via</span><span class="font-medium capitalize text-ink-950">{{ proposals.order.submitted_via?.replaceAll('_', ' ') ?? '--' }}</span></div>
              <div class="flex items-center justify-between"><span class="text-slate-600">Submitted at</span><span class="font-medium text-ink-950">{{ formatDateTime(proposals.order.submitted_at) }}</span></div>
            </div>
            <p v-else class="flex items-center gap-1.5 text-sm text-slate-600"><Clock3 :size="14" /> Not yet submitted to broker.</p>
          </div>

          <div class="rounded-card border border-border bg-canvas p-4 text-xs text-slate-600">
            <p class="font-medium text-ink-950">Idempotency key</p>
            <p class="tabular-nums mt-1 break-all">{{ idempotencyKey }}</p>
            <p class="mt-1">Ensures Execute can be safely retried without submitting a duplicate order.</p>
          </div>
        </div>
      </div>
    </template>

    <ConfirmDialog :open="approveDialogOpen" title="Approve this proposal?" description="This clears the proposal for execution against Alpaca paper trading." confirm-label="Approve" :loading="proposals.isSubmitting" @confirm="doApprove" @cancel="approveDialogOpen = false" />

    <ConfirmDialog :open="rejectDialogOpen" title="Reject this proposal?" danger confirm-label="Reject" :loading="proposals.isSubmitting" @confirm="doReject" @cancel="rejectDialogOpen = false">
      <label for="reject-reason" class="mb-1 block text-xs font-medium text-slate-600">Reason (required)</label>
      <textarea id="reject-reason" v-model="rejectReason" rows="3" class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-sm outline-none focus-visible:border-brand-600" />
    </ConfirmDialog>

    <ConfirmDialog
      :open="executeDialogOpen"
      title="Execute trade?"
      description="This submits a real (paper) order to Alpaca. It cannot be undone once filled."
      confirm-label="Execute"
      danger
      :loading="proposals.isSubmitting"
      @confirm="doExecute"
      @cancel="executeDialogOpen = false"
    />
  </div>
</template>
