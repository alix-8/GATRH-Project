import { BrowserRouter, Routes, Route } from 'react-router-dom'
import Login from './pages/_Login/Login'
import Dashboard from './pages/Secretary/Dashboard/SecDashboard'
import Students from './pages/Secretary/Students/Students'
import Import from './pages/Secretary/Import/Import'
import Attendance from './pages/Secretary/Attendance/Attendance'
import Reminders from './pages/_Reminders/Reminders'
import Profile from './pages/_Profile/Profile'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Login />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/students" element={<Students />} />
        <Route path="/import" element={<Import />} />
        <Route path="/attendance" element={<Attendance />} />
        <Route path="/reminders" element={<Reminders />} />
        <Route path="/profile" element={<Profile />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
