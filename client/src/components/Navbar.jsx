import { NavLink } from 'react-router-dom';

const links = [
  ['/', 'Home'],
  ['/passengers', 'Passengers'],
  ['/trains', 'Trains'],
  ['/reservations', 'Reservations'],
  ['/sql-reports', 'SQL Reports'],
];

export default function Navbar() {
  return (
    <nav className="navbar navbar-expand navbar-dark bg-dark">
      <div className="container">
        <span className="navbar-brand">Railway Reservation</span>
        <div className="navbar-nav flex-wrap">
          {links.map(([to, label]) => (
            <NavLink key={to} to={to} end className="nav-link">{label}</NavLink>
          ))}
        </div>
      </div>
    </nav>
  );
}
