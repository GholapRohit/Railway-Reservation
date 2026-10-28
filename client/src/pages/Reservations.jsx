import { useEffect, useState } from 'react';
import api, { errorMessage, today } from '../api.js';

const PAYMENT_METHODS = ['UPI', 'Credit Card', 'Debit Card', 'Net Banking'];
const emptyForm = { passenger_id: '', schedule_id: '', src_station_id: '', dest_station_id: '', seat_number: '' };

export default function Reservations() {
  const [reservations, setReservations] = useState([]);
  const [passengers, setPassengers] = useState([]);
  const [schedules, setSchedules] = useState([]);
  const [halts, setHalts] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [quote, setQuote] = useState(null);       // fare calculated by the server
  const [quoteError, setQuoteError] = useState('');
  const [method, setMethod] = useState('UPI');
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);        // true while ANY request is in progress
  const [cancelId, setCancelId] = useState(null); // reservation whose cancel panel is open
  const [reason, setReason] = useState('');

  const loadReservations = () => api.get('/reservations').then((res) => setReservations(res.data.data));
  const loadSchedules = () => api.get('/schedules').then((res) => setSchedules(res.data.data));

  useEffect(() => {
    Promise.all([
      loadReservations(),
      loadSchedules(),
      api.get('/passengers').then((res) => setPassengers(res.data.data)),
    ]).catch((err) => setNotice({ type: 'danger', text: errorMessage(err) }));
  }, []);

  // When the schedule changes, load its stations in route order
  async function chooseSchedule(scheduleId) {
    setForm({ ...form, schedule_id: scheduleId, src_station_id: '', dest_station_id: '' });
    setHalts([]);
    setQuote(null);
    setQuoteError('');
    if (!scheduleId) return;
    try {
      const res = await api.get(`/schedules/${scheduleId}/halts`);
      setHalts(res.data.data);
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    }
  }

  // When both stations are chosen, ask the server for the fare (the server also validates the route)
  async function chooseStations(next) {
    setForm(next);
    setQuote(null);
    setQuoteError('');
    if (!next.schedule_id || !next.src_station_id || !next.dest_station_id) return;
    try {
      const res = await api.get('/fare', {
        params: { scheduleId: next.schedule_id, sourceStationId: next.src_station_id, destinationStationId: next.dest_station_id },
      });
      setQuote(res.data.data);
    } catch (err) {
      setQuoteError(errorMessage(err));
    }
  }

  // Runs one request: disables the buttons, shows the result, then refreshes the table
  async function run(request, onSuccess) {
    setBusy(true);
    setNotice(null);
    try {
      const res = await request();
      setNotice({ type: 'success', text: res.data.message });
      if (onSuccess) onSuccess(res.data.data);
      await Promise.all([loadReservations(), loadSchedules()]);
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    } finally {
      setBusy(false);
    }
  }

  function book(e) {
    e.preventDefault();
    if (!quote) {
      setNotice({ type: 'danger', text: quoteError || 'Choose a valid route first.' });
      return;
    }
    run(() => api.post('/reservations', form), () => { setForm(emptyForm); setHalts([]); setQuote(null); });
  }

  const pay = (id) => run(() => api.post(`/reservations/${id}/pay`, { payment_method: method }));

  function cancel(e) {
    e.preventDefault();
    run(() => api.post(`/reservations/${cancelId}/cancel`, { reason }), () => { setCancelId(null); setReason(''); });
  }

  return (
    <>
      <h1 className="h3 mb-3">Reservations</h1>
      {notice && <div className={`alert alert-${notice.type}`}>{notice.text}</div>}

      <div className="card mb-4"><div className="card-body">
        <h2 className="h5">New reservation</h2>
        <form onSubmit={book} className="row g-2">
          <div className="col-md-6">
            <select className="form-select" aria-label="Passenger" value={form.passenger_id} onChange={(e) => setForm({ ...form, passenger_id: e.target.value })} required>
              <option value="">Passenger</option>
              {passengers.map((p) => <option key={p.passenger_id} value={p.passenger_id}>{p.name}</option>)}
            </select>
          </div>
          <div className="col-md-6">
            <select className="form-select" aria-label="Schedule" value={form.schedule_id} onChange={(e) => chooseSchedule(e.target.value)} required>
              <option value="">Train and date</option>
              {schedules.map((s) => <option key={s.schedule_id} value={s.schedule_id}>{s.train_number} {s.train_name} - {s.journey_date}</option>)}
            </select>
          </div>
          <div className="col-md-4">
            <select className="form-select" aria-label="Source" value={form.src_station_id} disabled={halts.length === 0} onChange={(e) => chooseStations({ ...form, src_station_id: e.target.value })} required>
              <option value="">Source</option>
              {halts.map((h) => <option key={h.station_id} value={h.station_id}>{h.station_name}</option>)}
            </select>
          </div>
          <div className="col-md-4">
            <select className="form-select" aria-label="Destination" value={form.dest_station_id} disabled={halts.length === 0} onChange={(e) => chooseStations({ ...form, dest_station_id: e.target.value })} required>
              <option value="">Destination</option>
              {halts.map((h) => <option key={h.station_id} value={h.station_id}>{h.station_name}</option>)}
            </select>
          </div>
          <div className="col-md-2"><input className="form-control" type="number" min="1" max="72" placeholder="Seat (1-72)" value={form.seat_number} onChange={(e) => setForm({ ...form, seat_number: e.target.value })} required /></div>
          <div className="col-md-2">
            <input className="form-control" aria-label="Fare" readOnly placeholder="Fare" value={quote ? `Rs. ${quote.fare.toFixed(2)}` : ''} />
          </div>
          {quote && <div className="col-12 text-muted small">{quote.stations_travelled} stations x Rs. {quote.rate_per_station} ({quote.train_type}). The journey date is taken from the schedule.</div>}
          {quoteError && <div className="col-12 text-danger small">{quoteError}</div>}
          <div className="col-12"><button className="btn btn-primary" disabled={busy}>{busy ? 'Please wait...' : 'Create reservation (Pending)'}</button></div>
        </form>
      </div></div>

      <div className="d-flex align-items-center gap-2 mb-2">
        <label htmlFor="method" className="mb-0">Payment method for Pay buttons:</label>
        <select id="method" className="form-select w-auto" value={method} onChange={(e) => setMethod(e.target.value)}>
          {PAYMENT_METHODS.map((m) => <option key={m}>{m}</option>)}
        </select>
      </div>

      {cancelId && (
        <form onSubmit={cancel} className="card card-body mb-3">
          <label className="form-label" htmlFor="reason">Reason for cancelling reservation #{cancelId} (80% of the amount paid is refunded)</label>
          <div className="input-group">
            <input id="reason" className="form-control" minLength="5" maxLength="255" value={reason} onChange={(e) => setReason(e.target.value)} required />
            <button className="btn btn-danger" disabled={busy}>{busy ? 'Please wait...' : 'Confirm cancel'}</button>
            <button type="button" className="btn btn-outline-secondary" disabled={busy} onClick={() => { setCancelId(null); setReason(''); }}>Keep</button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table className="table table-striped table-sm bg-white align-middle">
          <thead>
            <tr><th>ID</th><th>Passenger</th><th>Train</th><th>Route</th><th>Date</th><th>Seat</th><th>Fare</th><th>Status</th><th>Payment</th><th>Cancellation</th><th>Action</th></tr>
          </thead>
          <tbody>
            {reservations.length === 0 && <tr><td colSpan="11" className="text-center text-muted">No reservations yet.</td></tr>}
            {reservations.map((r) => (
              <tr key={r.reservation_id}>
                <td>{r.reservation_id}</td>
                <td>{r.passenger_name}</td>
                <td>{r.train_number} {r.train_name}</td>
                <td>{r.source_code} to {r.destination_code}</td>
                <td>{r.journey_date}</td>
                <td>{r.seat_number}</td>
                <td>{r.total_fare.toFixed(2)}</td>
                <td>{r.reservation_status}</td>
                <td>{r.transaction_id || '-'}</td>
                <td>{r.refund_amount != null ? `Refund ${r.refund_amount.toFixed(2)}` : '-'}</td>
                <td>
                  {r.reservation_status === 'Pending' && r.journey_date >= today() && (
                    <button className="btn btn-sm btn-success" disabled={busy} onClick={() => pay(r.reservation_id)}>Pay</button>
                  )}
                  {r.reservation_status === 'Confirmed' && r.journey_date >= today() && (
                    <button className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => { setCancelId(r.reservation_id); setReason(''); }}>Cancel</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
