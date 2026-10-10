import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import Login from './pages/_Login/Login'
import { ForgotPassword, ResetPassword } from './pages/_Login/PasswordReset'
import Dashboard from './pages/Secretary/Dashboard/SecDashboard'
import Students from './pages/Secretary/Students/Students'
import Import from './pages/Secretary/Import/Import'
import Attendance from './pages/Secretary/Attendance/Attendance'
import Reminders from './pages/_Reminders/Reminders'
import ProgramHeadDashboard from './pages/Program Head/Dashboard/ProgramHeadDashboard'
import Sidebar from './components/Sidebar/Sidebar'
import Profile from './pages/_Profile/Profile'
import ProgramHeadSubmissions from './pages/Program Head/Submission/ProgramHeadSubmissions'
import ProgramHeadRecords from './pages/Program Head/Records/ProgramHeadRecords'
import AdminDashboard from './pages/Admin/Dashboard/AdminDashboard'
import ChancellorDashboard from './pages/Chancellor/Dashboard/ChancellorDashboard'

const landingByRole = {
  secretary: '/dashboard',
  program_secretary: '/program-secretary/dashboard',
  program_head: '/program-head/dashboard',
  chancellor: '/chancellor/dashboard',
  admin: '/admin/dashboard',
}

function getSession() {
  try {
    const user = JSON.parse(localStorage.getItem('user') || 'null')
    const token = localStorage.getItem('auth_token')
    return user && token ? user : null
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

      <Route path="/admin/dashboard" element={<RoleRoute roles={['admin']}><AdminDashboard /></RoleRoute>} />
      <Route path="/admin/user-roles" element={<RoleRoute roles={['admin']}><RolePlaceholder title="User Roles" /></RoleRoute>} />
      <Route path="/admin/students" element={<RoleRoute roles={['admin']}><RolePlaceholder title="Students" /></RoleRoute>} />
      <Route path="/admin/programs" element={<RoleRoute roles={['admin']}><RolePlaceholder title="Programs" /></RoleRoute>} />
      <Route path="/admin/settings" element={<RoleRoute roles={['admin']}><RolePlaceholder title="System Settings" /></RoleRoute>} />

      <Route path="/unauthorized" element={session ? <RolePlaceholder title="Access not configured" /> : <Navigate to="/" replace />} />

      <Route path="*" element={<Navigate to={defaultLanding} replace />} />
    </Routes>
  )
}

function ResponsiveApp() {
  const [isCompactScreen, setIsCompactScreen] = useState(() => window.innerWidth <= 900)
  const location = useLocation()

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
