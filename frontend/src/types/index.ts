/**
 * Lake Shore Colleges ID Management System
 * TypeScript Type Definitions
 * Centralized type declarations for the entire frontend application.
 */

/** ---------------------------------------------------------------
 *  Authentication & Session
 * --------------------------------------------------------------- */
export type UserRole = 'admin' | 'staff';

export interface User {
  id: number;
  username: string;
  full_name: string;
  role: UserRole;
}

export interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  csrfToken: string | null;
}

export interface LoginCredentials {
  username: string;
  password: string;
}

export interface LoginResponse {
  success: boolean;
  message?: string;
  data?: User;
  csrf_token?: string;
}

/** ---------------------------------------------------------------
 *  ID Card / Student Record
 * --------------------------------------------------------------- */
export type IdType = 'COLLEGE' | 'JUNIOR_HIGH' | 'SENIOR_HIGH';

export type CardStatus = 'created' | 'done' | 'edited' | 'printed' | 'released';

export type BackgroundMode = 'transparent' | 'white' | 'custom';

export interface Signatory {
  id: number;
  full_name: string;
  position_title: string;
  signature_path: string;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface IdCard {
  id: number;
  student_name: string;
  id_type: IdType;
  course: string;
  grade_level: string;
  section_name: string;
  student_number: string;
  student_id_number: string;
  lrn: string;
  academic_year: string;
  school_year: string;
  photo_path: string;
  original_photo_path: string;
  photo_processing_status: string;
  photo_processed_at: string | null;
  photo_crop: string;
  background_mode: BackgroundMode;
  background_color: string;
  address_line1: string;
  address_line2: string;
  emergency_label: string;
  emergency_contact: string;
  emergency_phone: string;
  terms_title: string;
  term_1: string | null;
  term_2: string | null;
  term_3: string | null;
  institution_name: string;
  institution_address: string;
  mobile_no: string;
  telephone_no: string;
  email_address: string;
  signatory_id: number | null;
  signatory_name: string;
  signature_path: string;
  template_id: number | null;
  template_version: number | null;
  status: CardStatus;
  printed_at: string | null;
  print_count: number;
  released_by: number | null;
  released_at: string | null;
  release_notes: string | null;
  student_received: number;
  created_at: string;
  updated_at: string;
}

export interface CardFormData {
  student_name: string;
  id_type: IdType;
  course: string;
  grade_level: string;
  section_name: string;
  student_number: string;
  student_id_number: string;
  lrn: string;
  academic_year: string;
  school_year: string;
  photo_path: string;
  preferred_photo_id: number | null;
  address_line1: string;
  address_line2: string;
  emergency_label: string;
  emergency_contact: string;
  emergency_phone: string;
  terms_title: string;
  term_1: string;
  term_2: string;
  term_3: string;
  institution_name: string;
  institution_address: string;
  mobile_no: string;
  telephone_no: string;
  email_address: string;
  signatory_id: number | null;
  signatory_name: string;
  signature_path: string;
  template_id: number | null;
  original_photo_path: string;
  photo_processing_status: string;
  photo_processed_at: string | null;
  photo_crop: string;
  background_mode: BackgroundMode;
  background_color: string;
  existing_card_id: number | null;
}

export interface CardLookupResult {
  id: number;
  student_name: string;
  student_number: string;
  student_id_number: string;
  lrn: string;
  id_type: IdType;
  course: string;
  grade_level: string;
  section_name: string;
  academic_year: string;
  school_year: string;
}

/** ---------------------------------------------------------------
 *  Photo Library
 * --------------------------------------------------------------- */
export type PhotoType = 'ORIGINAL' | 'PROCESSED';
export type PhotoSource = 'CREATE_ID' | 'PHOTO_PROCESSING' | 'UPLOAD';
export type PhotoProcessingStatus = 'original' | 'processed' | 'uploaded' | 'failed';

export interface StudentPhoto {
  id: number;
  student_id: number;
  student_name?: string;
  student_id_number?: string;
  student_number?: string;
  lrn?: string;
  id_type?: IdType;
  course?: string;
  grade_level?: string;
  section_name?: string;
  source: PhotoSource;
  type: PhotoType;
  file_path: string;
  mime_type: string;
  file_size: number;
  width: number;
  height: number;
  background_info: { mode: BackgroundMode; color: string } | null;
  crop_data: any | null;
  processing_status: PhotoProcessingStatus;
  processed_at: string | null;
  is_active: number;
  created_at: string;
  updated_at: string;
}

export interface PhotoLibraryFilters {
  source?: string;
  type?: string;
  is_active?: string;
  processing_status?: string;
  q?: string;
  id_type?: string;
}

export interface PhotoLibraryResult {
  data: StudentPhoto[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

/** ---------------------------------------------------------------
 *  Template
 * --------------------------------------------------------------- */
export interface IdTemplate {
  id: number;
  name: string;
  version: number;
  parent_id: number | null;
  id_type: IdType;
  front_image: string;
  back_image: string;
  fields_json: string | null;
  photo_processing_mode: string;
  is_active: number;
  is_system: number;
  active_dept: string | null;
  created_at: string;
  updated_at: string;
}

/** ---------------------------------------------------------------
 *  Dashboard / Statistics
 * --------------------------------------------------------------- */
export interface DashboardStats {
  total_cards: number;
  created: number;
  done: number;
  edited: number;
  printed: number;
  released: number;
  by_type: Record<IdType, number>;
  recent_activity: IdCard[];
}

/** ---------------------------------------------------------------
 *  Import / Export
 * --------------------------------------------------------------- */
export interface ImportRow {
  sheet: string;
  row: number;
  status: 'valid' | 'invalid' | 'duplicate';
  errors: string[];
  warnings: string[];
  data: any;
}

export interface ImportPreview {
  rows: ImportRow[];
  counts: { valid: number; invalid: number; duplicate: number };
  sheet_errors: string[];
}

export interface ExportResult {
  success: boolean;
  message: string;
  data?: any;
}

/** ---------------------------------------------------------------
 *  Audit Log
 * --------------------------------------------------------------- */
export interface AuditLogEntry {
  id: number;
  user_id: number | null;
  action: string;
  entity_type: string;
  entity_id: number | null;
  old_value: string | null;
  new_value: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
}

/** ---------------------------------------------------------------
 *  Lost ID Request
 * --------------------------------------------------------------- */
export interface LostIdRequest {
  id: number;
  student_name: string;
  id_type: IdType;
  course: string;
  grade_level: string;
  section_name: string;
  student_number: string;
  lrn: string;
  reference_card_id: number;
  status: string;
  receipt_path: string;
  created_at: string;
}

/** ---------------------------------------------------------------
 *  Print History
 * --------------------------------------------------------------- */
export interface PrintHistoryEntry {
  id: number;
  card_id: number;
  user_id: number | null;
  print_type: 'original' | 'reprint';
  reason: string;
  printed_at: string;
  created_at: string;
}

/** ---------------------------------------------------------------
 *  API Response
 * --------------------------------------------------------------- */
export interface ApiResponse<T = any> {
  success: boolean;
  message?: string;
  data?: T;
  error?: string;
  csrf_token?: string;
  retry_after?: number;
  captcha_required?: boolean;
}

/** ---------------------------------------------------------------
 *  UI / Form
 * --------------------------------------------------------------- */
export interface FormFieldError {
  field: string;
  message: string;
}

export interface ValidationResult {
  valid: boolean;
  errors: FormFieldError[];
}

export interface PaginationParams {
  page: number;
  per_page: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

/** ---------------------------------------------------------------
 *  Card Zone / Template Designer
 * --------------------------------------------------------------- */
export interface CardZone {
  key: string;
  label: string;
  kind: 'text' | 'select' | 'textarea' | 'upload' | 'photo' | 'signature';
  required?: boolean;
  placeholder?: string;
  options?: string[];
}