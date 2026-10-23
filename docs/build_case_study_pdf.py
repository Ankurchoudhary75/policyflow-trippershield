#!/usr/bin/env python3
import os
import subprocess
import markdown

md_path = os.path.join(os.path.dirname(__file__), 'POLICYFLOW_ENGINEERING_CASE_STUDY.md')
html_path = os.path.join(os.path.dirname(__file__), 'policyflow_engineering_case_study.html')
pdf_path = os.path.join(os.path.dirname(__file__), 'PolicyFlow_Engineering_Case_Study_Ankur_Choudhary.pdf')

with open(md_path, 'r', encoding='utf-8') as f:
    text = f.read()

# Convert markdown to html with table and code extensions
html_body = markdown.markdown(text, extensions=['tables', 'fenced_code', 'codehilite', 'toc'])

html_template = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PolicyFlow: Fault-Tolerant Insurance Issuance & Reconciliation Engine</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');
  
  @page {{
    size: A4;
    margin: 18mm 16mm 18mm 16mm;
    @bottom-right {{
      content: counter(page);
    }}
  }}

  body {{
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
    color: #1e293b;
    background-color: #ffffff;
    max-width: 900px;
    margin: 0 auto;
    padding: 30px 20px;
  }}

  h1, h2, h3, h4 {{
    color: #0f172a;
    font-weight: 700;
    line-height: 1.25;
  }}

  h1 {{
    font-size: 26pt;
    border-bottom: 2px solid #0284c7;
    padding-bottom: 12px;
    margin-bottom: 8px;
  }}

  h2 {{
    font-size: 16pt;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 6px;
    margin-top: 26pt;
    color: #0369a1;
  }}

  h3 {{
    font-size: 13pt;
    margin-top: 18pt;
    color: #0f172a;
  }}

  p, li {{
    font-size: 10pt;
    color: #334155;
  }}

  code {{
    font-family: 'JetBrains Mono', monospace;
    background-color: #f1f5f9;
    padding: 2px 5px;
    border-radius: 4px;
    font-size: 9pt;
    color: #0284c7;
  }}

  pre {{
    font-family: 'JetBrains Mono', monospace;
    background-color: #0f172a;
    color: #f8fafc;
    padding: 14px 18px;
    border-radius: 8px;
    font-size: 8.5pt;
    overflow-x: auto;
    line-height: 1.45;
  }}

  pre code {{
    background-color: transparent;
    color: inherit;
    padding: 0;
  }}

  blockquote {{
    border-left: 4px solid #0284c7;
    background-color: #f0f9ff;
    padding: 10px 16px;
    margin: 16px 0;
    border-radius: 0 8px 8px 0;
    color: #0369a1;
    font-style: italic;
  }}

  table {{
    width: 100%;
    border-collapse: collapse;
    margin: 18px 0;
    font-size: 9pt;
  }}

  th, td {{
    border: 1px solid #cbd5e1;
    padding: 8px 12px;
    text-align: left;
  }}

  th {{
    background-color: #f8fafc;
    color: #0f172a;
    font-weight: 600;
  }}

  tr:nth-child(even) {{
    background-color: #f8fafc;
  }}

  hr {{
    border: 0;
    height: 1px;
    background: #e2e8f0;
    margin: 24px 0;
  }}

  .header-box {{
    background: linear-gradient(135deg, #0f172a 0%, #0369a1 100%);
    color: white;
    padding: 24px 28px;
    border-radius: 12px;
    margin-bottom: 24px;
  }}

  .header-box h1 {{
    color: white;
    border: none;
    margin: 0 0 8px 0;
    font-size: 22pt;
  }}

  .header-box p {{
    color: #e0f2fe;
    margin: 4px 0;
    font-size: 10pt;
  }}
</style>
</head>
<body>
<div class="header-box">
  <h1>PolicyFlow: Engineering Case Study</h1>
  <p><strong>Fault-Tolerant Insurance Issuance & Reconciliation Engine</strong></p>
  <p>TripperShield Campus Engineering Challenge • Candidate: Ankur Choudhary (<a href="https://github.com/Ankurchoudhary75" style="color:#38bdf8;">@Ankurchoudhary75</a>)</p>
</div>
{html_body}
</body>
</html>
"""

with open(html_path, 'w', encoding='utf-8') as f:
    f.write(html_template)

print(f"Generated HTML: {html_path}")

# Run headless Chrome to produce PDF
chrome_binary = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
if os.path.exists(chrome_binary):
    cmd = [
        chrome_binary,
        "--headless",
        "--disable-gpu",
        "--print-to-pdf=" + pdf_path,
        html_path
    ]
    subprocess.run(cmd, check=True)
    print(f"Successfully generated PDF: {pdf_path}")
else:
    print("Chrome binary not found at default location; PDF generation skipped.")
