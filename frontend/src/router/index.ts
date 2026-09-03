import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    title: string
    requiresAuth?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(_to, _from, savedPosition) {
    return savedPosition ?? { top: 0 }
  },
  routes: [
    {
      path: '/',
      redirect: '/dashboard',
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/auth/LoginPage.vue'),
      meta: { title: 'Sign in', requiresAuth: false },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/auth/RegisterPage.vue'),
      meta: { title: 'Create account', requiresAuth: false },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/pages/DashboardPage.vue'),
      meta: { title: 'Dashboard' },
    },
    {
      path: '/agents',
      name: 'agents',
      component: () => import('@/pages/AgentsPage.vue'),
      meta: { title: 'AI Agents' },
    },
    {
      path: '/options-scanner',
      name: 'options-scanner',
      component: () => import('@/pages/OptionsScannerPage.vue'),
      meta: { title: 'Options Scanner' },
    },
    {
      path: '/strategy-analysis/:id?',
      name: 'strategy-analysis',
      component: () => import('@/pages/StrategyAnalysisPage.vue'),
      meta: { title: 'Strategy Analysis' },
      props: true,
    },
    {
      path: '/risk-management',
      name: 'risk-management',
      component: () => import('@/pages/RiskManagementPage.vue'),
      meta: { title: 'Risk Management' },
    },
    {
      path: '/trade-execution/:proposalId?',
      name: 'trade-execution',
      component: () => import('@/pages/TradeExecutionPage.vue'),
      meta: { title: 'Trade Execution' },
      props: true,
    },
    {
      path: '/decisions',
      name: 'decisions',
      component: () => import('@/pages/DecisionsPage.vue'),
      meta: { title: 'AI Decisions' },
    },
    {
      path: '/portfolio',
      name: 'portfolio',
      component: () => import('@/pages/PortfolioPage.vue'),
      meta: { title: 'Portfolio' },
    },
    {
      path: '/backtesting',
      name: 'backtesting',
      component: () => import('@/pages/BacktestingPage.vue'),
      meta: { title: 'Backtesting' },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/pages/SettingsPage.vue'),
      meta: { title: 'Settings' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/pages/NotFoundPage.vue'),
      meta: { title: 'Not found', requiresAuth: false },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.bootstrap()

  const requiresAuth = to.meta.requiresAuth !== false
  if (requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/dashboard' ? { redirect: to.fullPath } : undefined }
  }
  if ((to.name === 'login' || to.name === 'register') && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · AURA` : 'AURA'
})

export default router
