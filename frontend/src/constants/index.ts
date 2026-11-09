/**
 * Lake Shore Colleges ID Management System
 * Centralized Constants & Configuration
 * All application-wide constants, configuration values, and static data.
 */

/** ---------------------------------------------------------------
 *  API Configuration
 * --------------------------------------------------------------- */
export const API_URL =
  import.meta.env.VITE_API_URL ||
  'http://localhost/lake-shore-id-editor/backend/api.php';

export const BACKEND_URL = API_URL.replace(/\/api\.php(?:\?.*)?$/, '');

export const REQUEST_TIMEOUT = 30000;

/** ---------------------------------------------------------------
 *  ID Type / Department Configuration
 * --------------------------------------------------------------- */
export const ID_TYPES: { value: string; title: string; description: string }[] = [
  {
    value: 'COLLEGE',
    title: 'College Student',
    description: 'Currently enrolled in a college degree program.',
  },
  {
    value: 'JUNIOR_HIGH',
    title: 'Basic Education - Junior High School Student',
    description: 'Grade 7 to Grade 10.',
  },
  {
    value: 'SENIOR_HIGH',
    title: 'Basic Education - Senior High School Student',
    description: 'Grade 11 to Grade 12.',
  },
];

export const TYPE_TITLES: Record<string, string> = {
  COLLEGE: 'College Department',
  JUNIOR_HIGH: 'Basic Education - Junior High School',
  SENIOR_HIGH: 'Basic Education - Senior High School',
};

/** ---------------------------------------------------------------
 *  College Courses (single source of truth — synced with backend)
 * --------------------------------------------------------------- */
export const COLLEGE_COURSES = [
  'BACHELOR OF SCIENCE IN PSYCHOLOGY',
  'BACHELOR OF SPECIAL NEEDS EDUCATION',
  'BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION',
  'BACHELOR OF SCIENCE IN ACCOUNTANCY',
  'BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT',
  'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT',
  'BACHELOR OF SCIENCE IN MANAGEMENT ACCOUNTING',
  'BACHELOR OF SCIENCE IN CRIMINOLOGY',
];

/** ---------------------------------------------------------------
 *  Grade Levels
 * --------------------------------------------------------------- */
export const JUNIOR_HIGH_GRADES = ['GRADE 7', 'GRADE 8', 'GRADE 9', 'GRADE 10'];
export const SENIOR_HIGH_GRADES = ['GRADE 11', 'GRADE 12'];

/** ---------------------------------------------------------------
 *  Grade Color Coding (Basic Ed name bands)
 * --------------------------------------------------------------- */
export const GRADE_COLORS: Record<string, string> = {
  'GRADE 7': '#205f38',
  'GRADE 8': '#f0b51f',
  'GRADE 9': '#1968c7',
  'GRADE 10': '#f01616',
  'GRADE 11': '#850074',
  'GRADE 12': '#f47b20',
};

/** ---------------------------------------------------------------
 *  ID Status Lifecycle
 *  created -> done -> edited / printed -> released
 * --------------------------------------------------------------- */
export const CARD_STATUS_LABELS: Record<string, string> = {
  created: 'Created',
  done: 'Done',
  edited: 'Edited',
  printed: 'Printed',
  released: 'Released',
};

export const cardStatusLabel = (status: string): string =>
  CARD_STATUS_LABELS[status] || 'Created';

/** ---------------------------------------------------------------
 *  Department Signatories
 * --------------------------------------------------------------- */
export const DEPARTMENT_SIGNATORIES: Record<string, string> = {
  COLLEGE: 'Sherill S. Villaluz',
  JUNIOR_HIGH: 'Annabelle V. Molina',
  SENIOR_HIGH: 'Annabelle V. Molina',
};

/** ---------------------------------------------------------------
 *  Background Modes
 * --------------------------------------------------------------- */
export const BACKGROUND_MODES = [
  { value: 'transparent', label: 'Transparent' },
  { value: 'white', label: 'White' },
  { value: 'custom', label: 'Custom Color' },
];

/** ---------------------------------------------------------------
 *  Default ID Card (empty template)
 * --------------------------------------------------------------- */
export const EMPTY_CARD = {
  student_name: '',
  id_type: 'COLLEGE',
  course: COLLEGE_COURSES[0] ?? '',
  grade_level: 'GRADE 7',
  section_name: '',
  student_number: '',
  student_id_number: '',
  lrn: '',
  academic_year: '2026-2027',
  school_year: '2026-2027',
  photo_path: '',
  original_photo_path: '',
  photo_processing_status: 'original',
  photo_processed_at: null,
  photo_crop: '',
  background_mode: 'transparent',
  background_color: '#FFFFFF',
  address_line1: '',
  address_line2: '',
  emergency_label: 'In case of emergency, please notify',
  emergency_contact: '',
  emergency_phone: '',
  terms_title: 'Terms and Conditions',
  term_1: '',
  term_2: '',
  term_3: '',
  institution_name: 'Lake Shore Colleges',
  institution_address: '',
  mobile_no: '',
  telephone_no: '',
  email_address: '',
  signatory_id: null,
  signatory_name: '',
  signature_path: '',
  template_id: null,
  template_version: null,
  status: 'created',
  printed_at: null,
  print_count: 0,
  released_by: null,
  released_at: null,
  release_notes: null,
  student_received: 0,
  created_at: '',
  updated_at: '',
};

/** ---------------------------------------------------------------
 *  Public Lookup Fields (must mirror backend studentLookupColumns())
 * --------------------------------------------------------------- */
export const PUBLIC_LOOKUP_FIELDS = [
  'student_name',
  'id_type',
  'course',
  'grade_level',
  'section_name',
  'student_number',
  'student_id_number',
  'lrn',
  'academic_year',
  'school_year',
];

/** ---------------------------------------------------------------
 *  Bulk Operation Limits
 * --------------------------------------------------------------- */
export const MAX_BULK_PRINT = 50;
export const MAX_BULK_GENERATE = 100;
export const IMPORT_MAX_ROWS = 5000;

/** ---------------------------------------------------------------
 *  Image / Photo Constraints
 * --------------------------------------------------------------- */
export const MAX_PHOTO_FILE_SIZE = 10485760; // 10 MB
export const MIN_PHOTO_DIMENSION = 96;
export const MAX_PHOTO_DIMENSION = 12000;
export const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
export const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

/** ---------------------------------------------------------------
 *  ID Card Dimensions (CR80 at 600 DPI)
 * --------------------------------------------------------------- */
export const ID_CARD = {
  width_mm: 54,
  height_mm: 85.6,
  dpi: 600,
  width_px: 1276,
  height_px: 2019,
};

/** ---------------------------------------------------------------
 *  UI / Pagination
 * --------------------------------------------------------------- */
export const DEFAULT_PAGE_SIZE = 20;
export const DEFAULT_IMPORT_YEAR = '2026-2027';

/** ---------------------------------------------------------------
 *  Local Storage Keys
 * --------------------------------------------------------------- */
export const STORAGE_KEYS = {
  THEME: 'lsc_theme',
  LAST_DEPARTMENT: 'lsc_last_department',
  PHOTO_PREFERENCES: 'lsc_photo_preferences',
};