import type { Order, Trade } from '@/types/api'
import { isoDaysFromAnchor } from './demo'
import { SPY_PROPOSAL } from './proposals'

export const SPY_ORDER: Order = {
  id: 'order-spy-1',
  trade_proposal_id: SPY_PROPOSAL.id,
  broker_order_id: 'alpaca-paper-8842910',
  client_order_id: 'aura-spy-bcs-0001',
  idempotency_key: 'idem-spy-bcs-0001',
  status: 'filled',
  order_class: 'mleg',
  symbol: 'SPY',
  qty: 1,
  submitted_via: 'alpaca_api',
  submitted_at: isoDaysFromAnchor(0),
  updated_at: isoDaysFromAnchor(0),
  legs: [
    { id: 'oleg-spy-1', order_id: 'order-spy-1', occ_symbol: 'SPY_C570', side: 'buy', ratio_qty: 1, position_intent: 'buy_to_open', filled_qty: 1, filled_avg_price: '13.30' },
    { id: 'oleg-spy-2', order_id: 'order-spy-1', occ_symbol: 'SPY_C600', side: 'sell', ratio_qty: 1, position_intent: 'sell_to_open', filled_qty: 1, filled_avg_price: '4.70' },
  ],
}

export const AAPL_ORDER: Order = {
  id: 'order-aapl-1',
  trade_proposal_id: 'proposal-aapl-historical',
  broker_order_id: 'alpaca-paper-8791204',
  client_order_id: 'aura-aapl-bps-0001',
  idempotency_key: 'idem-aapl-bps-0001',
  status: 'filled',
  order_class: 'mleg',
  symbol: 'AAPL',
  qty: 1,
  submitted_via: 'alpaca_api',
  submitted_at: isoDaysFromAnchor(-20),
  updated_at: isoDaysFromAnchor(-5),
  legs: [
    { id: 'oleg-aapl-1', order_id: 'order-aapl-1', occ_symbol: 'AAPL_P210', side: 'sell', ratio_qty: 1, position_intent: 'sell_to_open', filled_qty: 1, filled_avg_price: '3.10' },
    { id: 'oleg-aapl-2', order_id: 'order-aapl-1', occ_symbol: 'AAPL_P200', side: 'buy', ratio_qty: 1, position_intent: 'buy_to_open', filled_qty: 1, filled_avg_price: '1.45' },
  ],
}

export const QQQ_ORDER: Order = {
  id: 'order-qqq-1',
  trade_proposal_id: 'proposal-qqq-historical',
  broker_order_id: 'alpaca-paper-8765310',
  client_order_id: 'aura-qqq-ic-0001',
  idempotency_key: 'idem-qqq-ic-0001',
  status: 'filled',
  order_class: 'mleg',
  symbol: 'QQQ',
  qty: 1,
  submitted_via: 'alpaca_api',
  submitted_at: isoDaysFromAnchor(-25),
  updated_at: isoDaysFromAnchor(-10),
  legs: [
    { id: 'oleg-qqq-1', order_id: 'order-qqq-1', occ_symbol: 'QQQ_P470', side: 'buy', ratio_qty: 1, position_intent: 'buy_to_open', filled_qty: 1, filled_avg_price: '2.20' },
    { id: 'oleg-qqq-2', order_id: 'order-qqq-1', occ_symbol: 'QQQ_P475', side: 'sell', ratio_qty: 1, position_intent: 'sell_to_open', filled_qty: 1, filled_avg_price: '3.45' },
    { id: 'oleg-qqq-3', order_id: 'order-qqq-1', occ_symbol: 'QQQ_C505', side: 'sell', ratio_qty: 1, position_intent: 'sell_to_open', filled_qty: 1, filled_avg_price: '3.05' },
    { id: 'oleg-qqq-4', order_id: 'order-qqq-1', occ_symbol: 'QQQ_C510', side: 'buy', ratio_qty: 1, position_intent: 'buy_to_open', filled_qty: 1, filled_avg_price: '1.60' },
  ],
}

export const ORDERS: Order[] = [SPY_ORDER, AAPL_ORDER, QQQ_ORDER]

export const TRADES: Trade[] = [
  { id: 'trade-spy-1', order_id: SPY_ORDER.id, fill_id: 'fill-spy-1', qty: 1, price: '13.30', side: 'buy', filled_at: isoDaysFromAnchor(0) },
  { id: 'trade-spy-2', order_id: SPY_ORDER.id, fill_id: 'fill-spy-2', qty: 1, price: '4.70', side: 'sell', filled_at: isoDaysFromAnchor(0) },
  { id: 'trade-aapl-1', order_id: AAPL_ORDER.id, fill_id: 'fill-aapl-1', qty: 1, price: '3.10', side: 'sell', filled_at: isoDaysFromAnchor(-20) },
  { id: 'trade-aapl-2', order_id: AAPL_ORDER.id, fill_id: 'fill-aapl-2', qty: 1, price: '1.45', side: 'buy', filled_at: isoDaysFromAnchor(-20) },
  { id: 'trade-aapl-close', order_id: AAPL_ORDER.id, fill_id: 'fill-aapl-close', qty: 1, price: '0.00', side: 'buy', filled_at: isoDaysFromAnchor(-5) },
  { id: 'trade-qqq-1', order_id: QQQ_ORDER.id, fill_id: 'fill-qqq-1', qty: 1, price: '2.20', side: 'buy', filled_at: isoDaysFromAnchor(-25) },
  { id: 'trade-qqq-2', order_id: QQQ_ORDER.id, fill_id: 'fill-qqq-2', qty: 1, price: '3.45', side: 'sell', filled_at: isoDaysFromAnchor(-25) },
  { id: 'trade-qqq-close', order_id: QQQ_ORDER.id, fill_id: 'fill-qqq-close', qty: 1, price: '4.15', side: 'buy', filled_at: isoDaysFromAnchor(-10) },
]
