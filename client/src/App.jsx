import { Routes, Route } from 'react-router-dom';
import Navbar from './components/Navbar.jsx';
import Home from './pages/Home.jsx';
import Passengers from './pages/Passengers.jsx';
import Trains from './pages/Trains.jsx';
import Reservations from './pages/Reservations.jsx';
import SQLReports from './pages/SQLReports.jsx';

export default function App() {
  return (
    <>
      <Navbar />
      <main className="container py-4">
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/passengers" element={<Passengers />} />
          <Route path="/trains" element={<Trains />} />
          <Route path="/reservations" element={<Reservations />} />
          <Route path="/sql-reports" element={<SQLReports />} />
          <Route path="*" element={<p>Page not found.</p>} />
        </Routes>
      </main>
    </>
  );
}
