/**
 * Lake Shore Colleges ID Management System
 * Records Page
 * Search, filter, and manage saved student ID records.
 */

import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import {
  listCards,
  markCardDone,
  markCardPrinted,
  releaseCard,
  deleteCard,
  listSignatories,
} from '../services/api';
import { cardStatusLabel, TYPE_TITLES } from '../constants';
import { Card, Input, Button, Alert } from '../components/common';
import type { IdCard } from '../types';

const STATUS_LABELS: Record<string, string> = {
  created: 'Created',
  done: 'Done',
  edited: 'Edited',
  printed: 'Printed',
  released: 'Released',
};

interface RecordsPageProps {}

const RecordsPage: React.FC<RecordsPageProps> = () => {
  const { user } = useAuth();
  const [records, setRecords] = useState<IdCard[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [idTypeFilter, setIdTypeFilter] = useState('');

  const loadRecords = async () => {
    setLoading(true);
    setError('');
    try {
      const r = await listCards({
        page: 1,
        per_page: 50,
        q: filter || undefined,
        status: statusFilter || undefined,
        id_type: idTypeFilter || undefined,
      });
      if (r.success && r.data) setRecords(r.data.data);
      else setError(r.message || 'Failed to load records');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load records');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadRecords();
  }, [filter, statusFilter, idTypeFilter]);

  const shown = records.filter(
    (x) =>
      (!statusFilter || x.status === statusFilter) &&
      (!idTypeFilter || x.id_type === idTypeFilter) &&
      `${x.student_name} ${x.student_number} ${x.student_id_number} ${x.lrn} ${x.course} ${x.grade_level} ${x.section_name}`
        .toLowerCase()
        .includes(filter.toLowerCase()),
  );

  const handleMarkDone = async (id: number) => {
    try {
      await markCardDone(id);
      setRecords((prev) =>
        prev.map((r) => (r.id === id ? { ...r, status: 'done' } : r)),
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to mark as done');
    }
  };

  const handleMarkPrinted = async (id: number) => {
    try {
      await markCardPrinted(id);
      setRecords((prev) =>
        prev.map((r) => (r.id === id ? { ...r, status: 'printed' } : r)),
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to mark as printed');
    }
  };

  const handleRelease = async (id: number) => {
    if (!window.confirm('Release this ID to the student?')) return;
    try {
      await releaseCard(id, '', false);
      setRecords((prev) =>
        prev.map((r) => (r.id === id ? { ...r, status: 'released' } : r)),
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to release ID');
    }
  };

  const handleDelete = async (id: number) => {
    if (!window.confirm('Delete this ID record?')) return;
    try {
      await deleteCard(id);
      setRecords((prev) => prev.filter((r) => r.id !== id));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to delete record');
    }
  };

  return (
    <div className="records-shell">
      <Card title="Saved IDs">
        {error && <Alert type="error" message={error} dismissible onDismiss={() => setError('')} />}

        <div className="record-toolbar">
          <Input
            label="Search"
            name="search"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder="Search by name, ID, or LRN..."
            full
          />
          <div className="toolbar-filters">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="filter-select"
            >
              <option value="all">All Status</option>
              <option value="created">Created</option>
              <option value="done">Done</option>
              <option value="edited">Edited</option>
              <option value="printed">Printed</option>
              <option value="released">Released</option>
            </select>
            <select
              value={idTypeFilter}
              onChange={(e) => setIdTypeFilter(e.target.value)}
              className="filter-select"
            >
              <option value="all">All Types</option>
              <option value="COLLEGE">College</option>
              <option value="JUNIOR_HIGH">Junior High</option>
              <option value="SENIOR_HIGH">Senior High</option>
            </select>
            <Button variant="primary" onClick={loadRecords}>
              Refresh
            </Button>
          </div>
        </div>

        <div className="records-table-wrap">
          {loading ? (
            <div className="records-loading">Loading records…</div>
          ) : error ? (
            <div className="records-error">{error}</div>
          ) : shown.length === 0 ? (
            <div className="records-empty-state">
              <p>No records found.</p>
            </div>
          ) : (
            <table className="records-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>ID Number</th>
                  <th>Status</th>
                  <th>Grade / Course</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((card) => (
                  <tr key={card.id}>
                    <td className="record-cell-name">
                      {card.student_name || '# ' + card.id}
                    </td>
                    <td>
                      <span className={`badge badge-type ${card.id_type.toLowerCase()}`}>
                        {TYPE_TITLES[card.id_type] || card.id_type}
                      </span>
                    </td>
                    <td>
                      {card.student_number || card.student_id_number || '—'}
                    </td>
                    <td>
                      <span className={`badge badge-status status-${card.status}`}>
                        {STATUS_LABELS[card.status] || cardStatusLabel(card.status)}
                      </span>
                    </td>
                    <td>
                      {card.grade_level || card.course || '—'}
                    </td>
                    <td className="record-actions">
                      {card.status === 'created' && (
                        <Button
                          variant="secondary"
                          size="sm"
                          onClick={() => handleMarkDone(card.id)}
                        >
                          Mark Done
                        </Button>
                      )}
                      {card.status === 'done' && (
                        <Button
                          variant="secondary"
                          size="sm"
                          onClick={() => handleMarkPrinted(card.id)}
                        >
                          Mark Printed
                        </Button>
                      )}
                      {card.status === 'printed' && (
                        <Button
                          variant="secondary"
                          size="sm"
                          onClick={() => handleRelease(card.id)}
                        >
                          Release
                        </Button>
                      )}
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => handleDelete(card.id)}
                      >
                        Delete
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
        <div className="records-footer">
          <span>{shown.length} record{shown.length !== 1 ? 's' : ''} shown</span>
        </div>
      </Card>
    </div>
  );
};

export default RecordsPage;
