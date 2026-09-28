# -*- coding: utf-8 -*-
"""
Lake Shore Colleges ID Management System - Detailed PowerPoint Generator
Creates an editable .pptx presenting the full system.
"""
import os
from pptx import Presentation
from pptx.util import Inches, Pt
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.enum.shapes import MSO_SHAPE

# Theme (matches the system brand)
DARK   = RGBColor(0x17, 0x3F, 0x29)
GREEN  = RGBColor(0x20, 0x5F, 0x38)
MID    = RGBColor(0x2D, 0x68, 0x48)
WHITE  = RGBColor(0xFF, 0xFF, 0xFF)
CREAM  = RGBColor(0xF4, 0xF6, 0xF5)
INK    = RGBColor(0x22, 0x31, 0x2A)
GRAY   = RGBColor(0x6E, 0x78, 0x73)
GOLD   = RGBColor(0xF0, 0xB5, 0x1F)
LINEC  = RGBColor(0xD6, 0xDE, 0xD8)

FONT_H = "Oswald"
FONT_B = "Montserrat"

PPTX_DIR = os.path.dirname(os.path.abspath(__file__))
LOGO = os.path.join(os.path.dirname(PPTX_DIR), "frontend", "public", "lsc-logo.png")

prs = Presentation()
prs.slide_width  = Inches(13.333)
prs.slide_height = Inches(7.5)
BLANK = prs.slide_layouts[6]
SW, SH = prs.slide_width, prs.slide_height

# Running slide counter: every new_slide() bumps it and header() prints it,
# so page numbers stay correct when slides are inserted anywhere in the deck.
_slide_no = 0

def new_slide():
    global _slide_no
    _slide_no += 1
    return prs.slides.add_slide(BLANK)

def bg(slide, color):
    slide.background.fill.solid()
    slide.background.fill.fore_color.rgb = color

def rect(slide, x, y, w, h, fill, line=None, shape=MSO_SHAPE.RECTANGLE, lw=0.75):
    sp = slide.shapes.add_shape(shape, x, y, w, h)
    if fill is None:
        sp.fill.background()
    else:
        sp.fill.solid()
        sp.fill.fore_color.rgb = fill
    if line is None:
        sp.line.fill.background()
    else:
        sp.line.color.rgb = line
        sp.line.width = Pt(lw)
    sp.shadow.inherit = False
    return sp
def txt(slide, x, y, w, h, paras, align=PP_ALIGN.LEFT, anchor=MSO_ANCHOR.TOP, wrap=True):
    """paras: list of dicts: {'runs':[(text,size,bold,color,font)], 'sa':.., 'ls':..}"""
    tb = slide.shapes.add_textbox(x, y, w, h)
    tf = tb.text_frame
    tf.word_wrap = wrap
    tf.vertical_anchor = anchor
    tf.margin_left = tf.margin_right = tf.margin_top = tf.margin_bottom = 0
    first = True
    for p in paras:
        para = tf.paragraphs[0] if first else tf.add_paragraph()
        first = False
        para.alignment = p.get("align", align)
        para.space_after = Pt(p.get("sa", 4))
        para.space_before = Pt(p.get("sb", 0))
        para.line_spacing = p.get("ls", 1.05)
        for (t, size, bold, color, fname) in p["runs"]:
            r = para.add_run()
            r.text = t
            r.font.size = Pt(size)
            r.font.bold = bold
            r.font.color.rgb = color
            r.font.name = fname
    return tb

def bullets(slide, items, x, y, w, h, size=15, gap=8, color=INK, marker_color=GREEN,
            bullet="\u25AA", ls=1.08, head=None):
    paras = []
    if head:
        paras.append({"runs": [(head[0], head[1], True, head[2], FONT_H)], "sa": 12})
    for it in items:
        if isinstance(it, tuple):
            pre, rest = it
        else:
            pre, rest = "", it
        runs = [(bullet + "  ", size, True, marker_color, FONT_B)]
        if pre:
            runs.append((pre + " ", size, True, color, FONT_B))
        runs.append((rest, size, False, color, FONT_B))
        paras.append({"runs": runs, "sa": gap, "ls": ls})
    return txt(slide, x, y, w, h, paras)

def header(slide, title, kicker=None, page=None):
    bg(slide, WHITE)
    txt(slide, Inches(0.6), Inches(0.35), Inches(11.5), Inches(0.6),
        [{"runs": [(title, 29, True, GREEN, FONT_H)]}])
    if kicker:
        txt(slide, Inches(0.62), Inches(0.98), Inches(11.5), Inches(0.4),
            [{"runs": [(kicker, 12, False, GRAY, FONT_B)]}])
    rect(slide, Inches(0.6), Inches(1.45), SW - Inches(1.2), Pt(2.5), LINEC)
    # Page number always comes from the running counter, so inserting a slide
    # anywhere in the deck never leaves a wrong number behind.
    pnum = slide.shapes.add_textbox(SW - Inches(1.1), SH - Inches(0.5), Inches(0.8), Inches(0.35))
    pf = pnum.text_frame
    pf.paragraphs[0].alignment = PP_ALIGN.RIGHT
    r = pf.paragraphs[0].add_run()
    r.text = str(_slide_no)
    r.font.size = Pt(11)
    r.font.color.rgb = GRAY
    r.font.name = FONT_B

def footer(slide, label="Lake Shore Colleges  •  ID Management System"):
    f = slide.shapes.add_textbox(Inches(0.6), SH - Inches(0.5), Inches(8), Inches(0.35))
    r = f.text_frame.paragraphs[0].add_run()
    r.text = label
    r.font.size = Pt(9)
    r.font.color.rgb = GRAY
    r.font.name = FONT_B

def section_slide(num, title, subtitle=None):
    s = new_slide()
    bg(s, DARK)
    rect(s, 0, 0, Inches(0.35), SH, GREEN)
    txt(s, Inches(1.2), Inches(2.5), Inches(11), Inches(1.2),
        [{"runs": [(f"0{num}", 52, True, GOLD, FONT_H)]}])
    txt(s, Inches(1.2), Inches(3.35), Inches(11), Inches(1.2),
        [{"runs": [(title, 40, True, WHITE, FONT_H)]}])
    if subtitle:
        txt(s, Inches(1.22), Inches(4.45), Inches(10.5), Inches(1.4),
            [{"runs": [(subtitle, 15, False, RGBColor(0xBF, 0xD6, 0xC8), FONT_B)], "ls": 1.25}])
    return s

def table(slide, data, x, y, w, h, col_w=None, header_fill=GREEN, header_color=WHITE,
          font_size=11, row_h=0.42, head_h=0.5):
    rows, cols = len(data), len(data[0])
    tbl_shape = slide.shapes.add_table(rows, cols, x, y, w, h)
    tbl = tbl_shape.table
    tbl.first_row = False
    tbl.horz_banding = False
    if col_w:
        for ci, cw in enumerate(col_w):
            tbl.columns[ci].width = cw
    tbl.rows[0].height = Inches(head_h)
    for ri in range(1, rows):
        tbl.rows[ri].height = Inches(row_h)
    for ri, row in enumerate(data):
        for ci, val in enumerate(row):
            cell = tbl.cell(ri, ci)
            cell.margin_left = Inches(0.08)
            cell.margin_right = Inches(0.06)
            cell.margin_top = Inches(0.02)
            cell.margin_bottom = Inches(0.02)
            cell.vertical_anchor = MSO_ANCHOR.MIDDLE
            tfc = cell.text_frame
            tfc.word_wrap = True
            p = tfc.paragraphs[0]
            p.alignment = PP_ALIGN.LEFT
            r = p.add_run()
            r.text = str(val)
            r.font.size = Pt(font_size)
            r.font.name = FONT_B
            if ri == 0:
                cell.fill.solid()
                cell.fill.fore_color.rgb = header_fill
                r.font.bold = True
                r.font.color.rgb = header_color
            else:
                cell.fill.solid()
                cell.fill.fore_color.rgb = WHITE if ri % 2 else RGBColor(0xEF, 0xF4, 0xF1)
                r.font.bold = ci == 0
                r.font.color.rgb = INK
    return tbl_shape

def chip(slide, x, y, w, h, label, fill=GREEN, size=12, color=WHITE):
    sp = rect(slide, x, y, w, h, fill, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    sp.adjustments[0] = 0.5
    tf = sp.text_frame
    tf.word_wrap = True
    tf.vertical_anchor = MSO_ANCHOR.MIDDLE
    p = tf.paragraphs[0]
    p.alignment = PP_ALIGN.CENTER
    r = p.add_run()
    r.text = label
    r.font.size = Pt(size)
    r.font.bold = True
    r.font.color.rgb = color
    r.font.name = FONT_B
    return sp
# ===========================================================================
# Slide 1 - Title
# ===========================================================================
s = new_slide()
bg(s, DARK)
rect(s, 0, 0, SW, Inches(0.28), GOLD)
rect(s, 0, SH - Inches(0.28), SW, Inches(0.28), GOLD)
rect(s, Inches(5.42), Inches(1.0), Inches(2.5), Inches(2.5), GREEN, shape=MSO_SHAPE.OVAL)
rect(s, Inches(5.26), Inches(0.84), Inches(2.82), Inches(2.82), None,
     line=RGBColor(0x2A, 0x4F, 0x3A), shape=MSO_SHAPE.OVAL, lw=1.5)
if os.path.exists(LOGO):
    s.shapes.add_picture(LOGO, Inches(5.62), Inches(1.2), Inches(2.1), Inches(2.1))
txt(s, Inches(0.8), Inches(4.15), Inches(11.7), Inches(1.15),
    [{"runs": [("LAKE SHORE COLLEGES", 40, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(0.8), Inches(5.15), Inches(11.7), Inches(0.9),
    [{"runs": [("ID MANAGEMENT SYSTEM", 30, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(1.5), Inches(6.15), Inches(10.3), Inches(0.7),
    [{"runs": [("Automated Student ID Creation, Tracking, Preview, Export & Printing",
                15, False, RGBColor(0xCF, 0xE0, 0xD5), FONT_B)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(1.5), Inches(6.75), Inches(10.3), Inches(0.5),
    [{"runs": [("System Presentation  •  v2.0", 12, False, RGBColor(0x9F, 0xB8, 0xAA), FONT_B)],
      "align": PP_ALIGN.CENTER}])

# ===========================================================================
# Slide 2 - Agenda
# ===========================================================================
s = new_slide()
header(s, "Presentation Outline", kicker="What we will cover today", page=2)
agenda = [
    ("01", "About the System", "Purpose, users, roles & status lifecycle"),
    ("02", "Student Portal", "Create ID, existing-student search, lost ID"),
    ("03", "Admin Dashboard", "Records, analytics, status, printing"),
    ("04", "Photos & Data", "Photo processing, library, bulk import"),
    ("05", "Design & Templates", "Exact-copy templates and versioning"),
    ("06", "Technical Architecture", "Frontend, backend, database, API, security"),
    ("07", "Installation & QA", "XAMPP setup, test suites, go-live"),
]
for i, (num, t, d) in enumerate(agenda):
    col, row = i % 2, i // 2
    x = Inches(0.7) + col * Inches(6.1)
    y = Inches(1.75) + row * Inches(1.32)
    rect(s, x, y, Inches(5.85), Inches(1.12), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x + Inches(0.22), y + Inches(0.19), Inches(0.75), Inches(0.75), GREEN,
         shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.22), y + Inches(0.28), Inches(0.75), Inches(0.6),
        [{"runs": [(num, 20, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(1.18), y + Inches(0.18), Inches(4.5), Inches(0.45),
        [{"runs": [(t, 16, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(1.18), y + Inches(0.66), Inches(4.55), Inches(0.45),
        [{"runs": [(d, 10.5, False, GRAY, FONT_B)]}])
footer(s)

# ===========================================================================
# Slide 3 - Section 01: About the System
# ===========================================================================
s = section_slide(1, "About the System")
footer(s)
txt(s, Inches(1.2), Inches(5.0), Inches(11), Inches(1.2),
    [{"runs": [("One platform that turns student data into a finished, printable school ID in minutes.",
                15, False, RGBColor(0xCF, 0xE0, 0xD5), FONT_B)], "ls": 1.2}])
# ===========================================================================
# Slide 4 - What the system does
# ===========================================================================
s = new_slide()
header(s, "What the System Does", kicker="System overview", page=4)
bullets(s, [
    ("ID Creation \u2013", "Generates official Lake Shore Colleges student IDs for every department."),
    ("Three Departments \u2013", "College, Junior High School and Senior High School on one platform."),
    ("Public Portal \u2013", "Students submit their own ID request online with no login required."),
    ("Existing-Student Search \u2013", "A student number search finds an existing record instead of retyping it."),
    ("Admin & Staff Dashboard \u2013", "Create, search, edit, print and manage all ID records centrally."),
    ("Analytics \u2013", "Totals, per-department counts and monthly trends, filterable by date and status."),
    ("Template System \u2013", "Admin uploads the exact 100% design image and places live data zones on it."),
    ("Template Versioning \u2013", "Publish a new design without changing cards already generated."),
    ("Photo Processing & Library \u2013", "Background removal, plus upload, clone, archive and preferred photo."),
    ("Bulk Import \u2013", "Upload a CSV / XLSX / XLS roster, validate a preview, then commit in one transaction."),
    ("Smart ID 51 Ready \u2013", "Exports front & back as high-resolution PNG/JPG in CR80 54\u00d785.6mm portrait."),
    ("Rate-Limited & Audited \u2013", "Public forms are throttled and bot-protected; every staff action is logged."),
], Inches(0.8), Inches(1.8), Inches(7.4), Inches(4.8), size=12.5, gap=7)

# right summary panel
rect(s, Inches(8.4), Inches(1.8), Inches(4.2), Inches(4.6), CREAM,
     shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(8.7), Inches(2.05), Inches(3.6), Inches(4.2), [
    {"runs": [("At a glance", 16, True, GREEN, FONT_H)], "sa": 9},
    {"runs": [("3", 34, True, GOLD, FONT_H)], "sa": 1},
    {"runs": [("supported departments", 11, False, GRAY, FONT_B)], "sa": 11},
    {"runs": [("2", 34, True, GOLD, FONT_H)], "sa": 1},
    {"runs": [("ID sides (front + back)", 11, False, GRAY, FONT_B)], "sa": 11},
    {"runs": [("8", 34, True, GOLD, FONT_H)], "sa": 1},
    {"runs": [("college programmes", 11, False, GRAY, FONT_B)], "sa": 11},
    {"runs": [("5", 34, True, GOLD, FONT_H)], "sa": 1},
    {"runs": [("statuses, created to released", 11, False, GRAY, FONT_B)], "sa": 11},
    {"runs": [("55", 34, True, GOLD, FONT_H)], "sa": 1},
    {"runs": [("API actions  ·  10 tables", 11, False, GRAY, FONT_B)], "sa": 0},
])
footer(s)

# ===========================================================================
# Slide 5 - Users and roles
# ===========================================================================
s = new_slide()
header(s, "Who Uses the System", kicker="Three personas, one secure platform", page=5)
roles = [
    ("Students (Public Portal)", "No login needed", [
        "Submit ID creation requests online",
        "Search a student number to avoid retyping",
        "Report a lost ID and request a reprint",
        "Receive a reference number after submission",
    ], GREEN),
    ("Staff", "School registrar / office personnel", [
        "Create and edit student IDs",
        "Review, mark IDs as done, print and release",
        "Manage lost-ID reprint requests",
        "Use the photo library and bulk import",
    ], MID),
    ("Admin", "Full system control", [
        "Everything staff can do",
        "Manage users & roles (admin / staff)",
        "Design and version ID templates",
        "Read the audit log and print history",
    ], DARK),
]
for i, (name, tag, items, col) in enumerate(roles):
    x = Inches(0.7) + i * Inches(4.15)
    rect(s, x, Inches(1.8), Inches(3.85), Inches(4.9), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x, Inches(1.8), Inches(3.85), Inches(1.0), col, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.22), Inches(1.92), Inches(3.4), Inches(0.8),
        [{"runs": [(name, 14, True, WHITE, FONT_H)]}])
    txt(s, x + Inches(0.22), Inches(2.95), Inches(3.4), Inches(0.35),
        [{"runs": [(tag, 10, False, GRAY, FONT_B)]}])
    bullets(s, items, x + Inches(0.22), Inches(3.4), Inches(3.45), Inches(3.1),
            size=11.5, gap=6, marker_color=GOLD)
footer(s)
# ===========================================================================
# Slide 6 - Section 02: Student Portal
# ===========================================================================
s = section_slide(2, "Student Portal", "Self-service ID creation and lost-ID reporting")
footer(s)

# ===========================================================================
# Slide 7 - Student creates an ID (flow)
# ===========================================================================
s = new_slide()
header(s, "Create My ID \u2013 Student Flow", kicker="Public portal, no login required", page=7)
steps = [
    ("1", "Choose Department", "College, Junior High or Senior High."),
    ("2", "Fill Details", "Name, course/grade/section, ID number, LRN, year."),
    ("3", "Upload Photo", "PNG / JPG / WEBP \u2013 stored server-side."),
    ("4", "Add Back Details", "Home address, emergency contact & phone."),
    ("5", "Review Preview", "Live front + back preview updates as you type."),
    ("6", "Submit", "Saved to the ID Management System with a reference #."),
]
for i, (n, t, d) in enumerate(steps):
    col, row = i % 3, i // 3
    x = Inches(0.7) + col * Inches(4.15)
    y = Inches(1.9) + row * Inches(2.35)
    rect(s, x, y, Inches(3.9), Inches(2.05), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x + Inches(0.2), y + Inches(0.2), Inches(0.72), Inches(0.72), GREEN,
         shape=MSO_SHAPE.OVAL)
    txt(s, x + Inches(0.2), y + Inches(0.28), Inches(0.72), Inches(0.6),
        [{"runs": [(n, 20, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(1.1), y + Inches(0.2), Inches(2.6), Inches(0.5),
        [{"runs": [(t, 14, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(1.1), y + Inches(0.75), Inches(2.65), Inches(1.2),
        [{"runs": [(d, 11, False, GRAY, FONT_B)], "ls": 1.15}])
footer(s)

# ===========================================================================
# Slide 8 - Departments & fields
# ===========================================================================
s = new_slide()
header(s, "Departments and ID Data", kicker="What each department captures", page=8)
# three panels
depts = [
    ("COLLEGE DEPARTMENT", "8 degree programs", [
        "BS Psychology",
        "BS Special Needs Education",
        "BS Technology & Livelihood Education",
        "BS Accountancy",
        "BS Real Estate Management",
        "BS Tourism Management",
        "BS Management Accounting",
        "BS Criminology",
    ]),
    ("JUNIOR HIGH SCHOOL", "Grades 7 \u2013 10", [
        "Grade 7 \u2013 Green band",
        "Grade 8 \u2013 Yellow band",
        "Grade 9 \u2013 Blue band",
        "Grade 10 \u2013 Red band",
        "Section name",
        "School year",
        "LRN + ID number",
    ]),
    ("SENIOR HIGH SCHOOL", "Grades 11 \u2013 12", [
        "Grade 11 \u2013 Purple band",
        "Grade 12 \u2013 Orange band",
        "Section name",
        "School year",
        "LRN + ID number",
    ]),
]
for i, (t, tag, items) in enumerate(depts):
    x = Inches(0.55) + i * Inches(4.25)
    rect(s, x, Inches(1.8), Inches(4.0), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.22), Inches(1.95), Inches(3.6), Inches(0.4),
        [{"runs": [(t, 15, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.22), Inches(2.38), Inches(3.6), Inches(0.35),
        [{"runs": [(tag, 11, False, GOLD, FONT_B)]}])
    bullets(s, items, x + Inches(0.22), Inches(2.85), Inches(3.6), Inches(3.3),
            size=11.5, gap=6, marker_color=GREEN)
footer(s)
# ===========================================================================
# Slide 9 - Lost ID / reprint workflow
# ===========================================================================
s = new_slide()
header(s, "Lost ID & Reprint Request", kicker="Student reports a lost card \u2013 staff handles the reprint", page=9)
flow = [
    ("Student", "Submits the Lost ID form", "Name + department + receipt upload (PNG/JPG/PDF)."),
    ("System", "Creates a request", "Reference number issued; request listed for review."),
    ("Staff", "Reviews request & receipt", "Confirms the details against existing records."),
    ("Staff", "Re-prints the ID", "Status updated; fresh copy handed to the student."),
]
x0 = Inches(0.7)
for i, (who, what, how) in enumerate(flow):
    x = x0 + i * Inches(3.1)
    rect(s, x, Inches(2.0), Inches(2.8), Inches(2.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    chip(s, x + Inches(0.2), Inches(2.2), Inches(2.4), Inches(0.55), who, fill=GREEN if i % 2 == 0 else DARK, size=11)
    txt(s, x + Inches(0.2), Inches(2.95), Inches(2.4), Inches(0.4),
        [{"runs": [(what, 13.5, True, INK, FONT_B)]}])
    txt(s, x + Inches(0.2), Inches(3.45), Inches(2.45), Inches(1.1),
        [{"runs": [(how, 11, False, GRAY, FONT_B)], "ls": 1.15}])
    if i < 3:
        txt(s, x + Inches(2.78), Inches(2.15), Inches(0.5), Inches(0.5),
            [{"runs": [("\u25B6", 20, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
footer(s)

# ===========================================================================
# Slide 10 - Existing student detection (public search)
# ===========================================================================
s = new_slide()
header(s, "Existing Student Detection", kicker="Search first \u2013 so nobody has to type their details twice")
# left: the flow
rect(s, Inches(0.7), Inches(1.8), Inches(6.0), Inches(1.15), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(1.95), Inches(5.6), Inches(0.4),
    [{"runs": [("Student Number:  2026-00125", 14, True, INK, FONT_B),
               ("     \u2192  [ Search ]", 14, True, GREEN, FONT_B)]}])
txt(s, Inches(0.95), Inches(2.45), Inches(5.6), Inches(0.4),
    [{"runs": [("Student Found", 12, True, MID, FONT_B),
               ("  =  the form fills itself   \u00b7   ", 12, False, GRAY, FONT_B),
               ("Student Not Found", 12, True, RGBColor(0xB0, 0x3A, 0x2E), FONT_B),
               ("  =  create it", 12, False, GRAY, FONT_B)]}])
bullets(s, [
    ("No retyping \u2013", "The API returns the one matching record and the form fills automatically."),
    ("Three identifiers \u2013", "Matches on Student Number, Student ID Number or LRN."),
    ("Privacy by default \u2013", "Returns 10 whitelisted fields only \u2013 no photo, address or emergency contact."),
    ("No second row \u2013", "A found record sends its id back; the server re-checks it inside the write lock."),
    ("Form switches \u2013", "\"Submit ID\" becomes \"Report Lost ID\" as soon as a record is found."),
], Inches(0.7), Inches(3.15), Inches(6.2), Inches(3.3), size=12, gap=9)

# right: why it is safe to expose publicly
rect(s, Inches(7.25), Inches(1.8), Inches(5.35), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(7.5), Inches(1.98), Inches(4.85), Inches(0.45),
    [{"runs": [("Why it cannot be used to enumerate students", 13.5, True, GREEN, FONT_H)]}])
bullets(s, [
    "Exact match only \u2013 never LIKE, so numbers cannot be walked one prefix at a time",
    "One row only \u2013 LIMIT 1, so it can only answer about the number submitted",
    "Minimum 4 characters \u2013 below that nothing reaches the database",
    "No wildcards \u2013 % and _ are not in the accepted character set",
    "Rejects, never truncates \u2013 a shortened number could match a different student",
    "Rate limited \u2013 5 per 10 minutes in its own bucket, with honeypot + captcha",
    "Audited \u2013 every successful lookup is written to the audit log",
], Inches(7.5), Inches(2.55), Inches(4.85), Inches(3.6), size=11, gap=8, marker_color=GOLD)
footer(s)

# ===========================================================================
# Slide 10 - Section 03: Admin Dashboard
# ===========================================================================
s = section_slide(3, "Admin Dashboard", "The command center for every ID in the school")
footer(s)

# ===========================================================================
# Slide 11 - Admin dashboard features
# ===========================================================================
s = new_slide()
header(s, "Admin & Staff Dashboard", kicker="Everything the office needs in one place", page=11)
feat = [
    ("Dashboard", "Quick links: create College / JHS / SHS IDs, view saved records."),
    ("ID Editor", "Full form driven by the selected template; photo + signatory auto-applied."),
    ("Saved IDs", "Searchable table of all IDs with status, date and one-click actions."),
    ("Lost Requests", "Inbox of lost-ID reprint requests with receipt preview."),
    ("Printing", "Smart ID 51 dual-side print and PNG/JPG export per side."),
    ("Templates", "Admin-only: upload design images and assign data zones."),
    ("User Management", "Admin-only: add / edit / disable staff and admin accounts."),
    ("Signatories", "Manage authorized signatory names & signature images."),
]
for i, (t, d) in enumerate(feat):
    col, row = i % 2, i // 2
    x = Inches(0.7) + col * Inches(6.1)
    y = Inches(1.72) + row * Inches(1.36)
    rect(s, x, y, Inches(5.85), Inches(1.16), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x, y, Inches(0.14), Inches(1.16), GREEN)
    txt(s, x + Inches(0.3), y + Inches(0.14), Inches(5.35), Inches(0.42),
        [{"runs": [(t, 13.5, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.3), y + Inches(0.56), Inches(5.35), Inches(0.55),
        [{"runs": [(d, 11, False, GRAY, FONT_B)], "ls": 1.12}])
footer(s)

# ===========================================================================
# Dashboard analytics
# ===========================================================================
s = new_slide()
header(s, "Dashboard Analytics", kicker="Totals, trends and filters \u2013 one action=dashboardStats")
# four KPI tiles
kpis = [
    ("Total records", "Every ID ever created", GREEN),
    ("By department", "College / JHS / SHS split", MID),
    ("Monthly trend", "Generated vs. printed", RGBColor(0x1D, 0x5C, 0x97)),
    ("Lost & reprints", "Requests and reprint jobs", RGBColor(0xB0, 0x3A, 0x2E)),
]
for i, (t, d, col) in enumerate(kpis):
    x = Inches(0.7) + i * Inches(3.05)
    rect(s, x, Inches(1.8), Inches(2.85), Inches(1.5), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x, Inches(1.8), Inches(2.85), Inches(0.16), col)
    txt(s, x + Inches(0.22), Inches(2.1), Inches(2.45), Inches(0.5),
        [{"runs": [(t, 14, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.22), Inches(2.6), Inches(2.45), Inches(0.6),
        [{"runs": [(d, 10.5, False, GRAY, FONT_B)], "ls": 1.15}])
# filters + what the endpoint returns
rect(s, Inches(0.7), Inches(3.55), Inches(6.0), Inches(2.9), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(3.72), Inches(5.5), Inches(0.45),
    [{"runs": [("Every chart is filterable", 14, True, GREEN, FONT_H)]}])
bullets(s, [
    ("Date range \u2013", "date_from and date_to narrow every figure at once."),
    ("Department \u2013", "College, Junior High or Senior High."),
    ("Status \u2013", "created, done, edited, printed or released."),
    ("Totals reconcile \u2013", "Per-department and monthly figures are verified against the database."),
], Inches(0.95), Inches(4.25), Inches(5.5), Inches(2.0), size=11.5, gap=8)
# right: sample response shape
rect(s, Inches(7.0), Inches(3.55), Inches(5.6), Inches(2.9), DARK, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(7.25), Inches(3.7), Inches(5.1), Inches(0.4),
    [{"runs": [("What the API returns", 13, True, GOLD, FONT_H)]}])
txt(s, Inches(7.25), Inches(4.2), Inches(5.1), Inches(2.1), [
    {"runs": [("{ \"summary\":   { total, printed, released }", 11, False, WHITE, "Consolas")], "sa": 2},
    {"runs": [("  \"by_type\":   { COLLEGE, JUNIOR_HIGH, ... }", 11, False, WHITE, "Consolas")], "sa": 2},
    {"runs": [("  \"monthly\":   { generated[], printed[] }", 11, False, WHITE, "Consolas")], "sa": 2},
    {"runs": [("  \"lost_ids\":  n,   \"reprints\":  n", 11, False, WHITE, "Consolas")], "sa": 2},
    {"runs": [("  \"filters\":   { date_from, date_to,", 11, False, WHITE, "Consolas")], "sa": 2},
    {"runs": [("                  id_type, status } }", 11, False, WHITE, "Consolas")], "sa": 0},
])
footer(s)
# ===========================================================================
# Slide 12 - ID Status lifecycle
# ===========================================================================
s = new_slide()
header(s, "ID Status Lifecycle", kicker="Every card tracks its journey from request to print", page=12)
statuses = [
    (GREEN, "Created", "New ID request from the portal or created by staff."),
    (RGBColor(0x1D, 0x5C, 0x97), "Done", "Final details confirmed and approved."),
    (RGBColor(0x8A, 0x6D, 0x00), "Edited", "Card was updated after approval and needs review."),
    (RGBColor(0x85, 0x00, 0x74), "Printed", "Sent to the printer; print count & date stored."),
    (DARK, "Released", "Handed to the student. Terminal \u2013 no further changes."),
]
x = Inches(0.55)
lane_y = Inches(3.0)
for i, (colr, name, desc) in enumerate(statuses):
    box_x = x + i * Inches(2.52)
    rect(s, box_x, lane_y - Inches(0.32), Inches(2.15), Inches(0.64), colr,
         shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, box_x, lane_y - Inches(0.26), Inches(2.15), Inches(0.5),
        [{"runs": [(name, 14.5, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, box_x + Inches(0.12), lane_y + Inches(0.5), Inches(2.2), Inches(1.3),
        [{"runs": [(desc, 10, False, GRAY, FONT_B)], "ls": 1.15}])
    if i < 4:
        txt(s, box_x + Inches(2.14), lane_y - Inches(0.25), Inches(0.42), Inches(0.5),
            [{"runs": [("\u25B6", 15, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
# legend box: automatic status additions
rect(s, Inches(0.7), Inches(5.15), Inches(11.9), Inches(1.3), CREAM,
     shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(5.3), Inches(11.4), Inches(1.0), [
    {"runs": [("How statuses are set automatically", 13, True, GREEN, FONT_H)], "sa": 6},
    {"runs": [("Student submission \u2192 Created.  Staff \u201cMark as Done\u201d \u2192 Done.  Editing a Done card \u2192 Edited.  ", 11, False, GRAY, FONT_B),
              ("Print \u2192 Printed (counter + timestamp + one history row).  Release \u2192 Released \u2014 final.", 11, True, GRAY, FONT_B)], "sa": 4},
    {"runs": [("A released ID can no longer be re-statused, re-released or re-printed; the server refuses with HTTP 409 and changes nothing.",
               10.5, False, MID, FONT_B)], "sa": 0},
])
footer(s)

# ===========================================================================
# Slide 13 - ID preview & Smart ID 51 export
# ===========================================================================
s = new_slide()
header(s, "Preview, Export & Print", kicker="Smart ID 51 / CR80 workflow", page=13)
left_items = [
    ("Live Preview", "Front + back cards update in real time as fields are typed."),
    ("Exact Proportion", "Portrait 642\u00d71013 px, matching CR80 54\u00d785.6 mm."),
    ("High-Resolution Render", "html2canvas generates a 3\u00d7 crisp render of each side."),
    ("Export Formats", "PNG and JPG export buttons for front and back separately."),
    ("Direct Print", "One job \u2013 page 1 front (colour), page 2 back (B&W), auto duplex."),
]
bullets(s, left_items, Inches(0.8), Inches(1.8), Inches(6.9), Inches(4.6), size=13, gap=11,
        marker_color=GOLD)
# right panel: card mockup
rect(s, Inches(8.0), Inches(1.9), Inches(4.5), Inches(4.5), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(9.1), Inches(2.4), Inches(2.2), Inches(3.5), DARK, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(9.1), Inches(2.6), Inches(2.2), Inches(0.5),
    [{"runs": [("LSC", 14, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
rect(s, Inches(9.55), Inches(3.2), Inches(1.3), Inches(1.0), GRAY, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(9.2), Inches(4.5), Inches(2.0), Inches(0.55), GREEN, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(9.2), Inches(5.2), Inches(2.0), Inches(0.3), MID, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(9.1), Inches(5.85), Inches(2.2), Inches(0.6),
    [{"runs": [("Front + Back", 11, False, GRAY, FONT_B)], "align": PP_ALIGN.CENTER}])
footer(s)

# ===========================================================================
# Section 05 - Photos & Data
# ===========================================================================
s = section_slide(4, "Photos & Data", "Photo processing, the photo library, and bulk student import")
footer(s)

# ===========================================================================
# Photo processing & library
# ===========================================================================
s = new_slide()
header(s, "Photo Processing & Library", kicker="Clean cut-outs, reusable photos, per-student history")
rect(s, Inches(0.7), Inches(1.8), Inches(6.0), Inches(4.55), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(1.98), Inches(5.5), Inches(0.45),
    [{"runs": [("Photo processing", 15, True, GREEN, FONT_H)]}])
txt(s, Inches(0.95), Inches(2.42), Inches(5.5), Inches(0.4),
    [{"runs": [("Removes the background to produce a clean cut-out.", 10.5, False, GRAY, FONT_B)]}])
rect(s, Inches(1.0), Inches(2.95), Inches(1.75), Inches(2.35), WHITE, line=LINEC, lw=1)
rect(s, Inches(1.3), Inches(3.25), Inches(1.15), Inches(1.45), RGBColor(0xD9, 0xDE, 0xDB))
txt(s, Inches(1.0), Inches(5.35), Inches(1.75), Inches(0.35),
    [{"runs": [("Original", 10, True, GRAY, FONT_B)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(2.78), Inches(3.85), Inches(0.5), Inches(0.5),
    [{"runs": [("\u2192", 20, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
rect(s, Inches(3.4), Inches(2.95), Inches(1.75), Inches(2.35), WHITE, line=LINEC, lw=1)
rect(s, Inches(3.7), Inches(3.25), Inches(1.15), Inches(1.45), GREEN)
txt(s, Inches(3.4), Inches(5.35), Inches(1.75), Inches(0.35),
    [{"runs": [("Processed", 10, True, GREEN, FONT_B)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(1.0), Inches(5.72), Inches(4.1), Inches(0.5),
    [{"runs": [("Background: transparent, white or a custom colour.", 10, False, GRAY, FONT_B)]}])
rect(s, Inches(5.05), Inches(2.9), Inches(1.5), Inches(2.45), WHITE, line=LINEC, lw=1)
txt(s, Inches(5.18), Inches(3.05), Inches(1.25), Inches(2.2), [
    {"runs": [("RULES", 10, True, GOLD, FONT_H)], "sa": 5},
    {"runs": [("PNG / JPG / WEBP", 9, False, GRAY, FONT_B)], "sa": 4},
    {"runs": [("max 10 MB", 9, False, GRAY, FONT_B)], "sa": 4},
    {"runs": [("min 96\u00d796 px", 9, False, GRAY, FONT_B)], "sa": 4},
    {"runs": [("original always kept", 9, False, GRAY, FONT_B)], "sa": 0},
])
rect(s, Inches(7.0), Inches(1.8), Inches(5.6), Inches(4.55), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(7.25), Inches(1.98), Inches(5.1), Inches(0.45),
    [{"runs": [("Photo Library \u2013 one photo, many uses", 15, True, GREEN, FONT_H)]}])
bullets(s, [
    ("Upload \u2013", "Add a photo against any student record."),
    ("Search & browse \u2013", "Reuse an existing photo instead of asking the student again."),
    ("Clone \u2013", "Copy a photo to another student record."),
    ("Set preferred \u2013", "Choose which photo appears on the card."),
    ("Replace \u2013", "Swap the image while keeping the same record and history."),
    ("Archive / restore \u2013", "Hide a photo without losing it (soft delete)."),
    ("Delete \u2013", "Remove permanently when it is no longer needed."),
], Inches(7.25), Inches(2.55), Inches(5.1), Inches(3.6), size=11.5, gap=8, marker_color=GOLD)
footer(s)

# ===========================================================================
# Bulk student import
# ===========================================================================
s = new_slide()
header(s, "Bulk Student Import", kicker="Upload a roster once instead of creating records by hand")
flow = [
    ("1", "Download", "Blank CSV template with the correct columns."),
    ("2", "Upload", "CSV, XLSX or XLS \u2013 up to 5,000 rows / 10 MB."),
    ("3", "Validate", "Every row checked; the department is auto-detected."),
    ("4", "Preview", "Valid and invalid rows listed, each with a reason."),
    ("5", "Commit", "One transaction \u2013 all or nothing."),
    ("6", "Generate", "IDs are produced through the normal print flow."),
]
for i, (n, t, d) in enumerate(flow):
    col, row = i % 3, i // 3
    x = Inches(0.7) + col * Inches(4.15)
    y = Inches(1.85) + row * Inches(1.75)
    rect(s, x, y, Inches(3.9), Inches(1.5), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x + Inches(0.2), y + Inches(0.2), Inches(0.6), Inches(0.6), GREEN, shape=MSO_SHAPE.OVAL)
    txt(s, x + Inches(0.2), y + Inches(0.26), Inches(0.6), Inches(0.5),
        [{"runs": [(n, 17, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(0.95), y + Inches(0.2), Inches(2.8), Inches(0.45),
        [{"runs": [(t, 14, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.95), y + Inches(0.68), Inches(2.8), Inches(0.75),
        [{"runs": [(d, 10.5, False, GRAY, FONT_B)], "ls": 1.15}])
rect(s, Inches(0.7), Inches(5.45), Inches(11.9), Inches(1.05), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(5.58), Inches(11.4), Inches(0.85), [
    {"runs": [("Why it is safe", 12.5, True, GREEN, FONT_H)], "sa": 4},
    {"runs": [("The same department rules as the manual form are enforced, duplicates are found both inside the file and against the database, and the commit holds the lsc_card_write lock in a single transaction \u2013 so a failed import leaves nothing behind and never imports a student twice.",
               10.5, False, GRAY, FONT_B)], "sa": 0, "ls": 1.15},
])
footer(s)
# ===========================================================================
# Slide 14 - Section 04: Design & Templates
# ===========================================================================
s = section_slide(5, "Design & Templates", "ID design becomes a reusable, exact-copy template")
footer(s)

# ===========================================================================
# Slide 15 - Template designer
# ===========================================================================
s = new_slide()
header(s, "ID Template Designer", kicker="Upload your design once \u2013 data zones stay live forever", page=15)
# left: bullets
bullets(s, [
    ("100% Exact Copy", "Upload the front (and optional back) design as PNG/JPG \u2013 the ID uses your exact artwork."),
    ("Data Zones", "Drag & resize boxes on the design where name, photo, course, number etc. will render."),
    ("Per-Department Active Templates", "One active template per department, auto-matched when creating an ID."),
    ("Built-in Original LSC Design", "3 protected system templates (College / JHS / SHS) \u2013 cannot be deleted or renamed."),
    ("Fully Editable", "Change fonts, sizes, colours, transforms (uppercase), alignment and image fit."),
], Inches(0.8), Inches(1.85), Inches(7.3), Inches(4.7), size=13, gap=11, marker_color=GREEN)

# right: mini mock of a zone-decorated card
rect(s, Inches(8.4), Inches(1.85), Inches(4.15), Inches(4.7), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(8.9), Inches(2.2), Inches(3.1), Inches(4.0), WHITE, shape=MSO_SHAPE.ROUNDED_RECTANGLE,
     line=LINEC, lw=1)
rect(s, Inches(9.15), Inches(2.45), Inches(1.05), Inches(1.3), GRAY, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(10.45), Inches(2.45), Inches(1.3), Inches(0.38), GREEN, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
rect(s, Inches(10.45), Inches(3.0), Inches(1.3), Inches(0.2), MID, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(9.15), Inches(4.0), Inches(2.7), Inches(0.4),
    [{"runs": [("Drag & resize zones", 10, False, GRAY, FONT_B), ("\u219E\u219F", 12, True, GOLD, FONT_B)]}])
txt(s, Inches(9.15), Inches(4.45), Inches(2.6), Inches(1.4),
    [{"runs": [("Each zone = one data\nfield, positioned over\nthe exact design.", 10.5, False, GRAY, FONT_B)], "ls": 1.3}])
footer(s)

# ===========================================================================
# Slide 16 - ID card anatomy (front & back)
# ===========================================================================
s = new_slide()
header(s, "The Official LSC ID Card", kicker="Front and back anatomy", page=16)
front = [
    ("School logo & header", "Institution name and header design."),
    ("ID photo frame", "Student photo, cropped & fitted to the template."),
    ("Name band", "Full name in uppercase (Oswald font), colour-coded by grade."),
    ("Course / grade line", "College program or JHS/SHS grade level."),
    ("Student number / LRN", "ID number area at the bottom front."),
]
back = [
    ("Address block", "Home address line 1 and line 2."),
    ("Emergency contact", "\u201cIn case of emergency, please notify\u201d + name & phone."),
    ("Terms & conditions", "Non-transferable, wear conspicuously, replacement charged."),
    ("School details", "Institution name, address, contact numbers, e-mail."),
    ("Authorized signatory", "Signature + printed name of the department signatory."),
]
for panel_i, (items, label, x) in enumerate([(front, "FRONT", Inches(0.7)), (back, "BACK", Inches(6.8))]):
    rect(s, x, Inches(1.8), Inches(5.7), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    chip(s, x + Inches(0.22), Inches(2.0), Inches(1.4), Inches(0.5), label, fill=DARK, size=11)
    bullets(s, items, x + Inches(0.22), Inches(2.75), Inches(5.3), Inches(3.4), size=12, gap=9,
            marker_color=GOLD)
footer(s)

# ===========================================================================
# Template versioning
# ===========================================================================
s = new_slide()
header(s, "Template Versioning", kicker="Change the design mid-year without changing cards already printed")
bullets(s, [
    ("Versioned families \u2013", "Saving over a template creates version n+1; earlier versions are never overwritten."),
    ("Cards pin their version \u2013", "Each ID stores the template id and version it was generated with."),
    ("Safe redesign \u2013", "Activating a new design never alters a card that was already produced."),
    ("Faithful reprints \u2013", "A reprint always reproduces the original design, years later."),
    ("One active per department \u2013", "Enforced by a UNIQUE key, not only by the interface."),
    ("Protected originals \u2013", "The three seeded Original LSC Design templates cannot be deleted or renamed."),
], Inches(0.8), Inches(1.85), Inches(6.9), Inches(4.6), size=12.5, gap=10, marker_color=GREEN)
# right: version ladder
rect(s, Inches(8.0), Inches(1.85), Inches(4.6), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(8.25), Inches(2.0), Inches(4.1), Inches(0.4),
    [{"runs": [("A template family", 14, True, GREEN, FONT_H)]}])
versions = [
    ("v1", "Original LSC Design", "cards 1\u2013500 still print v1", RGBColor(0xC8, 0xD2, 0xCC)),
    ("v2", "Redesigned band", "cards 501\u20131200 print v2", RGBColor(0xA8, 0xBA, 0xAF)),
    ("v3", "New photo frame", "new cards print v3", GREEN),
]
for i, (v, name, note, col) in enumerate(versions):
    y = Inches(2.55) + i * Inches(1.22)
    rect(s, Inches(8.25), y, Inches(4.1), Inches(1.0), WHITE, line=LINEC, lw=1)
    rect(s, Inches(8.25), y, Inches(0.75), Inches(1.0), col)
    txt(s, Inches(8.25), y + Inches(0.28), Inches(0.75), Inches(0.5),
        [{"runs": [(v, 14, True, WHITE if i == 2 else INK, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, Inches(9.12), y + Inches(0.14), Inches(3.1), Inches(0.4),
        [{"runs": [(name, 12, True, INK, FONT_B)]}])
    txt(s, Inches(9.12), y + Inches(0.53), Inches(3.1), Inches(0.4),
        [{"runs": [(note, 9.5, False, GRAY, FONT_B)]}])
txt(s, Inches(8.25), Inches(6.0), Inches(4.1), Inches(0.4),
    [{"runs": [("Only one version is active per department.", 9.5, False, MID, FONT_B)]}])
footer(s)
# ===========================================================================
# Slide 17 - Section 05: Technical Architecture
# ===========================================================================
s = section_slide(6, "Technical Architecture", "Frontend, backend, database and API \u2013 secured by design")
footer(s)

# ===========================================================================
# Slide 18 - Architecture diagram
# ===========================================================================
s = new_slide()
header(s, "System Architecture", kicker="3-tier web application", page=18)
# tiers
tiers = [
    ("CLIENT (Browser)", "\u2022 React + Vite frontend\n\u2022 Student portal & admin dashboard\n\u2022 Live preview via html2canvas\n\u2022 PNG / JPG export", GREEN),
    ("BACKEND (PHP / XAMPP)", "\u2022 REST API \u2013 backend/api.php\n\u2022 Login, sessions & CSRF tokens\n\u2022 Image uploads (MIME validated)\n\u2022 Card & template logic", MID),
    ("DATABASE (MySQL/MariaDB)", "\u2022 lake_shore_id_system\n\u2022 users, id_cards, id_templates\n\u2022 signatories, system_settings\n\u2022 password_resets", DARK),
]
box_w, box_h = Inches(3.6), Inches(3.4)
for i, (tier, desc, colr) in enumerate(tiers):
    x = Inches(0.7) + i * Inches(4.15)
    y = Inches(2.3)
    rect(s, x, y, box_w, box_h, colr, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    paras = [{"runs": [(tier, 15, True, WHITE, FONT_H)], "sa": 12}]
    for li in desc.split("\n"):
        paras.append({"runs": [(li, 11.5, False, RGBColor(0xE7, 0xF0, 0xEA), FONT_B)], "sa": 7, "ls": 1.1})
    txt(s, x + Inches(0.25), y + Inches(0.25), box_w - Inches(0.5), box_h - Inches(0.5), paras)
    if i < 2:
        txt(s, x + box_w + Inches(0.05), y + Inches(1.4), Inches(0.5), Inches(0.6),
            [{"runs": [("\u25B6", 18, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
footer(s)

# ===========================================================================
# Slide 19 - Technology stack
# ===========================================================================
s = new_slide()
header(s, "Technology Stack", kicker="Built on open, tried-and-tested tooling", page=19)
stack = [
    ("Frontend", ["React (JSX)", "Vite (build + dev server)", "html2canvas (image render)", "CSS (custom, brand theming)", "Montserrat / Oswald typefaces"]),
    ("Backend", ["PHP (XAMPP / Apache)", "REST API (api.php)", "Native sessions + cookies", "password_hash / password_verify", "CSRF token verification"]),
    ("Data & Infra", ["MySQL / MariaDB", "JSON field storage (templates)", "PNG / JPG / WEBP / PDF uploads", "XAMPP stack", "Localhost deployment"]),
]
for i, (t, items) in enumerate(stack):
    x = Inches(0.7) + i * Inches(4.15)
    rect(s, x, Inches(1.8), Inches(3.9), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.25), Inches(2.0), Inches(3.4), Inches(0.5),
        [{"runs": [(t, 16, True, GREEN, FONT_H)]}])
    bullets(s, items, x + Inches(0.25), Inches(2.7), Inches(3.45), Inches(3.4), size=12.5, gap=10,
            marker_color=GOLD)
footer(s)
# ===========================================================================
# Slide 20 - Database schema
# ===========================================================================
s = new_slide()
header(s, "Database Design", kicker="MySQL / MariaDB \u2013 lake_shore_id_system", page=20)
db_rows = [
    ("Table", "Purpose", "Key fields"),
    ("id_cards", "All student ID records", "student_name, id_type, course, grade_level, photo, status, template_version"),
    ("users", "Staff & admin accounts", "username, password (bcrypt), role (admin/staff), is_active"),
    ("id_templates", "Versioned ID designs", "name, version, parent_id, fields_json, is_active, is_system"),
    ("student_photos", "Every photo variant", "student_id, type, source, file_path, background_info, is_active"),
    ("audit_logs", "Append-only activity trail", "action, entity_type, old_value, new_value, ip_address, user_agent"),
    ("print_history", "Every print job", "card_id, user_id, print_type (original/reprint), reason, printed_at"),
    ("signatories", "Authorized signatories", "full_name, position_title, signature_path, is_active"),
    ("password_resets", "Secure password recovery", "token_hash, expires_at (30-min), used flag"),
    ("rate_limit_*", "Public portal throttling", "bucket, ip_address, window_start, hits, expires_at"),
    ("system_settings", "School contact details", "institution_name, address, mobile, telephone, e-mail"),
]
table(s, db_rows, Inches(0.7), Inches(1.72), Inches(11.9), Inches(4.9),
      col_w=[Inches(2.1), Inches(3.5), Inches(6.3)], font_size=10.5, row_h=0.4, head_h=0.42)
txt(s, Inches(0.7), Inches(6.65), Inches(11.9), Inches(0.4),
    [{"runs": [("10 tables, InnoDB, utf8mb4. Audit rows are never updated or deleted, so the trail cannot be rewritten.",
                11, False, GRAY, FONT_B)]}])
footer(s)

# ===========================================================================
# Slide 21 - API endpoints
# ===========================================================================
s = new_slide()
header(s, "REST API \u2013 Endpoint Map", kicker="Single backend entrypoint: backend/api.php?action=...", page=21)
api_rows = [
    ("Action", "Method", "Purpose"),
    ("login / logout / me", "POST/POST/GET", "Session-based authentication"),
    ("forgotPassword / resetPassword", "POST", "Password recovery (token never returned)"),
    ("studentSaveCard / studentUploadPhoto", "POST", "Public portal submission + photo"),
    ("studentLookup", "POST", "Existing-student search (rate limited)"),
    ("lostIdRequest", "POST", "Lost-ID reprint request with receipt"),
    ("cards / card / saveCard", "GET/GET/POST", "List, view, create & edit records"),
    ("setCardStatus / printCard / bulkPrint", "POST", "Lifecycle, single print, batch print"),
    ("releaseCard", "POST", "Release a printed ID (terminal state)"),
    ("dashboardStats", "GET", "Totals, trends, lost IDs, reprints"),
    ("quickCreateStudent", "POST", "Create the record, then add photos"),
    ("templates / saveTemplate / setActiveTemplate", "GET/POST", "Template design & versioning (admin)"),
    ("photoLibrary* (11 actions)", "GET/POST", "Upload, clone, replace, archive, preferred"),
    ("importTemplate / importUpload / importCommit", "GET/POST", "Bulk student import"),
    ("users / saveUser / deleteUser", "GET/POST", "User management (admin only)"),
    ("auditLogs / printHistory", "GET", "Read-only history (admin only)"),
]
table(s, api_rows, Inches(0.7), Inches(1.7), Inches(11.9), Inches(5.0),
      col_w=[Inches(4.6), Inches(2.2), Inches(5.1)], font_size=10.5, row_h=0.32, head_h=0.4)
footer(s)

# ===========================================================================
# Slide 22 - Security
# ===========================================================================
s = new_slide()
header(s, "Security by Design", kicker="Protecting student data at every layer", page=22)
sec = [
    ("Session Authentication", "Login backed by PHP sessions; BINARY username match prevents case tricks."),
    ("Strong Password Hashing", "password_hash() / password_verify() with bcrypt."),
    ("CSRF Protection", "Every state-changing request requires a per-session CSRF token."),
    ("Validated Uploads", "MIME type + file extension whitelist (PNG/JPG/WEBP/PDF); random file names."),
    ("Duplicate Prevention", "API blocks a student with the same name + student number / ID / LRN."),
    ("Locked-down CORS", "Only allowlisted origins may call the API with credentials."),
    ("Role-based Access", "Admin-only actions (users, templates) enforced server-side."),
    ("Protected System Templates", "The Original LSC Design cannot be deleted or renamed."),
    ("Revocable Accounts", "Admins can disable users; deactivated accounts cannot log in."),
    ("Secure Reset Flow", "Hashed 30-minute reset tokens with single-use flag."),
    ("Rate Limiting", "Public forms throttled 5/10min per IP, with honeypot and self-hosted captcha."),
    ("Append-Only Audit", "Every staff action logged with user, IP and before/after values."),
    ("Privacy by Whitelist", "The public search returns only the 10 fields the form needs."),
]
# two-column list
for i, (t, d) in enumerate(sec):
    col, row = i % 2, i // 2
    x = Inches(0.7) + col * Inches(6.1)
    y = Inches(1.62) + row * Inches(0.75)
    txt(s, x, y, Inches(5.9), Inches(0.35),
        [{"runs": [("\u2713  ", 11.5, True, GREEN, FONT_B), (t, 12, True, INK, FONT_B)]}])
    txt(s, x + Inches(0.26), y + Inches(0.27), Inches(5.65), Inches(0.48),
        [{"runs": [(d, 10, False, GRAY, FONT_B)], "ls": 1.08}])
footer(s)

# ===========================================================================
# Rate limiting
# ===========================================================================
s = new_slide()
header(s, "Public Portal Rate Limiting", kicker="The portal stays open \u2013 but it cannot be flooded or automated")
pol = [
    ("5", "requests allowed", "per 10 minutes per IP"),
    ("429", "status returned", "with Retry-After header"),
    ("per", "endpoint buckets", "a search never eats quota"),
]
for i, (big, mid, small) in enumerate(pol):
    x = Inches(0.7) + i * Inches(2.85)
    rect(s, x, Inches(1.8), Inches(2.65), Inches(1.45), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.2), Inches(1.92), Inches(2.25), Inches(0.55),
        [{"runs": [(big, 26, True, GOLD, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(0.2), Inches(2.52), Inches(2.25), Inches(0.35),
        [{"runs": [(mid, 10, True, GREEN, FONT_B)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(0.2), Inches(2.84), Inches(2.25), Inches(0.35),
        [{"runs": [(small, 9.5, False, GRAY, FONT_B)], "align": PP_ALIGN.CENTER}])
bullets(s, [
    ("Server-side only \u2013", "The limit lives in the API; the form only mirrors the message."),
    ("Refused before any work \u2013", "Rejected before validation, upload or database write."),
    ("Race-proof counters \u2013", "One atomic upsert, so parallel hits cannot slip past."),
    ("Trusted proxies only \u2013", "X-Forwarded-For honoured only from a listed proxy; IPv6 bucketed per /64."),
    ("Fails open \u2013", "A database hiccup logs a warning but never takes the portal offline."),
], Inches(9.4), Inches(1.85), Inches(3.25), Inches(4.6), size=10.5, gap=9, marker_color=GOLD)
rect(s, Inches(0.7), Inches(3.45), Inches(8.4), Inches(3.0), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(3.6), Inches(7.9), Inches(0.45),
    [{"runs": [("Staying usable on a shared network", 14, True, GREEN, FONT_H)]}])
txt(s, Inches(0.95), Inches(4.1), Inches(7.9), Inches(2.2), [
    {"runs": [("A whole computer lab sits behind one public IP, so a hard block would stop real students. Two escapes keep it fair:", 11, False, GRAY, FONT_B)], "sa": 7, "ls": 1.15},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("Captcha \u2013", 11, True, INK, FONT_B),
              (" a blocked client solves a self-hosted SVG challenge once per window to unlock extra attempts. No third party, no API key, no GD extension needed.", 11, False, GRAY, FONT_B)], "sa": 6, "ls": 1.15},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("Honeypot \u2013", 11, True, INK, FONT_B),
              (" public forms carry a hidden field; a filled value marks a bot and is rejected without consuming the human's quota.", 11, False, GRAY, FONT_B)], "sa": 0, "ls": 1.15},
])
footer(s)

# ===========================================================================
# Audit log
# ===========================================================================
s = new_slide()
header(s, "Audit Log & Traceability", kicker="Append-only: who did what, when, from where \u2013 and it cannot be rewritten")
bullets(s, [
    ("Every important action \u2013", "Create, edit, status change, print, release, delete, template, signatory, user, login, logout."),
    ("Field-level diffs \u2013", "An edit reads as \u201cCourse: BS Psychology \u2192 BS Accountancy\u201d, not just a timestamp."),
    ("Who and where \u2013", "The user, the client IP and the user agent are captured automatically."),
    ("Public actions too \u2013", "Submissions, lookups, rate-limited requests and rejected bots are recorded with a null user."),
    ("Read-only by design \u2013", "No API route can update or delete an audit row \u2013 that is what makes it trustworthy."),
    ("Admin only \u2013", "Anonymous get 401, staff get 403; the endpoint is paginated and filterable."),
], Inches(0.8), Inches(1.85), Inches(6.9), Inches(4.6), size=12, gap=10, marker_color=GREEN)
rect(s, Inches(8.0), Inches(1.85), Inches(4.6), Inches(4.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(8.25), Inches(2.0), Inches(4.1), Inches(0.4),
    [{"runs": [("A recorded edit", 13.5, True, GREEN, FONT_H)]}])
rect(s, Inches(8.25), Inches(2.5), Inches(4.1), Inches(3.7), WHITE, line=LINEC, lw=1)
txt(s, Inches(8.45), Inches(2.66), Inches(3.75), Inches(3.4), [
    {"runs": [("card_updated", 11, True, GREEN, "Consolas")], "sa": 3},
    {"runs": [("id_card #142", 10, False, GRAY, "Consolas")], "sa": 3},
    {"runs": [("Ma. Bautista \u00b7 10:42", 10, False, GRAY, "Consolas")], "sa": 9},
    {"runs": [("FIELD          FROM \u2192 TO", 8.5, True, GOLD, "Consolas")], "sa": 4},
    {"runs": [("Course         BS Psychology", 9, False, INK, "Consolas")], "sa": 1},
    {"runs": [("               \u2192 BS Accountancy", 9, False, MID, "Consolas")], "sa": 6},
    {"runs": [("Section        (empty)", 9, False, INK, "Consolas")], "sa": 1},
    {"runs": [("               \u2192 BS-Acc-2A", 9, False, MID, "Consolas")], "sa": 6},
    {"runs": [("Status         done \u2192 edited", 9, False, INK, "Consolas")], "sa": 9},
    {"runs": [("192.168.1.24 \u00b7 Chrome", 8.5, False, GRAY, "Consolas")], "sa": 0},
])
footer(s)
# ===========================================================================
# Slide 23 - Section 06: Installation
# ===========================================================================
s = section_slide(7, "Installation & QA", "Set up on XAMPP, run the test suites, go live")
footer(s)

# ===========================================================================
# Slide 24 - Installation steps
# ===========================================================================
s = new_slide()
header(s, "Installation Steps (XAMPP)", kicker="Six steps to a running system", page=24)
steps = [
    ("1", "Extract project", "Copy the folder to C:\\xampp\\htdocs\\lake-shore-id-editor."),
    ("2", "Start servers", "Turn on Apache and MySQL in the XAMPP control panel."),
    ("3", "Import database", "Open phpMyAdmin and import database/id_system.sql."),
    ("4", "Configure DB", "Check backend/config.php \u2013 host, user, password (root / empty)."),
    ("5", "Test API", "Visit backend/api.php?action=cards \u2013 expect a JSON response."),
    ("6", "Run frontend", "cd frontend \u2192 npm install \u2192 npm run dev \u2192 localhost:5173."),
]
for i, (n, t, d) in enumerate(steps):
    col, row = i % 3, i // 3
    x = Inches(0.7) + col * Inches(4.15)
    y = Inches(1.9 + row * 2.35)
    rect(s, x, y, Inches(3.9), Inches(2.05), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x + Inches(0.2), y + Inches(0.2), Inches(0.72), Inches(0.72), GREEN, shape=MSO_SHAPE.OVAL)
    txt(s, x + Inches(0.2), y + Inches(0.28), Inches(0.72), Inches(0.6),
        [{"runs": [(n, 20, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(1.08), y + Inches(0.2), Inches(2.7), Inches(0.5),
        [{"runs": [(t, 13.5, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(1.08), y + Inches(0.72), Inches(2.7), Inches(1.25),
        [{"runs": [(d, 10.5, False, GRAY, FONT_B)], "ls": 1.15}])
footer(s)

# ===========================================================================
# Slide 25 - Access & credentials
# ===========================================================================
s = new_slide()
header(s, "Access Points & Accounts", kicker="Where to go and who signs in", page=25)
left = [
    ("Student portal (public)", "http://localhost:5173", "Create ID / lost ID \u2013 no login."),
    ("Staff & admin login", "http://localhost:5173", "Sign in with a staff or admin account."),
    ("Backend API", "http://localhost/lake-shore-id-editor/backend/api.php", "JSON responses for every action."),
    ("phpMyAdmin", "http://localhost/phpmyadmin", "Database management & backups."),
]
for i, (t, url, d) in enumerate(left):
    y = Inches(1.9) + i * Inches(1.0)
    txt(s, Inches(0.7), y, Inches(7.3), Inches(0.4),
        [{"runs": [(t, 13, True, INK, FONT_B)]}])
    txt(s, Inches(0.7), y + Inches(0.32), Inches(7.3), Inches(0.35),
        [{"runs": [(url, 11, True, GREEN, FONT_B), ("   \u2013  " + d, 10.5, False, GRAY, FONT_B)]}])

rect(s, Inches(8.25), Inches(1.9), Inches(4.35), Inches(2.7), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(8.5), Inches(2.1), Inches(3.9), Inches(0.5),
    [{"runs": [("Default admin account", 14, True, GREEN, FONT_H)]}])
txt(s, Inches(8.5), Inches(2.7), Inches(3.9), Inches(1.8), [
    {"runs": [("Username  ", 11.5, True, GRAY, FONT_B),
              ("mbautista@lakeshore.edu.ph", 11.5, True, INK, FONT_B)], "sa": 8},
    {"runs": [("Role  ", 11.5, True, GRAY, FONT_B), ("admin", 11.5, True, INK, FONT_B)], "sa": 8},
    {"runs": [("(password set from the seeded hash in id_system.sql \u2013 change on first login)",
               10, False, GRAY, FONT_B)], "sa": 0},
])
rect(s, Inches(8.25), Inches(4.85), Inches(4.35), Inches(1.6), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(8.5), Inches(5.0), Inches(3.9), Inches(1.35), [
    {"runs": [("Important folders", 13, True, GREEN, FONT_H)], "sa": 6},
    {"runs": [("Student photos  \u2192 backend/uploads/students/", 10.5, False, GRAY, FONT_B)], "sa": 4},
    {"runs": [("Signatures  \u2192 backend/uploads/signatures/", 10.5, False, GRAY, FONT_B)], "sa": 4},
    {"runs": [("Logo  \u2192 frontend/public/lsc-logo.png", 10.5, False, GRAY, FONT_B)], "sa": 0},
])
footer(s)

# ===========================================================================
# Quality assurance
# ===========================================================================
s = new_slide()
header(s, "Quality Assurance", kicker="322 automated cases run against a live server, and they clean up after themselves")
# headline score
rect(s, Inches(0.7), Inches(1.8), Inches(3.3), Inches(2.2), GREEN, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.9), Inches(2.0), Inches(2.9), Inches(1.0),
    [{"runs": [("320", 48, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(0.9), Inches(3.05), Inches(2.9), Inches(0.5),
    [{"runs": [("cases passing", 14, True, RGBColor(0xD6, 0xE8, 0xDC), FONT_H)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(0.9), Inches(3.5), Inches(2.9), Inches(0.4),
    [{"runs": [("of 322 total", 11, False, RGBColor(0xBF, 0xD6, 0xC8), FONT_B)], "align": PP_ALIGN.CENTER}])
# suite table
qa_rows = [
    ("Suite", "Cases", "Result"),
    ("qa_api_test", "68", "68 PASS"),
    ("qa_api_test2 (adversarial)", "42", "40 PASS  ·  2 policy"),
    ("qa_audit_test", "26", "26 PASS"),
    ("qa_rate_limit_test", "42", "42 PASS"),
    ("qa_rate_limit_ip_test", "12", "12 PASS"),
    ("qa_release / print / bulk", "57", "all PASS"),
    ("qa_dashboard / lookup / import", "75", "all PASS"),
]
table(s, qa_rows, Inches(4.35), Inches(1.8), Inches(8.25), Inches(2.9),
      col_w=[Inches(4.05), Inches(1.3), Inches(2.9)], font_size=11, row_h=0.31, head_h=0.38)
rect(s, Inches(0.7), Inches(4.25), Inches(11.9), Inches(2.2), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
txt(s, Inches(0.95), Inches(4.4), Inches(11.4), Inches(1.9), [
    {"runs": [("What the suites prove", 13.5, True, GREEN, FONT_H)], "sa": 7},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("6 parallel identical submissions always create exactly one row ", 11, True, INK, FONT_B),
              ("\u2013 a named write lock closes a race that a duplicate check alone could not.", 11, False, GRAY, FONT_B)], "sa": 5, "ls": 1.12},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("The public search cannot be used to enumerate students ", 11, True, INK, FONT_B),
              ("\u2013 exact match, one row, minimum length, no wildcards, rate limited.", 11, False, GRAY, FONT_B)], "sa": 5, "ls": 1.12},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("A released ID is genuinely final ", 11, True, INK, FONT_B),
              ("\u2013 re-release and status changes are refused with 409 and the data stays intact.", 11, False, GRAY, FONT_B)], "sa": 5, "ls": 1.12},
    {"runs": [("\u25AA  ", 11, True, GOLD, FONT_B), ("The 2 remaining items are product decisions, not defects ", 11, True, INK, FONT_B),
              ("\u2013 whether a student number should be unique school-wide or only per name.", 11, False, GRAY, FONT_B)], "sa": 0, "ls": 1.12},
])
footer(s)

# ===========================================================================
# Documentation
# ===========================================================================
s = new_slide()
header(s, "Documentation & Handover", kicker="Everything needed to run, extend and hand the system over")
docs = [
    ("01", "Overview", "Purpose, features, departments, lifecycle"),
    ("02", "Architecture", "Tiers, stack, folders, request flow"),
    ("03", "Database", "All 10 tables, columns, keys, seed data"),
    ("04", "API Reference", "Every action, auth level, status codes"),
    ("05", "Installation", "Fresh install, updates, production build"),
    ("06", "Student Guide", "The public portal, step by step"),
    ("07", "Staff & Admin Guide", "Every screen and the daily routine"),
    ("08", "Security", "Controls, rate limiting, audit, checklist"),
    ("09", "Operations", "Backup, restore, maintenance schedule"),
    ("10", "Testing & QA", "The suites, results, manual checklist"),
    ("11", "Troubleshooting", "Common problems and exact fixes"),
]
for i, (n, t, d) in enumerate(docs):
    col, row = i % 2, i // 2
    x = Inches(0.7) + col * Inches(6.1)
    y = Inches(1.75) + row * Inches(0.86)
    rect(s, x, y, Inches(5.85), Inches(0.72), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    rect(s, x + Inches(0.14), y + Inches(0.13), Inches(0.46), Inches(0.46), GREEN,
         shape=MSO_SHAPE.ROUNDED_RECTANGLE)
    txt(s, x + Inches(0.14), y + Inches(0.19), Inches(0.46), Inches(0.35),
        [{"runs": [(n, 11, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
    txt(s, x + Inches(0.74), y + Inches(0.11), Inches(5.0), Inches(0.32),
        [{"runs": [(t, 12, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.74), y + Inches(0.4), Inches(5.0), Inches(0.3),
        [{"runs": [(d, 9.5, False, GRAY, FONT_B)]}])
txt(s, Inches(0.7), Inches(6.6), Inches(11.9), Inches(0.4),
    [{"runs": [("Stored in the documentation/ folder of the project, alongside the PowerPoint deck in presentation/.",
                11, False, GRAY, FONT_B)]}])
footer(s)
# ===========================================================================
# Slide 26 - Key takeaways
# ===========================================================================
s = new_slide()
header(s, "Key Takeaways", kicker="What this system delivers", page=26)
take = [
    ("Fast & Accurate", "Data is captured once, with server-side duplicate protection \u2013 the ID is generated automatically."),
    ("Any Department", "College, Junior High and Senior High, each with their own fields and grade colour bands."),
    ("Design-Faithful", "The uploaded design becomes an exact-copy template \u2013 and versioning keeps old cards unchanged."),
    ("Print-Ready", "Front and back preview, PNG/JPG export, bulk print and Smart ID 51 dual-side printing."),
    ("Secure & Auditable", "Roles, CSRF, validated uploads, rate limiting and an append-only audit trail."),
    ("Proven", "320 of 322 automated QA cases pass; the 2 remaining are documented product decisions."),
    ("Documented", "Eleven guides covering installation, users, security, operations, QA and troubleshooting."),
    ("Easy to Maintain", "PHP + MySQL + React on XAMPP \u2013 simple to host, update and back up."),
]
rect(s, Inches(0.7), Inches(1.8), Inches(11.9), Inches(4.5), CREAM, shape=MSO_SHAPE.ROUNDED_RECTANGLE)
for i, (t, d) in enumerate(take):
    col, row = i % 2, i // 2
    x = Inches(1.0) + col * Inches(5.8)
    y = Inches(2.05) + row * Inches(1.1)
    txt(s, x, y, Inches(5.4), Inches(0.4),
        [{"runs": [("\u2605  ", 12, True, GOLD, FONT_B), (t, 13, True, GREEN, FONT_H)]}])
    txt(s, x + Inches(0.26), y + Inches(0.36), Inches(5.2), Inches(0.75),
        [{"runs": [(d, 10.5, False, GRAY, FONT_B)], "ls": 1.12}])
footer(s)

# ===========================================================================
# Slide 27 - Thank you
# ===========================================================================
s = new_slide()
bg(s, DARK)
rect(s, 0, 0, SW, Inches(0.28), GOLD)
rect(s, 0, SH - Inches(0.28), SW, Inches(0.28), GOLD)
rect(s, Inches(5.42), Inches(1.1), Inches(2.5), Inches(2.5), GREEN, shape=MSO_SHAPE.OVAL)
if os.path.exists(LOGO):
    s.shapes.add_picture(LOGO, Inches(5.62), Inches(1.3), Inches(2.1), Inches(2.1))
txt(s, Inches(0.8), Inches(4.05), Inches(11.7), Inches(1.0),
    [{"runs": [("Thank You", 46, True, WHITE, FONT_H)], "align": PP_ALIGN.CENTER}])
txt(s, Inches(0.8), Inches(5.1), Inches(11.7), Inches(0.7),
    [{"runs": [("Lake Shore Colleges  \u2022  ID Management System", 17, True, GOLD, FONT_H)],
      "align": PP_ALIGN.CENTER}])
txt(s, Inches(2.5), Inches(5.9), Inches(8.3), Inches(0.6),
    [{"runs": [("Questions & discussion welcome", 13, False, RGBColor(0xCF, 0xE0, 0xD5), FONT_B)],
      "align": PP_ALIGN.CENTER}])

# ===========================================================================
# Save
# ===========================================================================
out = os.path.join(PPTX_DIR, "Lake_Shore_ID_Management_System.pptx")
prs.save(out)
print("Saved:", out)
print("Slides:", len(prs.slides._sldIdLst))