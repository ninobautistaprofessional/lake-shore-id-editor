/**
 * Lake Shore Colleges ID Management System
 * Reusable Select Component
 */

import type { ChangeEvent } from 'react';

interface SelectProps {
  label: string;
  name: string;
  value: string;
  onChange: (e: ChangeEvent<HTMLSelectElement>) => void;
  children: React.ReactNode;
  full?: boolean;
  error?: string;
  disabled?: boolean;
}

export const Select: React.FC<SelectProps> = ({
  label,
  name,
  value,
  onChange,
  children,
  full,
  error,
  disabled,
}) => {
  return (
    <div className={`field ${full ? 'full' : ''}`}>
      <span>{label}</span>
      <select
        name={name}
        value={value}
        onChange={onChange}
        disabled={disabled}
      >
        {children}
      </select>
      {error && <span className="field-error">{error}</span>}
    </div>
  );
};