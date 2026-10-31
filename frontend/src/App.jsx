import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import Login from './pages/auth/Login'
import { ForgotPassword, ResetPassword } from './pages/auth/PasswordReset'
import Dashboard from './user/secretary/dashboard/Dashboard'
import Students from './user/secretary/students/Students'
import Import from './user/secretary/import/Import'
import Attendance from './user/secretary/attendance/Attendance'
import Reminders from './pages/reminders/Reminders'
import ProgramHeadDashboard from './user/program-head/dashboard/Dashboard'
import Sidebar from './components/layout/Sidebar'
import Profile from './pages/profile/Profile'
import ProgramHeadSubmissions from './user/program-head/submissions/Submissions'
import ProgramHeadRecords from './user/program-head/records/Records'
import AdminDashboard from './user/admin/dashboard/Dashboard'
import AdminUserRoles from './user/admin/user-roles/UserRoles'
import AdminPrograms from './user/admin/programs/Programs'
import AdminStudents from './user/admin/students/Students'
import AdminActivityLog from './user/admin/activity-log/ActivityLog'
import AdminSystemSettings from './user/admin/system-settings/SystemSettings'
import ChancellorDashboard from './user/chancellor/dashboard/Dashboard'
import CoordinatorMonitoring from './user/coordinator/monitoring/Monitoring'

const landingByRole = {
  secretary: '/dashboard',
  program_secretary: '/program-secretary/dashboard',
  program_head: '/program-head/dashboard',
  chancellor: '/chancellor/dashboard',
  cm_coordinator: '/coordinator/monitoring',
  admin: '/admin/dashboard',
}

const pageTitleByPath = {
  '/': 'Login',
  '/forgot-password': 'Forgot Password',
  '/reset-password': 'Reset Password',
  '/dashboard': 'Dashboard',
  '/students': 'Students',
  '/import': 'Import',
  '/attendance': 'Attendance',
  '/reminders': 'Reminders',
  '/profile': 'Profile',
  '/program-head/dashboard': 'Dashboard',
  '/program-head/submission': 'Submissions',
  '/program-head/records': 'Records',
  '/program-secretary/dashboard': 'Dashboard',
  '/program-secretary/submission': 'Submissions',
  '/program-secretary/records': 'Records',
  '/chancellor/dashboard': 'Dashboard',
  '/coordinator/monitoring': 'Monitoring',
  '/admin/dashboard': 'Dashboard',
  '/admin/user-roles': 'User Roles',
  '/admin/students': 'Students',
  '/admin/programs': 'Programs',
  '/admin/activity-log': 'Activity Log',
  '/admin/system-settings': 'System Settings',
  '/unauthorized': 'Access Not Configured',
}

function getSession() {
  try {
    localStorage.removeItem('auth_token')
    const user = JSON.parse(localStorage.getItem('user') || 'null')
    return user
  } catch {
    return null
  }
}

function RoleRoute({ roles, children }) {
  const user = getSession()
  if (!user) return <Navigate to="/" replace />
  if (!roles.includes(user.role)) return <Navigate to={landingByRole[user.role] || '/unauthorized'} replace />
  return children
}

function RolePlaceholder({ title }) {
  return <div style={{ display: 'flex', minHeight: '100vh', color: '#172b4d' }}><Sidebar /><main style={{ padding: '40px' }}><h1>{title}</h1><p>This page is not available yet.</p></main></div>
}

function AppRoutes() {
  const navigate = useNavigate()
  const session = getSession()
  const defaultLanding = session ? landingByRole[session.role] || '/unauthorized' : '/'

  useEffect(() => {
    const handleExpiredSession = () => navigate('/', {
      replace: true,
      state: { authMessage: 'Your session has expired. Please sign in again.' },
    })
    window.addEventListener('auth:expired', handleExpiredSession)
    return () => window.removeEventListener('auth:expired', handleExpiredSession)
  }, [navigate])

  return (
    <Routes>
      <Route path="/" element={session ? <Navigate to={defaultLanding} replace /> : <Login />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />

      <Route path="/dashboard" element={<RoleRoute roles={['secretary']}><Dashboard /></RoleRoute>} />
      <Route path="/students" element={<RoleRoute roles={['secretary']}><Students /></RoleRoute>} />
      <Route path="/import" element={<RoleRoute roles={['secretary']}><Import /></RoleRoute>} />
      <Route path="/attendance" element={<RoleRoute roles={['secretary']}><Attendance /></RoleRoute>} />
      <Route path="/reminders" element={<RoleRoute roles={['secretary', 'program_head', 'program_secretary', 'cm_coordinator']}><Reminders /></RoleRoute>} />
      <Route path="/profile" element={<RoleRoute roles={['secretary', 'program_secretary', 'program_head', 'chancellor', 'cm_coordinator']}><Profile /></RoleRoute>} />

      <Route path="/program-head/dashboard" element={<RoleRoute roles={['program_head']}><ProgramHeadDashboard /></RoleRoute>} />
      <Route path="/program-head/submission" element={<RoleRoute roles={['program_head']}><ProgramHeadSubmissions /></RoleRoute>} />
      <Route path="/program-head/records" element={<RoleRoute roles={['program_head']}><ProgramHeadRecords /></RoleRoute>} />

      <Route path="/program-secretary/dashboard" element={<RoleRoute roles={['program_secretary']}><ProgramHeadDashboard /></RoleRoute>} />
      <Route path="/program-secretary/submission" element={<RoleRoute roles={['program_secretary']}><ProgramHeadSubmissions /></RoleRoute>} />
      <Route path="/program-secretary/records" element={<RoleRoute roles={['program_secretary']}><ProgramHeadRecords /></RoleRoute>} />

      <Route path="/chancellor/dashboard" element={<RoleRoute roles={['chancellor']}><ChancellorDashboard /></RoleRoute>} />

      <Route path="/coordinator/monitoring" element={<RoleRoute roles={['cm_coordinator']}><CoordinatorMonitoring /></RoleRoute>} />

      <Route path="/admin/dashboard" element={<RoleRoute roles={['admin']}><AdminDashboard /></RoleRoute>} />
      <Route path="/admin/user-roles" element={<RoleRoute roles={['admin']}><AdminUserRoles /></RoleRoute>} />
      <Route path="/admin/students" element={<RoleRoute roles={['admin']}><AdminStudents /></RoleRoute>} />
      <Route path="/admin/programs" element={<RoleRoute roles={['admin']}><AdminPrograms /></RoleRoute>} />
      <Route path="/admin/activity-log" element={<RoleRoute roles={['admin']}><AdminActivityLog /></RoleRoute>} />
      <Route path="/admin/system-settings" element={<RoleRoute roles={['admin']}><AdminSystemSettings /></RoleRoute>} />

      <Route path="/unauthorized" element={session ? <RolePlaceholder title="Access not configured" /> : <Navigate to="/" replace />} />

      <Route path="*" element={<Navigate to={defaultLanding} replace />} />
    </Routes>
  )
}

function ResponsiveApp() {
  const [isCompactScreen, setIsCompactScreen] = useState(() => window.innerWidth <= 900)
  const location = useLocation()

  useEffect(() => {
    const pageTitle = pageTitleByPath[location.pathname] || 'GATRH'
    document.title = `GATRH - ${pageTitle}`
  }, [location.pathname])

  useEffect(() => {
    const updateScreenSize = () => setIsCompactScreen(window.innerWidth <= 900)
    window.addEventListener('resize', updateScreenSize)
    return () => window.removeEventListener('resize', updateScreenSize)
  }, [])

  const isPasswordFlow = ['/forgot-password', '/reset-password'].includes(location.pathname)
  const showScreenSizeNotice = isCompactScreen && !isPasswordFlow

  return (
    <>
      <div style={showScreenSizeNotice ? { display: 'none' } : undefined}>
        <AppRoutes />
      </div>
      {showScreenSizeNotice && (
        <main className="screen-size-notice" role="status">
          <div>
            <h1>Please return to full screen</h1>
            <h2>The website is not yet available on small screens</h2>
            <p>Widen your browser window to use the app.</p>
          </div>
        </main>
      )}
    </>
  )
}

function App() {
  return <BrowserRouter><ResponsiveApp /></BrowserRouter>
}

export default App
