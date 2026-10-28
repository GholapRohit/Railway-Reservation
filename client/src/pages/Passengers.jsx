import { useEffect, useState } from 'react';
import api, { errorMessage, today } from '../api.js';

const emptyForm = { name: '', email: '', phone: '', gender: 'Male', dob: '' };

export default function Passengers() {
  const [passengers, setPassengers] = useState([]);
  const [search, setSearch] = useState('');
  const [form, setForm] = useState(emptyForm);
  const [notice, setNotice] = useState(null); // { type: 'success' | 'danger', text }
  const [busy, setBusy] = useState(false);

  async function load(name = '') {
    try {
      const res = await api.get('/passengers', { params: { search: name } });
      setPassengers(res.data.data);
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    }
  }

  useEffect(() => { load(); }, []);

  const change = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  async function add(e) {
    e.preventDefault();
    setBusy(true);
    setNotice(null);
    try {
      const res = await api.post('/passengers', form);
      setNotice({ type: 'success', text: res.data.message });
      setForm(emptyForm);
      setSearch('');
      await load();
    } catch (err) {
      setNotice({ type: 'danger', text: errorMessage(err) });
    } finally {
      setBusy(false);
    }
  }

  function runSearch(e) {
    e.preventDefault();
    setNotice(null);
    load(search.trim());
  }

  return (
    <>
      <h1 className="h3 mb-3">Passengers</h1>
      {notice && <div className={`alert alert-${notice.type}`}>{notice.text}</div>}

      <div className="card mb-4"><div className="card-body">
        <h2 className="h5">Add passenger</h2>
        <form onSubmit={add} className="row g-2">
          <div className="col-md-4"><input className="form-control" name="name" placeholder="Name" value={form.name} onChange={change} required /></div>
          <div className="col-md-4"><input className="form-control" type="email" name="email" placeholder="Email" value={form.email} onChange={change} required /></div>
          <div className="col-md-4"><input className="form-control" name="phone" placeholder="Phone (10-15 digits)" value={form.phone} onChange={change} required /></div>
          <div className="col-md-4">
            <select className="form-select" name="gender" value={form.gender} onChange={change}>
              <option>Male</option><option>Female</option><option>Other</option>
            </select>
          </div>
          <div className="col-md-4"><input className="form-control" type="date" name="dob" max={today()} value={form.dob} onChange={change} required /></div>
          <div className="col-md-4"><button className="btn btn-primary w-100" disabled={busy}>{busy ? 'Saving...' : 'Add passenger'}</button></div>
        </form>
      </div></div>

      <form onSubmit={runSearch} className="input-group mb-3">
        <input className="form-control" placeholder="Search by name" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="btn btn-outline-secondary">Search</button>
      </form>

      <div className="table-wrap">
        <table className="table table-striped table-sm bg-white">
          <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Gender</th><th>DOB</th><th>Age</th></tr></thead>
          <tbody>
            {passengers.length === 0 && <tr><td colSpan="7" className="text-center text-muted">No passengers found.</td></tr>}
            {passengers.map((p) => (
              <tr key={p.passenger_id}>
                <td>{p.passenger_id}</td><td>{p.name}</td><td>{p.email}</td><td>{p.phone}</td>
                <td>{p.gender}</td><td>{p.dob}</td><td>{p.age}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
