import { BrowserRouter, Routes, Route } from 'react-router-dom'
import Login from './pages/Login/Login'
import Dashboard from './pages/Dashboard/Dashboard'
import Students from './pages/Students/Students'
import Import from './pages/Import/Import'
import Attendance from './pages/Attendance/Attendance'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Login />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/students" element={<Students />} />
        <Route path="/import" element={<Import />} />
        <Route path="/attendance" element={<Attendance />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App