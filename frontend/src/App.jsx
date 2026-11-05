import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { lazy, Suspense, useEffect, useState } from 'react'
import Login from './pages/auth/Login'
import { ForgotPassword, ResetPassword } from './pages/auth/PasswordReset'
import Sidebar from './components/layout/Sidebar'
import Header from './components/layout/Header'
import LoadingCircle from './components/feedback/LoadingCircle'
import SecretaryDashboardSkeleton from './user/secretary/dashboard/DashboardSkeleton'
import ProgramDashboardSkeleton from './user/program-head/dashboard/DashboardSkeleton'
import AdminDashboardSkeleton from './user/admin/dashboard/DashboardSkeleton'
import ChancellorDashboardSkeleton from './user/chancellor/dashboard/DashboardSkeleton'
import CoordinatorMonitoringSkeleton from './user/coordinator/monitoring/skeleton/MonitoringSkeleton'
import { isLoginSkeletonPending } from './utils/loginSkeletonState'
import { API_BASE_URL } from './api/request'

// Authenticated pages are downloaded only when the user visits them.
const Dashboard = lazy(() => import('./user/secretary/dashboard/Dashboard'))
const Students = lazy(() => import('./user/secretary/students/Students'))
const Import = lazy(() => import('./user/secretary/import/Import'))
const Attendance = lazy(() => import('./user/secretary/attendance/Attendance'))
const Reminders = lazy(() => import('./pages/reminders/Reminders'))
const Profile = lazy(() => import('./pages/profile/Profile'))
const ProgramHeadDashboard = lazy(() => import('./user/program-head/dashboard/Dashboard'))
const ProgramHeadSubmissions = lazy(() => import('./user/program-head/submissions/Submissions'))
const ProgramHeadRecords = lazy(() => import('./user/program-head/records/Records'))
const AdminDashboard = lazy(() => import('./user/admin/dashboard/Dashboard'))
const AdminUserRoles = lazy(() => import('./user/admin/user-roles/UserRoles'))
const AdminPrograms = lazy(() => import('./user/admin/programs/Programs'))
const AdminStudents = lazy(() => import('./user/admin/students/Students'))
const AdminActivityLog = lazy(() => import('./user/admin/activity-log/ActivityLog'))
const AdminSystemSettings = lazy(() => import('./user/admin/system-settings/SystemSettings'))
const ChancellorDashboard = lazy(() => import('./user/chancellor/dashboard/Dashboard'))
const CoordinatorMonitoring = lazy(() => import('./user/coordinator/monitoring/Monitoring'))

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

function saveMinimalUser(user) {
  localStorage.setItem('user', JSON.stringify({
    id: user.id,
    name: user.name,
    role: user.role,
  }))
}

function RoleRoute({ roles, children, user, authStatus, onRetry }) {
  const location = useLocation()
  if (authStatus === 'checking') return <AuthStatusScreen message="Checking your sign-in…" />
  if (authStatus === 'error') return <AuthStatusScreen message="Unable to verify your sign-in. Check your connection and try again." onRetry={onRetry} />
  if (!user) return <Navigate to="/" replace />
  if (!roles.includes(user.role)) return <Navigate to={landingByRole[user.role] || '/unauthorized'} replace />
  const showLoginSkeleton = location.pathname === landingByRole[user.role] && isLoginSkeletonPending()
  return <Suspense fallback={showLoginSkeleton
    ? <LoginLandingSkeleton role={user.role} />
    : <AuthenticatedPageLoading />}>{children}</Suspense>
}

function AuthStatusScreen({ message, onRetry }) {
  return (
    <main style={{ display: 'grid', minHeight: '100vh', placeContent: 'center', gap: 16, color: '#172b4d', textAlign: 'center' }}>
      <p>{message}</p>
      {onRetry && <button type="button" onClick={onRetry}>Retry</button>}
    </main>
  )
}

function LoginLandingSkeleton({ role }) {
  if (role === 'secretary') return <SecretaryDashboardSkeleton />
  if (role === 'program_secretary' || role === 'program_head') {
    return <ProgramDashboardSkeleton programSecretary={role === 'program_secretary'} />
  }
  if (role === 'admin') return <AdminDashboardSkeleton />
  if (role === 'chancellor') return <ChancellorDashboardSkeleton />
  if (role === 'cm_coordinator') {
    const today = new Date()
    const month = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
    return <CoordinatorMonitoringSkeleton month={month} />
  }
  return <AuthenticatedPageLoading />
}

function AuthenticatedPageLoading() {
  const location = useLocation()
  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: '#fff' }}>
      <Sidebar />
      <main style={{ flex: 1, minWidth: 0 }}>
        <Header title={pageTitleByPath[location.pathname] || 'GATRH'} showNotification={false} />
        <div style={{ padding: 32 }}><LoadingCircle label="Loading page…" /></div>
      </main>
    </div>
  )
}

function RolePlaceholder({ title }) {
  return <div style={{ display: 'flex', minHeight: '100vh', color: '#172b4d' }}><Sidebar /><main style={{ padding: '40px' }}><h1>{title}</h1><p>This page is not available yet.</p></main></div>
}

function NotFoundPage({ homePath }) {
  const navigate = useNavigate()

  return (
    <main style={{ display: 'grid', minHeight: '100vh', placeContent: 'center', gap: 12, padding: 24, color: '#172b4d', textAlign: 'center' }}>
      <p style={{ margin: 0, fontSize: 14, fontWeight: 700, letterSpacing: '0.12em' }}>404</p>
      <h1 style={{ margin: 0 }}>Page not found</h1>
      <p style={{ margin: 0 }}>That page address doesn’t exist or may have changed.</p>
      <button type="button" onClick={() => navigate(homePath)} style={{ justifySelf: 'center', marginTop: 8, padding: '10px 18px', cursor: 'pointer' }}>
        {homePath === '/' ? 'Go to login' : 'Go to your dashboard'}
      </button>
    </main>
  )
}

function AppRoutes() {
  const navigate = useNavigate()
  const [auth, setAuth] = useState({ status: 'checking', user: null })
  const [authCheckVersion, setAuthCheckVersion] = useState(0)
  const session = auth.user
  const defaultLanding = session ? landingByRole[session.role] || '/unauthorized' : '/'

  useEffect(() => {
    let active = true
    setAuth({ status: 'checking', user: null })
    fetch(`${API_BASE_URL}/me`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
      .then(async response => {
        const data = await response.json().catch(() => ({}))
        if (response.status === 401) {
          localStorage.removeItem('user')
          if (active) setAuth({ status: 'unauthenticated', user: null })
          return
        }
        if (!response.ok || !data.user?.role) throw new Error(data.message || 'Could not verify session.')
        const user = { id: data.user.id, name: data.user.name, role: data.user.role }
        saveMinimalUser(user)
        if (active) {
          setAuth({ status: 'authenticated', user })
          window.dispatchEvent(new CustomEvent('auth:verified', { detail: { user } }))
        }
      })
      .catch(() => {
        if (active) setAuth({ status: 'error', user: null })
      })
    return () => { active = false }
  }, [authCheckVersion])

  useEffect(() => {
    const handleExpiredSession = () => navigate('/', {
      replace: true,
      state: { authMessage: 'Your session has expired. Please sign in again.' },
    })
    const handleAuthChanged = event => {
      if (event.detail?.user) {
        const user = { id: event.detail.user.id, name: event.detail.user.name, role: event.detail.user.role }
        saveMinimalUser(user)
        setAuth({ status: 'authenticated', user })
      } else {
        localStorage.removeItem('user')
        setAuth({ status: 'unauthenticated', user: null })
      }
    }
    const handleExpired = () => {
      localStorage.removeItem('user')
      setAuth({ status: 'unauthenticated', user: null })
    }
    window.addEventListener('auth:expired', handleExpiredSession)
    window.addEventListener('auth:expired', handleExpired)
    window.addEventListener('auth:changed', handleAuthChanged)
    return () => {
      window.removeEventListener('auth:expired', handleExpiredSession)
      window.removeEventListener('auth:expired', handleExpired)
      window.removeEventListener('auth:changed', handleAuthChanged)
    }
  }, [navigate])

  const roleRoute = (roles, page) => <RoleRoute roles={roles} user={session} authStatus={auth.status} onRetry={() => setAuthCheckVersion(value => value + 1)}>{page}</RoleRoute>

  return (
    <Routes>
      <Route path="/" element={auth.status === 'checking' ? <AuthStatusScreen message="Checking your sign-in…" /> : auth.status === 'error' ? <AuthStatusScreen message="Unable to verify your sign-in. Check your connection and try again." onRetry={() => setAuthCheckVersion(value => value + 1)} /> : session ? <Navigate to={defaultLanding} replace /> : <Login />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />

      <Route path="/dashboard" element={roleRoute(['secretary'], <Dashboard />)} />
      <Route path="/students" element={roleRoute(['secretary'], <Students />)} />
      <Route path="/import" element={roleRoute(['secretary'], <Import />)} />
      <Route path="/attendance" element={roleRoute(['secretary'], <Attendance />)} />
      <Route path="/reminders" element={roleRoute(['secretary', 'program_head', 'program_secretary', 'cm_coordinator'], <Reminders />)} />
      <Route path="/profile" element={roleRoute(['secretary', 'program_secretary', 'program_head', 'chancellor', 'cm_coordinator'], <Profile />)} />

      <Route path="/program-head/dashboard" element={roleRoute(['program_head'], <ProgramHeadDashboard />)} />
      <Route path="/program-head/submission" element={roleRoute(['program_head'], <ProgramHeadSubmissions />)} />
      <Route path="/program-head/records" element={roleRoute(['program_head'], <ProgramHeadRecords />)} />

      <Route path="/program-secretary/dashboard" element={roleRoute(['program_secretary'], <ProgramHeadDashboard />)} />
      <Route path="/program-secretary/submission" element={roleRoute(['program_secretary'], <ProgramHeadSubmissions />)} />
      <Route path="/program-secretary/records" element={roleRoute(['program_secretary'], <ProgramHeadRecords />)} />

      <Route path="/chancellor/dashboard" element={roleRoute(['chancellor'], <ChancellorDashboard />)} />

      <Route path="/coordinator/monitoring" element={roleRoute(['cm_coordinator'], <CoordinatorMonitoring />)} />

      <Route path="/admin/dashboard" element={roleRoute(['admin'], <AdminDashboard />)} />
      <Route path="/admin/user-roles" element={roleRoute(['admin'], <AdminUserRoles />)} />
      <Route path="/admin/students" element={roleRoute(['admin'], <AdminStudents />)} />
      <Route path="/admin/programs" element={roleRoute(['admin'], <AdminPrograms />)} />
      <Route path="/admin/activity-log" element={roleRoute(['admin'], <AdminActivityLog />)} />
      <Route path="/admin/system-settings" element={roleRoute(['admin'], <AdminSystemSettings />)} />

      <Route path="/unauthorized" element={auth.status === 'checking' ? <AuthStatusScreen message="Checking your sign-in…" /> : session ? <RolePlaceholder title="Access not configured" /> : <Navigate to="/" replace />} />

      <Route path="*" element={auth.status === 'checking'
        ? <AuthStatusScreen message="Checking your sign-in…" />
        : auth.status === 'error'
          ? <AuthStatusScreen message="Unable to verify your sign-in. Check your connection and try again." onRetry={() => setAuthCheckVersion(value => value + 1)} />
          : <NotFoundPage homePath={session ? defaultLanding : '/'} />} />
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
  const showScreenSizeNotice = isCompactScreen && !isPasswordFlow && location.pathname !== '/'

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
