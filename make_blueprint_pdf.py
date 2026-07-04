#!/usr/bin/env python3
"""Unified Platform Blueprint PDF — Fleet + Safety + Warehouse -> one platform."""
import os
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_JUSTIFY
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
                                PageBreak, Flowable, KeepTogether)

NAVY   = colors.HexColor("#15314f")
TEAL   = colors.HexColor("#1f6f6b")
ACCENT = colors.HexColor("#ec6609")
PLUM   = colors.HexColor("#6b3f69")
LIGHT  = colors.HexColor("#eef3f7")
MIDGREY= colors.HexColor("#5a6b7b")
LINE   = colors.HexColor("#d6e0e8")
INK    = colors.HexColor("#2b3a47")

# pillar colors
C_FLEET = colors.HexColor("#1f6f6b")   # teal
C_SAFE  = colors.HexColor("#b5532a")   # burnt orange
C_WARE  = colors.HexColor("#3a5a8c")   # blue
C_CORE  = colors.HexColor("#15314f")   # navy

OUT = r"C:\Users\titow\Downloads\Unified_Platform_Blueprint.pdf"
styles = getSampleStyleSheet()

def S(name, **kw): styles.add(ParagraphStyle(name, **kw))
S("Hero",    fontName="Helvetica-Bold", fontSize=28, leading=32, textColor=colors.white)
S("HeroSub", fontName="Helvetica",      fontSize=13, leading=18, textColor=colors.white)
S("WhiteSmall", fontName="Helvetica",   fontSize=9.5, leading=13, textColor=colors.white)
S("H1",  fontName="Helvetica-Bold", fontSize=16, leading=20, textColor=NAVY, spaceBefore=4, spaceAfter=6)
S("H2",  fontName="Helvetica-Bold", fontSize=12, leading=15, textColor=TEAL, spaceBefore=9, spaceAfter=3)
S("Body",fontName="Helvetica",      fontSize=9.7, leading=14, textColor=INK, alignment=TA_JUSTIFY, spaceAfter=5)
S("Lead",fontName="Helvetica",      fontSize=10.5,leading=15.5, textColor=INK, alignment=TA_JUSTIFY, spaceAfter=7)
S("Feat",fontName="Helvetica",      fontSize=9.3, leading=13, textColor=INK)
S("Small",fontName="Helvetica",     fontSize=8.3, leading=11, textColor=MIDGREY)
S("Cell", fontName="Helvetica",     fontSize=8.4, leading=11, textColor=INK)
S("CellB",fontName="Helvetica-Bold",fontSize=8.4, leading=11, textColor=colors.white, alignment=TA_CENTER)
S("Box",  fontName="Helvetica-Bold",fontSize=8.2, leading=10, textColor=colors.white, alignment=TA_CENTER)
S("BoxI", fontName="Helvetica",     fontSize=7.8, leading=9.5, textColor=colors.white, alignment=TA_CENTER)
S("Step", fontName="Helvetica",     fontSize=8.6, leading=12, textColor=INK)
S("StepN",fontName="Helvetica-Bold",fontSize=8.6, leading=12, textColor=ACCENT)

class HBar(Flowable):
    def __init__(self,w,c=ACCENT,t=2): super().__init__(); self.w=w;self.c=c;self.t=t
    def draw(self):
        self.canv.setStrokeColor(self.c); self.canv.setLineWidth(self.t); self.canv.line(0,0,self.w,0)

def bullets(items, tw=150*mm):
    rows=[]
    for it in items:
        if isinstance(it,tuple):
            lead,rest=it; txt=f'<font color="#15314f"><b>{lead}</b></font>&nbsp; {rest}' if rest else f'<font color="#15314f"><b>{lead}</b></font>'
        else: txt=it
        rows.append([Paragraph("•",ParagraphStyle("b",parent=styles["Feat"],textColor=ACCENT,fontName="Helvetica-Bold")),
                     Paragraph(txt,styles["Feat"])])
    t=Table(rows,colWidths=[5*mm,tw])
    t.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"TOP"),("TOPPADDING",(0,0),(-1,-1),1.6),
        ("BOTTOMPADDING",(0,0),(-1,-1),1.6),("LEFTPADDING",(0,0),(-1,-1),0)]))
    return t

def flow(steps, color=ACCENT, box_w=30*mm):
    """Render a horizontal status flow: [box] -> [box] -> ..."""
    cells=[]; widths=[]
    for i,s in enumerate(steps):
        cells.append(Paragraph(s, styles["Box"])); widths.append(box_w)
        if i < len(steps)-1:
            cells.append(Paragraph("&rarr;", ParagraphStyle("ar",parent=styles["Feat"],textColor=MIDGREY,
                         fontName="Helvetica-Bold",fontSize=11,alignment=TA_CENTER))); widths.append(7*mm)
    t=Table([cells],colWidths=widths)
    sty=[("VALIGN",(0,0),(-1,-1),"MIDDLE"),("ALIGN",(0,0),(-1,-1),"CENTER"),
         ("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5)]
    for i in range(len(steps)):
        col=i*2
        sty.append(("BACKGROUND",(col,0),(col,0),color))
        sty.append(("ROUNDEDCORNERS",[3,3,3,3]))
    t.setStyle(TableStyle(sty))
    return t

def card(title, items, accent=TEAL, tw=150*mm):
    head=Paragraph(title,ParagraphStyle("ch",parent=styles["H2"],textColor=accent,spaceBefore=0,spaceAfter=3))
    t=Table([[[head,bullets(items,tw)]]],colWidths=[tw+8*mm])
    t.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),("BOX",(0,0),(-1,-1),0.6,LINE),
        ("LINEBEFORE",(0,0),(0,-1),3,accent),("LEFTPADDING",(0,0),(-1,-1),9),("RIGHTPADDING",(0,0),(-1,-1),9),
        ("TOPPADDING",(0,0),(-1,-1),7),("BOTTOMPADDING",(0,0),(-1,-1),7)]))
    return KeepTogether([t,Spacer(1,6)])

def cover(c,doc):
    c.saveState(); w,h=A4
    c.setFillColor(NAVY); c.rect(0,0,w,h,fill=1,stroke=0)
    c.setFillColor(TEAL); c.rect(0,h-150*mm,w,8*mm,fill=1,stroke=0)
    c.setFillColor(ACCENT); c.rect(0,h-150*mm-2.2*mm,w,2.2*mm,fill=1,stroke=0)
    # three pillar chips
    chips=[("FLEET",C_FLEET),("SAFETY",C_SAFE),("WAREHOUSE",C_WARE)]
    x=20*mm
    for lbl,col in chips:
        c.setFillColor(col); c.roundRect(x,h-38*mm,34*mm,11*mm,3*mm,fill=1,stroke=0)
        c.setFillColor(colors.white); c.setFont("Helvetica-Bold",9); c.drawCentredString(x+17*mm,h-31.5*mm,lbl)
        x+=38*mm
    c.setFillColor(colors.HexColor("#9fb4c7")); c.setFont("Helvetica",9)
    c.drawString(20*mm,16*mm,"Confidential — Technical Blueprint")
    c.drawRightString(w-20*mm,16*mm,"Unified Platform")
    c.restoreState()

def interior(c,doc):
    c.saveState(); w,h=A4
    c.setFillColor(NAVY); c.rect(0,h-15*mm,w,15*mm,fill=1,stroke=0)
    c.setFillColor(ACCENT); c.rect(0,h-15*mm,w,1.3*mm,fill=1,stroke=0)
    c.setFillColor(colors.white); c.setFont("Helvetica-Bold",9)
    c.drawString(20*mm,h-10*mm,"Unified Platform Blueprint")
    c.setFont("Helvetica",8.3); c.setFillColor(colors.HexColor("#c7d4e0"))
    c.drawRightString(w-20*mm,h-10*mm,"Fleet · Safety · Warehouse")
    c.setStrokeColor(LINE); c.setLineWidth(0.5); c.line(20*mm,13*mm,w-20*mm,13*mm)
    c.setFillColor(MIDGREY); c.setFont("Helvetica",8)
    c.drawString(20*mm,9*mm,"Prepared for Njuguna Waitara")
    c.drawRightString(w-20*mm,9*mm,f"Page {doc.page-1}")
    c.restoreState()

story=[]
# ---------------- COVER ----------------
story.append(Spacer(1,84*mm))
story.append(Paragraph("Unified Platform", styles["Hero"]))
story.append(Paragraph("Blueprint", styles["Hero"]))
story.append(Spacer(1,6))
story.append(Paragraph("Combining Fleet Management, Health &amp; Safety, and Warehouse Management into one Work-Order-centred system", styles["HeroSub"]))
story.append(Spacer(1,12))
story.append(Paragraph("Architecture · Workflow · Data Model · Module Map · Build Plan", styles["WhiteSmall"]))
story.append(Spacer(1,4))
story.append(Paragraph("<b>Prepared for:</b>&nbsp; Njuguna Waitara", styles["WhiteSmall"]))
story.append(PageBreak())

# ---------------- 1. EXECUTIVE SUMMARY ----------------
story.append(Paragraph("1 · Executive Summary", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph(
    "Today the business runs <b>three separate systems</b> — Fleet Management, Health &amp; Safety (HSE), and Warehouse "
    "Management (WMS) — across three codebases and two databases. In reality they serve <b>one company, the same field "
    "crews, and the same clients</b>, and they are already entangled: they share user, team, region and location tables, "
    "read each other's databases at runtime, use the same face-login, and each re-implements site visits, approvals, "
    "photos, signatures and GPS capture independently.",styles["Lead"]))
story.append(Paragraph(
    "This blueprint proposes a single platform built on <b>one identity &amp; organisation backbone</b>, with the "
    "<b>Work Order</b> as the spine that ties everything together, and <b>three operational pillars</b> on top. The "
    "shared capture engine — photos, signatures, GPS, offline sync, push, PDF and the approval workflow — is built "
    "<b>once</b> instead of three times.",styles["Lead"]))
story.append(Spacer(1,4))
# stat band
def stat(n,l): return [Paragraph(n,ParagraphStyle("sn",parent=styles["H1"],textColor=ACCENT,alignment=TA_CENTER,fontSize=20,spaceAfter=0)),
                       Paragraph(l,ParagraphStyle("sl",parent=styles["Small"],alignment=TA_CENTER,textColor=NAVY))]
sb=Table([[stat("3","systems today"),stat("2","databases"),stat("~50","modules"),stat("1","platform tomorrow")]],colWidths=[42.5*mm]*4)
sb.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),LIGHT),("BOX",(0,0),(-1,-1),0.6,LINE),
    ("INNERGRID",(0,0),(-1,-1),0.6,colors.white),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
    ("TOPPADDING",(0,0),(-1,-1),9),("BOTTOMPADDING",(0,0),(-1,-1),9)]))
story.append(sb); story.append(Spacer(1,10))
story.append(card("Why now — the evidence they are already one system", [
    ("Shared identity —","users, teams, regions, locations and worktypes already live across all three databases."),
    ("Cross-reads —","Fleet &amp; HSE read the WMS database; WMS reads the Fleet database, at runtime."),
    ("Duplicated modules —","site visits exist in all three; approvals, photo tables, signatures and GPS are rebuilt per app."),
    ("Head start —","WMS already has a REST API, a field-tech portal, work-order-linked stock usage, push and face login — the exact foundation a unified platform needs."),
], accent=ACCENT))
story.append(PageBreak())

# ---------------- 2. APPLICATION WORKFLOW ----------------
story.append(Paragraph("2 · The Application Workflow (end to end)", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph(
    "A single field job today touches all three systems. The unified platform models this as one <b>Work Order</b> "
    "moving through a lifecycle, with each pillar contributing its step. This is the spine of the whole application.",styles["Body"]))
story.append(Spacer(1,4))

# Work order lifecycle steps
wo_steps=[
 ("1","Work Order created","Office opens a job for a client/site/task (e.g. an FTTH cabinet build) and assigns a crew/team.","CORE"),
 ("2","Vehicle assigned","A vehicle is allocated to the crew; insurance &amp; licence validity checked.","FLEET"),
 ("3","Pre-work safety","Crew completes Journey Management Plan, Work Permit, Toolbox Talk and Risk Assessment before leaving.","SAFETY"),
 ("4","Materials requisition","Tech raises a stock requisition → line-manager 1st approval → master final approval → store issues stock (serial-scanned).","WAREHOUSE"),
 ("5","Travel &amp; run","Fuel request (approve → pay → photo proof) and the daily odometer log are recorded against the trip.","FLEET"),
 ("6","On-site install","Tech consumes stock against the work order (barcode/serial), and can reuse Good recovered materials.","WAREHOUSE"),
 ("7","Inspections","Vehicle, equipment and site HSE inspections are filled with photos, GPS and signatures.","SAFETY"),
 ("8","Recover materials","Unused / removed materials are logged as recoveries (Good or Faulty) for reuse or write-off.","WAREHOUSE"),
 ("9","Network acceptance","The FTTH 'design vs ground' QA acceptance form is completed and signed off on site.","WAREHOUSE"),
 ("10","Close-out","Service/maintenance &amp; expense approvals settled; the job is costed and reported end-to-end.","FLEET"),
]
pill_color={"CORE":C_CORE,"FLEET":C_FLEET,"SAFETY":C_SAFE,"WAREHOUSE":C_WARE}
rows=[]
for n,t,d,p in wo_steps:
    badge=Table([[Paragraph(p,styles["BoxI"])]],colWidths=[24*mm])
    badge.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),pill_color[p]),("TOPPADDING",(0,0),(-1,-1),3),
        ("BOTTOMPADDING",(0,0),(-1,-1),3),("VALIGN",(0,0),(-1,-1),"MIDDLE")]))
    rows.append([Paragraph(n,ParagraphStyle("wn",parent=styles["StepN"],alignment=TA_CENTER,fontSize=11)),
                 [Paragraph(f"<b>{t}</b>",styles["Step"]),Paragraph(d,styles["Small"])],
                 badge])
wt=Table(rows,colWidths=[9*mm,131*mm,26*mm])
wt.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"MIDDLE"),("LINEBELOW",(0,0),(-1,-2),0.5,LINE),
    ("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),6),
    ("LEFTPADDING",(1,0),(1,-1),4),("ALIGN",(2,0),(2,-1),"RIGHT")]))
story.append(wt); story.append(Spacer(1,8))

story.append(Paragraph("Key sub-workflows (status machines preserved from the current system)", styles["H2"]))
story.append(Paragraph("Expense requests — Fuel, Service, Car Wash (Fleet)", styles["Small"]))
story.append(flow(["Pending","Approved","Pending Pay","Paid","Completed"], C_FLEET, box_w=27*mm)); story.append(Spacer(1,5))
story.append(Paragraph("Materials requisition (Warehouse)", styles["Small"]))
story.append(flow(["Pending","1st Approval","Final Approval","Issued","Accepted"], C_WARE, box_w=27*mm)); story.append(Spacer(1,5))
story.append(Paragraph("Safety forms &amp; inspections (HSE)", styles["Small"]))
story.append(flow(["Draft","Completed","Signed"], C_SAFE, box_w=30*mm)); story.append(Spacer(1,5))
story.append(Paragraph("Stock transfer  ·  Material recovery (Warehouse)", styles["Small"]))
story.append(flow(["Shipping","Delivered"], C_WARE, box_w=30*mm)); story.append(Spacer(1,3))
story.append(flow(["Recovered","Available (Good)","Consumed"], C_WARE, box_w=30*mm))
story.append(PageBreak())

# ---------------- 3. ARCHITECTURE ----------------
story.append(Paragraph("3 · Target Architecture", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph("One backbone, three pillars, one shared capture engine — delivered as an API-first, offline-capable app.", styles["Body"]))
story.append(Spacer(1,4))

def layer(label, items, color, label_w=30*mm):
    lab=Table([[Paragraph(label,styles["CellB"])]],colWidths=[label_w])
    lab.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),color),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
        ("TOPPADDING",(0,0),(-1,-1),7),("BOTTOMPADDING",(0,0),(-1,-1),7)]))
    body=Table([[Paragraph(items,styles["Cell"])]],colWidths=[140*mm-label_w])
    body.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),("BOX",(0,0),(-1,-1),0.5,LINE),
        ("LEFTPADDING",(0,0),(-1,-1),8),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
        ("TOPPADDING",(0,0),(-1,-1),7),("BOTTOMPADDING",(0,0),(-1,-1),7)]))
    row=Table([[lab,body]],colWidths=[label_w,140*mm-label_w])
    row.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"MIDDLE"),("LEFTPADDING",(0,0),(-1,-1),0),("RIGHTPADDING",(0,0),(-1,-1),0),
        ("TOPPADDING",(0,0),(-1,-1),0),("BOTTOMPADDING",(0,0),(-1,-1),0)]))
    return [row, Spacer(1,4)]

for r in layer("CLIENTS", "Installable mobile/desktop PWA (offline-first)  ·  Web admin console  ·  USSD  ·  Push notifications", NAVY): story.append(r)
for r in layer("PILLARS", "Fleet      ·      Safety (HSE)      ·      Warehouse   — each a module set over shared services", PLUM): story.append(r)
for r in layer("SHARED ENGINE", "Approval workflow · Attachments (photos) · Digital signatures · GPS · Offline sync · PDF/reports · Notifications · Audit log", TEAL): story.append(r)
for r in layer("API", "One REST/JSON API (one auth, role-scoped) — powers web, mobile and integrations (extends the existing WMS API)", ACCENT): story.append(r)
for r in layer("BACKBONE", "Identity &amp; roles · Teams · Regions · Locations · Clients · WORK ORDERS · Products · Vehicles", C_CORE): story.append(r)
for r in layer("DATA", "Single MySQL database (merged &amp; normalised from the current fleet + wms databases)", MIDGREY): story.append(r)
story.append(Spacer(1,8))
story.append(card("Architecture principles", [
    ("API-first —","every screen talks to one role-scoped API; WMS already proves this works here. Web, mobile and USSD share it."),
    ("Offline by default —","field capture (forms, photos, signature, GPS) works with no signal and syncs automatically — built into the shared engine, not per module."),
    ("Build cross-cutting once —","one approval engine, one attachments table, one signature service, one PDF service — replacing ~15 photo tables and copy-pasted workflow code."),
    ("Same stack, same host —","Laravel (modern PHP) on the existing cPanel host, reusing the merged database — preserves deployability and the live data."),
], accent=NAVY))
story.append(PageBreak())

# ---------------- 4. DATA MODEL ----------------
story.append(Paragraph("4 · Unified Data Model", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph("The model is organised as a shared backbone, a Work-Order spine, cross-cutting services, and the three pillars' own entities.", styles["Body"]))

story.append(card("Identity &amp; Organisation backbone (built once)", [
    ("users —","one table for office &amp; field staff (merges user_details + euser_details); role, team, location, face-login, status."),
    ("roles &amp; permissions —","granular roles replacing the blunt master/user split (admin, finance, EHS, line-manager, storekeeper, supervisor, technician, safety)."),
    ("teams · regions · locations · clients —","the org/geography lookups shared across all pillars (de-duplicated from the three current copies)."),
], accent=C_CORE))
story.append(card("Work-Order spine (the new unifying entity)", [
    ("work_orders —","client, site, task/node, assigned team &amp; vehicle, status, dates — the thread every pillar attaches to."),
    ("sites —","estate/OLT/cabinet and GPS, reusable across fleet trips, safety forms and acceptance."),
    "Everything below references a work order where relevant, enabling true per-job cost &amp; compliance roll-up.",
], accent=ACCENT))
story.append(card("Cross-cutting services (replace duplicated tables)", [
    ("attachments —","one polymorphic table for all photos/files (replaces ~15 separate *_photos tables)."),
    ("signatures —","one signature service for every multi-party sign-off."),
    ("approvals —","one polymorphic approval/state-machine log for fuel, service, requisitions, permits, etc."),
    ("notifications · audit_log —","unified email/SMS/WhatsApp/push, and an immutable change trail for compliance."),
], accent=TEAL))

# three pillar entity columns
def pillar_col(title, color, items):
    head=Table([[Paragraph(title,styles["CellB"])]],colWidths=[54*mm])
    head.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),color),("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5)]))
    body=Table([[Paragraph("<br/>".join("• "+i for i in items),ParagraphStyle("pc",parent=styles["Cell"],leading=12.5))]],colWidths=[54*mm])
    body.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),("BOX",(0,0),(-1,-1),0.5,LINE),
        ("LEFTPADDING",(0,0),(-1,-1),7),("RIGHTPADDING",(0,0),(-1,-1),6),("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),7),("VALIGN",(0,0),(-1,-1),"TOP")]))
    return [head,body]
story.append(Spacer(1,2))
story.append(Paragraph("Pillar-specific entities", styles["H2"]))
pcols=Table([[pillar_col("FLEET",C_FLEET,["vehicles","vehicle_assignments","insurance_policies","fuel_requests","daily_logs","service_requests","vehicle_inspections"]),
              pillar_col("SAFETY (HSE)",C_SAFE,["permits","journey_plans (JMP)","risk_assessments","capa (incidents)","equipment_inspections","toolbox_talks","ppe / toolkit issues"]),
              pillar_col("WAREHOUSE",C_WARE,["products / stock","requisitions","goods_received (GRN)","issuances","transfers","stocktakes","recoveries","acceptances (FTTH)"])]],
            colWidths=[56*mm,56*mm,56*mm])
pcols.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"TOP"),("LEFTPADDING",(0,0),(0,-1),0),
    ("LEFTPADDING",(1,0),(1,-1),2*mm),("LEFTPADDING",(2,0),(2,-1),2*mm),("RIGHTPADDING",(0,0),(-1,-1),0)]))
story.append(pcols)
story.append(PageBreak())

# ---------------- 5. MODULE MAP ----------------
story.append(Paragraph("5 · Module Map — ~50 modules on three pillars", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph("Every existing module from the three systems, mapped onto the unified structure. Shared modules are built once on the backbone.", styles["Body"]))
story.append(Spacer(1,4))

def modblock(title,color,mods):
    head=Paragraph(title,ParagraphStyle("mh",parent=styles["H2"],textColor=color,spaceBefore=2,spaceAfter=3))
    # render as wrapped chips via a paragraph
    chips="&nbsp; ".join(f'<font backColor="#f0f4f8"> {m} </font>' for m in mods)
    p=Paragraph(chips,ParagraphStyle("chips",parent=styles["Cell"],leading=16))
    t=Table([[[head,p]]],colWidths=[158*mm])
    t.setStyle(TableStyle([("BOX",(0,0),(-1,-1),0.5,LINE),("LINEBEFORE",(0,0),(0,-1),3,color),
        ("LEFTPADDING",(0,0),(-1,-1),9),("RIGHTPADDING",(0,0),(-1,-1),9),("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),7)]))
    return KeepTogether([t,Spacer(1,6)])

story.append(modblock("Shared backbone &amp; services", C_CORE,
    ["Users &amp; Roles","Teams","Regions","Locations","Clients","Work Orders","Calendar","Notifications","Attachments","Signatures","Approvals","Audit Log","Reports/PDF","REST API","Auth + Face login"]))
story.append(modblock("Fleet pillar", C_FLEET,
    ["Vehicles","Vehicle Assignment","Insurance","Fuel","Daily Log","Service / Maintenance","Car Wash","Vehicle Monthly Inspection","AI Odometer (planned)"]))
story.append(modblock("Safety (HSE) pillar", C_SAFE,
    ["Work Permits","JMP","CAPA","Baseline Risk","HSE Inspection","Ladder","Safety Harness","Fire Extinguisher","First Aid","Electrical","Compressors","Pre-Lift / Crane","Toolbox Talks","PPE","Toolkit","Site Visits","DSTI","Environment","Cleaning"]))
story.append(modblock("Warehouse pillar", C_WARE,
    ["Product Catalog","Categories","Receiving (GRN)","Issuance","Requisitions","Stock Use / Consumption","Warehouse Transfers","Stocktake","Recoveries","FTTH Acceptance","Stock Reports","Barcode / Serial","Customers"]))
story.append(Spacer(1,4))
story.append(Paragraph("Unified role model", styles["H2"]))
story.append(Paragraph(
    "The current blunt <b>admin vs user</b> split (and WMS's overlapping type/ttype tiers) is replaced by clear roles, "
    "scoped by team / location / work order: <b>Admin</b>, <b>Finance</b> (settles payments), <b>EHS / Safety</b> "
    "(owns the safety pillar), <b>Line Manager</b> (approves requisitions &amp; expenses), <b>Storekeeper</b> "
    "(runs a warehouse), <b>Supervisor</b>, <b>Technician/Driver</b> (field capture, own/team records only).",styles["Body"]))
story.append(PageBreak())

# ---------------- 6. BUILD PLAN ----------------
story.append(Paragraph("6 · Phased Build Plan", styles["H1"])); story.append(HBar(40*mm)); story.append(Spacer(1,7))
story.append(Paragraph(
    "Built in phases that each ship value on their own. The strategy: stand up the backbone, then migrate one pillar at a "
    "time with <b>old-vs-new parity checks</b> against the live screens, then layer on the intelligence that makes it a "
    "super app. The current systems keep running until each piece is proven.",styles["Body"]))
story.append(Spacer(1,4))

phases=[
 ("Phase 0","Foundation","One Laravel app + merged database, unified auth &amp; roles, the Work-Order spine, and the shared engine (attachments, signatures, approvals, GPS, offline, PDF, notifications, API).",NAVY),
 ("Phase 1","Warehouse pillar","Migrate WMS first — it already has the API &amp; field portal. Products, requisitions, receiving, issuance, transfers, stocktake, recoveries, FTTH acceptance — parity-tested against today.",C_WARE),
 ("Phase 2","Fleet pillar","Vehicles, assignment, insurance, fuel, daily log, service, car wash, vehicle inspection — with the shared approval/photo engine.",C_FLEET),
 ("Phase 3","Safety pillar","All HSE forms: permits, JMP, CAPA, risk, equipment inspections, toolbox talks, PPE/toolkit, site visits, environment/cleaning.",C_SAFE),
 ("Phase 4","Super-app intelligence","Offline PWA hardening + push/WhatsApp; AI photo/odometer verification &amp; fraud checks; analytics &amp; compliance cockpit; M-Pesa / KRA eTIMS / telematics; client portal.",ACCENT),
]
rows=[]
for ph,t,d,col in phases:
    chip=Table([[Paragraph(ph,styles["Box"])]],colWidths=[20*mm])
    chip.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),col),("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),6),("VALIGN",(0,0),(-1,-1),"MIDDLE")]))
    rows.append([chip,[Paragraph(f"<b>{t}</b>",styles["Step"]),Paragraph(d,styles["Small"])]])
pt=Table(rows,colWidths=[22*mm,144*mm])
pt.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"MIDDLE"),("LINEBELOW",(0,0),(-1,-2),0.5,LINE),
    ("TOPPADDING",(0,0),(-1,-1),7),("BOTTOMPADDING",(0,0),(-1,-1),7),("LEFTPADDING",(1,0),(1,-1),5)]))
story.append(pt); story.append(Spacer(1,9))

story.append(Paragraph("Migration &amp; reconciliation (one-time)", styles["H2"]))
story.append(bullets([
    ("Merge the two databases —","collapse the duplicate users/teams/locations/regions and the three site_visit copies into one set."),
    ("De-conflict IDs —","the fleet and wms databases have independent auto-increment IDs that must be re-keyed on merge."),
    ("Resolve schema drift —","e.g. teams and euser_details differ in columns between databases; reconcile to one shape."),
    ("Replace serialized blobs —","barcode/transfer data stored as PHP-serialized arrays becomes proper rows."),
    ("Fix security debt as we port —","server-side role checks, parameterised queries, secrets to env, per-user API tokens, file validation."),
], tw=150*mm))
story.append(Spacer(1,8))
story.append(card("What you gain", [
    ("One login, one app —","field crews stop juggling three systems.",),
    ("True cost-per-job —","fuel + materials + recoveries + labour + vehicle, rolled up per work order, site and client."),
    ("Material accountability —","issued vs used vs recovered, with the acceptance to prove the work."),
    ("Build once —","site visits, users, approvals, photos, signatures — one implementation, not three."),
    ("A sellable product —","an integrated Fleet + Safety + Warehouse platform for field-ops / telecom contractors."),
], accent=TEAL))

doc=SimpleDocTemplate(OUT,pagesize=A4,leftMargin=20*mm,rightMargin=20*mm,topMargin=21*mm,bottomMargin=16*mm,
    title="Unified Platform Blueprint — Fleet, Safety, Warehouse", author="Unified Platform Blueprint")
doc.build(story, onFirstPage=cover, onLaterPages=interior)
print("WROTE",OUT,os.path.getsize(OUT),"bytes")
