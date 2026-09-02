import { BrowserRouter, Routes, Route } from 'react-router-dom'
import Login from './pages/Login/Login'
import Dashboard from './pages/Dashboard/Dashboard'
import Students from './pages/Students/Students'
import Import from './pages/Import/Import'
import Attendance from './pages/Attendance/Attendance'
import Records from './pages/Records/Records'
import Reminders from './pages/Reminders/Reminders'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Login />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/students" element={<Students />} />
        <Route path="/import" element={<Import />} />
        <Route path="/attendance" element={<Attendance />} />
        <Route path="/records" element={<Records />} />
        <Route path="/reminders" element={<Reminders />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App