#!/usr/bin/env python3
"""Generate a non-technical Features Overview PDF for the Fleet & Safety System (unbranded)."""
import os
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_JUSTIFY
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
                                Image, PageBreak, Flowable, KeepTogether)
from reportlab.pdfgen import canvas as canvas_mod

# ---- Brand palette ----
NAVY   = colors.HexColor("#15314f")
TEAL   = colors.HexColor("#1f6f6b")
ACCENT = colors.HexColor("#ec6609")   # from the system's own theme
LIGHT  = colors.HexColor("#eef3f7")
MIDGREY= colors.HexColor("#5a6b7b")
LINE   = colors.HexColor("#d6e0e8")

OUT  = r"C:\Users\titow\Downloads\Fleet_Safety_System_Features.pdf"

styles = getSampleStyleSheet()

def S(name, **kw):
    styles.add(ParagraphStyle(name, **kw))

S("Hero",     fontName="Helvetica-Bold", fontSize=30, leading=34, textColor=colors.white, alignment=TA_LEFT)
S("HeroSub",  fontName="Helvetica",      fontSize=14, leading=20, textColor=colors.white, alignment=TA_LEFT)
S("H1",       fontName="Helvetica-Bold", fontSize=17, leading=21, textColor=NAVY, spaceBefore=6, spaceAfter=8)
S("H2",       fontName="Helvetica-Bold", fontSize=12.5, leading=16, textColor=TEAL, spaceBefore=10, spaceAfter=4)
S("Body",     fontName="Helvetica",      fontSize=10.5, leading=15.5, textColor=colors.HexColor("#2b3a47"), alignment=TA_JUSTIFY, spaceAfter=6)
S("Lead",     fontName="Helvetica",      fontSize=11.5, leading=17, textColor=colors.HexColor("#2b3a47"), alignment=TA_JUSTIFY, spaceAfter=8)
S("Feat",     fontName="Helvetica",      fontSize=10, leading=14, textColor=colors.HexColor("#2b3a47"))
S("FeatBold", fontName="Helvetica-Bold", fontSize=10, leading=14, textColor=NAVY)
S("Cap",      fontName="Helvetica",      fontSize=8.5, leading=11, textColor=MIDGREY)
S("StatNum",  fontName="Helvetica-Bold", fontSize=22, leading=24, textColor=ACCENT, alignment=TA_CENTER)
S("StatLbl",  fontName="Helvetica",      fontSize=8.5, leading=11, textColor=NAVY, alignment=TA_CENTER)
S("WhiteSmall", fontName="Helvetica", fontSize=9, leading=12, textColor=colors.white, alignment=TA_LEFT)

class HBar(Flowable):
    """A coloured rule."""
    def __init__(self, width, color=ACCENT, thickness=2):
        super().__init__(); self.width=width; self.color=color; self.thickness=thickness
    def draw(self):
        self.canv.setStrokeColor(self.color); self.canv.setLineWidth(self.thickness)
        self.canv.line(0,0,self.width,0)

def bullets(items, text_w=138*mm):
    """Build a clean bullet list sized to fit its container (text_w = width of the text column)."""
    rows=[]
    for it in items:
        if isinstance(it, tuple):
            lead, rest = it
            txt = f'<font color="#15314f"><b>{lead}</b></font> &nbsp;{rest}' if rest else f'<font color="#15314f"><b>{lead}</b></font>'
        else:
            txt = it
        rows.append([Paragraph("•", ParagraphStyle("b", parent=styles["Feat"], textColor=ACCENT, fontName="Helvetica-Bold")),
                     Paragraph(txt, styles["Feat"])])
    t=Table(rows, colWidths=[6*mm, text_w])
    t.setStyle(TableStyle([
        ("VALIGN",(0,0),(-1,-1),"TOP"),
        ("TOPPADDING",(0,0),(-1,-1),2.5),
        ("BOTTOMPADDING",(0,0),(-1,-1),2.5),
        ("LEFTPADDING",(0,0),(-1,-1),0),
    ]))
    return t

def card(title, items, accent=TEAL):
    """A titled feature card with a coloured left border."""
    head = Paragraph(title, ParagraphStyle("ch", parent=styles["H2"], textColor=accent, spaceBefore=0, spaceAfter=4))
    inner = [head, bullets(items)]
    t = Table([[inner]], colWidths=[170*mm])
    t.setStyle(TableStyle([
        ("BACKGROUND",(0,0),(-1,-1), colors.white),
        ("BOX",(0,0),(-1,-1), 0.6, LINE),
        ("LINEBEFORE",(0,0),(0,-1), 3, accent),
        ("LEFTPADDING",(0,0),(-1,-1),10),
        ("RIGHTPADDING",(0,0),(-1,-1),10),
        ("TOPPADDING",(0,0),(-1,-1),8),
        ("BOTTOMPADDING",(0,0),(-1,-1),8),
    ]))
    return KeepTogether([t, Spacer(1,7)])

# ---------- page furniture ----------
def cover(canv, doc):
    canv.saveState()
    w,h = A4
    canv.setFillColor(NAVY); canv.rect(0,0,w,h,fill=1,stroke=0)
    canv.setFillColor(TEAL); canv.rect(0,h-150*mm,w,8*mm,fill=1,stroke=0)
    canv.setFillColor(ACCENT); canv.rect(0,h-150*mm-2.2*mm,w,2.2*mm,fill=1,stroke=0)
    # masthead accent mark (no logo / no brand name)
    canv.setFillColor(ACCENT); canv.roundRect(20*mm, h-40*mm, 16*mm, 16*mm, 3*mm, fill=1, stroke=0)
    canv.setFillColor(colors.white); canv.setFont("Helvetica-Bold",15)
    canv.drawCentredString(28*mm, h-34.5*mm, "F+S")
    # footer line
    canv.setFillColor(colors.HexColor("#9fb4c7")); canv.setFont("Helvetica",9)
    canv.drawString(20*mm, 16*mm, "Confidential")
    canv.drawRightString(w-20*mm, 16*mm, "Features Overview")
    canv.restoreState()

def interior(canv, doc):
    canv.saveState()
    w,h = A4
    # header band
    canv.setFillColor(NAVY); canv.rect(0,h-16*mm,w,16*mm,fill=1,stroke=0)
    canv.setFillColor(ACCENT); canv.rect(0,h-16*mm,w,1.4*mm,fill=1,stroke=0)
    canv.setFillColor(colors.white); canv.setFont("Helvetica-Bold",9.5)
    canv.drawString(20*mm, h-10.5*mm, "Fleet & Safety Management System")
    canv.setFont("Helvetica",8.5); canv.setFillColor(colors.HexColor("#c7d4e0"))
    canv.drawRightString(w-20*mm, h-10.5*mm, "Features Overview")
    # footer
    canv.setStrokeColor(LINE); canv.setLineWidth(0.5); canv.line(20*mm,14*mm,w-20*mm,14*mm)
    canv.setFillColor(MIDGREY); canv.setFont("Helvetica",8)
    canv.drawString(20*mm, 9.5*mm, "Features Overview — for review by developers and stakeholders")
    canv.drawRightString(w-20*mm, 9.5*mm, f"Page {doc.page-1}")
    canv.restoreState()

# ---------- build story ----------
story=[]

# COVER content (sits over the navy background)
story.append(Spacer(1, 86*mm))
story.append(Paragraph("Fleet &amp; Safety", styles["Hero"]))
story.append(Paragraph("Management System", styles["Hero"]))
story.append(Spacer(1,6))
story.append(Paragraph("A plain-language overview of what the system does", styles["HeroSub"]))
story.append(Spacer(1, 10))
story.append(Paragraph("Prepared for developer review and stakeholder briefing", styles["WhiteSmall"]))
story.append(Spacer(1, 4))
story.append(Paragraph('<b>Prepared for:</b>&nbsp; Njuguna Waitara', styles["WhiteSmall"]))
story.append(PageBreak())

# ---- What is this system ----
story.append(Paragraph("What is this system?", styles["H1"]))
story.append(HBar(40*mm)); story.append(Spacer(1,8))
story.append(Paragraph(
    "The Fleet &amp; Safety Management System is an all-in-one web platform for managing the company's "
    "vehicles and upholding workplace Health, Safety &amp; Environment (HSE) standards across "
    "every project site. It replaces paper forms, spreadsheets and manual approvals with a single system "
    "that field teams and office staff use every day — from logging a fuel request to signing off a safety "
    "inspection on site.", styles["Lead"]))
story.append(Paragraph(
    "The platform brings together two connected areas of the business: <b>Fleet Management</b> (vehicles, "
    "drivers, fuel, maintenance) and <b>Safety &amp; Compliance</b> (permits, inspections, risk assessments "
    "and daily safety routines). Everything is accessible from a phone or computer, so records are captured "
    "where the work actually happens — complete with photos, GPS location and signatures.", styles["Lead"]))
story.append(Spacer(1,10))

# ---- At a glance stat band ----
def stat(num,lbl):
    inner=[Paragraph(num,styles["StatNum"]), Paragraph(lbl,styles["StatLbl"])]
    return inner
stats=Table([[stat("2","Connected areas:<br/>Fleet &amp; Safety"),
              stat("30+","Features &amp;<br/>form types"),
              stat("2","User levels:<br/>Admin &amp; Team"),
              stat("100%","Mobile-friendly,<br/>on-site capture")]],
            colWidths=[42.5*mm]*4)
stats.setStyle(TableStyle([
    ("BACKGROUND",(0,0),(-1,-1),LIGHT),
    ("BOX",(0,0),(-1,-1),0.6,LINE),
    ("INNERGRID",(0,0),(-1,-1),0.6,colors.white),
    ("VALIGN",(0,0),(-1,-1),"MIDDLE"),
    ("TOPPADDING",(0,0),(-1,-1),10),("BOTTOMPADDING",(0,0),(-1,-1),10),
]))
story.append(stats)
story.append(Spacer(1,14))

# ---- Fleet ----
story.append(Paragraph("Part 1 — Fleet Management", styles["H1"]))
story.append(HBar(40*mm)); story.append(Spacer(1,8))
story.append(Paragraph("Everything needed to run the company's vehicles day to day.", styles["Body"]))

story.append(card("Vehicles &amp; Drivers", [
    ("Vehicle register —", "keep a complete list of all company vehicles, with logbooks and ownership details."),
    ("Driver profiles —", "manage drivers and their driving licences, with automatic reminders before a licence expires."),
    ("Vehicle assignment —", "assign a driver to each vehicle and see who is responsible for what."),
    ("Insurance tracking —", "store each vehicle's insurance and get automatic alerts before it expires."),
]))
story.append(card("Daily Operations", [
    ("Daily vehicle log —", "drivers record start and end odometer readings with photos; distance travelled is calculated automatically."),
    ("Fuel requests —", "drivers request fuel, a manager approves, payment is recorded, and the driver uploads photo proof from the fuel station (pump, dashboard and receipt)."),
    ("Service, repairs &amp; maintenance —", "request work, get it approved and paid, and document it with photos and receipts."),
    ("Car wash —", "request and keep a record of vehicle cleaning."),
    ("Monthly vehicle inspection —", "a detailed day-by-day vehicle safety checklist with photos and an inspector's signature."),
]))
story.append(PageBreak())

# ---- HSE ----
story.append(Paragraph("Part 2 — Safety &amp; Compliance (HSE)", styles["H1"]))
story.append(HBar(40*mm)); story.append(Spacer(1,8))
story.append(Paragraph(
    "A complete digital toolkit for keeping people safe and meeting health, safety and environmental "
    "obligations on every site. Each form captures photos and signatures and can be produced as a "
    "professional PDF report.", styles["Body"]))

story.append(card("Permits &amp; Journey Safety", [
    ("Work Permits —", "formal permission-to-work for high-risk jobs, with hazard checks and sign-off by several responsible people before work begins."),
    ("Journey Management Plans —", "pre-trip safety plans with manager approval, plus a report filed after the trip."),
], accent=ACCENT))
story.append(card("Risk &amp; Incident Management", [
    ("Risk Assessments —", "identify hazards for a task and rate how serious they are, with recommended controls."),
    ("Incident &amp; Corrective Actions (CAPA) —", "report incidents, investigate the root cause, assign corrective actions and verify they worked."),
    ("HSE Site Inspections —", "record findings during a site walk-through and track each one through to closure."),
], accent=ACCENT))
story.append(card("Equipment Inspections", [
    "Routine safety checks — each with photos and sign-off — for:",
    ("Ladders · Safety Harnesses · Fire Extinguishers · First Aid Kits", ""),
    ("Electrical Equipment · Air Compressors · Cranes (pre-lift checks)", ""),
], accent=TEAL))
story.append(card("Daily Site Safety", [
    ("Toolbox Talks —", "record the daily team safety briefing, who attended and the topic covered."),
    ("PPE &amp; Tool Issuance —", "log protective equipment and tools handed to staff, confirmed by the recipient's signature."),
    ("Site Visit Reports —", "capture observations, findings and recommended actions from a site visit."),
    ("Daily Supervisor Checks, Environment &amp; Cleaning inspections —", "routine daily and weekly housekeeping and supervision checklists."),
], accent=TEAL))
story.append(PageBreak())

# ---- Who uses it ----
story.append(Paragraph("Who uses it", styles["H1"]))
story.append(HBar(40*mm)); story.append(Spacer(1,8))
story.append(Paragraph(
    "The system has two levels of access. People see and do only what their role allows, which keeps "
    "approvals controlled and each person's information private to them and their team.", styles["Body"]))
story.append(Spacer(1,4))

admin_items=[
    "Approve or reject fuel, maintenance and journey requests",
    "Record payments once a request is approved",
    "Manage vehicles, drivers and user accounts",
    "Oversee every safety record across all teams and sites",
    "View dashboards with live statistics and generate reports",
]
user_items=[
    "Submit their own fuel, maintenance and journey requests",
    "Record daily vehicle logs (odometer in and out)",
    "Complete safety forms and inspections on site",
    "Confirm receipt of PPE and tools by signing",
    "See their own records and their team's activity",
]
def role_card(title, subtitle, items, head_color):
    head=[Paragraph(title, ParagraphStyle("rt",parent=styles["H2"],textColor=colors.white,spaceBefore=0,spaceAfter=2,fontSize=13)),
          Paragraph(subtitle, ParagraphStyle("rs",parent=styles["Cap"],textColor=colors.HexColor("#dbe6ef")))]
    htbl=Table([[head]],colWidths=[82*mm])
    htbl.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),head_color),
        ("LEFTPADDING",(0,0),(-1,-1),10),("RIGHTPADDING",(0,0),(-1,-1),10),
        ("TOPPADDING",(0,0),(-1,-1),8),("BOTTOMPADDING",(0,0),(-1,-1),8)]))
    body=Table([[bullets(items, text_w=58*mm)]],colWidths=[82*mm])
    body.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),
        ("BOX",(0,0),(-1,-1),0.6,LINE),
        ("LEFTPADDING",(0,0),(-1,-1),8),("RIGHTPADDING",(0,0),(-1,-1),8),
        ("TOPPADDING",(0,0),(-1,-1),8),("BOTTOMPADDING",(0,0),(-1,-1),10)]))
    return [htbl,body]

left=role_card("Administrators &amp; Managers","Office staff who oversee and approve", admin_items, NAVY)
right=role_card("Team Members","Field staff &amp; drivers on site", user_items, TEAL)
two=Table([[left,right]],colWidths=[85*mm,85*mm])
two.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"TOP"),
    ("LEFTPADDING",(0,0),(0,-1),0),("RIGHTPADDING",(0,0),(0,-1),3*mm),
    ("LEFTPADDING",(1,0),(1,-1),3*mm),("RIGHTPADDING",(1,0),(-1,-1),0)]))
story.append(two)
story.append(Spacer(1,14))

# ---- system-wide ----
story.append(Paragraph("Built-in across the whole system", styles["H1"]))
story.append(HBar(40*mm)); story.append(Spacer(1,8))
sw=[
    ("Photo evidence —","capture photos straight from a phone camera and attach them to any record."),
    ("GPS location —","records are tagged with where they were created."),
    ("Digital signatures —","sign on-screen to approve or confirm receipt."),
    ("PDF reports —","produce a clean, professional report for any record, ready to print or share."),
    ("Automatic alerts —","email and SMS reminders, for example when a licence or insurance is about to expire."),
    ("Secure sign-in —","each person logs in to their own account with role-based access; face login is also supported."),
    ("Dashboards —","live counts and charts give managers an at-a-glance view of activity and outstanding items."),
]
story.append(card("Capabilities every module shares", sw, accent=ACCENT))

story.append(Spacer(1,6))
story.append(Paragraph("Looking ahead", styles["H2"]))
story.append(Paragraph(
    "This document describes the system as it works today. A planned modernization will bring the two areas "
    "together into a single, faster and more secure platform — keeping all of the capabilities above while "
    "making the system easier to maintain and grow.", styles["Body"]))

# build
doc=SimpleDocTemplate(OUT, pagesize=A4,
                      leftMargin=20*mm, rightMargin=20*mm,
                      topMargin=22*mm, bottomMargin=18*mm,
                      title="Fleet & Safety Management System — Features Overview",
                      author="Fleet & Safety Management System")

def first_page(canv,doc): cover(canv,doc)
def later_pages(canv,doc): interior(canv,doc)

doc.build(story, onFirstPage=first_page, onLaterPages=later_pages)
print("WROTE", OUT, os.path.getsize(OUT), "bytes")
