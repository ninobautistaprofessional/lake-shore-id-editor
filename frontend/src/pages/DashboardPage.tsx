/**
 * Lake Shore Colleges ID Management System
 * Dashboard Page
 * Overview statistics, recent activity, and quick actions.
 */

import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import {
  getDashboardStats,
  listCards,
  listSignatories,
} from '../services/api';
import { cardStatusLabel, TYPE_TITLES } from '../constants';
import { Card, Input, Button, Alert } from '../components/common';
import type { IdCard } from '../types';

interface DashboardPageProps {}

const DASH_STATUS_OPTIONS: [string, string][] = [
  ['', 'All statuses'],
  ['created', 'Created'],
  ['done', 'Done'],
  ['edited', 'Edited'],
  ['printed', 'Printed'],
  ['released', 'Released'],
];

const EXPORT_STATUS_LABELS: Record<string, string> = {
  created: 'Created',
  done: 'Done',
  edited: 'Edited',
  printed: 'Printed',
  released: 'Released',
};

const DashboardPage: React.FC<DashboardPageProps> = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [cards, setCards] = useState<IdCard[]>([]);
  const [filter, setFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [error, setError] = useState('');

  const loadStats = async () => {
    setLoading(true);
    setError('');
    try {
      const r = await getDashboardStats();
      if (r.success) setStats(r.data);
      else setError(r.message || 'Failed to load dashboard statistics');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load dashboard statistics');
    } finally {
      setLoading(false);
    }
  };

  const loadCards = async () => {
    try {
      const r = await listCards({ page: 1, per_page: 10, q: filter, status: statusFilter || undefined });
      if (r.success && r.data) setCards(r.data.data);
    } catch (e) {
      /* non-fatal; recent activity is supplemental */
    }
  };

  useEffect(() => {
    loadStats();
    loadCards();
  }, [filter, statusFilter]);

  if (loading && !stats) {
    return (
      <div className="dashboard-shell">
        <div className="dashboard-loading">Loading dashboard…</div>
      </div>
    );
  }

  const counts = {
    COLLEGE: cards.filter((x) => x.id_type === 'COLLEGE').length,
    JUNIOR_HIGH: cards.filter((x) => x.id_type === 'JUNIOR_HIGH').length,
    SENIOR_HIGH: cards.filter((x) => x.id_type === 'SENIOR_HIGH').length,
  };

  const recent = stats?.recent_activity || [];

  return (
    <div className="dashboard-shell">
      <div className="dashboard-header">
        <h1>Dashboard</h1>
        <p>Overview of student ID records across all departments.</p>
      </div>

      {error && <Alert type="error" message={error} dismissible onDismiss={() => setError('')} />}

      <div className="dashboard-stats">
        <div className="stat-card">
          <div className="stat-value">{stats?.total_cards ?? 0}</div>
          <div className="stat-label">Total ID Records</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{stats?.created ?? 0}</div>
          <div className="stat-label">Created</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{stats?.done ?? 0}</div>
          <div className="stat-label">Done</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{stats?.edited ?? 0}</div>
          <div className="stat-label">Edited</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{stats?.printed ?? 0}</div>
          <div className="stat-label">Printed</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{stats?.released ?? 0}</div>
          <div className="stat-label">Released</div>
        </div>
      </div>

      <div className="dashboard-section">
        <div className="section-header">
          <h2>Recent Activity</h2>
          <div className="section-filters">
            <Input
              label="Search"
              name="search"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              placeholder="Search by name, ID, or LRN..."
              full
            />
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="status-select"
            >
              {DASH_STATUS_OPTIONS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="records-table-wrap">
          <table className="records-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Type</th>
                <th>ID Number</th>
                <th>Status</th>
                <th>Grade / Course</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {recent.length > 0 ? (
                recent.map((card: IdCard) => (
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
                        {EXPORT_STATUS_LABELS[card.status] || cardStatusLabel(card.status)}
                      </span>
                    </td>
                    <td>
                      {card.grade_level || card.course || '—'}
                    </td>
                    <td>
                      {new Date(card.created_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={6} className="records-empty">
                    No recent activity. Create your first ID to get started.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="dashboard-quick-actions">
        <h2>Quick Actions</h2>
        <div className="quick-action-grid">
          <Card title="College Student" action={
            <Button
              variant="primary"
              onClick={() => {
                // Handled by parent App via view switch
              }}
              disabled
            >
              Create ID
            </Button>
          }>
            <p>Create college student IDs with course, student number and photo.</p>
          </Card>
          <Card title="Junior High School" action={
            <Button variant="primary" disabled>Create ID</Button>
          }>
            <p>Grade 7 to Grade 10 with color-coded name bands.</p>
          </Card>
          <Card title="Senior High School" action={
            <Button variant="primary" disabled>Create ID</Button>
          }>
            <p>Grade 11 and Grade 12 with separate color-coded layouts.</p>
          </Card>
          <Card title="Saved IDs" action={
            <Button variant="primary" disabled>View Saved IDs</Button>
          }>
            <p>View, search, edit and manage student IDs already saved.</p>
          </Card>
          {user?.role === 'admin' && (
            <Card title="Lost ID Requests" action={
              <Button variant="primary" disabled>View Requests</Button>
            }>
              <p>Reprint requests for lost student IDs.</p>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
};

export default DashboardPage;
