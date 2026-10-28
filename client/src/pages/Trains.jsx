import { useEffect, useState } from 'react';
import api, { errorMessage, today } from '../api.js';

// Must match the ENUM in schema.sql
const TRAIN_TYPES = ['Rajdhani', 'Duronto', 'Superfast', 'Express', 'Intercity', 'Passenger'];
const emptyTrain = { train_number: '', train_name: '', train_type: 'Express' };

export default function Trains() {
  const [trains, setTrains] = useState([]);
  const [stations, setStations] = useState([]);
  const [form, setForm] = useState(emptyTrain);
  const [notice, setNotice] = useState(null);
  const [busy, setBusy] = useState(false);

  const [query, setQuery] = useState({ sourceStationId: '', destinationStationId: '', journeyDate: '' });
  const [results, setResults] = useState(null); // null = not searched yet
  const [searchNotice, setSearchNotice] = useState(null);
  const [searching, setSearching] = useState(false);

  async function loadTrains() {
    try {
      const res = await api.get('/trains');
      setTrains(res.data.data);
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    }
  }

  useEffect(() => {
    loadTrains();
    api.get('/stations')
      .then((res) => setStations(res.data.data))
      .catch((err) => setNotice({ type: 'danger', text: errorMessage(err) }));
  }, []);

  async function addTrain(e) {
    e.preventDefault();
    setBusy(true);
    setNotice(null);
    try {
      const res = await api.post('/trains', form);
      setNotice({ type: 'success', text: res.data.message });
      setForm(emptyTrain);
      await loadTrains();
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    } finally {
      setBusy(false);
    }
  }

  async function search(e) {
    e.preventDefault();
    setSearching(true);
    setSearchNotice(null);
    setResults(null);
    try {
      const res = await api.get('/search-trains', { params: query });
      setResults(res.data.data);
      if (res.data.data.length === 0) setSearchNotice({ type: 'warning', text: res.data.message });
    } catch (err) {
      setSearchNotice({ type: 'danger', text: errorMessage(err) });
    } finally {
      setSearching(false);
    }
  }

  const stationOptions = stations.map((s) => (
    <option key={s.station_id} value={s.station_id}>{s.station_name} ({s.station_code})</option>
  ));

  return (
    <>
      <h1 className="h3 mb-3">Trains</h1>
      {notice && <div className={`alert alert-${notice.type}`}>{notice.text}</div>}

      <div className="card mb-4"><div className="card-body">
        <h2 className="h5">Add train</h2>
        <form onSubmit={addTrain} className="row g-2">
          <div className="col-md-3"><input className="form-control" placeholder="Train number" value={form.train_number} onChange={(e) => setForm({ ...form, train_number: e.target.value })} required /></div>
          <div className="col-md-4"><input className="form-control" placeholder="Train name" value={form.train_name} onChange={(e) => setForm({ ...form, train_name: e.target.value })} required /></div>
          <div className="col-md-3">
            <select className="form-select" value={form.train_type} onChange={(e) => setForm({ ...form, train_type: e.target.value })}>
              {TRAIN_TYPES.map((t) => <option key={t}>{t}</option>)}
            </select>
          </div>
          <div className="col-md-2"><button className="btn btn-primary w-100" disabled={busy}>{busy ? 'Saving...' : 'Add'}</button></div>
        </form>
      </div></div>

      <div className="table-wrap mb-4">
        <table className="table table-striped table-sm bg-white">
          <thead><tr><th>ID</th><th>Number</th><th>Name</th><th>Type</th></tr></thead>
          <tbody>
            {trains.length === 0 && <tr><td colSpan="4" className="text-center text-muted">No trains yet.</td></tr>}
            {trains.map((t) => (
              <tr key={t.train_id}><td>{t.train_id}</td><td>{t.train_number}</td><td>{t.train_name}</td><td>{t.train_type}</td></tr>
            ))}
          </tbody>
        </table>
      </div>

      <h2 className="h5">Search trains</h2>
      {searchNotice && <div className={`alert alert-${searchNotice.type}`}>{searchNotice.text}</div>}
      <form onSubmit={search} className="row g-2 mb-3">
        <div className="col-md-4">
          <select className="form-select" aria-label="Source station" value={query.sourceStationId} onChange={(e) => setQuery({ ...query, sourceStationId: e.target.value })} required>
            <option value="">Source station</option>{stationOptions}
          </select>
        </div>
        <div className="col-md-4">
          <select className="form-select" aria-label="Destination station" value={query.destinationStationId} onChange={(e) => setQuery({ ...query, destinationStationId: e.target.value })} required>
            <option value="">Destination station</option>{stationOptions}
          </select>
        </div>
        <div className="col-md-2"><input className="form-control" type="date" aria-label="Journey date" min={today()} value={query.journeyDate} onChange={(e) => setQuery({ ...query, journeyDate: e.target.value })} required /></div>
        <div className="col-md-2"><button className="btn btn-success w-100" disabled={searching}>{searching ? 'Searching...' : 'Search'}</button></div>
      </form>

      {results && results.length > 0 && (
        <div className="table-wrap">
          <table className="table table-striped table-sm bg-white">
            <thead><tr><th>Train no.</th><th>Train name</th><th>Date</th><th>Source</th><th>Departure</th><th>Destination</th><th>Arrival</th></tr></thead>
            <tbody>
              {results.map((r) => (
                <tr key={r.schedule_id}>
                  <td>{r.train_number}</td><td>{r.train_name}</td><td>{r.journey_date}</td>
                  <td>{r.source_station}</td><td>{r.source_departure}</td>
                  <td>{r.destination_station}</td><td>{r.destination_arrival}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
