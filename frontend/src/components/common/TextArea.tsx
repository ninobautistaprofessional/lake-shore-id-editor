/**
 * Lake Shore Colleges ID Management System
 * Reusable TextArea Component
 */

import type { ChangeEvent } from 'react';

interface TextAreaProps {
  label: string;
  name: string;
  value: string;
  onChange: (e: ChangeEvent<HTMLTextAreaElement>) => void;
  rows?: number;
  full?: boolean;
  error?: string;
  placeholder?: string;
}

export const TextArea: React.FC<TextAreaProps> = ({
  label,
  name,
  value,
  onChange,
  rows = 3,
  full,
  error,
  placeholder,
}) => {
  return (
    <div className={`field ${full ? 'full' : ''}`}>
      <span>{label}</span>
      <textarea
        name={name}
        value={value}
        onChange={onChange}
        rows={rows}
        placeholder={placeholder}
      />
      {error && <span className="field-error">{error}</span>}
    </div>
  );
};