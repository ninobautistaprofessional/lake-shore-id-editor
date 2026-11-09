/**
 * Lake Shore Colleges ID Management System
 * Authentication Context
 * Provides global authentication state and session management.
 */

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import type { User, AuthState } from '../types';
import { me, logout as apiLogout } from '../services/api';

interface AuthContextType extends AuthState {
  login: (username: string, password: string) => Promise<boolean>;
  logout: () => Promise<void>;
  refreshSession: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [state, setState] = useState<AuthState>({
    user: null,
    isAuthenticated: false,
    isLoading: true,
    csrfToken: null,
  });

  // Check session on mount
  useEffect(() => {
    checkSession();
  }, []);

  const checkSession = useCallback(async () => {
    try {
      const res = await me();
      if (res.success && res.data?.id) {
        setState({
          user: res.data,
          isAuthenticated: true,
          isLoading: false,
          csrfToken: res.data.csrf_token,
        });
      } else {
        setState({
          user: null,
          isAuthenticated: false,
          isLoading: false,
          csrfToken: null,
        });
      }
    } catch {
      setState({
        user: null,
        isAuthenticated: false,
        isLoading: false,
        csrfToken: null,
      });
    }
  }, []);

  const login = async (username: string, password: string): Promise<boolean> => {
    const res = await me(); // check if already logged in
    if (res.success && res.data?.id) {
      setState({
        user: res.data,
        isAuthenticated: true,
        isLoading: false,
        csrfToken: res.data.csrf_token,
      });
      return true;
    }
    const loginRes = await apiRequestLogin(username, password);
    if (loginRes.success && loginRes.data?.user) {
      setState({
        user: loginRes.data.user,
        isAuthenticated: true,
        isLoading: false,
        csrfToken: loginRes.data.csrf_token,
      });
      return true;
    }
    return false;
  };

  const logout = async () => {
    try {
      await apiLogout();
    } catch {
      // Ignore errors on logout
    }
    setState({
      user: null,
      isAuthenticated: false,
      isLoading: false,
      csrfToken: null,
    });
  };

  const refreshSession = async () => {
    await checkSession();
  };

  return (
    <AuthContext.Provider
      value={{
        ...state,
        login,
        logout,
        refreshSession,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = (): AuthContextType => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

// Helper for login (exported for use in services)
async function apiRequestLogin(
  username: string,
  password: string
): Promise<any> {
  const res = await fetch(
    `${import.meta.env.VITE_API_URL || 'http://localhost/lake-shore-id-editor/backend/api.php'}?action=login`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ username, password }),
      credentials: 'include',
    }
  );
  const data = await res.text();
  let json: any = null;
  try {
    json = JSON.parse(data);
  } catch {
    json = { raw: data };
  }
  return json;
}