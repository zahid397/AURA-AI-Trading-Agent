import type { FunctionalComponent } from 'vue'
import {
  Bot,
  FlaskConical,
  History,
  LayoutDashboard,
  LineChart,
  ScanSearch,
  Send,
  Settings,
  ShieldAlert,
  Wallet,
} from 'lucide-vue-next'

export interface NavItem {
  label: string
  routeName: string
  icon: FunctionalComponent
}

export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', routeName: 'dashboard', icon: LayoutDashboard },
  { label: 'AI Agents', routeName: 'agents', icon: Bot },
  { label: 'Options Scanner', routeName: 'options-scanner', icon: ScanSearch },
  { label: 'Strategy Analysis', routeName: 'strategy-analysis', icon: LineChart },
  { label: 'Risk Management', routeName: 'risk-management', icon: ShieldAlert },
  { label: 'Portfolio', routeName: 'portfolio', icon: Wallet },
  { label: 'Trade Execution', routeName: 'trade-execution', icon: Send },
  { label: 'AI Decisions', routeName: 'decisions', icon: History },
  { label: 'Backtesting', routeName: 'backtesting', icon: FlaskConical },
  { label: 'Settings', routeName: 'settings', icon: Settings },
]
