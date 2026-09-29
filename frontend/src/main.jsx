import React, { Component, useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import html2canvas from "html2canvas";
import JSZip from "jszip";
import { saveAs } from "file-saver";
import "./styles.css";

const API_URL =
  import.meta.env.VITE_API_URL ||
  "http://localhost/lake-shore-id-editor/backend/api.php";
const BACKEND_URL = API_URL.replace(/\/api\.php(?:\?.*)?$/, "");
/* Imported so Vite bundles it with the correct (relative) base URL —
 * a root-absolute "/lsc-logo.png" 404s when the app is served from a
 * sub-path such as /lake-shore-id-editor/ (Basic Ed logo missing bug). */
import logo from "./assets/lsc-logo.png";

const COURSES = [
  "BACHELOR OF SCIENCE IN PSYCHOLOGY",
  "BACHELOR OF SPECIAL NEEDS EDUCATION",
  "BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION",
  "BACHELOR OF SCIENCE IN ACCOUNTANCY",
  "BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT",
  "BACHELOR OF SCIENCE IN TOURISM MANAGEMENT",
  "BACHELOR OF SCIENCE IN MANAGEMENT ACCOUNTING",
  "BACHELOR OF SCIENCE IN CRIMINOLOGY",
];
const gradeColors = {
  "GRADE 7": "#205f38",
  "GRADE 8": "#f0b51f",
  "GRADE 9": "#1968c7",
  "GRADE 10": "#f01616",
  "GRADE 11": "#850074",
  "GRADE 12": "#f47b20",
};
const typeTitles = {
  COLLEGE: "College Department",
  JUNIOR_HIGH: "Basic Education - Junior High School",
  SENIOR_HIGH: "Basic Education - Senior High School",
};

/* ID status lifecycle: created -> done -> edited / printed -> released */
const CARD_STATUS_LABELS = {
  created: "Created",
  done: "Done",
  edited: "Edited",
  printed: "Printed",
  released: "Released",
};
const cardStatus = (s) => CARD_STATUS_LABELS[s] || "Created";

function formatDateTime(value) {
  if (!value) return "";
  const d = new Date(String(value).replace(" ", "T"));
  if (isNaN(d.getTime())) return String(value);
  return d.toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

const DEPARTMENT_SIGNATORIES = {
  COLLEGE: "Sherill S. Villaluz",
  JUNIOR_HIGH: "Annabelle V. Molina",
  SENIOR_HIGH: "Annabelle V. Molina",
};
const STUDENT_DEPARTMENTS = [
  {
    type: "COLLEGE",
    title: "College Student",
    desc: "Currently enrolled in a college degree program.",
  },
  {
    type: "JUNIOR_HIGH",
    title: "Basic Education - Junior High School Student",
    desc: "Grade 7 to Grade 10.",
  },
  {
    type: "SENIOR_HIGH",
    title: "Basic Education - Senior High School Student",
    desc: "Grade 11 to Grade 12.",
  },
];

function makeStudentCard(type) {
  return {
    ...emptyCard,
    id_type: type,
    grade_level:
      type === "JUNIOR_HIGH"
        ? "GRADE 7"
        : type === "SENIOR_HIGH"
          ? "GRADE 11"
          : "",
    course: type === "COLLEGE" ? COURSES[0] : "",
  };
}

/*
 * Fields the public student-number search is allowed to fill in.
 * Mirrors studentLookupColumns() in backend/api.php — the API response is
 * already limited to exactly these keys, and the results panel renders only
 * these, so no sensitive column can reach the page even if one is added to
 * id_cards later. Keep the two lists in sync.
 */
const PUBLIC_LOOKUP_FIELDS = [
  "student_name",
  "id_type",
  "course",
  "grade_level",
  "section_name",
  "student_number",
  "student_id_number",
  "lrn",
  "academic_year",
  "school_year",
];

const emptyCard = {
  id: "",
  /* Set by the public student search when it locates an existing record.
   * It travels with the submission so the server can refuse to create a
   * second row for a student number that already exists. */
  existing_card_id: "",
  student_name: "",
  id_type: "COLLEGE",
  course: COURSES[0],
  grade_level: "GRADE 7",
  section_name: "",
  student_number: "",
  student_id_number: "",
  lrn: "",
  academic_year: "2026-2027",
  school_year: "2026-2027",
  photo_path: "",
  address_line1: "",
  address_line2: "",
  emergency_label: "In case of emergency, please notify",
  emergency_contact: "",
  emergency_phone: "",
  terms_title: "Terms and Conditions",
  term_1:
    "This card is non transferable. It must be worn conspicuously at all times while inside the LSC compound.",
  term_2: "Replacement for damage or lost ID is chargeable to card bearer.",
  term_3: "In case of lost card, please return to:",
  institution_name: "Lake Shore Colleges",
  institution_address:
    "A. Bonifacio St., Brgy. Canlalay, City of Biñan, Laguna, Philippines",
  mobile_no: "Mobile No.: 0936-958-2431 / 0962-773-7461",
  telephone_no: "Telephone No.: (049) 511-4328",
  email_address: "E-mail Address: lsei@lakeshore.edu.ph",
  signatory_id: "",
  signatory_name: "",
  signature_path: "",
  template_id: "",
  status: "created",
  printed_at: null,
  print_count: 0,
};

/* =====================================================================
 * ID Template Module
 * ---------------------------------------------------------------------
 * Templates let an admin upload an existing ID design (PNG/JPG) and get
 * a 100% exact copy as the card background. Only the data "zones" that
 * the admin places on top of that image are drawn as live data:
 *  - the rendered card = template image (exact copy) + zone texts/photos
 *  - one active template per department is auto-matched when creating IDs
 * ===================================================================== */
const TEMPLATE_FIELD_DEFS = [
  { key: "student_name", label: "Student Name", kind: "text" },
  { key: "id_type", label: "Department / ID Type", kind: "select" },
  { key: "course", label: "College Course", kind: "select" },
  { key: "grade_level", label: "Grade Level", kind: "select" },
  { key: "section_name", label: "Section", kind: "text" },
  { key: "grade_section", label: "Grade + Section (combined)", kind: "text" },
  { key: "student_number", label: "Student Number / ID Number", kind: "text" },
  { key: "student_id_number", label: "Student ID Number (college)", kind: "text" },
  { key: "lrn", label: "LRN", kind: "text" },
  { key: "academic_year", label: "Academic Year", kind: "text" },
  { key: "school_year", label: "School Year", kind: "text" },
  { key: "photo", label: "ID Photo", kind: "image" },
  { key: "address_line1", label: "Home Address (Line 1)", kind: "text" },
  { key: "address_line2", label: "Home Address (Line 2)", kind: "text" },
  { key: "emergency_label", label: "Emergency Label", kind: "text" },
  { key: "emergency_contact", label: "Emergency Contact Person", kind: "text" },
  { key: "emergency_phone", label: "Emergency Contact Phone", kind: "text" },
  { key: "terms_title", label: "Terms & Conditions Title", kind: "text" },
  { key: "term_1", label: "Terms & Conditions (line 1)", kind: "textarea" },
  { key: "term_2", label: "Terms & Conditions (line 2)", kind: "textarea" },
  { key: "term_3", label: "Terms & Conditions (line 3)", kind: "textarea" },
  { key: "institution_name", label: "Institution Name", kind: "text" },
  { key: "institution_address", label: "Institution Address", kind: "textarea" },
  { key: "mobile_no", label: "Mobile No.", kind: "text" },
  { key: "telephone_no", label: "Telephone No.", kind: "text" },
  { key: "email_address", label: "E-mail Address", kind: "text" },
  { key: "signatory_name", label: "Authorized Signatory Name", kind: "text" },
  { key: "signature", label: "Signature Image", kind: "image" },
];
const DESIGN_CANVAS_W = 321;
const DESIGN_CANVAS_H = 506;
const TEMPLATE_FONT_FAMILIES = [
  { value: "Montserrat", label: "Montserrat (body)" },
  { value: "Oswald", label: "Oswald (headings)" },
  { value: "Arial", label: "Arial" },
  { value: "Georgia", label: "Georgia" },
  { value: "Verdana", label: "Verdana" },
];
const templateDef = (key) =>
  TEMPLATE_FIELD_DEFS.find((d) => d.key === key) || {
    key,
    label: key.replace(/_/g, " "),
    kind: "text",
  };
function clamp(v, min, max) {
  return Math.min(max, Math.max(min, v));
}
function defaultZone(key, side) {
  const def = templateDef(key);
  const isImg = def.kind === "image";
  return {
    key,
    label: def.label,
    side,
    x: Math.round((DESIGN_CANVAS_W - (isImg ? 130 : 260)) / 2),
    y: Math.round((DESIGN_CANVAS_H - (isImg ? 170 : 48)) / 2),
    w: isImg ? 130 : 260,
    h: isImg ? 170 : 48,
    font_size: isImg ? undefined : 20,
    font_weight: isImg ? undefined : 700,
    font_family: isImg ? undefined : "Oswald",
    transform: isImg ? undefined : "uppercase",
    color: isImg ? undefined : "#ffffff",
    align: isImg ? undefined : "center",
    multiline: false,
    fit: isImg ? "cover" : undefined,
    required: false,
  };
}
function zoneDisplayValue(card, z) {
  if (!card) return "";
  switch (z.key) {
    case "grade_section":
      return `${card.grade_level || ""}${card.section_name ? " – " + card.section_name : ""}`;
    case "id_number":
    case "student_number":
      return card.student_number || card.student_id_number || "";
    case "photo":
    case "signature":
      return "";
    default:
      return card[z.key] ?? "";
  }
}
function zoneImageValue(card, z) {
  if (!card) return "";
  if (z.key === "photo") return card.photo_path || "";
  if (z.key === "signature") return card.signature_path || "";
  return "";
}
function zoneIsImage(z) {
  return templateDef(z.key).kind === "image";
}
function zoneStyle(z) {
  const s = {
    position: "absolute",
    left: z.x + "px",
    top: z.y + "px",
    width: z.w + "px",
    height: z.h + "px",
  };
  if (zoneIsImage(z)) {
    s.overflow = "hidden";
    return s;
  }
  s.fontSize = (z.font_size || 20) + "px";
  s.fontWeight = z.font_weight || 700;
  s.fontFamily = (z.font_family || "Montserrat") + ", sans-serif";
  s.color = z.color || "#ffffff";
  s.textAlign = z.align || "center";
  s.textTransform = z.transform || "none";
  s.lineHeight = 1.08;
  s.display = "flex";
  s.alignItems = "center";
  s.justifyContent =
    z.align === "left" ? "flex-start" : z.align === "right" ? "flex-end" : "center";
  s.whiteSpace = z.multiline ? "pre-wrap" : "nowrap";
  s.overflow = "hidden";
  return s;
}
function blankTemplate() {
  return {
    id: 0,
    name: "",
    id_type: "COLLEGE",
    front_image: "",
    back_image: "",
    fields_json: [],
    is_active: "1",
    is_system: "0",
  };
}
function templateZoneKeys(tpl) {
  const seen = {};
  const out = [];
  (tpl?.fields_json || []).forEach((z) => {
    if (!seen[z.key]) {
      seen[z.key] = true;
      out.push(z);
    }
  });
  return out;
}
/* ---- Audit log helpers (read-only trail rendering) ---- */
function fmtAuditDate(s) {
  if (!s) return "—";
  const d = new Date(String(s).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return s;
  return d.toLocaleString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}
function auditTone(action) {
  if (/deleted|disabled|failed/.test(action)) return "danger";
  if (/approved|printed|created|login$/.test(action)) return "ok";
  return "info";
}
function auditSummary(row) {
  let n = null;
  try {
    n = row.new_value ? JSON.parse(row.new_value) : null;
  } catch (e) {}
  if (n && typeof n === "object" && !Array.isArray(n)) {
    if (n.batch_print && n.count != null)
      return `Batch print — ${n.count} ID${n.count === 1 ? "" : "s"}`;
    if (n.student_name) return n.student_name;
    if (n.status) return "status → " + n.status;
  }
  return "";
}
function renderAuditValues(row) {
  const parse = (v) => {
    if (v === null || v === undefined || v === "") return null;
    try {
      return JSON.parse(v);
    } catch (e) {
      return String(v);
    }
  };
  const oldV = parse(row.old_value);
  const newV = parse(row.new_value);
  if (oldV === null && newV === null)
    return (
      <p className="audit-modal-empty">
        No value details recorded for this entry.
      </p>
    );
  if (
    Array.isArray(oldV) &&
    oldV.length > 0 &&
    oldV[0] &&
    typeof oldV[0] === "object" &&
    "field" in oldV[0]
  )
    return (
      <table className="audit-diff">
        <thead>
          <tr>
            <th>Field</th>
            <th>From</th>
            <th>To</th>
          </tr>
        </thead>
        <tbody>
          {oldV.map((c) => (
            <tr key={c.field}>
              <td>{c.label || c.field}</td>
              <td>{c.from === "" ? <i>(empty)</i> : c.from}</td>
              <td>{c.to === "" ? <i>(empty)</i> : c.to}</td>
            </tr>
          ))}
        </tbody>
      </table>
    );
  return (
    <div className="audit-values">
      {oldV !== null && (
        <div className="audit-block">
          <h4>Before</h4>
          <pre>{JSON.stringify(oldV, null, 2)}</pre>
        </div>
      )}
      {newV !== null && (
        <div className="audit-block">
          <h4>After</h4>
          <pre>{JSON.stringify(newV, null, 2)}</pre>
        </div>
      )}
    </div>
  );
}
async function api(action, options = {}) {
  const opts = { credentials: "include", ...options };
  const headers = { ...(opts.headers || {}) };
  if (csrfToken && !headers["X-CSRF-Token"])
    headers["X-CSRF-Token"] = csrfToken;
  if (
    headers["Content-Type"] === undefined &&
    opts.body &&
    typeof opts.body === "string"
  )
    headers["Content-Type"] = "application/json";
  opts.headers = headers;
  const qs = opts.params
    ? "&" + new URLSearchParams(opts.params).toString()
    : "";
  const r = await fetch(`${API_URL}?action=${action}${qs}`, opts);
  const j = await r
    .json()
    .catch(() => ({ success: false, message: "Invalid server response" }));
  if (r.status === 401) {
    sessionExpired = true;
    window.dispatchEvent(new Event("lsc-session-expired"));
  }
  if (!j.success) {
    /* Keep the HTTP status and the rate-limit metadata on the error:
     * the student portal needs them for the 429 countdown and for the
     * "verify you are human" captcha prompt. */
    const err = new Error(j.message || "Request failed");
    err.status = r.status;
    err.retryAfter = Number(j.retry_after) || 0;
    err.captchaRequired = !!j.captcha_required;
    err.captchaError = !!j.captcha_error;
    throw err;
  }
  if (j.csrf_token) csrfToken = j.csrf_token;
  return j;
}
let csrfToken = null;
let sessionExpired = false;
const assetUrl = (p) =>
  !p ? "" : /^https?:/i.test(p) ? p : `${BACKEND_URL}/${p.replace(/^\/+/, "")}`;
const Field = ({
  label,
  name,
  value,
  onChange,
  full = false,
  type = "text",
}) => (
  <label className={"field " + (full ? "full" : "")}>
    <span>{label}</span>
    <input
      type={type}
      name={name}
      value={value ?? ""}
      onChange={(e) => onChange(name, e.target.value)}
    />
  </label>
);
const Select = ({ label, name, value, onChange, children, full = false }) => (
  <label className={"field " + (full ? "full" : "")}>
    <span>{label}</span>
    <select
      name={name}
      value={value ?? ""}
      onChange={(e) => onChange(name, e.target.value)}
    >
      {children}
    </select>
  </label>
);

function FrontCard({ card }) {
  const color = gradeColors[card.grade_level] || "#205f38";
  const isCollege = card.id_type === "COLLEGE";
  const photo = assetUrl(card.photo_path);

  const getPhotoBackground = () => {
    if (!photo) return {};
    return { background: "#f1f3f4" };
  };

  return (
    <div className="smart-card front-card" id="front-card">
      <div className="top-wave" />

      <header className="front-header">
        <div className="brand">LAKE SHORE COLLEGES</div>

        <div className="formerly">
          formerly Lake Shore Educational Institution
        </div>

        <div className="address-small">
          A. Bonifacio St., Brgy. Canlalay, City of Biñan, Laguna, Philippines
        </div>
      </header>

      <div className="department">{typeTitles[card.id_type]}</div>

      <div className="front-main">
        <div className="photo-frame">
          {photo ? (
            <img
              src={photo}
              onError={(e) => {
                e.currentTarget.style.display = "none";
                e.currentTarget.parentElement.classList.add("photo-broken");
              }}
            />
          ) : (
            <div className="photo-placeholder">
              ID
              <br />
              PHOTO
            </div>
          )}
        </div>

        <div className="front-right">
          <img className="school-logo" src={logo} />

          {/* 
             College:
             No Academic Year block.

             Basic Education:
             Keep School Year block.
          */}
          {!isCollege && (
            <div className="year-block">
              <b>{card.school_year}</b>
              <small>SCHOOL YEAR</small>
            </div>
          )}
        </div>
      </div>

      <div
        className="name-band"
        style={{
          background: isCollege ? "#205f38" : color,
        }}
      >
        <div>{card.student_name || "STUDENT NAME"}</div>

        <small>
          {isCollege
            ? card.course || "COURSE"
            : `${card.grade_level || "GRADE"}${
                card.section_name ? " – " + card.section_name : ""
              }`}
        </small>
      </div>

      <div className="number-area">
        {isCollege ? (
          /*
           * COLLEGE
           *
           * Only Student ID.
           * Academic Year removed.
           */
          <div className="student-number college-student-number">
            <span>STUDENT ID</span>
            <b>{card.student_number || "—"}</b>
          </div>
        ) : (
          /*
           * JUNIOR HIGH / SENIOR HIGH
           *
           * Keep existing ID No. + LRN layout.
           */
          <>
            <div className="student-number">
              <span>ID No.</span>
              <b>{card.student_id_number || "—"}</b>
            </div>

            <div className="student-number">
              <span>LRN</span>
              <b>{card.lrn || "—"}</b>
            </div>
          </>
        )}
      </div>

      <div className="bottom-wave" />
    </div>
  );
}

function BackCard({ card }) {
  const sig = assetUrl(card.signature_path);
  return (
    <div className="smart-card back-card" id="back-card">
      <div className="back-address" style={{ background: "#FFFFFF", border: "2px solid #000000", color: "#000000" }}>
        <strong>Address:</strong>
        <div>{card.address_line1}</div>
        <div>{card.address_line2}</div>
      </div>
      <div className="emergency">
        <strong>{card.emergency_label}</strong>
        <div>{card.emergency_contact}</div>
        <div>{card.emergency_phone}</div>
      </div>
      <div className="terms">
        <h3>{card.terms_title}</h3>
        <p>- {card.term_1}</p>
        <p>- {card.term_2}</p>
        <p>- {card.term_3}</p>
      </div>
      <div className="back-school">
        <b>{card.institution_name}</b>
        <div>{card.institution_address}</div>
        <div>{card.mobile_no}</div>
        <div>{card.telephone_no}</div>
        <div>{card.email_address}</div>
      </div>
      {sig && (
        <div className="signature">
          <img
            src={sig}
            onError={(e) => {
              e.currentTarget.style.display = "none";
            }}
          />
          <div className="signature-line"></div>
          <div>{card.signatory_name}</div>
        </div>
      )}
    </div>
  );
}

/*
 * TemplateCard
 * ------------
 * Renders a card from an uploaded ID-template design.
 *  - The uploaded image itself is the background (100% exact copy).
 *  - Only the data zones defined by the admin are drawn on top.
 */
function TemplateCard({ card, template, side }) {
  const zones = (template?.fields_json || []).filter((z) => z.side === side);
  const img =
    side === "back" ? template?.back_image || "" : template?.front_image || "";
  const src = img ? assetUrl(img) : "";

  return (
    <div
      className={
        "smart-card template-card " +
        (side === "back" ? "back-card-template" : "front-card-template")
      }
      id={side === "back" ? "back-card" : "front-card"}
      data-template-card="1"
    >
      {src && (
        <img className="template-bg" src={src} alt="" crossOrigin="anonymous" />
      )}
      {zones.map((z, i) => {
        const isImg = zoneIsImage(z);
        const show = isImg ? Boolean(zoneImageValue(card, z)) : true;
        const v = zoneDisplayValue(card, z);
        return (
          <div
            className="template-zone"
            style={zoneStyle(z)}
            key={i}
            data-tzone-key={z.key}
          >
            {isImg ? (
              <img
                className="template-zone-img"
                src={assetUrl(zoneImageValue(card, z))}
                alt=""
                crossOrigin="anonymous"
                style={{ objectFit: z.fit || "cover" }}
                onError={(e) => (e.currentTarget.style.display = "none")}
              />
            ) : show && v !== "" ? (
              v
            ) : (
              <span className="tzone-empty" style={{ opacity: 0.3 }}>
                –– {templateDef(z.key).label} ––
              </span>
            )}
          </div>
        );
      })}
    </div>
  );
}

async function inlineImageAsDataURL(src) {
  try {
    const r = await fetch(src, { credentials: "include" });
    if (!r.ok) return null;
    const blob = await r.blob();
    return await new Promise((res) => {
      const fr = new FileReader();
      fr.onload = () => res(fr.result);
      fr.onerror = () => res(null);
      fr.readAsDataURL(blob);
    });
  } catch (e) {
    return null;
  }
}

/*
 * Renders any card face ("front-card" / "back-card") as a full-resolution
 * CR80 canvas (1276 x 2022 px @ 600 DPI). Shared by PNG/JPG export and by
 * the direct Smart ID 51 dual-side print job.
 */
async function renderCardCanvas(id) {
  const node = document.getElementById(id);
  if (!node) return null;

  /*
   * CR80 CARD SIZE
   * 54 × 85.6 mm
   *
   * At 600 DPI:
   * Width  = 54 / 25.4 × 600 = 1275.59 ≈ 1276 px
   * Height = 85.6 / 25.4 × 600 = 2022.05 ≈ 2022 px
   */

  const targetW = 1276;
  const targetH = 2022;

  /*
   * Your current card design is:
   * 321 × 506 CSS pixels
   *
   * We calculate the rendering scale from the
   * actual target width instead of using 600/96.
   *
   * This means html2canvas renders directly at
   * approximately 1276 × 2011 px instead of
   * rendering at 321 × 506 and then massively
   * enlarging the result.
   */
  const previewW = 321;
  const previewH = 506;

  const scale = targetW / previewW;

  const isTemplate = node.classList && node.classList.contains("template-card");

  const canvas = await html2canvas(node, {
    scale: isTemplate ? 1 : scale,

    useCORS: true,
    allowTaint: false,

    backgroundColor: "#ffffff",

    width: isTemplate ? targetW : previewW,
    height: isTemplate ? targetH : previewH,

    windowWidth: isTemplate ? targetW : previewW,
    windowHeight: isTemplate ? targetH : previewH,

    scrollX: 0,
    scrollY: 0,

    imageTimeout: 15000,

    onclone: async (doc) => {
      const clone = doc.getElementById(id);

      if (!clone) return;

      if (isTemplate) {
        /*
         * 100% EXACT TEMPLATE EXPORT
         * --------------------------
         * Render the card at full CR80 resolution. The template image is
         * the background, so it is drawn exactly as uploaded while the
         * data zones are scaled proportionally from the 321x506 space.
         */
        clone.style.width = targetW + "px";
        clone.style.height = targetH + "px";
        clone.style.transform = "none";
        clone.style.maxWidth = "none";
        clone.style.maxHeight = "none";
        clone.style.boxShadow = "none";
        clone.style.borderRadius = "0";

        const bg = clone.querySelector(".template-bg");
        if (bg) {
          bg.style.width = "100%";
          bg.style.height = "100%";
          bg.style.objectFit = "fill";
        }

        const zones = clone.querySelectorAll(".template-zone");
        zones.forEach((z) => {
          const L = parseFloat(z.style.left || "0") || 0;
          const T = parseFloat(z.style.top || "0") || 0;
          const W = parseFloat(z.style.width || "0") || 0;
          const H = parseFloat(z.style.height || "0") || 0;
          z.style.left = Math.round(L * scale) + "px";
          z.style.top = Math.round(T * scale) + "px";
          z.style.width = Math.round(W * scale) + "px";
          z.style.height = Math.round(H * scale) + "px";
          const fs = parseFloat(z.style.fontSize || "0") || 0;
          if (fs) z.style.fontSize = Math.round(fs * scale) + "px";
          z.style.maxWidth = "none";
          z.style.maxHeight = "none";
          z.style.display = "flex";
          const zi = z.querySelector(".template-zone-img");
          if (zi) {
            zi.style.width = "100%";
            zi.style.height = "100%";
            zi.style.maxWidth = "none";
            zi.style.maxHeight = "none";
          }
        });

        const imgs = clone.querySelectorAll("img");
        for (const img of imgs) {
          const src = img.getAttribute("src");
          if (!src) continue;
          try {
            const dataUrl = await inlineImageAsDataURL(src);
            if (dataUrl) img.src = dataUrl;
          } catch (e) {
            console.warn("Could not inline template image:", src, e);
          }
        }
        return;
      }

      /*
       * Keep the original card design dimensions.
       */
      clone.style.width = previewW + "px";
      clone.style.height = previewH + "px";

      clone.style.transform = "none";

      clone.style.maxWidth = "none";
      clone.style.maxHeight = "none";

      clone.style.boxShadow = "none";
      clone.style.borderRadius = "0";

      clone.style.background = "#ffffff";
      clone.style.backgroundImage = "none";

      const wrap = clone.parentElement;

      if (wrap) {
        wrap.style.background = "#ffffff";
        wrap.style.backgroundImage = "none";
        wrap.style.boxShadow = "none";
      }

      /*
       * Prepare images for html2canvas.
       * This helps avoid CORS-related image problems.
       */
      const imgs = clone.querySelectorAll("img");

      for (const img of imgs) {
        img.style.maxWidth = "none";
        img.style.maxHeight = "none";

        /*
         * Preserve the natural image dimensions.
         */
        img.style.objectFit = "contain";

        const src = img.getAttribute("src");

        if (!src) continue;

        try {
          const dataUrl = await inlineImageAsDataURL(src);

          if (dataUrl) {
            img.src = dataUrl;
          }
        } catch (e) {
          console.warn("Could not inline image:", src, e);
        }
      }
    },
  });

  /*
   * html2canvas will produce approximately:
   *
   * 321 × 506 × 3.975
   *
   * ≈ 1276 × 2011
   *
   * We resize only the small height difference needed
   * to achieve the exact CR80 600-DPI pixel dimensions.
   */
  let outputCanvas = canvas;

  if (canvas.width !== targetW || canvas.height !== targetH) {
    outputCanvas = document.createElement("canvas");

    outputCanvas.width = targetW;
    outputCanvas.height = targetH;

    const ctx = outputCanvas.getContext("2d");

    if (!ctx) {
      throw new Error("Unable to create export canvas.");
    }

    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = "high";

    ctx.drawImage(canvas, 0, 0, targetW, targetH);
  }

  return outputCanvas;
}

/*
 * Export one card face as a downloadable high-resolution file.
 */
async function exportCard(id, format = "png") {
  const outputCanvas = await renderCardCanvas(id);
  if (!outputCanvas) return;

  const targetW = 1276;
  const targetH = 2022;

  /*
   * Export format
   */
  const mime = format === "jpg" ? "image/jpeg" : "image/png";

  /*
   * JPG quality
   *
   * PNG does not use this value.
   */
  const quality = format === "jpg" ? 0.98 : undefined;

  const dataUrl = outputCanvas.toDataURL(mime, quality);

  /*
   * Download
   */
  const a = document.createElement("a");

  a.href = dataUrl;

  a.download = `${id}_CR80_600dpi_${targetW}x${targetH}.${format === "jpg" ? "jpg" : "png"}`;

  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

/*
 * SMART ID 51 — DIRECT DUAL-SIDE PRINT
 * ------------------------------------
 * Renders BOTH faces at full CR80 600-DPI resolution and sends them to the
 * printer as ONE job: page 1 = FRONT, page 2 = BACK.
 *
 * The Smart ID 51 driver controls the media: this printer is configured to
 * print COLOUR on the front side and BLACK & WHITE (K resin) on the back
 * side, so the app only has to deliver the two pages in order and the
 * driver handles duplex + panel selection.
 *
 * The card status is recorded as "printed" (with timestamp + counter) by
 * the caller after the job is sent.
 */
async function printCardDualSide() {
  const frontCanvas = await renderCardCanvas("front-card");
  const backCanvas = await renderCardCanvas("back-card");

  if (!frontCanvas || !backCanvas) {
    throw new Error(
      "Card previews are not ready yet. Please wait for the preview to finish rendering.",
    );
  }

  const frontUrl = frontCanvas.toDataURL("image/png");
  const backUrl = backCanvas.toDataURL("image/png");

  const iframe = document.createElement("iframe");
  iframe.setAttribute("title", "Smart ID 51 dual-side print");
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "1px";
  iframe.style.height = "1px";
  iframe.style.border = "0";
  iframe.style.opacity = "0";
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow.document;

  doc.open();
  doc.write(
    '<!doctype html><html><head><meta charset="utf-8"><title>Smart ID 51 — Dual Side</title><style>' +
      "@page{size:85.6mm 54mm;margin:0}" +
      "html,body{margin:0;padding:0;background:#fff}" +
      ".face{width:85.6mm;height:54mm;overflow:hidden;page-break-after:always;break-after:page}" +
      ".face.last{page-break-after:auto;break-after:auto}" +
      ".face img{width:100%;height:100%;display:block;-webkit-print-color-adjust:exact;print-color-adjust:exact}" +
      "</style></head><body>" +
      '<div class="face"><img src="' + frontUrl + '"></div>' +
      '<div class="face last"><img src="' + backUrl + '"></div>' +
      "</body></html>",
  );
  doc.close();

  /* Wait for the document and both faces to fully load before printing. */
  await new Promise((resolve) => {
    if (doc.readyState === "complete") resolve();
    else iframe.onload = resolve;
  });
  await Promise.all(
    Array.from(doc.images || []).map((img) =>
      img.complete
        ? Promise.resolve()
        : new Promise((resolve) => {
            img.onload = resolve;
            img.onerror = resolve;
          }),
    ),
  );

  iframe.contentWindow.focus();
  iframe.contentWindow.print();

  /* Clean up the hidden frame shortly after the print dialog closes. */
  setTimeout(() => {
    if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
  }, 2000);
}

/*
 * SMART ID 51 — BATCH DUAL-SIDE PRINT
 * -----------------------------------
 * The same print job as printCardDualSide(), but for a whole batch:
 * page 1 = FRONT of card 1, page 2 = BACK of card 1, page 3 = FRONT of
 * card 2, and so on. The caller renders every face with the SHARED card
 * renderer (renderCardCanvasForBulk — the same component used by the live
 * preview and bulk PNG export) and passes the PNG data URLs in; this
 * function only assembles the pages and hands them to the printer. The
 * @page size and per-face CSS are identical to the single-card job, so the
 * Smart ID 51 driver keeps handling duplex + panel selection as before.
 */
async function printFacesDualSide(
  faces,
  title = "Smart ID 51 — Batch Dual Side",
) {
  if (!Array.isArray(faces) || faces.length === 0) {
    throw new Error("No rendered card faces were supplied to the print job.");
  }
  const iframe = document.createElement("iframe");
  iframe.setAttribute("title", title);
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "1px";
  iframe.style.height = "1px";
  iframe.style.border = "0";
  iframe.style.opacity = "0";
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow.document;

  doc.open();
  doc.write(
    '<!doctype html><html><head><meta charset="utf-8"><title>' +
      title +
      '</title><style>' +
      "@page{size:85.6mm 54mm;margin:0}" +
      "html,body{margin:0;padding:0;background:#fff}" +
      ".face{width:85.6mm;height:54mm;overflow:hidden;page-break-after:always;break-after:page}" +
      ".face.last{page-break-after:auto;break-after:auto}" +
      ".face img{width:100%;height:100%;display:block;-webkit-print-color-adjust:exact;print-color-adjust:exact}" +
      "</style></head><body>",
  );
  /* Stream the pages card by card instead of building one huge HTML string
   * (large batches otherwise double the already-big image payload). */
  const last = faces.length - 1;
  faces.forEach((f, i) => {
    doc.write('<div class="face"><img src="' + f.front + '"></div>');
    doc.write(
      '<div class="face' +
        (i === last ? " last" : "") +
        '"><img src="' +
        f.back +
        '"></div>',
    );
  });
  doc.write("</body></html>");
  doc.close();

  /* Wait for the document and every face image to fully load before printing. */
  await new Promise((resolve) => {
    if (doc.readyState === "complete") resolve();
    else iframe.onload = resolve;
  });
  await Promise.all(
    Array.from(doc.images || []).map((img) =>
      img.complete
        ? Promise.resolve()
        : new Promise((resolve) => {
            img.onload = resolve;
            img.onerror = resolve;
          }),
    ),
  );

  iframe.contentWindow.focus();
  iframe.contentWindow.print();

  /* Clean up the hidden frame shortly after the print dialog closes. */
  setTimeout(() => {
    if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
  }, 2000);
}

/* One Smart ID 51 job is capped so the rendered page payload stays bounded
 * (each face is a full 1276×2022 @600 DPI image). Larger runs are split. */
const MAX_BULK_PRINT = 100;

/*
 * TemplateFieldsForm
 * ------------------
 * The admin ID form is driven by the selected template: only the data the
 * template's zones need is shown (auto-matched with the template design).
 */
function TemplateFieldsForm({
  card,
  template,
  update,
  photoUpload,
  signatureUpload,
  onTypeChange,
}) {
  const zones = templateZoneKeys(template);
  if (!zones.length)
    return (
      <p className="tpl-no-fields">
        This template has no data zones yet. Open the template design editor
        and place zones on the image where the student data should be drawn.
      </p>
    );
  const front = zones.filter((z) => z.side === "front");
  const back = zones.filter((z) => z.side === "back");
  const renderOne = (z) => {
    const def = templateDef(z.key);
    const lab = `${def.label}${z.required ? " *" : ""}`;
    if (def.kind === "image") {
      if (z.key === "photo")
        return (
          <label className="upload field full" key={z.key}>
            <span>{lab}</span>
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              onChange={(e) => photoUpload(e.target.files?.[0])}
            />
            {card.photo_path ? (
              <small>Photo uploaded — will be drawn inside the template photo zone.</small>
            ) : (
              <small>Student photo needed for this template.</small>
            )}
          </label>
        );
      if (z.key === "signature")
        return (
          <label className="upload field full" key={z.key}>
            <span>{lab}</span>
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              onChange={(e) => signatureUpload(e.target.files?.[0])}
            />
            {card.signature_path ? (
              <small>Signature set for {card.signatory_name || "the signatory"}.</small>
            ) : (
              <small>Assigned signatory signature is used automatically; you may override here.</small>
            )}
          </label>
        );
      return <div key={z.key} />;
    }
    if (def.kind === "select") {
      if (z.key === "id_type")
        return (
          <Select
            label={lab}
            name="id_type"
            value={card.id_type}
            onChange={onTypeChange}
            full
            key={z.key}
          >
            <option value="COLLEGE">College Student</option>
            <option value="JUNIOR_HIGH">Junior High School</option>
            <option value="SENIOR_HIGH">Senior High School</option>
          </Select>
        );
      if (z.key === "course")
        return (
          <Select label={lab} name="course" value={card.course} onChange={update} full key={z.key}>
            {COURSES.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
        );
      if (z.key === "grade_level")
        return (
          <Select label={lab} name="grade_level" value={card.grade_level} onChange={update} full key={z.key}>
            {(card.id_type === "JUNIOR_HIGH"
              ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"]
              : ["GRADE 11", "GRADE 12"]
            ).map((g) => (
              <option key={g}>{g}</option>
            ))}
          </Select>
        );
    }
    if (def.kind === "textarea")
      return (
        <label className="field full" key={z.key}>
          <span>{lab}</span>
          <textarea
            rows={3}
            name={z.key}
            value={card[z.key] ?? ""}
            onChange={(e) => update(z.key, e.target.value)}
          />
        </label>
      );
    return (
      <Field
        key={z.key}
        label={lab}
        name={z.key}
        value={card[z.key] ?? ""}
        onChange={update}
        full
      />
    );
  };
  return (
    <>
      {front.length > 0 && (
        <>
          <h2>Front of ID — Data Needed</h2>
          <div className="form-grid">{front.map(renderOne)}</div>
        </>
      )}
      {back.length > 0 && (
        <>
          <h2>Back of ID — Data Needed</h2>
          <div className="form-grid">{back.map(renderOne)}</div>
        </>
      )}
    </>
  );
}

/*
 * TemplateDesigner
 * ----------------
 * Full-screen zone editor. The admin uploads the existing ID design image
 * (front + optional back) and drags/resizes data zones directly on top of
 * that 100% exact image. Each zone is then rendered on the final ID.
 */
function TemplateDesigner({ initial, onSave, onCancel }) {
  const [tpl, setTpl] = useState(() => {
    const base = initial ? JSON.parse(JSON.stringify(initial)) : blankTemplate();
    base.fields_json = Array.isArray(base.fields_json) ? base.fields_json : [];
    return base;
  });
  const [side, setSide] = useState("front");
  const [sel, setSel] = useState(-1);
  const [frontFile, setFrontFile] = useState(null);
  const [backFile, setBackFile] = useState(null);
  const [frontPreview, setFrontPreview] = useState(
    initial?.front_image ? assetUrl(initial.front_image) : "",
  );
  const [backPreview, setBackPreview] = useState(
    initial?.back_image ? assetUrl(initial.back_image) : "",
  );
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState("");
  const canvasRef = useRef(null);

  const zones = tpl.fields_json;
  const setZones = (fn) =>
    setTpl((p) => ({ ...p, fields_json: fn(p.fields_json) }));
  const curPreview = side === "front" ? frontPreview : backPreview;

  function zoneAt(i) {
    return zones[i] || null;
  }
  function defOf(i) {
    const z = zoneAt(i);
    return z ? templateDef(z.key) : null;
  }
  function unusedKey() {
    const taken = {};
    zones.forEach((z) => (taken[z.key] = true));
    const pref =
      side === "front"
        ? [
            "student_name",
            "photo",
            "course",
            "grade_level",
            "section_name",
            "student_number",
            "student_id_number",
            "lrn",
            "school_year",
            "academic_year",
            "id_type",
            "grade_section",
          ]
        : [
            "institution_name",
            "institution_address",
            "mobile_no",
            "telephone_no",
            "email_address",
            "emergency_contact",
            "emergency_phone",
            "terms_title",
            "term_1",
            "term_2",
            "term_3",
            "signatory_name",
            "signature",
          ];
    for (const k of pref) if (!taken[k]) return k;
    const fb = TEMPLATE_FIELD_DEFS.find((d) => !taken[d.key]);
    return fb ? fb.key : "student_name";
  }
  function addZone(kind) {
    if (!curPreview) {
      setErr("First upload the design image for this side of the ID.");
      return;
    }
    const key =
      kind === "image"
        ? side === "front"
          ? "photo"
          : "signature"
        : unusedKey();
    const z = defaultZone(key, side);
    setZones((zs) => [...zs, z]);
    setSel(zones.length);
    setErr("");
  }
  function addZoneAtPoint(e) {
    if (!curPreview || !canvasRef.current) return;
    const rect = canvasRef.current.getBoundingClientRect();
    const x = Math.round(((e.clientX - rect.left) / rect.width) * DESIGN_CANVAS_W);
    const y = Math.round(((e.clientY - rect.top) / rect.height) * DESIGN_CANVAS_H);
    const z = defaultZone(unusedKey(), side);
    z.x = Math.round(clamp(x - z.w / 2, 0, DESIGN_CANVAS_W - z.w));
    z.y = Math.round(clamp(y - z.h / 2, 0, DESIGN_CANVAS_H - z.h));
    setZones((zs) => [...zs, z]);
    setSel(zones.length);
  }
  function beginDrag(i, e) {
    e.preventDefault();
    e.stopPropagation();
    const z = zoneAt(i);
    if (!z) return;
    const sx = e.clientX;
    const sy = e.clientY;
    const orig = { x: z.x, y: z.y };
    const move = (ev) => {
      const dx = ev.clientX - sx;
      const dy = ev.clientY - sy;
      setZones((zs) =>
        zs.map((p, idx) =>
          idx === i
            ? {
                ...p,
                x: Math.round(clamp(orig.x + dx, 0, DESIGN_CANVAS_W - p.w)),
                y: Math.round(clamp(orig.y + dy, 0, DESIGN_CANVAS_H - p.h)),
              }
            : p,
        ),
      );
    };
    const up = () => {
      window.removeEventListener("mousemove", move);
      window.removeEventListener("mouseup", up);
    };
    window.addEventListener("mousemove", move);
    window.addEventListener("mouseup", up);
  }
  function beginResize(i, e) {
    e.preventDefault();
    e.stopPropagation();
    const z = zoneAt(i);
    if (!z) return;
    const sx = e.clientX;
    const sy = e.clientY;
    const orig = { w: z.w, h: z.h };
    const move = (ev) => {
      const dx = ev.clientX - sx;
      const dy = ev.clientY - sy;
      setZones((zs) =>
        zs.map((p, idx) =>
          idx === i
            ? {
                ...p,
                w: Math.round(clamp(orig.w + dx, 20, DESIGN_CANVAS_W - p.x)),
                h: Math.round(clamp(orig.h + dy, 20, DESIGN_CANVAS_H - p.y)),
              }
            : p,
        ),
      );
    };
    const up = () => {
      window.removeEventListener("mousemove", move);
      window.removeEventListener("mouseup", up);
    };
    window.addEventListener("mousemove", move);
    window.addEventListener("mouseup", up);
  }
  function updateZone(i, patch) {
    setZones((zs) => zs.map((p, idx) => (idx === i ? { ...p, ...patch } : p)));
  }
  function removeZone(i) {
    setZones((zs) => zs.filter((p, idx) => idx !== i));
    setSel(-1);
  }
  /* Remove the selected zone with Delete / Backspace (ignored while typing). */
  useEffect(() => {
    function onKey(e) {
      if (e.key !== "Delete" && e.key !== "Backspace") return;
      const t = e.target;
      const tag = t && t.tagName;
      if (
        tag === "INPUT" ||
        tag === "SELECT" ||
        tag === "TEXTAREA" ||
        (t && t.isContentEditable)
      )
        return;
      if (sel >= 0 && sel < zones.length) {
        e.preventDefault();
        removeZone(sel);
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [sel, zones.length]);
  function changeKey(i, key) {
    const def = templateDef(key);
    const z = zoneAt(i);
    if (!z) return;
    const oldImg = zoneIsImage(z);
    const newImg = def.kind === "image";
    const nu = { ...z, key, label: def.label };
    if (newImg && !oldImg) {
      nu.font_size = undefined;
      nu.font_weight = undefined;
      nu.font_family = undefined;
      nu.transform = undefined;
      nu.color = undefined;
      nu.align = undefined;
      nu.multiline = false;
      nu.fit = "cover";
      nu.w = 150;
      nu.h = 180;
    } else if (!newImg && oldImg) {
      nu.font_size = 20;
      nu.font_weight = 700;
      nu.font_family = "Oswald";
      nu.transform = "uppercase";
      nu.color = "#ffffff";
      nu.align = "center";
      nu.multiline = false;
      nu.fit = undefined;
      nu.w = 260;
      nu.h = 48;
    }
    updateZone(i, nu);
  }
  async function save() {
    if (!tpl.name.trim()) {
      setErr("Please give this template a name.");
      return;
    }
    if (!tpl.front_image && !frontFile) {
      setErr(
        "Please upload the FRONT design image (PNG/JPG). This image is the 100% exact copy used as the ID background.",
      );
      return;
    }
    const fd = new FormData();
    fd.append("id", tpl.id || "");
    fd.append("name", tpl.name);
    fd.append("id_type", tpl.id_type);
    fd.append("is_active", String(tpl.is_active));
    fd.append("fields_json", JSON.stringify(zones));
    if (tpl.front_image) fd.append("front_image_prev", tpl.front_image);
    if (tpl.back_image) fd.append("back_image_prev", tpl.back_image);
    if (frontFile) fd.append("front_image", frontFile);
    if (backFile) fd.append("back_image", backFile);
    setSaving(true);
    setErr("");
    try {
      await api("saveTemplate", { method: "POST", body: fd });
      onSave(tpl.id || 0);
    } catch (e) {
      setErr(e.message);
      setSaving(false);
    }
  }
  const curZone = sel >= 0 && sel < zones.length ? zones[sel] : null;
  const curDef = curZone ? templateDef(curZone.key) : null;

  return (
    <div className="td-overlay">
      <div className="td-shell">
        <div className="td-head">
          <h2>ID Template Designer</h2>
          <div className="td-head-actions">
            <button onClick={onCancel} disabled={saving}>
              Cancel
            </button>
          </div>
        </div>
        {err && (
          <div className="alert">
            {err}
            <button onClick={() => setErr("")}>×</button>
          </div>
        )}
        <p className="td-note">
          🖼 The uploaded design is kept <b>100% exact</b> — it becomes the
          card background. Only the yellow <b>data zones</b> you place are
          drawn over it with the student data. Drag zones to move them, drag
          the corner handle to resize, or click the canvas to add a zone.
          Placed the wrong field? Hover the zone and click the red ✕ (or
          select it and press Delete) to remove it.
        </p>
        <div className="td-body">
          <div className="td-canvas-wrap">
            <div className="td-toolbar">
              <button
                className={"td-side " + (side === "front" ? "active" : "")}
                onClick={() => {
                  setSide("front");
                  setSel(-1);
                }}
              >
                Front
              </button>
              <button
                className={"td-side " + (side === "back" ? "active" : "")}
                onClick={() => {
                  setSide("back");
                  setSel(-1);
                }}
              >
                Back
              </button>
              <span className="td-toolbar-spacer" />
              <button type="button" onClick={() => addZone("text")}>
                ＋ Text Zone
              </button>
              <button type="button" onClick={() => addZone("image")}>
                ＋ Photo Zone
              </button>
            </div>
            <div className="td-canvas" ref={canvasRef} onMouseDown={addZoneAtPoint}>
              {curPreview ? (
                <img
                  className="template-bg"
                  src={curPreview}
                  alt="template design"
                />
              ) : (
                <div className="td-canvas-empty">
                  {side === "front"
                    ? "⬆ Upload the FRONT design (PNG/JPG) to start placing data zones"
                    : "Back design not uploaded yet — upload it below, or leave empty to use the default LSC back"}
                </div>
              )}
              {zones.map((z, i) => {
                if (z.side !== side) return null;
                const box = {
                  position: "absolute",
                  left: z.x + "px",
                  top: z.y + "px",
                  width: z.w + "px",
                  height: z.h + "px",
                };
                const def = templateDef(z.key);
                return (
                  <div
                    key={i}
                    className={"td-zone" + (sel === i ? " selected" : "")}
                    style={box}
                    onMouseDown={(e) => beginDrag(i, e)}
                    onClick={(e) => {
                      e.stopPropagation();
                      setSel(i);
                    }}
                    title={def.label + " (drag to move, corner to resize, ✕ or Delete to remove)"}
                  >
                    <span className="td-zone-label">{def.label}</span>
                    {zoneIsImage(z) ? (
                      <span className="td-zone-ph td-zone-ph-img">🖼 photo</span>
                    ) : (
                      <span className="td-zone-ph">{def.label}</span>
                    )}
                    <span
                      className="td-zone-remove"
                      title={"Remove " + def.label + " zone"}
                      onMouseDown={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                      }}
                      onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        removeZone(i);
                      }}
                    >
                      ✕
                    </span>
                    <span
                      className="td-zone-handle"
                      onMouseDown={(e) => beginResize(i, e)}
                    />
                  </div>
                );
              })}
            </div>
          </div>
          /* __DESIGNER_CHUNK_C__ */
          <div className="td-panel">
            <h3>Template Settings</h3>
            <div className="field">
              <span>Template Name</span>
              <input
                value={tpl.name}
                onChange={(e) => setTpl((p) => ({ ...p, name: e.target.value }))}
                placeholder="e.g. College ID 2026 design"
              />
            </div>
            <div className="field">
              <span>Department (auto-match)</span>
              <select
                value={tpl.id_type}
                onChange={(e) => setTpl((p) => ({ ...p, id_type: e.target.value }))}
              >
                <option value="COLLEGE">College</option>
                <option value="JUNIOR_HIGH">Junior High School</option>
                <option value="SENIOR_HIGH">Senior High School</option>
              </select>
            </div>
            <label className="td-active">
              <input
                type="checkbox"
                checked={String(tpl.is_active) === "1"}
                onChange={(e) =>
                  setTpl((p) => ({ ...p, is_active: e.target.checked ? "1" : "0" }))
                }
              />
              Use as active template for {typeTitles[tpl.id_type]} — new IDs of
              this department are automatically created from it
            </label>
            <h3>Design Images (100% exact copy)</h3>
            <label className="upload field">
              <span>Front design (PNG / JPG) *</span>
              <input
                type="file"
                accept="image/png,image/jpeg"
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (!f) return;
                  setFrontFile(f);
                  setFrontPreview(URL.createObjectURL(f));
                }}
              />
              {frontPreview && <small>Front image selected.</small>}
            </label>
            <label className="upload field">
              <span>Back design (PNG / JPG — optional)</span>
              <input
                type="file"
                accept="image/png,image/jpeg"
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (!f) return;
                  setBackFile(f);
                  setBackPreview(URL.createObjectURL(f));
                }}
              />
              {backPreview && <small>Back image selected.</small>}
            </label>
            {curZone && curDef ? (
              <div className="td-zone-config">
                <h3>Zone Settings</h3>
                <div className="field">
                  <span>Data Field</span>
                  <select
                    value={curZone.key}
                    onChange={(e) => changeKey(sel, e.target.value)}
                  >
                    {TEMPLATE_FIELD_DEFS.map((d) => (
                      <option key={d.key} value={d.key}>
                        {d.label}
                      </option>
                    ))}
                  </select>
                </div>
                <label className="td-active">
                  <input
                    type="checkbox"
                    checked={Boolean(curZone.required)}
                    onChange={(e) => updateZone(sel, { required: e.target.checked })}
                  />
                  Required (highlighted in the ID form)
                </label>
                {curDef.kind === "image" ? (
                  <div className="field">
                    <span>Photo Fit</span>
                    <select
                      value={curZone.fit || "cover"}
                      onChange={(e) => updateZone(sel, { fit: e.target.value })}
                    >
                      <option value="cover">Cover (fill, crop)</option>
                      <option value="contain">Contain (fit inside)</option>
                    </select>
                  </div>
                ) : (
                  <>
                    <div className="td-grid2">
                      <label className="field">
                        <span>Font Size (px)</span>
                        <input
                          type="number"
                          min={6}
                          max={80}
                          value={curZone.font_size || 20}
                          onChange={(e) =>
                            updateZone(sel, { font_size: Number(e.target.value) || 20 })
                          }
                        />
                      </label>
                      <label className="field">
                        <span>Font Weight</span>
                        <select
                          value={curZone.font_weight || 700}
                          onChange={(e) =>
                            updateZone(sel, { font_weight: Number(e.target.value) || 700 })
                          }
                        >
                          <option value={400}>400 Regular</option>
                          <option value={500}>500 Medium</option>
                          <option value={600}>600 Semi-bold</option>
                          <option value={700}>700 Bold</option>
                          <option value={800}>800 Extra-bold</option>
                        </select>
                      </label>
                    </div>
                    <div className="td-grid2">
                      <label className="field">
                        <span>Font Family</span>
                        <select
                          value={curZone.font_family || "Montserrat"}
                          onChange={(e) =>
                            updateZone(sel, { font_family: e.target.value })
                          }
                        >
                          {TEMPLATE_FONT_FAMILIES.map((f) => (
                            <option key={f.value} value={f.value}>
                              {f.label}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label className="field">
                        <span>Text Color</span>
                        <input
                          type="color"
                          value={curZone.color || "#ffffff"}
                          onChange={(e) =>
                            updateZone(sel, { color: e.target.value })
                          }
                        />
                      </label>
                    </div>
                    <div className="td-grid2">
                      <label className="field">
                        <span>Align</span>
                        <select
                          value={curZone.align || "center"}
                          onChange={(e) =>
                            updateZone(sel, { align: e.target.value })
                          }
                        >
                          <option value="left">Left</option>
                          <option value="center">Center</option>
                          <option value="right">Right</option>
                        </select>
                      </label>
                      <label className="field">
                        <span>Case</span>
                        <select
                          value={curZone.transform || "none"}
                          onChange={(e) =>
                            updateZone(sel, { transform: e.target.value })
                          }
                        >
                          <option value="none">As typed</option>
                          <option value="uppercase">UPPERCASE</option>
                          <option value="capitalize">Capitalize</option>
                        </select>
                      </label>
                    </div>
                    <label className="td-active">
                      <input
                        type="checkbox"
                        checked={Boolean(curZone.multiline)}
                        onChange={(e) =>
                          updateZone(sel, { multiline: e.target.checked })
                        }
                      />
                      Allow multiple lines (wrap)
                    </label>
                    <div className="td-grid2">
                      <label className="field">
                        <span>X</span>
                        <input
                          type="number"
                          value={curZone.x}
                          onChange={(e) =>
                            updateZone(sel, {
                              x: Math.round(
                                clamp(Number(e.target.value) || 0, 0, DESIGN_CANVAS_W - curZone.w),
                              ),
                            })
                          }
                        />
                      </label>
                      <label className="field">
                        <span>Y</span>
                        <input
                          type="number"
                          value={curZone.y}
                          onChange={(e) =>
                            updateZone(sel, {
                              y: Math.round(
                                clamp(Number(e.target.value) || 0, 0, DESIGN_CANVAS_H - curZone.h),
                              ),
                            })
                          }
                        />
                      </label>
                      <label className="field">
                        <span>W</span>
                        <input
                          type="number"
                          value={curZone.w}
                          onChange={(e) =>
                            updateZone(sel, {
                              w: Math.round(
                                clamp(Number(e.target.value) || 20, 20, DESIGN_CANVAS_W - curZone.x),
                              ),
                            })
                          }
                        />
                      </label>
                      <label className="field">
                        <span>H</span>
                        <input
                          type="number"
                          value={curZone.h}
                          onChange={(e) =>
                            updateZone(sel, {
                              h: Math.round(
                                clamp(Number(e.target.value) || 20, 20, DESIGN_CANVAS_H - curZone.y),
                              ),
                            })
                          }
                        />
                      </label>
                    </div>
                    <button
                      className="danger td-delete"
                      onClick={() => removeZone(sel)}
                    >
                      🗑 Remove this zone
                    </button>
                  </>
                )}
              </div>
            ) : (
              <p className="td-hint">
                Select a zone on the canvas to configure the data field, font,
                color, alignment and exact position. Press Delete or click the
                red ✕ on a zone to remove it.
              </p>
            )}
          </div>
        </div>

        <div className="td-foot">
          <button className="primary" onClick={save} disabled={saving}>
            {saving ? (
              <>
                <span className="s-spin" aria-hidden="true" />
                Saving…
              </>
            ) : (
              "Save Template"
            )}
          </button>
        </div>
      </div>
    </div>
  );
}

function Login({ onLogin, onBack }) {
  const [mode, setMode] = useState("login");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [info, setInfo] = useState("");
  const [fpUsername, setFpUsername] = useState("");
  const [fpToken, setFpToken] = useState("");
  const [fpPassword, setFpPassword] = useState("");
  const [fpConfirm, setFpConfirm] = useState("");
  const [fpMsg, setFpMsg] = useState("");
  async function submit(e) {
    e.preventDefault();
    setErr("");
    setBusy(true);
    try {
      const r = await api("login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password }),
      });
      onLogin(r.data);
    } catch (ex) {
      setErr(ex.message);
    } finally {
      setBusy(false);
    }
  }
  async function requestReset(e) {
    e.preventDefault();
    setErr("");
    setFpMsg("");
    setBusy(true);
    try {
      const r = await api("forgotPassword", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: fpUsername }),
      });
      if (r.reset_token) {
        setFpToken(r.reset_token);
        setMode("reset");
        setFpMsg(
          "Reset token generated below. It is valid for 30 minutes and can be used once.",
        );
      } else {
        setFpMsg(
          r.message ||
            "If the account exists, a reset token has been generated.",
        );
      }
    } catch (ex) {
      setFpMsg(ex.message);
    } finally {
      setBusy(false);
    }
  }
  async function confirmReset(e) {
    e.preventDefault();
    setErr("");
    setFpMsg("");
    setBusy(true);
    try {
      if (fpPassword !== fpConfirm) throw new Error("Passwords do not match");
      const r = await api("resetPassword", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: fpToken, password: fpPassword }),
      });
      setMode("login");
      setUsername(fpUsername);
      setPassword("");
      setInfo(r.message || "Password updated. You can now sign in.");
    } catch (ex) {
      setFpMsg(ex.message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="login-shell">
      <form
        className="login-card"
        onSubmit={
          mode === "login" ? submit : mode === "forgot" ? requestReset : confirmReset
        }
      >
        <div className="login-brand">
          <div className="login-mark">LSC</div>
          <div>
            <div className="login-title">Lake Shore Colleges</div>
            <div className="login-sub">ID Management System</div>
          </div>
        </div>
        {mode === "login" && (
          <>
            <h1>Sign in</h1>
            <p className="login-help">
              Use your Lake Shore Colleges email to access the ID editor.
            </p>
            {err && (
              <div className="alert" style={{ margin: "0 0 14px" }}>
                {err}
                <button type="button" onClick={() => setErr("")}>
                  ×
                </button>
              </div>
            )}
            {info && (
              <div className="fp-note" style={{ marginBottom: 14 }}>
                {info}
              </div>
            )}
            <label className="field full">
              <span>Email or Username</span>
              <input
                type="text"
                autoComplete="username"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                required
              />
            </label>
            <label className="field full">
              <span>Password</span>
              <input
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </label>
            <button
              className="primary login-submit"
              type="submit"
              disabled={busy}
            >
              {busy ? "Signing in..." : "Sign In"}
            </button>
            <div className="login-links">
              <button
                type="button"
                className="link-btn"
                onClick={() => {
                  setMode("forgot");
                  setErr("");
                  setInfo("");
                  setFpMsg("");
                }}
              >
                Forgot password?
              </button>
              {onBack && (
                <button
                  type="button"
                  className="link-btn"
                  onClick={onBack}
                >
                  ← Student Portal
                </button>
              )}
            </div>
          </>
        )}
        {mode === "forgot" && (
          <>
            <h1>Forgot password</h1>
            <p className="login-help">
              Enter your username or email to generate a one-time reset token
              (valid for 30 minutes). On setups without e-mail, the token is
              shown here or can be provided by an administrator.
            </p>
            {fpMsg && <div className="fp-note">{fpMsg}</div>}
            <label className="field full">
              <span>Email or Username</span>
              <input
                type="text"
                value={fpUsername}
                onChange={(e) => setFpUsername(e.target.value)}
                required
              />
            </label>
            <button
              className="primary login-submit"
              type="submit"
              disabled={busy}
            >
              {busy ? "Generating..." : "Generate Reset Token"}
            </button>
            <div className="login-links">
              <button
                type="button"
                className="link-btn"
                onClick={() => {
                  setMode("login");
                  setFpMsg("");
                }}
              >
                ← Back to sign in
              </button>
            </div>
          </>
        )}
        {mode === "reset" && (
          <>
            <h1>Set new password</h1>
            <p className="login-help">
              Paste your reset token and choose a new password (at least 6
              characters).
            </p>
            {fpMsg && <div className="fp-note">{fpMsg}</div>}
            <label className="field full">
              <span>Reset Token</span>
              <input
                type="text"
                value={fpToken}
                onChange={(e) => setFpToken(e.target.value)}
                required
              />
            </label>
            <label className="field full">
              <span>New Password</span>
              <input
                type="password"
                autoComplete="new-password"
                value={fpPassword}
                onChange={(e) => setFpPassword(e.target.value)}
                required
                minLength={6}
              />
            </label>
            <label className="field full">
              <span>Confirm New Password</span>
              <input
                type="password"
                autoComplete="new-password"
                value={fpConfirm}
                onChange={(e) => setFpConfirm(e.target.value)}
                required
                minLength={6}
              />
            </label>
            <button
              className="primary login-submit"
              type="submit"
              disabled={busy}
            >
              {busy ? "Updating..." : "Update Password"}
            </button>
            <div className="login-links">
              <button
                type="button"
                className="link-btn"
                onClick={() => {
                  setMode("login");
                  setFpMsg("");
                }}
              >
                ← Back to sign in
              </button>
            </div>
          </>
        )}
        <div className="login-foot">
          Protected session. Only authorized administrators may create student
          IDs.
        </div>
      </form>
    </div>
  );
}

/*
 * 3D interactive error toast shown when a student form submission fails.
 * Pop-in entrance + shake, 3D warning badge and a tactile close button.
 */
function StudentAlert({ message, onClose }) {
  return (
    <div className="s-alert" role="alert">
      <span className="s-alert-badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="19" height="19">
          <path
            d="M12 3.2 22 20.2 H2 Z"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.1"
            strokeLinejoin="round"
          />
          <line
            x1="12"
            y1="9.6"
            x2="12"
            y2="14.2"
            stroke="currentColor"
            strokeWidth="2.1"
            strokeLinecap="round"
          />
          <circle cx="12" cy="17" r="1.3" fill="currentColor" />
        </svg>
      </span>
      <div className="s-alert-body">{message}</div>
      <button
        type="button"
        className="s-alert-close"
        onClick={onClose}
        aria-label="Dismiss notification"
      >
        <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true">
          <path
            d="M5 5 19 19 M19 5 5 19"
            stroke="currentColor"
            strokeWidth="2.8"
            strokeLinecap="round"
          />
        </svg>
      </button>
    </div>
  );
}

/*
 * Shown when the public API answers HTTP 429 (rate limit reached).
 * Explains what happened, counts the wait down and — when the server
 * offers a human-verification challenge — lets a student behind a
 * shared network solve it and continue without waiting.
 */
function formatWait(total) {
  const m = Math.floor(total / 60);
  const s = total % 60;
  if (m <= 0) return `${s} second${s === 1 ? "" : "s"}`;
  return `${m} minute${m === 1 ? "" : "s"} ${s} second${s === 1 ? "" : "s"}`;
}

function StudentThrottleNotice({
  seconds,
  captchaRequired,
  captcha,
  code,
  onCode,
  onRefresh,
}) {
  return (
    <div className="s-alert s-alert-limit" role="alert">
      <span className="s-alert-badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="19" height="19">
          <circle
            cx="12"
            cy="13"
            r="8"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.1"
          />
          <path
            d="M12 9.2V13.4"
            stroke="currentColor"
            strokeWidth="2.1"
            strokeLinecap="round"
          />
          <path
            d="M9.4 2.6h5.2M12 5.2v3.4"
            stroke="currentColor"
            strokeWidth="2.1"
            strokeLinecap="round"
          />
        </svg>
      </span>
      <div className="s-alert-body">
        <b>Too many submissions from this network</b>
        <p>
          To keep the ID system safe, only a limited number of ID requests
          can be sent from one network within a few minutes.{" "}
          <strong>Your submission was not processed.</strong> Please wait{" "}
          <b>{formatWait(Math.max(0, seconds))}</b> and submit again.
        </p>
        {captchaRequired && (
          <div className="s-captcha">
            <div className="s-captcha-row">
              {captcha?.image ? (
                <img
                  src={captcha.image}
                  alt="Verification code"
                  className="s-captcha-img"
                />
              ) : (
                <span className="s-captcha-img s-captcha-img--wait">
                  Loading…
                </span>
              )}
              <input
                type="text"
                className="s-captcha-input"
                value={code}
                onChange={(e) => onCode(e.target.value.toUpperCase())}
                placeholder="Enter the code"
                maxLength={5}
                autoComplete="off"
                aria-label="Verification code"
              />
              <button
                type="button"
                className="ghost-btn"
                onClick={onRefresh}
              >
                New code
              </button>
            </div>
            <small>
              On a shared Wi-Fi (one connection for a whole classroom or
              dormitory)? Solve the code once and you can keep submitting
              right away.
            </small>
          </div>
        )}
      </div>
    </div>
  );
}

/*
 * Honeypot input: off-screen and read-only, so a person never sees or
 * fills it, while automated form fillers happily submit a value that
 * the API treats as a bot (backend/rate_limit.php).
 */
function HoneypotField() {
  return (
    <input
      className="s-honeypot"
      type="text"
      name="website"
      tabIndex={-1}
      autoComplete="off"
      aria-hidden="true"
      readOnly
      value=""
    />
  );
}

/*
 * Student search — existing record detection.
 *
 * Workflow: Enter Student Number -> Search -> Student Found (or Student Not
 * Found) -> the form underneath is filled in automatically. It lives inside
 * the ID creation form, so the student never has to type their details twice.
 *
 * Only the fields listed in PUBLIC_LOOKUP_FIELDS are ever shown or copied
 * into the form; the API response contains nothing else. Deliberately NOT a
 * nested <form> — this sits inside the real <form>, and the Search button
 * therefore is type="button" with an explicit Enter handler.
 */
function StudentLookupPanel({
  value,
  onValue,
  onSearch,
  busy,
  result,
  onClear,
  onLostId,
}) {
  const found = result?.status === "found" ? result.data || {} : null;
  const rows = found
    ? [
        [
          "Department",
          found.id_type
            ? typeTitles[found.id_type] || found.id_type
            : "",
        ],
        ["Name", found.student_name],
        ["Course", found.course],
        ["Grade", found.grade_level],
        ["Section", found.section_name],
        [
          found.id_type === "COLLEGE" ? "Academic Year" : "School Year",
          found.id_type === "COLLEGE" ? found.academic_year : found.school_year,
        ],
        ["ID Number", found.student_id_number],
        ["LRN", found.lrn],
      ].filter(([, v]) => v)
    : [];
  return (
    <section className="s-lookup">
      <h2>Already have a Student Number?</h2>
      <p className="s-lookup-note">
        Search the ID Management System for your existing record instead of
        typing everything again. Your details are loaded into the form below
        automatically.
      </p>
      <div className="s-lookup-row">
        <label className="field">
          <span>Student Number</span>
          <input
            type="text"
            name="lookup_student_number"
            value={value}
            onChange={(e) => onValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                /* Do not submit the whole ID form on Enter. */
                e.preventDefault();
                onSearch();
              }
            }}
            placeholder="e.g. 2026-00125"
            autoComplete="off"
            maxLength={100}
          />
        </label>
        <button
          className="primary"
          type="button"
          onClick={onSearch}
          disabled={busy || !value.trim()}
        >
          {busy ? (
            <>
              <span className="s-spin" aria-hidden="true" /> Searching…
            </>
          ) : (
            "Search"
          )}
        </button>
        {value && (
          <button type="button" className="ghost-btn" onClick={onClear}>
            Clear
          </button>
        )}
      </div>

      {found && (
        <div className="s-lookup-box s-lookup-box--found" role="status">
          <b>Student Found</b>
          <dl>
            {rows.map(([k, v]) => (
              <div key={k}>
                <dt>{k}</dt>
                <dd>{v}</dd>
              </div>
            ))}
          </dl>
          <small>
            These details have been loaded into the form below. If everything
            is correct, there is nothing to submit — the record already exists.
          </small>
          {onLostId && (
            <button type="button" className="link-btn" onClick={onLostId}>
              This is me and my ID is lost → Report Lost ID
            </button>
          )}
        </div>
      )}

      {result?.status === "missing" && (
        <div className="s-lookup-box s-lookup-box--missing" role="status">
          <b>Student Not Found</b>
          <small>
            {result.message ||
              "No ID record matches that Student Number. Please check the " +
                "number and try again, or continue with the form below to " +
                "create a new ID record."}
          </small>
        </div>
      )}
    </section>
  );
}

/*
 * 3D animated success medal with a confetti burst, shown after a
 * student form has been submitted successfully.
 */
function SuccessMedal() {
  return (
    <div className="s-success-wrap">
      <div className="s-confetti" aria-hidden="true">
        {Array.from({ length: 14 }).map((_, i) => (
          <i key={i} />
        ))}
      </div>
      <div className="s-medal">
        <svg viewBox="0 0 52 52" aria-hidden="true">
          <circle className="s-medal-ring" cx="26" cy="26" r="23" />
          <path className="s-medal-check" d="M15.5 27.5 23 35 37 18.5" />
        </svg>
      </div>
    </div>
  );
}

function StudentTopBar({ onBack, backLabel, onStaffLogin }) {
  return (
    <div className="student-topbar">
      <div className="student-brand">
        <img
          src={logo}
          alt="Lake Shore Colleges logo"
          onError={(e) => {
            e.currentTarget.style.display = "none";
          }}
        />
        <div>
          <b>Lake Shore Colleges</b>
          <small>Student ID Services</small>
        </div>
      </div>
      <div className="student-top-actions">
        {onStaffLogin && (
          <button className="ghost-btn" onClick={onStaffLogin}>
            Staff Login
          </button>
        )}
        {onBack && (
          <button className="ghost-btn" onClick={onBack}>
            {backLabel}
          </button>
        )}
      </div>
    </div>
  );
}

function StudentPortal({ onStaffLogin }) {
  const [step, setStep] = useState("landing"); // landing | choose | form | lost | lost_done | done
  const [card, setCard] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [submittedId, setSubmittedId] = useState("");
  const [verifiedRefId, setVerifiedRefId] = useState("");
  const [lost, setLost] = useState({
    student_name: "",
    id_type: "COLLEGE",
    course: COURSES[0],
    grade_level: "GRADE 7",
    section_name: "",
    student_number: "",
    lrn: "",
  });
  const [receiptFile, setReceiptFile] = useState(null);
  /* Student search (existing record detection): the Student Number being
   * looked up and the outcome of the last search. */
  const [lookupNumber, setLookupNumber] = useState("");
  const [lookupBusy, setLookupBusy] = useState(false);
  const [lookupResult, setLookupResult] = useState(null); // null | found | missing
  /* Rate limiting (HTTP 429) + optional human-verification challenge. */
  const [throttle, setThrottle] = useState(null);
  const [captcha, setCaptcha] = useState(null);
  const [captchaCode, setCaptchaCode] = useState("");
  const [waitLeft, setWaitLeft] = useState(0);

  /* Count the retry wait down, then drop the notice automatically. */
  useEffect(() => {
    if (!throttle) return undefined;
    const timer = setInterval(
      () => setWaitLeft((s) => (s > 0 ? s - 1 : 0)),
      1000,
    );
    return () => clearInterval(timer);
  }, [throttle]);
  useEffect(() => {
    if (throttle && waitLeft === 0) clearThrottle();
  }, [throttle, waitLeft]);

  /* Fetch a fresh verification challenge from the public endpoint. */
  async function loadCaptcha() {
    try {
      const r = await api("publicCaptcha");
      setCaptcha({ id: r.captcha_id, image: r.image });
      setCaptchaCode("");
    } catch {
      /* Verification is unavailable — the wait countdown still applies. */
    }
  }

  /* Turn an HTTP 429 into the throttle notice. Returns true when the
   * error was a rate-limit response (already handled). */
  function applyThrottle(e) {
    if (e.status !== 429) return false;
    setThrottle({ captchaRequired: !!e.captchaRequired });
    setWaitLeft(Math.max(1, e.retryAfter || 60));
    if (e.captchaRequired) loadCaptcha();
    else {
      setCaptcha(null);
      setCaptchaCode("");
    }
    return true;
  }

  function clearThrottle() {
    setThrottle(null);
    setCaptcha(null);
    setCaptchaCode("");
    setWaitLeft(0);
  }

  /* Solution sent with a blocked submission so a real student on a
   * shared network can keep going (see backend/rate_limit.php). */
  function captchaFields() {
    if (!captcha?.id || !captchaCode.trim()) return {};
    return { captcha_id: captcha.id, captcha_code: captchaCode.trim() };
  }

  /* The authorized signatory is server data — staff can change who signs each
   * department's cards — so it is always loaded from the API and never taken
   * from what the browser submits. */
  async function loadSignatory(type) {
    try {
      const r = await api(`publicSignatory&type=${encodeURIComponent(type)}`);
      const s = r.data;
      return {
        signatory_id: s?.id ?? "",
        signatory_name: s?.full_name || DEPARTMENT_SIGNATORIES[type] || "",
        signature_path: s?.signature_path || "",
      };
    } catch {
      /*
       * Keep the department signatory name even if the
       * signature record could not be loaded publicly.
       */
      return {
        signatory_id: "",
        signatory_name: DEPARTMENT_SIGNATORIES[type] || "",
        signature_path: "",
      };
    }
  }

  async function chooseDepartment(type) {
    setErr("");
    setLookupNumber("");
    setLookupResult(null);
    setCard(makeStudentCard(type));
    setStep("form");
    window.scrollTo(0, 0);
    const sig = await loadSignatory(type);
    /* Only apply when the student is still on the department they picked —
     * a slow response must not overwrite a newer choice. */
    setCard((p) => (p.id_type === type ? { ...p, ...sig } : p));
  }

  /*
   * Search the ID Management System for an existing student record.
   *
   * The API answers with a single whitelisted row (PUBLIC_LOOKUP_FIELDS) or
   * 404 "Student Not Found", so this can only ever pre-fill the form. The
   * card id it returns travels with the submission in `existing_card_id`,
   * which is what stops the create form from producing a duplicate row for a
   * student number that already exists.
   */
  async function searchStudent() {
    const number = lookupNumber.trim();
    if (!number) {
      setErr("Please enter your Student Number to search.");
      return;
    }
    setErr("");
    setLookupResult(null);
    setLookupBusy(true);
    try {
      const r = await api("studentLookup", {
        method: "POST",
        body: JSON.stringify({
          student_number: number,
          /* Honeypot: hidden from people, filled in by naive bots. */
          website: "",
          ...captchaFields(),
        }),
      });
      clearThrottle();
      const d = r.data || {};
      const patch = { existing_card_id: r.card_id || "" };
      for (const k of PUBLIC_LOOKUP_FIELDS) {
        if (d[k] !== undefined) patch[k] = d[k];
      }
      const foundType = d.id_type || "";
      if (foundType && foundType !== card?.id_type) {
        /* The stored record decides the department: the form only offers the
         * fields that belong to that id_type, and the signatory follows it. */
        const sig = await loadSignatory(foundType);
        setCard({ ...makeStudentCard(foundType), ...patch, ...sig });
      } else {
        setCard((p) => ({ ...p, ...patch }));
      }
      setLookupResult({ status: "found", data: d });
    } catch (e) {
      if (!applyThrottle(e)) {
        /* 404 is the documented "Student Not Found" answer and is shown in
         * the panel; anything else goes to the normal alert. */
        if (e.status === 404) setLookupResult({ status: "missing", message: e.message });
        else setErr(e.message);
      }
    } finally {
      setLookupBusy(false);
    }
  }

  /* Forget the search and the record it loaded, back to a blank form. */
  function clearLookup() {
    setLookupNumber("");
    setLookupResult(null);
    setErr("");
    setCard((p) => ({ ...p, ...makeStudentCard(p.id_type) }));
  }

  /* A found record means there is nothing to create: send the student to the
   * existing lost-ID workflow instead, pre-filled with what we know. */
  function reportLostFromLookup() {
    const d = lookupResult?.data || {};
    setLost((p) => ({
      ...p,
      student_name: d.student_name || "",
      id_type: d.id_type || p.id_type,
      course: d.course || "",
      grade_level: d.grade_level || "",
      section_name: d.section_name || "",
      student_number: d.student_number || d.student_id_number || "",
      lrn: d.lrn || "",
    }));
    setErr("");
    setStep("lost");
    window.scrollTo(0, 0);
  }

  const update = (n, v) => setCard((p) => ({ ...p, [n]: v }));
  const updateLost = (n, v) => setLost((p) => ({ ...p, [n]: v }));

  function changeLostType(t) {
    const next = { ...lost, id_type: t };
    if (t === "COLLEGE") {
      next.course = COURSES.includes(next.course) ? next.course : COURSES[0];
      next.grade_level = "";
      next.section_name = "";
    } else {
      next.course = "";
      next.grade_level =
        t === "JUNIOR_HIGH"
          ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"].includes(next.grade_level)
            ? next.grade_level
            : "GRADE 7"
          : ["GRADE 11", "GRADE 12"].includes(next.grade_level)
            ? next.grade_level
            : "GRADE 11";
    }
    setLost(next);
  }

  async function submitLost() {
    if (!lost.student_name.trim()) {
      setErr("Please enter the student full name.");
      window.scrollTo(0, 0);
      return;
    }
    if (lost.id_type === "COLLEGE" && !lost.student_number.trim()) {
      setErr("Please enter the student number.");
      window.scrollTo(0, 0);
      return;
    }
    if (
      lost.id_type !== "COLLEGE" &&
      !lost.student_number.trim() &&
      !lost.lrn.trim()
    ) {
      setErr(
        "Please enter the Student / ID Number (or LRN) so your ID can be checked in the system.",
      );
      window.scrollTo(0, 0);
      return;
    }
    setErr("");
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append("student_name", lost.student_name);
      fd.append("id_type", lost.id_type);
      fd.append("course", lost.course);
      fd.append("grade_level", lost.grade_level);
      fd.append("section_name", lost.section_name);
      fd.append("student_number", lost.student_number);
      fd.append("lrn", lost.lrn);
      /* Honeypot: hidden from people, filled in by naive bots. */
      fd.append("website", "");
      Object.entries(captchaFields()).forEach(([k, v]) => fd.append(k, v));
      if (receiptFile) fd.append("receipt", receiptFile);
      const r = await api("lostIdRequest", {
        method: "POST",
        body: fd,
      });
      clearThrottle();
      setSubmittedId(r.id);
      setVerifiedRefId(r.reference_card_id || "");
      setStep("lost_done");
      window.scrollTo(0, 0);
    } catch (e) {
      if (!applyThrottle(e)) setErr(e.message);
      window.scrollTo(0, 0);
    } finally {
      setBusy(false);
    }
  }

  async function studentPhotoUpload(file) {
    if (!file) return;
    setErr("");
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append("photo", file);
      fd.append("website", "");
      Object.entries(captchaFields()).forEach(([k, v]) => fd.append(k, v));
      const r = await api("studentUploadPhoto", { method: "POST", body: fd });
      clearThrottle();
      update("photo_path", r.path);
    } catch (e) {
      if (!applyThrottle(e)) setErr(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function submitId() {
    if (card.existing_card_id) {
      setErr(
        "An ID record already exists for this Student Number. Use Report Lost ID if you need a replacement.",
      );
      window.scrollTo(0, 0);
      return;
    }
    if (!card.student_name.trim()) {
      setErr("Please enter the student full name before submitting.");
      window.scrollTo(0, 0);
      return;
    }
    setErr("");
    setBusy(true);
    try {
      const r = await api("studentSaveCard", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          ...card,
          /* Honeypot: hidden from people, filled in by naive bots. */
          website: "",
          ...captchaFields(),
        }),
      });
      clearThrottle();
      setSubmittedId(r.id);
      setStep("done");
      window.scrollTo(0, 0);
    } catch (e) {
      if (!applyThrottle(e)) setErr(e.message);
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    setCard(null);
    setSubmittedId("");
    setVerifiedRefId("");
    setErr("");
    setBusy(false);
    setLookupNumber("");
    setLookupResult(null);
    setLookupBusy(false);
    clearThrottle();
    setLost({
      student_name: "",
      id_type: "COLLEGE",
      course: COURSES[0],
      grade_level: "GRADE 7",
      section_name: "",
      student_number: "",
      lrn: "",
    });
    setReceiptFile(null);
    setStep("landing");
    window.scrollTo(0, 0);
  }

  if (step === "landing")
    return (
      <div className="student-shell">
        <StudentTopBar onStaffLogin={onStaffLogin} />
        <main className="student-landing">
          <div className="student-logo-ring">
            <img
              src={logo}
              alt="Lake Shore Colleges logo"
              onError={(e) => {
                e.currentTarget.style.display = "none";
              }}
            />
          </div>
          <h1>Lake Shore Colleges</h1>
          <p className="student-tagline">
            formerly Lake Shore Educational Institution
          </p>
          <p className="student-note">
            Create your official student ID online. Select your department,
            fill out the form and review the live preview — your ID will be
            saved directly to the school's ID Management System.
          </p>
          <div className="student-cta-row">
            <button
              className="primary student-cta"
              onClick={() => {
                setErr("");
                setStep("choose");
              }}
            >
              Create ID
            </button>
            <button
              className="ghost-btn student-cta-alt"
              onClick={() => {
                setErr("");
                setSubmittedId("");
                setStep("lost");
              }}
            >
              Report Lost ID
            </button>
          </div>
        </main>
      </div>
    );

  if (step === "choose")
    return (
      <div className="student-shell">
        <StudentTopBar onBack={reset} backLabel="← Home" onStaffLogin={onStaffLogin} />
        <main className="student-landing">
          <h1>What kind of student are you?</h1>
          <p className="student-note">
            Select your department so we can open the correct ID creation form.
          </p>
          <div className="student-choose">
            {STUDENT_DEPARTMENTS.map((d) => (
              <button
                key={d.type}
                className="choose-card"
                onClick={() => chooseDepartment(d.type)}
              >
                <b>{d.title}</b>
                <span>{d.desc}</span>
              </button>
            ))}
          </div>
          <p className="student-foot">
            <button type="button" className="link-btn" onClick={reset}>
              ← Back
            </button>
          </p>
        </main>
      </div>
    );

  if (step === "form" && card)
    return (
      <div className="student-shell student-editor">
        <StudentTopBar onBack={reset} backLabel="← Cancel" onStaffLogin={onStaffLogin} />
        <main className="content">
          <header className="top">
            <div>
              <h1>Student ID Creation</h1>
              <p>
                {typeTitles[card.id_type]} — fill out your details and review
                the live ID preview.
              </p>
            </div>
            <div className="top-actions">
              <button onClick={reset}>Back</button>
            </div>
          </header>

          {err && <StudentAlert message={err} onClose={() => setErr("")} />}
          {throttle && (
            <StudentThrottleNotice
              seconds={waitLeft}
              captchaRequired={throttle.captchaRequired}
              captcha={captcha}
              code={captchaCode}
              onCode={setCaptchaCode}
              onRefresh={loadCaptcha}
            />
          )}

          <div className="editor-layout">
            <form
              className="editor-form"
              onSubmit={(e) => {
                e.preventDefault();
                submitId();
              }}
            >
              <HoneypotField />
              <StudentLookupPanel
                value={lookupNumber}
                onValue={setLookupNumber}
                onSearch={searchStudent}
                busy={lookupBusy}
                result={lookupResult}
                onClear={clearLookup}
                onLostId={reportLostFromLookup}
              />

              <h2>Student Details</h2>
              <div className="form-grid">
                <div className="field full">
                  <span>Department / ID Type</span>
                  <input
                    type="text"
                    value={typeTitles[card.id_type] || ""}
                    readOnly
                  />
                </div>
                <Field
                  label="Student Full Name"
                  name="student_name"
                  value={card.student_name}
                  onChange={update}
                  full
                />
                {card.id_type === "COLLEGE" ? (
                  <>
                    <Select
                      label="Course"
                      name="course"
                      value={card.course}
                      onChange={update}
                      full
                    >
                      {COURSES.map((c) => (
                        <option key={c}>{c}</option>
                      ))}
                    </Select>
                    <Field
                      label="Student ID Number"
                      name="student_number"
                      value={card.student_number}
                      onChange={update}
                    />
                    <Field
                      label="Academic Year"
                      name="academic_year"
                      value={card.academic_year}
                      onChange={update}
                    />
                  </>
                ) : (
                  <>
                    <Select
                      label={
                        card.id_type === "JUNIOR_HIGH"
                          ? "Junior High School Grade"
                          : "Senior High School Grade"
                      }
                      name="grade_level"
                      value={card.grade_level}
                      onChange={update}
                      full
                    >
                      {(card.id_type === "JUNIOR_HIGH"
                        ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"]
                        : ["GRADE 11", "GRADE 12"]
                      ).map((g) => (
                        <option key={g}>{g}</option>
                      ))}
                    </Select>
                    <Field
                      label="Section"
                      name="section_name"
                      value={card.section_name}
                      onChange={update}
                    />
                    <Field
                      label="School Year"
                      name="school_year"
                      value={card.school_year}
                      onChange={update}
                    />
                    <Field
                      label="ID Number"
                      name="student_id_number"
                      value={card.student_id_number}
                      onChange={update}
                    />
                    <Field
                      label="LRN"
                      name="lrn"
                      value={card.lrn}
                      onChange={update}
                    />
                  </>
                )}
                <label className="upload field full">
                  <span>ID Photo Upload</span>
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => studentPhotoUpload(e.target.files?.[0])}
                  />
                  {card.photo_path && (
                    <small>Photo uploaded successfully.</small>
                  )}
                </label>
              </div>

              <h2>Back ID Details</h2>
              <div className="form-grid">
                <Field
                  label="Address Line 1"
                  name="address_line1"
                  value={card.address_line1}
                  onChange={update}
                  full
                />
                <Field
                  label="Address Line 2"
                  name="address_line2"
                  value={card.address_line2}
                  onChange={update}
                  full
                />
                <Field
                  label="Emergency Contact"
                  name="emergency_contact"
                  value={card.emergency_contact}
                  onChange={update}
                />
                <Field
                  label="Emergency Phone"
                  name="emergency_phone"
                  value={card.emergency_phone}
                  onChange={update}
                />
                <div className="field full">
                  <span>Authorized Signatory</span>
                  <input
                    type="text"
                    value={DEPARTMENT_SIGNATORIES[card.id_type] || ""}
                    readOnly
                  />
                </div>
              </div>

              <div className="form-actions">
                {card.existing_card_id ? (
                  <>
                    <small>
                      An ID record (#{card.existing_card_id}) already exists for
                      this Student Number, so a second one cannot be created.
                      Review the details above, then use Report Lost ID if you
                      need a replacement card.
                    </small>
                    <button
                      className="primary"
                      type="button"
                      onClick={reportLostFromLookup}
                      disabled={busy}
                    >
                      Report Lost ID
                    </button>
                  </>
                ) : (
                  <>
                    <small>
                      Your ID will be saved to the school's ID Management System
                      for review.
                    </small>
                    <button className="primary" type="submit" disabled={busy}>
                      {busy ? (
                        <>
                          <span className="s-spin" aria-hidden="true" />
                          Submitting…
                        </>
                      ) : (
                        "Submit ID"
                      )}
                    </button>
                  </>
                )}
              </div>
            </form>

            <aside className="preview-panel">
              <div className="preview-head">
                <b>SMART ID 51 PREVIEW</b>
                <span>Front + Back</span>
              </div>
              <div className="preview-card-wrap">
                <FrontCard card={card} />
                <BackCard card={card} />
              </div>
              <div className="export-box">
                <b>Live preview</b>
                <small>
                  This preview shows exactly how your ID will be printed. No
                  download is available here — submit the form and the school
                  will process and release your ID.
                </small>
              </div>
            </aside>
          </div>
        </main>
      </div>
    );

  if (step === "lost")
    return (
      <div className="student-shell student-editor">
        <StudentTopBar onBack={reset} backLabel="← Home" onStaffLogin={onStaffLogin} />
        <main className="content">
          <header className="top">
            <div>
              <h1>Report Lost ID</h1>
              <p>
                Request a reprint of your student ID. Fill in your details and
                attach a copy of your payment receipt (optional). Your details
                are checked against the ID Management System — a request can
                only be submitted if your ID already exists in the system.
              </p>
            </div>
            <div className="top-actions">
              <button onClick={reset}>Cancel</button>
            </div>
          </header>

          {err && <StudentAlert message={err} onClose={() => setErr("")} />}
          {throttle && (
            <StudentThrottleNotice
              seconds={waitLeft}
              captchaRequired={throttle.captchaRequired}
              captcha={captcha}
              code={captchaCode}
              onCode={setCaptchaCode}
              onRefresh={loadCaptcha}
            />
          )}

          <section className="lost-form records">
            <HoneypotField />
            <h2>Student Information</h2>
            <div className="form-grid">
              <Field
                label="Student Full Name"
                name="student_name"
                value={lost.student_name}
                onChange={updateLost}
                full
              />
              <Select
                label="Department / ID Type"
                name="id_type"
                value={lost.id_type}
                onChange={(n, v) => {
                  updateLost(n, v);
                  changeLostType(v);
                }}
                full
              >
                <option value="COLLEGE">College Student</option>
                <option value="JUNIOR_HIGH">
                  Basic Education - Junior High School
                </option>
                <option value="SENIOR_HIGH">
                  Basic Education - Senior High School
                </option>
              </Select>

              {lost.id_type === "COLLEGE" ? (
                <>
                  <Select
                    label="College Course"
                    name="course"
                    value={lost.course}
                    onChange={updateLost}
                    full
                  >
                    {COURSES.map((c) => (
                      <option key={c}>{c}</option>
                    ))}
                  </Select>
                  <Field
                    label="Student Number"
                    name="student_number"
                    value={lost.student_number}
                    onChange={updateLost}
                    full
                  />
                </>
              ) : (
                <>
                  <Select
                    label={
                      lost.id_type === "JUNIOR_HIGH"
                        ? "Grade Level"
                        : "Grade Level"
                    }
                    name="grade_level"
                    value={lost.grade_level}
                    onChange={updateLost}
                  >
                    {(lost.id_type === "JUNIOR_HIGH"
                      ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"]
                      : ["GRADE 11", "GRADE 12"]
                    ).map((g) => (
                      <option key={g}>{g}</option>
                    ))}
                  </Select>
                  <Field
                    label="Section"
                    name="section_name"
                    value={lost.section_name}
                    onChange={updateLost}
                  />
                  <Field
                    label="Student / ID Number"
                    name="student_number"
                    value={lost.student_number}
                    onChange={updateLost}
                  />
                  <Field
                    label="LRN (optional)"
                    name="lrn"
                    value={lost.lrn}
                    onChange={updateLost}
                  />
                </>
              )}

              <div className="field full">
                <span>Payment Receipt (optional)</span>
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,application/pdf"
                  onChange={(e) => setReceiptFile(e.target.files?.[0] || null)}
                />
                <small className="upload-hint">
                  {receiptFile
                    ? `Selected: ${receiptFile.name}`
                    : "PNG, JPG, WEBP image or PDF of the reprinting payment receipt."}
                </small>
              </div>
            </div>

            <div className="form-actions">
              <small>
                Your details will be verified against the IDs already created
                in the system.
              </small>
              <button className="primary" onClick={submitLost} disabled={busy}>
                {busy ? (
                  <>
                    <span className="s-spin" aria-hidden="true" />
                    Submitting…
                  </>
                ) : (
                  "Submit Request"
                )}
              </button>
            </div>
          </section>
        </main>
      </div>
    );

  if (step === "lost_done")
    return (
      <div className="student-shell">
        <StudentTopBar onStaffLogin={onStaffLogin} />
        <main className="student-landing">
          <SuccessMedal />
          <h1>Lost ID Request Submitted</h1>
          <p className="student-note">
            Your lost ID reprint request has been submitted to the
            administrator. The school will review your details and payment
            receipt before reprinting your ID. Please wait for confirmation.
          </p>
          {submittedId && (
            <p className="student-ref">
              Request No.: <b>#{submittedId}</b>
            </p>
          )}
          {verifiedRefId && (
            <p className="student-note">
              Your ID was found in the school system (ID record #
              {verifiedRefId}). No need to create a new one.
            </p>
          )}
          <button className="primary student-cta" onClick={reset}>
            Back to Home
          </button>
        </main>
      </div>
    );

  if (step === "done")
    return (
      <div className="student-shell">
        <StudentTopBar onStaffLogin={onStaffLogin} />
        <main className="student-landing">
          <SuccessMedal />
          <h1>ID Request Submitted</h1>
          <p className="student-note">
            Your student ID request has been saved to the Lake Shore Colleges
            ID Management System. Please wait for the school to verify and
            process your ID.
          </p>
          {submittedId && (
            <p className="student-ref">
              Reference No.: <b>#{submittedId}</b>
            </p>
          )}
          <button className="primary student-cta" onClick={reset}>
            Create Another ID
          </button>
          <p className="student-foot">
            <button type="button" className="link-btn" onClick={reset}>
              ← Back to Home
            </button>
          </p>
        </main>
      </div>
    );

  return null;
}

function App() {
  const [user, setUser] = useState(null);
  const [authReady, setAuthReady] = useState(false);
  const [staffLogin, setStaffLogin] = useState(false);
  const [view, setView] = useState("dashboard");
  /* Photo picked from the library BEFORE the student row exists. save() clones
   * it into the new student's library so the Create-ID flow needs no pre-save. */
  const [pendingPhotoId, setPendingPhotoId] = useState(null); // photo-library pick awaiting first save
  const [pendingCloneMsg, setPendingCloneMsg] = useState("");
  /* User info dropdown in the app bar (User Accounts / Audit Logs shortcuts) */
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  useEffect(() => {
    if (!userMenuOpen) return;
    /* Refresh the "Audit Logs" badge count whenever the menu opens */
    if (user && user.role === "admin") refreshAuditCount();
    const onDocDown = (e) => {
      if (!e.target || !e.target.closest || !e.target.closest(".user-menu-wrap"))
        setUserMenuOpen(false);
    };
    const onKey = (e) => {
      if (e.key === "Escape") setUserMenuOpen(false);
    };
    document.addEventListener("mousedown", onDocDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDocDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [userMenuOpen]);
  const [card, setCard] = useState(emptyCard);
  const [cards, setCards] = useState([]);
  const [signatories, setSignatories] = useState([]);
  const [msg, setMsg] = useState("");
  const [loading, setLoading] = useState(false);
  const [filter, setFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [releaseNotes, setReleaseNotes] = useState("");
  const [studentReceived, setStudentReceived] = useState(false);
  const [users, setUsers] = useState([]);
  const [userFilter, setUserFilter] = useState("");
  const [userFormOpen, setUserFormOpen] = useState(false);
  const [userForm, setUserForm] = useState({
    id: "",
    username: "",
    full_name: "",
    role: "staff",
    password: "",
    is_active: true,
  });
  const [requests, setRequests] = useState([]);
  const [reqFilter, setReqFilter] = useState("");
  const [activeRequestId, setActiveRequestId] = useState(null);
  const [printing, setPrinting] = useState(false);
  const [templates, setTemplates] = useState([]);
  const [templateFilter, setTemplateFilter] = useState("");
  const [editingTemplate, setEditingTemplate] = useState(null);
  const [auditRows, setAuditRows] = useState([]);
  const [auditPage, setAuditPage] = useState(1);
  const [auditPages, setAuditPages] = useState(1);
  const [auditTotal, setAuditTotal] = useState(0);
  const [auditQ, setAuditQ] = useState("");
  const [auditAction, setAuditAction] = useState("");
  const [auditUserQ, setAuditUserQ] = useState("");
  const [auditFrom, setAuditFrom] = useState("");
  const [auditTo, setAuditTo] = useState("");
  const [auditActions, setAuditActions] = useState([]);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditDetail, setAuditDetail] = useState(null);
  /* Print History (admin view + dashboard card count) */
  const [phRows, setPhRows] = useState([]);
  const [phPage, setPhPage] = useState(1);
  const [phPages, setPhPages] = useState(1);
  const [phTotal, setPhTotal] = useState(0);
  const [phQ, setPhQ] = useState("");
  const [phUser, setPhUser] = useState("");
  const [phType, setPhType] = useState("");
  const [phFrom, setPhFrom] = useState("");
  const [phTo, setPhTo] = useState("");
  const [phSummary, setPhSummary] = useState(null);
  const [phLoading, setPhLoading] = useState(false);
  /* Reprint reason dialog (required for every print after the first) */
  const [reprintOpen, setReprintOpen] = useState(false);
  const [reprintChoice, setReprintChoice] = useState("Damaged");
  const [reprintText, setReprintText] = useState("");
  /* Dashboard Analytics — aggregated server-side (dashboardStats action) so
   * totals/charts are computed by SQL, never by loading every record. */
  const [dashStats, setDashStats] = useState(null);
  const [dashLoading, setDashLoading] = useState(false);
  const [dashFrom, setDashFrom] = useState("");
  const [dashTo, setDashTo] = useState("");
  const [dashDept, setDashDept] = useState("");
  const [dashStatus, setDashStatus] = useState("");
  /* CSV / Excel Student Import workflow state */
  const [importFile, setImportFile] = useState(null);
  const [importPreview, setImportPreview] = useState(null);
  const [importLoading, setImportLoading] = useState(false);
  const [importResult, setImportResult] = useState(null);
  /* Bulk ID Generation workflow state */
  const [bulkModalOpen, setBulkModalOpen] = useState(false);
  const [bulkSelected, setBulkSelected] = useState([]);
  const [bulkPreview, setBulkPreview] = useState(null);
  const [bulkGenerating, setBulkGenerating] = useState(false);
  const [bulkProgress, setBulkProgress] = useState({ current: 0, total: 0, failed: 0 });
  const [bulkResults, setBulkResults] = useState(null);
  const [bulkTemplateId, setBulkTemplateId] = useState("");
  /* ---- Batch Printing (multiple IDs in one Smart ID 51 job) state ---- */
  const [printSel, setPrintSel] = useState([]);
  const [bulkPrintOpen, setBulkPrintOpen] = useState(false);
  const [bpChoice, setBpChoice] = useState("Damaged");
  const [bpText, setBpText] = useState("");
  const [bpPrinting, setBpPrinting] = useState(false);
  const [bpProgress, setBpProgress] = useState({
    current: 0,
    total: 0,
    phase: "render",
  });
  const [bpResult, setBpResult] = useState(null);
  const counts = useMemo(
    () => ({
      COLLEGE: cards.filter((x) => x.id_type === "COLLEGE").length,
      JUNIOR_HIGH: cards.filter((x) => x.id_type === "JUNIOR_HIGH").length,
      SENIOR_HIGH: cards.filter((x) => x.id_type === "SENIOR_HIGH").length,
    }),
    [cards],
  );
  const pendingRequests = useMemo(
    () => requests.filter((x) => x.status === "pending"),
    [requests],
  );
  const shownRequests = useMemo(
    () =>
      requests.filter((x) =>
        `${x.student_name} ${x.course} ${x.grade_level} ${x.section_name} ${x.student_number} ${x.lrn} ${x.status}`
          .toLowerCase()
          .includes(reqFilter.toLowerCase()),
      ),
    [requests, reqFilter],
  );
  const shownTemplates = useMemo(
    () =>
      templates.filter((t) =>
        `${t.name} ${typeTitles[t.id_type] || ""} ${t.id_type}`
          .toLowerCase()
          .includes(templateFilter.toLowerCase()),
      ),
    [templates, templateFilter],
  );
  const activeTemplateFor = (idType) =>
    templates.find((t) => t.id_type === idType && String(t.is_active) === "1") || null;
  async function loadTemplates() {
    try {
      const r = await api("templates");
      setTemplates(r.data);
    } catch (e) {
      /* keep current list */
    }
  }
  async function load() {
    try {
      const [c, s] = await Promise.all([api("cards"), api("signatories")]);

      setCards(c.data);
      setSignatories(s.data);

      /*
       * If currently editing an ID,
       * automatically apply the correct signatory.
       */
      if (card.id_type) {
        applyDepartmentSignatory(card.id_type, s.data);
      }
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function loadUsers() {
    try {
      const r = await api("users");
      setUsers(r.data);
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function loadRequests() {
    try {
      const r = await api("lostIdRequests");
      setRequests(r.data);
    } catch (e) {
      /* nothing - keep current list */
    }
  }
  async function loadAudit() {
    setAuditLoading(true);
    try {
      const params = { page: auditPage, per_page: 15 };
      if (auditQ) params.search = auditQ;
      if (auditAction) params.action_type = auditAction;
      if (auditUserQ) params.user_id = auditUserQ;
      if (auditFrom) params.date_from = auditFrom;
      if (auditTo) params.date_to = auditTo;
      const r = await api("auditLogs", { params });
      setAuditRows(r.data || []);
      setAuditActions(r.actions || []);
      setAuditPage(r.pagination?.page || 1);
      setAuditPages(r.pagination?.total_pages || 1);
      setAuditTotal(r.pagination?.total || 0);
    } catch (e) {
      /* keep current list */
    } finally {
      setAuditLoading(false);
    }
  }
  /* Lightweight total for the account-menu badge — does not touch the
   * visible audit list (loadAudit keeps the user's filters and page). */
  async function refreshAuditCount() {
    try {
      const r = await api("auditLogs", { params: { page: 1, per_page: 1 } });
      setAuditTotal(r.pagination?.total || 0);
    } catch (e) {
      /* keep the last known total */
    }
  }
  async function loadPrintHistory() {
    setPhLoading(true);
    try {
      const params = { page: phPage, per_page: 15 };
      if (phQ) params.search = phQ;
      if (phUser) params.user_id = phUser;
      if (phType) params.type = phType;
      if (phFrom) params.date_from = phFrom;
      if (phTo) params.date_to = phTo;
      const r = await api("printHistory", { params });
      setPhRows(r.data || []);
      setPhPage(r.pagination?.page || 1);
      setPhPages(r.pagination?.total_pages || 1);
      setPhTotal(r.pagination?.total || 0);
      setPhSummary(r.summary || null);
    } catch (e) {
      /* keep current list */
    } finally {
      setPhLoading(false);
    }
  }
  async function logout() {
    try {
      await api("logout", { method: "POST" });
    } catch (e) {}
    setUser(null);
    setStaffLogin(false);
    csrfToken = null;
    setCards([]);
    setSignatories([]);
    setRequests([]);
    setActiveRequestId(null);
    setTemplates([]);
    setEditingTemplate(null);
    setCard(emptyCard);
    setView("dashboard");
  }
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const r = await api("me");
        if (!cancelled) setUser(r.data);
      } catch (e) {
        if (!cancelled) setUser(null);
      } finally {
        if (!cancelled) setAuthReady(true);
      }
    })();
    const onExpired = () => {
      setUser(null);
      setCards([]);
      setSignatories([]);
      setMsg("Your session has expired. Please sign in again.");
    };
    window.addEventListener("lsc-session-expired", onExpired);
    return () => {
      cancelled = true;
      window.removeEventListener("lsc-session-expired", onExpired);
    };
  }, []);
  useEffect(() => {
    if (user) load();
  }, [user]);
  useEffect(() => {
    if (user && user.role === "admin") {
      loadUsers();
      /* Dashboard "Print History" card: prints recorded this month */
      api("printHistory", { params: { page: 1, per_page: 1 } })
        .then((r) => setPhSummary(r.summary || null))
        .catch(() => {});
      /* Count for the account-menu "Audit Logs" badge */
      refreshAuditCount();
    }
  }, [user]);
  useEffect(() => {
    if (user) loadRequests();
  }, [user]);
  useEffect(() => {
    if (user) loadTemplates();
  }, [user]);
  useEffect(() => {
    if (user && user.role === "admin" && view === "audit") {
      const t = setTimeout(loadAudit, 200);
      return () => clearTimeout(t);
    }
  }, [user, view, auditPage, auditQ, auditAction, auditUserQ, auditFrom, auditTo]);
  useEffect(() => {
    if (user && user.role === "admin" && view === "printhistory") {
      const t = setTimeout(loadPrintHistory, 200);
      return () => clearTimeout(t);
    }
  }, [user, view, phPage, phQ, phUser, phType, phFrom, phTo]);
  /* Dashboard Analytics: (re)load whenever the dashboard becomes visible or a
   * filter changes, then poll every 30s so the numbers stay near real-time. */
  async function loadDashboardStats() {
    setDashLoading(true);
    try {
      const params = {};
      if (dashFrom) params.date_from = dashFrom;
      if (dashTo) params.date_to = dashTo;
      if (dashDept) params.id_type = dashDept;
      if (dashStatus) params.status = dashStatus;
      const r = await api("dashboardStats", { params });
      setDashStats(r.data || null);
    } catch (e) {
      /* keep the last good snapshot on transient failures */
    } finally {
      setDashLoading(false);
    }
  }
  useEffect(() => {
    if (!user || view !== "dashboard") return undefined;
    const t = setTimeout(loadDashboardStats, 200);
    const iv = setInterval(loadDashboardStats, 30000);
    return () => {
      clearTimeout(t);
      clearInterval(iv);
    };
  }, [user, view, dashFrom, dashTo, dashDept, dashStatus]);
  const update = (n, v) => setCard((p) => ({ ...p, [n]: v }));
  function create(type) {
    const next = {
      ...emptyCard,
      id_type: type,
      grade_level:
        type === "JUNIOR_HIGH"
          ? "GRADE 7"
          : type === "SENIOR_HIGH"
            ? "GRADE 11"
            : "",
      course: type === "COLLEGE" ? COURSES[0] : "",
    };

    const act = activeTemplateFor(type);
    next.template_id = act ? String(act.id) : "";

    setActiveRequestId(null);
    setCard(next);
    setPendingPhotoId(null);
    setPendingCloneMsg("");
    setView("editor");
    window.scrollTo(0, 0);

    if (signatories.length > 0) {
      applyDepartmentSignatory(type, signatories);
    }
  }
 function changeType(t) {
  const next = {
    ...card,
    id_type: t,
  };

  if (t === "COLLEGE") {
    next.course = COURSES.includes(next.course)
      ? next.course
      : COURSES[0];

    next.grade_level = "";
    next.section_name = "";
    next.student_id_number = "";
    next.lrn = "";
  }

  if (t === "JUNIOR_HIGH") {
    next.course = "";
    next.grade_level = [
      "GRADE 7",
      "GRADE 8",
      "GRADE 9",
      "GRADE 10",
    ].includes(next.grade_level)
      ? next.grade_level
      : "GRADE 7";
  }

  if (t === "SENIOR_HIGH") {
    next.course = "";
    next.grade_level = [
      "GRADE 11",
      "GRADE 12",
    ].includes(next.grade_level)
      ? next.grade_level
      : "GRADE 11";
  }

  /* Keep the template in sync with the department: the picker only lists
   * templates of the new type, so a stale id would silently deselect. */
  const act = activeTemplateFor(t);
  next.template_id = act ? String(act.id) : "";
  setCard(next);
}
  /* ---- CSV / Excel Student Import ---- */
  async function handleImportUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const ext = file.name.split(".").pop().toLowerCase();
    if (!["csv", "xlsx", "xls"].includes(ext)) {
      alert("Please select a .csv, .xlsx or .xls file.");
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      alert("File is too large (max 10 MB).");
      return;
    }
    setImportFile(file);
    setImportLoading(true);
    setImportPreview(null);
    setImportResult(null);
    try {
      const formData = new FormData();
      formData.append("file", file);
      const r = await api("importUpload", {
        method: "POST",
        body: formData,
      });
      setImportPreview({
        counts: r.data.counts,
        rows: r.data.rows,
        sheet_errors: r.data.sheet_errors,
      });
    } catch (err) {
      alert("Import validation failed: " + (err.message || "Unknown error"));
    } finally {
      setImportLoading(false);
    }
  }
  async function handleImportCommit() {
    if (!importPreview) return;
    const validRows = importPreview.rows.filter((r) => r.status === "valid");
    if (validRows.length === 0) {
      alert("No valid rows to import.");
      return;
    }
    if (!confirm(`Import ${validRows.length} valid student${validRows.length === 1 ? "" : "s"} into the system?`)) return;
    setImportLoading(true);
    try {
      const r = await api("importCommit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ rows: validRows }),
      });
      setImportResult({
        count: r.imported,
        ids: r.ids,
        message: r.message || `${r.imported} students imported successfully`,
      });
      setImportFile(null);
      setImportPreview(null);
      // Refresh the records list
      if (view === "records") load();
    } catch (err) {
      alert("Import failed: " + (err.message || "Unknown error"));
    } finally {
      setImportLoading(false);
    }
  }
  function handleTemplateDownload() {
    window.open(`${API_URL}?action=importTemplate`, "_blank");
  }
  async function handleGenerateIds() {
    /* After a CSV import, jump straight into the bulk flow: pre-select the
     * imported IDs, open the modal, and let validate -> preview -> ZIP run. */
    if (!importResult || !importResult.ids || importResult.ids.length === 0) return;
    setBulkSelected([...importResult.ids]);
    setBulkPreview(null);
    setBulkResults(null);
    setBulkTemplateId("");
    setImportResult(null);
    setView("records");
    setFilter("");
    await load();
    setBulkModalOpen(true);
  }
  async function save() {
    try {
      setLoading(true);
      const r = await api("saveCard", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(card),
      });
      setCard((p) => ({
        ...p,
        id: r.id,
        status: r.status || p.status || "created",
      }));
      if (activeRequestId) {
        try {
          await api("updateLostIdRequest", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              id: activeRequestId,
              reference_card_id: r.id,
              status: "approved",
            }),
          });
          setActiveRequestId(null);
          await loadRequests();
        } catch (e) {
          /* keep editing */
        }
      }
      /* Pre-save library pick: clone the chosen photo into the new
       * student's library now that the row exists, then select it. */
      if (pendingPhotoId && r.id) {
        try {
          const cloneRes = await api("photoLibraryClone", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ photo_id: pendingPhotoId, student_id: r.id }),
          });
          const newPhotoId = cloneRes.photo_id || pendingPhotoId;
          setCard((p) => ({
            ...p,
            id: r.id,
            status: r.status || p.status || "created",
            preferred_photo_id: newPhotoId,
            photo_path: cloneRes.path || p.photo_path,
          }));
          setPendingPhotoId(null);
          setPendingCloneMsg("Photo attached to this student from the library.");
        } catch (e) {
          setPendingCloneMsg(e.message || "Could not attach the selected photo.");
        }
      }
      setMsg("ID saved successfully");
      await load();
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }
  /* ---- Bulk ID Generation ---- */
  async function validateBulkStudents() {
    /* Validate each selected student and categorize results.
     * NOTE: api("card&id=..") resolves to ?action=card&id=.. and returns
     * { success, data: row }, so unwrap `.data` before reading fields. */
    if (!bulkSelected || bulkSelected.length === 0) {
      alert("Select at least one student first.");
      return;
    }
    const results = [];
    const seenIdNumbers = new Set();
    for (const id of bulkSelected) {
      try {
        const r = await api(`card&id=${id}`);
        const student = (r && r.data) || r;
        let status = "ready";
        const issues = [];

        /* Check required fields */
        if (!student.student_name || !String(student.student_name).trim()) {
          issues.push("Missing student name");
          status = "incomplete";
        }
        if (!student.student_number && !student.student_id_number && !student.lrn) {
          issues.push("Missing ID number");
          status = "incomplete";
        }
        if (!student.id_type) {
          issues.push("Missing department/type");
          status = "incomplete";
        }

        /* Check if already has a generated ID */
        if (student.status && (student.status === "done" || student.status === "printed" || student.status === "released")) {
          status = "already_generated";
        }

        /* Check for duplicate ID numbers */
        const idNumber = student.student_number || student.student_id_number || student.lrn || "";
        if (idNumber && seenIdNumbers.has(idNumber)) {
          issues.push("Duplicate ID number");
          status = "duplicate";
        }
        seenIdNumbers.add(idNumber);

        results.push({ id, student, status, issues });
      } catch (err) {
        results.push({ id, student: null, status: "error", issues: [err.message || "Failed to load student data"] });
      }
    }

    setBulkPreview({
      results,
      counts: {
        ready: results.filter((r) => r.status === "ready").length,
        incomplete: results.filter((r) => r.status === "incomplete").length,
        already_generated: results.filter((r) => r.status === "already_generated").length,
        duplicate: results.filter((r) => r.status === "duplicate").length,
        error: results.filter((r) => r.status === "error").length,
        total: results.length,
      },
    });
  }
  async function runBulkGeneration() {
    if (!bulkPreview) return;
    const readyStudents = bulkPreview.results.filter((r) => r.status === "ready");
    if (readyStudents.length === 0) {
      alert("No valid students to generate IDs for.");
      return;
    }
    /* Resolve the template each rendered card needs: explicit bulk choice
     * wins, otherwise the active template for that student's department. */
    const templateForStudent = (student) => {
      if (bulkTemplateId) return templates.find((t) => String(t.id) === String(bulkTemplateId)) || null;
      if (student.template_id) return templates.find((t) => String(t.id) === String(student.template_id)) || null;
      return activeTemplateFor(student.id_type);
    };
    const missingTemplate = readyStudents.filter((r) => !templateForStudent(r.student));
    if (missingTemplate.length > 0) {
      alert(
        `No ID template found for ${missingTemplate.length} student(s). ` +
          `Select a template above or activate one for their department first.`,
      );
      return;
    }
    setBulkGenerating(true);
    setBulkProgress({ current: 0, total: readyStudents.length, failed: 0 });
    setBulkResults(null);

    const zip = new JSZip();
    const generated = [];
    const failed = [];

    try {
      for (let i = 0; i < readyStudents.length; i++) {
        const item = readyStudents[i];
        const student = item.student;
        setBulkProgress({
          current: i + 1,
          total: readyStudents.length,
          failed: failed.length,
        });

        try {
          /* Bulk render uses the existing stored record as-is (no duplicate
           * re-save). Resolve the effective template, mark the card done,
           * then rasterise both faces into the ZIP. */
          const template = templateForStudent(student);
          const fullCard = {
            ...student,
            template_id: template ? String(template.id) : student.template_id || "",
          };

          try {
            await api("setCardStatus", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ id: student.id, status: "done" }),
            });
            fullCard.status = "done";
          } catch (e) {
            /* Non-fatal: a card that is already done/printed keeps its
             * status; validation already filtered terminal states. */
          }

          /* Generate front and back images without touching the live UI */
          const frontCanvas = await renderCardCanvasForBulk(fullCard, "front", template);
          const backCanvas = await renderCardCanvasForBulk(fullCard, "back", template);

          const safeName = String(fullCard.student_name || `student_${fullCard.id}`)
            .replace(/[^\w\-]+/g, "_")
            .slice(0, 60);
          const studentId = safeName || `student_${fullCard.id}`;

          if (frontCanvas) {
            const frontBlob = await new Promise((resolve) =>
              frontCanvas.toBlob((b) => resolve(b), "image/png")
            );
            if (frontBlob) zip.file(`${studentId}_front.png`, frontBlob);
          }
          if (backCanvas) {
            const backBlob = await new Promise((resolve) =>
              backCanvas.toBlob((b) => resolve(b), "image/png")
            );
            if (backBlob) zip.file(`${studentId}_back.png`, backBlob);
          }

          generated.push({ id: student.id, name: student.student_name, saved_id: fullCard.id });
        } catch (err) {
          failed.push({ id: student.id, name: student.student_name, error: err.message || "Unknown error" });
        }

        /* Yield to event loop periodically to prevent browser freezing */
        await new Promise((resolve) => setTimeout(resolve, i % 10 === 9 ? 50 : 5));
      }

      /* Generate ZIP */
      if (generated.length > 0) {
        const zipBlob = await zip.generateAsync({ type: "blob" });
        const deptPrefix = readyStudents[0]?.student?.id_type === "COLLEGE" ? "College"
          : readyStudents[0]?.student?.id_type === "JUNIOR_HIGH" ? "JuniorHigh"
          : readyStudents[0]?.student?.id_type === "SENIOR_HIGH" ? "SeniorHigh"
          : "students";
        const yr = readyStudents[0]?.student?.school_year || new Date().getFullYear();
        saveAs(zipBlob, `${deptPrefix}_${yr}_IDs.zip`);
      }

      /* Record audit log */
      try {
        await api("bulkGenerateAudit", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            total: readyStudents.length,
            succeeded: generated.length,
            failed: failed.length,
            template_id: bulkTemplateId,
            generated_ids: generated.map((g) => g.saved_id),
          }),
        });
      } catch (e) {
        /* Audit logging is non-critical */
      }

      setBulkResults({ generated, failed });
      alert(`${generated.length} IDs generated and downloaded successfully.\n${failed.length} failed.`);
      setBulkModalOpen(false);
      await load();
    } catch (err) {
      alert("Bulk generation failed: " + (err.message || "Unknown error"));
      setBulkResults({ generated: [], failed });
    } finally {
      setBulkGenerating(false);
    }
  }
  /* Rows currently visible in the ID table after the search + status filters.
   * Declared BEFORE the Batch Printing block below: `shownEligible` is computed
   * during render, so `shown` must already be initialised here (a `const` used
   * before its declaration line throws a TDZ ReferenceError and crashes the
   * whole app with a white screen). */
  const shown = cards.filter(
    (x) =>
      (!statusFilter || x.status === statusFilter) &&
      `${x.student_name} ${x.student_number} ${x.student_id_number} ${x.lrn} ${x.course} ${x.grade_level} ${x.section_name}`
        .toLowerCase()
        .includes(filter.toLowerCase()),
  );
  /* ---- Batch Printing (several IDs in ONE Smart ID 51 job) ---- */
  /* Printable = the same rule the single print enforces server-side:
   * released IDs are terminal and can never be printed again. */
  const eligibleForPrint = (x) => x && x.status !== "released";
  const shownEligible = shown.filter(eligibleForPrint);
  const printEligibleSelected = cards.filter(
    (x) => printSel.includes(x.id) && eligibleForPrint(x),
  );
  const allShownEligibleSelected =
    shownEligible.length > 0 &&
    shownEligible.every((x) => printSel.includes(x.id));
  function togglePrintRow(id, on) {
    setPrintSel((p) =>
      on ? (p.includes(id) ? p : [...p, id]) : p.filter((x) => x !== id),
    );
  }
  /* Header "Select All" checkbox: ticks every printable row currently shown
   * (released rows are excluded — they cannot be printed). */
  function togglePrintAll(on) {
    if (on) {
      const add = shownEligible
        .map((x) => x.id)
        .filter((id) => !printSel.includes(id));
      if (add.length) setPrintSel([...printSel, ...add]);
    } else {
      setPrintSel((p) =>
        p.filter((id) => !shownEligible.some((x) => x.id === id)),
      );
    }
  }
  function resetBatchPrintDialog() {
    setBpChoice("Damaged");
    setBpText("");
    setBpResult(null);
    setBpProgress({ current: 0, total: 0, phase: "render" });
  }
  function openBatchPrint() {
    if (printEligibleSelected.length === 0) {
      setMsg(
        "Select at least one printable ID first (released IDs cannot be printed).",
      );
      return;
    }
    resetBatchPrintDialog();
    setBulkPrintOpen(true);
  }
  /* Print All: tick every printable ID in the current (filtered) list and
   * open the same confirmation dialog, so the exact count is always shown
   * before anything is sent to the printer. */
  function printAllEligible() {
    if (shownEligible.length === 0) {
      setMsg("No printable IDs in the current list (released IDs are excluded).");
      return;
    }
    setPrintSel(shownEligible.map((x) => x.id));
    resetBatchPrintDialog();
    setBulkPrintOpen(true);
  }
  async function runBatchPrint(reason) {
    if (bpPrinting) return;
    const batch = printEligibleSelected.slice(0, MAX_BULK_PRINT);
    if (batch.length === 0) {
      alert("No printable IDs selected.");
      return;
    }
    /* Resolve the template for each card the same way bulk generation does:
     * the card's own template first, then the active template for the
     * student's department. */
    const templateForStudent = (student) => {
      if (student.template_id) {
        const own = templates.find(
          (t) => String(t.id) === String(student.template_id),
        );
        if (own) return own;
      }
      return activeTemplateFor(student.id_type);
    };
    const missingTemplate = batch.filter((s) => !templateForStudent(s));
    if (missingTemplate.length > 0) {
      alert(
        `No ID template found for ${missingTemplate.length} selected student(s). ` +
          `Activate a template for their department first.`,
      );
      return;
    }
    setBpPrinting(true);
    setBpResult(null);
    setBpProgress({ current: 0, total: batch.length, phase: "render" });
    try {
      /* Render every front + back off-screen with the SAME renderer used by
       * bulk generation and the live preview — no duplicate card markup. */
      const faces = [];
      for (let i = 0; i < batch.length; i++) {
        const student = batch[i];
        const template = templateForStudent(student);
        const fullCard = {
          ...student,
          template_id: template
            ? String(template.id)
            : student.template_id || "",
        };
        const front = await renderCardCanvasForBulk(fullCard, "front", template);
        const back = await renderCardCanvasForBulk(fullCard, "back", template);
        if (!front || !back) {
          throw new Error(
            `Could not render the ID for ${student.student_name || "#" + student.id}.`,
          );
        }
        faces.push({
          id: student.id,
          name: student.student_name,
          front: front.toDataURL("image/png"),
          back: back.toDataURL("image/png"),
        });
        setBpProgress({ current: i + 1, total: batch.length, phase: "render" });
        /* Yield to the event loop so the progress bar keeps painting. */
        await new Promise((r) => setTimeout(r, i % 10 === 9 ? 40 : 5));
      }
      /* ONE dual-side job: front, back, front, back, … (Smart ID 51). */
      setBpProgress({ current: 0, total: 1, phase: "send" });
      await printFacesDualSide(
        faces,
        `Smart ID 51 — Batch (${faces.length} ID${faces.length === 1 ? "" : "s"})`,
      );
      /* Record EVERY printed ID through the existing tracking system
       * (print_history + status sync + audit) — bulk never bypasses it. */
      setBpProgress({ current: 1, total: 1, phase: "record" });
      const r = await api("bulkPrint", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: faces.map((f) => f.id), reason }),
      });
      const res = r.data || {};
      const printed = res.printed ?? faces.length;
      setBpResult({
        printed,
        skipped: res.skipped || [],
        missing: res.missing || [],
      });
      setMsg(
        `${printed} ID(s) sent to the Smart ID 51 and recorded in the Print History.` +
          (res.skipped?.length
            ? ` ${res.skipped.length} skipped (released).`
            : ""),
      );
      setPrintSel([]);
      await load();
    } catch (e) {
      setBpResult({ error: e.message || "Batch print failed." });
      setMsg(e.message || "Batch print failed.");
    } finally {
      setBpPrinting(false);
    }
  }
    /* Render a card face canvas for bulk generation without modifying the live UI.
   * Mounts the SAME React card components used by the live preview
   * (FrontCard / BackCard / TemplateCard) into a hidden off-screen node via
   * createRoot, rasterises with html2canvas, then unmounts and cleans up.
   * This guarantees bulk PNGs match the on-screen preview pixel-for-pixel,
   * including uploaded template designs and data zones. */
  async function renderCardCanvasForBulk(student, side, template) {
    const targetW = 1276;
    const targetH = 2022;
    const previewW = 321;
    const previewH = 506;
    const scale = targetW / previewW;
    const tpl = template || null;
    const useTemplate =
      side === "back"
        ? Boolean(tpl && (tpl.back_image || tpl.front_image))
        : Boolean(tpl && tpl.front_image);
    const backFallback = side === "back" && tpl && !tpl.back_image;

    const holder = document.createElement("div");
    holder.style.position = "absolute";
    holder.style.left = "-10000px";
    holder.style.top = "0";
    holder.style.width = previewW + "px";
    holder.style.height = previewH + "px";
    holder.style.background = "#fff";
    holder.style.overflow = "hidden";
    document.body.appendChild(holder);

    const nodeId = side === "front" ? "bulk-front-card" : "bulk-back-card";
    let root = null;
    try {
      const el =
        useTemplate && !backFallback
          ? React.createElement(TemplateCard, { card: student, template: tpl, side })
          : side === "front"
            ? React.createElement(FrontCard, { card: student })
            : React.createElement(BackCard, { card: student });
      /* Tag the rendered root with a stable id for html2canvas lookup. */
      const wrapped = React.createElement("div", { id: nodeId }, el);
      root = createRoot(holder);
      root.render(wrapped);
      /* Let React commit + images start loading. */
      await new Promise((r) => setTimeout(r, 120));

      /* Inline remote images as data URLs so html2canvas never taints. */
      const imgs = holder.querySelectorAll("img");
      await Promise.all(
        Array.from(imgs).map(async (img) => {
          const src = img.getAttribute("src");
          if (!src || src.startsWith("data:")) return;
          try {
            const dataUrl = await inlineImageAsDataURL(src);
            if (dataUrl) img.src = dataUrl;
          } catch (e) { /* keep original src */ }
        }),
      );
      /* Wait for inlined images to decode. */
      await Promise.all(
        Array.from(holder.querySelectorAll("img")).map((img) =>
          img.complete
            ? Promise.resolve()
            : new Promise((res) => { img.onload = res; img.onerror = res; }),
        ),
      );

      const node = holder.firstChild;
      if (!node) return null;
      const canvas = await html2canvas(node, {
        scale: useTemplate ? 1 : scale,
        useCORS: true,
        allowTaint: false,
        backgroundColor: "#ffffff",
        width: useTemplate ? targetW : previewW,
        height: useTemplate ? targetH : previewH,
        windowWidth: useTemplate ? targetW : previewW,
        windowHeight: useTemplate ? targetH : previewH,
        scrollX: 0,
        scrollY: 0,
        imageTimeout: 30000,
        logging: false,
        onclone: useTemplate && !backFallback
          ? (doc) => {
              const clone = doc.getElementById(nodeId);
              if (!clone) return;
              const cardEl = clone.firstChild || clone;
              cardEl.style.width = targetW + "px";
              cardEl.style.height = targetH + "px";
              cardEl.style.transform = "none";
              cardEl.style.maxWidth = "none";
              cardEl.style.maxHeight = "none";
              cardEl.style.boxShadow = "none";
              cardEl.style.borderRadius = "0";
              const bg = cardEl.querySelector(".template-bg");
              if (bg) {
                bg.style.width = "100%";
                bg.style.height = "100%";
                bg.style.objectFit = "fill";
              }
              cardEl.querySelectorAll(".template-zone").forEach((z) => {
                const L = parseFloat(z.style.left || "0") || 0;
                const T = parseFloat(z.style.top || "0") || 0;
                const W = parseFloat(z.style.width || "0") || 0;
                const H = parseFloat(z.style.height || "0") || 0;
                z.style.left = Math.round(L * scale) + "px";
                z.style.top = Math.round(T * scale) + "px";
                z.style.width = Math.round(W * scale) + "px";
                z.style.height = Math.round(H * scale) + "px";
                const fs = parseFloat(z.style.fontSize || "0") || 0;
                if (fs) z.style.fontSize = Math.round(fs * scale) + "px";
                z.style.maxWidth = "none";
                z.style.maxHeight = "none";
                z.style.display = "flex";
              });
            }
          : undefined,
      });
      /* Non-template path renders at 321*scale ≈ 1276 wide; normalise the
       * height to exactly 2022 the same way renderCardCanvas does. */
      if ((!useTemplate || backFallback) && (canvas.width !== targetW || canvas.height !== targetH)) {
        const out = document.createElement("canvas");
        out.width = targetW;
        out.height = targetH;
        const ctx = out.getContext("2d");
        if (ctx) {
          ctx.imageSmoothingEnabled = true;
          ctx.imageSmoothingQuality = "high";
          ctx.drawImage(canvas, 0, 0, targetW, targetH);
          return out;
        }
      }
      return canvas;
    } finally {
      try { if (root) root.unmount(); } catch (e) { /* ignore */ }
      if (holder.parentNode) holder.parentNode.removeChild(holder);
    }
  }
  /* Render front card HTML for bulk generation (legacy fallback, unused by
   * the React-component renderer above but kept for reference). */
  function renderFrontCardHTML(c) {
    const photo = assetUrl(c.photo_path);
    const color = gradeColors[c.grade_level] || "#205f38";
    return `
      <div class="top-wave" style="background:${color}"></div>
      <div class="front-header"><div class="brand">LAKE SHORE COLLEGES</div>
      <div class="formerly">formerly Lake Shore Educational Institution</div>
      <div class="address-small">A. Bonifacio St., Brgy. Canlalay, City of Biñan, Laguna, Philippines</div></div>
      <div class="department">${typeTitles[c.id_type]}</div>
      <div class="front-main">
        <div class="photo-frame">${photo ? `<img src="${photo}" style="width:100%;height:100%;object-fit:cover" />` : '<div class="photo-placeholder">ID<br/>PHOTO</div>'}
        </div>
        <div class="front-right">
          <div class="student-number"><span>STUDENT ID</span><b>${c.student_id_number || ""}</b>
          <span>STUDENT NUMBER</span><b>${c.student_number || ""}</b>
          ${c.id_type === "COLLEGE" ? `<span>COURSE</span><b>${c.course || ""}</b>` : `<span>GRADE & SECTION</span><b>${c.grade_level || ""} ${c.section_name || ""}</b>`}
          ${c.lrn ? `<span>LRN</span><b>${c.lrn}</b>` : ""}</div>
          <div class="student-name">${c.student_name || ""}</div>
        </div>
      </div>
    `;
  }
  /* Render back card HTML for bulk generation */
  function renderBackCardHTML(c) {
    const sig = assetUrl(c.signature_path);
    return `
      <div class="back-address" style="background:#fff;border:2px solid #000;color:#000;padding:8px 10px;border-radius:6px;font-size:11px;line-height:1.45;margin-bottom:10px">
        <strong>Address:</strong><div>${c.address_line1 || ""}</div><div>${c.address_line2 || ""}</div>
      </div>
      <div class="emergency" style="font-size:11px;background:#205f38;color:#fff;border-radius:6px;padding:8px 10px;margin-bottom:10px">
        <strong>${c.emergency_label || "In case of emergency, please notify"}</strong><div>${c.emergency_contact || ""}</div><div>${c.emergency_phone || ""}</div>
      </div>
      <div class="terms" style="color:#000"><h3>${c.terms_title || "Terms and Conditions"}</h3>
        <p>- ${c.term_1 || ""}</p><p>- ${c.term_2 || ""}</p><p>- ${c.term_3 || ""}</p></div>
      <div class="back-school" style="color:#000;border-top:1px solid #000">
        <b>${c.institution_name || ""}</b>
        <div>${c.institution_address || ""}</div>
        <div>${c.mobile_no || ""}</div>
        <div>${c.telephone_no || ""}</div>
        <div>${c.email_address || ""}</div>
      </div>
      ${sig ? `<div class="signature"><img src="${sig}" style="max-width:100px" /><div class="signature-line"></div><div>${c.signatory_name || ""}</div></div>` : ""}
    `;
  }
  /* Open a saved record in the editor.
   * Wired to "Edit / View" in the Saved IDs table and to a lost-ID reprint
   * request that already has a reference card, so it must LOAD the row — the
   * id argument is the record to open, not the one currently in the editor. */
  async function edit(id) {
    const cardId = Number(id);
    if (!cardId) return;
    try {
      setLoading(true);
      const r = await api(`card&id=${cardId}`);
      /* ?action=card&id=.. answers { success, data: row } — unwrap .data
       * before reading fields (same unwrap the bulk flows use). */
      const row = (r && r.data) || r || {};
      const next = { ...emptyCard, ...row, id: cardId };
      /* The picker is a <select>, so the FK must be a string to match an
       * <option value>; a numeric id would silently show "no template". */
      next.template_id = row.template_id ? String(row.template_id) : "";
      setActiveRequestId(null);
      setPendingPhotoId(null);
      setPendingCloneMsg("");
      setCard(next);
      setView("editor");
      setMsg("");
      window.scrollTo(0, 0);
      if (signatories.length > 0) applyDepartmentSignatory(next.id_type, signatories);
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }
  /*
   * Mark a saved ID as Done (created/edited -> done) once staff have reviewed
   * it — the "ready for printing" state in the ID lifecycle.
   *
   * NOTE: this handler is referenced by the "Mark as Done" button in the
   * editor's status bar, which only renders once `card.id` is set. It was
   * missing, so every save that produced a not-yet-done record threw
   * "markCardDone is not defined" during App's render. With no error boundary
   * React unmounts the whole tree, so the staff screen went completely white
   * immediately after a successful Save ID.
   */
  async function markCardDone() {
    if (!card.id) return;
    try {
      setLoading(true);
      const r = await api("setCardStatus", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: card.id, status: "done" }),
      });
      setCard((p) => ({ ...p, ...(r.data || {}) }));
      setMsg("ID marked as Done — ready for printing.");
      await load();
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }
  /*
   * Release a printed ID to the student (PRINTED -> RELEASED).
   * Requires confirmation; stores who released it, when, optional notes and
   * whether the student physically received the card.
   */
  async function releaseCardNow() {
    if (!card.id) return;
    if (
      !window.confirm(
        "Release this ID to the student? Its status will become RELEASED.",
      )
    )
      return;
    try {
      setLoading(true);
      const r = await api("releaseCard", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: card.id,
          release_notes: releaseNotes,
          student_received: studentReceived,
        }),
      });
      setCard((p) => ({ ...p, ...r.data }));
      setMsg("ID released to the student.");
      await load();
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }
  /*
   * Direct dual-side print on the Smart ID 51.
   * Front page prints in colour and back page in black & white as configured
   * on the printer itself; this job just delivers FRONT then BACK in order.
   * Every job is recorded in the Print History:
   *   - 1st print    -> "Original" (reason defaults to "New Student")
   *   - later prints -> "Reprint"  (reason must be chosen in the dialog first)
   * while id_cards keeps its printed timestamp + counter in sync.
   */
  async function doPrintDualSide(reason) {
    if (printing) return;
    try {
      setPrinting(true);
      await printCardDualSide();
      if (card.id) {
        const r = await api("printCard", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: card.id, reason }),
        });
        setCard((p) => ({
          ...p,
          status: r.data.status,
          printed_at: r.data.printed_at,
          print_count: r.data.print_count,
        }));
        setMsg(
          r.print_type === "reprint"
            ? `Print job sent to Smart ID 51 — Reprint recorded (${r.reason}), print #${r.data.print_count}.`
            : `Print job sent to Smart ID 51 — Original print recorded, print #${r.data.print_count}.`,
        );
        await load();
      } else {
        setMsg("Print job sent to Smart ID 51. Save the ID to track its print status.");
      }
    } catch (e) {
      setMsg(e.message);
    } finally {
      setPrinting(false);
      setReprintOpen(false);
    }
  }
  /* Entry point: a repeat print must state a reason before the job runs. */
  function printDualSide() {
    if (printing) return;
    if (card.id && ((card.print_count ?? 0) > 0 || card.printed_at)) {
      setReprintChoice("Damaged");
      setReprintText("");
      setReprintOpen(true);
      return;
    }
    doPrintDualSide("New Student");
  }
  async function editRequest(req) {
    try {
      if (req.reference_card_id) return edit(req.reference_card_id);
      const next = {
        ...emptyCard,
        id_type: req.id_type || "COLLEGE",
        student_name: req.student_name || "",
        course: req.course || "",
        grade_level: req.grade_level || "",
        section_name: req.section_name || "",
        student_number: req.student_number || "",
        lrn: req.lrn || "",
      };
      if (next.id_type === "COLLEGE") {
        next.course = COURSES.includes(next.course) ? next.course : COURSES[0];
        next.grade_level = "";
        next.section_name = "";
        next.student_id_number = "";
        next.lrn = "";
      } else {
        const validGrades =
          next.id_type === "JUNIOR_HIGH"
            ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"]
            : ["GRADE 11", "GRADE 12"];
        next.course = "";
        next.grade_level = validGrades.includes(next.grade_level)
          ? next.grade_level
          : next.id_type === "JUNIOR_HIGH"
            ? "GRADE 7"
            : "GRADE 11";
        next.student_id_number = req.student_number || "";
        next.lrn = req.lrn || "";
        next.student_number = "";
      }
      const act = activeTemplateFor(next.id_type);
      next.template_id = act ? String(act.id) : "";
      setActiveRequestId(Number(req.id));
      setCard(next);
      setView("editor");
      window.scrollTo(0, 0);
      if (signatories.length > 0) applyDepartmentSignatory(next.id_type, signatories);
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function setRequestStatus(id, status) {
    if (status === "rejected" && !confirm("Reject this lost ID reprint request?")) return;
    try {
      await api("updateLostIdRequest", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, status }),
      });
      await loadRequests();
      setMsg(`Request #${id} marked as "${status}".`);
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function del(id) {
    if (!confirm("Delete this ID record?")) return;
    try {
      await api("deleteCard", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      await load();
      setMsg("Record deleted");
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function saveTemplateRecord(id) {
    await loadTemplates();
    setEditingTemplate(null);
    if (id) setMsg("Template saved successfully");
  }
  async function deleteTemplateRecord(id) {
    if (!confirm("Delete this template? This will not affect IDs already created from it."))
      return;
    try {
      await api("deleteTemplate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      await loadTemplates();
      setMsg("Template deleted");
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function setActiveTemplateRecord(id) {
    try {
      await api("setActiveTemplate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      await loadTemplates();
      const t = templates.find((x) => String(x.id) === String(id));
      setMsg(
        `"${t?.name || "Template"}" is now the active template for ${typeTitles[t?.id_type] || ""}.`,
      );
    } catch (e) {
      setMsg(e.message);
    }
  }
  function openTemplateDesigner(t = null) {
    setEditingTemplate(t ? JSON.parse(JSON.stringify(t)) : blankTemplate());
  }
  function openUserForm(u = null) {
    setUserForm(
      u
        ? {
            id: u.id,
            username: u.username,
            full_name: u.full_name,
            role: u.role,
            password: "",
            is_active: String(u.is_active) === "1",
          }
        : {
            id: "",
            username: "",
            full_name: "",
            role: "staff",
            password: "",
            is_active: true,
          },
    );
    setUserFormOpen(true);
    setMsg("");
  }
  const updateUserForm = (n, v) => setUserForm((p) => ({ ...p, [n]: v }));
  async function saveUserForm() {
    try {
      setLoading(true);
      await api("saveUser", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(userForm),
      });
      setMsg(
        userForm.id
          ? "User account updated successfully"
          : "User account created successfully",
      );
      setUserFormOpen(false);
      await loadUsers();
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }
  async function deactivateUserAccount(id) {
    if (!confirm("Deactivate this user account? The user will no longer be able to sign in."))
      return;
    try {
      await api("deleteUser", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      await loadUsers();
      setMsg("User account deactivated");
    } catch (e) {
      setMsg(e.message);
    }
  }
  async function photoUpload(file) {
    if (!file) return;
    try {
      setLoading(true);
      const fd = new FormData();
      fd.append("photo", file);
      const r = await api("uploadPhoto", { method: "POST", body: fd });
      update("photo_path", r.path);
    } catch (e) {
      setMsg(e.message);
    } finally {
      setLoading(false);
    }
  }

  function applyDepartmentSignatory(type, availableSignatories = signatories) {
    const requiredName = DEPARTMENT_SIGNATORIES[type];

    if (!requiredName) {
      return;
    }

    const signatory = availableSignatories.find(
      (s) =>
        String(s.full_name).trim().toLowerCase() ===
        requiredName.trim().toLowerCase(),
    );

    if (signatory) {
      setCard((p) => ({
        ...p,
        signatory_id: signatory.id,
        signatory_name: signatory.full_name,
        signature_path: signatory.signature_path,
      }));
    } else {
      /*
       * Keep the required name even if the database
       * signatory record has not been returned yet.
       */
      setCard((p) => ({
        ...p,
        signatory_id: "",
        signatory_name: requiredName,
        signature_path: "",
      }));
    }
  }
  /* NOTE: `shown` is declared further up in this component (just before the
   * Batch Printing block). It must be initialised before `shownEligible` is
   * computed during render — a `const` referenced before its declaration line
   * throws "Cannot access 'shown' before initialization" (TDZ), which crashes
   * <App /> on the very first render and shows only a white screen. */
  const shownUsers = users.filter((x) =>
    `${x.full_name} ${x.username} ${x.role}`
      .toLowerCase()
      .includes(userFilter.toLowerCase()),
  );
  if (!authReady)
    return (
      <div className="login-shell">
        <div className="login-card" style={{ textAlign: "center" }}>
          <div className="login-mark" style={{ margin: "0 auto 12px" }}>
            LSC
          </div>
          <div>Loading session…</div>
        </div>
      </div>
    );
  if (!user) {
    if (staffLogin)
      return (
        <Login
          onBack={() => setStaffLogin(false)}
          onLogin={(u) => {
            setUser(u);
            setMsg("");
          }}
        />
      );
    return <StudentPortal onStaffLogin={() => setStaffLogin(true)} />;
  }
  return (
    <div className="app-shell">
      <header className="app-bar">
        <div className="app-bar-brand">
          <div className="app-bar-logo">
            <span className="app-bar-logo-fallback">LSC</span>
            <img
              src={logo}
              alt="Lake Shore Colleges logo"
              onError={(e) => {
                e.currentTarget.style.display = "none";
              }}
            />
          </div>
          <div className="app-bar-title">
            <b>Lake Shore Colleges</b>
            <small>ID Management System</small>
          </div>
        </div>
        <div className="app-bar-user">
          <button
            className="notif-btn"
            onClick={() => {
              setView("requests");
              window.scrollTo(0, 0);
            }}
            title="Lost ID Reprint Requests"
          >
            <span className="notif-icon">🔔</span>
            {pendingRequests.length > 0 && (
              <span className="notif-badge">{pendingRequests.length}</span>
            )}
          </button>
          {user.role === "admin" ? (
            <div className={"user-menu-wrap" + (userMenuOpen ? " open" : "")}>
              <button
                type="button"
                className="user-chip user-chip-btn"
                title={user.username}
                aria-haspopup="menu"
                aria-expanded={userMenuOpen}
                onClick={() => setUserMenuOpen((o) => !o)}
              >
                <span className="user-avatar">
                  {(user.full_name || user.username || "U")
                    .trim()
                    .charAt(0)
                    .toUpperCase()}
                </span>
                <span className="user-meta">
                  <b>{user.full_name || user.username}</b>
                  <small>Administrator</small>
                </span>
                <span className="user-caret" aria-hidden="true">
                  ▾
                </span>
              </button>
              {userMenuOpen && (
                <div className="user-dropdown" role="menu">
                  <div className="user-dropdown-head">
                    <b>{user.full_name || user.username}</b>
                    <small>Administrator</small>
                  </div>
                  <button
                    type="button"
                    role="menuitem"
                    className={view === "users" ? "active" : ""}
                    onClick={() => {
                      setUserMenuOpen(false);
                      setUserFilter("");
                      setView("users");
                      window.scrollTo(0, 0);
                    }}
                  >
                    <span className="user-dd-icon" aria-hidden="true">👥</span>
                    <span className="user-dd-label">User Accounts</span>
                    <span className="user-dd-count">{users.length}</span>
                  </button>
                  <button
                    type="button"
                    role="menuitem"
                    className={view === "templates" ? "active" : ""}
                    onClick={() => {
                      setUserMenuOpen(false);
                      setTemplateFilter("");
                      setView("templates");
                      window.scrollTo(0, 0);
                    }}
                  >
                    <span className="user-dd-icon" aria-hidden="true">🗂</span>
                    <span className="user-dd-label">ID Templates</span>
                    <span className="user-dd-count">{templates.length}</span>
                  </button>
                  <button
                    type="button"
                    role="menuitem"
                    className={view === "audit" ? "active" : ""}
                    onClick={() => {
                      setUserMenuOpen(false);
                      setAuditPage(1);
                      setView("audit");
                      window.scrollTo(0, 0);
                    }}
                  >
                    <span className="user-dd-icon" aria-hidden="true">📜</span>
                    <span className="user-dd-label">Audit Logs</span>
                    <span className="user-dd-count">
                      {Number(auditTotal || 0).toLocaleString()}
                    </span>
                  </button>
                </div>
              )}
            </div>
          ) : (
            <div className="user-chip" title={user.username}>
              <span className="user-avatar">
                {(user.full_name || user.username || "U")
                  .trim()
                  .charAt(0)
                  .toUpperCase()}
              </span>
              <span className="user-meta">
                <b>{user.full_name || user.username}</b>
                <small>Staff</small>
              </span>
            </div>
          )}
          <button className="logout-btn" onClick={logout}>
            Logout
          </button>
        </div>
      </header>
      <main className="content">
        <header className="top">
          <div>
            <h1>
              {view === "dashboard"
                ? "Dashboard"
                : view === "records"
                  ? "Saved IDs"
                  : view === "requests"
                    ? "Lost ID Reprint Requests"
                    : view === "printhistory"
                      ? "Print History"
                      : view === "import"
                        ? "Import Students"
                        : view === "users"
                          ? "User Accounts"
                          : view === "audit"
                            ? "Audit Logs"
                            : view === "templates"
                              ? "ID Templates"
                              : view === "photoprocessing"
                                ? "Photo Processing"
                                : view === "photolibrary"
                                  ? "Photo Library"
                                  : "ID Card Editor"}
            </h1>
            <p>
              {view === "requests"
                ? "Review lost ID reprint requests, view receipts, and re-export updated IDs."
                : "Create, preview, save and export Lake Shore Colleges IDs."}
            </p>
          </div>
          {view === "editor" && (
            <div className="top-actions editor-top-actions">
              <button
                className="primary"
                onClick={save}
                disabled={loading}
              >
                {loading ? (
                  <>
                    <span className="s-spin" aria-hidden="true" />
                    Saving…
                  </>
                ) : (
                  "Save ID"
                )}
              </button>
              <button
                onClick={() => {
                  setActiveRequestId(null);
                  setView("dashboard");
                }}
              >
                Back
              </button>
            </div>
          )}
        </header>
        {msg && (
          <div className="alert">
            {msg}
            <button onClick={() => setMsg("")}>×</button>
          </div>
        )}
        {view === "dashboard" && (
          <>
            {/* ===== Dashboard Analytics (server-aggregated, auto-refresh) ===== */}
            <div className="dash-analytics">
              <div className="dash-toolbar">
                <h2 className="dash-section-title">Analytics</h2>
                <div className="dash-filters">
                  <label className="dash-filter">
                    <span>From</span>
                    <input
                      type="date"
                      value={dashFrom}
                      max={dashTo || undefined}
                      onChange={(e) => setDashFrom(e.target.value)}
                    />
                  </label>
                  <label className="dash-filter">
                    <span>To</span>
                    <input
                      type="date"
                      value={dashTo}
                      min={dashFrom || undefined}
                      onChange={(e) => setDashTo(e.target.value)}
                    />
                  </label>
                  <label className="dash-filter">
                    <span>Department</span>
                    <select
                      value={dashDept}
                      onChange={(e) => setDashDept(e.target.value)}
                    >
                      <option value="">All departments</option>
                      <option value="COLLEGE">College</option>
                      <option value="JUNIOR_HIGH">Junior High School</option>
                      <option value="SENIOR_HIGH">Senior High School</option>
                    </select>
                  </label>
                  <label className="dash-filter">
                    <span>Status</span>
                    <select
                      value={dashStatus}
                      onChange={(e) => setDashStatus(e.target.value)}
                    >
                      {DASH_STATUS_OPTIONS.map(([v, l]) => (
                        <option key={v} value={v}>
                          {l}
                        </option>
                      ))}
                    </select>
                  </label>
                  <button
                    className="dash-reset"
                    onClick={() => {
                      setDashFrom("");
                      setDashTo("");
                      setDashDept("");
                      setDashStatus("");
                    }}
                    disabled={
                      dashLoading || (!dashFrom && !dashTo && !dashDept && !dashStatus)
                    }
                  >
                    Reset
                  </button>
                  <button
                    className="dash-refresh"
                    onClick={loadDashboardStats}
                    disabled={dashLoading}
                  >
                    {dashLoading ? "Refreshing…" : "⟳ Refresh"}
                  </button>
                </div>
              </div>
              {dashStats ? (
                <>
                  <div className="stat-cards">
                    <StatCard
                      icon="🎓"
                      label="Total Students"
                      value={dashStats.summary.total}
                      hint={`${dashStats.summary.in_progress} not yet released`}
                      tone="tone-total"
                    />
                    <StatCard
                      icon="🖨️"
                      label="Printed"
                      value={dashStats.summary.printed}
                      hint={`${dashPct(dashStats.summary.printed, dashStats.summary.total)}% of students`}
                      tone="tone-printed"
                    />
                    <StatCard
                      icon="✅"
                      label="Released"
                      value={dashStats.summary.released}
                      hint={`${dashPct(dashStats.summary.released, dashStats.summary.total)}% of students`}
                      tone="tone-released"
                    />
                    <StatCard
                      icon="🔎"
                      label="Lost IDs"
                      value={dashStats.lost_ids}
                      hint="Reprint requests reported"
                      tone="tone-lost"
                    />
                    <StatCard
                      icon="♻️"
                      label="Reprints"
                      value={dashStats.reprints}
                      hint="Reprint jobs recorded"
                      tone="tone-reprint"
                    />
                  </div>
                  <div className="dash-charts">
                    <div className="chart-card">
                      <div className="chart-head">
                        <h3>Students / IDs by Department</h3>
                        <small>College · Junior High · Senior High</small>
                      </div>
                      <DeptChart rows={dashStats.by_type} />
                    </div>
                    <div className="chart-card">
                      <div className="chart-head">
                        <h3>Monthly ID Generation</h3>
                        <small className="chart-legend">
                          <i className="legend-swatch" /> Generated
                          <i className="legend-line" /> Printed
                        </small>
                      </div>
                      <MonthlyChart
                        generated={dashStats.monthly.generated}
                        printed={dashStats.monthly.printed}
                        monthKeys={dashMonthKeys(dashFrom, dashTo)}
                      />
                    </div>
                  </div>
                </>
              ) : (
                <div className="dash-loading">
                  {dashLoading ? "Loading analytics…" : "No analytics available yet."}
                </div>
              )}
            </div>
            <div className="dashboard-grid">
              <Dash
                title="College"
                count={counts.COLLEGE}
                action={() => create("COLLEGE")}
                desc="Create college student IDs with editable course, student number and photo."
              />
              <Dash
                title="Junior High School"
                count={counts.JUNIOR_HIGH}
                action={() => create("JUNIOR_HIGH")}
                desc="Grade 7 to Grade 10 with color-coded name bands."
              />
              <Dash
                title="Senior High School"
                count={counts.SENIOR_HIGH}
                action={() => create("SENIOR_HIGH")}
                desc="Grade 11 and Grade 12 with separate color-coded layouts."
              />
              <Dash
                title="Saved IDs"
                count={cards.length}
                action={() => {
                  setFilter("");
                  setView("records");
                  window.scrollTo(0, 0);
                }}
                desc="View, search, edit and manage student IDs already saved in the database."
                buttonLabel="View Saved IDs"
              />
              <Dash
                title="Lost ID Requests"
                count={pendingRequests.length}
                action={() => {
                  setReqFilter("");
                  setView("requests");
                  window.scrollTo(0, 0);
                }}
                desc="Reprint requests for lost student IDs including pending notifications and uploaded payment receipts."
                buttonLabel="View Requests"
              />
              {user.role === "admin" && (
                <Dash
                  title="Print History"
                  count={phSummary?.month?.total ?? 0}
                  action={() => {
                    setPhPage(1);
                    setView("printhistory");
                    window.scrollTo(0, 0);
                  }}
                  desc="Every print job recorded: who printed which student's ID, when, Original or Reprint, and the reason. Prints this month shown."
                  buttonLabel="View Print History"
                />
              )}
              <Dash
                title="Import Students"
                icon="📥"
                count="CSV / Excel"
                action={() => {
                  setImportFile(null);
                  setImportPreview(null);
                  setImportResult(null);
                  setView("import");
                  window.scrollTo(0, 0);
                }}
                desc="Upload a CSV or Excel file to import student batches. Validates data, detects duplicates, and generates IDs."
                buttonLabel="Import Students"
              />
              <Dash
                title="Photo Processing"
                icon="🖼"
                action={() => {
                  setView("photoprocessing");
                  window.scrollTo(0, 0);
                }}
                desc="AI-powered photo editing: auto crop, background removal, color replacement, alignment, and quality analysis."
                buttonLabel="Open Photo Processor"
              />
              {user.role === "admin" && (
                <Dash
                  title="Photo Library"
                  icon="📸"
                  action={() => {
                    setView("photolibrary");
                    window.scrollTo(0, 0);
                  }}
                  desc="Centralized photo repository for all students. Search, filter, archive, and manage student photos."
                  buttonLabel="Open Photo Library"
                />
              )}
            </div>
            {pendingRequests.length > 0 && (
              <section className="request-alerts">
                <h2>
                  Lost ID Reprint Notifications ({pendingRequests.length})
                </h2>
                {pendingRequests.slice(0, 5).map((r) => (
                  <div className="request-alert-item" key={r.id}>
                    <div className="request-alert-info">
                      <b>{r.student_name}</b>
                      <span>
                        {r.course ||
                          `${r.grade_level} ${r.section_name}`}{" "}
                        • Request #{r.id}
                      </span>
                    </div>
                    <div className="request-alert-actions">
                      {r.receipt_path && (
                        <a
                          href={assetUrl(r.receipt_path)}
                          target="_blank"
                          rel="noreferrer"
                          className="ghost-link"
                        >
                          Receipt
                        </a>
                      )}
                      <button
                        className="primary"
                        onClick={() => editRequest(r)}
                      >
                        View & Edit ID
                      </button>
                    </div>
                  </div>
                ))}
              </section>
            )}
            <section className="dashboard-preview">
              <div>
                <h2>Smart ID 51 / CR80 export</h2>
                <p>
                  Front and back previews use the supplied 642 × 1013 design
                  proportion. Export is rendered at 3× for high-resolution PNG
                  or JPG transfer into the card software.
                </p>
              </div>
              <div className="mini-cards">
                <div>
                  FRONT
                  <br />
                  <small>54 × 85.6 mm</small>
                </div>
                <div>
                  BACK
                  <br />
                  <small>54 × 85.6 mm</small>
                </div>
              </div>
            </section>
          </>
        )}
        {view === "editor" && (
          <div className="editor-layout">
            <section className="editor-form">
              <h2>ID Template</h2>
              <div className="form-grid">
                <div className="field full">
                  <span>Active template for {typeTitles[card.id_type]}</span>
                  <select
                    value={card.template_id || ""}
                    onChange={(e) => update("template_id", e.target.value)}
                  >
                    <option value="">— Default LSC design (no template) —</option>
                    {templates
                      .filter((t) => t.id_type === card.id_type)
                      .map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name}
                          {String(t.is_active) === "1" ? " (active)" : ""}
                        </option>
                      ))}
                  </select>
                  <small>
                    {card.template_id
                      ? "The uploaded design is used as a 100% exact copy. Only the data zones are drawn on top."
                      : "No template selected — the built-in LSC design is used."}
                    {templates.every((t) => t.id_type !== card.id_type) && (
                      <>
                        {" "}No custom templates exist for {typeTitles[card.id_type]} yet — add one
                        in the Templates tab (a template only appears for its own department).
                      </>
                    )}
                  </small>
                </div>
              </div>
              <h2>Student Details</h2>
              <div className="form-grid">
                <div className="field full">
                  <span>Department / ID Type</span>
                  <input
                    type="text"
                    value={typeTitles[card.id_type] || ""}
                    readOnly
                  />
                </div>
                <Field
                  label="Student Full Name"
                  name="student_name"
                  value={card.student_name}
                  onChange={update}
                  full
                />
                {card.id_type === "COLLEGE" ? (
                  <>
                    <Select
                      label="Course"
                      name="course"
                      value={card.course}
                      onChange={update}
                      full
                    >
                      {COURSES.map((c) => (
                        <option key={c}>{c}</option>
                      ))}
                    </Select>
                    <Field
                      label="Student ID Number"
                      name="student_number"
                      value={card.student_number}
                      onChange={update}
                    />
                  </>
                ) : (
                  <>
                    <Select
                      label={
                        card.id_type === "JUNIOR_HIGH"
                          ? "Junior High School Grade"
                          : "Senior High School Grade"
                      }
                      name="grade_level"
                      value={card.grade_level}
                      onChange={update}
                      full
                    >
                      {(card.id_type === "JUNIOR_HIGH"
                        ? ["GRADE 7", "GRADE 8", "GRADE 9", "GRADE 10"]
                        : ["GRADE 11", "GRADE 12"]
                      ).map((g) => (
                        <option key={g}>{g}</option>
                      ))}
                    </Select>
                    <Field
                      label="Section"
                      name="section_name"
                      value={card.section_name}
                      onChange={update}
                    />
                    <Field
                      label="School Year"
                      name="school_year"
                      value={card.school_year}
                      onChange={update}
                    />
                    <Field
                      label="ID Number"
                      name="student_id_number"
                      value={card.student_id_number}
                      onChange={update}
                    />
                    <Field
                      label="LRN"
                      name="lrn"
                      value={card.lrn}
                      onChange={update}
                    />
                  </>
                )}
                <label className="upload field full">
                  <span>ID Photo Upload</span>
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (!file) return;
                      setMsg("");
                      const fd = new FormData();
                      fd.append("photo", file);
                      if (card.id) fd.append("student_id", card.id);
                      const uploadApi = card.id ? "uploadPhoto" : "uploadPhoto";
                      api(uploadApi, { method: "POST", body: fd })
                        .then((r) => {
                          setCard((p) => ({
                            ...p,
                            photo_path: r.path,
                            preferred_photo_id: r.photo_id || p.preferred_photo_id,
                          }));
                          setMsg("Photo uploaded. The template photo area will crop/resize it to fit when the ID is generated.");
                        })
                        .catch((err) => setMsg(err.message));
                    }}
                  />
                  {card.photo_path ? (
                    <small>Photo uploaded successfully.</small>
                  ) : (
                    <small>The template photo area will crop/resize this photo to fit when the ID is generated.</small>
                  )}
                </label>
                <div className="cid-photo-selector">
                  <details open={!card.id}>
                    <summary>
                      ID Picture — Search &amp; select from Photo Library
                      {card.id > 0 ? ` (${card.photo_count || 0} photos)` : " (all students)"}
                    </summary>
                    <PhotoSelector
                      cardId={card.id}
                      selectedPhotoId={card.preferred_photo_id}
                      pendingPhotoId={pendingPhotoId}
                      onSelect={(photoId, path, photo) => {
                        setCard((p) => ({
                          ...p,
                          preferred_photo_id: photoId,
                          photo_path: path,
                          photo_count: p.id
                            ? p.photo_count
                            : p.photo_count || 1,
                        }));
                        /* Pre-save pick: remember it so save() can clone the
                         * library photo into the new student's library. */
                        if (!card.id && photoId) {
                          setPendingPhotoId(photoId);
                          setMsg(
                            "Photo selected from the library. It will be attached to this student when you save the record.",
                          );
                        } else {
                          setMsg("Photo selected for ID.");
                        }
                      }}
                      api={api}
                      backendUrl={BACKEND_URL}
                    />
                  </details>
                </div>
              </div>
              <h2>Back ID Details</h2>
              <div className="form-grid">
                <Field
                  label="Address Line 1"
                  name="address_line1"
                  value={card.address_line1}
                  onChange={update}
                  full
                />
                <Field
                  label="Address Line 2"
                  name="address_line2"
                  value={card.address_line2}
                  onChange={update}
                  full
                />
                <Field
                  label="Emergency Contact"
                  name="emergency_contact"
                  value={card.emergency_contact}
                  onChange={update}
                />
                <Field
                  label="Emergency Phone"
                  name="emergency_phone"
                  value={card.emergency_phone}
                  onChange={update}
                />
                <div className="field full">
                  <span>Authorized Signatory</span>
                  <input
                    type="text"
                    value={DEPARTMENT_SIGNATORIES[card.id_type] || ""}
                    readOnly
                  />
                </div>
              </div>
            </section>
            <aside className="preview-panel">
              <div className="preview-head">
                <b>SMART ID 51 PREVIEW</b>
                <span>Front + Back</span>
              </div>
              <div className="status-bar">
                <span
                  className={
                    "badge card-status-" + cardStatus(card.status).toLowerCase()
                  }
                >
                  Status: {cardStatus(card.status)}
                </span>
                {card.status === "printed" && card.print_count > 0 && (
                  <small className="print-meta">
                    Printed ×{card.print_count}
                    {card.printed_at
                      ? ` · ${formatDateTime(card.printed_at)}`
                      : ""}
                  </small>
                )}
                {card.status === "released" && (
                  <small className="print-meta">
                    Released
                    {card.released_by_name ? ` by ${card.released_by_name}` : ""}
                    {card.released_at
                      ? ` · ${formatDateTime(card.released_at)}`
                      : ""}
                    {card.student_received ? " · student received" : ""}
                  </small>
                )}
                {card.id &&
                  card.status !== "done" &&
                  card.status !== "printed" &&
                  card.status !== "released" && (
                    <button
                      className="primary btn-done"
                      onClick={markCardDone}
                      disabled={loading}
                    >
                      ✓ Mark as Done
                    </button>
                  )}
              </div>
              <div className="preview-card-wrap">
                {(() => {
                  const tpl = templates.find(
                    (t) => String(t.id) === String(card.template_id),
                  );
                  if (tpl && tpl.front_image) {
                    return (
                      <>
                        <TemplateCard card={card} template={tpl} side="front" />
                        {tpl.back_image ? (
                          <TemplateCard card={card} template={tpl} side="back" />
                        ) : (
                          <BackCard card={card} />
                        )}
                      </>
                    );
                  }
                  return (
                    <>
                      <FrontCard card={card} />
                      <BackCard card={card} />
                    </>
                  );
                })()}
              </div>
              <div className="export-box">
                <b>Direct print — Smart ID 51 (dual side)</b>
                <button
                  className="primary print-btn"
                  onClick={printDualSide}
                  disabled={printing}
                >
                  {printing ? "Preparing print…" : "🖨 Print Front + Back"}
                </button>
                <small>
                  Sends ONE job to the Smart ID 51: page 1 = FRONT (colour),
                  page 2 = BACK (black &amp; white). The printer driver handles
                  duplex and panel selection. Every print is recorded in the{" "}
                  <b>Print History</b> — reprints require a reason.
                </small>
              </div>
              {card.id &&
                (card.status === "printed" || card.status === "released") && (
                  <div className="export-box">
                    {card.status === "printed" ? (
                      <>
                        <b>Release ID</b>
                        <label className="release-notes">
                          <span>Release notes (optional)</span>
                          <textarea
                            rows={2}
                            value={releaseNotes}
                            onChange={(e) => setReleaseNotes(e.target.value)}
                            placeholder="e.g. Claimed by the student with school ID"
                          />
                        </label>
                        <label className="release-check">
                          <input
                            type="checkbox"
                            checked={studentReceived}
                            onChange={(e) =>
                              setStudentReceived(e.target.checked)
                            }
                          />{" "}
                          Student received the ID
                        </label>
                        <button
                          className="primary release-btn"
                          onClick={releaseCardNow}
                          disabled={loading}
                        >
                          ✅ Release ID
                        </button>
                        <small>
                          Marks the ID as <b>Released</b>, recording who
                          released it, when, and any notes. Requires
                          confirmation.
                        </small>
                      </>
                    ) : (
                      <>
                        <b>Release information</b>
                        <small className="release-info">
                          Released by{" "}
                          <b>
                            {card.released_by_name ||
                              (card.released_by
                                ? `user #${card.released_by}`
                                : "unknown")}
                          </b>
                          {card.released_at
                            ? ` on ${formatDateTime(card.released_at)}`
                            : ""}
                          {card.release_notes ? ` — “${card.release_notes}”` : ""}
                          {" · "}
                          {card.student_received
                            ? "Student received"
                            : "Student receipt not confirmed"}
                        </small>
                      </>
                    )}
                  </div>
                )}
              <div className="export-box">
                <b>Export for Smart ID software</b>
                <ExportButtons />{" "}
                <small>
                  Output is 1276 × 2022 px at 600 DPI — exact CR80 high-resolution print.
                  Pure white background, no shadows or borders.
                </small>
              </div>
            </aside>
          </div>
        )}
        {view === "import" && (
          <section className="import-section">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setView("dashboard");
                  setImportFile(null);
                  setImportPreview(null);
                  setImportResult(null);
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <button
                className="import-template-btn"
                onClick={handleTemplateDownload}
              >
                📋 Download Template
              </button>
            </div>

            <div className="import-upload-area">
              <h2>Import Students from CSV / Excel</h2>
              <p>
                Upload a .csv, .xlsx, or .xls file containing student records.
                The system will validate the data, detect duplicates, and show a
                preview before importing.
              </p>

              <label className="import-file-label">
                <input
                  type="file"
                  accept=".csv,.xlsx,.xls"
                  onChange={handleImportUpload}
                  disabled={importLoading}
                />
                <span className="import-file-button">
                  {importLoading ? "Uploading..." : "📁 Choose File"}
                </span>
                <span className="import-file-name">
                  {importFile ? importFile.name : "No file selected"}
                </span>
              </label>

              {importPreview && (
                <div className="import-preview">
                  <div className="import-summary">
                    <div className="import-stat import-stat-valid">
                      <span className="import-stat-count">{importPreview.counts.valid}</span>
                      <span className="import-stat-label">Valid</span>
                    </div>
                    <div className="import-stat import-stat-duplicate">
                      <span className="import-stat-count">{importPreview.counts.duplicate}</span>
                      <span className="import-stat-label">Duplicates</span>
                    </div>
                    <div className="import-stat import-stat-invalid">
                      <span className="import-stat-count">{importPreview.counts.invalid}</span>
                      <span className="import-stat-label">Invalid</span>
                    </div>
                    <div className="import-stat import-stat-total">
                      <span className="import-stat-count">{importPreview.counts.total}</span>
                      <span className="import-stat-label">Total</span>
                    </div>
                  </div>

                  {importPreview.counts.valid > 0 && (
                    <button
                      className="primary import-commit-btn"
                      onClick={handleImportCommit}
                      disabled={importLoading}
                    >
                      {importLoading ? "Importing..." : `Import ${importPreview.counts.valid} Students`}
                    </button>
                  )}

                  <div className="import-preview-table-container">
                    <table className="import-preview-table">
                      <thead>
                        <tr>
                          <th>Row</th>
                          <th>Status</th>
                          <th>Student Name</th>
                          <th>Student Number</th>
                          <th>Details</th>
                        </tr>
                      </thead>
                      <tbody>
                        {importPreview.rows.map((r, i) => (
                          <tr key={i} className={`import-row-${r.status}`}>
                            <td>{r.row}</td>
                            <td>
                              <span className={`import-badge import-badge-${r.status}`}>
                                {r.status}
                              </span>
                            </td>
                            <td>{r.data.student_name}</td>
                            <td>{r.data.student_number}</td>
                            <td>
                              {r.errors.length > 0 && (
                                <ul className="import-errors">
                                  {r.errors.map((e, j) => (
                                    <li key={j}>{e}</li>
                                  ))}
                                </ul>
                              )}
                              {r.warnings.length > 0 && (
                                <ul className="import-warnings">
                                  {r.warnings.map((w, j) => (
                                    <li key={j}>{w}</li>
                                  ))}
                                </ul>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {importResult && (
                <div className="import-result">
                  <h3>✅ Import Complete</h3>
                  <p>{importResult.message}</p>
                  <button
                    className="primary"
                    onClick={handleGenerateIds}
                  >
                    Generate IDs for Imported Students
                  </button>
                </div>
              )}
            </div>
          </section>
        )}
        {view === "records" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setFilter("");
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>

              <input
                placeholder="Search student name, ID, LRN..."
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
              />

              <select
                className="status-filter"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">All</option>
                <option value="created">Created (Request)</option>
                <option value="done">Done (Review)</option>
                <option value="edited">Edited</option>
                <option value="printed">Printed (Approved)</option>
                <option value="released">Released</option>
              </select>

              <button className="primary" onClick={() => create("COLLEGE")}>
                + Create New ID
              </button>
              {user.role === "admin" && (
                <button
                  className="bulk-btn"
                  onClick={() => {
                    setBulkModalOpen(true);
                    setBulkSelected(shown.map((r) => r.id));
                    setBulkPreview(null);
                    setBulkResults(null);
                    setBulkTemplateId("");
                  }}
                  title="Select the visible students and generate all their IDs at once"
                >
                  🗜️ Bulk Generate IDs
                </button>
              )}
              <span
                className="bp-sel-chip"
                title="IDs ticked for batch printing"
              >
                {printSel.length} selected
                {printSel.length > 0 && (
                  <button
                    onClick={() => setPrintSel([])}
                    title="Clear selection"
                  >
                    ✕
                  </button>
                )}
              </span>
              <button
                className="primary"
                onClick={openBatchPrint}
                disabled={printEligibleSelected.length === 0 || bulkPrintOpen}
                title="Print every ticked ID as ONE Smart ID 51 job (front + back per card)"
              >
                🖨 Print Selected
                {printEligibleSelected.length > 0
                  ? ` (${printEligibleSelected.length})`
                  : ""}
              </button>
              <button
                onClick={printAllEligible}
                disabled={shownEligible.length === 0 || bulkPrintOpen}
                title="Tick every printable ID in the current list and open the batch print dialog"
              >
                Print All Eligible ({shownEligible.length})
              </button>
            </div>
            <table>
              <thead>
                <tr>
                  <th className="bp-col-check">
                    <input
                      type="checkbox"
                      checked={allShownEligibleSelected}
                      onChange={(e) => togglePrintAll(e.target.checked)}
                      title="Select all printable rows in the list"
                    />
                  </th>
                  <th>Name</th>
                  <th>Department</th>
                  <th>Course / Grade</th>
                  <th>ID / LRN</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {shown.length === 0 ? (
                  <tr>
                    <td colSpan="7" style={{ textAlign: "center", padding: "28px" }}>
                      {filter
                        ? "No saved IDs match your search."
                        : "No saved student IDs yet."}
                    </td>
                  </tr>
                ) : (
                  shown.map((x) => (
                    <tr key={x.id}>
                      <td className="bp-col-check">
                        <input
                          type="checkbox"
                          checked={printSel.includes(x.id)}
                          disabled={!eligibleForPrint(x)}
                          title={
                            eligibleForPrint(x)
                              ? "Select for batch printing"
                              : "Released IDs can no longer be printed"
                          }
                          onChange={(e) =>
                            togglePrintRow(x.id, e.target.checked)
                          }
                        />
                      </td>
                      <td>{x.student_name}</td>
                      <td>{typeTitles[x.id_type]}</td>
                      <td>{x.course || `${x.grade_level} ${x.section_name}`}</td>
                      <td>{x.student_number || x.student_id_number || x.lrn}</td>
                      <td>
                        <span
                          className={
                            "badge card-status-" +
                            cardStatus(x.status).toLowerCase()
                          }
                          title={
                            x.status === "printed" && x.printed_at
                              ? `Printed ×${x.print_count} · ${formatDateTime(x.printed_at)}`
                              : x.status === "released"
                                ? `Released${x.released_by_name ? ` by ${x.released_by_name}` : ""}${x.released_at ? ` · ${formatDateTime(x.released_at)}` : ""}`
                                : undefined
                          }
                        >
                          {cardStatus(x.status)}
                        </span>
                      </td>
                      <td>
                        <button onClick={() => edit(x.id)}>Edit / View</button>
                        <button className="danger" onClick={() => del(x.id)}>
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </section>
        )}
        {view === "users" && user.role === "admin" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <input
                placeholder="Search users..."
                value={userFilter}
                onChange={(e) => setUserFilter(e.target.value)}
              />
              <button className="primary" onClick={() => openUserForm()}>
                + New User
              </button>
            </div>
            {userFormOpen && (
              <div className="user-form">
                <h2>
                  {userForm.id
                    ? "Edit User Account"
                    : "Create New User Account"}
                </h2>
                <div className="form-grid">
                  <Field
                    label="Full Name"
                    name="full_name"
                    value={userForm.full_name}
                    onChange={updateUserForm}
                  />
                  <Field
                    label="Username / Email"
                    name="username"
                    value={userForm.username}
                    onChange={updateUserForm}
                  />
                  <Select
                    label="Role"
                    name="role"
                    value={userForm.role}
                    onChange={updateUserForm}
                  >
                    <option value="staff">Staff</option>
                    <option value="admin">Administrator</option>
                  </Select>
                  <Field
                    label={
                      userForm.id
                        ? "New Password (leave blank to keep current)"
                        : "Password"
                    }
                    name="password"
                    type="password"
                    value={userForm.password}
                    onChange={updateUserForm}
                  />
                  <label className="field">
                    <span>Status</span>
                    <select
                      value={userForm.is_active ? "1" : "0"}
                      onChange={(e) =>
                        updateUserForm("is_active", e.target.value === "1")
                      }
                    >
                      <option value="1">Active</option>
                      <option value="0">Deactivated</option>
                    </select>
                  </label>
                </div>
                <div className="user-form-actions">
                  <button
                    className="primary"
                    onClick={saveUserForm}
                    disabled={loading}
                  >
                    {loading
                      ? "Saving..."
                      : userForm.id
                        ? "Update User"
                        : "Create User"}
                  </button>
                  <button onClick={() => setUserFormOpen(false)}>Cancel</button>
                </div>
              </div>
            )}
            <table>
              <thead>
                <tr>
                  <th>Full Name</th>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {shownUsers.length === 0 ? (
                  <tr>
                    <td
                      colSpan="5"
                      style={{ textAlign: "center", padding: "28px" }}
                    >
                      {userFilter
                        ? "No users match your search."
                        : "No user accounts yet."}
                    </td>
                  </tr>
                ) : (
                  shownUsers.map((u) => {
                    const active = String(u.is_active) === "1";
                    return (
                      <tr key={u.id}>
                        <td>{u.full_name}</td>
                        <td>{u.username}</td>
                        <td>
                          <span className={"badge " + u.role}>
                            {u.role === "admin" ? "Administrator" : "Staff"}
                          </span>
                        </td>
                        <td>
                          <span
                            className={"badge " + (active ? "active" : "inactive")}
                          >
                            {active ? "Active" : "Deactivated"}
                          </span>
                        </td>
                        <td>
                          <button onClick={() => openUserForm(u)}>
                            Edit / Reset Password
                          </button>
                          {active && (
                            <button
                              className="danger"
                              onClick={() => deactivateUserAccount(u.id)}
                            >
                              Deactivate
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </section>
        )}
        {view === "requests" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setReqFilter("");
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <input
                placeholder="Search name, course, grade, status..."
                value={reqFilter}
                onChange={(e) => setReqFilter(e.target.value)}
              />
            </div>
            <table>
              <thead>
                <tr>
                  <th>Request</th>
                  <th>Student</th>
                  <th>Course / Grade</th>
                  <th>ID / LRN</th>
                  <th>Status</th>
                  <th>Receipt</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {shownRequests.length === 0 ? (
                  <tr>
                    <td
                      colSpan="8"
                      style={{ textAlign: "center", padding: "28px" }}
                    >
                      {reqFilter
                        ? "No lost ID requests match your search."
                        : "No lost ID requests yet."}
                    </td>
                  </tr>
                ) : (
                  shownRequests.map((r) => {
                    const done =
                      r.status === "reprinted" || r.status === "rejected";
                    return (
                      <tr key={r.id}>
                        <td>
                          <b>#{r.id}</b>
                        </td>
                        <td>{r.student_name}</td>
                        <td>
                          {r.course ||
                            `${r.grade_level} ${r.section_name}` || "—"}
                        </td>
                        <td>
                          {r.student_number ||
                            r.student_id_number ||
                            r.lrn ||
                            "—"}
                        </td>
                        <td>
                          <span className={"badge req-" + r.status}>
                            {r.status}
                          </span>
                        </td>
                        <td>
                          {r.receipt_path ? (
                            <a
                              href={assetUrl(r.receipt_path)}
                              target="_blank"
                              rel="noreferrer"
                            >
                              View
                            </a>
                          ) : (
                            "—"
                          )}
                        </td>
                        <td>{new Date(r.created_at).toLocaleString()}</td>
                        <td>
                          <button onClick={() => editRequest(r)}>
                            Edit ID & Export
                          </button>
                          {r.status !== "approved" && !done && (
                            <button
                              onClick={() => setRequestStatus(r.id, "approved")}
                            >
                              Approve
                            </button>
                          )}
                          {!done && (
                            <button
                              onClick={() =>
                                setRequestStatus(r.id, "reprinted")
                              }
                            >
                              Mark Printed
                            </button>
                          )}
                          {r.status !== "rejected" &&
                            r.status !== "reprinted" && (
                              <button
                                className="danger"
                                onClick={() =>
                                  setRequestStatus(r.id, "rejected")
                                }
                              >
                                Reject
                              </button>
                            )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </section>
        )}
        {view === "templates" && user.role === "admin" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setTemplateFilter("");
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <input
                placeholder="Search templates..."
                value={templateFilter}
                onChange={(e) => setTemplateFilter(e.target.value)}
              />
              <button className="primary" onClick={() => openTemplateDesigner(null)}>
                ＋ New Template
              </button>
            </div>
            {shownTemplates.length === 0 ? (
              <div className="tpl-empty">
                <b>No templates yet.</b>
                <p>
                  Upload an existing ID design (PNG/JPG) to use it as a{" "}
                  <b>100% exact-copy template</b>. Place data zones on the
                  image, and new IDs of that department will automatically use
                  the template.
                </p>
              </div>
            ) : (
              <div className="tpl-grid">
                {shownTemplates.map((t) => {
                  const zones = t.fields_json || [];
                  const isActive = String(t.is_active) === "1";
                  const isSystem = String(t.is_system) === "1";
                  return (
                    <div className="tpl-card" key={t.id}>
                      <div className="tpl-thumb">
                        {t.front_image ? (
                          <img src={assetUrl(t.front_image)} alt={t.name} />
                        ) : (
                          <span className="tpl-thumb-empty">
                            {isSystem ? "Built-in CSS design" : "No image"}
                          </span>
                        )}
                        {isSystem && (
                          <span className="tpl-badge-system">ORIGINAL</span>
                        )}
                        {isActive && <span className="tpl-badge-active">ACTIVE</span>}
                      </div>
                      <div className="tpl-info">
                        <b>{t.name}</b>
                        <span className="tpl-dept">{typeTitles[t.id_type]}</span>
<small>
                          {isSystem ? ("The built-in Lake Shore Colleges design — protected and cannot be deleted.") : (<> {zones.length} data zone{zones.length === 1 ? "" : "s"} • {" "} {t.back_image ? "front + back" : "front only"}</>)}
                        </small>
                      </div>
                      <div className="tpl-actions">
                        {isSystem ? (
                          <span className="tpl-protected">
                            🔒 Original template (protected)
                          </span>
                        ) : (
                          <button onClick={() => openTemplateDesigner(t)}>
                            Edit Design
                          </button>
                        )}
                        {!isActive && (
                          <button
                            className="primary"
                            onClick={() => setActiveTemplateRecord(t.id)}
                          >
                            Set Active
                          </button>
                        )}
                        {!isSystem && (
                          <button
                            className="danger"
                            onClick={() => deleteTemplateRecord(t.id)}
                          >
                            Delete
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </section>
        )}
        {editingTemplate && (
          <TemplateDesigner
            initial={editingTemplate}
            onCancel={() => setEditingTemplate(null)}
            onSave={saveTemplateRecord}
          />
        )}
        {view === "audit" && user.role === "admin" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <input
                placeholder="Search action, entity, values or user..."
                value={auditQ}
                onChange={(e) => {
                  setAuditPage(1);
                  setAuditQ(e.target.value);
                }}
              />
            </div>
            <div className="audit-filters">
              <label>
                <span>Action</span>
                <select
                  value={auditAction}
                  onChange={(e) => {
                    setAuditPage(1);
                    setAuditAction(e.target.value);
                  }}
                >
                  <option value="">All actions</option>
                  {auditActions.map((a) => (
                    <option key={a} value={a}>
                      {a}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span>User</span>
                <select
                  value={auditUserQ}
                  onChange={(e) => {
                    setAuditPage(1);
                    setAuditUserQ(e.target.value);
                  }}
                >
                  <option value="">All users</option>
                  {users.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.username} ({u.role})
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span>From</span>
                <input
                  type="date"
                  value={auditFrom}
                  onChange={(e) => {
                    setAuditPage(1);
                    setAuditFrom(e.target.value);
                  }}
                />
              </label>
              <label>
                <span>To</span>
                <input
                  type="date"
                  value={auditTo}
                  onChange={(e) => {
                    setAuditPage(1);
                    setAuditTo(e.target.value);
                  }}
                />
              </label>
            </div>
            <table className="table">
              <thead>
                <tr>
                  <th>Date / Time</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Details</th>
                  <th>IP address</th>
                </tr>
              </thead>
              <tbody>
                {auditRows.length === 0 && (
                  <tr>
                    <td colSpan={6} className="audit-empty">
                      No audit entries match the current filters.
                    </td>
                  </tr>
                )}
                {auditRows.map((l) => (
                  <tr
                    key={l.id}
                    className="audit-row"
                    onClick={() => setAuditDetail(l)}
                  >
                    <td>{formatDateTime(l.created_at)}</td>
                    <td>
                      {l.username || `#${l.user_id ?? "—"}`}{" "}
                      {l.user_role && (
                        <span className="audit-role">{l.user_role}</span>
                      )}
                    </td>
                    <td>
                      <span
                        className={"audit-badge audit-" + auditTone(l.action)}
                      >
                        {l.action}
                      </span>
                    </td>
                    <td>
                      {l.entity_type}
                      {l.entity_id ? ` #${l.entity_id}` : ""}
                    </td>
                    <td className="audit-summary">{auditSummary(l)}</td>
                    <td>{l.ip_address || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="audit-pager">
              <span>
                Page {auditPage} of {auditPages || 1} · {auditTotal} entries
              </span>
              <div>
                <button
                  disabled={auditPage <= 1}
                  onClick={() => setAuditPage((p) => Math.max(1, p - 1))}
                >
                  ← Prev
                </button>
                <button
                  disabled={auditPage >= auditPages}
                  onClick={() => setAuditPage((p) => p + 1)}
                >
                  Next →
                </button>
              </div>
            </div>
            {auditDetail && (
              <div
                className="audit-modal-overlay"
                onClick={() => setAuditDetail(null)}
              >
                <div
                  className="audit-modal"
                  onClick={(e) => e.stopPropagation()}
                >
                  <div className="audit-modal-head">
                    <h3>
                      {auditDetail.action.replace(/_/g, " ")} —{" "}
                      {auditDetail.entity_type}
                      {auditDetail.entity_id
                        ? ` #${auditDetail.entity_id}`
                        : ""}
                    </h3>
                    <button onClick={() => setAuditDetail(null)}>✕</button>
                  </div>
                  <p className="audit-modal-meta">
                    {fmtAuditDate(auditDetail.created_at)} ·{" "}
                    {auditDetail.username || "system"}
                    {auditDetail.user_role
                      ? ` (${auditDetail.user_role})`
                      : ""} · IP {auditDetail.ip_address || "—"}
                    <br />
                    <span className="audit-public">{auditDetail.user_agent}</span>
                  </p>
                  {renderAuditValues(auditDetail)}
                </div>
              </div>
            )}
          </section>
        )}
        {view === "printhistory" && user.role === "admin" && (
          <section className="records">
            <div className="record-toolbar">
              <button
                onClick={() => {
                  setView("dashboard");
                  window.scrollTo(0, 0);
                }}
              >
                ← Back to Dashboard
              </button>
              <input
                placeholder="Search student, student number, reason or staff..."
                value={phQ}
                onChange={(e) => {
                  setPhPage(1);
                  setPhQ(e.target.value);
                }}
              />
            </div>
            <div className="audit-filters">
              <label>
                <span>Printed by</span>
                <select
                  value={phUser}
                  onChange={(e) => {
                    setPhPage(1);
                    setPhUser(e.target.value);
                  }}
                >
                  <option value="">All users</option>
                  {users.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.username} ({u.role})
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span>Type</span>
                <select
                  value={phType}
                  onChange={(e) => {
                    setPhPage(1);
                    setPhType(e.target.value);
                  }}
                >
                  <option value="">Original + Reprint</option>
                  <option value="original">Original</option>
                  <option value="reprint">Reprint</option>
                </select>
              </label>
              <label>
                <span>From</span>
                <input
                  type="date"
                  value={phFrom}
                  onChange={(e) => {
                    setPhPage(1);
                    setPhFrom(e.target.value);
                  }}
                />
              </label>
              <label>
                <span>To</span>
                <input
                  type="date"
                  value={phTo}
                  onChange={(e) => {
                    setPhPage(1);
                    setPhTo(e.target.value);
                  }}
                />
              </label>
            </div>
            {phSummary && (
              <div className="ph-summary">
                <div className="ph-stat">
                  <b>{phSummary.month?.total ?? 0}</b>
                  <span>Printed this month</span>
                </div>
                <div className="ph-stat">
                  <b>{phSummary.month?.original ?? 0}</b>
                  <span>Originals this month</span>
                </div>
                <div className="ph-stat">
                  <b>{phSummary.month?.reprint ?? 0}</b>
                  <span>Reprints this month</span>
                </div>
                <div className="ph-staff">
                  <b>Prints per staff</b>
                  {(phSummary.by_user || []).length === 0 ? (
                    <span className="audit-public">No prints recorded yet.</span>
                  ) : (
                    phSummary.by_user.map((u) => (
                      <span key={u.id} className="ph-staff-row">
                        {u.full_name || u.username}: <b>{u.month_count}</b> this
                        month · <b>{u.total_count}</b> all-time
                      </span>
                    ))
                  )}
                </div>
              </div>
            )}
            <table className="table">
              <thead>
                <tr>
                  <th>Date / Time</th>
                  <th>Student</th>
                  <th>Printed By</th>
                  <th>Type</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {phRows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="audit-empty">
                      No print jobs match the current filters.
                    </td>
                  </tr>
                )}
                {phRows.map((p) => (
                  <tr key={p.id}>
                    <td>{formatDateTime(p.printed_at)}</td>
                    <td>
                      {p.student_name || `ID record #${p.card_id}`}
                      {(p.student_number || p.student_id_number) && (
                        <span className="ph-student-no">
                          {p.student_number || p.student_id_number}
                        </span>
                      )}
                    </td>
                    <td>
                      {p.full_name ||
                        p.username ||
                        (p.user_id ? `#${p.user_id}` : "system")}
                      {p.user_role && (
                        <span className="audit-role">{p.user_role}</span>
                      )}
                    </td>
                    <td>
                      <span className={"badge ph-" + p.print_type}>
                        {p.print_type === "original" ? "Original" : "Reprint"}
                      </span>
                    </td>
                    <td className="ph-reason">{p.reason || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="audit-pager">
              <span>
                Page {phPage} of {phPages || 1} · {phTotal} print jobs
              </span>
              <div>
                <button
                  disabled={phPage <= 1}
                  onClick={() => setPhPage((p) => Math.max(1, p - 1))}
                >
                  ← Prev
                </button>
                <button
                  disabled={phPage >= phPages}
                  onClick={() => setPhPage((p) => p + 1)}
                >
                  Next →
                </button>
              </div>
            </div>
          </section>
        )}
        {reprintOpen && (
          <div
            className="audit-modal-overlay"
            onClick={printing ? undefined : () => setReprintOpen(false)}
          >
            <div className="audit-modal" onClick={(e) => e.stopPropagation()}>
              <div className="audit-modal-head">
                <h3>Reprint ID — {card.student_name || `#${card.id}`}</h3>
                <button
                  onClick={() => setReprintOpen(false)}
                  disabled={printing}
                >
                  ✕
                </button>
              </div>
              <p className="audit-modal-meta">
                This ID was already printed {card.print_count} time(s)
                {card.printed_at
                  ? ` (last: ${formatDateTime(card.printed_at)})`
                  : ""}
                . Select a reason for the print history, then print.
              </p>
              <div className="audit-block">
                <h4>Reprint reason</h4>
                <div className="reason-choices">
                  {["Damaged", "Lost", "Incorrect Information", "Other"].map(
                    (r) => (
                      <label
                        key={r}
                        className={
                          "reason-choice" +
                          (reprintChoice === r ? " selected" : "")
                        }
                      >
                        <input
                          type="radio"
                          name="print-reason"
                          value={r}
                          checked={reprintChoice === r}
                          onChange={() => setReprintChoice(r)}
                          disabled={printing}
                        />
                        {r}
                      </label>
                    ),
                  )}
                </div>
                {reprintChoice === "Other" && (
                  <label className="field full">
                    <span>Specify the reason</span>
                    <input
                      value={reprintText}
                      onChange={(e) => setReprintText(e.target.value)}
                      placeholder="e.g. Name correction requested by the registrar"
                      maxLength={200}
                      disabled={printing}
                    />
                  </label>
                )}
              </div>
              <div className="reason-actions">
                <button
                  onClick={() => setReprintOpen(false)}
                  disabled={printing}
                >
                  Cancel
                </button>
                <button
                  className="primary"
                  disabled={
                    printing ||
                    (reprintChoice === "Other" && reprintText.trim() === "")
                  }
                  onClick={() =>
                    doPrintDualSide(
                      reprintChoice === "Other"
                        ? reprintText.trim()
                        : reprintChoice,
                    )
                  }
                >
                  {printing ? (
                    <>
                      <span className="s-spin" aria-hidden="true" />
                      Preparing print…
                    </>
                  ) : (
                    "🖨 Print Reprint"
                  )}
                </button>
              </div>
            </div>
          </div>
        )}
      {view === "photolibrary" && (
        <PhotoLibraryView user={user} onBack={() => { setView("dashboard"); window.scrollTo(0, 0); }} />
      )}
      {view === "photoprocessing" && (
        <PhotoProcessingView user={user} api={api} assetUrl={assetUrl} onBack={() => { setView("dashboard"); window.scrollTo(0, 0); }} />
      )}
      {bulkModalOpen && (
        <BulkGenerateModal
          records={shown}
          templates={templates}
          selected={bulkSelected}
          setSelected={setBulkSelected}
          preview={bulkPreview}
          setPreview={setBulkPreview}
          generating={bulkGenerating}
          progress={bulkProgress}
          templateId={bulkTemplateId}
          setTemplateId={setBulkTemplateId}
          validateFn={validateBulkStudents}
          generateFn={runBulkGeneration}
          onClose={() => {
            if (!bulkGenerating) setBulkModalOpen(false);
          }}
        />
      )}
      {bulkPrintOpen && (
        <BulkPrintModal
          records={printEligibleSelected}
          selected={printSel}
          setSelected={setPrintSel}
          printing={bpPrinting}
          progress={bpProgress}
          result={bpResult}
          reasonChoice={bpChoice}
          setReasonChoice={setBpChoice}
          reasonText={bpText}
          setReasonText={setBpText}
          printFn={runBatchPrint}
          onClose={() => {
            if (!bpPrinting) setBulkPrintOpen(false);
          }}
        />
      )}
      </main>
    </div>
  );
}

/* ================= Photo Selector (searchable dropdown for Create ID) =================
 * New record (cardId falsy)  -> global library via photoLibrarySearch (path-only pick,
 *   attached to the unsaved card; becomes permanent on Save since photo_path is saved).
 * Saved record (cardId truthy) -> per-student library via photoLibraryList with
 *   ownership-safe photoLibrarySetPreferred + quick upload. */
function PhotoSelector({ cardId, selectedPhotoId, pendingPhotoId, onSelect, api, backendUrl }) {
  const [photos, setPhotos] = useState([]);
  const [loading, setLoading] = useState(false);
  const [msg, setMsg] = useState("");
  const [searchQ, setSearchQ] = useState("");
  const [open, setOpen] = useState(false);
  const [filters, setFilters] = useState({ source: "", type: "", status: "" });
  // Global (pre-save) search state — only consulted when there is no saved card yet
  const [gQ, setGQ] = useState("");
  const [gRows, setGRows] = useState([]);
  const [gLoading, setGLoading] = useState(false);
  const [gTotal, setGTotal] = useState(0);
  const [gSearched, setGSeached] = useState(false);

  async function load() {
    if (!cardId) { setPhotos([]); return; }
    setLoading(true);
    try {
      const r = await api("photoLibraryList&student_id=" + cardId);
      setPhotos(r.data || []);
    } catch (e) { setMsg(e.message); }
    setLoading(false);
  }

  // Reload when the card changes or a new photo becomes preferred (e.g. after upload)
  useEffect(() => { load(); }, [cardId, selectedPhotoId]);

  // ---- Global library search (pre-save mode: no student_id required) ----
  async function searchGlobal() {
    setGLoading(true);
    setGSeached(true);
    try {
      const qs = new URLSearchParams({
        q: gQ.trim(),
        source: filters.source || "",
        type: filters.type || "",
        is_active: "1",
        page: 1,
        per_page: 12,
      });
      const r = await api("photoLibrarySearch&" + qs.toString());
      setGRows(r.data || []);
      setGTotal(r.total || 0);
    } catch (e) { setMsg(e.message); }
    setGLoading(false);
  }

  function pickGlobal(p) {
    // Path-only attach: works before the student row exists. Save persists it
    // via the card's photo_path column; the library link is made after save.
    setOpen(false);
    setSearchQ("");
    onSelect(p.id, p.file_path, p);
  }

  function photoName(p) {
    return (p.file_path || "").split("/").pop() || ("photo-" + p.id);
  }

  function backgroundLabel(p) {
    const bi = p.background_info;
    if (!bi || !bi.mode) return "";
    if (bi.mode === "transparent") return "TRANSPARENT";
    if (bi.mode === "white") return "WHITE";
    if (bi.mode === "custom" && bi.color) return bi.color.toUpperCase();
    return String(bi.mode).toUpperCase();
  }

  function formatDate(d) {
    if (!d) return "";
    const dt = new Date(String(d).replace(" ", "T"));
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString() + " " + dt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  }

  // Client-side filtering — list is already server-scoped to this student only
  const filtered = photos.filter((p) => {
    const q = searchQ.trim().toLowerCase();
    if (q && !(photoName(p).toLowerCase().includes(q) || String(p.id).includes(q))) return false;
    if (filters.source && p.source !== filters.source) return false;
    if (filters.type && p.type !== filters.type) return false;
    if (filters.status === "active" && p.is_active !== 1) return false;
    if (filters.status === "archived" && p.is_active === 1) return false;
    return true;
  });

  const selectedPhoto = photos.find((p) => p.id === selectedPhotoId);
  const pendingPhoto = !cardId && pendingPhotoId
    ? (photos.find((p) => p.id === pendingPhotoId) || gRows.find((p) => p.id === pendingPhotoId) || null)
    : null;

  async function selectPhoto(p) {
    setOpen(false);
    setSearchQ("");
    try {
      await api("photoLibrarySetPreferred", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_id: p.id, student_id: cardId }),
      });
      onSelect(p.id, p.file_path);
    } catch (e) { setMsg(e.message); }
  }

  async function handleUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append("photo", file);
    fd.append("student_id", cardId);
    try {
      const r = await api("photoLibraryUpload", { method: "POST", body: fd });
      setMsg("Uploaded. Photo #" + r.photo_id);
      await load();
      // Auto-select the freshly uploaded photo
      await selectPhoto({ id: r.photo_id, file_path: r.path, is_active: 1 });
    } catch (err) { setMsg(err.message); }
  }


  return (
    <div style={{ marginTop: 8 }}>
      {/* Currently selected photo chip (saved card) */}
      {selectedPhoto && (
        <div className="cid-photo-chip">
          <img src={`${backendUrl}/api.php?action=photoLibraryServe&id=${selectedPhoto.id}`} alt="Selected" />
          <div>
            <div className="cpo-name">Using: {photoName(selectedPhoto)}</div>
            <div className="cpo-sub">
              {selectedPhoto.source} · {selectedPhoto.type} · {selectedPhoto.width}×{selectedPhoto.height}
              {backgroundLabel(selectedPhoto) ? " · " + backgroundLabel(selectedPhoto) : ""}
            </div>
          </div>
        </div>
      )}

      {/* Pending pick chip (new record, pre-save): shows the library photo
        * that will be cloned into the new student's library on Save. */}
      {!cardId && (pendingPhoto || selectedPhotoId) && (
        <div className="cid-photo-chip">
          {pendingPhoto ? (
            <>
              <img src={`${backendUrl}/api.php?action=photoLibraryServe&id=${pendingPhoto.id}`} alt="Pending" />
              <div>
                <div className="cpo-name">Selected: {photoName(pendingPhoto)} (Photo #{pendingPhoto.id})</div>
                <div className="cpo-sub">
                  {pendingPhoto.student_name ? pendingPhoto.student_name + " · " : ""}
                  {pendingPhoto.source} · {pendingPhoto.type} · {pendingPhoto.width}×{pendingPhoto.height}
                  {" · will be attached when you save"}
                </div>
              </div>
            </>
          ) : (
            <div>
              <div className="cpo-name">Selected: Photo #{selectedPhotoId}</div>
              <div className="cpo-sub">Will be attached when you save this record.</div>
            </div>
          )}
        </div>
      )}

      {/* ---- Pre-save mode: global library search (no student row yet) ---- */}
      {!cardId && (
        <div className="cid-photo-presave">
          <span className="cid-photo-selector-label">Photo Library — all students</span>
          <div className="cid-photo-presave-bar">
            <input
              type="text"
              placeholder="Search name, ID number, or LRN..."
              value={gQ}
              onChange={(e) => setGQ(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); searchGlobal(); } }}
            />
            <button className="primary" onClick={searchGlobal} disabled={gLoading}>
              {gLoading ? "Searching…" : "Search"}
            </button>
          </div>
          <div className="cid-photo-filters cid-photo-presave-filters">
            <select value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}>
              <option value="">All Sources</option>
              <option value="CREATE_ID">Create ID</option>
              <option value="PHOTO_PROCESSING">Photo Processing</option>
            </select>
            <select value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}>
              <option value="">All Types</option>
              <option value="ORIGINAL">Original</option>
              <option value="PROCESSED">Processed</option>
            </select>
          </div>
          <div className="cid-photo-presave-list">
            {gLoading && <div className="cid-photo-presave-empty">Searching photos...</div>}
            {!gLoading && !gSearched && (
              <div className="cid-photo-presave-empty">Search the photo library to pick an ID picture — no need to save first.</div>
            )}
            {!gLoading && gSearched && gRows.length === 0 && (
              <div className="cid-photo-presave-empty">No photos match your search. Try another name or ID.</div>
            )}
            {!gLoading && gRows.map((p) => (
              <div key={p.id} className={"cid-photo-option" + (p.id === (pendingPhotoId || selectedPhotoId) ? " selected" : "")} onClick={() => pickGlobal(p)}>
                <img src={`${backendUrl}/api.php?action=photoLibraryServe&id=${p.id}`} alt={p.type} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="cpo-name">{p.student_name || photoName(p)}</div>
                  <div className="cpo-meta">
                    <span className={"cpo-chip " + (p.source === "PHOTO_PROCESSING" ? "src-process" : "src-create")}>
                      {p.source === "PHOTO_PROCESSING" ? "PHOTO_PROCESSING" : "CREATE_ID"}
                    </span>
                    <span className={"cpo-chip" + (p.type === "PROCESSED" ? " type-processed" : "")}>{p.type}</span>
                    <span className="cpo-chip">{p.width}×{p.height}</span>
                  </div>
                  <div className="cpo-sub">{p.student_id_number || p.student_number || ""}{p.student_id_number || p.student_number ? " · " : ""}{formatDate(p.created_at)}</div>
                </div>
              </div>
            ))}
          </div>
          {gSearched && gTotal > gRows.length && (
            <small className="cid-photo-presave-hint">Showing {gRows.length} of {gTotal} matches — refine your search to narrow results.</small>
          )}
        </div>
      )}

      {/* Searchable dropdown (saved card only) */}
      {cardId ? (
      <div className="cid-photo-select">
        <input
          type="text"
          placeholder="Search Student Photos..."
          value={searchQ}
          onFocus={() => setOpen(true)}
          onChange={(e) => { setSearchQ(e.target.value); setOpen(true); }}
        />
        {open && (
          <>
            <div className="cid-dropdown-backdrop" onClick={() => setOpen(false)} />
            <div className="cid-photo-dropdown">
              <div className="cid-photo-filters" style={{ padding: "8px 10px", borderBottom: "1px solid #eef2ef" }}>
                <select value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}>
                  <option value="">All Sources</option>
                  <option value="CREATE_ID">Create ID</option>
                  <option value="PHOTO_PROCESSING">Photo Processing</option>
                </select>
                <select value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}>
                  <option value="">All Types</option>
                  <option value="ORIGINAL">Original</option>
                  <option value="PROCESSED">Processed</option>
                </select>
                <select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                  <option value="">All Status</option>
                  <option value="active">Active</option>
                  <option value="archived">Archived</option>
                </select>
              </div>
              {loading && <div style={{ padding: 10, fontSize: 12, color: "#8a948e" }}>Loading photos...</div>}
              {!loading && filtered.length === 0 && (
                <div style={{ padding: 12, fontSize: 12, color: "#8a948e" }}>
                  {photos.length === 0 ? "No photos in library yet. Upload below or use Photo Processing." : "No photos match your search."}
                </div>
              )}
              {!loading && filtered.map((p) => (
                <div key={p.id} className={"cid-photo-option" + (p.id === selectedPhotoId ? " selected" : "")} onClick={() => selectPhoto(p)}>
                  <img src={`${backendUrl}/api.php?action=photoLibraryServe&id=${p.id}`} alt={p.type} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div className="cpo-name">{photoName(p)}</div>
                    <div className="cpo-meta">
                      <span className={"cpo-chip " + (p.source === "PHOTO_PROCESSING" ? "src-process" : "src-create")}>
                        {p.source === "PHOTO_PROCESSING" ? "PHOTO_PROCESSING" : "CREATE_ID"}
                      </span>
                      <span className={"cpo-chip" + (p.type === "PROCESSED" ? " type-processed" : "")}>{p.type}</span>
                      {backgroundLabel(p) && <span className="cpo-chip">{backgroundLabel(p)}</span>}
                      <span className="cpo-chip">{p.width}×{p.height}</span>
                      {p.is_active !== 1 && <span className="cpo-chip status-archived">ARCHIVED</span>}
                    </div>
                    <div className="cpo-sub">{formatDate(p.created_at)}</div>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
      ) : null}

      {cardId ? (
      <label className="pp-dropzone" style={{ marginTop: 8, padding: 10, fontSize: 11, display: "block" }}>
        <div>📷 Quick Upload</div>
        <input type="file" accept="image/png,image/jpeg,image/webp" onChange={handleUpload} style={{ display: "none" }} />
      </label>
      ) : (
        <small className="cid-photo-presave-hint">Or upload a new photo above — it will be saved with this record.</small>
      )}
      {msg && <div style={{ fontSize: 11, marginTop: 4 }}>{msg}</div>}
    </div>
  );
}

/* ================= Photo Processing Module ================= */
function PhotoProcessingView({ user, api, assetUrl, onBack }) {
  const [searchQ, setSearchQ] = useState("");
  const [searchResults, setSearchResults] = useState([]);
  const [showResults, setShowResults] = useState(false);
  const [selectedStudent, setSelectedStudent] = useState(null);
  const [photos, setPhotos] = useState([]);
  const [selectedPhoto, setSelectedPhoto] = useState(null);
  const [previewUrl, setPreviewUrl] = useState("");
  const [msg, setMsg] = useState("");
  // Upload-first workflow: hold a file before a student is selected
  const [pendingFile, setPendingFile] = useState(null);
  const [pendingFileName, setPendingFileName] = useState("");
  const [quickCreateOpen, setQuickCreateOpen] = useState(false);
  const [quickName, setQuickName] = useState("");
  const [quickIdNumber, setQuickIdNumber] = useState("");
  const [quickLrn, setQuickLrn] = useState("");
  const [quickSection, setQuickSection] = useState("");
  const [quickType, setQuickType] = useState("COLLEGE");
  const [creatingQuick, setCreatingQuick] = useState(false);
  let searchTimer;
  function handleSearch(q) {
    setSearchQ(q);
    clearTimeout(searchTimer);
    if (q.trim().length < 2) { setSearchResults([]); setShowResults(false); return; }
    searchTimer = setTimeout(async () => {
      try {
        const r = await api("cards&search=" + encodeURIComponent(q) + "&per_page=10");
        const all = r.data || [];
        const seen = new Map();
        all.forEach((c) => {
          const key = (c.student_name || "").toLowerCase() + "|" + (c.student_id_number || "");
          if (!seen.has(key)) seen.set(key, c);
        });
        setSearchResults([...seen.values()].slice(0, 8));
        setShowResults(true);
      } catch (e) { setSearchResults([]); }
    }, 300);
  }

  async function selectStudent(s) {
    setSelectedStudent(s);
    setShowResults(false);
    setSearchQ(s.student_name);
    setMsg("");
    // If there is a pending file, upload it to the newly selected student
    if (pendingFile) {
      await uploadPendingToStudent(s.id);
    }
    try {
      const r = await api("photoLibraryList&student_id=" + s.id);
      setPhotos((r.data || []).map((p) => ({
        ...p,
        _url: `${BACKEND_URL}/api.php?action=photoLibraryServe&id=${p.id}`,
      })));
    } catch (e) { setMsg("Could not load photos: " + e.message); }
  }

  /** Upload a pending file to an existing student's library */
  async function uploadPendingToStudent(studentId) {
    if (!pendingFile) return;
    const fd = new FormData();
    fd.append("photo", pendingFile);
    fd.append("student_id", String(studentId));
    try {
      const r = await api("photoLibraryUpload", { method: "POST", body: fd });
      setMsg("Pending photo uploaded to " + (selectedStudent?.student_name || "#" + studentId) + ". Photo #" + r.photo_id);
      setPendingFile(null);
      setPendingFileName("");
      // Reload photos for the student
      const lr = await api("photoLibraryList&student_id=" + studentId);
      setPhotos((lr.data || []).map((p) => ({
        ...p,
        _url: `${BACKEND_URL}/api.php?action=photoLibraryServe&id=${p.id}`,
      })));
    } catch (e) {
      setMsg("Pending photo upload failed: " + e.message);
    }
  }

  /** Create a quick student record (minimal), then upload pending file */
  async function createQuickStudentAndUpload() {
    if (!quickName.trim()) { setMsg("Student name is required."); return; }
    if (quickLrn.trim() && !/^\d{12}$/.test(quickLrn.trim())) { setMsg("LRN must be exactly 12 digits."); return; }
    setCreatingQuick(true);
    try {
      const r = await api("quickCreateStudent", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          student_name: quickName.trim(),
          id_type: quickType,
          student_id_number: quickIdNumber.trim(),
          lrn: quickType === "COLLEGE" ? "" : quickLrn.trim(),
          section_name: quickType === "COLLEGE" ? "" : quickSection.trim(),
        }),
      });
      if (!r.success) throw new Error(r.message || "Quick create failed");
      const newId = r.id;
      setSelectedStudent({ id: newId, student_name: r.student_name, student_id_number: quickIdNumber.trim(), lrn: quickLrn.trim(), section_name: quickSection.trim(), course: "", grade_level: "", id_type: r.id_type });
      setSearchQ(r.student_name);
      setShowResults(false);
      setQuickCreateOpen(false);
      setQuickName("");
      setQuickIdNumber("");
      setQuickLrn("");
      setQuickSection("");
      // Upload pending file to the new student
      await uploadPendingToStudent(newId);
    } catch (e) {
      setMsg("Quick create failed: " + e.message);
    } finally {
      setCreatingQuick(false);
    }
  }

  async function handlePhotoUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    // Store as pending when no student is selected
    if (!selectedStudent) {
      setPendingFile(file);
      setPendingFileName(file.name);
      setPreviewUrl(url);
      setSelectedPhoto({ _url: url, _file: file, type: "ORIGINAL", source: "CREATE_ID" });
      setMsg("Photo loaded. Select a student or create a new one to save.");
      return;
    }
    // A student is already selected: show the preview and save right away
    setSelectedPhoto({ _url: url, _file: file, type: "ORIGINAL", source: "CREATE_ID" });
    setPreviewUrl(url);
    setMsg("");
    await savePhotoToStudent(file);
  }

  function selectExistingPhoto(p) {
    setSelectedPhoto(p);
    setPreviewUrl(p._url);
    setMsg("");
  }

  /* Save an uploaded file straight into the selected student's library.
   * Runs automatically right after the photo is chosen — there is no
   * separate name field or save button on this page. */
  async function savePhotoToStudent(file) {
    if (!selectedStudent || !file) return;
    try {
      const fd = new FormData();
      fd.append("photo", file);
      fd.append("student_id", String(selectedStudent.id));
      const r = await api("photoLibraryUpload", { method: "POST", body: fd });
      setMsg("Photo saved to " + selectedStudent.student_name + "'s library. Photo #" + r.photo_id);
      setSelectedPhoto(null);
      setPreviewUrl("");
      const lr = await api("photoLibraryList&student_id=" + selectedStudent.id);
      setPhotos((lr.data || []).map((p) => ({ ...p, _url: `${BACKEND_URL}/api.php?action=photoLibraryServe&id=${p.id}` })));
    } catch (e) { setMsg("Save failed: " + e.message); }
  }

  return (
      <div className="pp-page">
      <div className="pp-head">
        <button type="button" className="pp-back" onClick={() => { if (onBack) onBack(); }}>
          ← Back to Dashboard
        </button>
        <div className="pp-head-titles">
          <h2>Photo Processing</h2>
          <p>Search a student, then pick or upload a photo — it is saved to the library automatically.</p>
        </div>
      </div>
      <div className="pp-grid">
        <div className="pp-panel pp-col-student">
          <h3>Student</h3>
          <div className="cid-student-search">
            <input placeholder="Search ID Number or Full Name..." value={searchQ}
              onChange={(e) => handleSearch(e.target.value)}
              onFocus={() => searchResults.length > 0 && setShowResults(true)} />
            {showResults && searchResults.length > 0 && (
              <div className="cid-search-results">
                {searchResults.map((s) => (
                  <div key={s.id} className="cid-search-result" onClick={() => selectStudent(s)}>
                    <div className="csr-name">{s.student_name || "—"}</div>
                    <div className="csr-details">{s.student_id_number || s.student_number || ""} {s.course || s.grade_level} {s.section_name}</div>
                  </div>
                ))}
              </div>
            )}
            {showResults && searchResults.length === 0 && searchQ.length >= 2 && (
              <div className="cid-search-results"><div className="pp-no-results">No student found.</div>
                {pendingFile && (
                  <div className="pp-quick-create">
                    <button className="primary" style={{ fontSize: 12, marginTop: 6 }} onClick={() => setQuickCreateOpen(true)}>
                      + Create Student &amp; Upload Photo
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>
          {selectedStudent && (
            <div className="pp-selected-student">
              <b>{selectedStudent.student_name}</b><br />{selectedStudent.student_id_number || selectedStudent.student_number}<br />{selectedStudent.course || selectedStudent.grade_level}
            </div>
          )}
          {pendingFile && !selectedStudent && (
            <div className="pp-pending-box">
              <div className="pp-pending-title">Photo Ready</div>
              <div className="pp-pending-name">{pendingFileName}</div>
              <div className="pp-pending-hint">
                Select a student above or create a new one to save this photo.
              </div>
              <button type="button" className="primary" style={{ fontSize: 11, marginTop: 6 }} onClick={() => setQuickCreateOpen(true)}>
                + Create Student Record
              </button>
            </div>
          )}
          <h3 style={{ marginTop: 16 }}>Photo Library</h3>
          <div className="pp-photo-grid">
            {photos.filter((p) => p.is_active === 1).slice(0, 8).map((p) => (
              <button type="button" key={p.id} className={"pp-photo-item" + (selectedPhoto?.id === p.id ? " selected" : "")} onClick={() => selectExistingPhoto(p)} title={p.type}>
                <img src={p._url} alt={p.type} />
                <span className={"pp-photo-badge " + (p.source === "PHOTO_PROCESSING" ? "processed" : "original")}>{p.source === "PHOTO_PROCESSING" ? "PROC" : "ORIG"}</span>
              </button>
            ))}
            {photos.length === 0 && <div className="pp-photo-empty">No photos yet.</div>}
          </div>
        </div>
        <div className="pp-panel pp-panel-editor">
          <h3>Upload Photo</h3>
          <label className="pp-dropzone" style={{ marginBottom: 12 }}>
            <div>📷 Choose Photo</div>
            <p>JPG, JPEG, PNG{selectedStudent ? " — will be saved to " + selectedStudent.student_name : " — select a student first"}</p>
            <input type="file" accept="image/png,image/jpeg,image/webp" style={{ display: "none" }} onChange={handlePhotoUpload} />
          </label>
          {previewUrl ? (
            <div className="pp-editor-wrap"><img src={previewUrl} alt="Preview" className="pp-editor-img" /></div>
          ) : (
            <div className="pp-editor-wrap"><div className="pp-editor-placeholder"><div className="pp-icon">🖼</div><div>Choose a photo to preview it here</div></div></div>
          )}
          {msg && <div className="alert pp-msg">{msg}</div>}
        </div>
      </div>

      {quickCreateOpen && (
        <div className="audit-modal-overlay" onClick={() => !creatingQuick && setQuickCreateOpen(false)}>
          <div className="audit-modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 420 }}>
            <div className="audit-modal-head">
              <h3 style={{ margin: 0 }}>Create Student Record</h3>
            </div>
            <p style={{ fontSize: 12, color: "#6e7873", margin: "10px 0 14px" }}>
              A minimal student record will be created so the uploaded photo can be saved to the Photo Library. You can complete the details later in the Students module.
            </p>
            <div style={{ marginBottom: 10 }}>
              <label style={{ fontSize: 12, fontWeight: 700, display: "block", marginBottom: 4 }}>Full Name *</label>
              <input style={{ width: "100%" }} value={quickName} onChange={(e) => setQuickName(e.target.value)} placeholder="e.g. Juan Dela Cruz" autoFocus disabled={creatingQuick} />
            </div>
            <div style={{ marginBottom: 10 }}>
              <label style={{ fontSize: 12, fontWeight: 700, display: "block", marginBottom: 4 }}>ID Number</label>
              <input style={{ width: "100%" }} value={quickIdNumber} onChange={(e) => setQuickIdNumber(e.target.value)} placeholder="e.g. 2023-01234" disabled={creatingQuick} />
            </div>
            {quickType !== "COLLEGE" && (
              <div style={{ display: "flex", gap: 10 }}>
                <div style={{ marginBottom: 10, flex: 1 }}>
                  <label style={{ fontSize: 12, fontWeight: 700, display: "block", marginBottom: 4 }}>LRN</label>
                  <input style={{ width: "100%" }} value={quickLrn} onChange={(e) => setQuickLrn(e.target.value.replace(/[^\d]/g, "").slice(0, 12))} placeholder="12-digit LRN" inputMode="numeric" maxLength={12} disabled={creatingQuick} />
                </div>
                <div style={{ marginBottom: 10, flex: 1 }}>
                  <label style={{ fontSize: 12, fontWeight: 700, display: "block", marginBottom: 4 }}>Section</label>
                  <input style={{ width: "100%" }} value={quickSection} onChange={(e) => setQuickSection(e.target.value)} placeholder="e.g. Rizal" disabled={creatingQuick} />
                </div>
              </div>
            )}
            <div style={{ marginBottom: 14 }}>
              <label style={{ fontSize: 12, fontWeight: 700, display: "block", marginBottom: 4 }}>ID Type</label>
              <select style={{ width: "100%" }} value={quickType} onChange={(e) => setQuickType(e.target.value)} disabled={creatingQuick}>
                <option value="COLLEGE">College</option>
                <option value="JUNIOR_HIGH">Junior High</option>
                <option value="SENIOR_HIGH">Senior High</option>
              </select>
            </div>
            <div style={{ display: "flex", gap: 8, justifyContent: "flex-end" }}>
              <button onClick={() => setQuickCreateOpen(false)} disabled={creatingQuick}>Cancel</button>
              <button className="primary" disabled={creatingQuick || !quickName.trim()} onClick={createQuickStudentAndUpload}>
                {creatingQuick ? "Creating..." : "Create & Upload Photo"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* ================= Centralized Photo Library (admin) ================= */
function PhotoLibraryView({ user, onBack }) {
  const [filters, setFilters] = useState({ q: "", id_type: "", source: "", type: "", is_active: "1" });
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [loading, setLoading] = useState(false);
  const [msg, setMsg] = useState("");
  const [editRow, setEditRow] = useState(null);
  const [replacing, setReplacing] = useState(false);
  const [deletingId, setDeletingId] = useState(0);

  async function load(p) {
    setLoading(true);
    try {
      const qs = new URLSearchParams({ ...filters, page: p, per_page: 20 });
      const r = await api("photoLibrarySearch&" + qs.toString());
      setRows(r.data || []);
      setTotal(r.total || 0);
      setPages(r.total_pages || 1);
    } catch (e) { setMsg("Error: " + e.message); }
    setLoading(false);
  }

  /* Mount once — no deps array here previously caused an infinite fetch
   * loop (every re-render re-ran the effect), saturating the browser's
   * per-host connection limit and surfacing "Failed to fetch". */
  useEffect(() => { load(1); }, []);

  async function toggleArchive(p) {
    try {
      const actionName = p.is_active === 1 ? "photoLibraryArchive" : "photoLibraryRestore";
      await api(actionName, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ photo_id: p.id, student_id: p.student_id }) });
      setMsg(p.is_active === 1 ? "Photo archived." : "Photo restored.");
      if (editRow?.id === p.id) setEditRow((r) => (r ? { ...r, is_active: p.is_active === 1 ? 0 : 1 } : r));
      load(page);
    } catch (e) { setMsg(e.message); }
  }

  /* Replace the file behind the photo record opened in the popup */
  async function handleReplace(e) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file || !editRow) return;
    setReplacing(true);
    try {
      const fd = new FormData();
      fd.append("photo", file);
      fd.append("photo_id", String(editRow.id));
      await api("photoLibraryReplace", { method: "POST", body: fd });
      setMsg("Photo replaced.");
      setEditRow(null);
      await load(page);
    } catch (err) { setMsg(err.message); }
    setReplacing(false);
  }

  /* Permanently remove the photo record and its file */
  async function deletePhotoRow(p) {
    if (!confirm(`Delete this photo permanently?\n\nStudent: ${p.student_name || "#" + p.id}\nPhoto #${p.id}\n\nThe image file and its record will be removed. This cannot be undone.`)) return;
    setDeletingId(p.id);
    try {
      await api("photoLibraryDelete", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_id: p.id }),
      });
      setMsg("Photo deleted.");
      if (editRow?.id === p.id) setEditRow(null);
      await load(page);
    } catch (e) { setMsg(e.message); }
    setDeletingId(0);
  }

  return (
    <div style={{ padding: "20px 28px" }}>
      <div className="pp-head">
        <button type="button" className="pp-back" onClick={() => { if (onBack) onBack(); }}>
          ← Back to Dashboard
        </button>
        <div className="pp-head-titles">
          <h2 style={{ margin: 0 }}>Centralized Photo Library</h2>
          <p style={{ margin: "2px 0 0", color: "#6e7873", fontSize: 12 }}>Search, filter, and manage all student photos across every department.</p>
        </div>
      </div>
      <div className="pl-filter-bar">
        <input placeholder="Search name or ID..." value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
        <select value={filters.id_type} onChange={(e) => setFilters({ ...filters, id_type: e.target.value })}>
          <option value="">All Departments</option>
          <option value="COLLEGE">College</option>
          <option value="JUNIOR_HIGH">Junior High</option>
          <option value="SENIOR_HIGH">Senior High</option>
        </select>
        <select value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}>
          <option value="">All Sources</option>
          <option value="CREATE_ID">Create ID</option>
          <option value="PHOTO_PROCESSING">Photo Processing</option>
        </select>
        <select value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}>
          <option value="">All Types</option>
          <option value="ORIGINAL">Original</option>
          <option value="PROCESSED">Processed</option>
          <option value="ARCHIVED">Archived</option>
        </select>
        <select value={filters.is_active} onChange={(e) => setFilters({ ...filters, is_active: e.target.value })}>
          <option value="">All Status</option>
          <option value="1">Active</option>
          <option value="0">Archived</option>
        </select>
        <button className="primary" onClick={() => { setPage(1); load(1); }}>Search</button>
        {msg && <span style={{ fontSize: 12 }}>{msg}</span>}
      </div>
      {loading ? (
        <div style={{ textAlign: "center", padding: 40, color: "#8a948e" }}>Loading photos...</div>
      ) : (<>
        {/* Name-only table — zero image requests until a row's Edit popup opens */}
        <div className="pl-tablewrap">
          <table className="pl-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>LRN / Student No.</th>
                <th>Department</th>
                <th>Type</th>
                <th>Size</th>
                <th>Uploaded</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr><td colSpan="7" className="pl-table-empty">No photos found.</td></tr>
              ) : (
                rows.map((p) => (
                  <tr key={p.id}>
                    <td className="pl-name-cell">
                      {p.student_name || "—"}
                      {p.is_active !== 1 && <span className="cpo-chip status-archived">ARCHIVED</span>}
                    </td>
                    <td>{p.lrn || p.student_id_number || p.student_number || "—"}</td>
                    <td>{(typeTitles[p.id_type] || p.id_type || "—").replace("Basic Education - ", "")}</td>
                    <td>
                      <div style={{ display: "flex", gap: 4, flexWrap: "wrap" }}>
                        <span className={"pl-badge " + (p.source === "PHOTO_PROCESSING" ? "source-process" : "source-create")}>{p.source === "PHOTO_PROCESSING" ? "PROC" : "CREATE"}</span>
                        <span className={"pl-badge type-" + p.type.toLowerCase()}>{p.type}</span>
                      </div>
                    </td>
                    <td>{p.width}×{p.height} · {(p.file_size / 1024).toFixed(0)} KB</td>
                    <td>{formatDateTime(p.created_at)}</td>
                    <td>
                      <div className="pl-actions">
                        <button type="button" onClick={() => setEditRow(p)} disabled={deletingId === p.id}>Edit</button>
                        <button type="button" className="danger" onClick={() => deletePhotoRow(p)} disabled={deletingId === p.id}>{deletingId === p.id ? "Deleting…" : "Delete"}</button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {pages > 1 && (
          <div className="audit-pager" style={{ marginTop: 16 }}>
            <span>Page {page} of {pages} · {total} photos</span>
            <div>
              <button disabled={page <= 1} onClick={() => { const np = page - 1; setPage(np); load(np); }}>← Prev</button>
              <button disabled={page >= pages} onClick={() => { const np = page + 1; setPage(np); load(np); }}>Next →</button>
            </div>
          </div>
        )}
      </>)}

      {/* ===== Edit popup: preview + replace the photo file ===== */}
      {editRow && (
        <div className="audit-modal-overlay" onClick={() => !replacing && setEditRow(null)}>
          <div className="audit-modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 460 }}>
            <div className="audit-modal-head">
              <h3 style={{ margin: 0 }}>Edit Photo — {editRow.student_name || "#" + editRow.id}</h3>
              <button type="button" onClick={() => setEditRow(null)}>×</button>
            </div>
            <div className="pl-edit-preview">
              <img src={`${BACKEND_URL}/api.php?action=photoLibraryServe&id=${editRow.id}`} alt="Current photo" />
              <div className="pl-edit-meta">
                Photo #{editRow.id} · {editRow.width}×{editRow.height} · {(editRow.file_size / 1024).toFixed(0)} KB · {formatDateTime(editRow.created_at)}
              </div>
            </div>
            <label className="pp-dropzone pp-dropzone-sm" style={{ cursor: replacing ? "wait" : "pointer" }}>
              <div>{replacing ? "Replacing…" : "📤 Replace Photo"}</div>
              <p>Choose a new JPG / PNG / WEBP — the current file will be replaced</p>
              <input type="file" accept="image/png,image/jpeg,image/webp" style={{ display: "none" }} disabled={replacing} onChange={handleReplace} />
            </label>
            <div style={{ display: "flex", gap: 8, justifyContent: "space-between", marginTop: 14 }}>
              <button type="button" onClick={() => toggleArchive(editRow)}>{editRow.is_active === 1 ? "Archive" : "Restore"}</button>
              <button type="button" className="primary" onClick={() => setEditRow(null)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* ================= Bulk ID Generation Modal ================= */
function BulkGenerateModal({
  records,
  templates,
  selected,
  setSelected,
  preview,
  setPreview,
  generating,
  progress,
  templateId,
  setTemplateId,
  validateFn,
  generateFn,
  onClose,
}) {
  const [selectAll, setSelectAll] = useState(true);
  const toggleSelect = (id) => {
    setSelected((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  };
  const runValidation = async () => {
    setPreview(null);
    await validateFn();
  };
  const visibleRecords = records.slice(0, 20);
  return (
    <div className="audit-modal-overlay" onClick={() => !generating && onClose()}>
      <div className="audit-modal bulk-modal" onClick={(e) => e.stopPropagation()}>
        <div className="audit-modal-head">
          <h3>Bulk ID Generation</h3>
          <button onClick={() => !generating && onClose()}>✕</button>
        </div>

        {!preview && !generating && (
          <>
            <div className="audit-block">
              <h4>ID Template</h4>
              <select value={templateId} onChange={(e) => setTemplateId(e.target.value ? Number(e.target.value) : "")} className="bulk-template-select">
                <option value="">— Use each student's template —</option>
                {templates.map((t) => (<option key={t.id} value={t.id}>{t.name}</option>))}
              </select>
            </div>
            <div className="audit-block">
              <h4>Students ({selected.length} selected of {records.length})</h4>
              <label className="bulk-select-all">
                <input type="checkbox" checked={selectAll} onChange={(e) => {
                  setSelectAll(e.target.checked);
                  if (e.target.checked) setSelected(records.map((r) => r.id));
                  else setSelected([]);
                }} />
                <span>Select all {records.length} students</span>
              </label>
              <div className="bulk-student-list">
                {visibleRecords.map((r) => (
                  <label key={r.id} className="bulk-student-item">
                    <input type="checkbox" checked={selected.includes(r.id)} onChange={() => toggleSelect(r.id)} disabled={generating} />
                    <span>{r.student_name || "# " + r.id}</span>
                    <small>{r.student_id_number || r.student_number || r.lrn || "No ID number"}</small>
                  </label>
                ))}
                {records.length > 20 && (<small className="bulk-more-hint">Showing first 20 of {records.length} students</small>)}
              </div>
            </div>
            <div className="bulk-modal-actions">
              <button type="button" onClick={onClose}>Cancel</button>
              <button type="button" className="primary" onClick={runValidation} disabled={selected.length === 0}>Validate & Preview</button>
            </div>
          </>
        )}

        {preview && !generating && (
          <div className="bulk-preview">
            <div className="bulk-counts">
              <div className="bulk-count-item"><b>{preview.counts.total}</b><span>Selected</span></div>
              <div className="bulk-count-item ok"><b>{preview.counts.ready}</b><span>Ready</span></div>
              <div className="bulk-count-item warn"><b>{preview.counts.incomplete}</b><span>Incomplete</span></div>
              <div className="bulk-count-item warn"><b>{preview.counts.already_generated}</b><span>Already Gen</span></div>
              <div className="bulk-count-item err"><b>{preview.counts.duplicate + preview.counts.error}</b><span>Dup/Err</span></div>
            </div>
            <div className="bulk-preview-list">
              <h4>Ready to Generate ({preview.counts.ready})</h4>
              {preview.results.filter((r) => r.status === "ready").slice(0, 10).map((r) => (
                <div key={r.id} className="bulk-preview-item">
                  <span>{r.student.student_name}</span>
                  <small>{r.student.student_number || r.student.lrn || "# " + r.student.id}</small>
                  <button className="bulk-remove" onClick={() => setSelected((prev) => prev.filter((x) => x !== r.id))} title="Remove from batch">✕</button>
                </div>
              ))}
              {preview.counts.ready > 10 && (<small className="bulk-more-hint">+ {preview.counts.ready - 10} more ready students</small>)}
            </div>
            {preview.counts.incomplete > 0 && (
              <div className="bulk-issue-list">
                <h4>Incomplete Records ({preview.counts.incomplete})</h4>
                {preview.results.filter((r) => r.status === "incomplete").map((r) => (
                  <div key={r.id} className="bulk-issue-item">
                    <span>{r.student?.student_name || "# " + r.id}</span>
                    <small>{r.issues.join(", ")}</small>
                  </div>
                ))}
              </div>
            )}
            <div className="bulk-modal-actions">
              <button type="button" onClick={() => setPreview(null)}>Back</button>
              <button type="button" className="primary" onClick={generateFn} disabled={preview.counts.ready === 0}>Generate IDs & Download ZIP</button>
            </div>
          </div>
        )}

        {generating && (
          <div className="bulk-progress">
            <div className="bulk-progress-bar">
              <div className="bulk-progress-fill" style={{ width: `${progress.total > 0 ? Math.round((progress.current / progress.total) * 100) : 0}%` }} />
            </div>
            <p>Generating IDs: {progress.current} / {progress.total}{progress.failed > 0 && ` (${progress.failed} failed)`}</p>
            <p className="bulk-more-hint">Please keep this tab open — rendering each card at full print resolution.</p>
          </div>
        )}
      </div>
    </div>
  );
}

/* ================= Batch Printing Modal =================
 * Confirmation → progress → result for printing several IDs in ONE Smart
 * ID 51 job. Mirrors BulkGenerateModal's flow: the exact selected count is
 * shown before printing, IDs that were printed before force a reprint
 * reason (recorded in the Print History), and the render progress is
 * tracked card by card. Close is locked while a job is running. */
function BulkPrintModal({
  records,
  selected,
  setSelected,
  printing,
  progress,
  result,
  reasonChoice,
  setReasonChoice,
  reasonText,
  setReasonText,
  printFn,
  onClose,
}) {
  /* First prints vs reprints — the same split the server derives from
   * print_count / printed_at when recording the print history. */
  const originals = records.filter(
    (r) => !r.print_count && !r.printed_at,
  ).length;
  const reprints = records.length - originals;
  const reasonNeeded = reprints > 0;
  const reason = !reasonNeeded
    ? ""
    : reasonChoice === "Other"
      ? reasonText.trim()
      : reasonChoice;
  const removeFromBatch = (id) => {
    if (printing) return;
    setSelected((prev) => prev.filter((x) => x !== id));
  };
  const visibleRecords = records.slice(0, 20);
  const overCap = records.length > MAX_BULK_PRINT;
  return (
    <div className="audit-modal-overlay" onClick={() => !printing && onClose()}>
      <div
        className="audit-modal bulk-print-modal"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="audit-modal-head">
          <h3>Batch Print — Smart ID 51</h3>
          <button onClick={() => !printing && onClose()}>✕</button>
        </div>

        {!printing && !result && (
          <>
            <div className="bulk-counts">
              <div className="bulk-count-item">
                <b>{records.length}</b>
                <span>Selected</span>
              </div>
              <div className="bulk-count-item ok">
                <b>{originals}</b>
                <span>First print</span>
              </div>
              <div className="bulk-count-item warn">
                <b>{reprints}</b>
                <span>Reprint</span>
              </div>
            </div>
            {overCap && (
              <p className="bp-warn">
                Only the first {MAX_BULK_PRINT} selected IDs fit in one print
                job — print the rest in a second batch.
              </p>
            )}
            <div className="audit-block">
              <h4>IDs in this batch ({records.length})</h4>
              <div className="bulk-student-list">
                {visibleRecords.map((r) => (
                  <div key={r.id} className="bulk-preview-item">
                    <span>{r.student_name || "# " + r.id}</span>
                    <small>
                      {r.student_number || r.student_id_number || r.lrn || ""}
                      {r.print_count > 0 || r.printed_at
                        ? ` · reprint (printed ×${r.print_count || 0})`
                        : " · first print"}
                    </small>
                    <button
                      className="bulk-remove"
                      onClick={() => removeFromBatch(r.id)}
                      disabled={printing}
                      title="Remove from batch"
                    >
                      ✕
                    </button>
                  </div>
                ))}
                {records.length > 20 && (
                  <small className="bulk-more-hint">
                    Showing first 20 of {records.length} students
                  </small>
                )}
              </div>
            </div>
            <div className="audit-block">
              {reasonNeeded ? (
                <>
                  <h4>
                    Reprint reason (required — {reprints} ID
                    {reprints === 1 ? "" : "s"} already printed)
                  </h4>
                  <div className="reason-choices">
                    {["Damaged", "Lost", "Incorrect Information", "Other"].map(
                      (r) => (
                        <label
                          key={r}
                          className={
                            "reason-choice" +
                            (reasonChoice === r ? " selected" : "")
                          }
                        >
                          <input
                            type="radio"
                            name="bulk-print-reason"
                            value={r}
                            checked={reasonChoice === r}
                            onChange={() => setReasonChoice(r)}
                            disabled={printing}
                          />
                          {r}
                        </label>
                      ),
                    )}
                  </div>
                  {reasonChoice === "Other" && (
                    <label className="field full">
                      <span>Specify the reason</span>
                      <input
                        value={reasonText}
                        onChange={(e) => setReasonText(e.target.value)}
                        placeholder="Applies to every reprint in this batch"
                        maxLength={200}
                        disabled={printing}
                      />
                    </label>
                  )}
                </>
              ) : (
                <p className="bp-note">
                  Every selected ID is a first print — each will be recorded as
                  “Original · New Student” in the <b>Print History</b>.
                </p>
              )}
            </div>
            <div className="bulk-modal-actions">
              <button type="button" onClick={onClose}>
                Cancel
              </button>
              <button
                type="button"
                className="primary"
                disabled={records.length === 0 || (reasonNeeded && reason === "")}
                onClick={() => printFn(reason)}
              >
                🖨 Print {records.length} ID{records.length === 1 ? "" : "s"}{" "}
                (Front + Back)
              </button>
            </div>
          </>
        )}

        {printing && (
          <div className="bulk-progress">
            <div className="bulk-progress-bar">
              <div
                className="bulk-progress-fill"
                style={{
                  width:
                    progress.phase === "render" && progress.total > 0
                      ? `${Math.round((progress.current / progress.total) * 100)}%`
                      : "100%",
                }}
              />
            </div>
            <p>
              {progress.phase === "render"
                ? `Rendering IDs: ${progress.current} / ${progress.total}`
                : progress.phase === "send"
                  ? "Sending the job to the printer — choose the Smart ID 51 in the print dialog…"
                  : "Recording the print jobs in the Print History…"}
            </p>
            <p className="bulk-more-hint">
              Please keep this tab open — each ID is rendered at full print
              resolution (front + back).
            </p>
          </div>
        )}

        {result && !printing && (
          <div className="bp-result">
            {result.error ? (
              <>
                <p className="bp-warn">Batch print failed: {result.error}</p>
                <div className="bulk-modal-actions">
                  <button type="button" onClick={onClose}>
                    Close
                  </button>
                </div>
              </>
            ) : (
              <>
                <div className="bulk-counts">
                  <div className="bulk-count-item ok">
                    <b>{result.printed}</b>
                    <span>Printed</span>
                  </div>
                  <div className="bulk-count-item warn">
                    <b>{result.skipped.length}</b>
                    <span>Skipped</span>
                  </div>
                  <div className="bulk-count-item err">
                    <b>{result.missing.length}</b>
                    <span>Not found</span>
                  </div>
                </div>
                {result.skipped.length > 0 && (
                  <div className="bp-result-list">
                    <h4>Skipped (released — cannot be printed)</h4>
                    {result.skipped.map((s) => (
                      <div key={s.id} className="bp-result-item">
                        <span>{s.student_name || "# " + s.id}</span>
                      </div>
                    ))}
                  </div>
                )}
                <p className="bp-note">
                  Every printed ID was recorded in the <b>Print History</b> and
                  the batch was written to the <b>Audit Log</b>.
                </p>
                <div className="bulk-modal-actions">
                  <button type="button" className="primary" onClick={onClose}>
                    Done
                  </button>
                </div>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
const DASH_STATUS_OPTIONS = [
  ["", "All statuses"],
  ["created", "Created"],
  ["done", "Done"],
  ["edited", "Edited"],
  ["printed", "Printed"],
  ["released", "Released"],
];
const DASH_MONTHS = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
];
const DASH_DEPT_COLORS = {
  COLLEGE: "#205f38",
  JUNIOR_HIGH: "#1968c7",
  SENIOR_HIGH: "#850074",
};
const dashPct = (part, whole) => (whole > 0 ? Math.round((part / whole) * 100) : 0);
function dashMonthLabel(ym) {
  const [y, m] = String(ym).split("-");
  const i = Number(m) - 1;
  return DASH_MONTHS[i] ? `${DASH_MONTHS[i]} ${y}` : String(ym);
}
/* Continuous month axis for the Monthly chart: from→to when a date range is
 * selected, otherwise the trailing 12 months. Missing months render as 0. */
function dashMonthKeys(from, to) {
  const parse = (s) => (s ? new Date(`${s}T00:00:00`) : null);
  let start = parse(from);
  let end = parse(to);
  if (start && end && !isNaN(start.getTime()) && !isNaN(end.getTime()) && start > end) {
    [start, end] = [end, start];
  }
  if (!start || !end || isNaN(start.getTime()) || isNaN(end.getTime())) {
    const now = new Date();
    end = new Date(now.getFullYear(), now.getMonth(), 1);
    start = new Date(end.getFullYear(), end.getMonth() - 11, 1);
  }
  const keys = [];
  const cur = new Date(start.getFullYear(), start.getMonth(), 1);
  let guard = 0;
  while (cur <= end && guard < 36) {
    keys.push(`${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, "0")}`);
    cur.setMonth(cur.getMonth() + 1);
    guard++;
  }
  return keys;
}
function StatCard({ icon, label, value, hint, tone }) {
  return (
    <div className={`stat-card ${tone || ""}`}>
      <span className="stat-icon" aria-hidden="true">
        {icon}
      </span>
      <div className="stat-body">
        <b className="stat-value">{value}</b>
        <span className="stat-label">{label}</span>
        {hint ? <small className="stat-hint">{hint}</small> : null}
      </div>
    </div>
  );
}
/* Department / Level analytics — horizontal bars per existing id_type
 * (College / Junior High / Senior High) with printed + released breakdown. */
function DeptChart({ rows }) {
  const data = rows || [];
  if (!data.length) {
    return <p className="chart-empty">No records match the selected filters.</p>;
  }
  const max = Math.max(1, ...data.map((d) => d.total));
  return (
    <div className="dept-chart">
      {data.map((d) => (
        <div key={d.id_type} className="dept-row">
          <div className="dept-head">
            <span className="dept-name">
              <i
                className="dept-dot"
                style={{ background: DASH_DEPT_COLORS[d.id_type] || "#205f38" }}
              />
              {typeTitles[d.id_type] || d.id_type}
            </span>
            <span className="dept-total">{d.total}</span>
          </div>
          <div className="dept-track">
            <div
              className="dept-fill"
              style={{
                width: `${(d.total / max) * 100}%`,
                background: DASH_DEPT_COLORS[d.id_type] || "#205f38",
              }}
              title={`${d.total} total · ${d.printed} printed · ${d.released} released`}
            />
          </div>
          <small className="dept-sub">
            {d.printed} printed · {d.released} released
          </small>
        </div>
      ))}
    </div>
  );
}

/* Monthly ID Generation chart — dependency-free SVG. Bars = IDs created
 * (generated) that month, line = print jobs recorded that month. */
function MonthlyChart({ generated, printed, monthKeys }) {
  const keys = monthKeys.length ? monthKeys : Object.keys(generated || {}).sort();
  const data = keys.map((k) => ({
    ym: k,
    g: (generated && generated[k]) || 0,
    p: (printed && printed[k]) || 0,
  }));
  const max = Math.max(1, ...data.map((d) => Math.max(d.g, d.p)));
  const W = 560;
  const H = 145;
  const padL = 34;
  const padR = 8;
  const padT = 12;
  const padB = 27;
  const iw = W - padL - padR;
  const ih = H - padT - padB;
  const n = Math.max(1, data.length);
  const step = iw / n;
  const barW = Math.max(5, Math.min(24, step * 0.5));
  const y = (v) => padT + ih - (v / max) * ih;
  const cx = (i) => padL + step * i + step / 2;
  const labelEvery = Math.ceil(n / 12);
  const linePts = data.map((d, i) => `${cx(i)},${y(d.p)}`).join(" ");
  const allZero = data.every((d) => d.g === 0 && d.p === 0);
  return (
    <div className="month-chart">
      <svg
        viewBox={`0 0 ${W} ${H}`}
        preserveAspectRatio="xMidYMid meet"
        role="img"
        aria-label="IDs generated per month"
      >
        {[max, max / 2, 0].map((v, i) => (
          <g key={i}>
            <line x1={padL} x2={W - padR} y1={y(v)} y2={y(v)} className="mc-grid" />
            <text x={padL - 6} y={y(v) + 3} className="mc-num" textAnchor="end">
              {Math.round(v)}
            </text>
          </g>
        ))}
        {data.map((d, i) => (
          <g key={d.ym}>
            <rect
              x={cx(i) - barW / 2}
              y={y(d.g)}
              width={barW}
              height={padT + ih - y(d.g)}
              rx={2}
              className="mc-bar"
            >
              <title>{`${dashMonthLabel(d.ym)} — generated: ${d.g} · printed: ${d.p}`}</title>
            </rect>
            {d.g > 0 && n <= 14 ? (
              <text x={cx(i)} y={y(d.g) - 3} className="mc-val" textAnchor="middle">
                {d.g}
              </text>
            ) : null}
            {i % labelEvery === 0 ? (
              <text x={cx(i)} y={padT + ih + 12} className="mc-month" textAnchor="middle">
                {dashMonthLabel(d.ym)}
              </text>
            ) : null}
          </g>
        ))}
        {!allZero && (
          <>
            <polyline points={linePts} className="mc-line" fill="none" />
            {data.map((d, i) =>
              d.p > 0 ? (
                <circle key={`c${d.ym}`} cx={cx(i)} cy={y(d.p)} r={3} className="mc-dot">
                  <title>{`${dashMonthLabel(d.ym)} — printed: ${d.p}`}</title>
                </circle>
              ) : null,
            )}
          </>
        )}
        <line x1={padL} x2={W - padR} y1={padT + ih} y2={padT + ih} className="mc-axis" />
      </svg>
      {allZero ? <p className="chart-empty">No IDs generated in this period.</p> : null}
    </div>
  );
}

function Dash({
  title,
  icon,
  count,
  action,
  desc,
  buttonLabel = "Create ID",
}) {
  return (
    <div className="dash-card">
      <div className="dash-head">
        {icon && <span className="dash-icon">{icon}</span>}
        <span className="dash-count">{count}</span>
        <h2>{title}</h2>
      </div>
      <p>{desc}</p>
      <button className="primary" onClick={action}>
        {buttonLabel}
      </button>
    </div>
  );
}

function ExportButtons() {
  const [busy, setBusy] = useState(null);
  async function go(id, fmt) {
    try {
      setBusy(id + "-" + fmt);
      await exportCard(id, fmt);
    } finally {
      setBusy(null);
    }
  }
  return (
    <div className="export-grid">
      <button onClick={() => go("front-card", "png")} disabled={!!busy}>
        {busy === "front-card-png" ? "Exporting…" : "Front PNG"}
      </button>
      <button onClick={() => go("back-card", "png")} disabled={!!busy}>
        {busy === "back-card-png" ? "Exporting…" : "Back PNG"}
      </button>
      <button onClick={() => go("front-card", "jpg")} disabled={!!busy}>
        {busy === "front-card-jpg" ? "Exporting…" : "Front JPG"}
      </button>
      <button onClick={() => go("back-card", "jpg")} disabled={!!busy}>
        {busy === "back-card-jpg" ? "Exporting…" : "Back JPG"}
      </button>
    </div>
  );
}
/*
 * ErrorBoundary
 * -------------
 * Without this, ANY uncaught render error unmounts the whole React tree and
 * the user is left staring at a blank white page with no clue what happened
 * (that is exactly how the missing `markCardDone` handler presented itself).
 * React 18 only logs the error to the console, which is invisible to staff.
 *
 * This catches it and shows the message plus a Reload button instead.
 */
class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }
  static getDerivedStateFromError(error) {
    return { error };
  }
  componentDidCatch(error, info) {
    /* Keep the real stack in the console for whoever debugs it. */
    console.error("Unhandled UI error:", error, info?.componentStack);
  }
  render() {
    if (!this.state.error) return this.props.children;
    return (
      <div className="login-shell">
        <div className="login-card" style={{ textAlign: "center" }}>
          <div className="login-mark" style={{ margin: "0 auto 12px" }}>
            LSC
          </div>
          <h3 style={{ margin: "0 0 8px" }}>Something went wrong</h3>
          <p style={{ fontSize: 12, color: "#5d6b63", wordBreak: "break-word" }}>
            {String(this.state.error?.message || this.state.error)}
          </p>
          <button
            className="primary"
            style={{ marginTop: 14 }}
            onClick={() => window.location.reload()}
          >
            Reload
          </button>
        </div>
      </div>
    );
  }
}

createRoot(document.getElementById("root")).render(
  <ErrorBoundary>
    <App />
  </ErrorBoundary>,
);
