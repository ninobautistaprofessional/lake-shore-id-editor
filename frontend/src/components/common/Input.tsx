/**
 * Lake Shore Colleges ID Management System
 * Reusable Input Component
 */

import type { ChangeEvent } from 'react';

interface InputProps {
  id?: string;
  label: string;
  name: string;
  value: string;
  onChange: (e: ChangeEvent<HTMLInputElement>) => void;
  type?: string;
  placeholder?: string;
  full?: boolean;
  error?: string;
  disabled?: boolean;
  autoComplete?: string;
  required?: boolean;
  readOnly?: boolean;
  maxLength?: number;
  minLength?: number;
  accept?: string;
  onBlur?: (e: React.FocusEvent<HTMLInputElement>) => void;
}

export const Input: React.FC<InputProps> = ({
  id,
  label,
  name,
  value,
  onChange,
  type = 'text',
  placeholder,
  full,
  error,
  disabled,
  autoComplete,
  required,
  readOnly,
  maxLength,
  minLength,
  accept,
  onBlur,
}) => {
  const inputId = id || name;
  return (
    <div className={`field ${full ? 'full' : ''}`}>
      <label htmlFor={inputId}>{label}</label>
      <input
        id={inputId}
        type={type}
        name={name}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        disabled={disabled}
        autoComplete={autoComplete}
        required={required}
        readOnly={readOnly}
        maxLength={maxLength}
        minLength={minLength}
        accept={accept}
        onBlur={onBlur}
      />
      {error && <span className="field-error">{error}</span>}
    </div>
  );
};
