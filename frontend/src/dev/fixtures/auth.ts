import type { MeResponse, UserSettings } from '@/types/api'
import { DEMO_PASSWORD, DEMO_USER } from './demo'

export const DEMO_CREDENTIALS = {
  email: DEMO_USER.email,
  password: DEMO_PASSWORD,
}

export const DEMO_USER_SETTINGS: UserSettings = {
  id: 'settings-user-demo-001',
  user_id: DEMO_USER.id,
  mode: 'approval_required',
  confidence_threshold: '0.55',
  notification_prefs: {
    email_on_execution: true,
    email_on_rejection: true,
    email_on_kill_switch: true,
    in_app: true,
  },
}

export const ME_RESPONSE: MeResponse = {
  user: DEMO_USER,
  settings: DEMO_USER_SETTINGS,
}
