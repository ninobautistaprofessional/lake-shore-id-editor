/**
 * Lake Shore Colleges ID Management System
 * Login Page Component
 */

import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { Card, Input, Button, Alert } from '../components/common';

const LoginPage: React.FC = () => {
  const { login, isLoading } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setIsSubmitting(true);

    try {
      const success = await login(username, password);
      if (!success) {
        setError('Invalid username or password.');
      }
    } catch {
      setError('An unexpected error occurred. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="login-shell">
      <Card title="Staff Sign In" action={null}>
        <p className="login-help">
          Enter your credentials to access the ID Management System
        </p>

        {error && <Alert type="error" message={error} />}

        <form onSubmit={handleSubmit} noValidate>
          <Input
            label="Username"
            name="username"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            full
            autoComplete="username"
            disabled={isSubmitting}
            error={username && !username.includes('@') && username?.length > 0 ? undefined : undefined}
          />

          <Input
            label="Password"
            name="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            full
            autoComplete="current-password"
            disabled={isSubmitting}
          />

          <Button
            type="submit"
            variant="primary"
            full
            loading={isSubmitting}
            disabled={isSubmitting}
          >
            Sign In
          </Button>
        </form>

        <p className="login-foot">
          &copy; {new Date().getFullYear()} Lake Shore Colleges ID Management System
        </p>
      </Card>
    </div>
  );
};

export default LoginPage;