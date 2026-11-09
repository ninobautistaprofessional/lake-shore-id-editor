/**
 * Lake Shore Colleges ID Management System
 * Centralized API Service Layer
 * All REST API calls go through this module. Provides consistent
 * request/response handling, error handling, CSRF tokens, and
 * session expiration detection.
 */

import type {
  ApiResponse,
  IdCard,
  CardFormData,
  CardLookupResult,
  StudentPhoto,
  PhotoLibraryResult,
  PhotoLibraryFilters,
  IdTemplate,
  DashboardStats,
  ImportPreview,
  ImportRow,
  LostIdRequest,
  PrintHistoryEntry,
  AuditLogEntry,
  Signatory,
  PaginationParams,
  PaginatedResponse,
} from '../types';

const API_URL =
  import.meta.env.VITE_API_URL ||
  'http://localhost/lake-shore-id-editor/backend/api.php';

let csrfToken: string | null = null;

export const setCsrfToken = (token: string | null) => {
  csrfToken = token;
};

export const getCsrfToken = () => csrfToken;

export interface RequestOptions extends RequestInit {
  includeCsrf?: boolean;
}

const buildHeaders = (includeCsrf: boolean = false): HeadersInit => {
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (includeCsrf && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  return headers;
};

interface ApiJsonBody {
  success?: boolean;
  message?: string;
  data?: any;
  error?: string;
  csrf_token?: string;
  retry_after?: number;
  captcha_required?: boolean;
  [key: string]: any;
}

const handleResponse = async <T>(
  response: Response
): Promise<ApiResponse<T>> => {
  const bodyText = await response.text();
  let data: ApiJsonBody | null = null;

  if (bodyText) {
    try {
      data = JSON.parse(bodyText);
    } catch {
      data = { raw: bodyText };
    }
  }

  if (!response.ok) {
    return {
      success: false,
      message: data?.message || `HTTP ${response.status}`,
      error: data?.error,
      status: response.status as any,
    } as ApiResponse<T>;
  }

  return {
    success: data?.success ?? true,
    message: data?.message,
    data: data?.data ?? data,
    csrf_token: data?.csrf_token ?? undefined,
    retry_after: data?.retry_after,
    captcha_required: data?.captcha_required,
  } as ApiResponse<T>;
};

export const apiRequest = async <T>(
  endpoint: string,
  options: RequestOptions = {}
): Promise<ApiResponse<T>> => {
  const { includeCsrf, ...fetchOptions } = options;
  const url = endpoint.startsWith('http') ? endpoint : `${API_URL}?${endpoint}`;
  const headers = buildHeaders(includeCsrf);

  const config: RequestInit = {
    ...fetchOptions,
    headers: {
      ...headers,
      ...fetchOptions.headers,
    },
    credentials: 'include',
  };

  try {
    const response = await fetch(url, config);
    return handleResponse<T>(response);
  } catch (error) {
    return {
      success: false,
      message:
        error instanceof TypeError
          ? 'Network error. Unable to reach the server.'
          : 'An unexpected error occurred.',
      error: error instanceof Error ? error.message : String(error),
    } as ApiResponse<T>;
  }
};

/** ---------------------------------------------------------------
 *  Authentication
 * --------------------------------------------------------------- */

export const login = async (
  username: string,
  password: string
): Promise<ApiResponse<{ user: any; csrf_token: string }>> => {
  const res = await apiRequest<{ user: any; csrf_token: string }>(
    `action=login`,
    {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    }
  );
  if (res.success && res.data?.csrf_token) {
    setCsrfToken(res.data.csrf_token);
  }
  return res as ApiResponse<{ user: any; csrf_token: string }>;
};

export const logout = async (): Promise<ApiResponse<null>> => {
  const res = await apiRequest<null>('action=logout', {
    method: 'POST',
    includeCsrf: true,
  });
  setCsrfToken(null);
  return res;
};

export const me = async (): Promise<ApiResponse<any>> => {
  const res = await apiRequest<any>('action=me');
  if (res.success && res.data?.csrf_token) {
    setCsrfToken(res.data.csrf_token);
  }
  return res;
};

export const forgotPassword = async (
  username: string
): Promise<ApiResponse<null>> => {
  return apiRequest<null>('action=forgotPassword', {
    method: 'POST',
    body: JSON.stringify({ username }),
  });
};

export const resetPassword = async (
  token: string,
  newPassword: string
): Promise<ApiResponse<null>> => {
  return apiRequest<null>('action=resetPassword', {
    method: 'POST',
    body: JSON.stringify({ token, password: newPassword }),
  });
};

/** ---------------------------------------------------------------
 *  ID Cards / Student Records
 * --------------------------------------------------------------- */

export const listCards = async (
  params?: PaginationParams & { status?: string; id_type?: string; q?: string }
): Promise<ApiResponse<PaginatedResponse<IdCard>>> => {
  const query = new URLSearchParams();
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.status) query.set('status', params.status);
  if (params?.id_type) query.set('id_type', params.id_type);
  if (params?.q) query.set('q', params.q);
  return apiRequest<PaginatedResponse<IdCard>>(
    `action=cards&${query.toString()}`
  );
};

export const getCard = async (id: number): Promise<ApiResponse<IdCard>> => {
  return apiRequest<IdCard>(`action=card&id=${id}`);
};

export const saveCard = async (
  data: CardFormData
): Promise<ApiResponse<{ id: number; message: string }>> => {
  return apiRequest<{ id: number; message: string }>(
    'action=saveCard',
    {
      method: 'POST',
      body: JSON.stringify(data),
      includeCsrf: true,
    }
  );
};

export const updateCard = async (
  id: number,
  data: Partial<CardFormData>
): Promise<ApiResponse<{ id: number; message: string }>> => {
  return apiRequest<{ id: number; message: string }>(
    `action=updateCard&id=${id}`,
    {
      method: 'POST',
      body: JSON.stringify(data),
      includeCsrf: true,
    }
  );
};

export const deleteCard = async (
  id: number
): Promise<ApiResponse<{ message: string }>> => {
  return apiRequest<{ message: string }>(
    `action=deleteCard&id=${id}`,
    {
      method: 'POST',
      includeCsrf: true,
    }
  );
};

export const lookupStudent = async (
  studentNumber: string
): Promise<ApiResponse<CardLookupResult & { card_id: number }>> => {
  return apiRequest<CardLookupResult & { card_id: number }>(
    `action=studentLookup&number=${encodeURIComponent(studentNumber)}`
  );
};

/** ---------------------------------------------------------------
 *  Card Status / Workflow
 * --------------------------------------------------------------- */

export const markCardDone = async (
  id: number
): Promise<ApiResponse<{ id: number }>> => {
  return apiRequest<{ id: number }>(
    `action=markCardDone&id=${id}`,
    {
      method: 'POST',
      includeCsrf: true,
    }
  );
};

export const markCardPrinted = async (
  id: number,
  reason?: string
): Promise<ApiResponse<{ id: number }>> => {
  return apiRequest<{ id: number }>(
    `action=markCardPrinted&id=${id}`,
    {
      method: 'POST',
      body: JSON.stringify({ reason }),
      includeCsrf: true,
    }
  );
};

export const releaseCard = async (
  id: number,
  notes: string,
  received: boolean
): Promise<ApiResponse<{ id: number }>> => {
  return apiRequest<{ id: number }>(
    `action=releaseCard&id=${id}`,
    {
      method: 'POST',
      body: JSON.stringify({ release_notes: notes, student_received: received ? 1 : 0 }),
      includeCsrf: true,
    }
  );
};

/** ---------------------------------------------------------------
 *  Photo Library
 * --------------------------------------------------------------- */

export const uploadPhoto = async (
  studentId: number,
  file: File,
  label?: string
): Promise<ApiResponse<{ photo_id: number; path: string; width: number; height: number }>> => {
  const formData = new FormData();
  formData.append('photo', file);
  if (studentId) formData.append('student_id', String(studentId));
  if (label) formData.append('label', label);

  return apiRequest<{ photo_id: number; path: string; width: number; height: number }>(
    'action=uploadPhoto',
    {
      method: 'POST',
      body: formData,
      includeCsrf: true,
      headers: {},
    }
  );
};

export const getPhotoLibrary = async (
  studentId: number,
  filters?: PhotoLibraryFilters
): Promise<PhotoLibraryResult> => {
  const query = new URLSearchParams({ student_id: String(studentId) });
  if (filters?.source) query.set('source', filters.source);
  if (filters?.type) query.set('type', filters.type);
  if (filters?.is_active !== undefined) query.set('is_active', filters.is_active);
  if (filters?.processing_status) query.set('processing_status', filters.processing_status);

  const res = await apiRequest<PhotoLibraryResult>(
    `action=photoLibraryList&${query.toString()}`
  );
  return res.success ? (res.data as PhotoLibraryResult) : { data: [], total: 0, page: 1, per_page: 20, total_pages: 0 };
};

export const getPreferredPhoto = async (
  studentId: number
): Promise<ApiResponse<StudentPhoto | null>> => {
  return apiRequest<StudentPhoto | null>(
    `action=photoLibraryPreferred&student_id=${studentId}`
  );
};

export const deletePhoto = async (
  photoId: number
): Promise<ApiResponse<{ message: string }>> => {
  return apiRequest<{ message: string }>(
    `action=deletePhoto&id=${photoId}`,
    {
      method: 'POST',
      includeCsrf: true,
    }
  );
};

export const uploadPhotoPublic = async (
  file: File
): Promise<ApiResponse<{ path: string }>> => {
  const formData = new FormData();
  formData.append('photo', file);
  return apiRequest<{ path: string }>('action=studentUploadPhoto', {
    method: 'POST',
    body: formData,
    headers: {},
  });
};

/** ---------------------------------------------------------------
 *  Signatories
 * --------------------------------------------------------------- */

export const listSignatories = async (): Promise<ApiResponse<Signatory[]>> => {
  return apiRequest<Signatory[]>('action=signatories');
};

/** ---------------------------------------------------------------
 *  Templates
 * --------------------------------------------------------------- */

export const listTemplates = async (
  idType?: string
): Promise<ApiResponse<IdTemplate[]>> => {
  const query = idType ? `&id_type=${encodeURIComponent(idType)}` : '';
  return apiRequest<IdTemplate[]>(`action=templates${query}`);
};

/** ---------------------------------------------------------------
 *  Dashboard / Statistics
 * --------------------------------------------------------------- */

export const getDashboardStats = async (): Promise<ApiResponse<DashboardStats>> => {
  return apiRequest<DashboardStats>('action=dashboard');
};

/** ---------------------------------------------------------------
 *  Import / Export
 * --------------------------------------------------------------- */

export const previewImport = async (
  file: File
): Promise<ApiResponse<ImportPreview>> => {
  const formData = new FormData();
  formData.append('import_file', file);
  return apiRequest<ImportPreview>('action=importPreview', {
    method: 'POST',
    body: formData,
    headers: {},
    includeCsrf: true,
  });
};

export const commitImport = async (
  rows: ImportRow[]
): Promise<ApiResponse<{ imported: number; ids: number[] }>> => {
  return apiRequest<{ imported: number; ids: number[] }>(
    'action=importCommit',
    {
      method: 'POST',
      body: JSON.stringify({ rows }),
      includeCsrf: true,
    }
  );
};

export const exportCard = async (
  id: number,
  format: 'png' | 'jpg',
  side: 'front' | 'back'
): Promise<void> => {
  const res = await apiRequest<{ url: string }>(
    `action=exportCard&id=${id}&format=${format}&side=${side}`,
    { method: 'GET' }
  );
  if (!res.success || !res.data?.url) {
    throw new Error(res.message || 'Export failed');
  }
  const link = document.createElement('a');
  link.href = res.data.url;
  link.download = `id-${id}-${side}-${format}`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};

/** ---------------------------------------------------------------
 *  Audit Logs
 * --------------------------------------------------------------- */

export const listAuditLogs = async (
  params?: PaginationParams & { action?: string; user_id?: number }
): Promise<ApiResponse<PaginatedResponse<AuditLogEntry>>> => {
  const query = new URLSearchParams();
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.action) query.set('action', params.action);
  if (params?.user_id) query.set('user_id', String(params.user_id));
  return apiRequest<PaginatedResponse<AuditLogEntry>>(
    `action=auditLogs&${query.toString()}`
  );
};

/** ---------------------------------------------------------------
 *  Lost ID Requests
 * --------------------------------------------------------------- */

export const requestLostId = async (
  data: any
): Promise<ApiResponse<{ id: number; reference_card_id: number; message: string }>> => {
  return apiRequest<{ id: number; reference_card_id: number; message: string }>(
    'action=lostIdRequest',
    {
      method: 'POST',
      body: JSON.stringify(data),
    }
  );
};

export const listLostIdRequests = async (): Promise<ApiResponse<LostIdRequest[]>> => {
  return apiRequest<LostIdRequest[]>('action=lostIdRequests');
};

/** ---------------------------------------------------------------
 *  Print History
 * --------------------------------------------------------------- */

export const listPrintHistory = async (
  cardId?: number
): Promise<ApiResponse<PrintHistoryEntry[]>> => {
  const query = cardId !== undefined ? `&card_id=${cardId}` : '';
  return apiRequest<PrintHistoryEntry[]>(`action=printHistory${query}`);
};

/** ---------------------------------------------------------------
 *  Settings
 * --------------------------------------------------------------- */

export const getSettings = async (): Promise<ApiResponse<Record<string, string>>> => {
  return apiRequest<Record<string, string>>('action=settings');
};