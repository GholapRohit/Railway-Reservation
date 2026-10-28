import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api.js';

export default function SQLReports() {
  const [reports, setReports] = useState([]);
  const [report, setReport] = useState(null);
  const [activeId, setActiveId] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get('/reports')
      .then((res) => setReports(res.data.data))
      .catch((err) => setError(errorMessage(err)));
  }, []);

  async function open(id) {
    setActiveId(id);
    setLoading(true);
    setError('');
    setReport(null);
    try {
      const res = await api.get(`/reports/${id}`);
      setReport(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  return (
    <>
      <h1 className="h3 mb-3">SQL Reports</h1>
      {error && <div className="alert alert-danger">{error}</div>}

      <div className="d-flex flex-wrap gap-2 mb-4">
        {reports.map((r) => (
          <button key={r.id} className={`btn ${activeId === r.id ? 'btn-primary' : 'btn-outline-primary'}`} disabled={loading} onClick={() => open(r.id)}>
            {r.id}. {r.title}
          </button>
        ))}
      </div>

      {loading && <p>Running report...</p>}

      {report && (
        <>
          <h2 className="h5">{report.id}. {report.title}</h2>
          <pre className="sql-box">{report.sql}</pre>
          <p><strong>Concept:</strong> {report.concepts}</p>
          <div className="table-wrap">
            <table className="table table-striped table-sm bg-white">
              <thead><tr>{report.columns.map((c) => <th key={c}>{c}</th>)}</tr></thead>
              <tbody>
                {report.rows.length === 0 && <tr><td colSpan={report.columns.length} className="text-center text-muted">The query returned no rows.</td></tr>}
                {report.rows.map((row, i) => (
                  <tr key={i}>{report.columns.map((c) => <td key={c}>{row[c] === null ? 'NULL' : String(row[c])}</td>)}</tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  );
}
