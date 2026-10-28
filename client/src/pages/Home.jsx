import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api.js';

export default function Home() {
  const [counts, setCounts] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/dashboard')
      .then((res) => setCounts(res.data.data))
      .catch((err) => setError(errorMessage(err)));
  }, []);

  const cards = counts
    ? [['Passengers', counts.passengers], ['Trains', counts.trains], ['Reservations', counts.reservations]]
    : [];

  return (
    <>
      <h1 className="h3 mb-3">Railway Reservation System</h1>
      {error && <div className="alert alert-danger">{error}</div>}
      {!counts && !error && <p>Loading...</p>}
      <div className="row g-3">
        {cards.map(([label, value]) => (
          <div className="col-md-4" key={label}>
            <div className="card stat-card shadow-sm">
              <div className="card-body">
                <div className="text-muted">{label}</div>
                <div className="stat-number">{value}</div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </>
  );
}
